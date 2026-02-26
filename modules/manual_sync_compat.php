<?php
// modules/manual_sync_compat.php
//
// Provides manual_sync_process_queue() to avoid fatal errors in modular builds.
//
// Supports BOTH signatures (to match old/new calls):
// A) manual_sync_process_queue(array $acc, string $accountKey, int $userId, string $cookieStr, array &$state, array &$locations, array &$notifyState)
// B) manual_sync_process_queue(array $acc, int $userId, string $cookieStr, string $accountKey, array &$state, array &$notifyState)
//
// Delegates to an existing real sync processor if present, otherwise safely SKIPs.
// Does not touch config.php.

if (!function_exists('manual_sync_process_queue_core')) {

function manual_sync_process_queue_core(array $acc, int $userId, string $cookieStr, string $accountKey, array &$state, array &$notifyState): array {
    $accName = (string)($acc['name'] ?? 'ACC');

    $notifySync = function_exists('isTelegramNotifyEnabled')
        ? isTelegramNotifyEnabled($acc, 'sync')
        : (bool)($acc['telegram_notify']['sync'] ?? false);

    // Candidate underlying implementations in your project
    $candidates = [
        'machine_sync_process_queue',
        'sync_process_queue',
        'vmc_sync_process_queue',
        'manual_sync_real_process_queue',
        'machine_sync_process',
    ];

    foreach ($candidates as $fn) {
        if (function_exists($fn)) {
            if (function_exists('xhe_log')) xhe_log('manual_sync', "DELEGATE to {$fn} acc={$accName}", 'INFO');
            try {
                return $fn($acc, $userId, $cookieStr, $accountKey, $state, $notifyState);
            } catch (Throwable $e) {
                if (function_exists('xhe_log')) xhe_log('manual_sync', "EXCEPTION in {$fn}: " . $e->getMessage(), 'ERROR');
                return ['ok'=>false, 'error'=>"exception in {$fn}: ".$e->getMessage()];
            }
        }
    }

    if (function_exists('xhe_log')) {
        xhe_log(
            'manual_sync',
            "SKIP: no underlying sync module found (expected one of: ".implode(',', $candidates).") acc={$accName} notifySync=" . ($notifySync ? '1':'0'),
            'WARNING'
        );
    }
    return ['ok'=>true, 'skipped'=>'no_underlying_module'];
}

}

// public wrapper with flexible signature
if (!function_exists('manual_sync_process_queue')) {

function manual_sync_process_queue($acc, $p2, $p3, $p4 = null, &$state = null, &$p6 = null, &$p7 = null) {
    if (!is_array($acc)) return ['ok'=>false, 'error'=>'acc_not_array'];

    if (!is_array($state)) $state = [];
    $notifyState = [];

    // OLD: ($acc, $accountKey, $userId, $cookieStr, &$state, &$locations, &$notifyState)
    if (is_string($p2) && (is_int($p3) || ctype_digit((string)$p3)) && is_string($p4)) {
        $accountKey = (string)$p2;
        $userId     = (int)$p3;
        $cookieStr  = (string)$p4;

        if (is_array($p7)) $notifyState = $p7;
        else if (is_array($p6)) $notifyState = $p6;

        $res = manual_sync_process_queue_core($acc, $userId, $cookieStr, $accountKey, $state, $notifyState);

        if (is_array($p7)) $p7 = $notifyState;
        else if (is_array($p6)) $p6 = $notifyState;

        return $res;
    }

    // NEW: ($acc, $userId, $cookieStr, $accountKey, &$state, &$notifyState)
    $userId     = (int)$p2;
    $cookieStr  = (string)$p3;
    $accountKey = (string)$p4;

    if (is_array($p6)) $notifyState = $p6;
    $res = manual_sync_process_queue_core($acc, $userId, $cookieStr, $accountKey, $state, $notifyState);
    if (is_array($p6)) $p6 = $notifyState;

    return $res;
}

}
