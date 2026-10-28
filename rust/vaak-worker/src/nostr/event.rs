//! NIP-01 events: id and BIP-340 signature checks, plus the few kinds the
//! sync reads (0 profile, 1 note, 3 follow list, 10002 relay list).

use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use sha2::{Digest, Sha256};

/// Largest note body kept (bytes). strfry's default event limit is 64 KiB.
pub const MAX_CONTENT_BYTES: usize = 64 * 1024;
const MAX_TAGS: usize = 2000;
/// Follow lists past this many keys are cut (newest clients cap far lower).
pub const MAX_FOLLOWS: usize = 5000;
/// Write relays kept per author (NIP-65 asks for 2 to 4).
pub const MAX_WRITE_RELAYS: usize = 4;

#[derive(Debug, Clone, Deserialize, Serialize, PartialEq, Eq)]
pub struct Event {
    pub id: String,
    pub pubkey: String,
    pub created_at: i64,
    pub kind: i64,
    pub tags: Vec<Vec<String>>,
    pub content: String,
    pub sig: String,
}

pub fn is_hex64(s: &str) -> bool {
    s.len() == 64 && s.bytes().all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
}

impl Event {
    /// sha256 of `[0, pubkey, created_at, kind, tags, content]` as compact
    /// JSON. serde_json escapes the way NIP-01 asks (\n \" \\ \r \t \b \f,
    /// other control bytes as \u00xx, everything else raw UTF-8).
    pub fn computed_id(&self) -> String {
        let body = json!([0, self.pubkey, self.created_at, self.kind, self.tags, self.content]);
        let bytes = serde_json::to_vec(&body).unwrap_or_default();
        hex::encode(Sha256::digest(&bytes))
    }

    /// Shape, size, id and signature. Anything that fails is dropped.
    pub fn verify(&self) -> Result<(), &'static str> {
        if !is_hex64(&self.id) || !is_hex64(&self.pubkey) {
            return Err("bad id or pubkey");
        }
        if self.sig.len() != 128 {
            return Err("bad sig length");
        }
        if self.content.len() > MAX_CONTENT_BYTES || self.tags.len() > MAX_TAGS {
            return Err("too large");
        }
        if self.computed_id() != self.id {
            return Err("id mismatch");
        }
        let pk = hex::decode(&self.pubkey).map_err(|_| "bad pubkey hex")?;
        let sig = hex::decode(&self.sig).map_err(|_| "bad sig hex")?;
        let id = hex::decode(&self.id).map_err(|_| "bad id hex")?;
        let key = k256::schnorr::VerifyingKey::from_slice(&pk).map_err(|_| "pubkey not on curve")?;
        let sig = k256::schnorr::Signature::try_from(sig.as_slice()).map_err(|_| "bad signature")?;
        key.verify_raw(&id, &sig).map_err(|_| "signature does not verify")
    }

    fn tag_values<'a>(&'a self, name: &'a str) -> impl Iterator<Item = &'a Vec<String>> + 'a {
        self.tags.iter().filter(move |t| t.first().map(String::as_str) == Some(name))
    }

    /// NIP-02: followed keys from `p` tags, deduplicated, in list order.
    pub fn follow_list(&self) -> Vec<String> {
        let mut seen = std::collections::HashSet::new();
        let mut out = Vec::new();
        for tag in self.tag_values("p") {
            let Some(pk) = tag.get(1).map(|s| s.trim().to_ascii_lowercase()) else {
                continue;
            };
            if is_hex64(&pk) && seen.insert(pk.clone()) {
                out.push(pk);
                if out.len() >= MAX_FOLLOWS {
                    break;
                }
            }
        }
        out
    }

    /// NIP-65: relays the author writes to (no marker or "write").
    pub fn write_relays(&self) -> Vec<String> {
        let mut out: Vec<String> = Vec::new();
        for tag in self.tag_values("r") {
            let marker = tag.get(2).map(String::as_str).unwrap_or("");
            if !(marker.is_empty() || marker == "write") {
                continue;
            }
            if let Some(url) = tag.get(1).and_then(|u| super::relay::normalize_relay_url(u)) {
                if !out.contains(&url) {
                    out.push(url);
                }
            }
            if out.len() >= MAX_WRITE_RELAYS {
                break;
            }
        }
        out
    }

    /// NIP-10: a kind 1 is a reply when it carries an `e` tag that is not a
    /// plain mention. Returns `(is_reply, parent id, p-tagged keys)`.
    pub fn reply_info(&self) -> (bool, String, Vec<String>) {
        let mut parent = String::new();
        let mut root = String::new();
        let mut positional = Vec::new();
        for tag in self.tag_values("e") {
            let Some(id) = tag.get(1).map(|s| s.trim().to_ascii_lowercase()) else {
                continue;
            };
            if !is_hex64(&id) {
                continue;
            }
            match tag.get(3).map(String::as_str).unwrap_or("") {
                "reply" => parent = id,
                "root" => root = id,
                "mention" => {}
                _ => positional.push(id),
            }
        }
        if parent.is_empty() {
            parent = if !root.is_empty() && positional.is_empty() {
                root
            } else {
                positional.last().cloned().unwrap_or_default()
            };
        }
        if parent.is_empty() {
            return (false, String::new(), Vec::new());
        }
        let mut pks: Vec<String> = Vec::new();
        for tag in self.tag_values("p") {
            if let Some(pk) = tag.get(1).map(|s| s.trim().to_ascii_lowercase()) {
                if is_hex64(&pk) && !pks.contains(&pk) {
                    pks.push(pk);
                }
            }
        }
        (true, parent, pks)
    }

    /// NIP-18: the note a kind 6 repost points at (its first `e` tag) and
    /// that tag's relay hint, if any.
    pub fn repost_target(&self) -> Option<(String, String)> {
        let tag = self.tag_values("e").next()?;
        let id = tag.get(1)?.trim().to_ascii_lowercase();
        if !is_hex64(&id) {
            return None;
        }
        Some((id, tag.get(2).cloned().unwrap_or_default()))
    }

    /// NIP-25: the note a kind 7 reaction is about (its last `e` tag).
    pub fn reaction_target(&self) -> Option<String> {
        let id = self.tag_values("e").last()?.get(1)?.trim().to_ascii_lowercase();
        is_hex64(&id).then_some(id)
    }

    /// The reposted kind 1 a kind 6 carries in its content, when it is the
    /// note the `e` tag names and its signature checks out.
    pub fn embedded_repost(&self) -> Option<Event> {
        let (target, _) = self.repost_target()?;
        let inner: Event = serde_json::from_str(self.content.trim()).ok()?;
        (inner.kind == 1 && inner.id == target && inner.verify().is_ok()).then_some(inner)
    }
}

