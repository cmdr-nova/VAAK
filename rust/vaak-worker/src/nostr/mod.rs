//! Nostr follows in Home (opt-in per user; see api/ap-nostr.php).
//!
//! VAAK stores only public keys. Likes, replies, follows, reposts and quotes
//! are signed in the browser by the user's signer extension; `act` checks
//! and relays them. `share` shows boosted and quoted notes on VAAK.
//! `relay_book` is the admin's relay list (Admin > Relays > Nostr).

pub mod act;
pub mod bech32;
pub mod event;
pub mod home;
pub mod relay;
pub mod relay_book;
pub mod render;
pub mod share;
pub mod sync;
