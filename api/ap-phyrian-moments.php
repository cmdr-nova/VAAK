<?php
declare(strict_types=1);

/**
 * Opt-in Phyrian moments.
 *
 * VAAK_PHYRIAN_MOMENTS is off unless set to 1/true/yes/on.
 * The game page checks that switch before this file is loaded. While it is
 * off, sharing does no database, Redis, or image work. A card image is
 * rendered only after the player confirms a post.
 *
 * Posts go out through the ordinary publisher as text plus one image with
 * alt text and a link back to the player's VAAK profile.
 */

const AP_PHYRIAN_MOMENTS_TEXT_MAX = 500;

function ap_phyrian_moments_enabled(): bool
{
    $raw = getenv('VAAK_PHYRIAN_MOMENTS');
    if (!is_string($raw)) {
        return false;
    }
    return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * @return array{memo: array<int, array{ok:bool, row:?array<string, mixed>}>, lookups:int}
 */
function &ap_phyrian_moments_state(): array
{
    static $state = ['memo' => [], 'lookups' => 0];
    return $state;
}

function ap_phyrian_moments_cache_reset(): void
{
    $state = &ap_phyrian_moments_state();
    $state['memo'] = [];
    $state['lookups'] = 0;
}

function ap_phyrian_moments_lookup_count(): int
{
    return (int) ap_phyrian_moments_state()['lookups'];
}

function ap_phyrian_moments_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ap_phyrian_moments_profile_url(string $actorKey): string
{
    $key = strtolower(trim($actorKey));
    if (!preg_match('/^[a-z0-9_]+$/', $key)) {
        return 'https://mkultra.monster/';
    }
    return 'https://mkultra.monster/users/' . $key;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function ap_phyrian_moments_normalize(array $row): array
{
    return [
        'status' => trim((string) ($row['status'] ?? '')),
        'strain' => trim((string) ($row['strain'] ?? '')),
        'resonance' => (int) ($row['resonance'] ?? 0),
        'generation' => (int) ($row['generation'] ?? 1),
        'level' => (int) ($row['level'] ?? 1),
        'banked_resonance' => (int) ($row['banked_resonance'] ?? 0),
        'resonance_exchanges' => (int) ($row['resonance_exchanges'] ?? 0),
        'inductions_given' => (int) ($row['inductions_given'] ?? 0),
        'daily_resonance_decay' => (int) ($row['daily_resonance_decay'] ?? 5),
    ];
}

function ap_phyrian_moments_imprinted(array $player): bool
{
    if (function_exists('ap_phyrian_player_is_imprinted')) {
        return ap_phyrian_player_is_imprinted($player);
    }
    return (string) ($player['status'] ?? '') === 'imprinted' && trim((string) ($player['strain'] ?? '')) !== '';
}

/**
 * @return array{ok:bool, row:?array<string, mixed>}
 */
function ap_phyrian_moments_snapshot(int $ownerUserId, ?PDO $db = null): array
{
    if (!ap_phyrian_moments_enabled() || $ownerUserId < 1) {
        return ['ok' => true, 'row' => null];
    }
    $state = &ap_phyrian_moments_state();
    if (isset($state['memo'][$ownerUserId])) {
        return $state['memo'][$ownerUserId];
    }
    if (!$db instanceof PDO) {
        if (!function_exists('ap_db')) {
            return ['ok' => false, 'row' => null];
        }
        try {
            $db = ap_db();
        } catch (Throwable $e) {
            return ['ok' => false, 'row' => null];
        }
    }
    try {
        $state['lookups']++;
        $st = $db->prepare(
            'SELECT status, strain, resonance, generation, level,
                    banked_resonance, resonance_exchanges, inductions_given,
                    daily_resonance_decay
             FROM phyrian_players
             WHERE owner_user_id = ?
             LIMIT 1'
        );
        $st->execute([$ownerUserId]);
        $fetched = $st->fetch(PDO::FETCH_ASSOC);
        $result = [
            'ok' => true,
            'row' => is_array($fetched) ? ap_phyrian_moments_normalize($fetched) : null,
        ];
    } catch (Throwable $e) {
        $result = ['ok' => false, 'row' => null];
        error_log('[phyrian-moments] lookup failed: ' . $e->getMessage());
    }
    $state['memo'][$ownerUserId] = $result;
    return $result;
}

/**
 * Suggested post for a moment the player can actually share. Null means
 * that moment is not available from the synced row.
 *
 * @param array<string, mixed>|null $player
 */
function ap_phyrian_moments_suggestion(string $kind, ?array $player, string $profileUrl): ?string
{
    $player ??= [
        'status' => '',
        'strain' => '',
        'resonance' => 0,
        'level' => 1,
        'resonance_exchanges' => 0,
        'inductions_given' => 0,
    ];
    $imprinted = ap_phyrian_moments_imprinted($player);
    $url = $profileUrl;
    if ($kind === 'card') {
        return 'My Phyrian Strains card. ' . $url;
    }
    if (!$imprinted) {
        return null;
    }
    if ($kind === 'level_up') {
        $level = (int) ($player['level'] ?? 1);
        if ($level < 2) {
            return null;
        }
        return 'Level ' . $level . ' in Phyrian Strains. ' . $url;
    }
    if ($kind === 'rare_find') {
        $strain = trim((string) ($player['strain'] ?? ''));
        if ($strain === '') {
            return null;
        }
        return 'Rare find: ' . $strain . '. ' . $url;
    }
    if ($kind === 'win') {
        if ((int) ($player['resonance_exchanges'] ?? 0) > 0) {
            return 'Won a resonance exchange in Phyrian Strains. ' . $url;
        }
        if ((int) ($player['inductions_given'] ?? 0) > 0) {
            return 'Imprinted another player in Phyrian Strains. ' . $url;
        }
        if ((int) ($player['resonance'] ?? 0) >= 50) {
            return 'Holding stable resonance in Phyrian Strains. ' . $url;
        }
        return null;
    }
    return null;
}

function ap_phyrian_moments_plain(string $text, string $profileUrl): string
{
    $text = trim(strip_tags(str_replace(["\r\n", "\r"], "\n", $text)));
    $text = preg_replace("/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/", '', $text) ?? $text;
    if ($profileUrl !== '' && !str_contains($text, $profileUrl)) {
        $room = AP_PHYRIAN_MOMENTS_TEXT_MAX - mb_strlen($profileUrl) - 1;
        if ($room < 1) {
            return mb_substr($profileUrl, 0, AP_PHYRIAN_MOMENTS_TEXT_MAX);
        }
        if (mb_strlen($text) > $room) {
            $text = rtrim(mb_substr($text, 0, $room));
        }
        $text = rtrim($text);
        return $text === '' ? $profileUrl : ($text . ' ' . $profileUrl);
    }
    if (mb_strlen($text) > AP_PHYRIAN_MOMENTS_TEXT_MAX) {
        $text = rtrim(mb_substr($text, 0, AP_PHYRIAN_MOMENTS_TEXT_MAX));
    }
    return $text;
}

/**
 * @param array<string, mixed>|null $player
 */
function ap_phyrian_moments_alt(?array $player, string $actorKey): string
{
    $handle = $actorKey !== '' ? ('@' . $actorKey) : 'a local player';
    if ($player === null || !ap_phyrian_moments_imprinted($player)) {
        return 'Phyrian Strains card for ' . $handle . '. Not imprinted yet.';
    }
    $stability = function_exists('ap_phyrian_stability') ? ap_phyrian_stability($player) : '';
    $alt = 'Phyrian Strains card for ' . $handle
        . '. Strain ' . trim((string) ($player['strain'] ?? ''))
        . ', level ' . (int) ($player['level'] ?? 1)
        . ', generation ' . (int) ($player['generation'] ?? 1)
        . ', resonance ' . (int) ($player['resonance'] ?? 0) . ' of 100';
    if ($stability !== '') {
        $alt .= ', ' . $stability;
    }
    return $alt . '.';
}

/**
 * @return array<string, list<string>>
 */
function ap_phyrian_moments_font(): array
{
    static $font = null;
    if (is_array($font)) {
        return $font;
    }
    $rows = [
        'A' => ['01110', '10001', '10001', '11111', '10001', '10001', '10001'],
        'B' => ['11110', '10001', '10001', '11110', '10001', '10001', '11110'],
        'C' => ['01111', '10000', '10000', '10000', '10000', '10000', '01111'],
        'D' => ['11110', '10001', '10001', '10001', '10001', '10001', '11110'],
        'E' => ['11111', '10000', '10000', '11110', '10000', '10000', '11111'],
        'F' => ['11111', '10000', '10000', '11110', '10000', '10000', '10000'],
        'G' => ['01111', '10000', '10000', '10111', '10001', '10001', '01111'],
        'H' => ['10001', '10001', '10001', '11111', '10001', '10001', '10001'],
        'I' => ['11111', '00100', '00100', '00100', '00100', '00100', '11111'],
        'J' => ['00111', '00010', '00010', '00010', '10010', '10010', '01100'],
        'K' => ['10001', '10010', '10100', '11000', '10100', '10010', '10001'],
        'L' => ['10000', '10000', '10000', '10000', '10000', '10000', '11111'],
        'M' => ['10001', '11011', '10101', '10101', '10001', '10001', '10001'],
        'N' => ['10001', '11001', '10101', '10011', '10001', '10001', '10001'],
        'O' => ['01110', '10001', '10001', '10001', '10001', '10001', '01110'],
        'P' => ['11110', '10001', '10001', '11110', '10000', '10000', '10000'],
        'Q' => ['01110', '10001', '10001', '10001', '10101', '10010', '01101'],
        'R' => ['11110', '10001', '10001', '11110', '10100', '10010', '10001'],
        'S' => ['01111', '10000', '10000', '01110', '00001', '00001', '11110'],
        'T' => ['11111', '00100', '00100', '00100', '00100', '00100', '00100'],
        'U' => ['10001', '10001', '10001', '10001', '10001', '10001', '01110'],
        'V' => ['10001', '10001', '10001', '10001', '10001', '01010', '00100'],
        'W' => ['10001', '10001', '10001', '10101', '10101', '10101', '01010'],
        'X' => ['10001', '10001', '01010', '00100', '01010', '10001', '10001'],
        'Y' => ['10001', '10001', '01010', '00100', '00100', '00100', '00100'],
        'Z' => ['11111', '00001', '00010', '00100', '01000', '10000', '11111'],
        '0' => ['01110', '10001', '10011', '10101', '11001', '10001', '01110'],
        '1' => ['00100', '01100', '00100', '00100', '00100', '00100', '01110'],
        '2' => ['01110', '10001', '00001', '00010', '00100', '01000', '11111'],
        '3' => ['11110', '00001', '00001', '01110', '00001', '00001', '11110'],
        '4' => ['00010', '00110', '01010', '10010', '11111', '00010', '00010'],
        '5' => ['11111', '10000', '10000', '11110', '00001', '00001', '11110'],
        '6' => ['01110', '10000', '10000', '11110', '10001', '10001', '01110'],
        '7' => ['11111', '00001', '00010', '00100', '01000', '01000', '01000'],
        '8' => ['01110', '10001', '10001', '01110', '10001', '10001', '01110'],
        '9' => ['01110', '10001', '10001', '01111', '00001', '00001', '01110'],
        ' ' => ['00000', '00000', '00000', '00000', '00000', '00000', '00000'],
        '.' => ['00000', '00000', '00000', '00000', '00000', '01100', '01100'],
        ':' => ['00000', '01100', '01100', '00000', '01100', '01100', '00000'],
        '/' => ['00001', '00010', '00010', '00100', '01000', '01000', '10000'],
        '-' => ['00000', '00000', '00000', '11111', '00000', '00000', '00000'],
        '+' => ['00000', '00100', '00100', '11111', '00100', '00100', '00000'],
        '#' => ['01010', '01010', '11111', '01010', '11111', '01010', '01010'],
        '@' => ['01110', '10001', '10111', '10101', '10111', '10000', '01110'],
        "'" => ['00100', '00100', '01000', '00000', '00000', '00000', '00000'],
        '(' => ['00010', '00100', '01000', '01000', '01000', '00100', '00010'],
        ')' => ['01000', '00100', '00010', '00010', '00010', '00100', '01000'],
    ];
    $font = $rows;
    return $font;
}

/**
 * @param list<string> $lines
 */
function ap_phyrian_moments_render_png(array $lines): string
{
    $scale = 2;
    $charW = 6 * $scale;
    $charH = 8 * $scale;
    $pad = 16;
    $width = 520;
    $height = $pad * 2 + 28 + count($lines) * ($charH + 6);
    $height = max(160, $height);
    $bg = [18, 16, 24];
    $rgb = str_repeat(chr($bg[0]) . chr($bg[1]) . chr($bg[2]), $width * $height);
    $set = static function (string &$buf, int $x, int $y, int $r, int $g, int $b) use ($width, $height): void {
        if ($x < 0 || $y < 0 || $x >= $width || $y >= $height) {
            return;
        }
        $i = ($y * $width + $x) * 3;
        $buf[$i] = chr($r);
        $buf[$i + 1] = chr($g);
        $buf[$i + 2] = chr($b);
    };
    for ($x = 8; $x < $width - 8; $x++) {
        $set($rgb, $x, 8, 94, 231, 255);
        $set($rgb, $x, $height - 9, 94, 231, 255);
    }
    for ($y = 8; $y < $height - 8; $y++) {
        $set($rgb, 8, $y, 94, 231, 255);
        $set($rgb, $width - 9, $y, 196, 90, 140);
    }
    $font = ap_phyrian_moments_font();
    $draw = static function (string &$buf, int $x, int $y, string $text, int $r, int $g, int $b) use ($set, $font, $scale, $width): void {
        $text = strtoupper($text);
        $cx = $x;
        $len = strlen($text);
        for ($n = 0; $n < $len; $n++) {
            $ch = $text[$n];
            $glyph = $font[$ch] ?? $font[' '];
            if ($cx + 6 * $scale >= $width - 16) {
                break;
            }
            foreach ($glyph as $gy => $row) {
                $cols = strlen($row);
                for ($gx = 0; $gx < $cols; $gx++) {
                    if ($row[$gx] !== '1') {
                        continue;
                    }
                    for ($sy = 0; $sy < $scale; $sy++) {
                        for ($sx = 0; $sx < $scale; $sx++) {
                            $set($buf, $cx + $gx * $scale + $sx, $y + $gy * $scale + $sy, $r, $g, $b);
                        }
                    }
                }
            }
            $cx += 6 * $scale;
        }
    };
    $draw($rgb, $pad, $pad, 'PHYRIAN STRAINS', 196, 90, 140);
    $y = $pad + 28;
    foreach ($lines as $line) {
        $draw($rgb, $pad, $y, $line, 230, 230, 230);
        $y += $charH + 6;
    }
    $raw = '';
    $stride = $width * 3;
    for ($yy = 0; $yy < $height; $yy++) {
        $raw .= "\x00" . substr($rgb, $yy * $stride, $stride);
    }
    $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
    $chunk = static function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };
    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', $ihdr)
        . $chunk('IDAT', gzcompress($raw, 9))
        . $chunk('IEND', '');
}

/**
 * @param array<string, mixed>|null $player
 * @return list<string>
 */
function ap_phyrian_moments_card_lines(?array $player, string $profileUrl): array
{
    if ($player === null || !ap_phyrian_moments_imprinted($player)) {
        return ['NOT IMPRINTED YET', 'START PLAYING ON VAAK', $profileUrl];
    }
    $stability = function_exists('ap_phyrian_stability') ? strtoupper(ap_phyrian_stability($player)) : '';
    $lines = [
        'STRAIN ' . strtoupper(trim((string) ($player['strain'] ?? ''))),
        'LEVEL ' . (int) ($player['level'] ?? 1) . '  GEN ' . (int) ($player['generation'] ?? 1),
        'RESONANCE ' . (int) ($player['resonance'] ?? 0) . '/100' . ($stability !== '' ? ('  ' . $stability) : ''),
        $profileUrl,
    ];
    return $lines;
}

/**
 * Share form. Uses the player row the game page already loaded. No lookup.
 *
 * @param array<string, mixed>|null $player
 */
function ap_phyrian_moments_hub_html(?array $player, string $actorKey, string $csrf): string
{
    if (!ap_phyrian_moments_enabled()) {
        return '';
    }
    $url = ap_phyrian_moments_profile_url($actorKey);
    $player = is_array($player) ? ap_phyrian_moments_normalize($player) : null;
    $kinds = [
        'level_up' => 'Level-up',
        'rare_find' => 'Rare find',
        'win' => 'Win',
        'card' => 'Post my Strains card',
    ];
    $blocks = [];
    foreach ($kinds as $kind => $label) {
        $suggestion = ap_phyrian_moments_suggestion($kind, $player, $url);
        if ($suggestion === null) {
            continue;
        }
        $blocks[] = '<form method="post" action="?view=phyrian" style="margin:0 0 .75rem">'
            . '<input type="hidden" name="csrf" value="' . ap_phyrian_moments_h($csrf) . '">'
            . '<input type="hidden" name="action" value="phyrian_moment_post">'
            . '<input type="hidden" name="moment_kind" value="' . ap_phyrian_moments_h($kind) . '">'
            . '<input type="hidden" name="confirm" value="1">'
            . '<label for="phyrian-moment-' . ap_phyrian_moments_h($kind) . '" style="display:block;margin:0 0 .35rem;color:var(--text)">'
            . ap_phyrian_moments_h($label) . '</label>'
            . '<textarea id="phyrian-moment-' . ap_phyrian_moments_h($kind) . '" name="moment_text" maxlength="'
            . (string) AP_PHYRIAN_MOMENTS_TEXT_MAX . '" required rows="2" style="width:100%;box-sizing:border-box">'
            . ap_phyrian_moments_h($suggestion) . '</textarea>'
            . '<div class="composer-actions"><span class="meta">Posts to your timeline with a card image. Nothing is posted until you confirm.</span>'
            . '<button class="btn btn-primary" type="submit">Post this moment</button></div>'
            . '</form>';
    }
    if ($blocks === []) {
        return '';
    }
    return '<section class="phyrian-moments" aria-label="Share a Phyrian moment" style="margin:1rem 0;padding:.85rem;border:1px solid var(--border);border-radius:12px">'
        . '<h3 class="phyrian-section-title" style="margin-top:0">Share a moment</h3>'
        . '<p class="meta" style="margin:0 0 .75rem">Confirm the text below. VAAK attaches a picture of your Strains card so followers on other servers and Bluesky can see it, and links back to your profile.</p>'
        . implode('', $blocks)
        . '</section>';
}

/**
 * @param callable(string,string,string):array{ok:bool,error?:string}|null $deliver
 * @return array{ok:bool,error?:string,text?:string,alt?:string}
 */
function ap_phyrian_moments_publish(
    int $ownerUserId,
    string $actorKey,
    string $kind,
    string $text,
    bool $confirmed,
    string $csrf = '',
    ?PDO $db = null,
    ?callable $deliver = null
): array {
    if (!ap_phyrian_moments_enabled()) {
        return ['ok' => false, 'error' => 'Phyrian moments are turned off.'];
    }
    if (function_exists('ap_auth_csrf_ok') && !ap_auth_csrf_ok($csrf)) {
        return ['ok' => false, 'error' => 'Session expired — refresh the page and try again.'];
    }
    if (!$confirmed) {
        return ['ok' => false, 'error' => 'Confirm the post before sharing it.'];
    }
    if (!in_array($kind, ['level_up', 'rare_find', 'win', 'card'], true)) {
        return ['ok' => false, 'error' => 'Unknown moment.'];
    }
    $profileUrl = ap_phyrian_moments_profile_url($actorKey);
    $plain = ap_phyrian_moments_plain($text, $profileUrl);
    if (trim(str_replace($profileUrl, '', $plain)) === '') {
        return ['ok' => false, 'error' => 'Write a short note before posting.'];
    }
    if ($ownerUserId < 1) {
        return ['ok' => false, 'error' => 'Not signed in.'];
    }
    $snap = ap_phyrian_moments_snapshot($ownerUserId, $db);
    if (empty($snap['ok'])) {
        return ['ok' => false, 'error' => 'Phyrian Strains is unavailable.'];
    }
    $player = $snap['row'];
    if (ap_phyrian_moments_suggestion($kind, $player, $profileUrl) === null) {
        return ['ok' => false, 'error' => 'That moment is not available from your synced Strains stats.'];
    }
    $alt = ap_phyrian_moments_alt($player, $actorKey);
    $png = ap_phyrian_moments_render_png(ap_phyrian_moments_card_lines($player, $profileUrl));
    if ($png === '' || !str_starts_with($png, "\x89PNG")) {
        return ['ok' => false, 'error' => 'Could not render the Strains card.'];
    }
    $send = $deliver ?? static function (string $body, string $image, string $imageAlt): array {
        return ap_phyrian_moments_deliver($body, $image, $imageAlt);
    };
    $sent = $send($plain, $png, $alt);
    if (empty($sent['ok'])) {
        return ['ok' => false, 'error' => (string) ($sent['error'] ?? 'Could not post that moment.')];
    }
    return ['ok' => true, 'text' => $plain, 'alt' => $alt];
}

/**
 * Store the rendered card with the same media row shape as an upload, then
 * publish an ordinary post. Called only after the player confirms.
 *
 * @return array{ok:bool,error?:string}
 */
function ap_phyrian_moments_deliver(string $text, string $png, string $alt): array
{
    if (!function_exists('ap_r2_put_object') || !function_exists('ap_db') || !function_exists('ap_local_post_reply')) {
        return ['ok' => false, 'error' => 'Posting is unavailable.'];
    }
    $key = 'media/' . gmdate('Y/m/d') . '/phyrian-card-' . bin2hex(random_bytes(8)) . '.png';
    $put = ap_r2_put_object($key, $png, 'image/png');
    if (empty($put['ok'])) {
        return ['ok' => false, 'error' => (string) ($put['error'] ?? 'Could not store the card image.')];
    }
    $width = 0;
    $height = 0;
    if (function_exists('getimagesizefromstring')) {
        $info = @getimagesizefromstring($png);
        $width = is_array($info) ? (int) ($info[0] ?? 0) : 0;
        $height = is_array($info) ? (int) ($info[1] ?? 0) : 0;
    }
    $owner = function_exists('ap_db_default_owner_user_id') ? (int) ap_db_default_owner_user_id() : 0;
    if ($owner < 1) {
        return ['ok' => false, 'error' => 'Not signed in.'];
    }
    $desc = mb_substr(trim($alt), 0, 1500);
    try {
        ap_db()->prepare(
            'INSERT INTO masto_media (owner_user_id, created_at, s3_key, public_url, preview_url, media_type, mime, file_size, width, height, description)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $owner,
            function_exists('ap_db_now') ? ap_db_now() : gmdate('c'),
            (string) ($put['key'] ?? $key),
            (string) ($put['public_url'] ?? ''),
            (string) ($put['public_url'] ?? ''),
            'image',
            'image/png',
            strlen($png),
            $width > 0 ? $width : null,
            $height > 0 ? $height : null,
            $desc,
        ]);
        $localId = function_exists('ap_db_last_insert_id') ? ap_db_last_insert_id('masto_media', 'local_id') : (int) ap_db()->lastInsertId();
        if ($localId < 1) {
            return ['ok' => false, 'error' => 'Could not attach the card image.'];
        }
        $posted = ap_local_post_reply($text, '', null, '', false, null, [$localId], 'public');
    } catch (Throwable $e) {
        error_log('[phyrian-moments] post failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not post that moment.'];
    }
    if (empty($posted['ok'])) {
        return ['ok' => false, 'error' => (string) ($posted['error'] ?? 'Could not post that moment.')];
    }
    return ['ok' => true];
}
