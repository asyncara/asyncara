//! The public PHP regressions cover the real C callbacks. These tests isolate
//! Rust/Hyper's actual driver, Sender channel and kernel-wakeup ownership.
use super::*;
use std::collections::BTreeMap;
use std::io::{Read, Write};
use std::net::TcpListener;
use std::thread;

thread_local! {
    pub(super) static IO_POLLS: std::cell::Cell<usize> = const { std::cell::Cell::new(0) };
    static RESULTS: RefCell<BTreeMap<usize, (usize, bool)>> = const { RefCell::new(BTreeMap::new()) };
}
#[no_mangle]
extern "C" fn ar_hyper_head(job: *mut c_void, status: i32, _: *const u8, n: usize) -> i32 {
    assert!(!job.is_null() && status == 200 && n < 65536);
    0
}
#[no_mangle]
unsafe extern "C" fn ar_hyper_chunk(job: *mut c_void, p: *const u8, n: usize) -> i32 {
    assert!(std::slice::from_raw_parts(p, n).iter().all(|&c| c == b'x'));
    RESULTS.with(|r| r.borrow_mut().entry(job as usize).or_default().0 += n);
    0
}
#[no_mangle]
extern "C" fn ar_hyper_complete(job: *mut c_void, _: *const u8, n: usize) {
    assert_eq!(n, 0, "request failed");
    RESULTS.with(|r| {
        let mut r = r.borrow_mut();
        let row = r.entry(job as usize).or_default();
        assert_eq!(row.0, 65536);
        assert!(!row.1, "duplicate completion");
        row.1 = true;
    });
}

#[test]
fn single_driver_waker_survives_many_senders_and_real_readiness() {
    const CONNECTIONS: usize = 4;
    const REQUESTS: usize = 200;
    RESULTS.with(|r| r.borrow_mut().clear());
    IO_POLLS.with(|n| n.set(0));
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    listener.set_nonblocking(true).unwrap();
    let url = format!("http://{}/", listener.local_addr().unwrap());
    let peer = thread::spawn(move || {
        let mut tasks = Vec::new();
        let limit = Instant::now() + Duration::from_secs(5);
        while tasks.len() < CONNECTIONS {
            match listener.accept() {
                Ok((mut stream, _)) => tasks.push(thread::spawn(move || {
                    stream
                        .set_read_timeout(Some(Duration::from_secs(5)))
                        .unwrap();
                    stream
                        .set_write_timeout(Some(Duration::from_secs(5)))
                        .unwrap();
                    let mut request = Vec::new();
                    let mut bytes = [0; 4096];
                    loop {
                        match stream.read(&mut bytes) {
                            Ok(0) => break,
                            Ok(n) => request.extend_from_slice(&bytes[..n]),
                            Err(e) if e.kind() == io::ErrorKind::ConnectionReset => break,
                            Err(e) => panic!("peer read: {e}"),
                        }
                        assert!(request.len() <= 8192);
                        if request.ends_with(b"\r\n\r\n") {
                            assert!(request.starts_with(b"GET / HTTP/1.1\r\n"));
                            request.clear();
                            stream
                                .write_all(b"HTTP/1.1 200 OK\r\nContent-Length: 65536\r\n\r\n")
                                .unwrap();
                            stream.write_all(&[b'x'; 65536]).unwrap();
                        }
                    }
                })),
                Err(e) if e.kind() == io::ErrorKind::WouldBlock => {
                    assert!(Instant::now() < limit, "accept deadline");
                    let mut fd = libc::pollfd {
                        fd: listener.as_raw_fd(),
                        events: libc::POLLIN,
                        revents: 0,
                    };
                    assert!(unsafe { libc::poll(&mut fd, 1, 50) } >= 0);
                }
                Err(e) => panic!("accept: {e}"),
            }
        }
        for task in tasks {
            task.join().unwrap();
        }
    });
    unsafe {
        let engine = ar_hyper_new(CONNECTIONS, CONNECTIONS);
        assert!(!engine.is_null());
        for id in 1..=REQUESTS {
            assert_eq!(
                ar_hyper_submit(
                    engine,
                    id as *mut c_void,
                    url.as_ptr(),
                    url.len(),
                    5000,
                    1000,
                    std::ptr::null(),
                    0
                ),
                0
            );
        }
        let deadline = Instant::now() + Duration::from_secs(5);
        while RESULTS.with(|r| r.borrow().values().filter(|r| r.1).count()) < REQUESTS {
            assert!(Instant::now() < deadline, "lost wakeup or completion");
            let progress = ar_hyper_drive(engine);
            assert!(progress >= 0);
            let mut fds = [libc::pollfd {
                fd: -1,
                events: 0,
                revents: 0,
            }; CONNECTIONS];
            let count = ar_hyper_fds(engine, fds.as_mut_ptr(), fds.len());
            assert!(count <= CONNECTIONS);
            assert!(
                libc::poll(
                    fds.as_mut_ptr(),
                    count as _,
                    if progress == 0 { 10 } else { 0 }
                ) >= 0
            );
            for fd in &fds[..count] {
                if fd.revents != 0 {
                    assert_eq!(ar_hyper_ready(engine, fd.fd, fd.revents as _), 0);
                }
            }
        }
        assert_eq!(ar_hyper_connections(engine), CONNECTIONS);
        ar_hyper_free(engine);
    }
    peer.join().unwrap();
    assert!(IO_POLLS.with(|n| n.get()) >= 2 * REQUESTS);
    RESULTS.with(|r| assert_eq!(r.borrow().len(), REQUESTS));
}

