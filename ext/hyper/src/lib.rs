//! Experimental HTTP/1.1 bridge. Numeric plain-HTTP GET only; no threads,
//! Tokio executor, hidden resolver or blocking socket calls. Asyncara owns readiness,
//! request deadlines and response buffers. No Zend API crosses this boundary.
use http_body_util::{BodyExt, Full};
use hyper::{body::Bytes, Request, Uri};
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
struct Conn {
    addr: SocketAddr,
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
    fn new(addr: SocketAddr, ms: u64) -> io::Result<Self> {
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
        })));
        Ok(Self {
            addr,
            io,
            connecting: rc < 0,
            connect_deadline: Instant::now() + Duration::from_millis(ms),
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
            b.max_buf_size(65536).max_headers(256);
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
    addr: SocketAddr,
    deadline: Instant,
    connect_ms: u64,
    conn: Option<usize>,
    future: Option<Transfer>,
    wake: Arc<Flag>,
}
struct Engine {
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
            .get("content-encoding")
            .is_some_and(|v| v.as_bytes() != b"identity")
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
    fn finish(&mut self, i: usize, error: Option<&str>) {
        let op = self.ops.swap_remove(i);
        if let Some(c) = op.conn {
            if error.is_some() || self.conns[c].as_ref().is_some_and(|x| x.closed) {
                self.conns[c] = None
            } else if let Some(conn) = &mut self.conns[c] {
                conn.busy = false
            }
        }
        let message = error.unwrap_or("");
        unsafe { ar_hyper_complete(op.job as *mut c_void, message.as_ptr(), message.len()) }
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
                let addr = self.ops[i].addr;
                if self
                    .conns
                    .iter()
                    .flatten()
                    .filter(|c| c.busy && c.addr == addr)
                    .count()
                    >= self.host
                {
                    continue;
                }
                let idle = self.conns.iter().position(|c| {
                    c.as_ref()
                        .is_some_and(|c| !c.busy && !c.closed && c.addr == addr)
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
                    match Conn::new(addr, self.ops[i].connect_ms) {
                        Ok(conn) => {
                            self.conns[slot] = Some(conn);
                            slot
                        }
                        Err(_) => {
                            self.finish(i, Some("HTTP connect failed"));
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
                        self.finish(i, Some("HTTP connection closed"));
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
#[no_mangle]
pub extern "C" fn ar_hyper_new(total: usize, host: usize) -> *mut c_void {
    ffi(std::ptr::null_mut(), || {
        if total == 0 || total > 256 || host == 0 || host > total {
            return std::ptr::null_mut();
        }
        Box::into_raw(Box::new(Engine {
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
#[no_mangle]
pub unsafe extern "C" fn ar_hyper_submit(
    ptr: *mut c_void,
    job: *mut c_void,
    url: *const u8,
    len: usize,
    timeout: u64,
    connect: u64,
) -> i32 {
    ffi(-1, || {
        if ptr.is_null()
            || job.is_null()
            || url.is_null()
            || len > 8192
            || timeout == 0
            || timeout > 3600000
            || connect == 0
            || connect > 3600000
        {
            return -1;
        }
        let engine = &mut *ptr.cast::<Engine>();
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
        let Ok(ip) = host.parse() else { return -1 };
        let addr = SocketAddr::new(ip, port);
        engine.ops.push(Op {
            job: job as usize,
            uri,
            addr,
            deadline: Instant::now() + Duration::from_millis(timeout),
            connect_ms: connect,
            conn: None,
            future: None,
            wake: Flag::new(),
        });
        0
    })
}
#[no_mangle]
pub unsafe extern "C" fn ar_hyper_drive(ptr: *mut c_void) -> i32 {
    ffi(-1, || (&mut *ptr.cast::<Engine>()).drive())
}
#[no_mangle]
pub unsafe extern "C" fn ar_hyper_cancel(ptr: *mut c_void, job: *mut c_void) -> i32 {
    ffi(-1, || {
        let e = &mut *ptr.cast::<Engine>();
        if let Some(i) = e.ops.iter().position(|op| op.job == job as usize) {
            e.finish(i, Some("Operation cancelled"));
        }
        0
    })
}
#[no_mangle]
pub unsafe extern "C" fn ar_hyper_timeout(ptr: *mut c_void) -> i32 {
    ffi(0, || {
        let e = &*ptr.cast::<Engine>();
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
    let e = &*ptr.cast::<Engine>();
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
}
#[no_mangle]
pub unsafe extern "C" fn ar_hyper_ready(ptr: *mut c_void, fd: i32, events: i32) {
    let e = &mut *ptr.cast::<Engine>();
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
}

#[no_mangle]
pub unsafe extern "C" fn ar_hyper_connections(ptr: *mut c_void) -> usize {
    ffi(0, || {
        (&*ptr.cast::<Engine>()).conns.iter().flatten().count()
    })
}
