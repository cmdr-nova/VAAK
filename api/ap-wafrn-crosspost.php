<?php
/**
 * Wafrn ActivityPub ↔ Bluesky cross-post dedupe and text ask cards.
 *
 * Wafrn (https://github.com/gabboman/wafrn) links the two copies itself:
 * - Actor: packages/backend/utils/activitypub/userToJSONLD.ts pushes
 *   `at://${user.bskyDid}` onto alsoKnownAs when the user has a Bluesky DID.
 * - Post: packages/backend/atproto/utils/postToAtproto.ts sets the Bluesky
 *   record field fediverseId to `${frontendUrl}/fediverse/post/${post.id}`.
 *   Asks are prefixed `[Name asked:](url) ${question}\n\n${answer}` and then
 *   markdown is stripped, which arrives as "Name asked: question\n\nanswer".
 * - bskyUri / bskyCid are internal columns written by sendPostBsky.ts after
 *   the post. postToJSONLD.ts does not put them on the ActivityPub Note, so
 *   the inbound Note is not a reliable join key. The Bluesky record's
 *   fediverseId is.
 *
 * The ActivityPub copy stays. It already carries VAAK's ask card. A Bluesky
 * twin is dropped when its fediverseId matches an ActivityPub object on the
 * timeline (activity URLs and note URLs count as the same post). When that
 * record field is missing, a linked author, a 10 minute window, and
 * identical normalized text can still drop the twin. Bluesky posts with no
 * Wafrn link are left alone.
 */
declare(strict_types=1);

/**
 * Canonical post or actor URL. Host is lowercased, http becomes https, a
 * trailing slash is removed, and Wafrn's Create activity id
 * (/fediverse/activity/post/) collapses onto the Note id
 * (/fediverse/post/) that the Bluesky record stores.
 */
function ap_wafrn_post_identity(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (str_starts_with($url, 'http://')) {
        $url = 'https://' . substr($url, 7);
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || !is_string($parts['host'])) {
        return rtrim($url, '/');
    }
    $path = (string) ($parts['path'] ?? '');
    $path = preg_replace('#/fediverse/activity/post/#i', '/fediverse/post/', $path) ?? $path;
    $path = rtrim($path, '/');
    $host = strtolower($parts['host']);
    if (!empty($parts['port'])) {
        $host .= ':' . (int) $parts['port'];
    }

    return 'https://' . $host . $path;
}

/** Strip tags, one leading ask header ("X asked:" or Wafrn's Note "X asked"), and every whitespace character. */
function ap_wafrn_normalize_text(string $text): string
{
    $plain = ap_wafrn_plain_text($text);
    // Wafrn's federated asks end with this line; the Bluesky copy never does.
    $plain = preg_replace('/\s*To properly see this ask,\s*check the post in the original instance\.?\s*$/isu', '', $plain) ?? $plain;
    $plain = preg_replace('/^.{1,240}? asked:[ \t]*/u', '', $plain, 1) ?? $plain;
    // The ActivityPub Note opens with "<p>anonymous asked </p>" (no colon).
    $plain = preg_replace('/^(?:anonymous|@\S{1,240}?)\s+asked/iu', '', $plain, 1) ?? $plain;
    $plain = preg_replace('/\s+/u', '', $plain) ?? $plain;

    return $plain;
}

/**
 * Bluesky text from a Wafrn-linked account: "Name asked: question" then a
 * blank line, then the answer. Name, question, and answer must all be
 * non-empty. A missing blank line is not this form.
 *
 * @return array{asker_label:string,question:string,answer:string}|null
 */
function ap_wafrn_parse_text_ask(string $text): ?array
{
    $plain = ap_wafrn_plain_text($text);
    if (!preg_match('/^([^\n]{1,240}?)[ \t]+asked:[ \t]*([^\n]*)\n[ \t]*\n(.+)$/su', $plain, $m)) {
        return null;
    }
    $name = trim($m[1]);
    $question = trim($m[2]);
    $answer = trim($m[3]);
    if ($name === '' || $question === '' || $answer === '') {
        return null;
    }

    return [
        'asker_label' => $name,
        'question' => $question,
        'answer' => $answer,
    ];
}

