//! Shared TLS transport; verified client/server configuration stays separate. Socket readiness and the connection's single
//! driver waker are owned by the existing Asyncara reactor, not an executor.
use super::{ClientIo, Context, Io, Poll};
use hyper::rt::{Read as HyperRead, Write as HyperWrite};
use rustls::{ClientConfig, ClientConnection, Connection, ServerConfig, ServerConnection};
use std::{cell::Cell, rc::Rc};
use std::{
    io::{self, Read, Write},
    pin::Pin,
    sync::Arc,
};

pub(super) fn config(cert: &[u8], key: &[u8], ca: &[u8]) -> io::Result<Arc<ServerConfig>> {
    let invalid = |e| io::Error::new(io::ErrorKind::InvalidInput, e);
    let certs = rustls_pemfile::certs(&mut &*cert).collect::<Result<Vec<_>, _>>()?;
    let key =
        rustls_pemfile::private_key(&mut &*key)?.ok_or_else(|| invalid("missing private key"))?;
    let builder = ServerConfig::builder_with_provider(Arc::new(
        rustls::crypto::aws_lc_rs::default_provider(),
    ))
    .with_protocol_versions(&[&rustls::version::TLS13, &rustls::version::TLS12])
    .map_err(io::Error::other)?;
    let builder = if ca.is_empty() {
        builder.with_no_client_auth()
    } else {
        let mut roots = rustls::RootCertStore::empty();
        for cert in rustls_pemfile::certs(&mut &*ca) {
            roots.add(cert?).map_err(io::Error::other)?;
        }
        let verifier = rustls::server::WebPkiClientVerifier::builder_with_provider(
            Arc::new(roots),
            Arc::new(rustls::crypto::aws_lc_rs::default_provider()),
        )
        .build()
        .map_err(io::Error::other)?;
        builder.with_client_cert_verifier(verifier)
    };
    let mut config = builder
        .with_single_cert(certs, key)
        .map_err(io::Error::other)?;
    config.alpn_protocols = vec![b"http/1.1".to_vec()];
    // Bound library state separately from Asyncara's payload accounting. No
    // early data, session cache, ticket encryption or certificate auto-reload.
    config.session_storage = Arc::new(rustls::server::NoServerSessionStorage {});
    config.send_tls13_tickets = 0;
    Ok(Arc::new(config))
}

struct Wire<'a, 'b> {
    io: &'a mut ClientIo,
    cx: &'a mut Context<'b>,
}
impl Read for Wire<'_, '_> {
    fn read(&mut self, bytes: &mut [u8]) -> io::Result<usize> {
        let mut buf = hyper::rt::ReadBuf::new(bytes);
        match Pin::new(&mut *self.io).poll_read(self.cx, buf.unfilled()) {
            Poll::Ready(Ok(())) => Ok(buf.filled().len()),
            Poll::Ready(Err(e)) => Err(e),
            Poll::Pending => Err(io::ErrorKind::WouldBlock.into()),
        }
    }
}
impl Write for Wire<'_, '_> {
    fn write(&mut self, bytes: &[u8]) -> io::Result<usize> {
        match Pin::new(&mut *self.io).poll_write(self.cx, bytes) {
            Poll::Ready(r) => r,
            Poll::Pending => Err(io::ErrorKind::WouldBlock.into()),
        }
    }
    fn flush(&mut self) -> io::Result<()> {
        Ok(())
    }
}

