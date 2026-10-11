<?php
/** Internal presentation boundaries shared by independently authenticated native bridges. */
declare(strict_types=1);

/** Canonical cached source classification; no preview network or budget IO. */
function ap_native_quote_requires_php(array $quoted): bool
{
    if (!function_exists('admin_quote_opts_from_status') || !function_exists('admin_html_to_plain')) {
        return true;
    }
    $plain = admin_html_to_plain((string) ($quoted['content'] ?? ''));
    if ($plain !== '' && function_exists('ap_text_looks_like_as2_json') && ap_text_looks_like_as2_json($plain)) {
        $plain = function_exists('ap_text_scrub_as2_dump') ? ap_text_scrub_as2_dump($plain) : '';
    }
    $acct = (string) ($quoted['account']['acct'] ?? '');
    $uri = (string) ($quoted['uri'] ?? $quoted['url'] ?? '');
    if ($plain === '' && ($acct === '' || strtolower(ltrim($acct, '@')) === 'quoted') && $uri !== '') {
        // Canonical painter can recover a synthetic source and change its opts.
        return true;
    }
    $opts = admin_quote_opts_from_status($quoted, $uri);
    $text = (string) ($opts['text'] ?? '');
    if ($text !== '' && function_exists('ap_text_looks_like_as2_json') && ap_text_looks_like_as2_json($text)) {
        $text = function_exists('ap_text_scrub_as2_dump') ? ap_text_scrub_as2_dump($text) : '';
    }
    if ($text === '' && $plain !== '') {
        $text = $plain;
    }
    $media = is_array($opts['media'] ?? null) ? $opts['media'] : [];
    $card = is_array($opts['card'] ?? null) ? $opts['card'] : null;
    // Bsky fallback may add media/text dynamically; uncertain sources stay PHP.
    if ($media === [] && $uri !== '' && function_exists('ap_quote_target_is_bluesky')
        && ap_quote_target_is_bluesky($uri)) {
        return true;
    }
    return $media === [] && $card === null && trim($text) !== '';
}

/** Complete supplied quote trees only; dynamic previews remain canonical PHP. */
function ap_native_quote_tree_requires_php(array $status, int $depth = 0): bool
{
    if ($depth > 16) {
        return true;
    }
    foreach (['reblog', 'vaak_quote_preview'] as $field) {
        if (is_array($status[$field] ?? null) && ap_native_quote_tree_requires_php($status[$field], $depth + 1)) {
            return true;
        }
    }
    $quoted = $status['quote']['quoted_status'] ?? null;
    return is_array($quoted) && (ap_native_quote_requires_php($quoted)
        || ap_native_quote_tree_requires_php($quoted, $depth + 1));
}
