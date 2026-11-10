//! Experimental HTTP/1.1 bridge. Plain-HTTP GET; DNS supplied by Asyncara's
//! existing resolver workers. No Tokio executor or blocking socket calls. Asyncara owns readiness,
//! request deadlines and response buffers. No Zend API crosses this boundary.
#[cfg(not(target_os = "linux"))]
compile_error!("The Asyncara Hyper prototype currently supports Linux only");
use http_body_util::{BodyExt, Full};
use hyper::{body::Bytes, Request, Uri};
mod server;
use std::{
    cell::RefCell,
    ffi::c_void,
    future::Future,
    io,
    net::{SocketAddr, TcpStream},
    os::fd::{AsRawFd, FromRawFd},
    panic::{catch_unwind, AssertUnwindSafe},
    pin::Pin,
    rc::Rc,
    sync::{
        atomic::{AtomicBool, Ordering},
        Arc,
    },
    task::{Context, Poll, Wake, Waker},
    time::{Duration, Instant},
};
// No fault controls or exported setter exist in a normal build.
#[cfg(feature = "ffi-fault-tests")]
thread_local! { static TEST_FAULT: std::cell::Cell<u32> = const { std::cell::Cell::new(0) }; }
#[cfg(feature = "ffi-fault-tests")]
#[no_mangle]
pub extern "C" fn ar_hyper_test_fault(point: u32) {
    TEST_FAULT.with(|f| f.set(point));
}
#[inline]
fn fault(point: u32) {
    #[cfg(feature = "ffi-fault-tests")]
    TEST_FAULT.with(|f| {
        if f.get() == point {
            f.set(0);
            panic!("injected Hyper boundary fault {point}");
        }
    });
    #[cfg(not(feature = "ffi-fault-tests"))]
    let _ = point;
}
type Sender = hyper::client::conn::http1::SendRequest<Full<Bytes>>;
type Driver = Pin<Box<dyn Future<Output = Result<(), hyper::Error>>>>;
type Setup = Pin<
    Box<
        dyn Future<
            Output = Result<
                (
                    Sender,
                    hyper::client::conn::http1::Connection<Io, Full<Bytes>>,
                ),
                hyper::Error,
            >,
        >,
    >,
