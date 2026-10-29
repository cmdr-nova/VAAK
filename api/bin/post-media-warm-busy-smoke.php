#!/usr/bin/env php
<?php
declare(strict_types=1);

function vaak_extract_function(string $source, string $name): string
{
    if (!preg_match('/^function ' . preg_quote($name, '/') . '\(/m', $source, $m, PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException('missing ' . $name);
    }
    $start = (int) $m[0][1];
    $line = strrpos(substr($source, 0, $start), "\n");
    $start = $line === false ? 0 : $line + 1;
    $len = strlen($source);
    $i = strpos($source, '{', $start);
    if ($i === false) {
        throw new RuntimeException('no body ' . $name);
    }
    $depth = 0;
    $state = '';
    for ($j = $i; $j < $len; $j++) {
        $c = $source[$j];
        $next = $j + 1 < $len ? $source[$j + 1] : '';
        if ($state === '') {
            if ($c === "'" || $c === '"') {
                $state = $c;
                continue;
            }
            if ($c === '/' && $next === '/') {
                $state = 'line';
                continue;
            }
            if ($c === '/' && $next === '*') {
                $state = 'block';
                $j++;
                continue;
            }
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $j - $start + 1);
                }
            }
        } elseif ($state === 'line') {
            if ($c === "\n") {
                $state = '';
            }
        } elseif ($state === 'block') {
            if ($c === '*' && $next === '/') {
                $state = '';
                $j++;
            }
        } else {
            if ($c === '\\') {
                $j++;
                continue;
            }
            if ($c === $state) {
                $state = '';
            }
        }
    }
    throw new RuntimeException('unclosed ' . $name);
}

function vaak_eval_functions(string $path, array $names): void
{
    $source = (string) file_get_contents($path);
    foreach ($names as $name) {
        eval(vaak_extract_function($source, $name));
    }
}

/*
 * Post-media warm: a busy download slot is a retry, not a success.
 * Before the fix ap_remote_post_media_ensure returned the source URL when
 * the per-URL lock or host slot was taken, the worker marked the job
 * "succeeded" with nothing cached, and ON CONFLICT DO NOTHING meant the
 * image was never queued again (Home hotlinked the original forever).
 */
$root = dirname(__DIR__);
vaak_eval_functions($root . '/ap-r2.php', ['ap_remote_post_media_ensure', 'ap_media_warm_worker_run']);

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE ap_media_warm_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, job_type TEXT NOT NULL,
  target_key TEXT NOT NULL, media_kind TEXT NOT NULL, status TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0,
  max_attempts INTEGER NOT NULL DEFAULT 5, next_attempt_at TEXT NOT NULL, claimed_at TEXT, last_error TEXT,
  created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE(job_type, target_key, media_kind))");
$GLOBALS['fixture_db'] = $db;
$GLOBALS['fixture_slot'] = null;
$GLOBALS['fixture_cache'] = [];
$GLOBALS['fixture_downloads'] = [];

function ap_db(): PDO { return $GLOBALS['fixture_db']; }
function ap_profile_sanitize_https_url(string $url): ?string { return str_starts_with($url, 'https://') ? $url : null; }
function ap_remote_post_media_get(string $url): ?array { return $GLOBALS['fixture_cache'][$url] ?? null; }
function ap_remote_post_media_touch(string $url): void {}
function ap_remote_media_download_slot(string $host): ?array { return $GLOBALS['fixture_slot']; }
function ap_remote_media_download_slot_release(?array $slot): void {}
function ap_remote_post_media_download(string $url, float $timeoutSec = 6.0): array
{
    $GLOBALS['fixture_downloads'][] = [$url, $timeoutSec];
    return ['ok' => false, 'error' => 'Remote media HTTP 503'];
}
function ap_media_warm_lock_path(): string { return sys_get_temp_dir() . '/vaak-post-media-busy-smoke-' . getmypid() . '.lock'; }

$n = 0;
$check = static function (bool $ok, string $label) use (&$n): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $n++;
};
$queue = static function (string $url) use ($db): void {
    $db->prepare("INSERT INTO ap_media_warm_queue (job_type, target_key, media_kind, status, next_attempt_at, created_at, updated_at)
                  VALUES ('post_media', ?, 'image', 'pending', '2000-01-01T00:00:00+00:00', 'x', 'x')")->execute([$url]);
};
$row = static function (string $url) use ($db): array {
    $st = $db->prepare('SELECT * FROM ap_media_warm_queue WHERE target_key = ?');
    $st->execute([$url]);
    return $st->fetch();
};

// 1) Slot busy, nothing cached: job stays pending, no attempt spent.
$busy = 'https://gotosocial.example/fileserver/a/attachment/original/busy.jpeg';
$queue($busy);
$check(ap_remote_post_media_ensure($busy) === null, 'busy slot without a cached copy is not a success');
$check(($GLOBALS['ap_media_last_error'] ?? '') === 'Download slot busy', 'busy slot reports busy');
$stats = ap_media_warm_worker_run(3);
$r = $row($busy);
$check($r['status'] === 'pending', 'busy job stays pending, got ' . $r['status']);
$check((int) $r['attempts'] === 0, 'busy job keeps its attempts');
$check($r['next_attempt_at'] > gmdate('c'), 'busy job is retried later');
$check($stats['succeeded'] === 0 && $stats['retried'] === 1, 'busy job counts as a retry');

// 2) Slot busy but another process already cached it: success.
$GLOBALS['fixture_cache'][$busy] = ['public_url' => 'https://media.example/cache/post-media/busy.webp'];
$check(ap_remote_post_media_ensure($busy) === 'https://media.example/cache/post-media/busy.webp', 'busy slot returns an existing copy');
unset($GLOBALS['fixture_cache'][$busy]);

// 3) Slot free: background download gets the long timeout.
$GLOBALS['fixture_slot'] = ['global' => null, 'host' => null];
$slow = 'https://gotosocial.example/fileserver/a/attachment/original/slow.jpeg';
$queue($slow);
ap_media_warm_worker_run(3);
$check(($GLOBALS['fixture_downloads'][0][1] ?? 0) === 15.0, 'worker download uses the 15 s timeout');
$r = $row($slow);
$check($r['status'] === 'pending' && (int) $r['attempts'] === 1, 'a real download failure still spends an attempt');

// 4) The CLI drains past one batch.
$worker = (string) file_get_contents($root . '/ap-media-warm-worker.php');
$check(str_contains($worker, '--budget=') && str_contains($worker, 'while (empty($stats[\'busy\'])'), 'worker keeps claiming batches within its budget');

@unlink(ap_media_warm_lock_path());
echo "post-media-warm-busy-smoke ok checks={$n}\n";
