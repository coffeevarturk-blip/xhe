<?php
// modules/machine_sync.php
// all_in_one 2.0 style: machine sync controlled by config flags + per-VMC cooldown.
//
// Reads state file produced by supply module: runtime_base_dir()\state\jetinno_sync_state_<userId>.json
// For machines where upload_time ("Yükleme süresi") is older than stale_hours -> perform UI sync.
// Notifications are sent ONLY if enabled in config via telegram_notify['sync'] (fallback to 'reboot' for backward compat).

// ---- defaults (can be overridden by $CFG['sync']) ----
$defaultStaleAfterSec = 24 * 3600; // 6h
$defaultCooldownSec   = 420;      // 7 min

// ---- ensure state buckets ----
if (!isset($state) || !is_array($state)) $state = [];
if (!isset($state['sync']) || !is_array($state['sync'])) $state['sync'] = [];
if (!isset($state['sync']['machine']) || !is_array($state['sync']['machine'])) $state['sync']['machine'] = [];

// ---- pull XHE objects (works both in global scope and when included inside function) ----
if (!isset($browser) && isset($GLOBALS['browser'])) $browser = $GLOBALS['browser'];
if (!isset($image)   && isset($GLOBALS['image']))   $image   = $GLOBALS['image'];
if (!isset($input)   && isset($GLOBALS['input']))   $input   = $GLOBALS['input'];
if (!isset($btn)     && isset($GLOBALS['btn']))     $btn     = $GLOBALS['btn'];

// ---- safety: if XHE objects missing, skip ----
if (!isset($browser) || !is_object($browser) || !isset($image) || !is_object($image) || !isset($input) || !is_object($input) || !isset($btn) || !is_object($btn)) {
    if (function_exists('xhe_log')) xhe_log('sync', 'SKIP machine_sync: XHE objects missing', 'WARN');
    return;
}

// ---- config-driven thresholds ----
$staleAfterSec = (int)($CFG['sync']['stale_after_sec'] ?? 0);
if ($staleAfterSec <= 0) {
    $staleHours = (int)($CFG['sync']['stale_hours'] ?? 0);
    $staleAfterSec = ($staleHours > 0) ? ($staleHours * 3600) : $defaultStaleAfterSec;
}
$cooldownSec = (int)($CFG['sync']['cooldown_sec'] ?? $defaultCooldownSec);
// ---- HARD LIMIT: do not sync same machine more than once per 24h ----
$hardMinRepeatSec = (int)($CFG['sync']['hard_min_repeat_sec'] ?? 0); // daily scheduler controls cadence; keep 0 unless you explicitly need extra protection


// Optional global master switch (if present)
if (isset($CFG['sync']['enabled']) && !$CFG['sync']['enabled']) {
    if (function_exists('xhe_log')) xhe_log('sync', 'SYNC disabled by CFG[sync][enabled]=false', 'INFO');
    return;
}

// ---- notification enable gate (STRICT by config) ----
// Primary: telegram_notify['sync']
// Backward compat: if not present -> use telegram_notify['reboot'] (as in your old version)
$syncFlagKey = 'sync';
$notifyEnabled = null;

if (function_exists('isTelegramNotifyEnabled')) {
    // If helper supports arbitrary types, use it
    $notifyEnabled = isTelegramNotifyEnabled($acc, $syncFlagKey);
    // If helper returns null/false and sync flag absent, fallback to reboot
    if ($notifyEnabled === false && !array_key_exists('sync', (array)($acc['telegram_notify'] ?? []))) {
        $notifyEnabled = isTelegramNotifyEnabled($acc, 'reboot');
        $syncFlagKey = 'reboot';
    }
} else {
    $tn = (array)($acc['telegram_notify'] ?? []);
    if (array_key_exists('sync', $tn)) {
        $notifyEnabled = (bool)$tn['sync'];
        $syncFlagKey = 'sync';
    } else {
        $notifyEnabled = (bool)($tn['reboot'] ?? true);
        $syncFlagKey = 'reboot'; // backward compat
    }
}

$chatId = $acc['telegram_chat_id'] ?? null;

