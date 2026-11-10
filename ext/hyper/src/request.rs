//! Bounded copies at the C boundary; no borrowed request memory survives submit.
use super::Address;
use hyper::{
    body::Bytes,
    header::{HeaderMap, HeaderName, HeaderValue},
    Method,
};

#[repr(C)]
pub struct RequestData {
    pub method: *const u8,
    pub method_len: usize,
    pub body: *const u8,
    pub body_len: usize,
    pub headers: *const Address,
    pub header_count: usize,
}
pub(super) struct RequestSpec {
    pub method: Method,
    pub headers: HeaderMap,
    pub body: Bytes,
    pub stream_response: bool,
    pub upload_size: Option<i64>,
}
impl Default for RequestSpec {
    fn default() -> Self {
        Self {
            method: Method::GET,
            headers: HeaderMap::new(),
            body: Bytes::new(),
            stream_response: false,
            upload_size: None,
        }
    }
}
impl RequestSpec {
    pub(super) unsafe fn copy(raw: *const RequestData) -> Result<Self, ()> {
        if raw.is_null() {
            return Ok(Self::default());
        }
        let raw = &*raw;
        if raw.method.is_null()
            || raw.method_len == 0
            || raw.method_len > 32
            || raw.body_len > 16 * 1024 * 1024
            || (raw.body_len > 0 && raw.body.is_null())
            || raw.header_count > 256
            || (raw.header_count > 0 && raw.headers.is_null())
        {
            return Err(());
        }
        let method = std::slice::from_raw_parts(raw.method, raw.method_len);
        if !method.iter().all(u8::is_ascii_uppercase) {
            return Err(());
        }
        let method = Method::from_bytes(method).map_err(|_| ())?;
        // Tunnels/TRACE and caller-owned wire framing are not implemented here.
        if method == Method::CONNECT
            || method == Method::TRACE
            || (method == Method::HEAD && raw.body_len > 0)
        {
            return Err(());
        }
        let mut headers = HeaderMap::new();
        let mut bytes = 0usize;
        for index in 0..raw.header_count {
            let header = &*raw.headers.add(index);
            if header.bytes.is_null() || header.length > 65536 {
                return Err(());
            }
            bytes = bytes.checked_add(header.length + 2).ok_or(())?;
            if bytes > 65536 {
                return Err(());
            }
            let header = std::slice::from_raw_parts(header.bytes, header.length);
            let colon = header.iter().position(|c| *c == b':').ok_or(())?;
            let name = HeaderName::from_bytes(&header[..colon]).map_err(|_| ())?;
            if matches!(
                name.as_str(),
                "host"
                    | "content-length"
                    | "transfer-encoding"
                    | "connection"
                    | "upgrade"
                    | "expect"
                    | "trailer"
                    | "te"
                    | "accept-encoding"
                    | "proxy-authorization"
                    | "proxy-connection"
            ) {
                return Err(());
            }
            let mut value = &header[colon + 1..];
            while value.first().is_some_and(|c| *c == b' ' || *c == b'\t') {
                value = &value[1..];
            }
            while value.last().is_some_and(|c| *c == b' ' || *c == b'\t') {
                value = &value[..value.len() - 1];
            }
            // Do not silently reinterpret curl's special empty/removal syntax.
            if value.is_empty() {
                return Err(());
            }
            headers.append(name, HeaderValue::from_bytes(value).map_err(|_| ())?);
        }
        let body = if raw.body_len == 0 {
            Bytes::new()
        } else {
            Bytes::copy_from_slice(std::slice::from_raw_parts(raw.body, raw.body_len))
        };
        Ok(Self {
            method,
            headers,
            body,
            stream_response: false,
            upload_size: None,
        })
    }
}
