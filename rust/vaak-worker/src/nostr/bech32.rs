//! NIP-19 bech32 (not bech32m) for npub / note display links.
//! Mirrors `ap_nostr_bech32_*` in api/ap-nostr.php.

const CHARSET: &[u8; 32] = b"qpzry9x8gf2tvdw0s3jn54khce6mua7l";

fn polymod(values: &[u8]) -> u32 {
    const GEN: [u32; 5] = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
    let mut chk: u32 = 1;
    for &v in values {
        let top = chk >> 25;
        chk = ((chk & 0x1ff_ffff) << 5) ^ u32::from(v);
        for (i, g) in GEN.iter().enumerate() {
            if (top >> i) & 1 == 1 {
                chk ^= g;
            }
        }
    }
    chk
}

fn hrp_expand(hrp: &str) -> Vec<u8> {
    let mut out: Vec<u8> = hrp.bytes().map(|b| b >> 5).collect();
    out.push(0);
    out.extend(hrp.bytes().map(|b| b & 31));
    out
}

pub fn encode(hrp: &str, bytes: &[u8]) -> String {
    let mut values = Vec::with_capacity(bytes.len() * 8 / 5 + 8);
    let mut acc: u32 = 0;
    let mut bits = 0u32;
    for &b in bytes {
        acc = ((acc << 8) | u32::from(b)) & 0xff_ffff;
        bits += 8;
        while bits >= 5 {
            bits -= 5;
            values.push(((acc >> bits) & 31) as u8);
        }
    }
    if bits > 0 {
        values.push(((acc << (5 - bits)) & 31) as u8);
    }
    let mut check = hrp_expand(hrp);
    check.extend_from_slice(&values);
    check.extend_from_slice(&[0; 6]);
    let poly = polymod(&check) ^ 1;
    for i in 0..6 {
        values.push(((poly >> (5 * (5 - i))) & 31) as u8);
    }
    let mut out = String::with_capacity(hrp.len() + 1 + values.len());
    out.push_str(hrp);
    out.push('1');
    for v in values {
        out.push(CHARSET[v as usize] as char);
    }
    out
}

/// `(hrp, bytes)` for valid bech32 with a good checksum.
pub fn decode(input: &str) -> Option<(String, Vec<u8>)> {
    if input.len() > 5000 || (input != input.to_ascii_lowercase() && input != input.to_ascii_uppercase()) {
        return None;
    }
    let s = input.to_ascii_lowercase();
    let pos = s.rfind('1')?;
    if pos < 1 || pos + 7 > s.len() {
        return None;
    }
    let hrp = &s[..pos];
    let mut values = Vec::with_capacity(s.len() - pos - 1);
    for c in s[pos + 1..].bytes() {
        values.push(CHARSET.iter().position(|&x| x == c)? as u8);
    }
    let mut check = hrp_expand(hrp);
    check.extend_from_slice(&values);
    if polymod(&check) != 1 {
        return None;
    }
    let data = &values[..values.len() - 6];
    let mut acc: u32 = 0;
    let mut bits = 0u32;
    let mut out = Vec::with_capacity(data.len() * 5 / 8);
    for &v in data {
        acc = ((acc << 5) | u32::from(v)) & 0xff_ffff;
        bits += 5;
        if bits >= 8 {
            bits -= 8;
            out.push(((acc >> bits) & 0xff) as u8);
        }
    }
    if bits >= 5 || ((acc << (8 - bits)) & 0xff) != 0 {
        return None;
    }
    Some((hrp.to_string(), out))
}

/// 64-char hex → `npub1…` (empty when the hex is not a key).
pub fn npub(hex_pubkey: &str) -> String {
    match hex::decode(hex_pubkey) {
        Ok(bytes) if bytes.len() == 32 => encode("npub", &bytes),
        _ => String::new(),
    }
}

/// 64-char hex event id → `note1…`.
pub fn note(hex_id: &str) -> String {
    match hex::decode(hex_id) {
        Ok(bytes) if bytes.len() == 32 => encode("note", &bytes),
        _ => String::new(),
    }
}

/// The 32-byte key or id inside npub / note / nprofile / nevent (TLV type 0).
pub fn decode_entity(input: &str) -> Option<(String, String)> {
    let (hrp, bytes) = decode(input)?;
    match hrp.as_str() {
        "npub" | "note" if bytes.len() == 32 => Some((hrp, hex::encode(bytes))),
        "nprofile" | "nevent" => {
            let mut i = 0usize;
            while i + 2 <= bytes.len() {
                let (t, len) = (bytes[i], bytes[i + 1] as usize);
                let value = bytes.get(i + 2..i + 2 + len)?;
                if t == 0 && value.len() == 32 {
                    return Some((hrp, hex::encode(value)));
                }
                i += 2 + len;
            }
            None
        }
        _ => None,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    // NIP-19 "Examples".
    const HEX: &str = "7e7e9c42a91bfef19fa929e5fda1b72e0ebc1a4c1141673e2794234d86addf4e";
    const NPUB: &str = "npub10elfcs4fr0l0r8af98jlmgdh9c8tcxjvz9qkw038js35mp4dma8qzvjptg";

    #[test]
    fn nip19_vectors_round_trip() {
        assert_eq!(npub(HEX), NPUB);
        assert_eq!(decode_entity(NPUB), Some(("npub".into(), HEX.into())));
        assert_eq!(
            npub("3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d"),
            "npub180cvv07tjdrrgpa0j7j7tmnyl2yr6yr7l8j4s3evf6u64th6gkwsyjh6w6"
        );
        let nprofile = "nprofile1qqsrhuxx8l9ex335q7he0f09aej04zpazpl0ne2cgukyawd24mayt8gpp4mhxue69uhhytnc9e3k7mgpz4mhxue69uhkg6nzv9ejuumpv34kytnrdaksjlyr9p";
        assert_eq!(
            decode_entity(nprofile).map(|(_, h)| h),
            Some("3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d".into())
        );
        let id = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef";
        assert_eq!(decode_entity(&note(id)), Some(("note".into(), id.into())));
    }

    #[test]
    fn rejects_bad_input() {
        let mut bad = NPUB.to_string();
        bad.pop();
        bad.push('q');
        assert!(decode(&bad).is_none());
        assert!(decode("npub1QPZ").is_none());
        assert!(decode("").is_none());
        assert_eq!(npub("zz"), "");
    }
}
