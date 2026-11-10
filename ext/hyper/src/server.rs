//! Bounded HTTP/1 server; polled exclusively by Asyncara's interpreter thread.
//! No PHP callbacks, executor threads, DNS, TLS, or synchronous log writes here.
use super::{Bytes, Context, Flag, Future, Io, Poll, Rc, RefCell, Socket, Waker};
use http_body_util::{BodyExt, Full};
use hyper::{body::Incoming, header, service::Service, Request, Response};
use std::{
    cell::Cell,
    collections::{HashMap, VecDeque},
    convert::Infallible,
    ffi::c_void,
    io,
    net::{SocketAddr, TcpListener},
    os::fd::{AsRawFd, FromRawFd, OwnedFd},
    panic::{catch_unwind, AssertUnwindSafe},
    pin::Pin,
    rc::Weak,
    sync::{
        atomic::{AtomicUsize, Ordering},
        Arc,
    },
    time::{Duration, Instant},
};

type Reply = Response<Full<Bytes>>;
type Connection = hyper::server::conn::http1::Connection<Io, Dispatch>;
struct Data {
    bytes: Vec<u8>,
    used: Arc<AtomicUsize>,
}
impl AsRef<[u8]> for Data {
    fn as_ref(&self) -> &[u8] {
        &self.bytes
    }
}
impl Drop for Data {
    fn drop(&mut self) {
        self.used.fetch_sub(self.bytes.len(), Ordering::Relaxed);
    }
}
impl Data {
    fn new(used: &Arc<AtomicUsize>) -> Self {
        Self {
            bytes: Vec::new(),
            used: used.clone(),
        }
    }
    fn append(&mut self, data: &[u8], limit: usize) -> bool {
        if data.len() > limit.saturating_sub(self.used.load(Ordering::Relaxed))
            || self.bytes.try_reserve_exact(data.len()).is_err()
        {
            return false;
        }
        self.bytes.extend_from_slice(data);
        self.used.fetch_add(data.len(), Ordering::Relaxed);
        true
    }
}
struct Ticket {
    response: Option<Reply>,
    wake: Option<Waker>,
    answered: bool,
}
struct Event {
    id: u64,
    kind: i32,
    method: String,
    target: String,
    headers: Vec<u8>,
    body: Data,
}
struct Shared {
    events: VecDeque<Event>,
    pending: HashMap<u64, Weak<RefCell<Ticket>>>,
    next: u64,
    notify: OwnedFd,
    used: Arc<AtomicUsize>,
    limit: usize,
    slots: usize,
}
impl Shared {
    fn signal(&self) {
        let one = 1u64;
        loop {
            let rc =
                unsafe { libc::write(self.notify.as_raw_fd(), (&one as *const u64).cast(), 8) };
            if rc >= 0 {
                break;
            }
            match io::Error::last_os_error().kind() {
                io::ErrorKind::Interrupted => continue,
                io::ErrorKind::WouldBlock => break, // an existing notification remains readable
                _ => break, // owned eventfd cannot be closed through this API
            }
        }
    }
    fn clear_signal(&self) {
        let mut n = 0u64;
        loop {
            let rc = unsafe { libc::read(self.notify.as_raw_fd(), (&mut n as *mut u64).cast(), 8) };
            if rc >= 0 || io::Error::last_os_error().kind() != io::ErrorKind::Interrupted {
                break;
            }
        }
    }
}
struct Pending {
    id: u64,
    ticket: Rc<RefCell<Ticket>>,
    shared: Rc<RefCell<Shared>>,
}
impl Future for Pending {
    type Output = Reply;
    fn poll(self: Pin<&mut Self>, cx: &mut Context<'_>) -> Poll<Reply> {
        let mut ticket = self.ticket.borrow_mut();
        if let Some(response) = ticket.response.take() {
            Poll::Ready(response)
        } else {
            ticket.wake = Some(cx.waker().clone());
            Poll::Pending
        }
    }
}
impl Drop for Pending {
    fn drop(&mut self) {
        if !self.ticket.borrow().answered {
            let mut shared = self.shared.borrow_mut();
            let body = Data::new(&shared.used);
            // One request plus at most one cancellation per admitted ticket.
            shared.events.push_back(Event {
                id: self.id,
                kind: 2,
                method: String::new(),
                target: String::new(),
                headers: Vec::new(),
                body,
            });
            shared.signal();
        }
    }
}
#[derive(Clone)]
struct Dispatch {
    shared: Rc<RefCell<Shared>>,
    body_limit: usize,
    deadline: Rc<Cell<Instant>>,
    timeout: Duration,
    count: Rc<Cell<usize>>,
    max_requests: usize,
}
fn error(status: u16) -> Reply {
    Response::builder()
        .status(status)
        .header(header::CONNECTION, "close")
        .body(Full::new(Bytes::new()))
        .expect("constant response")
}
impl Service<Request<Incoming>> for Dispatch {
    type Response = Reply;
    type Error = Infallible;
    type Future = Pin<Box<dyn Future<Output = Result<Reply, Infallible>>>>;
    fn call(&self, request: Request<Incoming>) -> Self::Future {
        let dispatch = self.clone();
        Box::pin(async move {
            let (parts, mut incoming) = request.into_parts();
            // Hyper's max_buf_size controls buffer growth, not the exact size
            // of a complete head parsed from the final read. Enforce our public
            // head bound independently before allocating a PHP request event.
            let head_bytes = parts.uri.to_string().len()
                + parts.method.as_str().len()
                + 16
                + parts
                    .headers
                    .iter()
                    .map(|(k, v)| k.as_str().len() + v.as_bytes().len() + 4)
                    .sum::<usize>();
            if head_bytes > 65536 {
                return Ok(error(431));
            }
            // CONNECT/upgrades require different resource ownership, not advertised.
            if parts.method == hyper::Method::CONNECT || parts.headers.contains_key(header::UPGRADE)
            {
                return Ok(error(501));
            }
            if parts
                .headers
                .get(header::CONTENT_LENGTH)
                .and_then(|v| v.to_str().ok())
                .and_then(|v| v.parse::<u64>().ok())
                .is_some_and(|n| n > dispatch.body_limit as u64)
            {
                return Ok(error(413));
            }
            let (used, limit) = {
                let s = dispatch.shared.borrow();
                (s.used.clone(), s.limit)
            };
            let mut body = Data::new(&used);
            while let Some(frame) = incoming.frame().await {
                let Ok(frame) = frame else {
                    return Ok(error(400));
                };
                if let Ok(data) = frame.into_data() {
                    if data.len() > dispatch.body_limit.saturating_sub(body.bytes.len()) {
                        return Ok(error(413));
                    }
                    if !body.append(&data, limit) {
                        return Ok(error(503));
                    }
                }
            }
            let ticket = Rc::new(RefCell::new(Ticket {
                response: None,
                wake: None,
                answered: false,
            }));
            let id = {
                let mut shared = dispatch.shared.borrow_mut();
                if shared.pending.len() >= shared.slots || shared.events.len() >= shared.slots * 2 {
                    return Ok(error(503));
                }
                let Some(id) = shared.next.checked_add(1).filter(|v| *v <= i64::MAX as u64) else {
                    return Ok(error(503));
                };
                shared.next = id;
                let mut headers = Vec::new();
                for (key, value) in &parts.headers {
                    headers.extend_from_slice(key.as_str().as_bytes());
                    headers.extend_from_slice(b": ");
                    headers.extend_from_slice(value.as_bytes());
                    headers.extend_from_slice(b"\r\n");
                }
                shared.pending.insert(id, Rc::downgrade(&ticket));
                shared.events.push_back(Event {
                    id,
                    kind: 1,
                    method: parts.method.to_string(),
                    target: parts.uri.to_string(),
                    headers,
                    body,
                });
                shared.signal();
                id
            };
            let mut reply = Pending {
                id,
                ticket,
                shared: dispatch.shared.clone(),
            }
            .await;
            dispatch.count.set(dispatch.count.get() + 1);
            if dispatch.count.get() >= dispatch.max_requests {
                reply.headers_mut().insert(
                    header::CONNECTION,
                    header::HeaderValue::from_static("close"),
                );
            }
            dispatch.deadline.set(Instant::now() + dispatch.timeout);
            Ok(reply)
        })
    }
}
struct Peer {
    io: Io,
    driver: Pin<Box<Connection>>,
    wake: Arc<Flag>,
    deadline: Rc<Cell<Instant>>,
}
struct Server {
    listener: Option<TcpListener>,
    address: String,
    peers: HashMap<i32, Peer>,
    shared: Rc<RefCell<Shared>>,
    max: usize,
    body_limit: usize,
    response_limit: usize,
    timeout: Duration,
    max_requests: usize,
    poisoned: bool,
    accepted: u64,
    closed: u64,
    timed_out: u64,
    protocol_errors: u64,
}
fn listen(address: SocketAddr, reuse_port: bool) -> io::Result<TcpListener> {
    let fd = unsafe {
        libc::socket(
            if address.is_ipv4() {
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
    let listener = unsafe { TcpListener::from_raw_fd(fd) };
    let one = 1i32;
    for option in [libc::SO_REUSEADDR]
        .into_iter()
        .chain(reuse_port.then_some(libc::SO_REUSEPORT))
    {
        if unsafe { libc::setsockopt(fd, libc::SOL_SOCKET, option, (&one as *const i32).cast(), 4) }
            < 0
        {
            return Err(io::Error::last_os_error());
        }
    }
    let rc = match address {
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
                libc::bind(
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
                libc::bind(
                    fd,
                    (&sa as *const libc::sockaddr_in6).cast(),
                    std::mem::size_of_val(&sa) as _,
                )
            }
        }
    };
    if rc < 0 || unsafe { libc::listen(fd, 4096) } < 0 {
        return Err(io::Error::last_os_error());
    }
    Ok(listener)
}
impl Server {
    fn drive(&mut self) -> io::Result<()> {
        if let Some(listener) = &self.listener {
            for _ in 0..64 {
                if self.peers.len() >= self.max || self.shared.borrow().pending.len() >= self.max {
                    break;
                }
                match listener.accept() {
                    Ok((stream, _)) => {
                        stream.set_nonblocking(true)?;
                        stream.set_nodelay(true)?;
                        let fd = stream.as_raw_fd();
                        let wake = Flag::new();
                        let io = Io(Rc::new(RefCell::new(Socket {
                            stream,
                            readable: true,
                            writable: true,
                            read: None,
                            write: None,
                            #[cfg(test)]
                            owner_waker: Waker::from(wake.clone()),
                        })));
                        let deadline = Rc::new(Cell::new(Instant::now() + self.timeout));
                        let service = Dispatch {
                            shared: self.shared.clone(),
                            body_limit: self.body_limit,
                            deadline: deadline.clone(),
                            timeout: self.timeout,
                            count: Rc::new(Cell::new(0)),
                            max_requests: self.max_requests,
                        };
                        let mut builder = hyper::server::conn::http1::Builder::new();
                        builder
                            .header_read_timeout(None)
                            .max_buf_size(65536)
                            .max_header_size(65536)
                            .max_headers(100);
                        let driver = Box::pin(builder.serve_connection(io.clone(), service));
                        self.peers.insert(
                            fd,
                            Peer {
                                io,
                                driver,
                                wake,
                                deadline,
                            },
                        );
                        self.accepted += 1;
                    }
                    Err(e) if e.kind() == io::ErrorKind::WouldBlock => break,
                    Err(e) if e.kind() == io::ErrorKind::Interrupted => continue,
                    Err(e) => return Err(e),
                }
            }
        }
        let now = Instant::now();
        self.peers.retain(|_, peer| {
            if now >= peer.deadline.get() {
                self.timed_out += 1;
                self.closed += 1;
                return false;
            }
            for _ in 0..8 {
                // bounded work per connection; never spin on an unready socket
                if !peer.wake.take() {
                    break;
                }
                let waker = Waker::from(peer.wake.clone());
                if let Poll::Ready(result) =
                    peer.driver.as_mut().poll(&mut Context::from_waker(&waker))
                {
                    self.closed += 1;
                    if result.is_err() {
                        self.protocol_errors += 1;
                    }
                    return false;
                }
            }
            true
        });
        if self.listener.is_none() && self.peers.is_empty() {
            self.shared.borrow().signal();
        }
        Ok(())
    }
}
// Every exported mutating boundary is fail-stop after panic. PHP never executes
// while Rust holds a borrow, and all borrowed views are copied before consumption.
unsafe fn with<T: Copy>(ptr: *mut c_void, failed: T, f: impl FnOnce(&mut Server) -> T) -> T {
    if ptr.is_null() {
        return failed;
    }
    let server = &mut *ptr.cast::<Server>();
    if server.poisoned {
        return failed;
    }
    match catch_unwind(AssertUnwindSafe(|| f(server))) {
        Ok(v) => v,
        Err(_) => {
            server.poisoned = true;
            failed
        }
    }
}
#[no_mangle]
pub unsafe extern "C" fn ar_server_new(
    address: *const u8,
    len: usize,
    max: usize,
    body: usize,
    response: usize,
    budget: usize,
    timeout: u64,
    requests: usize,
    reuse_port: i32,
) -> *mut c_void {
    catch_unwind(AssertUnwindSafe(|| -> Option<*mut c_void> {
        if address.is_null()
            || len > 128
            || !(1..=16384).contains(&max)
            || body > 64 * 1024 * 1024
            || response > 64 * 1024 * 1024
            || !(65536..=1024 * 1024 * 1024).contains(&budget)
            || !(1..=3600000).contains(&timeout)
            || !(1..=1000000).contains(&requests)
        {
            return None;
        }
        let address: SocketAddr = std::str::from_utf8(std::slice::from_raw_parts(address, len))
            .ok()?
            .parse()
            .ok()?;
        let listener = listen(address, reuse_port != 0).ok()?;
        let address = listener.local_addr().ok()?.to_string();
        let fd = libc::eventfd(0, libc::EFD_NONBLOCK | libc::EFD_CLOEXEC);
        if fd < 0 {
            return None;
        }
        let shared = Rc::new(RefCell::new(Shared {
            events: VecDeque::new(),
            pending: HashMap::new(),
            next: 0,
            notify: OwnedFd::from_raw_fd(fd),
            used: Arc::new(AtomicUsize::new(0)),
            limit: budget,
            slots: max,
        }));
        Some(
            Box::into_raw(Box::new(Server {
                listener: Some(listener),
                address,
                peers: HashMap::new(),
                shared,
                max,
                body_limit: body,
                response_limit: response,
                timeout: Duration::from_millis(timeout),
                max_requests: requests,
                poisoned: false,
                accepted: 0,
                closed: 0,
                timed_out: 0,
                protocol_errors: 0,
            }))
            .cast(),
        )
    }))
    .ok()
    .flatten()
    .unwrap_or(std::ptr::null_mut())
}
#[no_mangle]
pub unsafe extern "C" fn ar_server_free(ptr: *mut c_void) {
    if !ptr.is_null() {
        let _ = catch_unwind(AssertUnwindSafe(|| {
            drop(Box::from_raw(ptr.cast::<Server>()))
        }));
    }
}
#[no_mangle]
pub unsafe extern "C" fn ar_server_drive(ptr: *mut c_void) -> i32 {
    with(ptr, -1, |s| {
        if s.drive().is_err() {
            s.poisoned = true;
            -1
        } else {
            0
        }
    })
}
#[no_mangle]
pub unsafe extern "C" fn ar_server_timeout(ptr: *mut c_void) -> i32 {
    with(ptr, -2, |s| {
        if !s.shared.borrow().events.is_empty()
            || s.peers
                .values()
                .any(|p| p.wake.0.load(std::sync::atomic::Ordering::Relaxed))
        {
            return 0;
        }
        let now = Instant::now();
        s.peers
            .values()
            .map(|p| {
                p.deadline
                    .get()
                    .saturating_duration_since(now)
                    .as_nanos()
                    .div_ceil(1_000_000)
                    .min(i32::MAX as u128) as i32
            })
            .min()
            .unwrap_or(-1)
    })
}
#[no_mangle]
pub unsafe extern "C" fn ar_server_fds(
    ptr: *mut c_void,
    out: *mut libc::pollfd,
    capacity: usize,
) -> usize {
    with(ptr, usize::MAX, |s| {
        let accept = s.listener.is_some()
            && s.peers.len() < s.max
            && s.shared.borrow().pending.len() < s.max;
        let count = s.peers.len() + usize::from(accept);
        if out.is_null() {
            return count;
        }
        if capacity < count {
            return usize::MAX;
        }
        let mut index = 0;
        if accept {
            *out = libc::pollfd {
                fd: s.listener.as_ref().unwrap().as_raw_fd(),
                events: libc::POLLIN,
                revents: 0,
            };
            index += 1;
        }
        for (&fd, peer) in &s.peers {
            let socket = peer.io.0.borrow();
            *out.add(index) = libc::pollfd {
                fd,
                events: (if socket.read.is_some() {
                    libc::POLLIN
                } else {
                    0
                }) | (if socket.write.is_some() {
                    libc::POLLOUT
                } else {
                    0
                }),
                revents: 0,
            };
            index += 1;
        }
        count
    })
}
#[no_mangle]
pub unsafe extern "C" fn ar_server_ready(ptr: *mut c_void, fd: i32, events: i32) -> i32 {
    with(ptr, -1, |s| {
        if let Some(peer) = s.peers.get_mut(&fd) {
            let mut socket = peer.io.0.borrow_mut();
            if events & i32::from(libc::POLLIN | libc::POLLERR | libc::POLLHUP | libc::POLLNVAL)
                != 0
            {
                socket.readable = true;
                if let Some(w) = socket.read.take() {
                    w.wake();
                }
            }
            if events & i32::from(libc::POLLOUT | libc::POLLERR | libc::POLLHUP | libc::POLLNVAL)
                != 0
            {
                socket.writable = true;
                if let Some(w) = socket.write.take() {
                    w.wake();
                }
            }
            // HUP can arrive while the service is waiting for a PHP response.
            if events & i32::from(libc::POLLERR | libc::POLLHUP | libc::POLLNVAL) != 0 {
                peer.deadline.set(Instant::now());
            }
        }
        0
    })
}
#[repr(C)]
pub struct View {
    id: u64,
    kind: i32,
    method: *const u8,
    method_len: usize,
    target: *const u8,
    target_len: usize,
    headers: *const u8,
    headers_len: usize,
    body: *const u8,
    body_len: usize,
}
#[no_mangle]
pub unsafe extern "C" fn ar_server_peek(ptr: *mut c_void, view: *mut View) -> i32 {
    with(ptr, -1, |s| {
        if view.is_null() {
            return -1;
        }
        let shared = s.shared.borrow();
        if let Some(e) = shared.events.front() {
            *view = View {
                id: e.id,
                kind: e.kind,
                method: e.method.as_ptr(),
                method_len: e.method.len(),
                target: e.target.as_ptr(),
                target_len: e.target.len(),
                headers: e.headers.as_ptr(),
                headers_len: e.headers.len(),
                body: e.body.bytes.as_ptr(),
                body_len: e.body.bytes.len(),
            };
            1
        } else {
            shared.clear_signal();
            if s.listener.is_none() && s.peers.is_empty() {
                2
            } else {
                0
            }
        }
    })
}
#[no_mangle]
pub unsafe extern "C" fn ar_server_pop(ptr: *mut c_void) -> i32 {
    with(ptr, -1, |s| {
        let mut shared = s.shared.borrow_mut();
        if let Some(e) = shared.events.pop_front() {
            if e.kind == 2 {
                shared.pending.remove(&e.id);
            }
        }
        0
    })
}
#[repr(C)]
pub struct Header {
    name: *const u8,
    name_len: usize,
    value: *const u8,
    value_len: usize,
}
#[no_mangle]
pub unsafe extern "C" fn ar_server_respond(
    ptr: *mut c_void,
    id: u64,
    status: u16,
    headers: *const Header,
    count: usize,
    body: *const u8,
    len: usize,
) -> i32 {
    with(ptr, -1, |s| {
        if !(200..=599).contains(&status)
            || count > 100
            || len > s.response_limit
            || body.is_null()
            || (count > 0 && headers.is_null())
        {
            return -2;
        }
        let ticket = s.shared.borrow().pending.get(&id).and_then(Weak::upgrade);
        let Some(ticket) = ticket else {
            return 0;
        };
        if ticket.borrow().answered {
            return -2;
        }
        let mut builder = Response::builder().status(status);
        let mut total = 0usize;
        for i in 0..count {
            let h = &*headers.add(i);
            if h.name.is_null() || h.value.is_null() || h.name_len > 65536 || h.value_len > 65536 {
                return -2;
            }
            total += h.name_len + h.value_len + 4;
            if total > 65536 {
                return -2;
            }
            let Ok(name) =
                header::HeaderName::from_bytes(std::slice::from_raw_parts(h.name, h.name_len))
            else {
                return -2;
            };
            let Ok(value) =
                header::HeaderValue::from_bytes(std::slice::from_raw_parts(h.value, h.value_len))
            else {
                return -2;
            };
            if [
                header::CONTENT_LENGTH,
                header::TRANSFER_ENCODING,
                header::CONNECTION,
                header::UPGRADE,
                header::TRAILER,
            ]
            .contains(&name)
            {
                return -2;
            }
            builder = builder.header(name, value);
        }
        let (used, limit) = {
            let shared = s.shared.borrow();
            (shared.used.clone(), shared.limit)
        };
        let mut data = Data::new(&used);
        if !data.append(std::slice::from_raw_parts(body, len), limit) {
            return -3;
        }
        // Charge follows every cloned Bytes view until the final transport
        // reference drops, including a slow write or disconnected peer.
        let Ok(response) = builder.body(Full::new(Bytes::from_owner(data))) else {
            return -2;
        };
        let mut t = ticket.borrow_mut();
        t.answered = true;
        t.response = Some(response);
        if let Some(w) = t.wake.take() {
            w.wake();
        }
        s.shared.borrow_mut().pending.remove(&id);
        1
    })
}
#[repr(C)]
pub struct Info {
    notification_fd: i32,
    active: usize,
    queued: usize,
    pending: usize,
    bytes: usize,
    accepted: u64,
    closed: u64,
    timed_out: u64,
    errors: u64,
    address: *const u8,
    address_len: usize,
}
#[no_mangle]
pub unsafe extern "C" fn ar_server_info(ptr: *mut c_void, out: *mut Info) -> i32 {
    with(ptr, -1, |s| {
        if out.is_null() {
            return -1;
        }
        let shared = s.shared.borrow();
        *out = Info {
            notification_fd: shared.notify.as_raw_fd(),
            active: s.peers.len(),
            queued: shared.events.len(),
            pending: shared.pending.len(),
            bytes: shared.used.load(Ordering::Relaxed),
            accepted: s.accepted,
            closed: s.closed,
            timed_out: s.timed_out,
            errors: s.protocol_errors,
            address: s.address.as_ptr(),
            address_len: s.address.len(),
        };
        0
    })
}
#[no_mangle]
pub unsafe extern "C" fn ar_server_shutdown(ptr: *mut c_void) -> i32 {
    with(ptr, -1, |s| {
        s.listener.take();
        for peer in s.peers.values_mut() {
            peer.driver.as_mut().graceful_shutdown();
            peer.wake.set();
        }
        s.shared.borrow().signal();
        0
    })
}
