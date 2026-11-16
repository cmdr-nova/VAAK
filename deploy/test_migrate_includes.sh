#!/usr/bin/env bash
# migrate.php is copied to the release root, next to api/. Its recommendation
# indexes must require that api directory. dirname(__DIR__, 2) is above the
# release (/srv/mkultra/vaak-releases/api/...) and aborts activate.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
command -v php >/dev/null || { echo "php is required" >&2; exit 127; }

if grep -nE 'require(_once)?[[:space:]]+dirname\(__DIR__, 2\)' "$root/deploy/remote/migrate.php"; then
  echo "migrate.php still requires a file via dirname(__DIR__, 2)" >&2
  exit 1
fi

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

binary="${tmp}/vaak-worker"
printf '#!/bin/sh\necho vaak-worker\n' >"$binary"
chmod 0755 "$binary"
sha="$(printf 'b%.0s' {1..40})"
bash "$root/deploy/assemble-release.sh" --binary "$binary" --revision "$sha" --output "${tmp}/out"

# Two levels under the temp dir, so the repo-layout fallback stays inside
# this directory and cannot accidentally find a real api/ tree.
mkdir -p "${tmp}/a/b" "${tmp}/bogus-api"
cp -a "$root/deploy/remote/migrate.php" "${tmp}/a/b/migrate.php"

cat >"${tmp}/check.php" <<'PHP'
<?php
declare(strict_types=1);

$migrate = $argv[1];
$mode = $argv[2];
$api = $argv[3] ?? '';
require $migrate;

$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
};

if ($mode === 'api-root') {
    $path = vaak_migrate_recommendations_php($api);
    $expected = rtrim($api, '/') . '/ap-recommendations.php';
    if ($path !== $expected) {
        $fail("api-root path was {$path}, expected {$expected}");
    }
    $realMigrate = realpath($migrate);
    if ($realMigrate === false) {
        $fail('migrate.php is missing');
    }
    $release = dirname($realMigrate);
    $resolved = realpath($path);
    if ($resolved === false) {
        $fail("api-root file is missing: {$path}");
    }
    if (!str_starts_with($resolved, $release . DIRECTORY_SEPARATOR)) {
        $fail("api-root resolved outside the release: {$resolved}");
    }
    require_once $path;
    if (!function_exists('ap_home_recommendation_pgsql_quote_key')) {
        $fail('ap-recommendations.php did not load');
    }
    $specs = vaak_migrate_recommendation_index_specs($api);
    if (count($specs) !== 9) {
        $fail('expected nine recommendation indexes, got ' . count($specs));
    }
    foreach ($specs as $name => $spec) {
        if (!str_contains($spec['create'], 'IF NOT EXISTS')) {
            $fail("{$name} create is not idempotent");
        }
    }
    foreach (vaak_migrate_recommendation_function_statements() as $statement) {
        if (!str_contains($statement, 'CREATE OR REPLACE FUNCTION')) {
            $fail('recommendation helper is not CREATE OR REPLACE');
        }
    }
    exit(0);
}

if ($mode === 'fallback') {
    $path = vaak_migrate_recommendations_php(null);
    $realMigrate = realpath($migrate);
    if ($realMigrate === false) {
        $fail('migrate.php is missing');
    }
    $release = dirname($realMigrate);
    $expected = $release . '/api/ap-recommendations.php';
    if ($path !== $expected) {
        $fail("assembled fallback was {$path}, expected {$expected}");
    }
    $outside = dirname($release, 2) . '/api/ap-recommendations.php';
    if ($path === $outside || !is_file($path)) {
        $fail("assembled fallback left the release: {$path}");
    }
    exit(0);
}

if ($mode === 'repo') {
    $path = vaak_migrate_recommendations_php(null);
    $repoApi = dirname($migrate, 3) . '/api/ap-recommendations.php';
    if ($path !== $repoApi || !is_file($path)) {
        $fail("repo fallback was {$path}, expected {$repoApi}");
    }
    $specs = vaak_migrate_recommendation_index_specs(null);
    if (count($specs) !== 9) {
        $fail('repo layout did not load recommendation indexes');
    }
    exit(0);
}

if ($mode === 'missing-root') {
    try {
        vaak_migrate_recommendations_php($api);
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'ap-recommendations.php') || !str_contains($e->getMessage(), $api)) {
            $fail($e->getMessage());
        }
        exit(0);
    }
    $fail('a passed api root with no ap-recommendations.php was accepted');
}

if ($mode === 'missing-both') {
    try {
        vaak_migrate_recommendations_php(null);
    } catch (RuntimeException $e) {
        $assembled = dirname($migrate) . '/api/ap-recommendations.php';
        $repo = dirname($migrate, 3) . '/api/ap-recommendations.php';
        if (!str_contains($e->getMessage(), $assembled) || !str_contains($e->getMessage(), $repo)) {
            $fail($e->getMessage());
        }
        exit(0);
    }
    $fail('migrate.php with no api directory was accepted');
}

$fail("unknown mode {$mode}");
PHP

php "${tmp}/check.php" "${tmp}/out/migrate.php" api-root "${tmp}/out/api"
php "${tmp}/check.php" "${tmp}/out/migrate.php" fallback
php "${tmp}/check.php" "${tmp}/out/migrate.php" missing-root "${tmp}/bogus-api"
php "${tmp}/check.php" "$root/deploy/remote/migrate.php" repo
php "${tmp}/check.php" "${tmp}/a/b/migrate.php" missing-both

echo "migrate_include_resolution_ok"
