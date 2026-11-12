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
extern "C" fn ar_hyper_encoding(_: *mut c_void, coding: i32) -> i32 {
    assert_eq!(coding, 0); // Real codec and compressed bodies use C integration tests.
    0
}
#[no_mangle]
extern "C" fn ar_hyper_decoded_end(_: *mut c_void) -> i32 {
    0
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
fn completed_response_then_eof_releases_dispatch_receiver() {
    RESULTS.with(|r| r.borrow_mut().clear());
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let url = format!("http://{}/", listener.local_addr().unwrap());
    let peer = thread::spawn(move || {
        let (mut stream, _) = listener.accept().unwrap();
        stream
            .set_read_timeout(Some(Duration::from_secs(5)))
            .unwrap();
        stream
            .set_write_timeout(Some(Duration::from_secs(5)))
            .unwrap();
        let mut head = Vec::new();
        let mut byte = [0u8; 1];
        while !head.ends_with(b"\r\n\r\n") {
            stream.read_exact(&mut byte).unwrap();
            head.push(byte[0]);
            assert!(head.len() < 8192);
        }
        // The peer may close even without a Connection: close declaration.
        // Deliver one complete valid response, then EOF, without reading again.
        stream
            .write_all(b"HTTP/1.1 200 OK\r\nContent-Length: 65536\r\n\r\n")
            .unwrap();
        stream.write_all(&[b'x'; 65536]).unwrap();
        stream.shutdown(std::net::Shutdown::Write).unwrap();
    });
    unsafe {
        let engine = ar_hyper_new(1, 1);
        assert_eq!(
            ar_hyper_submit(
                engine,
                1usize as *mut c_void,
                url.as_ptr(),
                url.len(),
                5000,
                1000,
                std::ptr::null(),
                0
            ),
            0
        );
        let deadline = Instant::now() + Duration::from_secs(3);
        while !RESULTS.with(|r| r.borrow().get(&1).is_some_and(|v| v.1)) {
            assert!(
                Instant::now() < deadline,
                "completed response stuck behind retained dispatch receiver"
            );
            let progress = ar_hyper_drive(engine);
            assert!(progress >= 0);
            let mut fd = libc::pollfd {
                fd: -1,
                events: 0,
                revents: 0,
            };
            let count = ar_hyper_fds(engine, &mut fd, 1);
            assert!(count <= 1);
            assert!(libc::poll(&mut fd, count as _, if progress == 0 { 10 } else { 0 }) >= 0);
            if fd.revents != 0 {
                assert_eq!(ar_hyper_ready(engine, fd.fd, fd.revents as _), 0);
            }
        }
        assert!((*engine.cast::<Engine>()).ops.is_empty());
        ar_hyper_free(engine);
    }
    peer.join().unwrap();
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
    const REQUESTS: usize = 64; // retain 32 GETs and add 32 body-bearing POSTs
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
            if head.starts_with(b"POST ") {
                let mut body = [0u8; 65536];
                stream.read_exact(&mut body).unwrap();
                assert!(body.iter().all(|b| *b == b'y'));
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
            let body = [b'y'; 65536];
            let request = RequestData {
                method: b"POST".as_ptr(),
                method_len: 4,
                body: body.as_ptr(),
                body_len: body.len(),
                headers: std::ptr::null(),
                header_count: 0,
            };
            assert_eq!(
                ar_hyper_submit_http(
                    engine,
                    id as *mut c_void,
                    url.as_ptr(),
                    url.len(),
                    5000,
                    1000,
                    std::ptr::null(),
                    0,
                    ca.as_ptr(),
                    ca.len(),
                    if id <= 32 { std::ptr::null() } else { &request }
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

#[test]
fn request_metadata_and_body_are_owned_and_bounded() {
    unsafe {
        let mut body = vec![0, 1, 255];
        let mut header = b"X-Test: original".to_vec();
        let mut headers = [Address {
            bytes: header.as_ptr(),
            length: header.len(),
        }];
        let mut raw = RequestData {
            method: b"PUT".as_ptr(),
            method_len: 3,
            body: body.as_ptr(),
            body_len: body.len(),
            headers: headers.as_ptr(),
            header_count: 1,
        };
        let copy = RequestSpec::copy(&raw).unwrap();
        body.fill(3);
        header.fill(b'x');
        assert_eq!(copy.body.as_ref(), &[0, 1, 255]);
        assert_eq!(copy.headers["x-test"], "original");
        assert_eq!(copy.method, hyper::Method::PUT);
        raw.header_count = 0;
        raw.body_len = 16 * 1024 * 1024 + 1;
        assert!(RequestSpec::copy(&raw).is_err()); // rejects before touching short body pointer
        raw.body_len = 0;
        raw.header_count = 257;
        assert!(RequestSpec::copy(&raw).is_err());
        raw.header_count = 1;
        for invalid in [
            "Host: other",
            "Content-Length: 0",
            "Transfer-Encoding: chunked",
            "X: a\r\nb: c",
            "X:\0",
            "X: ",
            "X",
        ] {
            headers[0] = Address {
                bytes: invalid.as_ptr(),
                length: invalid.len(),
            };
            assert!(RequestSpec::copy(&raw).is_err(), "{invalid:?}");
        }
        raw.header_count = 0;
        for invalid in ["CONNECT", "TRACE", "get", "GET ", ""] {
            raw.method = invalid.as_ptr();
            raw.method_len = invalid.len();
            assert!(RequestSpec::copy(&raw).is_err());
        }
        raw.method = b"HEAD".as_ptr();
        raw.method_len = 4;
        raw.body_len = 1;
        assert!(RequestSpec::copy(&raw).is_err());
        raw.body_len = 0;
        assert!(RequestSpec::copy(&raw).is_ok());
        raw.method = b"POST".as_ptr();
        let maximum = vec![b'a'; 16 * 1024 * 1024];
        raw.body = maximum.as_ptr();
        raw.body_len = maximum.len();
        assert_eq!(RequestSpec::copy(&raw).unwrap().body.len(), maximum.len());
    }
}

#[test]
#[ignore = "requires ASYNCARA_TLS_TEST_CERT_DIR private CA fixture"]
fn established_tls_reads_while_application_writes_are_blocked() {
    use hyper::rt::{Read as _, Write as _};
    let root = std::path::PathBuf::from(std::env::var("ASYNCARA_TLS_TEST_CERT_DIR").unwrap());
    let cert = std::fs::read(root.join("server.pem")).unwrap();
    let key = std::fs::read(root.join("server.key")).unwrap();
    let ca = std::fs::read(root.join("ca.pem")).unwrap();
    let config = tls::config(&cert, &key, &[]).unwrap();
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let address = listener.local_addr().unwrap();
    let (ready_tx, ready_rx) = std::sync::mpsc::channel();
    let (reply_tx, reply_rx) = std::sync::mpsc::channel();
    let (sent_tx, sent_rx) = std::sync::mpsc::channel();
    let (end_tx, end_rx) = std::sync::mpsc::channel::<()>();
    let peer = thread::spawn(move || {
        let (mut stream, _) = listener.accept().unwrap();
        stream
            .set_read_timeout(Some(Duration::from_secs(5)))
            .unwrap();
        stream
            .set_write_timeout(Some(Duration::from_secs(5)))
            .unwrap();
        let size = 4096i32;
        assert_eq!(
            unsafe {
                libc::setsockopt(
                    stream.as_raw_fd(),
                    libc::SOL_SOCKET,
                    libc::SO_RCVBUF,
                    (&size as *const i32).cast(),
                    4,
                )
            },
            0
        );
        let mut session = rustls::ServerConnection::new(config).unwrap();
        while session.is_handshaking() {
            session.complete_io(&mut stream).unwrap();
        }
        ready_tx.send(()).unwrap();
        reply_rx.recv_timeout(Duration::from_secs(5)).unwrap();
        // Deliberately never read application data: the client's write stays blocked.
        session.writer().write_all(b"early").unwrap();
        while session.wants_write() {
            session.write_tls(&mut stream).unwrap();
        }
        sent_tx.send(()).unwrap();
        let _ = end_rx.recv_timeout(Duration::from_secs(5));
    });
    let stream = TcpStream::connect(address).unwrap();
    stream.set_nonblocking(true).unwrap();
    let size = 4096i32;
    assert_eq!(
        unsafe {
            libc::setsockopt(
                stream.as_raw_fd(),
                libc::SOL_SOCKET,
                libc::SO_SNDBUF,
                (&size as *const i32).cast(),
                4,
            )
        },
        0
    );
    let flag = Flag::new();
    let waker = Waker::from(flag.clone());
    let io = Io(Rc::new(RefCell::new(Socket {
        stream,
        readable: true,
        writable: true,
        read: None,
        write: None,
        owner_waker: waker.clone(),
    })));
    let ready = Rc::new(Cell::new(false));
    let mut transport = tls::TlsIo::client(
        io.clone(),
        tls::client_config(&ca, &[], &[]).unwrap(),
        "localhost".try_into().unwrap(),
        ready.clone(),
    )
    .unwrap();
    let mut cx = Context::from_waker(&waker);
    let deadline = Instant::now() + Duration::from_secs(5);
    while !ready.get() {
        assert!(Instant::now() < deadline, "handshake deadline");
        if let Poll::Ready(v) = Pin::new(&mut transport).poll_flush(&mut cx) {
            v.unwrap();
        }
        let mut s = io.0.borrow_mut();
        let mut fd = libc::pollfd {
            fd: s.stream.as_raw_fd(),
            events: if s.write.is_some() {
                libc::POLLOUT
            } else {
                libc::POLLIN
            },
            revents: 0,
        };
        assert!(unsafe { libc::poll(&mut fd, 1, 10) } >= 0);
        if fd.revents & libc::POLLIN != 0 {
            s.readable = true;
            s.read.take();
        }
        if fd.revents & libc::POLLOUT != 0 {
            s.writable = true;
            s.write.take();
        }
    }
    ready_rx.recv_timeout(Duration::from_secs(5)).unwrap();
    let block = [b'x'; 65536];
    let mut written = 0;
    loop {
        assert!(written < 16777216, "socket never blocked");
        match Pin::new(&mut transport).poll_write(&mut cx, &block) {
            Poll::Ready(Ok(n)) => {
                assert!(n > 0);
                written += n;
            }
            Poll::Ready(Err(e)) => panic!("write: {e}"),
            Poll::Pending => break,
        }
    }
    assert!(
        !io.0.borrow().writable && io.0.borrow().write.is_some(),
        "actual kernel EAGAIN required"
    );
    reply_tx.send(()).unwrap();
    sent_rx.recv_timeout(Duration::from_secs(5)).unwrap();
    let mut fd = libc::pollfd {
        fd: io.0.borrow().stream.as_raw_fd(),
        events: libc::POLLIN,
        revents: 0,
    };
    assert_eq!(unsafe { libc::poll(&mut fd, 1, 1000) }, 1);
    assert_ne!(fd.revents & libc::POLLIN, 0);
    io.0.borrow_mut().readable = true; // same readiness delivery as ar_hyper_ready
    let mut bytes = [0u8; 5];
    let mut buf = hyper::rt::ReadBuf::new(&mut bytes);
    let result = Pin::new(&mut transport).poll_read(&mut cx, buf.unfilled());
    assert!(
        matches!(result, Poll::Ready(Ok(()))),
        "read was coupled to blocked application write: {result:?}"
    );
    assert_eq!(buf.filled(), b"early");
    drop(transport);
    drop(io);
    end_tx.send(()).unwrap();
    peer.join().unwrap();
}

thread_local! {
    static UPLOAD_SLOT: RefCell<(i32, Vec<u8>)> = const { RefCell::new((1, Vec::new())) };
}
#[no_mangle]
unsafe extern "C" fn ar_hyper_upload(
    _: *mut c_void,
    data: *mut *const u8,
    length: *mut usize,
) -> i32 {
    UPLOAD_SLOT.with(|slot| {
        let mut slot = slot.borrow_mut();
        let state = slot.0;
        if state == 0 {
            *data = slot.1.as_ptr();
            *length = slot.1.len();
            slot.0 = 1;
        }
        state
    })
}

#[test]
fn streamed_upload_slot_owns_copy_and_never_self_wakes_while_pending() {
    use hyper::body::Body;
    let wake = Flag::new();
    wake.take();
    let waker = Waker::from(wake.clone());
    let mut cx = Context::from_waker(&waker);
    let mut body = ClientBody::new(1, Bytes::new(), Some(-1));
    UPLOAD_SLOT.with(|slot| *slot.borrow_mut() = (1, Vec::new()));
    for _ in 0..100 {
        assert!(Pin::new(&mut body).poll_frame(&mut cx).is_pending());
    }
    assert!(!wake.take(), "empty upload slot busy-wakes");
    UPLOAD_SLOT.with(|slot| *slot.borrow_mut() = (0, vec![b'x'; 65536]));
    let Poll::Ready(Some(Ok(frame))) = Pin::new(&mut body).poll_frame(&mut cx) else {
        panic!("missing frame")
    };
    UPLOAD_SLOT.with(|slot| slot.borrow_mut().1.fill(b'y'));
    assert!(
        frame.data_ref().unwrap().iter().all(|&b| b == b'x'),
        "borrow survived refill"
    );
    UPLOAD_SLOT.with(|slot| slot.borrow_mut().0 = 2);
    assert!(matches!(
        Pin::new(&mut body).poll_frame(&mut cx),
        Poll::Ready(None)
    ));
    assert!(body.is_end_stream());
}

#[test]
fn streamed_upload_checks_fixed_length_and_invalid_slots() {
    use hyper::body::Body;
    let waker = Waker::from(Flag::new());
    let mut cx = Context::from_waker(&waker);
    for (state, size) in [(2, 0), (0, 65537), (0, 11), (-1, 0)] {
        let mut body = ClientBody::new(1, Bytes::new(), Some(10));
        UPLOAD_SLOT.with(|slot| *slot.borrow_mut() = (state, vec![b'x'; size]));
        assert!(matches!(
            Pin::new(&mut body).poll_frame(&mut cx),
            Poll::Ready(Some(Err(_)))
        ));
        assert!(body.is_end_stream());
    }
    let mut body = ClientBody::new(1, Bytes::new(), Some(10));
    assert_eq!(body.size_hint().exact(), Some(10));
    UPLOAD_SLOT.with(|slot| *slot.borrow_mut() = (0, vec![b'x'; 10]));
    assert!(matches!(
        Pin::new(&mut body).poll_frame(&mut cx),
        Poll::Ready(Some(Ok(_)))
    ));
    assert_eq!(body.size_hint().exact(), Some(0));
    assert!(body.is_end_stream());
}