/// Kind 0 fields VAAK paints. Only https pictures are kept.
#[derive(Debug, Default, Clone, PartialEq, Eq)]
pub struct Profile {
    pub name: String,
    pub display_name: String,
    pub picture: String,
    pub nip05: String,
}

fn clip(s: &str, max: usize) -> String {
    s.trim().chars().filter(|c| !c.is_control()).take(max).collect()
}

pub fn parse_profile(content: &str) -> Profile {
    let Ok(v) = serde_json::from_str::<Value>(content) else {
        return Profile::default();
    };
    let get = |k: &str| v.get(k).and_then(Value::as_str).unwrap_or("");
    let display = if get("display_name").trim().is_empty() { get("displayName") } else { get("display_name") };
    let picture = get("picture").trim();
    Profile {
        name: clip(get("name"), 80),
        display_name: clip(display, 80),
        picture: if picture.starts_with("https://") && picture.len() <= 500 && !picture.contains(char::is_whitespace) {
            picture.to_string()
        } else {
            String::new()
        },
        nip05: clip(get("nip05"), 120),
    }
}

#[cfg(test)]
pub(crate) mod tests {
    use super::*;
    use k256::schnorr::SigningKey;

    /// Build and sign an event with a test key (BIP-340, zero aux rand).
    pub(crate) fn signed(seed: u8, created_at: i64, kind: i64, tags: Vec<Vec<String>>, content: &str) -> Event {
        let sk = SigningKey::from_slice(&[seed; 32]).expect("test key");
        let mut ev = Event {
            id: String::new(),
            pubkey: hex::encode(sk.verifying_key().to_bytes()),
            created_at,
            kind,
            tags,
            content: content.to_string(),
            sig: String::new(),
        };
        ev.id = ev.computed_id();
        let id = hex::decode(&ev.id).unwrap();
        let sig = sk.sign_raw(&id, &[0u8; 32]).expect("sign");
        ev.sig = hex::encode(sig.to_bytes());
        ev
    }

