//! Outbound ActivityPub HTTP signatures, the same bytes PHP sends.
//!
//! Draft-cavage rsa-sha256 like `ap_signed_get_ex` (GET: `(request-target)
//! host date`) and `ap_deliver_signed_json` (POST: `(request-target) host date
//! digest`; content-type and content-length stay unsigned for Threads and
//! Akkoma). RSA PKCS#1 v1.5 is deterministic, so the same key, keyId and
//! signing string give PHP's exact Signature header.

use anyhow::{anyhow, bail, Context, Result};
use base64::Engine as _;
use ring::rand::SystemRandom;
use ring::signature::{RsaKeyPair, RSA_PKCS1_SHA256};
use serde_json::{json, Value};
use sha2::{Digest as _, Sha256};

/// A local actor's signing key and the keyId remotes fetch it by.
pub(crate) struct Signer {
    key: RsaKeyPair,
    pub(crate) key_id: String,
}

impl Signer {
    /// PKCS#8 (`BEGIN PRIVATE KEY`, what openssl_pkey_export writes) or
    /// PKCS#1 (`BEGIN RSA PRIVATE KEY`).
    pub(crate) fn from_pem(pem: &str, key_id: &str) -> Result<Self> {
        let (label, der) = pem_der(pem).context("signing key is not PEM")?;
        let key = match label.as_str() {
            "PRIVATE KEY" => RsaKeyPair::from_pkcs8(&der).map_err(|e| anyhow!("signing key: {e}"))?,
            "RSA PRIVATE KEY" => RsaKeyPair::from_der(&der).map_err(|e| anyhow!("signing key: {e}"))?,
            other => bail!("signing key has unsupported PEM label {other}"),
        };
        Ok(Self { key, key_id: key_id.to_string() })
    }

    pub(crate) fn load(path: &str, key_id: &str) -> Result<Self> {
        let pem = std::fs::read_to_string(path).with_context(|| format!("read signing key {path}"))?;
        Self::from_pem(&pem, key_id)
    }

    fn sign(&self, signing: &str) -> Result<String> {
        let mut out = vec![0u8; self.key.public().modulus_len()];
        self.key
            .sign(&RSA_PKCS1_SHA256, &SystemRandom::new(), signing.as_bytes(), &mut out)
            .map_err(|_| anyhow!("signature generation failed"))?;
        Ok(base64::engine::general_purpose::STANDARD.encode(out))
    }

