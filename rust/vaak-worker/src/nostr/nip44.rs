//! NIP-44 v2 encryption: what NIP-46 remote-signing requests and answers
//! travel in. secp256k1 ECDH (x coordinate) -> HKDF-SHA256 conversation
//! key; per message a random 32-byte nonce, ChaCha20 over the padded text,
//! HMAC-SHA256 over nonce + ciphertext.

use base64::Engine;
use chacha20::cipher::{KeyIvInit, StreamCipher};
use chacha20::ChaCha20;
use hmac::{Hmac, KeyInit, Mac};
use sha2::Sha256;

type HmacSha256 = Hmac<Sha256>;

const VERSION: u8 = 2;
const MIN_PLAINTEXT: usize = 1;
const MAX_PLAINTEXT: usize = 65535;

fn hmac(key: &[u8], parts: &[&[u8]]) -> [u8; 32] {
    let mut mac = <HmacSha256 as KeyInit>::new_from_slice(key).expect("hmac takes any key length");
    for p in parts {
        mac.update(p);
    }
    mac.finalize().into_bytes().into()
}

/// Shared key between `secret` (32 bytes) and the x-only `pubkey` (hex).
pub fn conversation_key(secret: &[u8; 32], pubkey_hex: &str) -> Result<[u8; 32], &'static str> {
    let x = hex::decode(pubkey_hex).map_err(|_| "bad pubkey")?;
    if x.len() != 32 {
        return Err("bad pubkey");
    }
    let mut sec1 = [0u8; 33];
    sec1[0] = 0x02;
    sec1[1..].copy_from_slice(&x);
    let public = k256::PublicKey::from_sec1_bytes(&sec1).map_err(|_| "pubkey not on curve")?;
    let secret = k256::SecretKey::from_slice(secret).map_err(|_| "bad secret key")?;
    let shared = k256::ecdh::diffie_hellman(secret.to_nonzero_scalar(), public.as_affine());
    Ok(hmac(b"nip44-v2", &[shared.raw_secret_bytes().as_slice()]))
}

/// (chacha key, chacha nonce, hmac key) for one message.
fn message_keys(conversation: &[u8; 32], nonce: &[u8; 32]) -> ([u8; 32], [u8; 12], [u8; 32]) {
    // HKDF-expand to 76 bytes: T(1) | T(2) | T(3).
    let t1 = hmac(conversation, &[nonce, &[1]]);
    let t2 = hmac(conversation, &[&t1, nonce, &[2]]);
    let t3 = hmac(conversation, &[&t2, nonce, &[3]]);
    let mut okm = [0u8; 96];
    okm[..32].copy_from_slice(&t1);
    okm[32..64].copy_from_slice(&t2);
    okm[64..].copy_from_slice(&t3);
    let mut key = [0u8; 32];
    let mut iv = [0u8; 12];
    let mut mac = [0u8; 32];
    key.copy_from_slice(&okm[..32]);
    iv.copy_from_slice(&okm[32..44]);
    mac.copy_from_slice(&okm[44..76]);
    (key, iv, mac)
}

pub fn padded_len(len: usize) -> usize {
    if len <= 32 {
        return 32;
    }
    let next_power = 1usize << (usize::BITS - (len - 1).leading_zeros());
    let chunk = if next_power <= 256 { 32 } else { next_power / 8 };
    chunk * ((len - 1) / chunk + 1)
}

pub fn encrypt_with_nonce(conversation: &[u8; 32], plaintext: &str, nonce: &[u8; 32]) -> Result<String, &'static str> {
    let text = plaintext.as_bytes();
    if text.len() < MIN_PLAINTEXT || text.len() > MAX_PLAINTEXT {
        return Err("message length out of range");
    }
    let (key, iv, mac_key) = message_keys(conversation, nonce);
    let mut buf = vec![0u8; 2 + padded_len(text.len())];
    buf[..2].copy_from_slice(&(text.len() as u16).to_be_bytes());
    buf[2..2 + text.len()].copy_from_slice(text);
    ChaCha20::new(&key.into(), &iv.into()).apply_keystream(&mut buf);
    let mac = hmac(&mac_key, &[nonce, &buf]);
    let mut out = Vec::with_capacity(1 + 32 + buf.len() + 32);
    out.push(VERSION);
    out.extend_from_slice(nonce);
    out.extend_from_slice(&buf);
    out.extend_from_slice(&mac);
    Ok(base64::engine::general_purpose::STANDARD.encode(out))
}