/**
 * Ask card fields for a linked Wafrn Bluesky account. Unlinked accounts keep
 * whatever card the existing ask parser already produced.
 *
 * @return array{question:string,asker_label:string,answer:string,asker_actor:string}|null
 */
function ap_wafrn_text_ask_for_linked(string $text, bool $linked): ?array
{
    if (!$linked) {
        return null;
    }
    $parsed = ap_wafrn_parse_text_ask($text);
    if ($parsed === null) {
        return null;
    }

    return [
        'question' => $parsed['question'],
        'asker_label' => $parsed['asker_label'],
        'answer' => $parsed['answer'],
        'asker_actor' => '',
    ];
}

/**
 * DIDs Wafrn puts on alsoKnownAs. The published form is `at://did:plc:...`.
 * A bsky.app profile URL that contains a DID is accepted too.
 *
 * @return list<string>
 */
function ap_wafrn_dids_from_also_known_as(mixed $aka): array
{
    if (is_string($aka)) {
        $aka = [$aka];
    }
    if (!is_array($aka)) {
        return [];
    }
    $out = [];
    foreach ($aka as $item) {
        if (is_array($item)) {
            $item = (string) ($item['id'] ?? $item['href'] ?? '');
        }
        if (!is_string($item) || $item === '') {
            continue;
        }
        if (preg_match('~(did:(?:plc|web):[A-Za-z0-9._:%-]+)~', $item, $m)) {
            $out[$m[1]] = true;
        }
    }
    ksort($out);

    return array_keys($out);
}

/**
 * Decide which Bluesky ids to drop, and which actor/DID pairs the linkage
 * proved. ActivityPub ids are never returned.
 *
 * A non-empty fediverse_id is a post link. It drops the Bluesky copy only
 * when that post is already in $posts. It does not fall through to the text
 * match: the linked ActivityPub post simply is not on this timeline.
 *
 * @param list<array<string,mixed>> $posts
 * @param array<string,list<string>> $didToActors
 * @return array{drop:list<string>,learn:list<array{actor_id:string,bsky_did:string}>}
 */
function ap_wafrn_plan(array $posts, array $didToActors): array
{
    $ap = [];
    $identities = [];
    foreach ($posts as $post) {
        if (!is_array($post) || (string) ($post['network'] ?? '') !== 'ap') {
            continue;
        }
        $object = ap_wafrn_post_identity((string) ($post['object_id'] ?? ''));
        if ($object === '' || isset($identities[$object])) {
            continue;
        }
        $identities[$object] = true;
        $ap[] = [
            'object' => $object,
            'actor' => ap_wafrn_post_identity((string) ($post['actor_id'] ?? '')),
            'created_at' => (int) ($post['created_at'] ?? 0),
            'text' => ap_wafrn_normalize_text((string) ($post['text'] ?? '')),
        ];
    }
    $actorsByDid = [];
    foreach ($didToActors as $did => $actors) {
        if (!is_string($did) || !is_array($actors)) {
            continue;
        }
        foreach ($actors as $actor) {
            $id = ap_wafrn_post_identity((string) $actor);
            if ($id !== '') {
                $actorsByDid[$did][$id] = true;
            }
        }
    }
    $drop = [];
    $learn = [];
    $learnSeen = [];
    foreach ($posts as $post) {
        if (!is_array($post) || (string) ($post['network'] ?? '') !== 'bsky') {
            continue;
        }
        $id = (string) ($post['id'] ?? '');
        if ($id === '' || isset($drop[$id])) {
            continue;
        }
        $fedi = ap_wafrn_post_identity((string) ($post['fediverse_id'] ?? ''));
        if ($fedi !== '') {
            if (!isset($identities[$fedi])) {
                continue;
            }
            $drop[$id] = true;
            $did = trim((string) ($post['author_did'] ?? ''));
            if (!str_starts_with($did, 'did:')) {
                continue;
            }
            foreach ($ap as $row) {
                if ($row['object'] !== $fedi || $row['actor'] === '') {
                    continue;
                }
                $key = $row['actor'] . "\n" . $did;
                if (isset($learnSeen[$key])) {
                    continue;
                }
                $learnSeen[$key] = true;
                $learn[] = ['actor_id' => $row['actor'], 'bsky_did' => $did];
            }
            continue;
        }
        $did = trim((string) ($post['author_did'] ?? ''));
        $linked = $actorsByDid[$did] ?? [];
        if ($linked === []) {
            continue;
        }
        $bskyTs = (int) ($post['created_at'] ?? 0);
        if ($bskyTs <= 0) {
            continue;
        }
        $norm = ap_wafrn_normalize_text((string) ($post['text'] ?? ''));
        if ($norm === '') {
            continue;
        }
        $matches = 0;
        foreach ($ap as $row) {
            if ($row['actor'] === '' || empty($linked[$row['actor']])) {
                continue;
            }
            if ($row['created_at'] <= 0 || abs($row['created_at'] - $bskyTs) > 600) {
                continue;
            }
            if ($row['text'] === '' || $row['text'] !== $norm) {
                continue;
            }
            $matches++;
            if ($matches > 1) {
                break;
            }
        }
        if ($matches === 1) {
            $drop[$id] = true;
        }
    }

    return ['drop' => array_keys($drop), 'learn' => $learn];
}