// ---- read machines from sync_state file (produced by supply module) ----
$syncStateFile = runtime_base_dir() . "\\state\\jetinno_sync_state_{$userId}.json";
$syncState = [];
if (is_file($syncStateFile)) {
    $tmp = json_decode((string)@file_get_contents($syncStateFile), true);
    if (is_array($tmp)) $syncState = $tmp;
}
$machines = $syncState['machines'] ?? [];
if (!is_array($machines) || count($machines) === 0) {
    if (function_exists('xhe_log')) xhe_log('sync', "No machines in sync_state file={$syncStateFile}", 'DEBUG');
    return;
}


// ---- DAILY SCHEDULER (Istanbul): run once per day at 05:00, sync only stale (>6h) machines ----
// This module is called inside the main AIO loop; scheduler makes it effectively "cron-like".
$tzName         = (string)($CFG['sync']['timezone'] ?? 'Europe/Istanbul');
$dailyHour      = (int)($CFG['sync']['daily_hour'] ?? 5);        // 5 AM
$dailyWindowMin = (int)($CFG['sync']['daily_window_min'] ?? 60); // 05:00-05:59 window

try { $tz = new DateTimeZone($tzName); } catch (Exception $e) { $tz = new DateTimeZone('Europe/Istanbul'); }
$nowTs = time();
$dtNow = new DateTime('@' . $nowTs);
$dtNow->setTimezone($tz);

$today    = $dtNow->format('Y-m-d');
$minOfDay = ((int)$dtNow->format('G')) * 60 + (int)$dtNow->format('i');
$startMin = $dailyHour * 60;
$endMin   = $startMin + max(1, $dailyWindowMin);

$dailyStateFile = runtime_base_dir() . "\\state\\jetinno_sync_daily_{$userId}.json";
$dailyState = [];
if (is_file($dailyStateFile)) {
    $tmpDaily = json_decode((string)@file_get_contents($dailyStateFile), true);
    if (is_array($tmpDaily)) $dailyState = $tmpDaily;
}
$lastRunDate = (string)($dailyState['last_run_date'] ?? '');

if ($minOfDay < $startMin || $minOfDay >= $endMin) {
    if (function_exists('xhe_log')) xhe_log('sync', 'SKIP daily window now=' . $dtNow->format('H:i') . ' tz=' . $tz->getName() . ' window=' . sprintf('%02d:00..%02d:%02d', $dailyHour, intdiv($endMin, 60), $endMin % 60), 'DEBUG');
    return;
}
if ($lastRunDate === $today) {
    if (function_exists('xhe_log')) xhe_log('sync', 'SKIP daily already ran today=' . $today, 'DEBUG');
    return;
}

