<?php
/** Private transport state only; no source, audience, or action authority. */
declare(strict_types=1);
function ap_native_preview_exact_keys(array $value, array $expected): bool
{
    $keys = array_keys($value);
    sort($keys);
    sort($expected);
    return $keys === $expected;
}
function ap_native_preview_effective_budget(): int
{
    $raw = $GLOBALS['admin_status_link_preview_budget'] ?? null;
    return is_int($raw) ? $raw : 3;
}
function ap_native_preview_state_valid(mixed $state, int $initial, int $sourceCount): bool
{
    if (!is_array($state) || !ap_native_preview_exact_keys($state, ['initial', 'remaining', 'attempts'])
        || !is_int($state['initial']) || !is_int($state['remaining']) || !is_int($state['attempts'])
        || $state['initial'] !== $initial || $state['attempts'] < 0 || $state['attempts'] > $sourceCount) {
        return false;
    }
    if ($initial <= 0) {
        return $state['attempts'] === 0 && $state['remaining'] === $initial;
    }
    return $state['attempts'] <= $initial && $state['remaining'] === $initial - $state['attempts'];
}
/** Call only after every response card and field has passed validation. */
function ap_native_preview_commit_state(array $state): void
{
    if ($state['attempts'] > 0) {
        $GLOBALS['admin_status_link_preview_budget'] = $state['remaining'];
    }
}
