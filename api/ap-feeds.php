<?php
/** Public RSS 2.0 / Atom 1.0 feeds for local actor outboxes. */
declare(strict_types=1);

function ap_feed_public_rows(string $actorKey, int $limit = 40): array
{
    $rows = [];
    foreach (ap_outbox_list(max(1, min($limit, 100)), $actorKey) as $row) {
        $visibility = (string) ($row['visibility'] ?? 'public');
        if (function_exists('ap_visibility_in_ap_outbox') && !ap_visibility_in_ap_outbox($visibility)) {
            continue;
        }
        $rows[] = $row;
    }
    return $rows;
}

function ap_feed_actor_url(string $actorKey): string
{
    return 'https://mkultra.monster/users/' . rawurlencode($actorKey);
}

function ap_feed_row_url(string $actorKey, array $row): string
{
    $id = trim((string) ($row['id'] ?? ''));
    if ($id !== '') {
        $parts = explode('/', trim($id, '/'));
        $last = end($parts);
        if (is_string($last) && preg_match('/^[a-f0-9]+$/i', $last)) {
            return ap_feed_actor_url($actorKey) . '/notes/' . rawurlencode($last);
        }
    }
    return ap_feed_actor_url($actorKey);
}

function ap_feed_row_text(array $row): string
{
    $create = json_decode((string) ($row['raw_create_json'] ?? ''), true);
    $object = is_array($create) && is_array($create['object'] ?? null) ? $create['object'] : [];
    $content = (string) ($object['content'] ?? $row['content'] ?? '');
    $content = preg_replace('/<br\s*\/?\s*>/i', "\n", $content) ?? $content;
    return trim(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function ap_feed_row_date(array $row): string
{
    $date = trim((string) ($row['published'] ?? ''));
    if ($date !== '') {
        return $date;
    }
    return gmdate(DATE_ATOM);
}

function ap_feed_render(string $actorKey, string $displayName, string $format = 'rss'): void
{
    $actorUrl = ap_feed_actor_url($actorKey);
    $profile = function_exists('ap_profile_get') ? ap_profile_get($actorKey) : [];
    $name = trim($displayName) !== '' ? $displayName : ('@' . $actorKey);
    $summary = trim(strip_tags((string) ($profile['summary'] ?? '')));
    $rows = ap_feed_public_rows($actorKey);

    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=120, stale-while-revalidate=300');
    header('Vary: Accept');

    if ($format === 'atom') {
        header('Content-Type: application/atom+xml; charset=utf-8');
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('feed');
        $xml->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        $xml->writeElement('title', $name);
        $xml->startElement('id'); $xml->text($actorUrl); $xml->endElement();
        $xml->startElement('link'); $xml->writeAttribute('rel', 'alternate'); $xml->writeAttribute('href', $actorUrl); $xml->endElement();
        foreach ($rows as $row) {
            $url = ap_feed_row_url($actorKey, $row);
            $date = ap_feed_row_date($row);
            $xml->startElement('entry');
            $xml->writeElement('title', mb_strimwidth(ap_feed_row_text($row), 0, 140, '…', 'UTF-8') ?: 'Post');
            $xml->writeElement('id', $url);
            $xml->writeElement('updated', $date);
            $xml->startElement('link'); $xml->writeAttribute('rel', 'alternate'); $xml->writeAttribute('href', $url); $xml->endElement();
            $xml->startElement('content'); $xml->writeAttribute('type', 'html'); $xml->text(ap_feed_row_text($row)); $xml->endElement();
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();
        echo $xml->outputMemory();
        return;
    }

    header('Content-Type: application/rss+xml; charset=utf-8');
    $xml = new XMLWriter();
    $xml->openMemory();
    $xml->startDocument('1.0', 'UTF-8');
    $xml->startElement('rss'); $xml->writeAttribute('version', '2.0');
    $xml->startElement('channel');
    $xml->writeElement('title', $name);
    $xml->writeElement('link', $actorUrl);
    $xml->writeElement('description', $summary !== '' ? $summary : ('Public posts by ' . $name));
    foreach ($rows as $row) {
        $url = ap_feed_row_url($actorKey, $row);
        $text = ap_feed_row_text($row);
        $xml->startElement('item');
        $xml->writeElement('title', mb_strimwidth($text, 0, 140, '…', 'UTF-8') ?: 'Post');
        $xml->writeElement('link', $url);
        $xml->writeElement('guid', $url);
        $xml->writeElement('pubDate', gmdate(DATE_RSS, strtotime(ap_feed_row_date($row)) ?: time()));
        $xml->writeElement('description', $text);
        $xml->endElement();
    }
    $xml->endElement(); $xml->endElement(); $xml->endDocument();
    echo $xml->outputMemory();
}
