//! Deserialize PHP normalize expected.json fixtures into Rust freeze types.

use std::fs;
use std::path::PathBuf;
use vaak_types::NormalizedStatusProjection;

fn fixtures_root() -> PathBuf {
    // rust/vaak-types/tests → repo root api/fixtures/normalize
    PathBuf::from(env!("CARGO_MANIFEST_DIR"))
        .join("../../api/fixtures/normalize")
        .canonicalize()
        .expect("api/fixtures/normalize must exist relative to vaak-types")
}

#[test]
fn all_expected_fixtures_deserialize() {
    let root = fixtures_root();
    let mut cases = Vec::new();
    for entry in fs::read_dir(&root).expect("read fixtures dir") {
        let entry = entry.expect("dirent");
        if !entry.file_type().expect("ft").is_dir() {
            continue;
        }
        let expected = entry.path().join("expected.json");
        if expected.is_file() {
            cases.push((entry.file_name().to_string_lossy().to_string(), expected));
        }
    }
    cases.sort_by(|a, b| a.0.cmp(&b.0));
    assert!(
        !cases.is_empty(),
        "expected at least one normalize fixture under {}",
        root.display()
    );

    for (name, path) in &cases {
        let raw = fs::read_to_string(path).unwrap_or_else(|e| panic!("read {name}: {e}"));
        let proj: NormalizedStatusProjection = serde_json::from_str(&raw)
            .unwrap_or_else(|e| panic!("deserialize {name}: {e}\n{raw}"));
        assert!(
            !proj.uri.is_empty(),
            "{name}: uri must be non-empty"
        );
        // Round-trip keeps the freeze contract stable.
        let again: NormalizedStatusProjection =
            serde_json::from_value(serde_json::to_value(&proj).unwrap()).unwrap();
        assert_eq!(proj.uri, again.uri, "{name} round-trip uri");
        assert_eq!(proj.has_visible_body, again.has_visible_body, "{name}");
    }
}

#[test]
fn mastodon_note_has_media() {
    let path = fixtures_root().join("mastodon_note/expected.json");
    let proj: NormalizedStatusProjection =
        serde_json::from_str(&fs::read_to_string(path).unwrap()).unwrap();
    assert!(proj.has_media());
    assert!(proj.has_visible_body);
    assert!(!proj.sensitive);
}

#[test]
fn bsky_skeet_sensitive_with_media() {
    let path = fixtures_root().join("bsky_skeet/expected.json");
    let proj: NormalizedStatusProjection =
        serde_json::from_str(&fs::read_to_string(path).unwrap()).unwrap();
    assert!(proj.is_bluesky());
    assert!(proj.sensitive);
    assert!(proj.has_media());
}
