<?php
// modules/manual_reboot.php
//
// COMPAT VERSION (fixes signature mismatch)
// Some older builds call:
//   manual_reboot_process_queue($acc, $accountKey, (int)$userId, (string)$cookieStr, $state, $locations, $notifyState)
//
// This module supports BOTH signatures:
// A) manual_reboot_process_queue(array $acc, string $accountKey, int $userId, string $cookieStr, array &$state, array &$locations, array &$notifyState)
// B) manual_reboot_process_queue(array $acc, int $userId, string $cookieStr, string $accountKey, array &$state, array &$notifyState)
//
// It delegates to an existing reboot processor if present, otherwise safely SKIPs.
// Does not touch config.php.

if (!function_exists('manual_reboot_process_queue_core')) {

function manual_reboot_process_queue_core(array $acc, int $userId, string $cookieStr, string $accountKey, array &$state, array &$notifyState): array {
    $accName = (string)($acc['name'] ?? 'ACC');

    $notifyReboot = function_exists('isTelegramNotifyEnabled')
        ? isTelegramNotifyEnabled($acc, 'reboot')
        : (bool)($acc['telegram_notify']['reboot'] ?? false);

    $candidates = [
        'reboot_process_queue',
        'machine_reboot_process_queue',
        'boiler_reboot_process_queue',
        'reboot_process',
    ];

    foreach ($candidates as $fn) {
        if (function_exists($fn)) {
            if (function_exists('xhe_log')) xhe_log('manual_reboot', "DELEGATE to {$fn} acc={$accName}", 'INFO');
            try {
                return $fn($acc, $userId, $cookieStr, $accountKey, $state, $notifyState);
            } catch (Throwable $e) {
                if (function_exists('xhe_log')) xhe_log('manual_reboot', "EXCEPTION in {$fn}: " . $e->getMessage(), 'ERROR');
                return ['ok'=>false, 'error'=>"exception in {$fn}: ".$e->getMessage()];
            }
        }
    }

    if (function_exists('xhe_log')) {
        xhe_log(
            'manual_reboot',
            "SKIP: no underlying reboot module found (expected one of: ".implode(',', $candidates).") acc={$accName} notifyReboot=" . ($notifyReboot ? '1':'0'),
            'WARNING'
        );
    }
    return ['ok'=>true, 'skipped'=>'no_underlying_module'];
}

}

// ---- public wrapper with flexible signature ----
if (!function_exists('manual_reboot_process_queue')) {

function manual_reboot_process_queue($acc, $p2, $p3, $p4 = null, &$state = null, &$p6 = null, &$p7 = null) {
    // Detect signature by types/count:
    // Old style: ($acc, $accountKey(string), $userId(int), $cookieStr(string), &$state(array), &$locations(array), &$notifyState(array))
    // New style: ($acc, $userId(int), $cookieStr(string), $accountKey(string), &$state(array), &$notifyState(array))
    if (!is_array($acc)) return ['ok'=>false, 'error'=>'acc_not_array'];

    // Initialize references if missing
    if (!is_array($state)) $state = [];
    // $p6/$p7 may be locations/notifyState depending on signature
    $notifyState = [];

    if (is_string($p2) && (is_int($p3) || ctype_digit((string)$p3)) && is_string($p4)) {
        // OLD
        $accountKey = (string)$p2;
        $userId     = (int)$p3;
        $cookieStr  = (string)$p4;

        // p6 is locations, p7 is notifyState
        if (is_array($p7)) $notifyState = $p7;
        else if (is_array($p6)) $notifyState = $p6; // fallback
        $res = manual_reboot_process_queue_core($acc, $userId, $cookieStr, $accountKey, $state, $notifyState);

        // push back notifyState to caller if passed by ref
        if (is_array($p7)) $p7 = $notifyState;
        else if (is_array($p6)) $p6 = $notifyState;

        return $res;
    }

    // NEW (expected params: $p2=int userId, $p3=string cookieStr, $p4=string accountKey, $state=array, $p6=notifyState)
    $userId     = (int)$p2;
    $cookieStr  = (string)$p3;
    $accountKey = (string)$p4;

    if (is_array($p6)) $notifyState = $p6;
    $res = manual_reboot_process_queue_core($acc, $userId, $cookieStr, $accountKey, $state, $notifyState);
    if (is_array($p6)) $p6 = $notifyState;
    return $res;
}

}
