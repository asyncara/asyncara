//! In-memory rustls control without Asyncara, PHP, Hyper, Fibers or socket adapters.
#[cfg(feature = "aws")]
use rustls::crypto::aws_lc_rs as provider;
#[cfg(not(feature = "aws"))]
use rustls::crypto::ring as provider;
use rustls::{
    ClientConfig, ClientConnection, Connection, RootCertStore, ServerConfig, ServerConnection,
};
use std::{
    io::{Cursor, Read, Write},
    sync::Arc,
};
fn move_tls(from: &mut Connection, to: &mut Connection) {
    let mut out = Vec::new();
    while from.wants_write() {
        from.write_tls(&mut out).unwrap();
    }
    if out.is_empty() {
        return;
    }
    std::fs::File::create("/dev/null")
        .unwrap()
        .write_all(&out)
        .unwrap();
    let mut wire = Cursor::new(out);
    while wire.position() < wire.get_ref().len() as u64 {
        to.read_tls(&mut wire).unwrap();
        to.process_new_packets().unwrap();
    }
}
fn main() {
    let path = std::path::PathBuf::from(std::env::args().nth(1).unwrap());
    let ca = std::fs::read(path.join("ca.pem")).unwrap();
    let cert = std::fs::read(path.join("server.pem")).unwrap();
    let key = std::fs::read(path.join("server.key")).unwrap();
    let certs = rustls_pemfile::certs(&mut &*cert)
        .collect::<Result<Vec<_>, _>>()
        .unwrap();
    let key = rustls_pemfile::private_key(&mut &*key).unwrap().unwrap();
    let mut provider = provider::default_provider();
    if let Some(name) = std::env::args().nth(2) {
        provider
            .cipher_suites
            .retain(|s| format!("{:?}", s.suite()).contains(&name));
    }
    let provider = Arc::new(provider);
    let mut roots = RootCertStore::empty();
    for c in rustls_pemfile::certs(&mut &*ca) {
        roots.add(c.unwrap()).unwrap();
    }
    let timing = std::env::var_os("ASYNCARA_CRYPTO_TIMING").is_some();
    let mutual = std::env::var_os("ASYNCARA_CRYPTO_CLIENT_AUTH").is_some();
    let began = std::time::Instant::now();
    let versions: &[&rustls::SupportedProtocolVersion] =
        if std::env::args().nth(3).as_deref() == Some("TLS12") {
            &[&rustls::version::TLS12]
        } else {
            &[&rustls::version::TLS13, &rustls::version::TLS12]
        };
    let server = ServerConfig::builder_with_provider(provider.clone())
        .with_protocol_versions(versions)
        .unwrap();
    let server = if mutual {
        let verifier = rustls::server::WebPkiClientVerifier::builder_with_provider(
            Arc::new(roots.clone()),
            provider.clone(),
        )
        .build()
        .unwrap();
        server.with_client_cert_verifier(verifier)
    } else {
        server.with_no_client_auth()
    };
    let mut server = server.with_single_cert(certs, key).unwrap();
    server.send_tls13_tickets = 0;
    server.session_storage = Arc::new(rustls::server::NoServerSessionStorage {});
    let client = ClientConfig::builder_with_provider(provider)
        .with_safe_default_protocol_versions()
        .unwrap()
        .with_root_certificates(roots);
    let client = if mutual {
        let cert = std::fs::read(path.join("client.pem")).unwrap();
        let key = std::fs::read(path.join("client.key")).unwrap();
        let certs = rustls_pemfile::certs(&mut &*cert)
            .collect::<Result<Vec<_>, _>>()
            .unwrap();
        let key = rustls_pemfile::private_key(&mut &*key).unwrap().unwrap();
        client.with_client_auth_cert(certs, key).unwrap()
    } else {
        client.with_no_client_auth()
    };
    if timing {
        println!("config_seconds={:.6}", began.elapsed().as_secs_f64());
    }
    let mut s = Connection::Server(ServerConnection::new(Arc::new(server)).unwrap());
    let mut c = Connection::Client(
        ClientConnection::new(Arc::new(client), "localhost".try_into().unwrap()).unwrap(),
    );
    if timing {
        println!("connection_seconds={:.6}", began.elapsed().as_secs_f64());
    }
    for _ in 0..10 {
        move_tls(&mut c, &mut s);
        move_tls(&mut s, &mut c);
        if !s.is_handshaking() && !c.is_handshaking() {
            break;
        }
    }
    assert!(!s.is_handshaking() && !c.is_handshaking());
    if timing {
        println!(
            "handshake_seconds={:.6} mutual={}",
            began.elapsed().as_secs_f64(),
            mutual
        );
    }
    println!("cipher: {:?}", c.negotiated_cipher_suite());
    for size in [1, 16, 17, 255, 16384, 16385, 65536] {
        for direction in 0..2 {
            let (from, to) = if direction == 0 {
                (&mut c, &mut s)
            } else {
                (&mut s, &mut c)
            };
            let input = vec![42u8; size];
            let mut written = 0;
            let mut read = Vec::new();
            while written < size {
                let end = (written + 16384).min(size);
                written += from.writer().write(&input[written..end]).unwrap();
                move_tls(from, to);
                let mut buf = [0u8; 16384];
                loop {
                    match to.reader().read(&mut buf) {
                        Ok(0) => panic!("unexpected EOF"),
                        Ok(n) => read.extend_from_slice(&buf[..n]),
                        Err(e) if e.kind() == std::io::ErrorKind::WouldBlock => break,
                        Err(e) => panic!("{e}"),
                    }
                }
            }
            assert_eq!(input, read);
        }
        println!("{size} OK");
    }
}
