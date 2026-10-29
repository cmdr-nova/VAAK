//! Shadow HTTP signature verification for the AP inbox (Rust cutover F1).
//!
//! PHP still decides every inbox request. When VAAK_INBOX_SIG_SHADOW=1, PHP
//! pushes a copy of each signature check (headers, request values, body, the
//! key it verified with, and its verdict) to `vaak:queue:inbox_sig_shadow`.
//! This module repeats the check the way `ap_inbox_try_verify`
//! (api/ap-inbox.php) does and records whether the two verdicts agree. It
//! never fetches keys, never answers a request, and never writes to the
//! database. Blocked-host and key-fetch refusals stay PHP's, so those
//! verdicts are skipped rather than compared.

use std::collections::HashMap;
use std::time::Duration;

use anyhow::Result;
use base64::Engine as _;
use regex::Regex;
use serde::Deserialize;
use serde_json::{json, Value};
use sha2::{Digest as _, Sha256};

use crate::config::Config;
use crate::redis_util;

/// Same clock-skew margin as PHP's AP_INBOX_DATE_SKEW.
const DATE_SKEW: i64 = 3600;
const QUEUE_NAME: &str = "inbox_sig_shadow";
const STATS_KEY: &str = "vaak:inbox_sig_shadow:stats:v1";
const DISAGREE_KEY: &str = "vaak:inbox_sig_shadow:disagree:v1";
const DISAGREE_CAP: isize = 50;
const KEEP_SECS: i64 = 14 * 86400;

#[derive(Deserialize, Default)]
pub struct Record {
    #[serde(default)]
    pub now: i64,
    #[serde(default)]
    pub headers: HashMap<String, String>,
    #[serde(default)]
    pub server: HashMap<String, String>,
    #[serde(default)]
    pub request_actor: Option<String>,
    #[serde(default)]
    pub body_b64: String,
    #[serde(default)]
    pub pem: Option<String>,
    #[serde(default)]
    pub php: Option<Verdict>,
}

#[derive(Deserialize, Clone, Debug, PartialEq)]
pub struct Verdict {
    pub ok: bool,
    pub path: Option<String>,
    pub code: i64,
    pub error: Option<String>,
}

impl Verdict {
    fn pass(path: String) -> Self {
        Self { ok: true, path: Some(path), code: 200, error: None }
    }
    fn fail(error: &str) -> Self {
        Self { ok: false, path: None, code: 401, error: Some(error.to_string()) }
    }
    pub fn to_json(&self) -> Value {
        json!({"ok": self.ok, "path": self.path, "code": self.code, "error": self.error})
    }
}

#[derive(Debug, PartialEq)]
pub enum Outcome {
    Agree,
    Disagree,
    /// PHP refused before the signature itself was checked (blocked host or
    /// throttled key fetch). Rust has no block list or fetch budget.
    Skipped,
}

pub fn compare(php: &Verdict, rust: &Verdict) -> Outcome {
    if php.code == 403 || php.code == 429 {
        return Outcome::Skipped;
    }
    let same = php.ok == rust.ok
        && if php.ok { php.path == rust.path } else { php.error == rust.error };
    if same { Outcome::Agree } else { Outcome::Disagree }
}

struct Request<'a> {
    rec: &'a Record,
    body: Vec<u8>,
}

impl Request<'_> {
    /// `ap_header`: PHP already resolved headers into one lowercase map.
    fn header(&self, name: &str) -> &str {
        self.rec.headers.get(&name.to_ascii_lowercase()).map(String::as_str).unwrap_or("")
    }
    fn server(&self, key: &str) -> &str {
        self.rec.server.get(key).map(String::as_str).unwrap_or("")
    }
    fn method(&self) -> &str {
        match self.server("REQUEST_METHOD") { "" => "POST", m => m }
    }
}

/// `ap_inbox_try_verify` for one shadow record.
pub fn verify(rec: &Record) -> Verdict {
    let body = base64::engine::general_purpose::STANDARD.decode(rec.body_b64.as_bytes()).unwrap_or_default();
    verify_body(rec, body)
}