>;
type Transfer = Pin<Box<dyn Future<Output = (Sender, Result<(), String>)>>>;
extern "C" {
    fn ar_hyper_head(job: *mut c_void, status: i32, p: *const u8, n: usize) -> i32;
    fn ar_hyper_chunk(job: *mut c_void, p: *const u8, n: usize) -> i32;
    fn ar_hyper_complete(job: *mut c_void, error: *const u8, n: usize);
}
#[derive(Default)]
struct Flag(AtomicBool);
impl Flag {
    fn new() -> Arc<Self> {
        Arc::new(Self(AtomicBool::new(true)))
    }
    fn take(&self) -> bool {
        self.0.swap(false, Ordering::Relaxed)
    }
    fn set(&self) {
        self.0.store(true, Ordering::Relaxed)
    }
}
impl Wake for Flag {
    fn wake(self: Arc<Self>) {
        self.set()
    }
    fn wake_by_ref(self: &Arc<Self>) {
        self.set()
    }
}
struct Socket {
    stream: TcpStream,
    readable: bool,
    writable: bool,
    read: Option<Waker>,
    write: Option<Waker>,
    #[cfg(test)]
    owner_waker: Waker,
}
#[derive(Clone)]
struct Io(Rc<RefCell<Socket>>);
impl hyper::rt::Read for Io {
    fn poll_read(
        self: Pin<&mut Self>,
        cx: &mut Context<'_>,
        mut buf: hyper::rt::ReadBufCursor<'_>,
    ) -> Poll<io::Result<()>> {
        let mut s = self.0.borrow_mut();
        #[cfg(test)]
        {
            assert!(
                cx.waker().will_wake(&s.owner_waker),
                "non-driver read waker"
            );
            tests::IO_POLLS.with(|n| n.set(n.get() + 1));
        }
        if !s.readable {
            s.read = Some(cx.waker().clone());
            return Poll::Pending;
        }
        // SAFETY: libc recv initializes at most the cursor's available bytes; only
        // its nonnegative returned count is advanced. Nothing reads uninitialized data.
        let n = unsafe {
            let bytes = buf.as_mut();
            libc::recv(
                s.stream.as_raw_fd(),
                bytes.as_mut_ptr().cast(),
                bytes.len(),
                0,
            )
        };
        if n >= 0 {
            unsafe { buf.advance(n as usize) };
            return Poll::Ready(Ok(()));
        }
        let error = io::Error::last_os_error();
        if error.kind() == io::ErrorKind::WouldBlock {
            s.readable = false;
            s.read = Some(cx.waker().clone());
            Poll::Pending
        } else if error.kind() == io::ErrorKind::Interrupted {
            cx.waker().wake_by_ref();
            Poll::Pending
        } else {
            Poll::Ready(Err(error))
        }
    }
}
impl hyper::rt::Write for Io {
    fn poll_write(
        self: Pin<&mut Self>,
        cx: &mut Context<'_>,
        buf: &[u8],
    ) -> Poll<io::Result<usize>> {
        let mut s = self.0.borrow_mut();
        #[cfg(test)]
        {
            assert!(
                cx.waker().will_wake(&s.owner_waker),
                "non-driver write waker"
            );
            tests::IO_POLLS.with(|n| n.set(n.get() + 1));
        }
        if !s.writable {
            s.write = Some(cx.waker().clone());
            return Poll::Pending;
        }
        let n = unsafe {
            libc::send(
                s.stream.as_raw_fd(),
                buf.as_ptr().cast(),
                buf.len(),
                libc::MSG_NOSIGNAL,
            )
        };
        if n >= 0 {
            return Poll::Ready(Ok(n as usize));
        }
        let error = io::Error::last_os_error();
        if error.kind() == io::ErrorKind::WouldBlock {
            s.writable = false;
            s.write = Some(cx.waker().clone());
            Poll::Pending
        } else if error.kind() == io::ErrorKind::Interrupted {
            cx.waker().wake_by_ref();
            Poll::Pending
        } else {
            Poll::Ready(Err(error))
        }
    }
    fn poll_flush(self: Pin<&mut Self>, _: &mut Context<'_>) -> Poll<io::Result<()>> {
        Poll::Ready(Ok(()))
    }
    fn poll_shutdown(self: Pin<&mut Self>, _: &mut Context<'_>) -> Poll<io::Result<()>> {
        Poll::Ready(self.0.borrow().stream.shutdown(std::net::Shutdown::Write))
    }
}
#[derive(Clone, Debug, PartialEq, Eq)]
enum Origin {
    Numeric(SocketAddr),
    Name(String),
}
// Preserve the existing allocation-free numeric endpoint path. DNS owns a
// bounded address vector; numeric benchmark traffic does not allocate one.
enum Addresses {
    One(SocketAddr),
    Many(Vec<SocketAddr>),
}
impl Addresses {
    fn as_slice(&self) -> &[SocketAddr] {
        match self {
            Self::One(addr) => std::slice::from_ref(addr),
            Self::Many(addresses) => addresses,
        }
    }
}
struct Conn {
    addr: SocketAddr,
    origin: Origin,
    io: Io,
    connecting: bool,
    connect_deadline: Instant,
    setup: Option<Setup>,
    sender: Option<Sender>,
    driver: Option<Driver>,
    wake: Arc<Flag>,
    busy: bool,
    closed: bool,
}
impl Conn {
    fn new(addr: SocketAddr, origin: Origin, deadline: Instant) -> io::Result<Self> {
        let fd = unsafe {
            libc::socket(
                if addr.is_ipv4() {
                    libc::AF_INET
                } else {
                    libc::AF_INET6
                },
                libc::SOCK_STREAM | libc::SOCK_NONBLOCK | libc::SOCK_CLOEXEC,
                0,
            )
        };
        if fd < 0 {
            return Err(io::Error::last_os_error());
        }
        // SAFETY: this newly-created fd has exactly one owning TcpStream.
        let stream = unsafe { TcpStream::from_raw_fd(fd) };
        stream.set_nodelay(true)?;
        let rc = match addr {
            SocketAddr::V4(a) => {
                let sa = libc::sockaddr_in {
                    sin_family: libc::AF_INET as _,
                    sin_port: a.port().to_be(),
                    sin_addr: libc::in_addr {
                        s_addr: u32::from_ne_bytes(a.ip().octets()),
                    },
                    sin_zero: [0; 8],
                };
                unsafe {
                    libc::connect(
                        fd,
                        (&sa as *const libc::sockaddr_in).cast(),
                        std::mem::size_of_val(&sa) as _,
                    )
                }
            }
            SocketAddr::V6(a) => {
                let sa = libc::sockaddr_in6 {
                    sin6_family: libc::AF_INET6 as _,
                    sin6_port: a.port().to_be(),
                    sin6_addr: libc::in6_addr {
                        s6_addr: a.ip().octets(),
                    },
                    sin6_flowinfo: 0,
                    sin6_scope_id: a.scope_id(),
                };
                unsafe {
                    libc::connect(
                        fd,
                        (&sa as *const libc::sockaddr_in6).cast(),
                        std::mem::size_of_val(&sa) as _,
                    )
                }
            }
        };
        if rc < 0 && io::Error::last_os_error().raw_os_error() != Some(libc::EINPROGRESS) {
            return Err(io::Error::last_os_error());
        }
        let wake = Flag::new();
        let io = Io(Rc::new(RefCell::new(Socket {
            stream,
            readable: true,
            writable: rc == 0,
            read: None,
            write: if rc == 0 {
                None
            } else {
                Some(Waker::from(wake.clone()))
            },
            #[cfg(test)]
            owner_waker: Waker::from(wake.clone()),
        })));
        Ok(Self {
            addr,
            origin,
            io,
            connecting: rc < 0,
            connect_deadline: deadline,
            setup: None,
            sender: None,
            driver: None,
            wake,
            busy: true,
            closed: false,
        })
    }
    fn poll(&mut self) {
        let w = Waker::from(self.wake.clone());
        let mut cx = Context::from_waker(&w);
        if self.connecting {
            if !self.io.0.borrow().writable {
                return;
            }
            if !matches!(self.io.0.borrow().stream.take_error(), Ok(None)) {
                self.closed = true;
                return;
            }
            self.connecting = false;
        }
        if self.setup.is_none() && self.driver.is_none() && self.sender.is_none() {
            let mut b = hyper::client::conn::http1::Builder::new();
            // Permit fewer reads for medium bodies without enlarging the header
            // or trailer acceptance limit. The buffer remains adaptive; small
            // responses do not eagerly reserve the 128 KiB maximum.
            b.max_buf_size(131072)
                .max_header_size(65536)
                .max_headers(256);
            self.setup = Some(Box::pin(b.handshake(self.io.clone())));
        }
        if let Some(setup) = &mut self.setup {
            match setup.as_mut().poll(&mut cx) {
                Poll::Ready(Ok((sender, driver))) => {
                    self.sender = Some(sender);
                    self.driver = Some(Box::pin(driver));
                    self.setup = None
                }
                Poll::Ready(Err(_)) => {
                    self.closed = true;
                    self.setup = None
                }
                Poll::Pending => return,
            }
        }
        if let Some(driver) = &mut self.driver {
            if driver.as_mut().poll(&mut cx).is_ready() {
                self.closed = true
            }
        }
    }
}
struct Op {
    job: usize,
    uri: Uri,
    origin: Origin,
    addresses: Addresses,
    address_index: usize,
    deadline: Instant,
    connect_ms: u64,
    connect_deadline: Option<Instant>,
    conn: Option<usize>,
    future: Option<Transfer>,
    wake: Arc<Flag>,
}
struct Engine {
    poisoned: bool,
    conns: Vec<Option<Conn>>,
    ops: Vec<Op>,
    total: usize,
    host: usize,
}
async fn transfer(mut sender: Sender, uri: Uri, job: usize) -> (Sender, Result<(), String>) {
    let outcome = async {
        let request = Request::builder()
            .uri(uri.path_and_query().map(|p| p.as_str()).unwrap_or("/"))
            .header("Host", uri.authority().ok_or("Missing authority")?.as_str())
            .header("Accept-Encoding", "identity")
            .body(Full::new(Bytes::new()))
            .map_err(|_| "Invalid request")?;
        let response = sender
            .send_request(request)
            .await
            .map_err(|_| "Hyper request failed")?;
        if response
            .headers()
            .get_all("content-encoding")
            .iter()
            .any(|v| v.as_bytes() != b"identity")
        {
            return Err("Hyper prototype does not decode compressed responses".into());
        }
        let mut headers = format!(
            "HTTP/1.1 {} {}\r\n",
            response.status().as_u16(),
            response.status().canonical_reason().unwrap_or("")
        )
        .into_bytes();
        for (name, value) in response.headers() {
            if name.as_str().len() + value.as_bytes().len() + 4 > 65536 - headers.len() {
                return Err("HTTP header limit exceeded".into());
            }
            headers.extend_from_slice(name.as_str().as_bytes());
            headers.extend_from_slice(b": ");
            headers.extend_from_slice(value.as_bytes());
            headers.extend_from_slice(b"\r\n");
        }
        if unsafe {
            ar_hyper_head(
                job as *mut c_void,
                response.status().as_u16() as _,
                headers.as_ptr(),
                headers.len(),
            )
        } != 0
        {
            return Err("HTTP header buffer rejected".into());
        }
        let mut body = response.into_body();
        while let Some(frame) = body.frame().await {
            let frame = frame.map_err(|_| "Hyper partial transfer")?;
            if let Some(data) = frame.data_ref() {
                if unsafe { ar_hyper_chunk(job as *mut c_void, data.as_ptr(), data.len()) } != 0 {
                    return Err("HTTP body buffer rejected".into());
                }
            }
            if let Some(trailers) = frame.trailers_ref() {
                let mut raw = Vec::new();
                for (name, value) in trailers {
                    if name.as_str().len() + value.as_bytes().len() + 4 > 65536 - raw.len() {
                        return Err("HTTP trailer limit exceeded".into());
                    }
                    raw.extend_from_slice(name.as_str().as_bytes());
                    raw.extend_from_slice(b": ");
                    raw.extend_from_slice(value.as_bytes());
                    raw.extend_from_slice(b"\r\n");
                }
                if unsafe { ar_hyper_head(job as *mut c_void, 0, raw.as_ptr(), raw.len()) } != 0 {
                    return Err("HTTP trailer buffer rejected".into());
                }
            }
        }
        Ok(())
    }
    .await;
    (sender, outcome)
}
impl Engine {
    // Retry only before a request future is created, so no bytes are replayed.
    // The same connect budget covers every candidate address.
    fn next_address(&mut self, i: usize) -> bool {
        let op = &mut self.ops[i];
        if op.future.is_some() || op.address_index + 1 >= op.addresses.as_slice().len() {
            return false;
        }
        if let Some(c) = op.conn.take() {
            self.conns[c] = None;
        }
        op.address_index += 1;
        true
    }
    fn finish(&mut self, i: usize, error: Option<&str>) {
        let op = self.ops.swap_remove(i);
        fault(13);
        if let Some(c) = op.conn {
            if error.is_some() || self.conns[c].as_ref().is_some_and(|x| x.closed) {
                self.conns[c] = None
            } else if let Some(conn) = &mut self.conns[c] {
                conn.busy = false
            }
        }
        let message = error.unwrap_or("");
        unsafe { ar_hyper_complete(op.job as *mut c_void, message.as_ptr(), message.len()) }
        fault(18);
    }
    fn drive(&mut self) -> i32 {
        let now = Instant::now();
        let mut i = 0;
        while i < self.ops.len() {
            let op = &self.ops[i];
            if op.deadline <= now
                || op
                    .conn
                    .and_then(|c| self.conns[c].as_ref())
                    .is_some_and(|c| c.connecting && c.connect_deadline <= now)
            {
                self.finish(i, Some("HTTP operation timed out"))
            } else {
                i += 1
            }
        }
        let mut budget = 512;
        loop {
            let mut progress = false;
            for i in 0..self.ops.len() {
                if self.ops[i].conn.is_some() {
                    continue;
                }
                let addr = self.ops[i].addresses.as_slice()[self.ops[i].address_index];
                let origin = &self.ops[i].origin;
                if self
                    .conns
                    .iter()
                    .flatten()
                    .filter(|c| c.busy && &c.origin == origin)
                    .count()
                    >= self.host
                {
                    continue;
                }
                let idle = self.conns.iter().position(|c| {
                    c.as_ref().is_some_and(|c| {
                        !c.busy
                            && !c.closed
                            && &c.origin == origin
                            && self.ops[i].addresses.as_slice().contains(&c.addr)
                    })
                });
                let at = if let Some(at) = idle {
                    at
                } else {
                    let hole = self.conns.iter().position(Option::is_none);
                    let slot = if let Some(at) = hole {
                        at
                    } else if self.conns.len() < self.total {
                        self.conns.push(None);
                        self.conns.len() - 1
                    } else if let Some(at) = self
                        .conns
                        .iter()
                        .position(|c| c.as_ref().is_some_and(|c| !c.busy))
                    {
                        self.conns[at] = None;
                        at
                    } else {
                        continue;
                    };
                    let op = &mut self.ops[i];
                    let deadline = *op.connect_deadline.get_or_insert_with(|| {
                        Instant::now() + Duration::from_millis(op.connect_ms)
                    });
                    if deadline <= Instant::now() {
                        self.finish(i, Some("HTTP operation timed out"));
                        return 1;
                    }
                    match Conn::new(addr, op.origin.clone(), deadline) {
                        Ok(conn) => {
                            self.conns[slot] = Some(conn);
                            slot
                        }
                        Err(_) => {
                            if !self.next_address(i) {
                                self.finish(i, Some("HTTP connect failed"));
                            }
                            return 1;
                        }
                    }
                };
                self.ops[i].conn = Some(at);
                self.conns[at].as_mut().unwrap().busy = true;
                progress = true;
            }
            for c in &mut self.conns {
                if let Some(conn) = c {
                    if !conn.closed && conn.wake.take() {
                        conn.poll();
                        progress = true;
                        budget -= 1;
                        if budget == 0 {
                            return 1;
                        }
                    }
                    if conn.closed && !conn.busy {
                        *c = None
                    }
                }
            }
            let mut i = 0;
            while i < self.ops.len() {
                let Some(at) = self.ops[i].conn else {
                    i += 1;
                    continue;
                };
                if self.ops[i].future.is_none() {
                    let conn = self.conns[at].as_mut().unwrap();
                    if conn.closed {
                        if self.next_address(i) {
                            i += 1;
                        } else {
                            self.finish(i, Some("HTTP connection closed"));
                        }
                        progress = true;
                        continue;
                    }
                    if let Some(sender) = conn.sender.take() {
                        let op = &mut self.ops[i];
                        op.future = Some(Box::pin(transfer(sender, op.uri.clone(), op.job)));
                        op.wake.set();
                        progress = true
                    }
                }
                let op = &mut self.ops[i];
                if let Some(f) = &mut op.future {
                    if op.wake.take() {
                        let w = Waker::from(op.wake.clone());
                        let mut cx = Context::from_waker(&w);
                        let result = f.as_mut().poll(&mut cx);
                        progress = true;
                        budget -= 1;
                        if let Poll::Ready((sender, result)) = result {
                            if let Some(conn) = &mut self.conns[at] {
                                conn.sender = Some(sender)
                            }
                            self.finish(i, result.as_ref().err().map(String::as_str));
                            if budget == 0 {
                                return 1;
                            }
                            continue;
                        }
                        if budget == 0 {
                            return 1;
                        }
                    }
                }
                i += 1;
            }
            if !progress {
                return 0;
            }
        }
    }
}
fn ffi<T>(fallback: T, f: impl FnOnce() -> T) -> T {
    catch_unwind(AssertUnwindSafe(f)).unwrap_or(fallback)
}
// The C bridge serializes all entry points on the interpreter thread. Callbacks
// only copy bounded bytes/publish completion; they must not reenter this engine.
// -1 remains a validation/no-deadline result. -2 (or SIZE_MAX for counts) is a
// fatal engine result: C must destroy the engine and fail every owned operation.
// Even if a caller overlooks that result, no later entry drives poisoned state.
unsafe fn engine_ffi<T>(ptr: *mut c_void, failure: T, f: impl FnOnce(&mut Engine) -> T) -> T {
    if ptr.is_null() {
        return failure;
    }
    let engine = &mut *ptr.cast::<Engine>();
    if engine.poisoned {
        return failure;
    }
    match catch_unwind(AssertUnwindSafe(|| f(engine))) {
        Ok(value) => value,
        Err(_) => {
            engine.poisoned = true;
            failure
        }
    }
}
#[no_mangle]
pub extern "C" fn ar_hyper_new(total: usize, host: usize) -> *mut c_void {
    ffi(std::ptr::null_mut(), || {
        if total == 0 || total > 256 || host == 0 || host > total {
            return std::ptr::null_mut();
        }
        Box::into_raw(Box::new(Engine {
            poisoned: false,
            conns: Vec::new(),
            ops: Vec::new(),
            total,
            host,
        }))
        .cast()
    })
}
#[no_mangle]
pub unsafe extern "C" fn ar_hyper_free(ptr: *mut c_void) {
    if !ptr.is_null() {
        drop(Box::from_raw(ptr.cast::<Engine>()))
    }
}
#[repr(C)]
pub struct Address {
    bytes: *const u8,
    length: usize,
}
fn valid_dns_name(host: &str) -> bool {
    host.len() <= 253
        && host
            .strip_suffix('.')
            .unwrap_or(host)
            .split('.')
            .all(|label| {
                !label.is_empty()
                    && label.len() <= 63
                    && label.as_bytes()[0].is_ascii_alphanumeric()
                    && label.as_bytes()[label.len() - 1].is_ascii_alphanumeric()
                    && label
                        .bytes()
                        .all(|c| c.is_ascii_alphanumeric() || c == b'-')
            })
}
#[no_mangle]
pub unsafe extern "C" fn ar_hyper_submit(
    ptr: *mut c_void,
    job: *mut c_void,
    url: *const u8,
    len: usize,
    timeout: u64,
    connect: u64,
    addresses: *const Address,
    count: usize,
) -> i32 {
    engine_ffi(ptr, -2, |engine| {
        if ptr.is_null()
            || job.is_null()
            || url.is_null()
            || len > 8192
            || timeout == 0
            || timeout > 3600000
            || connect == 0
            || connect > 3600000
            || count > 16
            || (count != 0 && addresses.is_null())
        {
            return -1;
        }
        if engine.ops.len() >= 1024 {
            return -1;
        }
        let Ok(text) = std::str::from_utf8(std::slice::from_raw_parts(url, len)) else {
            return -1;
        };
        let Ok(uri) = text.parse::<Uri>() else {
            return -1;
        };
        if uri.scheme_str() != Some("http") {
            return -1;
        }
        let Some(authority) = uri.authority() else {
            return -1;
        };
        if authority.as_str().contains('@') {
            return -1;
        }
        // Authority::port_u16() also returns None for an invalid explicit port.
        // Only an absent suffix may select port 80; never silently redirect an
        // invalid authority to a different endpoint. Bracketed IPv6 is retained
        // here so its internal colons are not confused with the port separator.
        let raw_host = authority.host();
        let port = match authority.as_str().strip_prefix(raw_host) {
            Some("") => 80,
            Some(suffix) => {
                let Some(digits) = suffix.strip_prefix(':') else {
                    return -1;
                };
                if digits.is_empty() || !digits.bytes().all(|c| c.is_ascii_digit()) {
                    return -1;
                }
                let Ok(port) = digits.parse::<u16>() else {
                    return -1;
                };
                port
            }
            None => return -1,
        };
        let host = raw_host.trim_matches(['[', ']']);
        let (candidates, origin) = if let Ok(ip) = host.parse() {
            if count != 0 {
                return -1;
            } // Numeric origins cannot be redirected.
            let addr = SocketAddr::new(ip, port);
            (Addresses::One(addr), Origin::Numeric(addr))
        } else {
            if count == 0 || !valid_dns_name(raw_host) {
                return -1;
            }
            let mut candidates = Vec::with_capacity(count);
            for address in std::slice::from_raw_parts(addresses, count) {
                if address.bytes.is_null() || address.length == 0 || address.length > 45 {
                    return -1;
                }
                let Ok(text) =
                    std::str::from_utf8(std::slice::from_raw_parts(address.bytes, address.length))
                else {
                    return -1;
                };
                let Ok(ip) = text.parse() else {
                    return -1;
                };
                let address = SocketAddr::new(ip, port);
                if !candidates.contains(&address) {
                    candidates.push(address);
                }
            }
            (
                Addresses::Many(candidates),
                Origin::Name(authority.as_str().to_ascii_lowercase()),
            )
        };
        engine.ops.push(Op {
            job: job as usize,
            uri,
            origin,
            addresses: candidates,
            address_index: 0,
            deadline: Instant::now() + Duration::from_millis(timeout),
            connect_ms: connect,
            connect_deadline: None,
            conn: None,
            future: None,
            wake: Flag::new(),
        });
        fault(11);
        0
    })
}
#[no_mangle]
pub unsafe extern "C" fn ar_hyper_drive(ptr: *mut c_void) -> i32 {
    engine_ffi(ptr, -2, |e| {
        fault(12);
        e.drive()
    })
}
#[no_mangle]
pub unsafe extern "C" fn ar_hyper_cancel(ptr: *mut c_void, job: *mut c_void) -> i32 {
    engine_ffi(ptr, -2, |e| {
        if let Some(i) = e.ops.iter().position(|op| op.job == job as usize) {
            e.finish(i, Some("Operation cancelled"));
        }
        0
    })
}
#[no_mangle]
pub unsafe extern "C" fn ar_hyper_timeout(ptr: *mut c_void) -> i32 {
    engine_ffi(ptr, -2, |e| {
        fault(14);
        let now = Instant::now();
        let deadline = e
            .ops
            .iter()
            .map(|op| op.deadline)
            .chain(
                e.conns
                    .iter()
                    .flatten()
                    .filter(|c| c.connecting)
                    .map(|c| c.connect_deadline),
            )
            .min();
        deadline
            .map(|t| {
                let ns = t.saturating_duration_since(now).as_nanos();
                ((ns + 999999) / 1000000).min(i32::MAX as u128) as i32
            })
            .unwrap_or(-1)
    })
}
#[no_mangle]
pub unsafe extern "C" fn ar_hyper_fds(
    ptr: *mut c_void,
    out: *mut libc::pollfd,
    max: usize,
) -> usize {
    engine_ffi(ptr, usize::MAX, |e| {
        fault(15);
        let mut n = 0;
        for c in e.conns.iter().flatten() {
            if c.closed {
                continue;
            }
            let s = c.io.0.borrow();
            let events = (if s.read.is_some() { libc::POLLIN } else { 0 })
                | (if s.write.is_some() { libc::POLLOUT } else { 0 });
            if events == 0 {
                continue;
            }
            if n < max && !out.is_null() {
                *out.add(n) = libc::pollfd {
                    fd: s.stream.as_raw_fd(),
                    events,
                    revents: 0,
                }
            }
            n += 1;
        }
        n
    })
}
#[no_mangle]
pub unsafe extern "C" fn ar_hyper_ready(ptr: *mut c_void, fd: i32, events: i32) -> i32 {
    engine_ffi(ptr, -2, |e| {
        fault(16);
        for c in e.conns.iter_mut().flatten() {
            let mut s = c.io.0.borrow_mut();
            if s.stream.as_raw_fd() != fd {
                continue;
            }
            if events & (libc::POLLIN | libc::POLLERR | libc::POLLHUP) as i32 != 0 {
                s.readable = true;
                if let Some(w) = s.read.take() {
                    w.wake()
                }
            }
            if events & (libc::POLLOUT | libc::POLLERR | libc::POLLHUP) as i32 != 0 {
                s.writable = true;
                if let Some(w) = s.write.take() {
                    w.wake()
                }
            }
            c.wake.set();
            break;
        }
        0
    })
}

#[no_mangle]
pub unsafe extern "C" fn ar_hyper_connections(ptr: *mut c_void) -> usize {
    engine_ffi(ptr, usize::MAX, |e| {
        fault(17);
        e.conns.iter().flatten().count()
    })
}

#[cfg(test)]
mod tests;
