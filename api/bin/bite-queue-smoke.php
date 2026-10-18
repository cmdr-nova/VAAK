<?php
declare(strict_types=1);
/**
 * Bites go through the durable action queue like Like/Boost/Bookmark.
 * Each bite is one-shot (a nonce target key), so a second bite on the same
 * post queues again instead of coalescing. SQLite in memory; no network.
 */
$api = getenv('VAAK_BITE_QUEUE_API') ?: dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}
stream_wrapper_unregister('http');
stream_wrapper_unregister('https');

$GLOBALS['pdo'] = new PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['pdo']->exec("CREATE TABLE ap_action_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT, owner_user_id INTEGER NOT NULL, platform TEXT NOT NULL,
    action_kind TEXT NOT NULL, target_key TEXT NOT NULL, desired_state INTEGER NOT NULL,
    confirmed_state INTEGER, revision INTEGER NOT NULL DEFAULT 1, payload_json TEXT NOT NULL DEFAULT '{}',
    status TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0, max_attempts INTEGER NOT NULL DEFAULT 12,
    next_attempt_at TEXT NOT NULL, claimed_at TEXT, last_error TEXT, last_error_host TEXT, last_error_code INTEGER,
    result_json TEXT, receipt_json TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
    UNIQUE(owner_user_id, platform, action_kind, target_key))");

$GLOBALS['signals'] = 0;
function ap_db(): PDO { return $GLOBALS['pdo']; }
function ap_db_driver(PDO $db): string { return 'sqlite'; }
function ap_db_last_insert_id(string $t, string $c, PDO $db): int { return (int) $db->lastInsertId(); }
function ap_signal_record(): void { $GLOBALS['signals']++; }
function ap_local_actor_id(): string { return 'https://mkultra.monster/users/cmdr_nova'; }

reference_load($api . '/ap-action-queue.php', ['ap_action_queue_enqueue', 'ap_action_queue_make_tid']);
reference_load($api . '/ap-bites.php', ['ap_bite_precheck']);

$checks = 0;
function bite_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}

$post = 'https://wafrn.example/fediverse/post/abc';
$a = ap_action_queue_enqueue(1, 'fedi', 'bite', $post . '#bite-1', true, ['target' => $post, 'bite_kind' => 'post']);
$b = ap_action_queue_enqueue(1, 'fedi', 'bite', $post . '#bite-2', true, ['target' => $post, 'bite_kind' => 'post']);
bite_check(!empty($a['ok']) && !empty($b['ok']), 'bites queue');
bite_check($a['id'] !== $b['id'] && empty($b['coalesced']), 'a second bite on the same post queues again');
bite_check((int) ap_db()->query("SELECT COUNT(*) FROM ap_action_queue WHERE action_kind = 'bite' AND status = 'pending'")->fetchColumn() === 2, 'both bites are pending for the worker');
bite_check($GLOBALS['signals'] === 0, 'bites are not recorded as ranking signals');
bite_check(empty(ap_action_queue_enqueue(1, 'bsky', 'bite', $post . '#bite-3', true, [])['ok']), 'Bluesky bites are rejected');
bite_check(!empty(ap_action_queue_enqueue(1, 'fedi', 'like', $post, true, [])['ok']) && $GLOBALS['signals'] === 1, 'likes still record signals');

bite_check(ap_bite_precheck($post, 'post') === null, 'remote post can be bitten');
bite_check(ap_bite_precheck('https://mkultra.monster/users/cmdr_nova', 'user') === "You can't bite yourself", 'self bite is rejected before queueing');
bite_check(ap_bite_precheck('https://mkultra.monster/users/cmdr_nova/notes/1', 'post') === "You can't bite your own post", 'own post bite is rejected before queueing');
bite_check(ap_bite_precheck('alice', 'user') !== null, 'non-URL target is rejected');
bite_check(ap_bite_precheck($post, 'nibble') === 'Invalid bite kind', 'unknown kind is rejected');

$admin = (string) file_get_contents($api . '/ap-admin.php');
bite_check(str_contains($admin, "(int) \$vaakOwnerId, 'fedi', 'bite',"), 'Bite button handler enqueues');
bite_check(str_contains($admin, "actionInput.value !== 'bite_remote'"), 'Bite forms submit in the background');

echo "bite-queue-smoke: {$checks} checks passed\n";