/// The same check for a live request, whose body is already in hand.
pub(crate) fn verify_body(rec: &Record, body: Vec<u8>) -> Verdict {
    let req = Request { rec, body };
    let sig_input = req.header("Signature-Input");
    if !sig_input.is_empty() {
        return verify_rfc9421(&req, sig_input);
    }
    let digest = req.header("Digest");
    let date = req.header("Date");
    let sig_hdr = req.header("Signature");
    if digest.is_empty() || date.is_empty() || sig_hdr.is_empty() {
        return Verdict::fail("Missing Digest, Date, or Signature");
    }
    if !valid_date(date, rec.now) {
        return Verdict::fail("Date out of range");
    }
    if !valid_digest(digest, &req.body) {
        return Verdict::fail("Digest mismatch");
    }
    let Some(sig) = parse_signature(sig_hdr) else { return Verdict::fail("Malformed Signature") };
    let algo = match sig.get("algorithm").map(|a| a.to_ascii_lowercase()) {
        Some(a) if !a.is_empty() => a,
        _ => "hs2019".to_string(),
    };
    if algo != "rsa-sha256" && algo != "hs2019" {
        return Verdict::fail("Unsupported signature algorithm");
    }
    if algo == "hs2019" && !hs2019_time_ok(&sig, rec.now) {
        return Verdict::fail("Signature created/expires out of range");
    }
    let Some(key) = rec.pem.as_deref().and_then(PublicKey::from_pem) else {
        return Verdict::fail(if rec.pem.is_some() { "Invalid signature" } else { "Unable to fetch key" });
    };
    let sig_raw = strict_b64(sig.get("signature").map(String::as_str).unwrap_or(""));
    match verify_cavage(&req, &sig, &sig_raw, &key) {
        Some(path) => Verdict::pass(path),
        None => Verdict::fail("Invalid signature"),
    }
}

fn valid_date(date: &str, now: i64) -> bool {
    let ts = chrono::DateTime::parse_from_rfc2822(date)
        .or_else(|_| chrono::DateTime::parse_from_rfc3339(date))
        .map(|d| d.timestamp());
    matches!(ts, Ok(ts) if (now - ts).abs() <= DATE_SKEW)
}

fn body_sha256_b64(body: &[u8]) -> String {
    base64::engine::general_purpose::STANDARD.encode(Sha256::digest(body))
}

/// `ap_valid_digest`: the first SHA-256 part decides.
fn valid_digest(header: &str, body: &[u8]) -> bool {
    static PART: std::sync::LazyLock<Regex> = std::sync::LazyLock::new(|| Regex::new(r"(?i)^SHA-256\s*=\s*(.+)$").unwrap());
    static SPLIT: std::sync::LazyLock<Regex> = std::sync::LazyLock::new(|| Regex::new(r"\s*,\s*").unwrap());
    for part in SPLIT.split(header) {
        if let Some(m) = PART.captures(part) {
            return body_sha256_b64(body) == m[1].trim();
        }
    }
    false
}

fn valid_content_digest(header: &str, body: &[u8]) -> bool {
    static RE: std::sync::LazyLock<Regex> = std::sync::LazyLock::new(|| Regex::new(r"(?i)sha-256=:([A-Za-z0-9+/=]+):").unwrap());
    RE.captures(header).is_some_and(|m| body_sha256_b64(body) == m[1])
}

