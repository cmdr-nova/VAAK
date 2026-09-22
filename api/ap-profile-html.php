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