/**
 * Pure replay used by the PHP/Rust parity harness. Database lookups stay
 * outside this function.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function ap_wafrn_replay(array $input): array
{
    $op = (string) ($input['op'] ?? '');
    if ($op === 'plan') {
        $posts = is_array($input['posts'] ?? null) ? $input['posts'] : [];
        $links = is_array($input['did_to_actors'] ?? null) ? $input['did_to_actors'] : [];

        return ap_wafrn_plan($posts, $links);
    }
    if ($op === 'ask') {
        return [
            'card' => ap_wafrn_text_ask_for_linked(
                (string) ($input['text'] ?? ''),
                !empty($input['linked'])
            ),
        ];
    }
    if ($op === 'identity') {
        return ['identity' => ap_wafrn_post_identity((string) ($input['value'] ?? ''))];
    }
    if ($op === 'normalize') {
        return ['text' => ap_wafrn_normalize_text((string) ($input['value'] ?? ''))];
    }
    if ($op === 'dids') {
        return ['dids' => ap_wafrn_dids_from_also_known_as($input['also_known_as'] ?? [])];
    }

    return ['error' => 'unknown op'];
}

/**
 * Drop Wafrn Bluesky twins from a timeline page. Items may be the internal
 * {kind,row} shape or Mastodon statuses. Bluesky posts that are not Wafrn
 * twins stay in the list.
 *
 * @param list<array<string,mixed>> $items
 * @return list<array<string,mixed>>
 */
function ap_wafrn_filter_timeline_items(array $items): array
{
    if ($items === []) {
        return $items;
    }
    $posts = [];
    $bskyAt = [];
    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            continue;
        }
        $post = ap_wafrn_post_from_item($item);
        if ($post === null) {
            continue;
        }
        $post['_index'] = $index;
        $posts[] = $post;
        if ($post['network'] === 'bsky') {
            $bskyAt[] = count($posts) - 1;
        }
    }
    if ($bskyAt === []) {
        return $items;
    }
    $needLinks = [];
    foreach ($bskyAt as $pi) {
        if (trim((string) ($posts[$pi]['fediverse_id'] ?? '')) === '') {
            $needLinks[] = (string) $posts[$pi]['id'];
        }
    }
    $links = ap_wafrn_lookup_post_links($needLinks);
    foreach ($bskyAt as $pi) {
        $uri = (string) $posts[$pi]['id'];
        if (trim((string) ($posts[$pi]['fediverse_id'] ?? '')) === '' && isset($links[$uri])) {
            $posts[$pi]['fediverse_id'] = $links[$uri];
        }
    }
    $dids = [];
    $actors = [];
    foreach ($posts as $post) {
        if ($post['network'] === 'bsky' && str_starts_with((string) $post['author_did'], 'did:')) {
            $dids[(string) $post['author_did']] = true;
        }
        if ($post['network'] === 'ap' && (string) $post['actor_id'] !== '') {
            $actors[(string) $post['actor_id']] = true;
        }
    }
    $didToActors = ap_wafrn_lookup_actor_links(array_keys($dids), array_keys($actors));
    $plan = ap_wafrn_plan($posts, $didToActors);
    $drop = array_fill_keys($plan['drop'], true);
    $undecided = [];
    foreach ($bskyAt as $pi) {
        $id = (string) $posts[$pi]['id'];
        if (isset($drop[$id])) {
            continue;
        }
        if (ap_wafrn_post_identity((string) ($posts[$pi]['fediverse_id'] ?? '')) !== '') {
            continue;
        }
        $did = (string) $posts[$pi]['author_did'];
        if ($did === '' || empty($didToActors[$did]) || (int) $posts[$pi]['created_at'] <= 0) {
            continue;
        }
        $undecided[] = $pi;
    }
    if ($undecided !== []) {
        $extra = ap_wafrn_lookup_fallback_events($undecided, $posts, $didToActors);
        if ($extra !== []) {
            $again = ap_wafrn_plan(array_merge($posts, $extra), $didToActors);
            foreach ($again['drop'] as $id) {
                $drop[$id] = true;
            }
        }
    }
    ap_wafrn_remember_pairs($plan['learn']);
    if ($drop === []) {
        return $items;
    }
    $skip = [];
    foreach ($bskyAt as $pi) {
        if (isset($drop[(string) $posts[$pi]['id']])) {
            $skip[(int) $posts[$pi]['_index']] = true;
        }
    }
    $out = [];
    foreach ($items as $index => $item) {
        if (!isset($skip[$index])) {
            $out[] = $item;
        }
    }

    return $out;
}