    fn tags(list: &[&[&str]]) -> Vec<Vec<String>> {
        list.iter().map(|t| t.iter().map(|s| s.to_string()).collect()).collect()
    }

    #[test]
    fn real_relay_event_verifies() {
        // The example mined note from NIP-13: signed by a real client, not by
        // this code, so it checks the id serialization and BIP-340 together.
        let raw = include_str!("fixtures/real-note.json");
        let ev: Event = serde_json::from_str(raw).unwrap();
        assert_eq!(ev.verify(), Ok(()));
        let mut tampered = ev.clone();
        tampered.content.push('!');
        assert_eq!(tampered.verify(), Err("id mismatch"));
    }

    #[test]
    fn signed_events_verify_and_tampering_fails() {
        let ev = signed(7, 1_800_000_000, 1, tags(&[&["t", "nostr"]]), "hi \"there\"\n\u{1}✨");
        assert_eq!(ev.verify(), Ok(()));
        let mut bad_sig = ev.clone();
        bad_sig.sig.replace_range(0..2, if &ev.sig[0..2] == "00" { "01" } else { "00" });
        assert!(bad_sig.verify().is_err());
        let mut other_key = ev.clone();
        other_key.pubkey = signed(8, 1, 1, vec![], "").pubkey;
        assert!(other_key.verify().is_err());
        let mut big = signed(7, 1, 1, vec![], &"x".repeat(MAX_CONTENT_BYTES + 1));
        big.id = big.computed_id();
        assert_eq!(big.verify(), Err("too large"));
    }

    #[test]
    fn follow_and_relay_lists() {
        let a = "a".repeat(64);
        let b = "b".repeat(64);
        let ev = signed(
            1,
            1,
            3,
            tags(&[&["p", &a], &["p", &a.to_uppercase()], &["p", "short"], &["p", &b, "wss://x.example"], &["e", &b]]),
            "",
        );
        assert_eq!(ev.follow_list(), vec![a.clone(), b.clone()]);
        let rl = signed(
            1,
            1,
            10002,
            tags(&[
                &["r", "wss://Relay.Example.com/"],
                &["r", "wss://read.example", "read"],
                &["r", "wss://write.example", "write"],
                &["r", "ws://plain.example"],
                &["r", "wss://relay.example.com"],
            ]),
            "",
        );
        assert_eq!(rl.write_relays(), vec!["wss://relay.example.com", "wss://write.example"]);
    }

    #[test]
    fn nip10_reply_detection() {
        let p1 = "1".repeat(64);
        let e1 = "e".repeat(64);
        let e2 = "f".repeat(64);
        let top = signed(1, 1, 1, tags(&[&["p", &p1]]), "hello");
        assert!(!top.reply_info().0);
        let mention = signed(1, 1, 1, tags(&[&["e", &e1, "", "mention"]]), "see");
        assert!(!mention.reply_info().0);
        let marked = signed(1, 1, 1, tags(&[&["e", &e1, "", "root"], &["e", &e2, "", "reply"], &["p", &p1]]), "re");
        assert_eq!(marked.reply_info(), (true, e2.clone(), vec![p1.clone()]));
        let root_only = signed(1, 1, 1, tags(&[&["e", &e1, "", "root"]]), "re");
        assert_eq!(root_only.reply_info(), (true, e1.clone(), vec![]));
        let positional = signed(1, 1, 1, tags(&[&["e", &e1], &["e", &e2]]), "re");
        assert_eq!(positional.reply_info().1, e2);
        let reaction = signed(1, 1, 7, tags(&[&["e", &e1], &["e", &e2], &["p", &p1]]), "+");
        assert_eq!(reaction.reaction_target(), Some(e2.clone()));
        assert_eq!(signed(1, 1, 7, tags(&[&["p", &p1]]), "+").reaction_target(), None);
    }

    #[test]
    fn profile_fields_are_clipped_and_safe() {
        let p = parse_profile(r#"{"name":"nova","displayName":"Nova ✨","picture":"javascript:alert(1)","nip05":"_@mkultra.monster"}"#);
        assert_eq!(p.display_name, "Nova ✨");
        assert_eq!(p.picture, "");
        assert_eq!(p.nip05, "_@mkultra.monster");
        assert_eq!(parse_profile("not json"), Profile::default());
        let p = parse_profile(r#"{"display_name":"A","picture":"https://img.example/a.png"}"#);
        assert_eq!(p.picture, "https://img.example/a.png");
    }
}
