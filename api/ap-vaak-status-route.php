<?php
/**
 * Human-facing permalinks for local VAAK posts.
 *
 * ActivityPub object ids stay https://mkultra.monster/users/{key}/notes/{id}.
 * The UI link is /vaak/@{key}/{id}. That path does not collide with
 * /vaak/users/{key} (profiles), /vaak/conduct, /vaak/privacy, or the actor
 * documents at /users/{key} and /@{key}. Notes, inbox, and feeds are untouched,
 * so ActivityPub content negotiation is unchanged.
 *
 * Caddy on the production host rewrites known /vaak paths into index.php
 * query params (the same way /vaak/users/{key} becomes ?local_key=). If an
 * unknown /vaak/@… path is not already passed through to vaak/index.php with
 * its original request URI, add:
 *
 *   @vaak_local_status path_regexp vaakstatus ^/vaak/@([A-Za-z0-9_]+)/([A-Za-z0-9_-]+)/?$
 *   rewrite @vaak_local_status /vaak/index.php?vaak_status_key={re.vaakstatus.1}&vaak_status_id={re.vaakstatus.2}&{http.request.uri.query}
 *
 * PHP also accepts the path on REQUEST_URI, so a try_files fall-through that
 * preserves the original URI needs no new rule. There is no nginx config in
 * this repo. Old /vaak/?view=status&object=… links are left as they are.
 */
declare(strict_types=1);

/**
 * Local note parts, or null when $objectUrl is not a local note id.
 * Announce fragments (#announce-123) are the boost wrapper, not the note.
 *
 * @return array{key:string,id:string,object:string}|null
 */
function ap_vaak_local_note_parts(string $objectUrl): ?array
{
    $objectUrl = trim($objectUrl);
    $stripped = preg_replace('/#announce-\d+$/', '', $objectUrl);
    if (is_string($stripped)) {
        $objectUrl = $stripped;
    }
    $stripped = preg_replace('/[?#].*$/', '', $objectUrl);
    if (is_string($stripped)) {
        $objectUrl = $stripped;
    }
    $objectUrl = rtrim($objectUrl, '/');
    // The human permalink is not an object id. Fold it back so search and
    // linkify do not fetch https://mkultra.monster/vaak/@…
    if (preg_match('#^https://mkultra\.monster/vaak/@([A-Za-z0-9_]+)/([A-Za-z0-9_-]+)$#', $objectUrl, $ui)) {
        $key = strtolower($ui[1]);
        $id = $ui[2];
        return [
            'key' => $key,
            'id' => $id,
            'object' => 'https://mkultra.monster/users/' . $key . '/notes/' . $id,
        ];
    }
    if (!preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)/notes/([A-Za-z0-9_-]+)$#', $objectUrl, $m)) {
        return null;
    }
    $key = strtolower($m[1]);
    $id = $m[2];
    return [
        'key' => $key,
        'id' => $id,
        'object' => 'https://mkultra.monster/users/' . $key . '/notes/' . $id,
    ];
}

/** In-app href for a local note, or null so the caller keeps ?view=status. */
function ap_vaak_local_status_href(string $objectUrl, string $from = 'home'): ?string
{
    $parts = ap_vaak_local_note_parts($objectUrl);
    if ($parts === null) {
        return null;
    }
    $from = preg_replace('/[^a-z_]/', '', $from) ?: 'home';
    return '/vaak/@' . $parts['key'] . '/' . $parts['id'] . '?from=' . rawurlencode($from);
}

/**
 * Absolute Copy-link URL. Local notes drop the in-app ?from= hint.
 * Remote status hrefs stay https://mkultra.monster/vaak/?view=status&object=…
 */
function ap_vaak_status_copy_url(string $href): string
{
    $href = trim($href);
    if (preg_match('#^/vaak/@[A-Za-z0-9_]+/[A-Za-z0-9_-]+#', $href)) {
        $path = strtok($href, '?');
        return 'https://mkultra.monster' . (is_string($path) && $path !== '' ? $path : $href);
    }
    if (str_starts_with($href, '?view=status')) {
        return 'https://mkultra.monster/vaak/' . $href;
    }
    return '';
}

