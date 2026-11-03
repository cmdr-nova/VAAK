//! Nostr follows in Home (opt-in per user; see api/ap-nostr.php).
//!
//! VAAK stores only public keys. Likes, replies, follows, reposts and quotes
//! are signed in the browser by the user's signer extension; `act` checks
//! and relays them. `share` shows boosted and quoted notes on VAAK.
//! `relay_book` is the admin's relay list (Admin > Relays > Nostr), and
//! `moderation` the mutes, hides and admin blocks every Nostr read obeys,
//! and `notif` the replies, mentions, likes and reposts in Notifications.
//! `archive` keeps a permanent copy of linked users' own events, which
//! `backup_relay` serves as a write-restricted relay.
//! `bunker` signs through a user's NIP-46 remote signer (with `nip44`) for
//! pages that have no extension, such as an iPhone Home Screen app.
//! `pack` opens NIP-51 follow packs (Library > Collections) for one-signature
//! follow-all.
//! `quote` fetches the notes quotes point at, so a quoted note paints as
//! a card instead of a link.
//! `search` finds stored notes from a linked user's follows for Search, and
//! `counts` gives recent ones like/repost counts for Trending posts.
//! `zap` is NIP-57: Lightning zaps sent from Nostr cards and receipts that
//! notify.

pub mod act;
pub mod archive;
pub mod backup_relay;
pub mod bech32;
pub mod bunker;
pub mod counts;
pub mod event;
pub mod home;
pub mod moderation;
pub mod nip44;
pub mod notif;
pub mod pack;
pub mod quote;
pub mod relay;
pub mod relay_book;
pub mod render;
pub mod search;
pub mod share;
pub mod sync;
pub mod zap;
