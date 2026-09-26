<?php
/** Shared HTML-profile helpers used by every local account renderer. */
declare(strict_types=1);

if (!function_exists('ap_profile_html_share_meta')) {
    /** @return array<string,string> */
    function ap_profile_html_share_meta(string $actorKey, array $row, array $note, array $profile): array
    {
        $name = trim((string) ($profile['name'] ?? '')) ?: ('@' . $actorKey);
        $plain = trim(html_entity_decode(strip_tags((string) ($note['content'] ?? $row['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $plain = preg_replace('/\s+/u', ' ', $plain) ?? $plain;
        if ($plain === '') {
            $plain = trim((string) ($note['summary'] ?? ''));
        }
        if (function_exists('mb_strlen') && mb_strlen($plain) > 220) {
            $plain = mb_substr($plain, 0, 217) . '…';
        } elseif (strlen($plain) > 220) {
            $plain = substr($plain, 0, 217) . '…';
        }
        $rowId = rtrim((string) ($row['id'] ?? ''), '/');
        $url = '';
        if (preg_match('#/notes/([a-f0-9]+)$#i', $rowId, $m)) {
            $url = 'https://mkultra.monster/users/' . rawurlencode($actorKey) . '/notes/' . strtolower($m[1]);
        } elseif ($rowId !== '' && str_starts_with($rowId, 'https://')) {
            $url = $rowId;
        }
        $image = '';
        $attachments = $note['attachment'] ?? [];
        if (is_array($attachments) && isset($attachments['type'])) {
            $attachments = [$attachments];
        }
        if (is_array($attachments)) {
            foreach ($attachments as $attachment) {
                if (!is_array($attachment)) {
                    continue;
                }
                $mediaType = strtolower((string) ($attachment['mediaType'] ?? ''));
                $candidate = is_string($attachment['url'] ?? null)
                    ? (string) $attachment['url']
                    : (is_array($attachment['url'] ?? null) ? (string) ($attachment['url']['href'] ?? '') : '');
                if (!str_starts_with($candidate, 'https://') && is_string($attachment['preview'] ?? null)) {
                    $candidate = (string) $attachment['preview'];
                }
                if ($candidate !== '' && str_starts_with($candidate, 'https://')
                    && (str_starts_with($mediaType, 'image/') || $mediaType === '')) {
                    $image = $candidate;
                    break;
                }
            }
        }
        if ($image === '') {
            $image = trim((string) ($profile['icon_url'] ?? ''));
        }
        return [
            'title' => $name . ' · VAAK',
            'description' => $plain !== '' ? $plain : 'A post from ' . $name . ' on VAAK.',
            'url' => $url,
            'image' => $image,
        ];
    }
}

if (!function_exists('ap_profile_html_meta_tags')) {
    /** @param array<string,string> $meta */
    function ap_profile_html_meta_tags(array $meta): string
    {
        $title = trim((string) ($meta['title'] ?? ''));
        $description = trim((string) ($meta['description'] ?? ''));
        $url = trim((string) ($meta['url'] ?? ''));
        $image = trim((string) ($meta['image'] ?? ''));
        $html = '';
        if ($title !== '') {
            $safe = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<meta property="og:title" content="' . $safe . '"><meta name="twitter:title" content="' . $safe . '">';
        }
        if ($description !== '') {
            $safe = htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<meta name="description" content="' . $safe . '"><meta property="og:description" content="' . $safe . '"><meta name="twitter:description" content="' . $safe . '">';
        }
        if ($url !== '' && str_starts_with($url, 'https://')) {
            $safe = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<link rel="canonical" href="' . $safe . '"><meta property="og:url" content="' . $safe . '">';
        }
        $html .= '<meta property="og:type" content="article"><meta name="twitter:card" content="' . ($image !== '' ? 'summary_large_image' : 'summary') . '">';
        if ($image !== '' && str_starts_with($image, 'https://')) {
            $safe = htmlspecialchars($image, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<meta property="og:image" content="' . $safe . '"><meta name="twitter:image" content="' . $safe . '">';
        }
        return $html;
    }
}

if (!function_exists('ap_profile_quote_card_html')) {
    /**
     * Full quote card for public HTML profiles: text + media + link preview.
     * Cache-first; warms missing remotes/previews in the background.
     */
    function ap_profile_quote_card_html(string $quoteUrl, bool $allowNestedLinks = true): string
    {
        $quoteUrl = rtrim(trim($quoteUrl), '/');
        if ($quoteUrl === '' || !str_starts_with($quoteUrl, 'https://')) {
            return '';
        }
        if (!function_exists('ap_link_preview_html')) {
            require_once __DIR__ . '/ap-link-preview.php';
        }
        if (!function_exists('ap_masto_lookup_status_by_object_url')) {
            require_once __DIR__ . '/ap-masto-entities.php';
        }

        $acct = '';
        $text = '';
        $mediaHtml = '';
        $cardHtml = '';
        $openUrl = $quoteUrl;

        $isBsky = str_contains($quoteUrl, 'bsky.app/')
            || str_starts_with($quoteUrl, 'at://')
            || str_contains($quoteUrl, 'bsky.brid.gy');
        if ($isBsky && function_exists('ap_bsky_post_preview_from_url')) {
            $owner = function_exists('ap_db_masto_owner_user_id') ? (int) ap_db_masto_owner_user_id() : 0;
            $prev = ap_bsky_post_preview_from_url($quoteUrl, $owner, false);
            if ($prev === null && function_exists('ap_bsky_post_preview_warm_enqueue')) {
                ap_bsky_post_preview_warm_enqueue($quoteUrl, $owner);
            }
            if (is_array($prev)) {
                $acct = trim((string) ($prev['handle'] ?? ''));
                $text = trim((string) ($prev['text'] ?? ''));
                $openUrl = (string) ($prev['url'] ?? $quoteUrl);
                if (function_exists('ap_bsky_normalize_web_url') && str_starts_with($openUrl, 'https://bsky.app/')) {
                    $openUrl = ap_bsky_normalize_web_url($openUrl);
                }
                if (!empty($prev['media']) && is_array($prev['media'])) {
                    // Build a simple media strip for Bluesky embeds.
                    $cells = [];
                    foreach ($prev['media'] as $m) {
                        if (!is_array($m)) {
                            continue;
                        }
                        $u = (string) ($m['url'] ?? '');
                        if ($u === '' || !str_starts_with($u, 'https://')) {
                            continue;
                        }
                        $safe = htmlspecialchars($u, ENT_QUOTES, 'UTF-8');
                        $poster = (string) ($m['preview_url'] ?? '');
                        $mt = strtolower((string) ($m['mediaType'] ?? ''));
                        if (str_contains($mt, 'video') || str_contains($mt, 'mpegURL') || preg_match('/\.(mp4|m3u8|webm)(\?|$)/i', $u)) {
                            $p = str_starts_with($poster, 'https://') ? ' poster="' . htmlspecialchars($poster, ENT_QUOTES, 'UTF-8') . '"' : '';
                            $cells[] = '<video class="media-video" src="' . $safe . '" controls playsinline preload="metadata"' . $p . ' referrerpolicy="no-referrer"></video>';
                        } else {
                            $cells[] = '<img src="' . $safe . '" alt="" loading="lazy" referrerpolicy="no-referrer">';
                        }
                        if (count($cells) >= 4) {
                            break;
                        }
                    }
                    if ($cells !== []) {
                        $mediaHtml = '<div class="media-row media-count-' . count($cells) . '" style="margin-top:.45rem">' . implode('', $cells) . '</div>';
                    }
                }
                if (is_array($prev['card'] ?? null) && function_exists('ap_link_preview_html')) {
                    $cardHtml = ap_link_preview_html(array_merge($prev['card'], ['status' => 'ok']), $allowNestedLinks);
                } elseif (is_array($prev['external'] ?? null) && function_exists('ap_link_preview_html')) {
                    $ext = $prev['external'];
                    $cardHtml = ap_link_preview_html([
                        'url' => (string) ($ext['uri'] ?? ''),
                        'title' => (string) ($ext['title'] ?? ''),
                        'description' => (string) ($ext['description'] ?? ''),
                        'image' => (string) ($ext['thumb'] ?? ''),
                        'status' => 'ok',
                    ], $allowNestedLinks);
                }
            }
        } else {
            $st = function_exists('ap_masto_lookup_status_by_object_url')
                ? ap_masto_lookup_status_by_object_url($quoteUrl, 0, false)
                : null;
            if ($st === null && function_exists('ap_quote_target_warm_async')) {
                ap_quote_target_warm_async($quoteUrl);
            }
            if (is_array($st)) {
                $acct = (string) ($st['account']['acct'] ?? '');
                $htmlContent = (string) ($st['content'] ?? '');
                $text = function_exists('ap_html_to_plain_text')
                    ? trim(ap_html_to_plain_text($htmlContent))
                    : trim(html_entity_decode(strip_tags($htmlContent), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $openUrl = (string) ($st['url'] ?? $st['uri'] ?? $quoteUrl);
                // Rebuild a note-shaped object for the shared media renderer.
                $noteLike = [
                    'attachment' => [],
                ];
                foreach (is_array($st['media_attachments'] ?? null) ? $st['media_attachments'] : [] as $att) {
                    if (!is_array($att)) {
                        continue;
                    }
                    $u = (string) ($att['url'] ?? '');
                    if ($u === '' || !str_starts_with($u, 'https://')) {
                        continue;
                    }
                    $type = (string) ($att['type'] ?? 'image');
                    $mt = $type === 'video' || $type === 'gifv' ? 'video/mp4'
                        : ($type === 'audio' ? 'audio/mpeg' : 'image/jpeg');
                    if (!empty($att['preview_url']) && is_string($att['preview_url'])) {
                        // keep
                    }
                    $noteLike['attachment'][] = [
                        'type' => $type === 'video' || $type === 'gifv' ? 'Video' : ($type === 'audio' ? 'Audio' : 'Image'),
                        'mediaType' => $mt,
                        'url' => $u,
                        'thumbnail' => (string) ($att['preview_url'] ?? ''),
                    ];
                }
                if ($noteLike['attachment'] !== [] && function_exists('ap_user_note_media_html')) {
                    $mediaHtml = ap_user_note_media_html($noteLike, $allowNestedLinks);
                }
                if (is_array($st['card'] ?? null) && function_exists('ap_link_preview_html')) {
                    $cardHtml = ap_link_preview_html(array_merge($st['card'], ['status' => 'ok']), $allowNestedLinks);
                }
            } elseif (function_exists('ap_cmdr_object_snippet')) {
                $text = ap_cmdr_object_snippet($quoteUrl, 500);
                if ($text !== '' && str_starts_with($text, 'https://') && !str_contains($text, ' ')) {
                    $text = '';
                }
            }
        }

        if ($cardHtml === '' && $mediaHtml === '' && $text !== '' && function_exists('ap_link_preview_card_for_status_text')) {
            $card = ap_link_preview_card_for_status_text($text, false, false);
            if ($card === null) {
                $budget = &$GLOBALS['ap_profile_quote_link_budget'];
                if (!isset($budget) || !is_int($budget)) {
                    $budget = 2;
                }
                if ($budget > 0) {
                    $budget--;
                    $card = ap_link_preview_card_for_status_text($text, false, true);
                }
            }
            if ($card === null && function_exists('ap_link_preview_extract_url') && function_exists('ap_link_preview_warm_async')) {
                $warm = ap_link_preview_extract_url($text);
                if (is_string($warm) && $warm !== '') {
                    ap_link_preview_warm_async($warm);
                }
            }
            if (is_array($card) && function_exists('ap_link_preview_html')) {
                $cardHtml = ap_link_preview_html(array_merge($card, ['status' => 'ok']), $allowNestedLinks);
            }
        }

        $html = '<div class="quote-block"><span class="qt-label">Quoted</span>';
        if ($acct !== '') {
            $html .= '<div class="meta" style="margin-top:.3rem">@'
                . htmlspecialchars(ltrim($acct, '@'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
        }
        if ($text !== '') {
            $html .= '<div style="margin-top:.25rem;white-space:pre-wrap">'
                . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</div>';
        }
        if ($mediaHtml !== '') {
            $html .= $mediaHtml;
        }
        if ($cardHtml !== '') {
            $html .= '<div class="quote-link-card" style="margin-top:.45rem">' . $cardHtml . '</div>';
        }
        if ($openUrl !== '' && $openUrl !== 'https://bsky.app/') {
            $safe = htmlspecialchars($openUrl, ENT_QUOTES, 'UTF-8');
            if ($allowNestedLinks) {
                $html .= '<div class="muted" style="margin-top:.4rem;font-size:.75rem"><a href="'
                    . $safe . '" target="_blank" rel="noopener noreferrer">Open original</a></div>';
            } else {
                $html .= '<div class="muted qt-url" style="margin-top:.4rem;font-size:.75rem">' . $safe . '</div>';
            }
        } elseif ($text === '' && $mediaHtml === '' && $cardHtml === '') {
            $html .= '<div class="meta" style="margin-top:.3rem">Quoted post unavailable</div>';
        }
        $html .= '</div>';
        return $html;
    }
}
