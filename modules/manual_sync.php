<?php

if (!isset($CFG['sync']['enabled']) || !$CFG['sync']['enabled']) {
    return;
}

$now = time();

/* ============================================================
   DAILY SCHEDULE: 1 RUN PER DAY AT 05:00 (Europe/Istanbul)
   ============================================================ */

$dailyHour      = 5;
$dailyWindowMin = 60;
$tzName         = 'Europe/Istanbul';

$tz = new DateTimeZone($tzName);
$dt = new DateTime('@' . $now);
$dt->setTimezone($tz);

$today = $dt->format('Y-m-d');
$currentMin = ((int)$dt->format('G')) * 60 + (int)$dt->format('i');

$startMin = $dailyHour * 60;
$endMin   = $startMin + $dailyWindowMin;

$stateFile = runtime_base_dir() . '/state/jetinno_sync_state_' . $CFG['account']['user_id'] . '.json';
$state = [];

if (file_exists($stateFile)) {
    $state = json_decode(file_get_contents($stateFile), true) ?: [];
}

if (!isset($state['daily'])) {
    $state['daily'] = [];
}

$lastRunDate = $state['daily']['last_run_date'] ?? null;

// вне окна
if ($currentMin < $startMin || $currentMin >= $endMin) {
    return;
}

// уже запускали сегодня
if ($lastRunDate === $today) {
    return;
}

// отмечаем запуск
$state['daily']['last_run_date'] = $today;
$state['daily']['last_run_ts']   = $now;

/* ============================================================
   STALE CHECK (6 HOURS)
   ============================================================ */

$staleAfterSec = 6 * 3600;
$staleMachines = [];

if (!isset($state['machines'])) {
    $state['machines'] = [];
}

foreach ($state['machines'] as $vmc => $mState) {

    $uploadTimeStr = $mState['upload_time'] ?? null;
    if (!$uploadTimeStr) continue;

    $uploadTs = strtotime($uploadTimeStr);
    if (!$uploadTs) continue;

    if (($now - $uploadTs) > $staleAfterSec) {
        $staleMachines[] = $vmc;
    }
}

if (count($staleMachines) === 0) {
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return;
}

/* ============================================================
   TELEGRAM SUMMARY (ONE MESSAGE)
   ============================================================ */

$timeStr = $dt->format('H:i');
$accName = $CFG['account']['name'] ?? 'MAIN';

$msg = "🔄 DAILY SYNC RUN | {$accName}\n";
$msg .= "Stale machines: " . count($staleMachines) . "\n";
$msg .= "Time: {$timeStr}";

if (!empty($CFG['telegram']['notify_sync'])) {
    tg_notify('sync', $msg, $CFG['telegram']['chat_id'], 'daily_sync_' . $today, $notifyState);
}

/* ============================================================
   RUN SYNC FOR STALE MACHINES
   ============================================================ */

foreach ($staleMachines as $vmc) {

    xhe_log('sync', "DAILY SYNC vmc={$vmc}", 'INFO');

    // ваш существующий код UI синхронизации:
    run_machine_sync($browser, $image, $input, $btn, $vmc);

    $state['machines'][$vmc]['last_sync'] = date('Y-m-d H:i:s', $now);
}

/* ============================================================
   SAVE STATE
   ============================================================ */

file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));