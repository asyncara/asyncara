//! Explicit HTTP/1 proxy transport, bounded CONNECT and separate hop trust.
//! Prepared addresses/PEM arrive from workers; this module never resolves DNS
//! or reads files. No application headers or identity reach the CONNECT hop.
use super::{Address, Addresses, ClientIo, ClientTransport, Io, TlsSettings};
use base64::Engine as _;
use hyper::{
    body::Bytes,
    rt::{Read, Write},
    Uri,
};
use std::{cell::Cell, future::poll_fn, io, net::SocketAddr, pin::Pin, rc::Rc, sync::Arc};
#[repr(C)]
pub struct ProxyData {
    pub url: Address,
    pub roots: Address,
    pub user: Address,
    pub password: Address,
}
pub(super) struct Policy {
    endpoint: String,
    roots: Vec<u8>,
    auth: zeroize::Zeroizing<String>,
    tls: Option<(
        Arc<rustls::ClientConfig>,
        rustls::pki_types::ServerName<'static>,
    )>,
}
#[derive(Clone)]
pub(super) struct Settings {
    pub id: usize,
    policy: Rc<Policy>,
}
impl Settings {
    pub(super) fn authorization(&self) -> Option<hyper::header::HeaderValue> {
        if self.policy.auth.is_empty() {
            return None;
        }
        let mut value = hyper::header::HeaderValue::from_str(&self.policy.auth)
            .expect("validated ASCII Basic authorization");
        value.set_sensitive(true);
        Some(value)
    }
}
unsafe fn bytes<'a>(v: &Address, max: usize) -> Result<&'a [u8], ()> {
    if v.length > max || (v.length > 0 && v.bytes.is_null()) {
        return Err(());
    }
    if v.length == 0 {
        Ok(&[])
    } else {
        Ok(std::slice::from_raw_parts(v.bytes, v.length))
    }
}
// Numeric endpoints cannot be redirected via an unrelated resolved vector.
pub(super) unsafe fn endpoint(
    uri: &Uri,
    addresses: *const Address,
    count: usize,
) -> Result<Addresses, ()> {
    let authority = uri.authority().ok_or(())?;
    let raw = authority.host();
    let host = raw.trim_matches(['[', ']']);
    if authority.as_str().contains('@') {
        return Err(());
    }
    let port = match authority.as_str().strip_prefix(raw).ok_or(())? {
        "" => {
            if uri.scheme_str() == Some("https") {
                443
            } else {
                80
            }
        }
        suffix => {
            let s = suffix.strip_prefix(':').ok_or(())?;
            if s.is_empty() || !s.bytes().all(|b| b.is_ascii_digit()) {
                return Err(());
            }
            s.parse::<u16>().map_err(|_| ())?
        }
    };
    if port == 0 {
        return Err(());
    }
    if let Ok(ip) = host.parse() {
        if count != 0 {
            return Err(());
        }
        return Ok(Addresses::One(SocketAddr::new(ip, port)));
    }
    if !super::valid_dns_name(host) || count == 0 || count > 16 || addresses.is_null() {
        return Err(());
    }
    let mut out = Vec::with_capacity(count);
    for a in std::slice::from_raw_parts(addresses, count) {
        let ip = std::str::from_utf8(bytes(a, 45)?)
            .map_err(|_| ())?
            .parse()
            .map_err(|_| ())?;
        let addr = SocketAddr::new(ip, port);
        if !out.contains(&addr) {
            out.push(addr);
        }
    }
    Ok(Addresses::Many(out))
}
pub(super) unsafe fn copy(
    raw: *const ProxyData,
    cache: &mut Vec<Rc<Policy>>,
    addresses: *const Address,
    count: usize,
) -> Result<Option<(Settings, Addresses)>, ()> {
    if raw.is_null() {
        return Ok(None);
    }
    let raw = &*raw;
    let url = std::str::from_utf8(bytes(&raw.url, 8192)?).map_err(|_| ())?;
    if url.contains('#') {
        return Err(());
    }
    let uri = url.parse::<Uri>().map_err(|_| ())?;
    let secure = match uri.scheme_str() {
        Some("http") => false,
        Some("https") => true,
        _ => return Err(()),
    };
    if uri.query().is_some() || !matches!(uri.path(), "" | "/") {
        return Err(());
    }
    let addresses = endpoint(&uri, addresses, count)?;
    let roots = bytes(&raw.roots, 1048576)?;
    if secure == roots.is_empty() {
        return Err(());
    }
    let user = bytes(&raw.user, 4096)?;
    let password = bytes(&raw.password, 4096)?;
    if raw.user.bytes.is_null() != raw.password.bytes.is_null() {
        return Err(());
    }
    let auth = if raw.user.bytes.is_null() {
        String::new()
    } else {
        if user.is_empty()
            || user.contains(&b':')
            || user.iter().chain(password).any(|c| !(32..=126).contains(c))
        {
            return Err(());
        }
        let mut input =
            zeroize::Zeroizing::new(Vec::with_capacity(user.len() + 1 + password.len()));
        input.extend_from_slice(user);
        input.push(b':');
        input.extend_from_slice(password);
        format!(
            "Basic {}",
            base64::engine::general_purpose::STANDARD.encode(&*input)
        )
    };
    let auth = zeroize::Zeroizing::new(auth);
    let endpoint = format!(
        "{}://{}",
        if secure { "https" } else { "http" },
        uri.authority().ok_or(())?.as_str().to_ascii_lowercase()
    );
    let index = if let Some(i) = cache
        .iter()
        .position(|p| p.endpoint == endpoint && p.roots == roots && *p.auth == *auth)
    {
        i
    } else {
        if cache.len() >= 16 {
            return Err(());
        }
        let tls = if secure {
            let mut config = (*super::tls::client_config(roots, &[], &[]).map_err(|_| ())?).clone();
            config.alpn_protocols = vec![b"http/1.1".to_vec()];
            let host = uri.host().ok_or(())?.trim_matches(['[', ']']);
            Some((
                Arc::new(config),
                rustls::pki_types::ServerName::try_from(host.to_owned()).map_err(|_| ())?,
            ))
        } else {
            None
        };
        cache.push(Rc::new(Policy {
            endpoint,
            roots: roots.to_vec(),
            auth,
            tls,
        }));
        cache.len() - 1
    };
    Ok(Some((
        Settings {
            id: index + 1,
            policy: cache[index].clone(),
        },
        addresses,
    )))
}
pub(super) type Prepare = Pin<Box<dyn std::future::Future<Output = io::Result<ClientTransport>>>>;
pub(super) fn prepare(
    socket: Io,
    settings: Settings,
    uri: Uri,
    origin: Option<TlsSettings>,
    ready: Rc<Cell<bool>>,
    pending_write: Rc<Cell<bool>>,
) -> Prepare {
    Box::pin(async move {
        let mut wire = ClientIo::Plain(socket);
        if let Some((config, name)) = &settings.policy.tls {
            let mut tls = super::tls::TlsIo::client_over(
                wire,
                config.clone(),
                name.clone(),
                Rc::new(Cell::new(false)),
            )?;
            poll_fn(|cx| tls.handshake(cx)).await?;
            wire = ClientIo::Tls(Box::new(tls));
        }
        if let Some(origin) = origin {
            let authority = uri.authority().ok_or(io::ErrorKind::InvalidInput)?;
            let target = if authority.port().is_some() {
                authority.as_str().to_owned()
            } else {
                format!("{}:443", authority.as_str())
            };
            wire = connect(wire, &target, settings.authorization()).await?;
            let mut tls = super::tls::TlsIo::client_over(
                wire,
                origin.config,
                origin.name,
                Rc::new(Cell::new(false)),
            )?;
            poll_fn(|cx| tls.handshake(cx)).await?;
            wire = ClientIo::Tls(Box::new(tls));
        }
        ready.set(true);
        Ok(ClientTransport {
            io: wire,
            pending_write,
        })
    })
}
async fn connect(
    mut wire: ClientIo,
    target: &str,
    auth: Option<hyper::header::HeaderValue>,
) -> io::Result<ClientIo> {
    let mut request =
        zeroize::Zeroizing::new(format!("CONNECT {target} HTTP/1.1\r\nHost: {target}\r\n"));
    if let Some(auth) = auth {
        request.push_str("Proxy-Authorization: ");
        request.push_str(auth.to_str().map_err(|_| io::ErrorKind::InvalidInput)?);
        request.push_str("\r\n");
    }
    request.push_str("\r\n");
    let mut offset = 0;
    while offset < request.len() {
        let n =
            poll_fn(|cx| Pin::new(&mut wire).poll_write(cx, &request.as_bytes()[offset..])).await?;
        if n == 0 {
            return Err(io::ErrorKind::WriteZero.into());
        }
        offset += n;
    }
    poll_fn(|cx| Pin::new(&mut wire).poll_flush(cx)).await?;
    let mut head = Vec::with_capacity(4096);
    let mut informational = 0;
    loop {
        let mut headers = [httparse::EMPTY_HEADER; 256];
        let mut response = httparse::Response::new(&mut headers);
        match response
            .parse(&head)
            .map_err(|_| io::ErrorKind::InvalidData)?
        {
            httparse::Status::Complete(n) => {
                let code = response.code.ok_or(io::ErrorKind::InvalidData)?;
                if (100..200).contains(&code) && code != 101 {
                    informational += 1;
                    if informational > 8 {
                        return Err(io::ErrorKind::InvalidData.into());
                    }
                    head.drain(..n);
                    continue;
                }
                if !(200..300).contains(&code) {
                    return Err(io::Error::new(
                        io::ErrorKind::PermissionDenied,
                        "Proxy CONNECT rejected",
                    ));
                }
                // CONNECT success is a protocol switch. Content-Length and
                // Transfer-Encoding cannot describe a response body here.
                let extra = Bytes::copy_from_slice(&head[n..]);
                return Ok(if extra.is_empty() {
                    wire
                } else {
                    ClientIo::Prefix(extra, Box::new(wire))
                });
            }
            httparse::Status::Partial => (),
        }
        if head.len() >= 65536 {
            return Err(io::ErrorKind::InvalidData.into());
        }
        let mut bytes = [0u8; 4096];
        let limit = (65536 - head.len()).min(bytes.len());
        let n = poll_fn(|cx| {
            let mut buf = hyper::rt::ReadBuf::new(&mut bytes[..limit]);
            match Pin::new(&mut wire).poll_read(cx, buf.unfilled()) {
                std::task::Poll::Pending => std::task::Poll::Pending,
                std::task::Poll::Ready(r) => std::task::Poll::Ready(r.map(|()| buf.filled().len())),
            }
        })
        .await?;
        if n == 0 {
            return Err(io::ErrorKind::UnexpectedEof.into());
        }
        head.extend_from_slice(&bytes[..n]);
    }
}