/**
 * Object identities already present on a ranked list. Event rows that have
 * not yet stored `o` are resolved with one primary-key lookup so an older
 * Bluesky page cannot reintroduce a twin of an earlier ActivityPub post.
 *
 * @param list<array<string,mixed>> $ranked
 * @return array<string,true>
 */
function ap_wafrn_ranked_identities(array $ranked): array
{
    $seen = [];
    $missing = [];
    foreach ($ranked as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $object = ap_wafrn_post_identity((string) ($entry['o'] ?? ''));
        if ($object !== '') {
            $seen[$object] = true;
        }
        $kind = (string) ($entry['k'] ?? '');
        if ($kind === 'outbox') {
            $id = ap_wafrn_post_identity((string) ($entry['id'] ?? ''));
            if ($id !== '') {
                $seen[$id] = true;
            }
        } elseif ($kind === 'event' && $object === '') {
            $id = (int) ($entry['id'] ?? 0);
            if ($id > 0) {
                $missing[$id] = true;
            }
        }
    }
    if ($missing !== [] && function_exists('ap_db')) {
        try {
            foreach (array_chunk(array_keys($missing), 200) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $st = ap_db()->prepare("SELECT object_id FROM events WHERE id IN ($ph)");
                $st->execute($chunk);
                foreach ($st->fetchAll() ?: [] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $id = ap_wafrn_post_identity((string) ($row['object_id'] ?? ''));
                    if ($id !== '') {
                        $seen[$id] = true;
                    }
                }
            }
        } catch (Throwable $e) {
            // The page filter still drops a twin that lands in the same window.
        }
    }

    return $seen;
}

/** Remember Wafrn alsoKnownAs DIDs from an actor document already being fetched. */
function ap_wafrn_remember_actor_document(string $actorId, array $doc): void
{
    $aka = $doc['alsoKnownAs'] ?? $doc['also_known_as'] ?? null;
    $pairs = [];
    foreach (ap_wafrn_dids_from_also_known_as($aka) as $did) {
        $pairs[] = ['actor_id' => $actorId, 'bsky_did' => $did];
    }
    ap_wafrn_remember_pairs($pairs);
}

function ap_wafrn_did_is_linked(string $did): bool
{
    static $memo = [];
    $did = trim($did);
    if ($did === '' || !str_starts_with($did, 'did:')) {
        return false;
    }
    if (array_key_exists($did, $memo)) {
        return $memo[$did];
    }
    if (!empty($GLOBALS['ap_wafrn_actors_missing']) || !function_exists('ap_db')) {
        $memo[$did] = false;

        return false;
    }
    try {
        $st = ap_db()->prepare('SELECT 1 FROM wafrn_bsky_actors WHERE bsky_did = ? LIMIT 1');
        $st->execute([$did]);
        $memo[$did] = $st->fetchColumn() !== false;
    } catch (Throwable $e) {
        ap_wafrn_mark_missing_table($e);
        $memo[$did] = false;
    }

    return $memo[$did];
}

