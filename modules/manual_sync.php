<?php
// modules/manual_sync.php
// Manual SYNC via queue (created by request_machine_sync())
// Минимально изменённая версия machine_sync.php

if (!function_exists('manual_sync_process_queue')) {

function manual_sync_process_queue(array $acc, string $accountKey, int $userId, string $cookieStr, array &$state, $locations, &$notifyState): void
{
    if (!is_array($notifyState)) $notifyState = [];

    // ---- XHE objects ----
    global $browser, $image, $span, $input, $btn;

    if (!isset($browser) || !is_object($browser)) return;

    if (!isset($image) || !is_object($image)) return;
    if (!isset($input) || !is_object($input)) return;
    if (!isset($btn) || !is_object($btn)) return;

    // ---- queue path ----
    $base = function_exists('runtime_base_dir') ? rtrim(runtime_base_dir(), "\\/") : 'c:\\jetinno_runtime';
    $stateDir = $base . DIRECTORY_SEPARATOR . 'state';
    $queuePath = $stateDir . DIRECTORY_SEPARATOR . 'queue_sync.json';

    if (!is_file($queuePath)) return;

    $queue = json_decode((string)file_get_contents($queuePath), true);
    if (!is_array($queue) || !$queue) return;

    // Берём 1 задачу
    $task = array_shift($queue);
    file_put_contents($queuePath, json_encode($queue, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $vmc = preg_replace('~\D+~', '', (string)($task['vmc'] ?? ''));
    if ($vmc === '') return;

    // ---- cooldown 7 минут ----
    if (!isset($state['sync_global'])) $state['sync_global'] = [];
    if (!isset($state['sync_global']['manual'])) $state['sync_global']['manual'] = [];

    $lastTs = (int)($state['sync_global']['manual'][$vmc]['ts'] ?? 0);
    if ($lastTs > 0 && (time() - $lastTs) < 420) {
        if (function_exists('xhe_log')) xhe_log('sync', "SKIP manual cooldown vmc={$vmc}", 'INFO');
        return;
    }

    // ---- прямой URL машины ----
    $machineUrl = "https://saas-hk.jetinno.com/device_info?vmc_no={$vmc}&admin=";

    if (function_exists('xhe_log')) xhe_log('sync', "MANUAL sync vmc={$vmc} url={$machineUrl}", 'INFO');

$confirmSpanNumber  = 344; // если у вас используется confirm
$machineInputNumber = 40;  // ВАЖНО: возьмите значения из machine_sync.php
$syncBtnNumber      = 55;  // ВАЖНО: возьмите значения из machine_sync.php

    $browser->close_all_tabs();
    $browser->navigate($machineUrl);
    $browser->wait(5);

    // ---- control ----
    $image->click_by_src("https://saas-hk.jetinno.com/home/images/control.png", false);
    $browser->wait(4);

    // ---- sync icon (как в machine_sync.php) ----
    $image->click_by_src("https://saas-hk.jetinno.com/home/images/sync.png", false);
    $browser->wait(3);

$input->click_by_number($machineInputNumber);
    $input->set_value_by_number($machineInputNumber, $vmc);
    $browser->wait(1);

    $btn->set_focus_by_number($syncBtnNumber);
    $btn->click_by_number($syncBtnNumber);
    $browser->wait(3);

    // ---- state ----
    $state['sync_global']['manual'][$vmc] = [
        'ts'  => time(),
        'acc' => $accountKey,
    ];

    // ---- Telegram ----
    $notifySync = function_exists('isTelegramNotifyEnabled')
        ? isTelegramNotifyEnabled($acc, 'sync')
        : true;

    $chatId = $acc['telegram_chat_id'] ?? ($GLOBALS['CFG']['telegram']['chat_id'] ?? null);

    if ($notifySync && $chatId && function_exists('tg_notify')) {
        $msg = "🔄 MANUAL SYNC\nVMC: {$vmc}\nAccount: {$accountKey}\nTime: " . date('Y-m-d H:i:s');
        tg_notify('sync', $msg, (string)$chatId, $accountKey . '|manual_sync|' . $vmc, $notifyState);
    }
}

}