/// `ap_parse_signature`: keyId, headers and signature are required.
fn parse_signature(header: &str) -> Option<HashMap<&'static str, String>> {
    static RE: std::sync::LazyLock<Regex> =
        std::sync::LazyLock::new(|| Regex::new(r#"([a-zA-Z]+)\s*=\s*(?:"([^"]*)"|([^,\s]*))"#).unwrap());
    let mut header = header.trim();
    if let Some(rest) = header.strip_prefix("Signature ") {
        header = rest.trim();
    }
    let mut out = HashMap::new();
    for m in RE.captures_iter(header) {
        let quoted = m.get(2).map(|v| v.as_str()).unwrap_or("");
        let value = if quoted.is_empty() { m.get(3).map(|v| v.as_str().trim()).unwrap_or("") } else { quoted };
        let key = match m[1].to_ascii_lowercase().as_str() {
            "keyid" => "keyId",
            "algorithm" => "algorithm",
            "headers" => "headers",
            "signature" => "signature",
            "created" => "created",
            "expires" => "expires",
            _ => continue,
        };
        out.insert(key, value.to_string());
    }
    let filled = |k: &str| out.get(k).is_some_and(|v| !v.is_empty() && v != "0");
    (filled("keyId") && filled("headers") && filled("signature")).then_some(out)
}

fn epoch_field(value: Option<&String>) -> Option<i64> {
    let v = value?;
    (!v.is_empty() && v.len() <= 12 && v.bytes().all(|b| b.is_ascii_digit())).then(|| v.parse().ok()).flatten()
}

/// `ap_inbox_hs2019_time_ok`.
fn hs2019_time_ok(sig: &HashMap<&'static str, String>, now: i64) -> bool {
    let headers = sig.get("headers").map(|h| h.to_ascii_lowercase()).unwrap_or_default();
    let created_raw = sig.get("created");
    if headers.contains("(created)") && epoch_field(created_raw).is_none() {
        return false;
    }
    if let Some(raw) = created_raw.filter(|r| !r.is_empty()) {
        // PHP casts with (int): a non-numeric created counts as 0.
        let created = epoch_field(Some(raw)).unwrap_or(0);
        if created > now + DATE_SKEW {
            return false;
        }
        match epoch_field(sig.get("expires")) {
            Some(expires) => {
                if now > expires + DATE_SKEW {
                    return false;
                }
            }
            None => {
                if now > created + DATE_SKEW {
                    return false;
                }
            }
        }
    }
    !(headers.contains("(expires)") && epoch_field(sig.get("expires")).is_none())
}

/// PHP `rawurlencode`.
fn rawurlencode(s: &str) -> String {
    let mut out = String::new();
    for b in s.bytes() {
        if b.is_ascii_alphanumeric() || b"-_.~".contains(&b) {
            out.push(b as char);
        } else {
            out.push_str(&format!("%{b:02X}"));
        }
    }
    out
}

/// `parse_url($v, PHP_URL_PATH)` for the request values PHP reads.
fn url_path(v: &str) -> Option<&str> {
    let rest = if let Some(i) = v.find("://") {
        let after = &v[i + 3..];
        let end = after.find(['/', '?', '#']).unwrap_or(after.len());
        &after[end..]
    } else if let Some(after) = v.strip_prefix("//") {
        let end = after.find(['/', '?', '#']).unwrap_or(after.len());
        &after[end..]
    } else {
        v
    };
    let end = rest.find(['?', '#']).unwrap_or(rest.len());
    let path = &rest[..end];
    (!path.is_empty()).then_some(path)
}

/// `ap_request_target_path_candidates`.
fn path_candidates(req: &Request) -> Vec<String> {
    let mut paths: Vec<String> = Vec::new();
    let mut add = |p: &str| {
        let p = p.trim();
        if p.is_empty() || !p.starts_with('/') {
            return;
        }
        let (p, query) = match p.find('?') {
            Some(i) => (&p[..i], &p[i..]),
            None => (p, ""),
        };
        if p.is_empty() || p.contains(".php") {
            return;
        }
        let mut variants = vec![p.to_string()];
        if p != "/" {
            variants.push(if p.ends_with('/') { p.trim_end_matches('/').to_string() } else { format!("{p}/") });
        }
        for variant in variants {
            if variant.is_empty() {
                continue;
            }
            if !paths.contains(&variant) {
                paths.push(variant.clone());
            }
            if !query.is_empty() {
                let with_query = format!("{variant}{query}");
                if !paths.contains(&with_query) {
                    paths.push(with_query);
                }
            }
        }
    };
    add(req.server("AP_ORIGINAL_PATH"));
    for key in ["HTTP_X_ORIGINAL_URI", "HTTP_X_ORIGINAL_URL", "HTTP_X_FORWARDED_URI", "HTTP_X_REWRITE_URL", "REDIRECT_URI", "REQUEST_URI"] {
        let v = req.server(key);
        if !v.is_empty() {
            add(url_path(v).unwrap_or(v));
        }
    }
    for p in ["/inbox", "/inbox/", "/users/cmdr_nova/inbox", "/users/cmdr_nova/inbox/"] {
        add(p);
    }
    if let Some(key) = req.rec.request_actor.as_deref().filter(|k| !k.is_empty()) {
        let key = rawurlencode(key);
        add(&format!("/users/{key}/inbox"));
        add(&format!("/users/{key}/inbox/"));
    }
    paths
}

/// `ap_inbox_host_candidates`.
fn host_candidates(req: &Request) -> Vec<String> {
    let mut out: Vec<String> = Vec::new();
    let mut add = |raw: &str| {
        let raw = raw.split(',').next().unwrap_or("").trim();
        if raw.is_empty() || raw.contains(' ') || raw.contains('/') {
            return;
        }
        let mut forms = vec![raw.to_string()];
        if !raw.starts_with('[') {
            let mut stripped = raw;
            if let Some(colon) = raw.rfind(':') {
                let port = &raw[colon + 1..];
                if port == "443" || port == "80" {
                    stripped = &raw[..colon];
                }
            }
            forms.push(stripped.to_string());
            forms.push(raw.to_lowercase());
            forms.push(stripped.to_lowercase());
        }
        for form in forms {
            if !form.is_empty() && !out.contains(&form) {
                out.push(form);
            }
        }
    };
    add(req.header("Host"));
    add(req.server("HTTP_HOST"));
    add(req.server("HTTP_X_FORWARDED_HOST"));
    add(req.server("SERVER_NAME"));
    if out.is_empty() {
        out.push("mkultra.monster".to_string());
    }
    out
}

/// `ap_signing_string_for_path`.
fn signing_string(
    req: &Request,
    sig: &HashMap<&'static str, String>,
    path: &str,
    host: &str,
    content_type: Option<&str>,
) -> Option<String> {
    let method = req.method().to_lowercase();
    let headers = sig.get("headers").map(String::as_str).unwrap_or("");
    let mut parts = Vec::new();
    for h in headers.split_whitespace() {
        let h = h.to_lowercase();
        if h == "(request-target)" {
            parts.push(format!("(request-target): {method} {path}"));
            continue;
        }
        let val = match h.as_str() {
            "(created)" => sig.get("created").cloned().unwrap_or_default(),
            "(expires)" => sig.get("expires").cloned().unwrap_or_default(),
            "content-type" if content_type.is_some() => content_type.unwrap_or("").to_string(),
            "host" => host.to_string(),
            _ => req.header(&h).to_string(),
        };
        if val.is_empty() {
            return None;
        }
        parts.push(format!("{h}: {val}"));
    }
    Some(parts.join("\n"))
}

/// `ap_verify_http_signature`: every path, every host, and the charset-free
/// Content-Type a proxy may have added to.
fn verify_cavage(req: &Request, sig: &HashMap<&'static str, String>, sig_raw: &[u8], key: &PublicKey) -> Option<String> {
    static CHARSET: std::sync::LazyLock<Regex> = std::sync::LazyLock::new(|| Regex::new(r"(?i)\s*;\s*charset\s*=\s*[^;]+").unwrap());
    if sig_raw.is_empty() {
        return None;
    }
    let hosts = host_candidates(req);
    let content_type = req.header("Content-Type");
    let stripped = CHARSET.replace_all(content_type, "").trim().to_string();
    let signs_type = sig.get("headers").is_some_and(|h| h.contains("content-type"));
    for path in path_candidates(req) {
        for host in &hosts {
            if signing_string(req, sig, &path, host, None).is_some_and(|s| key.verify(s.as_bytes(), sig_raw)) {
                return Some(path);
            }
            if !stripped.is_empty() && stripped != content_type && signs_type
                && signing_string(req, sig, &path, host, Some(&stripped)).is_some_and(|s| key.verify(s.as_bytes(), sig_raw))
            {
                return Some(path);
            }
        }
    }
    None
}

struct SignatureInput {
    label: String,
    components: Vec<String>,
    params: HashMap<String, String>,
    params_raw: String,
}

/// `ap_inbox_parse_signature_input`.
fn parse_signature_input(header: &str) -> Option<SignatureInput> {
    static LABEL: std::sync::LazyLock<Regex> = std::sync::LazyLock::new(|| Regex::new(r"^[A-Za-z0-9_-]+$").unwrap());
    static LIST: std::sync::LazyLock<Regex> = std::sync::LazyLock::new(|| Regex::new(r"^\(([^)]*)\)").unwrap());
    static QUOTED: std::sync::LazyLock<Regex> = std::sync::LazyLock::new(|| Regex::new(r#""([^"]+)""#).unwrap());
    static PARAM: std::sync::LazyLock<Regex> =
        std::sync::LazyLock::new(|| Regex::new(r#";\s*([A-Za-z0-9_-]+)=("([^"]*)"|([^\s,;]+))"#).unwrap());
    let header = header.trim();
    let eq = header.find('=')?;
    if eq < 1 {
        return None;
    }
    let label = &header[..eq];
    if !LABEL.is_match(label) {
        return None;
    }
    let params_raw = &header[eq + 1..];
    let list = LIST.captures(params_raw)?;
    let components: Vec<String> = QUOTED.captures_iter(&list[1]).map(|m| m[1].to_string()).collect();
    if components.is_empty() {
        return None;
    }
    let mut params = HashMap::new();
    for p in PARAM.captures_iter(params_raw) {
        let quoted = p.get(3).map(|v| v.as_str()).unwrap_or("");
        let value = if quoted.is_empty() { p.get(4).map(|v| v.as_str()).unwrap_or("") } else { quoted };
        params.insert(p[1].to_ascii_lowercase(), value.to_string());
    }
    Some(SignatureInput { label: label.to_string(), components, params, params_raw: params_raw.to_string() })
}

/// `ap_inbox_try_verify_rfc9421`.
fn verify_rfc9421(req: &Request, sig_input: &str) -> Verdict {
    let sig_hdr = req.header("Signature");
    let Some(parsed) = parse_signature_input(sig_input).filter(|_| !sig_hdr.is_empty()) else {
        return Verdict::fail("Malformed Signature");
    };
    let alg = parsed.params.get("alg").map(|a| a.to_ascii_lowercase()).unwrap_or_else(|| "rsa-v1_5-sha256".to_string());
    if !["rsa-v1_5-sha256", "rsa-sha256", "hs2019"].contains(&alg.as_str()) {
        return Verdict::fail("Unsupported signature algorithm");
    }
    let now = req.rec.now;
    let Some(created) = epoch_field(parsed.params.get("created")) else {
        return Verdict::fail("Signature created/expires out of range");
    };
    if created > now + DATE_SKEW {
        return Verdict::fail("Signature created/expires out of range");
    }
    let expired = match epoch_field(parsed.params.get("expires")) {
        Some(expires) => now > expires + DATE_SKEW,
        None => now > created + DATE_SKEW,
    };
    if expired {
        return Verdict::fail("Signature created/expires out of range");
    }
    let date = req.header("Date");
    if !date.is_empty() && !valid_date(date, now) && (now - created).abs() > DATE_SKEW {
        return Verdict::fail("Date out of range");
    }
    let content_digest = req.header("Content-Digest");
    if parsed.components.iter().any(|c| c == "content-digest" || c == "digest") {
        let legacy = req.header("Digest");
        let ok = (!content_digest.is_empty() && valid_content_digest(content_digest, &req.body))
            || (!legacy.is_empty() && valid_digest(legacy, &req.body));
        if !ok {
            return Verdict::fail("Digest mismatch");
        }
    }
    let key_id = parsed.params.get("keyid").map(String::as_str).unwrap_or("");
    if key_id.is_empty() || !key_id.starts_with("https://") {
        return Verdict::fail("Malformed Signature");
    }
    let Ok(label_re) = Regex::new(&format!(r"{}\s*=\s*:([A-Za-z0-9+/=]+):", regex::escape(&parsed.label))) else {
        return Verdict::fail("Malformed Signature");
    };
    let Some(sig_b64) = label_re.captures(sig_hdr).map(|m| m[1].to_string()) else {
        return Verdict::fail("Malformed Signature");
    };
    let Some(key) = req.rec.pem.as_deref().and_then(PublicKey::from_pem) else {
        return Verdict::fail(if req.rec.pem.is_some() { "Invalid signature" } else { "Unable to fetch key" });
    };
    match verify_rfc9421_base(req, &parsed, &strict_b64(&sig_b64), &key, content_digest) {
        Some(path) => Verdict::pass(path),
        None => Verdict::fail("Invalid signature"),
    }
}

/// `ap_inbox_verify_rfc9421_base`: hosts outer, paths inner, as in PHP.
fn verify_rfc9421_base(req: &Request, parsed: &SignatureInput, sig_raw: &[u8], key: &PublicKey, content_digest: &str) -> Option<String> {
    if sig_raw.is_empty() {
        return None;
    }
    let method = req.method().to_uppercase();
    let paths = path_candidates(req);
    for host in host_candidates(req) {
        'path: for path in &paths {
            let mut lines = Vec::new();
            for component in &parsed.components {
                let name = component.to_lowercase();
                let line = match name.as_str() {
                    "@method" => format!("\"@method\": {method}"),
                    "@target-uri" => format!("\"@target-uri\": https://{host}{path}"),
                    "@authority" => format!("\"@authority\": {host}"),
                    "@path" | "@request-target" => format!("\"{name}\": {path}"),
                    "content-digest" => {
                        if content_digest.is_empty() {
                            continue 'path;
                        }
                        format!("\"content-digest\": {content_digest}")
                    }
                    _ => {
                        let val = if name == "host" { host.as_str() } else { req.header(&name) };
                        if val.is_empty() {
                            continue 'path;
                        }
                        format!("\"{name}\": {val}")
                    }
                };
                lines.push(line);
            }
            lines.push(format!("\"@signature-params\": {}", parsed.params_raw));
            if key.verify(lines.join("\n").as_bytes(), sig_raw) {
                return Some(path.clone());
            }
        }
    }
    None
}

/// PHP `base64_decode($s, true) ?: ''`.
fn strict_b64(s: &str) -> Vec<u8> {
    let engine = base64::engine::GeneralPurpose::new(
        &base64::alphabet::STANDARD,
        base64::engine::GeneralPurposeConfig::new()
            .with_decode_padding_mode(base64::engine::DecodePaddingMode::Indifferent)
            .with_decode_allow_trailing_bits(true),
    );
    engine.decode(s.trim().as_bytes()).unwrap_or_default()
}

/// An RSA public key from the PEM PHP verified with: SubjectPublicKeyInfo
/// ("PUBLIC KEY") or PKCS#1 ("RSA PUBLIC KEY"). Other key types never pass
/// PHP's `openssl_verify(..., OPENSSL_ALGO_SHA256)` for these signatures.
struct PublicKey {
    rsa_der: Vec<u8>,
}

impl PublicKey {
    fn from_pem(pem: &str) -> Option<Self> {
        let begin = pem.find("-----BEGIN ")?;
        let rest = &pem[begin + 11..];
        let label_end = rest.find("-----")?;
        let label = &rest[..label_end];
        let body_start = label_end + 5;
        let end = rest[body_start..].find("-----END ")? + body_start;
        let b64: String = rest[body_start..end].chars().filter(|c| !c.is_whitespace()).collect();
        let der = base64::engine::general_purpose::STANDARD.decode(b64).ok()?;
        let rsa_der = match label {
            "RSA PUBLIC KEY" => der,
            "PUBLIC KEY" => spki_rsa_key(&der)?,
            _ => return None,
        };
        Some(Self { rsa_der })
    }

    /// RSASSA-PKCS1-v1_5 with SHA-256. Legacy 1024-bit keys are accepted,
    /// because PHP's OpenSSL accepts them.
    fn verify(&self, message: &[u8], signature: &[u8]) -> bool {
        ring::signature::UnparsedPublicKey::new(&ring::signature::RSA_PKCS1_1024_8192_SHA256_FOR_LEGACY_USE_ONLY, &self.rsa_der)
            .verify(message, signature)
            .is_ok()
    }
}

/// One DER element: (tag, content, rest).
fn der_element(input: &[u8]) -> Option<(u8, &[u8], &[u8])> {
    let (&tag, rest) = input.split_first()?;
    let (&first, rest) = rest.split_first()?;
    let (len, rest) = if first < 0x80 {
        (first as usize, rest)
    } else {
        let count = (first & 0x7f) as usize;
        if count == 0 || count > 4 || rest.len() < count {
            return None;
        }
        let len = rest[..count].iter().fold(0usize, |acc, b| (acc << 8) | *b as usize);
        (len, &rest[count..])
    };
    (rest.len() >= len).then(|| (tag, &rest[..len], &rest[len..]))
}

/// The RSAPublicKey inside a SubjectPublicKeyInfo with the rsaEncryption OID.
fn spki_rsa_key(der: &[u8]) -> Option<Vec<u8>> {
    const RSA_OID: &[u8] = &[0x2a, 0x86, 0x48, 0x86, 0xf7, 0x0d, 0x01, 0x01, 0x01];
    let (0x30, spki, _) = der_element(der)? else { return None };
    let (0x30, algorithm, rest) = der_element(spki)? else { return None };
    let (0x06, oid, _) = der_element(algorithm)? else { return None };
    if oid != RSA_OID {
        return None;
    }
    let (0x03, bits, _) = der_element(rest)? else { return None };
    let (&0, key) = bits.split_first()? else { return None };
    Some(key.to_vec())
}

/// Parity harness: one record in, Rust's verdict out.
pub fn replay(input: &Value) -> Result<Value> {
    let rec: Record = serde_json::from_value(input.clone())?;
    Ok(verify(&rec).to_json())
}

/// The keyId a request claims, from Signature-Input (RFC 9421) or the
/// draft-cavage Signature header. Only a claim until it verifies.
pub(crate) fn claimed_key_id(headers: &HashMap<String, String>) -> Option<String> {
    match headers.get("signature-input").filter(|v| !v.is_empty()) {
        Some(input) => parse_signature_input(input)?.params.get("keyid").cloned(),
        None => parse_signature(headers.get("signature")?)?.remove("keyId"),
    }
    .filter(|k| !k.is_empty())
}

fn summary(rec: &Record, php: &Verdict, rust: &Verdict) -> Value {
    let sig_input = rec.headers.get("signature-input").map(String::as_str).unwrap_or("");
    let (key_id, algorithm, headers) = if sig_input.is_empty() {
        let sig = parse_signature(rec.headers.get("signature").map(String::as_str).unwrap_or("")).unwrap_or_default();
        (sig.get("keyId").cloned(), sig.get("algorithm").cloned(), sig.get("headers").cloned())
    } else {
        let parsed = parse_signature_input(sig_input);
        (
            parsed.as_ref().and_then(|p| p.params.get("keyid").cloned()),
            parsed.as_ref().and_then(|p| p.params.get("alg").cloned()),
            parsed.as_ref().map(|p| p.components.join(" ")),
        )
    };
    let key_host = key_id.as_deref().and_then(|k| k.split("://").nth(1)).map(|r| r.split(['/', '#', '?']).next().unwrap_or("").to_string());
    json!({
        "at": rec.now,
        "key_host": key_host,
        "rfc9421": !sig_input.is_empty(),
        "algorithm": algorithm,
        "headers": headers,
        "php": php.to_json(),
        "rust": rust.to_json(),
    })
}

/// Drain the shadow queue until the process stops. Spawned by `serve` when
/// VAAK_INBOX_SIG_SHADOW=1.
pub async fn run(cfg: std::sync::Arc<Config>) {
    loop {
        if let Err(e) = drain(&cfg).await {
            tracing::error!(error = %e, "inbox signature shadow stopped; retrying");
            tokio::time::sleep(Duration::from_secs(5)).await;
        }
    }
}

async fn drain(cfg: &Config) -> Result<()> {
    // BRPOP blocks its connection, so the queue reader gets its own.
    let mut queue = redis_util::connect(&cfg.redis_queue_url).await?;
    let mut cache = redis_util::connect(&cfg.redis_url).await?;
    tracing::info!("inbox signature shadow listening");
    loop {
        let Some(item) = redis_util::queue_brpop(&mut queue, QUEUE_NAME, 5.0).await? else { continue };
        let field = match serde_json::from_str::<Record>(&item) {
            Ok(rec) => match rec.php.clone() {
                Some(php) => {
                    let rust = verify(&rec);
                    match compare(&php, &rust) {
                        Outcome::Agree => "agree",
                        Outcome::Skipped => "skipped",
                        Outcome::Disagree => {
                            let row = summary(&rec, &php, &rust);
                            tracing::warn!(detail = %row, "inbox signature shadow disagrees with PHP");
                            let _: Result<(), _> = redis::pipe()
                                .lpush(DISAGREE_KEY, row.to_string())
                                .ltrim(DISAGREE_KEY, 0, DISAGREE_CAP - 1)
                                .expire(DISAGREE_KEY, KEEP_SECS)
                                .query_async(&mut cache)
                                .await;
                            "disagree"
                        }
                    }
                }
                None => "bad_record",
            },
            Err(_) => "bad_record",
        };
        let _: Result<(), _> = redis::pipe()
            .hincr(STATS_KEY, field, 1)
            .expire(STATS_KEY, KEEP_SECS)
            .query_async(&mut cache)
            .await;
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn record(headers: &[(&str, &str)], server: &[(&str, &str)]) -> Record {
        Record {
            now: 1_800_000_000,
            headers: headers.iter().map(|(k, v)| (k.to_string(), v.to_string())).collect(),
            server: server.iter().map(|(k, v)| (k.to_string(), v.to_string())).collect(),
            ..Default::default()
        }
    }

    #[test]
    fn candidates_follow_php_order() {
        let rec = Record {
            request_actor: Some("juno_flux".into()),
            ..record(&[("host", "mkultra.monster:443")], &[("REQUEST_URI", "/users/juno_flux/inbox?x=1"), ("HTTP_X_FORWARDED_HOST", "MKULTRA.monster")])
        };
        let req = Request { rec: &rec, body: Vec::new() };
        assert_eq!(
            path_candidates(&req),
            ["/users/juno_flux/inbox", "/users/juno_flux/inbox/", "/inbox", "/inbox/", "/users/cmdr_nova/inbox", "/users/cmdr_nova/inbox/"]
        );
        assert_eq!(host_candidates(&req), ["mkultra.monster:443", "mkultra.monster", "MKULTRA.monster"]);
    }

    #[test]
    fn signature_header_parses_like_php() {
        let sig = parse_signature(r#"Signature keyId="https://a.test/u#k", algorithm = "hs2019",headers="(request-target) host",signature="c2ln",created=123"#).unwrap();
        assert_eq!(sig["keyId"], "https://a.test/u#k");
        assert_eq!(sig["algorithm"], "hs2019");
        assert_eq!(sig["created"], "123");
        assert!(parse_signature(r#"keyId="k",signature="s""#).is_none());
    }

    #[test]
    fn early_failures_match_php_messages() {
        let missing = record(&[("date", "x")], &[]);
        assert_eq!(verify(&missing).error.as_deref(), Some("Missing Digest, Date, or Signature"));
        let old = record(&[("date", "Tue, 01 Jan 2019 00:00:00 GMT"), ("digest", "SHA-256=x"), ("signature", "keyId=\"k\"")], &[]);
        assert_eq!(verify(&old).error.as_deref(), Some("Date out of range"));
    }

    #[test]
    fn blocked_and_throttled_php_verdicts_are_skipped() {
        let rust = Verdict::fail("Unable to fetch key");
        let php = Verdict { ok: false, path: None, code: 403, error: Some("Instance blocked".into()) };
        assert_eq!(compare(&php, &rust), Outcome::Skipped);
        assert_eq!(compare(&Verdict::fail("Unable to fetch key"), &rust), Outcome::Agree);
        assert_eq!(compare(&Verdict::pass("/inbox".into()), &rust), Outcome::Disagree);
    }

    /// Records written by parity/php/inbox-sig-verify.php: real PHP verdicts
    /// for signatures made with keys generated in that run.
    #[test]
    fn php_fixture_verdicts_match() {
        let raw = include_str!("../fixtures/inbox_sig_shadow_cases.json");
        let file: Value = serde_json::from_str(raw).unwrap();
        let cases = file["cases"].as_array().unwrap();
        assert!(cases.len() >= 15);
        for case in cases {
            let rec: Record = serde_json::from_value(case["input"].clone()).unwrap();
            let php: Verdict = serde_json::from_value(case["output"].clone()).unwrap();
            assert_eq!(compare(&php, &verify(&rec)), Outcome::Agree, "case {}", case["id"]);
        }
    }
}
