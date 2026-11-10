//! A bounded upload slot owned by the C job. PHP fills it between drive calls;
//! only the connection driver consumes it. No application callback runs here.
use http_body_util::Full;
use hyper::body::{Body, Bytes, Frame, SizeHint};
use std::{
    ffi::c_void,
    io,
    pin::Pin,
    task::{Context, Poll},
};

extern "C" {
    fn ar_hyper_upload(job: *mut c_void, data: *mut *const u8, length: *mut usize) -> i32;
}

pub(super) enum ClientBody {
    Buffered(Full<Bytes>),
    Streaming {
        job: usize,
        remaining: Option<u64>,
        ended: bool,
    },
}
impl ClientBody {
    pub(super) fn new(job: usize, data: Bytes, size: Option<i64>) -> Self {
        match size {
            None => Self::Buffered(Full::new(data)),
            Some(size) => Self::Streaming {
                job,
                remaining: (size >= 0).then_some(size as u64),
                ended: false,
            },
        }
    }
}
impl Body for ClientBody {
    type Data = Bytes;
    type Error = io::Error;
    fn poll_frame(
        self: Pin<&mut Self>,
        cx: &mut Context<'_>,
    ) -> Poll<Option<Result<Frame<Bytes>, io::Error>>> {
        match self.get_mut() {
            Self::Buffered(body) => Pin::new(body)
                .poll_frame(cx)
                .map(|frame| frame.map(|result| result.map_err(|never| match never {}))),
            Self::Streaming {
                job,
                remaining,
                ended,
            } => {
                if *ended || *remaining == Some(0) {
                    return Poll::Ready(None);
                }
                let mut data = std::ptr::null();
                let mut length = 0;
                match unsafe { ar_hyper_upload(*job as *mut c_void, &mut data, &mut length) } {
                    // The C resume entry wakes the canonical connection driver.
                    // No self-wake or polling while waiting for the producer.
                    1 => Poll::Pending,
                    2 if remaining.is_none() => {
                        *ended = true;
                        Poll::Ready(None)
                    }
                    0 if !data.is_null()
                        && length > 0
                        && length <= 65536
                        && remaining.is_none_or(|n| length as u64 <= n) =>
                    {
                        // Copy the borrowed slot before returning to C/PHP. The
                        // slot can then be refilled, independent of socket writes.
                        let bytes = Bytes::copy_from_slice(unsafe {
                            std::slice::from_raw_parts(data, length)
                        });
                        if let Some(left) = remaining {
                            *left -= length as u64;
                        }
                        Poll::Ready(Some(Ok(Frame::data(bytes))))
                    }
                    _ => {
                        *ended = true;
                        Poll::Ready(Some(Err(io::Error::new(
                            io::ErrorKind::InvalidData,
                            "Invalid or cancelled upload slot",
                        ))))
                    }
                }
            }
        }
    }
    fn is_end_stream(&self) -> bool {
        match self {
            Self::Buffered(body) => body.is_end_stream(),
            Self::Streaming {
                ended, remaining, ..
            } => *ended || *remaining == Some(0),
        }
    }
    fn size_hint(&self) -> SizeHint {
        match self {
            Self::Buffered(body) => body.size_hint(),
            Self::Streaming {
                remaining: Some(size),
                ..
            } => SizeHint::with_exact(*size),
            Self::Streaming { .. } => SizeHint::default(),
        }
    }
}
