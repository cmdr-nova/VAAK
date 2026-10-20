//! Shared shapes for the VAAK normalizer freeze contract.
//!
//! PHP fixtures under `api/fixtures/normalize/*/expected.json` are the source of
//! truth for the projected subset. Full Mastodon status JSON can grow here later
//! for Axum route ports.

mod status;

pub use status::{
    MediaProjection, NormalizedAccount, NormalizedStatusProjection, StatusSource,
};

mod timeline;

pub mod emoji;

pub use timeline::{check_value as check_timeline_status, MediaAttachment, Poll, PollOption, PreviewCard, Quote, TimelineStatus, REMOTE_POLL_ID_BASE};
