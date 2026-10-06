<?php
declare(strict_types=1);

/** Pure settings safety gate; never connects to production or writes data. */
$fixturePath = dirname(__DIR__, 2) . '/rust/vaak-worker/fixtures/settings/profile-fields.json';
$fixture = is_file($fixturePath) ? json_decode((string) file_get_contents($fixturePath), true) : null;
$canonical = [
    'name', 'summary', 'attachment_json', 'icon_url', 'image_url',
    'manually_approves', 'discoverable', 'indexable', 'collection_consent',
    'vanity_verified', 'auto_follow_back', 'anti_ai_marker',
    'auto_unblur_sensitive', 'auto_delete_posts_7d', 'automated',
    'reply_policy', 'quote_policy', 'forum_signature', 'profile_badges',
    'hide_profile_replies', 'hide_profile_boosts', 'algorithm_enabled',
    'downranking_enabled', 'asks_enabled', 'webmentions_enabled', 'updated_at',
];
if (!is_array($fixture) || ($fixture['fields'] ?? null) !== $canonical) {
    fwrite(STDERR, "settings-parity: canonical field contract mismatch\n");
    exit(1);
}
if (($fixture['write_owner'] ?? '') !== 'php' || ($fixture['mutation_enabled'] ?? true) !== false) {
    fwrite(STDERR, "settings-parity: unsafe mutation ownership marker\n");
    exit(1);
}
foreach (['email', 'password', 'password_hash', 'api_key', 'session_token', 'totp_secret'] as $forbidden) {
    if (in_array($forbidden, $canonical, true)) {
        fwrite(STDERR, "settings-parity: sensitive field leaked into projection: {$forbidden}\n");
        exit(1);
    }
}
echo "settings-parity: PASS (" . count($canonical) . " canonical fields; PHP write owner)\n";
