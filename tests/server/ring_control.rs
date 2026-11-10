//! Independent fixed-input ring AEAD control: no Asyncara, PHP, Hyper or rustls.
use ring::aead::{self, Aad, LessSafeKey, Nonce, UnboundKey};
use ring::rand::SecureRandom;
use std::{fs::File, io::Write};
struct Payload(Vec<u8>);
impl AsMut<[u8]> for Payload {
    fn as_mut(&mut self) -> &mut [u8] {
        &mut self.0[5..]
    }
}
impl<'a> Extend<&'a u8> for Payload {
    fn extend<T: IntoIterator<Item = &'a u8>>(&mut self, iter: T) {
        self.0.extend(iter);
    }
}
fn main() {
    let mut sink = File::create("/dev/null").unwrap();
    for (algorithm, len) in [
        (&aead::AES_128_GCM, 16),
        (&aead::AES_256_GCM, 32),
        (&aead::CHACHA20_POLY1305, 32),
    ] {
        let mut key_bytes = vec![0u8; len];
        ring::rand::SystemRandom::new()
            .fill(&mut key_bytes)
            .unwrap();
        let key = Box::new(LessSafeKey::new(
            UnboundKey::new(algorithm, &key_bytes).unwrap(),
        ));
        for (seq, size) in [0, 1, 15, 16, 17, 255, 16384, 16385, 65536]
            .into_iter()
            .enumerate()
        {
            let plain = vec![42u8; size];
            let mut encrypted = plain.clone();
            let aad = [23u8, 3, 3, ((size + 16) >> 8) as u8, (size + 16) as u8];
            let mut nonce = [0u8; 12];
            nonce[..8].copy_from_slice(&(seq as u64).to_be_bytes());
            key.seal_in_place_append_tag(
                Nonce::assume_unique_for_key(nonce),
                Aad::from(aad),
                &mut encrypted,
            )
            .unwrap();
            sink.write_all(&encrypted).unwrap();
            let decrypted = key
                .open_in_place(
                    Nonce::assume_unique_for_key(nonce),
                    Aad::from(aad),
                    &mut encrypted,
                )
                .unwrap();
            assert_eq!(decrypted, plain);
            println!("{algorithm:?} {size} OK");
            // TLS's five-byte record prefix changes alignment and leaves spare
            // capacity for the tag. Exercise the same AEAD public API on it.
            let mut prefixed = Vec::with_capacity(5 + size + 16);
            prefixed.extend_from_slice(&[0u8; 5]);
            prefixed.extend_from_slice(&plain);
            nonce[11] = 1;
            let tag = key
                .seal_in_place_separate_tag(
                    Nonce::assume_unique_for_key(nonce),
                    Aad::from(aad),
                    &mut prefixed[5..],
                )
                .unwrap();
            prefixed.extend_from_slice(tag.as_ref());
            sink.write_all(&prefixed).unwrap();
            let decrypted = key
                .open_in_place(
                    Nonce::assume_unique_for_key(nonce),
                    Aad::from(aad),
                    &mut prefixed[5..],
                )
                .unwrap();
            assert_eq!(decrypted, plain);
            println!("offset5 {algorithm:?} {size} OK");
            let mut custom = Payload(Vec::with_capacity(5 + size + 16));
            custom.0.extend_from_slice(&[0u8; 5]);
            custom.0.extend_from_slice(&plain);
            nonce[11] = 2;
            key.seal_in_place_append_tag(
                Nonce::assume_unique_for_key(nonce),
                Aad::from(aad),
                &mut custom,
            )
            .unwrap();
            sink.write_all(&custom.0).unwrap();
            let decrypted = key
                .open_in_place(
                    Nonce::assume_unique_for_key(nonce),
                    Aad::from(aad),
                    &mut custom.0[5..],
                )
                .unwrap();
            assert_eq!(decrypted, plain);
            println!("custom {algorithm:?} {size} OK");
        }
    }
}
