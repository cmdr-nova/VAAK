<?php
declare(strict_types=1);
/**
 * Actual admin_linkify_body_html own mention discovery, as the raw outbox
 * card calls it (no declared mentions). Real preprocessing, unglue, bare and
 * Bluesky handle SQL helpers run against SQLite fixture rows. Only the
 * account helper ap_masto_mention_from_actor is a fixture map; its Rust port
 * has separate coverage. No application bootstrap or network.
 *
 * Usage: php-policy-test profile-raw-mention-reference.php [pdo-dsn]
 * A pgsql DSN reads the caller's already-seeded remote_actors/ap_users and
 * prints only the lookup results (the Rust SQL differential).
 */
if (!extension_loaded('mbstring')) {
    fwrite(STDERR, "Raw mention references require actual mbstring; use api/bin/php-policy-test\n");
    exit(2);
}
require_once __DIR__ . '/migration-reference-functions.php';
stream_wrapper_unregister('http'); stream_wrapper_unregister('https');
$api = dirname(__DIR__);
$dsn = $argv[1] ?? 'sqlite::memory:';
$GLOBALS['fixture_db'] = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['fixture_db']; }

$fixture = [
    'remote_actors' => [
        ['actor_id' => 'https://bsky.brid.gy/ap/did:plc:alice', 'username' => 'alice.bsky.social', 'host' => 'bsky.brid.gy', 'updated_at' => '2026-01-02T00:00:00Z'],
        ['actor_id' => 'https://other.example/users/alice', 'username' => 'alice.bsky.social', 'host' => 'other.example', 'updated_at' => '2026-01-05T00:00:00Z'],
        ['actor_id' => 'https://custom.example/users/me/', 'username' => 'custom.example', 'host' => 'custom.example', 'updated_at' => '2026-01-01T00:00:00Z'],
        ['actor_id' => 'https://mastodon.social/users/Gargron', 'username' => 'Gargron', 'host' => 'mastodon.social', 'updated_at' => '2026-01-01T00:00:00Z'],
        ['actor_id' => 'https://a.example/users/dup', 'username' => 'dup', 'host' => 'a.example', 'updated_at' => '2026-01-01T00:00:00Z'],
        ['actor_id' => 'https://b.example/users/dup', 'username' => 'Dup', 'host' => 'b.example', 'updated_at' => '2026-01-03T00:00:00Z'],
        ['actor_id' => 'https://hachyderm.io/users/eliza', 'username' => 'Eliza', 'host' => 'hachyderm.io', 'updated_at' => '2026-01-01T00:00:00Z'],
        ['actor_id' => 'https://x.example/users/bob', 'username' => 'bob', 'host' => 'x.example', 'updated_at' => '2026-01-01T00:00:00Z'],
        ['actor_id' => 'https://money.example/users/cash', 'username' => 'cash', 'host' => 'money.example', 'updated_at' => '2026-01-01T00:00:00Z'],
        ['actor_id' => 'https://tilde.example/~zed', 'username' => 'zed', 'host' => 'tilde.example', 'updated_at' => '2026-01-01T00:00:00Z'],
        // A remote "radio" must not win over the local radio account.
        ['actor_id' => 'https://stream.example/users/radio', 'username' => 'radio', 'host' => 'stream.example', 'updated_at' => '2026-01-09T00:00:00Z'],
    ],
    'ap_users' => [
        ['id' => 1, 'username' => 'fixture', 'actor_id' => 'https://mkultra.monster/users/fixture', 'disabled_at' => null],
        ['id' => 2, 'username' => 'luna', 'actor_id' => 'https://mkultra.monster/users/luna', 'disabled_at' => null],
        ['id' => 3, 'username' => 'ghost', 'actor_id' => 'https://mkultra.monster/users/ghost', 'disabled_at' => '2026-01-01T00:00:00Z'],
        ['id' => 4, 'username' => 'radio', 'actor_id' => 'https://mkultra.monster/users/radio', 'disabled_at' => null],
    ],
    'mentions' => [
        'https://bsky.brid.gy/ap/did:plc:alice' => ['username' => 'alice.bsky.social', 'acct' => 'alice.bsky.social', 'url' => 'https://bsky.brid.gy/ap/did:plc:alice'],
        'https://custom.example/users/me' => ['username' => 'custom.example', 'acct' => 'custom.example@custom.example', 'url' => 'https://custom.example/users/me'],
        'https://mastodon.social/users/Gargron' => ['username' => 'Gargron', 'acct' => 'Gargron@mastodon.social', 'url' => 'https://mastodon.social/users/Gargron'],
        'https://b.example/users/dup' => ['username' => 'Dup', 'acct' => 'Dup@b.example', 'url' => 'https://b.example/users/dup'],
        'https://hachyderm.io/users/eliza' => ['username' => 'Eliza', 'acct' => 'Eliza@hachyderm.io', 'url' => 'https://hachyderm.io/users/eliza'],
        'https://x.example/users/bob' => ['username' => 'bob', 'acct' => 'bob@x.example', 'url' => 'https://x.example/users/bob'],
        'https://money.example/users/cash' => ['username' => 'ca$h', 'acct' => 'cash@money.example', 'url' => 'https://money.example/users/cash'],
        'https://tilde.example/~zed' => ['username' => 'zed', 'acct' => 'zed@tilde.example', 'url' => 'https://tilde.example/~zed'],
        'https://mkultra.monster/users/luna' => ['username' => 'luna', 'acct' => 'luna', 'url' => 'https://mkultra.monster/users/luna'],
        'https://mkultra.monster/users/radio' => ['username' => 'radio', 'acct' => 'radio', 'url' => 'https://mkultra.monster/users/radio'],
    ],
];

