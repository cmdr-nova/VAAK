#!/usr/bin/env php
<?php
/**
 * Repair stored remote avatars and headers that point at Mastodon system files
 * on this instance, such as:
 *   https://mkultra.monster/system/accounts/avatars/.../original/file.png
 * Those paths are not files VAAK serves, so clients 404.
 *
 * Dry-run by default. Pass --apply to write. Does not fetch the network and
 * does not delete object-storage files. Safe to run more than once.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

require_once dirname(__DIR__) . '/ap-db.php';

$apply = in_array('--apply', $argv ?? [], true);
$actors = ['scanned' => 0, 'clear' => 0, 'replace' => 0];
$cache = ['scanned' => 0, 'delete' => 0, 'replace' => 0];

$db = ap_db();
$actorRows = $db->query('SELECT actor_id, icon_source_url, image_source_url, profile_json FROM remote_actors');
if ($actorRows === false) {
    fwrite(STDERR, "could not read remote_actors\n");
    exit(1);
}
while ($row = $actorRows->fetch(PDO::FETCH_ASSOC)) {
    if (!is_array($row)) {
        continue;
    }
    $actors['scanned']++;
    $actorId = (string) ($row['actor_id'] ?? '');
    foreach (['icon_source_url' => 'icon', 'image_source_url' => 'image'] as $column => $kind) {
        $decision = ap_account_media_repair_decision(
            isset($row[$column]) ? (string) $row[$column] : null,
            $actorId,
            isset($row['profile_json']) ? (string) $row['profile_json'] : null,
            $kind
        );
        if ($decision['action'] === 'keep') {
            continue;
        }
        $actors[$decision['action']]++;
        echo $decision['action'] . "\tremote_actors." . $column . "\t" . $actorId . "\n";
        if (!$apply) {
            continue;
        }
        if ($decision['action'] === 'replace') {
            $update = $db->prepare('UPDATE remote_actors SET ' . $column . ' = ? WHERE actor_id = ?');
            $update->execute([$decision['replacement'], $actorId]);
        } else {
            $update = $db->prepare('UPDATE remote_actors SET ' . $column . ' = NULL WHERE actor_id = ?');
            $update->execute([$actorId]);
        }
    }
}

$cacheRows = $db->query("SELECT id, actor_id, kind, public_url, source_url FROM remote_media_cache WHERE kind IN ('avatar', 'header')");
if ($cacheRows === false) {
    fwrite(STDERR, "could not read remote_media_cache\n");
    exit(1);
}
while ($row = $cacheRows->fetch(PDO::FETCH_ASSOC)) {
    if (!is_array($row)) {
        continue;
    }
    $cache['scanned']++;
    $decision = ap_account_media_cache_repair_decision(
        isset($row['public_url']) ? (string) $row['public_url'] : null,
        isset($row['source_url']) ? (string) $row['source_url'] : null
    );
    if ($decision['action'] === 'keep') {
        continue;
    }
    $cache[$decision['action']]++;
    echo $decision['action'] . "\tremote_media_cache." . (string) ($row['kind'] ?? '') . "\t" . (string) ($row['actor_id'] ?? '') . "\n";
    if (!$apply) {
        continue;
    }
    if ($decision['action'] === 'replace') {
        $update = $db->prepare('UPDATE remote_media_cache SET public_url = ? WHERE id = ?');
        $update->execute([$decision['replacement'], (int) ($row['id'] ?? 0)]);
    } else {
        $update = $db->prepare('DELETE FROM remote_media_cache WHERE id = ?');
        $update->execute([(int) ($row['id'] ?? 0)]);
    }
}

echo ($apply ? 'applied' : 'dry-run')
    . ' actors_scanned=' . $actors['scanned']
    . ' clear=' . $actors['clear']
    . ' replace=' . $actors['replace']
    . ' cache_scanned=' . $cache['scanned']
    . ' delete=' . $cache['delete']
    . ' replace=' . $cache['replace']
    . "\n";
