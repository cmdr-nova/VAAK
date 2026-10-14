<?php
declare(strict_types=1);

/**
 * Opt-in Phyrian Strains card for local VAAK profiles.
 *
 * VAAK_PHYRIAN_PROFILE_CARD is off unless set to 1/true/yes/on.
 * Callers must check {@see ap_phyrian_profile_card_enabled()} before this
 * file is loaded. While the switch is off, nothing here runs, so a profile
 * request does no extra database or Redis work.
 *
 * {@see ap_phyrian_profile_card_html()} is the only markup helper. Native
 * profile rendering can call it with an already loaded player row.
 * {@see ap_phyrian_profile_card_markup()} is the page entry: it re-checks
 * the switch, then reads at most one primary-key row from phyrian_players
 * and caches that row. It does not migrate, sync, or write.
 */

require_once __DIR__ . '/ap-phyrian.php';

const AP_PHYRIAN_PROFILE_CARD_TTL = 60;

function ap_phyrian_profile_card_enabled(): bool
{
    $raw = getenv('VAAK_PHYRIAN_PROFILE_CARD');
    if (!is_string($raw)) {
        return false;
    }
    return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * @return array{memo: array<int, array{expires:int, ok:bool, row:?array<string, mixed>}>, lookups:int}
 */
function &ap_phyrian_profile_card_state(): array
{
    static $state = [
        'memo' => [],
        'lookups' => 0,
    ];
    return $state;
}

function ap_phyrian_profile_card_cache_reset(): void
{
    $state = &ap_phyrian_profile_card_state();
    $state['memo'] = [];
    $state['lookups'] = 0;
}

function ap_phyrian_profile_card_lookup_count(): int
{
    $state = &ap_phyrian_profile_card_state();
    return (int) $state['lookups'];
}

function ap_phyrian_profile_card_cache_key(int $ownerUserId): string
{
    return 'vaak:phyrian:profile-card:v1:' . $ownerUserId;
}

function ap_phyrian_profile_card_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function ap_phyrian_profile_card_normalize(array $row): array
{
    $depth = $row['lineage_depth'] ?? null;
    return [
        'status' => trim((string) ($row['status'] ?? '')),
        'strain' => trim((string) ($row['strain'] ?? '')),
        'resonance' => (int) ($row['resonance'] ?? 0),
        'generation' => (int) ($row['generation'] ?? 1),
        'level' => (int) ($row['level'] ?? 1),
        'banked_resonance' => (int) ($row['banked_resonance'] ?? 0),
        'resonance_exchanges' => (int) ($row['resonance_exchanges'] ?? 0),
        'inductions_given' => (int) ($row['inductions_given'] ?? 0),
        'lineage_depth' => ($depth === null || $depth === '') ? null : (int) $depth,
        'daily_resonance_decay' => (int) ($row['daily_resonance_decay'] ?? (defined('AP_PHYRIAN_DAILY_DECAY') ? AP_PHYRIAN_DAILY_DECAY : 5)),
    ];
}

/**
 * Primary-key read of an already synced player row.
 * Returns ok=false only when the read itself fails. A missing row is ok with row=null.
 *
 * @return array{ok:bool, row:?array<string, mixed>}
 */
function ap_phyrian_profile_card_snapshot(int $ownerUserId, ?PDO $db = null): array
{
    if (!ap_phyrian_profile_card_enabled() || $ownerUserId < 1) {
        return ['ok' => true, 'row' => null];
    }
    $state = &ap_phyrian_profile_card_state();
    $cached = $state['memo'][$ownerUserId] ?? null;
    if (is_array($cached) && (int) ($cached['expires'] ?? 0) >= time()) {
        return ['ok' => (bool) $cached['ok'], 'row' => $cached['row']];
    }
    $key = ap_phyrian_profile_card_cache_key($ownerUserId);
    if (function_exists('ap_redis_json_get')) {
        $shared = ap_redis_json_get($key);
        if (is_array($shared) && array_key_exists('found', $shared)) {
            $row = (!empty($shared['found']) && is_array($shared['row'] ?? null))
                ? ap_phyrian_profile_card_normalize($shared['row'])
                : null;
            $state['memo'][$ownerUserId] = ['expires' => time() + AP_PHYRIAN_PROFILE_CARD_TTL, 'ok' => true, 'row' => $row];
            return ['ok' => true, 'row' => $row];
        }
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
                    lineage_depth, daily_resonance_decay
             FROM phyrian_players
             WHERE owner_user_id = ?
             LIMIT 1'
        );
        $st->execute([$ownerUserId]);
        $fetched = $st->fetch(PDO::FETCH_ASSOC);
        $row = is_array($fetched) ? ap_phyrian_profile_card_normalize($fetched) : null;
    } catch (Throwable $e) {
        $state['memo'][$ownerUserId] = ['expires' => time() + AP_PHYRIAN_PROFILE_CARD_TTL, 'ok' => false, 'row' => null];
        error_log('[phyrian-profile-card] lookup failed: ' . $e->getMessage());
        return ['ok' => false, 'row' => null];
    }
    $state['memo'][$ownerUserId] = ['expires' => time() + AP_PHYRIAN_PROFILE_CARD_TTL, 'ok' => true, 'row' => $row];
    if (function_exists('ap_redis_json_set')) {
        ap_redis_json_set($key, ['found' => $row !== null, 'row' => $row], AP_PHYRIAN_PROFILE_CARD_TTL);
    }
    return ['ok' => true, 'row' => $row];
}