/** @param array<string,mixed> $st */
function ap_wafrn_status_did(array $st): string
{
    $did = trim((string) ($st['author_did'] ?? ''));
    if (str_starts_with($did, 'did:')) {
        return $did;
    }
    $account = is_array($st['account'] ?? null) ? $st['account'] : [];
    $id = trim((string) ($account['id'] ?? ''));
    if (str_starts_with($id, 'did:')) {
        return $id;
    }
    $uri = trim((string) ($st['uri'] ?? ''));
    if (preg_match('~^at://(did:[^/]+)/~', $uri, $m)) {
        return $m[1];
    }
    $post = is_array($st['bsky_post'] ?? null) ? $st['bsky_post'] : [];
    $postDid = trim((string) ($post['author']['did'] ?? ''));
    if (str_starts_with($postDid, 'did:')) {
        return $postDid;
    }

    return '';
}

function ap_wafrn_plain_text(string $text): string
{
    $withBreaks = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
    $withBreaks = preg_replace('/<\/p>\s*<p[^>]*>/i', "\n\n", $withBreaks) ?? $withBreaks;
    $plain = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim($plain);
}

/**
 * @param array<string,mixed> $item
 * @return array{id:string,network:string,object_id:string,fediverse_id:string,actor_id:string,author_did:string,created_at:int,text:string}|null
 */
function ap_wafrn_post_from_item(array $item): ?array
{
    $kind = (string) ($item['kind'] ?? '');
    $row = is_array($item['row'] ?? null) ? $item['row'] : null;
    if ($row !== null && $kind !== '') {
        if ($kind === 'bsky') {
            $uri = rtrim(trim((string) ($row['bsky_uri'] ?? ($row['post']['uri'] ?? ''))), '/');
            if (!str_starts_with($uri, 'at://')) {
                return null;
            }
            $ts = (int) ($item['sort'] ?? 0);
            if ($ts <= 0) {
                $raw = (string) ($row['indexed_at'] ?? ($row['post']['indexedAt'] ?? ($row['post']['record']['createdAt'] ?? '')));
                $ts = strtotime($raw) ?: 0;
            }

            return [
                'id' => $uri,
                'network' => 'bsky',
                'object_id' => '',
                'fediverse_id' => (string) ($row['fediverse_id'] ?? ''),
                'actor_id' => '',
                'author_did' => (string) ($row['post']['author']['did'] ?? ($row['author_did'] ?? '')),
                'created_at' => $ts,
                'text' => (string) ($row['post']['record']['text'] ?? ($row['text'] ?? '')),
            ];
        }
        if ($kind === 'event') {
            $object = (string) ($row['object_id'] ?? '');
            if ($object === '') {
                return null;
            }
            $ts = (int) ($item['sort'] ?? 0);
            if ($ts <= 0) {
                $ts = strtotime((string) ($row['created_at'] ?? '')) ?: 0;
            }

            return [
                'id' => 'event:' . $object,
                'network' => 'ap',
                'object_id' => $object,
                'fediverse_id' => '',
                'actor_id' => (string) ($row['actor_id'] ?? ''),
                'author_did' => '',
                'created_at' => $ts,
                'text' => (string) ($row['summary'] ?? ''),
            ];
        }
        if ($kind === 'outbox') {
            $object = (string) ($row['id'] ?? '');
            if ($object === '') {
                return null;
            }
            $ts = (int) ($item['sort'] ?? 0);
            if ($ts <= 0) {
                $ts = strtotime((string) ($row['published'] ?? '')) ?: 0;
            }

            return [
                'id' => 'outbox:' . $object,
                'network' => 'ap',
                'object_id' => $object,
                'fediverse_id' => '',
                'actor_id' => (string) ($row['actor_id'] ?? ''),
                'author_did' => '',
                'created_at' => $ts,
                'text' => (string) ($row['content_text'] ?? ($row['content'] ?? '')),
            ];
        }
        if ($kind === 'boost') {
            $object = (string) ($row['object_id'] ?? '');
            if (!str_starts_with($object, 'http')) {
                return null;
            }
            $ts = (int) ($item['sort'] ?? 0);

            return [
                'id' => 'boost:' . $object,
                'network' => 'ap',
                'object_id' => $object,
                'fediverse_id' => '',
                'actor_id' => (string) ($row['actor_id'] ?? ''),
                'author_did' => '',
                'created_at' => $ts,
                'text' => (string) ($row['summary'] ?? ($row['content'] ?? '')),
            ];
        }

        return null;
    }
    $source = (string) ($item['source'] ?? '');
    $sid = (string) ($item['id'] ?? '');
    if ($source === 'rss' || str_starts_with($sid, 'rss:')) {
        return null;
    }
    $uri = rtrim(trim((string) ($item['uri'] ?? $item['url'] ?? '')), '/');
    $isBsky = $source === 'bluesky' || str_starts_with($uri, 'at://') || str_starts_with($sid, 'bsky:');
    if ($isBsky) {
        if (!str_starts_with($uri, 'at://')) {
            return null;
        }

        return [
            'id' => $uri,
            'network' => 'bsky',
            'object_id' => '',
            'fediverse_id' => (string) ($item['fediverse_id'] ?? ''),
            'actor_id' => '',
            'author_did' => ap_wafrn_status_did($item),
            'created_at' => strtotime((string) ($item['created_at'] ?? '')) ?: 0,
            'text' => (string) ($item['content'] ?? ''),
        ];
    }
    if (!str_starts_with($uri, 'http')) {
        return null;
    }
    $account = is_array($item['account'] ?? null) ? $item['account'] : [];
    $actor = (string) ($account['uri'] ?? ($account['url'] ?? ''));

    return [
        'id' => 'ap:' . $uri,
        'network' => 'ap',
        'object_id' => $uri,
        'fediverse_id' => '',
        'actor_id' => $actor,
        'author_did' => '',
        'created_at' => strtotime((string) ($item['created_at'] ?? '')) ?: 0,
        'text' => (string) ($item['content'] ?? ''),
    ];
}