#[test]
fn poisoned_engine_never_accepts_work_or_reports_idle_success() {
    unsafe {
        let engine = ar_hyper_new(1, 1);
        assert!(!engine.is_null());
        let failure = engine_ffi(engine, -2, |_e| {
            panic!("controlled failure");
        });
        assert_eq!(failure, -2);
        let url = b"http://127.0.0.1:1/";
        assert_eq!(
            ar_hyper_submit(
                engine,
                1usize as *mut c_void,
                url.as_ptr(),
                url.len(),
                1000,
                1000,
                std::ptr::null(),
                0
            ),
            -2
        );
        assert_eq!(ar_hyper_drive(engine), -2);
        assert_eq!(ar_hyper_cancel(engine, 1usize as *mut c_void), -2);
        assert_eq!(ar_hyper_timeout(engine), -2);
        assert_eq!(ar_hyper_ready(engine, -1, 0), -2);
        assert_eq!(ar_hyper_fds(engine, std::ptr::null_mut(), 0), usize::MAX);
        assert_eq!(ar_hyper_connections(engine), usize::MAX);
        ar_hyper_free(engine);
    }
}

#[test]
fn resolved_addresses_are_copied_bounded_and_keep_origin_identity() {
    unsafe {
        let engine = ar_hyper_new(4, 2);
        let url = b"http://Example.test:8080/path?q=value";
        let mut bytes = b"127.0.0.1".to_vec();
        let address = Address {
            bytes: bytes.as_ptr(),
            length: bytes.len(),
        };
        assert_eq!(
            ar_hyper_submit(
                engine,
                1usize as *mut c_void,
                url.as_ptr(),
                url.len(),
                1000,
                500,
                &address,
                1
            ),
            0
        );
        bytes.fill(b'x');
        drop(bytes);
        {
            let e = &*engine.cast::<Engine>();
            assert_eq!(
                e.ops[0].addresses.as_slice(),
                ["127.0.0.1:8080".parse::<SocketAddr>().unwrap()]
            );
            assert_eq!(e.ops[0].origin, Origin::Name("example.test:8080".into()));
            assert_eq!(
                e.ops[0].uri.authority().unwrap().as_str(),
                "Example.test:8080"
            );
        }
        let ip = b"::1";
        let second = Address {
            bytes: ip.as_ptr(),
            length: ip.len(),
        };
        for (u, pointer, count) in [
            ("http://example.test/", std::ptr::null(), 0),
            ("http://example.test/", std::ptr::null(), 1),
            ("http://example.test/", &second as *const Address, 17),
            ("http://127.0.0.1/", &second as *const Address, 1),
            ("http://[example.test]/", &second as *const Address, 1),
            ("http://-bad.test/", &second as *const Address, 1),
            ("http://bad..test/", &second as *const Address, 1),
            ("http://bad.test:65536/", &second as *const Address, 1),
            ("https://example.test/", &second as *const Address, 1),
        ] {
            assert_eq!(
                ar_hyper_submit(
                    engine,
                    2usize as *mut c_void,
                    u.as_ptr(),
                    u.len(),
                    1000,
                    500,
                    pointer,
                    count
                ),
                -1
            );
        }
        assert_eq!((*engine.cast::<Engine>()).ops.len(), 1);
        assert_eq!(ar_hyper_connections(engine), 0);
        ar_hyper_free(engine);
    }
}

