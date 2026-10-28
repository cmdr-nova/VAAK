//! Read-only Nostr follows in Home (opt-in per user; see api/ap-nostr.php).
//!
//! VAAK stores only public keys. Nothing here signs or publishes an event.

pub mod bech32;
pub mod event;
pub mod home;
pub mod relay;
pub mod render;
pub mod sync;