pub(super) struct TlsIo {
    io: ClientIo,
    tls: Connection,
    established: Option<Rc<Cell<bool>>>,
    handshaken: bool,
    closing: bool,
    deferred_read_error: Option<io::Error>,
}
impl TlsIo {
    pub(super) fn new(io: Io, config: Arc<ServerConfig>) -> io::Result<Self> {
        let mut tls = ServerConnection::new(config).map_err(io::Error::other)?;
        tls.set_buffer_limit(Some(65536));
        Ok(Self {
            io: ClientIo::Plain(io),
            tls: Connection::Server(tls),
            established: None,
            handshaken: false,
            closing: false,
            deferred_read_error: None,
        })
    }
    pub(super) fn client(
        io: Io,
        config: Arc<ClientConfig>,
        name: rustls::pki_types::ServerName<'static>,
        established: Rc<Cell<bool>>,
    ) -> io::Result<Self> {
        Self::client_over(ClientIo::Plain(io), config, name, established)
    }
    pub(super) fn client_over(
        io: ClientIo,
        config: Arc<ClientConfig>,
        name: rustls::pki_types::ServerName<'static>,
        established: Rc<Cell<bool>>,
    ) -> io::Result<Self> {
        let mut tls = ClientConnection::new(config, name).map_err(io::Error::other)?;
        tls.set_buffer_limit(Some(65536));
        Ok(Self {
            io,
            tls: Connection::Client(tls),
            established: Some(established),
            handshaken: false,
            closing: false,
            deferred_read_error: None,
        })
    }
    fn read_error(&mut self, error: io::Error, received: usize) -> Poll<io::Result<()>> {
        // A coalesced read may already have filled the caller's buffer before
        // discovering an abrupt EOF or a later record failure. Deliver those
        // bytes once; expose the error on the next read. Never return Err with
        // initialized plaintext silently discarded from this same call.
        if received > 0 {
            self.deferred_read_error = Some(error);
            Poll::Ready(Ok(()))
        } else {
            Poll::Ready(Err(error))
        }
    }
    pub(super) fn h2(&self) -> bool {
        self.tls.alpn_protocol() == Some(b"h2".as_slice())
    }
    fn flush_tls(&mut self, cx: &mut Context<'_>) -> Poll<io::Result<()>> {
        for _ in 0..16 {
            if !self.tls.wants_write() {
                return Pin::new(&mut self.io).poll_flush(cx);
            }
            match self.tls.write_tls(&mut Wire {
                io: &mut self.io,
                cx,
            }) {
                Ok(0) => return Poll::Ready(Err(io::ErrorKind::WriteZero.into())),
                Ok(_) => (),
                Err(e) if e.kind() == io::ErrorKind::WouldBlock => return Poll::Pending,
                Err(e) => return Poll::Ready(Err(e)),
            }
        }
        cx.waker().wake_by_ref(); // bounded dispatch, still runnable
        Poll::Pending
    }
    fn read_tls(&mut self, cx: &mut Context<'_>) -> Poll<io::Result<()>> {
        match self.tls.read_tls(&mut Wire {
            io: &mut self.io,
            cx,
        }) {
            Ok(0) if self.tls.is_handshaking() => {
                Poll::Ready(Err(io::ErrorKind::UnexpectedEof.into()))
            }
            Ok(_) => match self.tls.process_new_packets() {
                Ok(_) => Poll::Ready(Ok(())),
                Err(e) => Poll::Ready(Err(io::Error::new(io::ErrorKind::InvalidData, e))),
            },
            Err(e) if e.kind() == io::ErrorKind::WouldBlock => Poll::Pending,
            Err(e) => Poll::Ready(Err(e)),
        }
    }
    pub(super) fn handshake(&mut self, cx: &mut Context<'_>) -> Poll<io::Result<()>> {
        // After the initial handshake, pending application writes must never
        // gate reads. A peer can reject an upload without consuming its body.
        if self.handshaken {
            return Poll::Ready(Ok(()));
        }
        for _ in 0..16 {
            std::task::ready!(self.flush_tls(cx))?;
            if !self.tls.is_handshaking() {
                self.handshaken = true;
                if let Some(established) = &self.established {
                    established.set(true);
                }
                return Poll::Ready(Ok(()));
            }
            std::task::ready!(self.read_tls(cx))?;
        }
        cx.waker().wake_by_ref();
        Poll::Pending
    }
}
impl HyperRead for TlsIo {
    fn poll_read(
        mut self: Pin<&mut Self>,
        cx: &mut Context<'_>,
        mut buf: hyper::rt::ReadBufCursor<'_>,
    ) -> Poll<io::Result<()>> {
        std::task::ready!(self.handshake(cx))?;
        if let Some(error) = self.deferred_read_error.take() {
            return Poll::Ready(Err(error));
        }
        // Bounded initialized stack buffer: rustls Read cannot accept uninitialized bytes.
        let mut plain = [0u8; 16384];
        let mut received = 0usize;
        for _ in 0..16 {
            let size = buf.remaining().min(plain.len());
            if size == 0 {
                return Poll::Ready(Ok(()));
            }
            match self.tls.reader().read(&mut plain[..size]) {
                Ok(0) => return Poll::Ready(Ok(())),
                Ok(n) => {
                    buf.put_slice(&plain[..n]);
                    received += n;
                    continue; // coalesce already-ready records into the caller's bounded buffer
                }
                Err(e) if e.kind() == io::ErrorKind::WouldBlock => (),
                Err(e) => return self.read_error(e, received),
            }
            // Never wait for a later record after delivering available plaintext.
            // Both socket directions still register the canonical driver waker.
            if let Poll::Ready(Err(e)) = self.flush_tls(cx) {
                return self.read_error(e, received);
            }
            match self.read_tls(cx) {
                Poll::Ready(Ok(())) => (),
                Poll::Ready(Err(e)) => return self.read_error(e, received),
                Poll::Pending if received > 0 => return Poll::Ready(Ok(())),
                Poll::Pending => return Poll::Pending,
            }
        }
        if received > 0 {
            return Poll::Ready(Ok(()));
        }
        cx.waker().wake_by_ref();
        Poll::Pending
    }
}
impl HyperWrite for TlsIo {
    fn poll_write(
        mut self: Pin<&mut Self>,
        cx: &mut Context<'_>,
        bytes: &[u8],
    ) -> Poll<io::Result<usize>> {
        std::task::ready!(self.handshake(cx))?;
        std::task::ready!(self.flush_tls(cx))?;
        let n = self.tls.writer().write(bytes)?;
        if let Poll::Ready(Err(e)) = self.flush_tls(cx) {
            return Poll::Ready(Err(e));
        }
        Poll::Ready(Ok(n))
    }
    fn poll_flush(mut self: Pin<&mut Self>, cx: &mut Context<'_>) -> Poll<io::Result<()>> {
        std::task::ready!(self.handshake(cx))?;
        self.flush_tls(cx)
    }
    fn poll_shutdown(mut self: Pin<&mut Self>, cx: &mut Context<'_>) -> Poll<io::Result<()>> {
        if !self.closing {
            self.tls.send_close_notify();
            self.closing = true;
        }
        std::task::ready!(self.flush_tls(cx))?;
        Pin::new(&mut self.io).poll_shutdown(cx)
    }
}

