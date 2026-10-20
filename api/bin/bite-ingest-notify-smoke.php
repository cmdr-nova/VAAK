<?php
declare(strict_types=1);
/**
 * Inbound bites land in the bitten account's notifications: local accounts
 * biting each other notify, only a self-bite is ignored, and a bite on the
 * shared inbox goes to the target's owner rather than the default account.
 * No database or network; storage functions are stubbed.
 */
$api = getenv('VAAK_BITE_INGEST_API') ?: dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}
stream_wrapper_unregister('http');
stream_wrapper_unregister('https');

$GLOBALS['stored'] = [];
$GLOBALS['mentions'] = [];
$GLOBALS['users'] = [
    'https://mkultra.monster/users/cmdr_nova' => ['id' => 1, 'actor_id' => 'https://mkultra.monster/users/cmdr_nova'],
    'https://mkultra.monster/users/shana_flux' => ['id' => 7, 'actor_id' => 'https://mkultra.monster/users/shana_flux'],
];
function ap_as_id(mixed $v): ?string { return is_string($v) ? $v : (is_array($v) && is_string($v['id'] ?? null) ? $v['id'] : null); }
function ap_bite_store(array $row): int { $GLOBALS['stored'][] = $row; return count($GLOBALS['stored']); }
function ap_mention_store(array $row): void { $GLOBALS['mentions'][] = $row; }
function ap_local_user_by_actor_id(string $a): ?array { return $GLOBALS['users'][rtrim($a, '/')] ?? null; }
function ap_mention_interaction_object_id(string $t, string $a, string $k): string { return rtrim($t, '/') . '#' . $k . '-' . substr(hash('sha256', "$a|$t|$k"), 0, 12); }

reference_load($api . '/ap-bites.php', ['ap_bite_guess_kind', 'ap_bite_target_owner', 'ap_bite_ingest']);

$checks = 0;
function bite_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}
function bite(string $actor, string $target): array
{
    $GLOBALS['mentions'] = [];
    return ap_bite_ingest(['id' => $actor . '/bites/' . bin2hex(random_bytes(4)), 'type' => 'Bite', 'actor' => $actor, 'target' => $target]);
}

$cmdr = 'https://mkultra.monster/users/cmdr_nova';
$shana = 'https://mkultra.monster/users/shana_flux';
$remote = 'https://wafrn.example/fediverse/blog/alice';

$r = bite($remote, $cmdr);
bite_check(!empty($r['ok']) && count($GLOBALS['mentions']) === 1, 'remote user bite notifies');
bite_check($GLOBALS['mentions'][0]['activity_type'] === 'Bite' && $GLOBALS['mentions'][0]['owner_user_id'] === 1, 'stored as a Bite for cmdr_nova');
bite_check(str_contains($GLOBALS['mentions'][0]['object_id'], '/bites-received/'), 'user bite uses a synthetic object');

$r = bite($remote, $shana);
bite_check(!empty($r['ok']) && ($GLOBALS['mentions'][0]['owner_user_id'] ?? 0) === 7, 'shared-inbox bite notifies the bitten account, not the default one');
bite_check($GLOBALS['mentions'][0]['owner_actor_id'] === $shana, 'owner actor is the bitten account');

$r = bite($shana, $cmdr);
bite_check(!empty($r['ok']) && ($GLOBALS['mentions'][0]['owner_user_id'] ?? 0) === 1, 'local account biting another local account notifies');

$r = bite($cmdr, $shana . '/notes/abc');
bite_check(!empty($r['ok']) && ($GLOBALS['mentions'][0]['owner_user_id'] ?? 0) === 7, 'local post bite notifies the author');
bite_check(($GLOBALS['mentions'][0]['in_reply_to'] ?? '') === $shana . '/notes/abc', 'post bite points at the bitten post');

$r = bite($cmdr, $cmdr);
bite_check(empty($r['ok']) && $GLOBALS['mentions'] === [], 'self bite is ignored');
$r = bite($cmdr, $cmdr . '/notes/abc');
bite_check(empty($r['ok']) && $GLOBALS['mentions'] === [], 'own post bite is ignored');

$r = bite($remote, 'https://mkultra.monster/users/nobody_here');
bite_check(!empty($r['ok']) && $GLOBALS['mentions'] === [], 'bite on an unknown account is logged without a notification');

$r = bite($remote, 'https://elsewhere.example/users/bob');
bite_check(!empty($r['ok']) && $GLOBALS['mentions'] === [], 'bite on a remote target does not notify anyone here');

echo "bite-ingest-notify-smoke: {$checks} checks passed\n";