/**
 * @param list<string> $uris
 * @return array<string,string>
 */
function ap_wafrn_lookup_post_links(array $uris): array
{
    $uris = array_values(array_unique(array_filter($uris, static fn($uri): bool => is_string($uri) && str_starts_with($uri, 'at://'))));
    if ($uris === [] || !function_exists('ap_db')) {
        return [];
    }
    $out = [];
    try {
        foreach (array_chunk($uris, 200) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = ap_db()->prepare("SELECT bsky_uri, fediverse_id FROM bsky_post_links WHERE bsky_uri IN ($ph)");
            $st->execute($chunk);
            foreach ($st->fetchAll() ?: [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $fedi = trim((string) ($row['fediverse_id'] ?? ''));
                if ($fedi !== '') {
                    $out[(string) $row['bsky_uri']] = $fedi;
                }
            }
        }
    } catch (Throwable $e) {
        return [];
    }

    return $out;
}

/**
 * @param list<string> $dids
 * @param list<string> $actors
 * @return array<string,list<string>>
 */
function ap_wafrn_lookup_actor_links(array $dids, array $actors): array
{
    if (!empty($GLOBALS['ap_wafrn_actors_missing']) || !function_exists('ap_db')) {
        return [];
    }
    $dids = array_values(array_filter($dids, static fn($did): bool => is_string($did) && str_starts_with($did, 'did:')));
    $actorIds = [];
    foreach ($actors as $actor) {
        $id = ap_wafrn_post_identity((string) $actor);
        if ($id !== '') {
            $actorIds[$id] = true;
        }
    }
    $actorIds = array_keys($actorIds);
    if ($dids === [] && $actorIds === []) {
        return [];
    }
    try {
        $where = [];
        $params = [];
        if ($dids !== []) {
            $where[] = 'bsky_did IN (' . implode(',', array_fill(0, count($dids), '?')) . ')';
            array_push($params, ...$dids);
        }
        if ($actorIds !== []) {
            $where[] = 'actor_id IN (' . implode(',', array_fill(0, count($actorIds), '?')) . ')';
            array_push($params, ...$actorIds);
        }
        $st = ap_db()->prepare('SELECT actor_id, bsky_did FROM wafrn_bsky_actors WHERE ' . implode(' OR ', $where));
        $st->execute($params);
        $map = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $did = (string) ($row['bsky_did'] ?? '');
            $actor = (string) ($row['actor_id'] ?? '');
            if ($did !== '' && $actor !== '') {
                $map[$did][] = $actor;
            }
        }

        return $map;
    } catch (Throwable $e) {
        ap_wafrn_mark_missing_table($e);

        return [];
    }
}

