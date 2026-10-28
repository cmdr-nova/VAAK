//! Nostr follows in Home (opt-in per user; see api/ap-nostr.php).
//!
//! VAAK stores only public keys. Likes, replies and follows are signed in the
//! browser by the user's signer extension; `act` checks and relays them.

pub mod act;
pub mod bech32;
pub mod event;
pub mod home;
pub mod relay;
pub mod render;
pub mod sync;
