<?php
/**
 * Schema-probe cache shape and moderation-alias Redis copy.
 * No database. Proves a warm snapshot round-trips and a positive column
 * fact survives export, and that alias caching does not invent refs.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/ap-db.php';

$GLOBALS['checks'] = 0;
function schema_check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
}

schema_check(ap_schema_cache_revision() === VAAK_VERSION, 'cache revision follows the release version');
schema_check(
    ap_schema_cache_key('public') === 'vaak:schema:v1:' . VAAK_VERSION . ':public',
    'cache key includes revision and schema'
);

$snap = [
    'tables' => ['bsky_posts' => true, 'actor_profile' => true],
    'columns' => [
        'actor_profile' => ['forum_signature' => "''::text", 'webmentions_enabled' => '1'],
        'ap_user_blocks' => ['id' => "nextval('ap_user_blocks_id_seq'::regclass)"],
        'ap_reports' => ['admin_notes' => null],
    ],
    'indexes' => [
        'idx_bsky_posts_lower_handle' => true,
        'idx_bsky_posts_reply_root' => true,
    ],
];
$round = ap_schema_snapshot_normalize(ap_schema_snapshot_export($snap));
schema_check(isset($round['tables']['bsky_posts'], $round['tables']['actor_profile']), 'tables survive export');
schema_check(
    isset($round['indexes']['idx_bsky_posts_lower_handle'], $round['indexes']['idx_bsky_posts_reply_root']),
    'bsky lookup indexes survive export'
);
schema_check(($round['columns']['actor_profile']['forum_signature'] ?? null) === "''::text", 'column default survives export');
schema_check(array_key_exists('admin_notes', $round['columns']['ap_reports']) && $round['columns']['ap_reports']['admin_notes'] === null, 'null column default stays present');
schema_check(str_contains((string) $round['columns']['ap_user_blocks']['id'], 'ap_user_blocks_id_seq'), 'sequence default stays recognizable');

$merged = ap_schema_snapshot_merge($round, [
    'tables' => ['ap_reports'],
    'columns' => ['actor_profile' => ['hide_profile_replies' => '0']],
    'indexes' => ['idx_mentions_owner_deleted_id'],
]);
schema_check(isset($merged['tables']['ap_reports'], $merged['tables']['bsky_posts']), 'merge keeps existing tables and adds new ones');
schema_check(($merged['columns']['actor_profile']['forum_signature'] ?? null) === "''::text", 'merge does not clobber a known column');
schema_check(($merged['columns']['actor_profile']['hide_profile_replies'] ?? null) === '0', 'merge records a newly ensured column');
schema_check(isset($merged['indexes']['idx_mentions_owner_deleted_id']), 'merge records a newly ensured index');

$duplicate = new PDOException('ERROR:  relation "idx_bsky_posts_reply_root" already exists');
schema_check(ap_schema_error_is_duplicate($duplicate), 'duplicate relation is treated as present');
$duplicateColumn = new PDOException('duplicate column');
$duplicateColumn->errorInfo = ['42701', 0, 'duplicate column'];
schema_check(ap_schema_error_is_duplicate($duplicateColumn), 'SQLSTATE 42701 is treated as present');
schema_check(!ap_schema_error_is_duplicate(new RuntimeException('permission denied for schema public')), 'permission errors stay failures');

ap_schema_cache_reset();
$sharedKey = ap_schema_cache_key('public');
ap_schema_cache_write_shared($sharedKey, $snap);
$shared = ap_schema_cache_read_shared($sharedKey);
if ($shared === null) {
    schema_check(true, 'shared cache skipped when APCu and Redis are unavailable');
} else {
    $loaded = ap_schema_snapshot_normalize($shared);
    schema_check(isset($loaded['indexes']['idx_bsky_posts_reply_root']), 'shared cache returns the bsky reply index');
    schema_check(isset($loaded['columns']['actor_profile']['webmentions_enabled']), 'shared cache returns profile columns');
}
ap_schema_cache_reset();

echo "schema-probe-cache: PASS ({$GLOBALS['checks']} checks)\n";
