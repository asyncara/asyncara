//! HTTP/1 uses Hyper. HTTP/2 uses its h2 protocol crate directly so the request
//! owns SendStream and can reset an unfinished upload after an early response.
//! No spawned body futures survive C completion; no Tokio runtime is involved.
use super::{ClientBody, ClientTransport, Driver, Request};
use hyper::{
    body::{Body, Bytes, Frame, Incoming, SizeHint},
    Response,
};
use std::{
    future::poll_fn,
    io,
    pin::Pin,
    task::{ready, Context, Poll},
};

pub(super) enum Sender {
    H1(hyper::client::conn::http1::SendRequest<ClientBody>),
    H2(h2::client::SendRequest<Bytes>),
}
impl Sender {
    pub(super) fn multiplexed(&self) -> bool {
        matches!(self, Self::H2(_))
    }
    pub(super) fn draining(&mut self, cx: &mut Context<'_>) -> bool {
        match self {
            Self::H2(sender) => matches!(sender.poll_ready(cx), Poll::Ready(Err(_))),
            Self::H1(_) => false,
        }
    }
    pub(super) fn is_ready(&self) -> bool {
        match self {
            Self::H1(s) => s.is_ready(),
            Self::H2(_) => true,
        }
    }
    pub(super) fn acquire(slot: &mut Option<Self>) -> Option<Self> {
        match slot {
            Some(Self::H2(s)) => Some(Self::H2(s.clone())),
            _ => slot.take(),
        }
    }
    pub(super) async fn ready(&mut self) -> Result<(), String> {
        match self {
            Self::H1(s) => s.ready().await.map_err(|e| e.to_string()),
            Self::H2(s) => poll_fn(|cx| s.poll_ready(cx))
                .await
                .map_err(|e| e.to_string()),
        }
    }
    pub(super) async fn send_request(
        &mut self,
        request: Request<ClientBody>,
    ) -> Result<Response<ResponseBody>, String> {
        match self {
            Self::H1(s) => s
                .send_request(request)
                .await
                .map(|r| r.map(ResponseBody::H1))
                .map_err(|e| e.to_string()),
            Self::H2(s) => {
                poll_fn(|cx| s.poll_ready(cx))
                    .await
                    .map_err(|e| e.to_string())?;
                let (parts, body) = request.into_parts();
                let mut request = Request::from_parts(parts, ());
                *request.version_mut() = hyper::Version::HTTP_2;
                // The public validator owns wire framing. H1-only keep-alive is
                // ignored; other connection-specific headers are rejected there.
                request.headers_mut().remove("keep-alive");
                if let Some(size) = body.size_hint().exact() {
                    if size > 0
                        || matches!(
                            *request.method(),
                            hyper::Method::POST | hyper::Method::PUT | hyper::Method::PATCH
                        )
                    {
                        request.headers_mut().insert("content-length", size.into());
                    }
                }
                let ended = body.is_end_stream();
                let (mut response, tx) =
                    s.send_request(request, ended).map_err(|e| e.to_string())?;
                let mut upload = Upload {
                    body,
                    tx,
                    data: Bytes::new(),
                    ended,
                };
                let response = poll_fn(|cx| {
                    // A complete early response wins over further producer work.
                    if let Poll::Ready(value) = Pin::new(&mut response).poll(cx) {
                        return Poll::Ready(value.map_err(|e| e.to_string()));
                    }
                    if let Poll::Ready(Err(error)) = upload.poll(cx) {
                        return Poll::Ready(Err(error));
                    }
                    Poll::Pending
                })
                .await?;
                Ok(response.map(|recv| ResponseBody::H2 {
                    recv,
                    upload,
                    data_ended: false,
                    ended: false,
                }))
            }
        }
    }
}
use std::future::Future;
pub(super) struct Upload {
    body: ClientBody,
    tx: h2::SendStream<Bytes>,
    data: Bytes,
    ended: bool,
}
impl Upload {
    fn poll(&mut self, cx: &mut Context<'_>) -> Poll<Result<(), String>> {
        if self.ended {
            return Poll::Ready(Ok(()));
        }
        // Bounded producer work per interpreter turn; kernel transport has the
        // connection's canonical waker, this stream uses its own operation waker.
        for _ in 0..32 {
            if let Poll::Ready(reason) = self.tx.poll_reset(cx) {
                return Poll::Ready(Err(reason
                    .map(|r| format!("HTTP/2 stream reset: {r:?}"))
                    .unwrap_or_else(|e| e.to_string())));
            }
            if self.data.is_empty() {
                match ready!(Pin::new(&mut self.body).poll_frame(cx)) {
                    None => {
                        self.tx
                            .send_data(Bytes::new(), true)
                            .map_err(|e| e.to_string())?;
                        self.ended = true;
                        return Poll::Ready(Ok(()));
                    }
                    Some(Err(error)) => return Poll::Ready(Err(error.to_string())),
                    Some(Ok(frame)) => {
                        self.data = frame.into_data().map_err(|_| "Unexpected upload trailer")?
                    }
                }
                if self.data.is_empty() {
                    continue;
                }
            }
            self.tx.reserve_capacity(self.data.len().min(16384));
            if self.tx.capacity() == 0 {
                match ready!(self.tx.poll_capacity(cx)) {
                    Some(Ok(0)) => continue,
                    Some(Ok(_)) => (),
                    Some(Err(error)) => return Poll::Ready(Err(error.to_string())),
                    None => return Poll::Ready(Err("HTTP/2 upload capacity closed".into())),
                }
            }
            let count = self.data.len().min(self.tx.capacity()).min(16384);
            let bytes = self.data.split_to(count);
            self.ended = self.data.is_empty() && self.body.is_end_stream();
            self.tx
                .send_data(bytes, self.ended)
                .map_err(|e| e.to_string())?;
            if self.ended {
                return Poll::Ready(Ok(()));
            }
        }
        cx.waker().wake_by_ref();
        Poll::Pending
    }
}
impl Drop for Upload {
    fn drop(&mut self) {
        // Also discard DATA queued in h2 but not yet written to the socket.
        // h2 ignores a reset of a fully closed stream. A live sibling is intact.
        self.tx.send_reset(h2::Reason::CANCEL);
    }
}
pub(super) enum ResponseBody {
    H1(Incoming),
    H2 {
        recv: h2::RecvStream,
        upload: Upload,
        data_ended: bool,
        ended: bool,
    },
}
impl Body for ResponseBody {
    type Data = Bytes;
    type Error = String;
    fn poll_frame(
        self: Pin<&mut Self>,
        cx: &mut Context<'_>,
    ) -> Poll<Option<Result<Frame<Bytes>, String>>> {
        match self.get_mut() {
            Self::H1(body) => Pin::new(body)
                .poll_frame(cx)
                .map(|f| f.map(|r| r.map_err(|e| e.to_string()))),
            Self::H2 {
                recv,
                upload,
                data_ended,
                ended,
            } => {
                if *ended {
                    return Poll::Ready(None);
                }
                if !*data_ended {
                    match recv.poll_data(cx) {
                        Poll::Ready(Some(Ok(data))) => {
                            if let Err(error) = recv.flow_control().release_capacity(data.len()) {
                                return Poll::Ready(Some(Err(error.to_string())));
                            }
                            return Poll::Ready(Some(Ok(Frame::data(data))));
                        }
                        Poll::Ready(Some(Err(error))) => {
                            return Poll::Ready(Some(Err(error.to_string())))
                        }
                        Poll::Ready(None) => *data_ended = true,
                        Poll::Pending => (),
                    }
                }
                if *data_ended {
                    match recv.poll_trailers(cx) {
                        Poll::Ready(Ok(trailers)) => {
                            *ended = true;
                            return Poll::Ready(trailers.map(|t| Ok(Frame::trailers(t))));
                        }
                        Poll::Ready(Err(error)) => {
                            return Poll::Ready(Some(Err(error.to_string())))
                        }
                        Poll::Pending => (),
                    }
                }
                if let Poll::Ready(Err(error)) = upload.poll(cx) {
                    return Poll::Ready(Some(Err(error)));
                }
                Poll::Pending
            }
        }
    }
    fn is_end_stream(&self) -> bool {
        match self {
            Self::H1(b) => b.is_end_stream(),
            Self::H2 { ended, .. } => *ended,
        }
    }
    fn size_hint(&self) -> SizeHint {
        match self {
            Self::H1(b) => b.size_hint(),
            _ => SizeHint::default(),
        }
    }
}
pub(super) type Setup = Pin<Box<dyn Future<Output = Result<(Sender, Driver), String>>>>;
pub(super) async fn h2_handshake(transport: ClientTransport) -> Result<(Sender, Driver), String> {
    let mut b = h2::client::Builder::new();
    b.initial_window_size(65535)
        .initial_connection_window_size(1048576)
        .max_frame_size(16384)
        .max_header_list_size(65536)
        .header_table_size(4096)
        .max_concurrent_streams(0)
        .enable_push(false)
        .max_concurrent_reset_streams(64)
        .max_pending_accept_reset_streams(64)
        .max_local_error_reset_streams(Some(64))
        .max_send_buffer_size(65536);
    let (sender, driver) = b
        .handshake(TokioIo(transport))
        .await
        .map_err(|e| e.to_string())?;
    Ok((
        Sender::H2(sender),
        Box::pin(async move { driver.await.map_err(|e| e.to_string()) }) as Driver,
    ))
}
struct TokioIo(ClientTransport);
impl tokio::io::AsyncRead for TokioIo {
    fn poll_read(
        mut self: Pin<&mut Self>,
        cx: &mut Context<'_>,
        buf: &mut tokio::io::ReadBuf<'_>,
    ) -> Poll<io::Result<()>> {
        // The existing Hyper Read contract initializes exactly its filled bytes.
        let mut hyper_buf = hyper::rt::ReadBuf::uninit(unsafe { buf.unfilled_mut() });
        ready!(hyper::rt::Read::poll_read(
            Pin::new(&mut self.0),
            cx,
            hyper_buf.unfilled()
        ))?;
        let count = hyper_buf.filled().len();
        unsafe { buf.assume_init(count) };
        buf.advance(count);
        Poll::Ready(Ok(()))
    }
}
impl tokio::io::AsyncWrite for TokioIo {
    fn poll_write(
        mut self: Pin<&mut Self>,
        cx: &mut Context<'_>,
        bytes: &[u8],
    ) -> Poll<io::Result<usize>> {
        hyper::rt::Write::poll_write(Pin::new(&mut self.0), cx, bytes)
    }
    fn poll_flush(mut self: Pin<&mut Self>, cx: &mut Context<'_>) -> Poll<io::Result<()>> {
        hyper::rt::Write::poll_flush(Pin::new(&mut self.0), cx)
    }
    fn poll_shutdown(mut self: Pin<&mut Self>, cx: &mut Context<'_>) -> Poll<io::Result<()>> {
        hyper::rt::Write::poll_shutdown(Pin::new(&mut self.0), cx)
    }
}