pub fn encrypt(conversation: &[u8; 32], plaintext: &str) -> Result<String, &'static str> {
    let mut nonce = [0u8; 32];
    {
        use rand::TryRng;
        rand::rngs::SysRng.try_fill_bytes(&mut nonce).map_err(|_| "no randomness")?;
    }
    encrypt_with_nonce(conversation, plaintext, &nonce)
}

pub fn decrypt(conversation: &[u8; 32], payload: &str) -> Result<String, &'static str> {
    if payload.starts_with('#') {
        return Err("unknown encryption version");
    }
    if payload.len() < 132 || payload.len() > 87472 {
        return Err("bad payload size");
    }
    let raw = base64::engine::general_purpose::STANDARD.decode(payload).map_err(|_| "bad base64")?;
    if raw.len() < 99 || raw[0] != VERSION {
        return Err("unknown encryption version");
    }
    let mut nonce = [0u8; 32];
    nonce.copy_from_slice(&raw[1..33]);
    let (ciphertext, mac) = raw[33..].split_at(raw.len() - 33 - 32);
    let (key, iv, mac_key) = message_keys(conversation, &nonce);
    let mut check = <HmacSha256 as KeyInit>::new_from_slice(&mac_key).expect("hmac takes any key length");
    check.update(&nonce);
    check.update(ciphertext);
    check.verify_slice(mac).map_err(|_| "bad mac")?;
    let mut buf = ciphertext.to_vec();
    ChaCha20::new(&key.into(), &iv.into()).apply_keystream(&mut buf);
    if buf.len() < 2 {
        return Err("bad padding");
    }
    let len = u16::from_be_bytes([buf[0], buf[1]]) as usize;
    if len < MIN_PLAINTEXT || buf.len() != 2 + padded_len(len) {
        return Err("bad padding");
    }
    String::from_utf8(buf[2..2 + len].to_vec()).map_err(|_| "not utf-8")
}

#[cfg(test)]
mod tests {
    use super::*;

    fn key(n: u8) -> [u8; 32] {
        let mut k = [0u8; 32];
        k[31] = n;
        k
    }

    fn pubkey_hex(secret: &[u8; 32]) -> String {
        let sk = k256::schnorr::SigningKey::from_slice(secret).unwrap();
        hex::encode(sk.verifying_key().to_bytes())
    }

    // First vector of nip44.vectors.json (valid.encrypt_decrypt).
    #[test]
    fn spec_vector() {
        let conv = conversation_key(&key(1), &pubkey_hex(&key(2))).unwrap();
        assert_eq!(hex::encode(conv), "c41c775356fd92eadc63ff5a0dc1da211b268cbea22316767095b2871ea1412d");
        let payload = encrypt_with_nonce(&conv, "a", &key(1)).unwrap();
        assert_eq!(
            payload,
            "AgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABee0G5VSK0/9YypIObAtDKfYEAjD35uVkHyB0F4DwrcNaCXlCWZKaArsGrY6M9wnuTMxWfp1RTN9Xga8no+kF5Vsb"
        );
        assert_eq!(decrypt(&conv, &payload).unwrap(), "a");
    }

    #[test]
    fn both_sides_agree_and_tampering_fails() {
        let a = conversation_key(&key(7), &pubkey_hex(&key(9))).unwrap();
        let b = conversation_key(&key(9), &pubkey_hex(&key(7))).unwrap();
        assert_eq!(a, b);
        let text = "x".repeat(300);
        let payload = encrypt(&a, &text).unwrap();
        assert_eq!(decrypt(&b, &payload).unwrap(), text);
        let mut raw = base64::engine::general_purpose::STANDARD.decode(&payload).unwrap();
        raw[40] ^= 1;
        let bad = base64::engine::general_purpose::STANDARD.encode(raw);
        assert_eq!(decrypt(&b, &bad), Err("bad mac"));
    }

    #[test]
    fn padding() {
        assert_eq!(padded_len(1), 32);
        assert_eq!(padded_len(32), 32);
        assert_eq!(padded_len(33), 64);
        assert_eq!(padded_len(257), 320);
        assert_eq!(padded_len(1000), 1024);
    }
}