    /// Signature header for a GET, as `ap_signed_get_ex`.
    pub(crate) fn get_signature(&self, host: &str, path: &str, date: &str) -> Result<String> {
        let signature = self.sign(&get_signing_string(host, path, date))?;
        Ok(format!(r#"keyId="{}",algorithm="rsa-sha256",headers="(request-target) host date",signature="{signature}""#, self.key_id))
    }

    /// Signature header for a POST, as `ap_deliver_signed_json`.
    pub(crate) fn post_signature(&self, host: &str, path: &str, date: &str, digest: &str) -> Result<String> {
        let signature = self.sign(&post_signing_string(host, path, date, digest))?;
        Ok(format!(r#"keyId="{}",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="{signature}""#, self.key_id))
    }
}

pub(crate) fn get_signing_string(host: &str, path: &str, date: &str) -> String {
    format!("(request-target): get {path}\nhost: {host}\ndate: {date}")
}

pub(crate) fn post_signing_string(host: &str, path: &str, date: &str, digest: &str) -> String {
    format!("(request-target): post {path}\nhost: {host}\ndate: {date}\ndigest: {digest}")
}

/// `gmdate('D, d M Y H:i:s') . ' GMT'`.
pub(crate) fn http_date(unix: i64) -> String {
    chrono::DateTime::from_timestamp(unix, 0).unwrap_or_default().format("%a, %d %b %Y %H:%M:%S GMT").to_string()
}

/// `'SHA-256=' . base64_encode(hash('sha256', $body, true))`.
pub(crate) fn body_digest(body: &[u8]) -> String {
    format!("SHA-256={}", base64::engine::general_purpose::STANDARD.encode(Sha256::digest(body)))
}

/// Host and request-target the way PHP builds them from parse_url: the path
/// (or "/") plus "?query" when there is one. None unless https.
pub(crate) fn host_and_target(url: &str) -> Option<(String, String)> {
    let parsed = reqwest::Url::parse(url).ok()?;
    if parsed.scheme() != "https" {
        return None;
    }
    parsed.host_str()?;
    let rest = url.split_once("://")?.1;
    // parse_url keeps the host's case; userinfo and port are not part of it.
    let authority = rest.split(['/', '?', '#']).next()?;
    let authority = authority.rsplit_once('@').map(|(_, h)| h).unwrap_or(authority);
    let host = if authority.starts_with('[') {
        authority.split_inclusive(']').next()?.to_string()
    } else {
        authority.split(':').next()?.to_string()
    };
    let after_host = rest.find('/').map(|i| &rest[i..]).unwrap_or("");
    let path = after_host.split(['?', '#']).next().unwrap_or("");
    let mut target = if path.is_empty() { "/".to_string() } else { path.to_string() };
    if let Some(query) = parsed.query().filter(|q| !q.is_empty()) {
        target.push('?');
        target.push_str(query);
    }
    Some((host, target))
}

fn pem_der(pem: &str) -> Option<(String, Vec<u8>)> {
    let begin = pem.find("-----BEGIN ")?;
    let rest = &pem[begin + 11..];
    let label_end = rest.find("-----")?;
    let label = rest[..label_end].to_string();
    let body_start = label_end + 5;
    let end = rest.find("-----END ")?;
    let b64: String = rest[body_start..end].chars().filter(|c| !c.is_whitespace()).collect();
    let der = base64::engine::general_purpose::STANDARD.decode(b64.as_bytes()).ok()?;
    Some((label, der))
}

/// Parity producer "ap-sign": `{key_pem, key_id, method, url, date, body?}`
/// to the Signature header (and Digest for POST) PHP would send.
pub(crate) fn replay(input: &Value) -> Result<Value> {
    let signer = Signer::from_pem(input["key_pem"].as_str().unwrap_or_default(), input["key_id"].as_str().unwrap_or_default())?;
    let url = input["url"].as_str().unwrap_or_default();
    let date = input["date"].as_str().unwrap_or_default();
    let (host, target) = host_and_target(url).context("url is not https")?;
    Ok(match input["method"].as_str() {
        Some("post") => {
            let digest = body_digest(input["body"].as_str().unwrap_or_default().as_bytes());
            json!({"signature": signer.post_signature(&host, &target, date, &digest)?, "digest": digest})
        }
        _ => json!({"signature": signer.get_signature(&host, &target, date)?}),
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn signing_strings_match_php() {
        assert_eq!(
            get_signing_string("a.test", "/users/x?page=1", "Tue, 03 Nov 2026 07:00:00 GMT"),
            "(request-target): get /users/x?page=1\nhost: a.test\ndate: Tue, 03 Nov 2026 07:00:00 GMT"
        );
        assert_eq!(
            post_signing_string("a.test", "/inbox", "Tue, 03 Nov 2026 07:00:00 GMT", "SHA-256=x"),
            "(request-target): post /inbox\nhost: a.test\ndate: Tue, 03 Nov 2026 07:00:00 GMT\ndigest: SHA-256=x"
        );
    }

    #[test]
    fn http_date_is_php_gmdate() {
        assert_eq!(http_date(1_793_689_200), "Tue, 03 Nov 2026 07:00:00 GMT");
        assert_eq!(http_date(0), "Thu, 01 Jan 1970 00:00:00 GMT");
    }

    #[test]
    fn digest_is_base64_sha256() {
        assert_eq!(body_digest(b""), "SHA-256=47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=");
    }

    #[test]
    fn targets_follow_parse_url() {
        assert_eq!(host_and_target("https://A.test:443/users/x#main-key"), Some(("A.test".into(), "/users/x".into())));
        assert_eq!(host_and_target("https://a.test"), Some(("a.test".into(), "/".into())));
        assert_eq!(host_and_target("https://a.test/p?q=1&r=2"), Some(("a.test".into(), "/p?q=1&r=2".into())));
        assert_eq!(host_and_target("http://a.test/p"), None);
    }

    #[test]
    fn rejects_non_key_pem() {
        assert!(Signer::from_pem("-----BEGIN PUBLIC KEY-----\nAAAA\n-----END PUBLIC KEY-----\n", "k").is_err());
        assert!(Signer::from_pem("nope", "k").is_err());
    }
}