/** Fixture map for the account helper; production shape and cmdr_nova constant. */
function ap_masto_mention_from_actor(string $actorId): array
{
    $actorId = rtrim(trim($actorId), '/');
    if ($actorId === 'https://mkultra.monster/users/cmdr_nova' || str_starts_with($actorId, 'https://mkultra.monster/users/cmdr_nova/')) {
        return ['id' => '1', 'username' => 'cmdr_nova', 'url' => 'https://mkultra.monster/users/cmdr_nova', 'acct' => 'cmdr_nova'];
    }
    $known = $GLOBALS['fixture_mentions'][$actorId] ?? null;
    if (is_array($known)) {
        return ['id' => 'x'] + $known;
    }
    $user = basename((string) parse_url($actorId, PHP_URL_PATH));
    return ['id' => 'x', 'username' => $user, 'url' => $actorId, 'acct' => $user . '@' . strtolower((string) parse_url($actorId, PHP_URL_HOST))];
}
$GLOBALS['fixture_mentions'] = $fixture['mentions'];
reference_load($api . '/ap-masto-entities.php', ['ap_masto_resolve_bare_username', 'ap_masto_resolve_bsky_handle_actor']);
reference_load($api . '/ap-db.php', ['ap_local_user_by_username']);
if (str_starts_with($dsn, 'pgsql:')) {
    // Lookup-only differential against rows the Rust test already seeded:
    // the unmapped account helper returns the looked-up actor as its url.
    $GLOBALS['fixture_mentions'] = [];
    $out = [];
    foreach (json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR) as $name) {
        $bare = ap_masto_resolve_bare_username($name);
        $out[] = ['name' => $name, 'bsky' => ap_masto_resolve_bsky_handle_actor($name), 'bare' => is_array($bare) ? $bare['url'] : null];
    }
    echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}


ap_db()->exec('CREATE TABLE remote_actors(actor_id TEXT PRIMARY KEY, username TEXT, host TEXT, updated_at TEXT NOT NULL)');
ap_db()->exec('CREATE TABLE ap_users(id INTEGER PRIMARY KEY, username TEXT NOT NULL, actor_id TEXT NOT NULL, disabled_at TEXT)');
foreach ($fixture['remote_actors'] as $row) {
    ap_db()->prepare('INSERT INTO remote_actors VALUES(?,?,?,?)')->execute([$row['actor_id'], $row['username'], $row['host'], $row['updated_at']]);
}
foreach ($fixture['ap_users'] as $row) {
    ap_db()->prepare('INSERT INTO ap_users VALUES(?,?,?,?)')->execute([$row['id'], $row['username'], $row['actor_id'], $row['disabled_at']]);
}

$source = (string) file_get_contents($api . '/ap-admin.php');
preg_match_all('/^function\s+((?:admin_|vaak_)[a-zA-Z_0-9]+|h)\s*\(/m', $source, $matches);
reference_load($api . '/ap-admin.php', array_values(array_unique($matches[1])));
reference_load($api . '/ap-db.php', ['ap_normalize_bridgy_plain_mentions', 'ap_plain_unglue_mentions', 'ap_plain_expand_bare_mentions_from_urls', 'ap_plain_unglue_hashtags']);
reference_load($api . '/ap-masto-entities.php', ['ap_masto_repair_at_hash_tags', 'ap_masto_unglue_host_mentions', 'ap_masto_unglue_bare_handles', 'ap_masto_url_looks_like_status']);
reference_load($api . '/ap-vaak-profile-route.php', ['ap_vaak_pretty_profile_path', 'ap_vaak_normalize_actor_key']);