/**
 * Card HTML from an already loaded row. Null means this local user has no player row.
 * No database or Redis work.
 *
 * @param array<string, mixed>|null $row
 * @param array<string, mixed> $context
 */
function ap_phyrian_profile_card_html(?array $row, array $context = []): string
{
    $ownerUserId = (int) ($context['owner_user_id'] ?? 0);
    $isSelf = !empty($context['is_self']);
    $player = $row === null ? [
        'status' => '',
        'strain' => '',
        'resonance' => 0,
        'level' => 1,
    ] : $row;
    $imprinted = function_exists('ap_phyrian_player_is_imprinted')
        ? ap_phyrian_player_is_imprinted($player)
        : ((string) ($player['status'] ?? '') === 'imprinted' && trim((string) ($player['strain'] ?? '')) !== '');
    $maxResonance = defined('AP_PHYRIAN_MAX_RESONANCE') ? (int) AP_PHYRIAN_MAX_RESONANCE : 100;
    $maxLevel = defined('AP_PHYRIAN_MAX_LEVEL') ? (int) AP_PHYRIAN_MAX_LEVEL : 80;
    $parts = [];
    $parts[] = '<span class="phyrian-profile-card-title" style="color:var(--text);font-weight:650;letter-spacing:.04em;text-transform:uppercase;font-size:.72rem">Phyrian Strains</span>';
    if ($imprinted) {
        $isOrigin = $ownerUserId > 0 && function_exists('ap_phyrian_is_origin_owner') && ap_phyrian_is_origin_owner($ownerUserId);
        $stability = function_exists('ap_phyrian_stability') ? ap_phyrian_stability($player) : 'Stable';
        $rank = function_exists('ap_phyrian_rank_title') ? ap_phyrian_rank_title($player, $isOrigin) : '';
        $parts[] = '<span class="phyrian-badge">Imprinted</span>';
        if ($isOrigin) {
            $parts[] = '<span class="phyrian-badge phyrian-badge--origin">Origin</span>';
        }
        $parts[] = '<span class="who">' . ap_phyrian_profile_card_h((string) ($player['strain'] ?? '')) . '</span>';
        $parts[] = '<span>' . ap_phyrian_profile_card_h($stability) . '</span>';
        $parts[] = '<span>Gen <b>' . (int) ($player['generation'] ?? 1) . '</b></span>';
        $parts[] = '<span>Level <b>' . (int) ($player['level'] ?? 1) . '</b>/' . $maxLevel . '</span>';
        if ($rank !== '') {
            $parts[] = '<span>Rank <b>' . ap_phyrian_profile_card_h($rank) . '</b></span>';
        }
        $parts[] = '<span>Resonance <b>' . (int) ($player['resonance'] ?? 0) . '</b>/' . $maxResonance . '</span>';
        $banked = (int) ($player['banked_resonance'] ?? 0);
        if ($banked > 0) {
            $parts[] = '<span>Banked <b>' . $banked . '</b></span>';
        }
        $parts[] = '<span>Decay <b>−' . (int) ($player['daily_resonance_decay'] ?? 0) . '</b>/day</span>';
        $exchanges = (int) ($player['resonance_exchanges'] ?? 0);
        if ($exchanges > 0) {
            $parts[] = '<span>Exchanges <b>' . $exchanges . '</b></span>';
        }
        $inductions = (int) ($player['inductions_given'] ?? 0);
        if ($inductions > 0) {
            $parts[] = '<span>Inductions <b>' . $inductions . '</b></span>';
        }
        $depth = $player['lineage_depth'] ?? null;
        if ($depth !== null && (int) $depth > 0) {
            $parts[] = '<span>Lineage <b>' . (int) $depth . '</b></span>';
        }
    } else {
        $parts[] = '<span class="phyrian-badge phyrian-badge--unknown">Unmarked</span>';
        $parts[] = '<span>Not imprinted yet.</span>';
        if ($isSelf) {
            $parts[] = '<span>Start playing to receive a strain.</span>';
        }
        if ($row !== null) {
            $parts[] = '<span>Level <b>' . (int) ($player['level'] ?? 1) . '</b></span>';
            $parts[] = '<span>Resonance <b>' . (int) ($player['resonance'] ?? 0) . '</b>/' . $maxResonance . '</span>';
        }
    }
    if (!empty($context['opensim_linked'])) {
        $avatar = trim((string) ($context['avatar_name'] ?? ''));
        $parts[] = $avatar !== ''
            ? '<span>OpenSim linked as <b>' . ap_phyrian_profile_card_h($avatar) . '</b></span>'
            : '<span>OpenSim linked</span>';
    }
    $href = '';
    $label = '';
    if ($isSelf) {
        $href = '?view=phyrian';
        $label = $imprinted ? 'Open game' : 'Start playing';
    } elseif ($imprinted && $ownerUserId > 0) {
        $href = '?view=phyrian&page=dossier&owner=' . $ownerUserId;
        $label = 'Readout';
    }
    if ($href !== '' && $label !== '') {
        $parts[] = '<a href="' . ap_phyrian_profile_card_h($href) . '" style="margin-left:auto;color:var(--primary);text-decoration:none">'
            . ap_phyrian_profile_card_h($label) . '</a>';
    }
    $style = 'display:flex;flex-wrap:wrap;align-items:center;gap:.35rem .85rem;width:100%;box-sizing:border-box;'
        . 'margin:.85rem 0 0;padding:.5rem .7rem;border-radius:12px;'
        . 'border:1px solid color-mix(in srgb, #5ee7ff 28%, var(--border));'
        . 'background:linear-gradient(160deg, rgba(94,231,255,.05), transparent 55%), var(--panel);'
        . 'color:var(--muted);font-size:.85rem;line-height:1.35;overflow-wrap:anywhere';
    return '<section class="phyrian-profile-card" data-phyrian-card="' . ($imprinted ? 'imprinted' : 'unmarked') . '" aria-label="Phyrian Strains" style="'
        . $style . '">' . implode('', $parts) . '</section>' . "\n";
}

/**
 * Local-profile entry. Returns '' unless the switch is on and the subject is local.
 * A local user with no player row still gets the unmarked invitation card.
 *
 * @param array<string, mixed> $context
 */
function ap_phyrian_profile_card_markup(bool $isLocal, int $ownerUserId, array $context = [], ?PDO $db = null): string
{
    if (!$isLocal || !ap_phyrian_profile_card_enabled()) {
        return '';
    }
    $context['owner_user_id'] = $ownerUserId;
    if ($ownerUserId < 1) {
        return ap_phyrian_profile_card_html(null, $context);
    }
    $snap = ap_phyrian_profile_card_snapshot($ownerUserId, $db);
    if (empty($snap['ok'])) {
        return '';
    }
    return ap_phyrian_profile_card_html($snap['row'] ?? null, $context);
}