/**
 * User-facing Retry href for a status that failed to paint.
 * Local notes stay on the short path. The thread refresh flag is appended.
 */
function ap_vaak_status_retry_href(string $objectUrl, string $from = 'home'): string
{
    $href = function_exists('admin_status_href')
        ? admin_status_href($objectUrl, $from)
        : (ap_vaak_local_status_href($objectUrl, $from)
            ?? ('?view=status&object=' . rawurlencode($objectUrl) . '&from=' . rawurlencode($from)));
    if ($href === '' || $href === '#') {
        $href = '?view=status&object=' . rawurlencode($objectUrl) . '&from=' . rawurlencode($from);
    }
    return $href . (str_contains($href, '?') ? '&' : '?') . 'refresh_thread=1';
}

/**
 * Short permalink carried by this request, from the path or a Caddy rewrite.
 *
 * @return array{key:string,id:string,object:string}|null
 */
function ap_vaak_short_status_from_request(): ?array
{
    $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_GET['vaak_status_key'] ?? ''))) ?? '';
    $id = (string) ($_GET['vaak_status_id'] ?? '');
    if ($key !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
        return [
            'key' => $key,
            'id' => $id,
            'object' => 'https://mkultra.monster/users/' . $key . '/notes/' . $id,
        ];
    }
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
    $path = rawurldecode($path);
    if (!preg_match('#^/vaak/@([A-Za-z0-9_]+)/([A-Za-z0-9_-]+)/?$#', $path, $m)) {
        return null;
    }
    $key = strtolower($m[1]);
    $id = $m[2];
    return [
        'key' => $key,
        'id' => $id,
        'object' => 'https://mkultra.monster/users/' . $key . '/notes/' . $id,
    ];
}

/**
 * What to do with a short permalink request.
 * GET/HEAD only. An explicit non-status ?view= leaves the permalink for the
 * app (browsers that ignore <base> keep "?view=home" on this path).
 * Partials and the historical ?view=status&object= form are not redirected.
 *
 * @return array{action:'status',object:string}|array{action:'redirect',location:string}|null
 */
function ap_vaak_short_status_plan(): ?array
{
    $target = ap_vaak_short_status_from_request();
    if ($target === null) {
        return null;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        return null;
    }
    $explicit = strtolower(preg_replace('/[^a-z_]/', '', (string) ($_GET['view'] ?? '')) ?? '');
    if ($explicit !== '' && $explicit !== 'status') {
        $qs = $_GET;
        unset($qs['vaak_status_key'], $qs['vaak_status_id']);
        $location = '/vaak/';
        if ($qs !== []) {
            $location .= '?' . http_build_query($qs);
        }
        return ['action' => 'redirect', 'location' => $location];
    }
    $object = trim((string) ($_GET['object'] ?? ''));
    if ($object === '' || !str_starts_with($object, 'https://')) {
        $object = $target['object'];
    }
    return ['action' => 'status', 'object' => $object];
}

/**
 * A copied permalink in ?object= is the UI URL, not the note id.
 * Replace it before status lookup so nothing fetches the page itself.
 */
function ap_vaak_normalize_permalink_object_query(): void
{
    $object = (string) ($_GET['object'] ?? '');
    if ($object === '' || !str_contains($object, '/vaak/@')) {
        return;
    }
    $parts = ap_vaak_local_note_parts($object);
    if ($parts === null) {
        return;
    }
    $_GET['object'] = $parts['object'];
}

/** Apply the short-permalink plan. Does not return when it redirects. */
function ap_vaak_apply_short_status_request(): void
{
    ap_vaak_normalize_permalink_object_query();
    $plan = ap_vaak_short_status_plan();
    if ($plan === null) {
        return;
    }
    unset($_GET['vaak_status_key'], $_GET['vaak_status_id']);
    if ($plan['action'] === 'redirect') {
        header('Cache-Control: private, no-store');
        header('Location: ' . $plan['location'], true, 302);
        exit;
    }
    $_GET['view'] = 'status';
    $_GET['object'] = $plan['object'];
    $GLOBALS['vaak_short_status'] = true;
}