$signedIn = ['fixture', 'https://mkultra.monster/users/fixture'];
$guest = ['__guest__', ''];
$texts = [
    'bsky' => 'hi @alice.bsky.social and @unknown.bsky.social! also @Alice.Bsky.Social.',
    'bsky_custom' => 'domain handle @custom.example, not @custom.example.org.',
    'bsky_bridgy' => '@alice.bsky.social@bsky.brid.gy and @x.bsky.social@bsky.app said so',
    'user_host' => 'cc @Gargron@mastodon.social and @Gargron, @nobody@unknown.example too',
    'user_host_edges' => '@user@host.example.com. and @MiXeD@Host.Example and @a@b.c and x@bob@x.example',
    'ambiguous' => '@bob@y.example @bob@z.example and bare @bob stays',
    'ambiguous_prefer_bare' => '@bob@x.example then @dup and @Dup@b.example and @dup again',
    'bare' => '@dup said hi to @luna, @ghost and @nobody_here',
    'self' => 'self @fixture and @FIXTURE and @fixture@mkultra.monster',
    'cmdr' => '@cmdr_nova hi @cmdr-nova',
    'emails' => 'mail a@b.com or x@y, lone @ here, @x single, @@double, @_u_',
    'url_expand' => 'see https://mastodon.social/@Gargron/123 and @Gargron',
    'url_inside' => 'url https://example.com/@who/1?x=@who and www.example.com/@me then example.org/@you',
    'code' => 'code `@alice.bsky.social` and `@nobody` then @nobody and ` @x `',
    'glued' => '@ElizaIf you see @Eliza_x or @Elizabeth and @luna@mkultra.monsterHello',
    'unicode' => '@üser unicode @zoë and @Zoë日本 plus #タグ @dup。',
    'tags' => '#tag @a.b.c.example. @foo.ab-cd @foo.ab1 #@hash @#tag@x.example',
    'entities' => '&lt;@Gargron&gt; &amp; "quotes" \'single\' @dup&amp;co',
    'scheme_less' => 'bsky.app/profile/x.bsky.social/post/abc @custom.example',
    'long' => '@a_b_c_d_e_f_g_h_i_j_k_l_m_n_o_p_q_r too long, @abcdefghijabcdefghijabcdefghijab ok',
    'status_link' => 'RE https://mastodon.social/@Gargron/109 @Gargron.',
    'preg_refs' => 'pay @cash now',
    'tilde' => 'hi @zed',
    'replace_inside' => '@bob@x.example and x@bob@x.example and @bob@x.example.org',
    'case_sensitive' => '@Luna and @luna\'s, @LUNA@mkultra.monster',
    'www_edges' => 'xwww.www.foo.com and (https://a.example/x). and https://a.example/@dup/ and https://a.example/x/).',
    'trailing' => 'ping @dup!!! @Gargron@mastodon.social, @alice.bsky.social…',
    'multiline' => "first @luna\n\n@Gargron@mastodon.social\nlast @nobody",
    'whitespace' => '   ',
    'local_over_remote' => 'Welcome to VAAK, @radio! 🌟 say hi to @ghost',
];
$cases = [];
foreach ($texts as $name => $text) {
    foreach (['signed_in' => $signedIn, 'guest' => $guest] as $who => [$key, $actor]) {
        if ($who === 'guest' && !in_array($name, ['bsky', 'bare', 'self', 'user_host', 'tilde'], true)) continue;
        $GLOBALS['vaak_actor_key'] = $key; $GLOBALS['vaak_actor_id'] = $actor; unset($GLOBALS['vaak_user']);
        foreach (['remote_profile', 'profile'] as $view) {
            if ($view === 'profile' && $who === 'guest') continue;
            $cases[] = ['name' => $name . '_' . $who . '_' . $view, 'text' => $text, 'self_key' => $key, 'self_actor' => $actor, 'view' => $view, 'html' => admin_linkify_body_html($text, $view, [], null)];
        }
    }
}
// The same raw card with a guest self token: PHP keys it as '__guest__'.
$GLOBALS['vaak_actor_key'] = '__guest__'; $GLOBALS['vaak_actor_id'] = '';
$cases[] = ['name' => 'guest_token', 'text' => '@__guest__ and @dup', 'self_key' => '__guest__', 'self_actor' => '', 'view' => 'remote_profile', 'html' => admin_linkify_body_html('@__guest__ and @dup', 'remote_profile', [], null)];
echo json_encode(['fixture' => $fixture, 'cases' => $cases], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
