#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Opt-in: render stored blog posts with the Rust blog renderer and save the
 * HTML in vaak_blog_posts.body_html, so older posts get the richer Markdown
 * (formatting inside lists and headings, quotes, code, images) too.
 *
 * Needs the worker's render route: VAAK_BLOG_NATIVE=1 and the
 * VAAK_ADMIN_READ_SECRET the worker uses. Not installed as cron and not run
 * by deploy. Without --apply it only reports what would change.
 *
 *   sudo -u www-data env VAAK_BLOG_NATIVE=1 VAAK_ADMIN_READ_SECRET=... AP_DB_DSN=pgsql:dbname=novalandia \
 *     php api/bin/blog-render-backfill.php            # dry run
 *   ... php api/bin/blog-render-backfill.php --apply
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$apply = in_array('--apply', array_slice($argv, 1), true);
require_once __DIR__ . '/../ap-db.php';
require_once __DIR__ . '/../ap-blog.php';

$st = ap_db()->query('SELECT owner_user_id, actor_key, slug, body_markdown, body_html FROM vaak_blog_posts ORDER BY id ASC');
$rows = $st ? ($st->fetchAll() ?: []) : [];
$changed = 0;
$same = 0;
$failed = 0;
foreach ($rows as $row) {
    $render = ap_blog_render((string) ($row['body_markdown'] ?? ''));
    if ($render === null) {
        $failed++;
        fwrite(STDERR, 'render failed for ' . $row['actor_key'] . '/' . $row['slug'] . " (is VAAK_BLOG_NATIVE=1 set and the worker up?)\n");
        if ($failed >= 3 && $changed === 0 && $same === 0) {
            fwrite(STDERR, "stopping: the worker is not answering\n");
            exit(1);
        }
        continue;
    }
    if ($render['html'] === (string) ($row['body_html'] ?? '')) {
        $same++;
        continue;
    }
    $changed++;
    if ($apply) {
        // Leave updated_at alone: this is a re-render, not an edit.
        ap_db()->prepare('UPDATE vaak_blog_posts SET body_html = ? WHERE owner_user_id = ? AND actor_key = ? AND slug = ?')
            ->execute([$render['html'], (int) $row['owner_user_id'], (string) $row['actor_key'], (string) $row['slug']]);
    }
}
printf("%s posts=%d changed=%d unchanged=%d failed=%d\n", $apply ? 'applied' : 'dry-run', count($rows), $changed, $same, $failed);
exit($failed > 0 ? 1 : 0);
