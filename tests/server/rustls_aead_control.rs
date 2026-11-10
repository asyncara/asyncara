//! Isolate the public rustls AEAD API: fixed input, no TLS handshake or runtime.
#[cfg(feature = "aws")]
use rustls::crypto::aws_lc_rs as provider;
use rustls::crypto::cipher::{AeadKey, Iv, OutboundChunks, OutboundPlainMessage};
#[cfg(not(feature = "aws"))]
use rustls::crypto::ring as provider;
use rustls::{ContentType, ProtocolVersion};
use std::io::Write;
fn main() {
    let suite = provider::cipher_suite::TLS13_AES_256_GCM_SHA384
        .tls13()
        .unwrap();
    let mut cipher = suite
        .aead_alg
        .encrypter(AeadKey::from([7u8; 32]), Iv::from([9u8; 12]));
    let mut sink = std::fs::File::create("/dev/null").unwrap();
    for (seq, size) in [0, 1, 15, 16, 17, 255, 16384, 16385]
        .into_iter()
        .enumerate()
    {
        let data = vec![42u8; size];
        let message = OutboundPlainMessage {
            typ: ContentType::ApplicationData,
            version: ProtocolVersion::TLSv1_2,
            payload: OutboundChunks::Single(&data),
        };
        let encrypted = cipher.encrypt(message, seq as u64).unwrap().encode();
        sink.write_all(&encrypted).unwrap();
        println!("{size} OK");
    }
}