#[test]
#[ignore = "requires ASYNCARA_TLS_TEST_CERT_DIR from the isolated TLS peer fixture"]
fn tls_uses_only_the_canonical_driver_waker() {
    const REQUESTS: usize = 32;
    let root = std::path::PathBuf::from(
        std::env::var("ASYNCARA_TLS_TEST_CERT_DIR").expect("test certificates"),
    );
    let cert = std::fs::read(root.join("server.pem")).unwrap();
    let key = std::fs::read(root.join("server.key")).unwrap();
    let ca = std::fs::read(root.join("ca.pem")).unwrap();
    let config = tls::config(&cert, &key, &[]).unwrap();
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let url = format!("https://{}/", listener.local_addr().unwrap());
    RESULTS.with(|r| r.borrow_mut().clear());
    IO_POLLS.with(|n| n.set(0));
    let peer = thread::spawn(move || {
        let (stream, _) = listener.accept().unwrap();
        stream
            .set_read_timeout(Some(Duration::from_secs(5)))
            .unwrap();
        stream
            .set_write_timeout(Some(Duration::from_secs(5)))
            .unwrap();
        let mut stream =
            rustls::StreamOwned::new(rustls::ServerConnection::new(config).unwrap(), stream);
        for _ in 0..REQUESTS {
            let mut head = Vec::new();
            let mut byte = [0u8; 1];
            while !head.ends_with(b"\r\n\r\n") {
                stream.read_exact(&mut byte).unwrap();
                head.push(byte[0]);
                assert!(head.len() <= 8192);
            }
            stream
                .write_all(b"HTTP/1.1 200 OK\r\nContent-Length: 65536\r\n\r\n")
                .unwrap();
            stream.write_all(&[b'x'; 65536]).unwrap();
            stream.flush().unwrap();
        }
        // Keep the transport alive until the owner completes every collection.
        let mut byte = [0u8; 1];
        let _ = stream.read(&mut byte);
    });
    unsafe {
        let engine = ar_hyper_new(1, 1);
        assert!(!engine.is_null());
        for id in 1..=REQUESTS {
            assert_eq!(
                ar_hyper_submit_request(
                    engine,
                    id as *mut c_void,
                    url.as_ptr(),
                    url.len(),
                    5000,
                    1000,
                    std::ptr::null(),
                    0,
                    ca.as_ptr(),
                    ca.len()
                ),
                0
            );
        }
        let deadline = Instant::now() + Duration::from_secs(5);
        while RESULTS.with(|r| r.borrow().values().filter(|r| r.1).count()) < REQUESTS {
            assert!(Instant::now() < deadline, "TLS wakeup lost");
            let progress = ar_hyper_drive(engine);
            assert!(progress >= 0);
            let mut fd = libc::pollfd {
                fd: -1,
                events: 0,
                revents: 0,
            };
            let n = ar_hyper_fds(engine, &mut fd, 1);
            assert!(n <= 1);
            assert!(libc::poll(&mut fd, n as _, if progress == 0 { 10 } else { 0 }) >= 0);
            if n == 1 && fd.revents != 0 {
                assert_eq!(ar_hyper_ready(engine, fd.fd, fd.revents as _), 0);
            }
        }
        ar_hyper_free(engine);
    }
    peer.join().unwrap();
    assert!(IO_POLLS.with(|n| n.get()) >= REQUESTS * 2);
    RESULTS.with(|r| assert_eq!(r.borrow().len(), REQUESTS));
}