/**
 * @param list<int> $indexes
 * @param list<array<string,mixed>> $posts
 * @param array<string,list<string>> $didToActors
 * @return list<array<string,mixed>>
 */
function ap_wafrn_lookup_fallback_events(array $indexes, array $posts, array $didToActors): array
{
    if (!function_exists('ap_db')) {
        return [];
    }
    $actors = [];
    $min = PHP_INT_MAX;
    $max = 0;
    foreach ($indexes as $pi) {
        $did = (string) ($posts[$pi]['author_did'] ?? '');
        foreach ($didToActors[$did] ?? [] as $actor) {
            $id = ap_wafrn_post_identity((string) $actor);
            if ($id === '') {
                continue;
            }
            $actors[$id] = true;
            $actors[$id . '/'] = true;
        }
        $ts = (int) ($posts[$pi]['created_at'] ?? 0);
        if ($ts > 0) {
            $min = min($min, $ts - 600);
            $max = max($max, $ts + 600);
        }
    }
    if ($actors === [] || $min > $max) {
        return [];
    }
    // created_at is text. A suffix-wide upper bound covers both `Z` and `+00:00`.
    $from = gmdate('Y-m-d\TH:i:s', $min);
    $to = gmdate('Y-m-d\TH:i:s', $max) . '~';
    try {
        $ids = array_keys($actors);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = ap_db()->prepare(
            "SELECT actor_id, object_id, summary, created_at FROM events
             WHERE actor_id IN ($ph)
               AND created_at >= ? AND created_at <= ?
               AND type IN ('Create', 'Announce', 'Quote', 'QuotePost')
               AND action_taken IN ('log', 'local_observe')
             ORDER BY created_at DESC
             LIMIT 64"
        );
        $st->execute(array_merge($ids, [$from, $to]));
        $rows = $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
    if (count($rows) >= 64) {
        return [];
    }
    $extra = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $object = (string) ($row['object_id'] ?? '');
        if ($object === '') {
            continue;
        }
        $extra[] = [
            'id' => 'fallback:' . $object,
            'network' => 'ap',
            'object_id' => $object,
            'fediverse_id' => '',
            'actor_id' => (string) ($row['actor_id'] ?? ''),
            'author_did' => '',
            'created_at' => strtotime((string) ($row['created_at'] ?? '')) ?: 0,
            'text' => (string) ($row['summary'] ?? ''),
        ];
    }

    return $extra;
}

/** @param list<array{actor_id?:string,bsky_did?:string}> $pairs */
function ap_wafrn_remember_pairs(array $pairs): void
{
    if ($pairs === [] || !empty($GLOBALS['ap_wafrn_actors_missing']) || !function_exists('ap_db')) {
        return;
    }
    try {
        $st = ap_db()->prepare(
            'INSERT INTO wafrn_bsky_actors (actor_id, bsky_did, updated_at) VALUES (?, ?, ?)
             ON CONFLICT (actor_id, bsky_did) DO NOTHING'
        );
        $now = gmdate('c');
        foreach ($pairs as $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $actor = ap_wafrn_post_identity((string) ($pair['actor_id'] ?? ''));
            $did = trim((string) ($pair['bsky_did'] ?? ''));
            if ($actor === '' || !str_starts_with($did, 'did:')) {
                continue;
            }
            $st->execute([$actor, $did, $now]);
        }
    } catch (Throwable $e) {
        ap_wafrn_mark_missing_table($e);
    }
}

function ap_wafrn_mark_missing_table(Throwable $e): void
{
    $message = $e->getMessage();
    if (str_contains($message, 'wafrn_bsky_actors') || str_contains($message, '42P01')) {
        $GLOBALS['ap_wafrn_actors_missing'] = true;
    }
}