// Mark as ran today (prevents re-run if main loop repeats inside the window)
$dailyState['last_run_date'] = $today;
$dailyState['last_run_ts']   = $nowTs;
@file_put_contents($dailyStateFile, json_encode($dailyState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

if (function_exists('xhe_log')) xhe_log('sync', 'DAILY RUN START date=' . $today . ' time=' . $dtNow->format('H:i') . ' tz=' . $tz->getName(), 'INFO');

// ---- helper: parse upload_time to timestamp ----
$parseUploadTs = function(string $s): int {
    $s = trim($s);
    if ($s === '') return 0;

    if (function_exists('parse_ts')) {
        $ts = (int)parse_ts($s);
        if ($ts > 0) return $ts;
    }

    // 2026-02-13 10:11:12
    if (preg_match('~^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?~', $s, $m)) {
        $sec = isset($m[6]) ? (int)$m[6] : 0;
        return mktime((int)$m[4], (int)$m[5], $sec, (int)$m[2], (int)$m[3], (int)$m[1]);
    }
    // 13.02.2026 10:11:12
    if (preg_match('~^(\d{2})\.(\d{2})\.(\d{4})[ T](\d{2}):(\d{2})(?::(\d{2}))?~', $s, $m)) {
        $sec = isset($m[6]) ? (int)$m[6] : 0;
        return mktime((int)$m[4], (int)$m[5], $sec, (int)$m[2], (int)$m[1], (int)$m[3]);
    }

    return 0;
};

// ---- determine stale machines ----
$now = time();
$targets = []; // vmc => ['upload_time'=>..., 'upload_ts'=>..., 'age'=>...]
foreach ($machines as $vmc => $row) {
    if (!is_array($row)) continue;

    $vmcNo = (string)($row['vmc'] ?? $vmc);
    $vmcNo = function_exists('safe_s') ? safe_s($vmcNo) : trim($vmcNo);

    if ($vmcNo === '' || !ctype_digit($vmcNo)) continue;

    $uploadTime = (string)($row['upload_time'] ?? '');
    $uploadTime = function_exists('safe_s') ? safe_s($uploadTime) : trim($uploadTime);

    $uploadTs = $parseUploadTs($uploadTime);

    // If cannot parse -> treat as stale so we resync once (cooldown still applies)
    $age = ($uploadTs > 0) ? ($now - $uploadTs) : ($staleAfterSec + 1);

    if ($age > $staleAfterSec) {
        $targets[$vmcNo] = ['upload_time' => $uploadTime, 'upload_ts' => $uploadTs, 'age' => $age];
    }
}

if (!$targets) {
    if (function_exists('xhe_log')) xhe_log('sync', 'No stale machines for sync', 'DEBUG');
    return;
}

// ---- enforce deterministic order ----
$vmcList = array_keys($targets);
sort($vmcList, SORT_STRING);

if (function_exists('xhe_log')) {
    xhe_log('sync', 'Stale machines found: ' . count($vmcList) . " stale_after_sec={$staleAfterSec} cooldown_sec={$cooldownSec}", 'INFO');
}

// ---- UI element numbers (keep your current ones) ----
$machineInputNumber = 40;
$syncBtnNumber      = 55;

// ---- main loop ----
foreach ($vmcList as $vmc) {
    $t = $targets[$vmc];

    // per-machine cooldown (prevents repeated sync in loop)
    $lastTs = (int)($state['sync']['machine'][$vmc]['ts'] ?? 0);
    if ($lastTs > 0 && ($now - $lastTs) <= $cooldownSec) {
        if (function_exists('xhe_log')) xhe_log('sync', "SKIP cooldown vmc={$vmc} age_sec=" . ($now - $lastTs), 'INFO');
        continue;
    }

    // Hard 24h protection
    if ($lastTs > 0 && ($now - $lastTs) <= $hardMinRepeatSec) {
        if (function_exists('xhe_log')) xhe_log('sync', "SKIP hard_24h vmc={$vmc} age_sec=" . ($now - $lastTs), 'INFO');
        continue;
    }

    $location = '';
    if (function_exists('getLocation')) {
        $location = (string)(getLocation($locations, $vmc) ?? '');
    }
    if ($location === '') $location = 'Unknown';

    $uploadTime = (string)($t['upload_time'] ?? '');
    $ageH = round(((int)($t['age'] ?? 0)) / 3600, 1);

    // Notify start (ONLY if enabled by config + tg_notify exists + chatId present)
    if ($notifyEnabled && $chatId !== null && function_exists('tg_notify')) {
        $msg = "🔄 START MACHINE SYNC | {$vmc} - {$location}\n" .
               ($uploadTime !== '' ? "Last sync (Yükleme süresi): {$uploadTime}\n" : "") .
               "Stale: ~{$ageH}h\n" .
               "Account: {$accountKey}";

        // Dedup key must be stable per vmc
        $dedupKey = $accountKey . '|machine_sync_start|' . $vmc;

        // Use type 'sync' if config has it, else fallback type 'reboot' for backward compat
        $type = ($syncFlagKey === 'sync') ? 'sync' : 'reboot';

        tg_notify($type, $msg, (string)$chatId, $dedupKey, $notifyState);
    }

    // UI sync
    $browser->close_all_tabs();
    $browser->navigate("https://saas-hk.jetinno.com/device_info?vmc_no=" . urlencode($vmc) . "&admin=");
    $browser->wait(4);

    $image->click_by_src("https://saas-hk.jetinno.com/home/images/control.png", false);
    $browser->wait(3);

    $image->click_by_src("https://saas-hk.jetinno.com/home/images/sync.png", false);
    $browser->wait(3);

    $input->click_by_number($machineInputNumber);
    $input->set_value_by_number($machineInputNumber, $vmc);
    $browser->wait(1);

    $btn->set_focus_by_number($syncBtnNumber);
    $btn->click_by_number($syncBtnNumber);
    $browser->wait(3);

    // Save cooldown state
    $state['sync']['machine'][$vmc] = [
        'ts'  => $now,
        'acc' => (string)$accountKey,
    ];
}