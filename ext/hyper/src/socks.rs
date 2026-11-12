//! RFC 1928 CONNECT / RFC 1929 over the existing nonblocking reactor IO.
//! No resolver, executor, file IO or detached task; dropping setup closes its owner.
use super::ClientIo;
use hyper::rt::{Read, Write};
use std::{future::poll_fn, io, net::IpAddr, pin::Pin, task::Poll};

async fn send(wire: &mut ClientIo, bytes: &[u8]) -> io::Result<()> {
    let mut at = 0;
    while at < bytes.len() {
        let n = poll_fn(|cx| Pin::new(&mut *wire).poll_write(cx, &bytes[at..])).await?;
        if n == 0 {
            return Err(io::ErrorKind::WriteZero.into());
        }
        at += n;
    }
    poll_fn(|cx| Pin::new(&mut *wire).poll_flush(cx)).await
}
async fn read(wire: &mut ClientIo, bytes: &mut [u8]) -> io::Result<()> {
    let mut at = 0;
    while at < bytes.len() {
        let n = poll_fn(|cx| {
            let mut buf = hyper::rt::ReadBuf::new(&mut bytes[at..]);
            match Pin::new(&mut *wire).poll_read(cx, buf.unfilled()) {
                Poll::Pending => Poll::Pending,
                Poll::Ready(r) => Poll::Ready(r.map(|()| buf.filled().len())),
            }
        })
        .await?;
        if n == 0 {
            return Err(io::ErrorKind::UnexpectedEof.into());
        }
        at += n;
    }
    Ok(())
}
pub(super) async fn connect(
    mut wire: ClientIo,
    host: &str,
    port: u16,
    target: Option<IpAddr>,
    auth: &[u8],
) -> io::Result<ClientIo> {
    let method = if auth.is_empty() { 0 } else { 2 };
    send(&mut wire, &[5, 1, method]).await?;
    let mut reply = [0u8; 2];
    read(&mut wire, &mut reply).await?;
    // Never accept an unoffered method or silently downgrade explicit credentials.
    if reply != [5, method] {
        return Err(io::ErrorKind::PermissionDenied.into());
    }
    if method == 2 {
        send(&mut wire, auth).await?;
        read(&mut wire, &mut reply).await?;
        if reply != [1, 0] {
            return Err(io::ErrorKind::PermissionDenied.into());
        }
    }
    let mut request = [0u8; 262];
    request[..3].copy_from_slice(&[5, 1, 0]);
    let end = match target.or_else(|| host.parse().ok()) {
        Some(IpAddr::V4(ip)) => {
            request[3] = 1;
            request[4..8].copy_from_slice(&ip.octets());
            8
        }
        Some(IpAddr::V6(ip)) => {
            request[3] = 4;
            request[4..20].copy_from_slice(&ip.octets());
            20
        }
        None => {
            if host.is_empty() || host.len() > 253 || !super::valid_dns_name(host) {
                return Err(io::ErrorKind::InvalidInput.into());
            }
            request[3] = 3;
            request[4] = host.len() as u8;
            request[5..5 + host.len()].copy_from_slice(host.as_bytes());
            5 + host.len()
        }
    };
    request[end..end + 2].copy_from_slice(&port.to_be_bytes());
    send(&mut wire, &request[..end + 2]).await?;
    let mut header = [0u8; 4];
    read(&mut wire, &mut header).await?;
    if header[..3] != [5, 0, 0] {
        return Err(io::ErrorKind::ConnectionRefused.into());
    }
    let remaining = match header[3] {
        1 => 6,
        4 => 18,
        3 => {
            read(&mut wire, &mut reply[..1]).await?;
            if reply[0] == 0 {
                return Err(io::ErrorKind::InvalidData.into());
            }
            reply[0] as usize + 2
        }
        _ => return Err(io::ErrorKind::InvalidData.into()),
    };
    // Consume exactly the bound-address frame, never the tunneled protocol bytes.
    read(&mut wire, &mut request[..remaining]).await?;
    Ok(wire)
}