// Only supplied trust anchors are used: never a permissive verifier or fallback.
pub(super) fn client_config(pem: &[u8], cert: &[u8], key: &[u8]) -> io::Result<Arc<ClientConfig>> {
    if pem.is_empty() || pem.len() > 1048576 {
        return Err(io::ErrorKind::InvalidInput.into());
    }
    let mut roots = rustls::RootCertStore::empty();
    for cert in rustls_pemfile::certs(&mut &*pem) {
        if roots.len() >= 512 {
            return Err(io::ErrorKind::InvalidInput.into());
        }
        roots.add(cert?).map_err(io::Error::other)?;
    }
    if roots.is_empty() {
        return Err(io::ErrorKind::InvalidInput.into());
    }
    if cert.is_empty() != key.is_empty() || cert.len() > 1048576 || key.len() > 65536 {
        return Err(io::ErrorKind::InvalidInput.into());
    }
    let builder = ClientConfig::builder_with_provider(Arc::new(
        rustls::crypto::aws_lc_rs::default_provider(),
    ))
    .with_protocol_versions(&[&rustls::version::TLS13, &rustls::version::TLS12])
    .map_err(io::Error::other)?
    .with_root_certificates(roots);
    let mut config = if cert.is_empty() {
        builder.with_no_client_auth()
    } else {
        let mut chain = Vec::new();
        for cert in rustls_pemfile::certs(&mut &*cert) {
            if chain.len() >= 32 {
                return Err(io::ErrorKind::InvalidInput.into());
            }
            chain.push(cert?);
        }
        // One identity, not an ambiguous bundle of private keys. rustls also
        // checks that the leaf public key matches the configured signing key.
        let mut input = key;
        let key = rustls_pemfile::private_key(&mut input)?.ok_or(io::ErrorKind::InvalidInput)?;
        if rustls_pemfile::private_key(&mut input)?.is_some() {
            return Err(io::ErrorKind::InvalidInput.into());
        }
        builder
            .with_client_auth_cert(chain, key)
            .map_err(io::Error::other)?
    };
    config.alpn_protocols = vec![b"h2".to_vec(), b"http/1.1".to_vec()];
    config.resumption = rustls::client::Resumption::disabled();
    config.enable_early_data = false;
    Ok(Arc::new(config))
}
