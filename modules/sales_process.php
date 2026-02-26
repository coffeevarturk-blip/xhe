<?php
// modules/sales_process.php
//
// Sends daily sales summary to Telegram via tg_notify('sales', ...).
// Uses stable key: sales|ACCOUNTKEY|YYYY-mm-dd
//
// Fixes:
// - tg_notify may return bool OR array; treat array['ok'] as success.
// - ensure notifyState is array (caller may pass null).

if (!function_exists('sales_process_day')) {

function sales_process_day(array $acc, int $userId, string $cookieStr, string $accountKey, string $targetDateYmd, array &$state, array &$notifyState): array {
    $accName = (string)($acc['name'] ?? 'ACC');
    $chatId  = $acc['telegram_chat_id'] ?? null;

    if (!isset($notifyState) || !is_array($notifyState)) $notifyState = [];

    $notifySales = function_exists('isTelegramNotifyEnabled')
        ? isTelegramNotifyEnabled($acc, 'sales')
        : (bool)($acc['telegram_notify']['sales'] ?? false);

    $stats = [
        'enabled' => (bool)$notifySales,
        'sent'    => 0,
        'skipped' => '',
        'tg_ok'   => null,
        'key'     => '',
    ];

    if (!isset($state['sales_summary_sent']) || !is_array($state['sales_summary_sent'])) $state['sales_summary_sent'] = [];
    if (!isset($state['sales_summary_sent'][$accountKey]) || !is_array($state['sales_summary_sent'][$accountKey])) $state['sales_summary_sent'][$accountKey] = [];

    $key = "sales|{$accountKey}|{$targetDateYmd}";
    $stats['key'] = $key;

    if (isset($state['sales_summary_sent'][$accountKey][$targetDateYmd])) {
        $stats['skipped'] = 'already_sent_state';
        if (function_exists('xhe_log')) xhe_log('sales', "SKIP already sent (state) acc={$accName} date={$targetDateYmd} key={$key}", 'INFO');
        return ['ok'=>true, 'stats'=>$stats];
    }

    if (!$notifySales) {
        $stats['skipped'] = 'disabled_in_config';
        $state['sales_summary_sent'][$accountKey][$targetDateYmd] = time();
        if (function_exists('xhe_log')) xhe_log('sales', "SKIP disabled acc={$accName} date={$targetDateYmd} key={$key}", 'INFO');
        return ['ok'=>true, 'stats'=>$stats];
    }

    if ($chatId === null || !function_exists('tg_notify')) {
        $stats['skipped'] = 'no_chat_or_tg_notify';
        if (function_exists('xhe_log')) xhe_log('sales', "SKIP missing telegram setup acc={$accName} date={$targetDateYmd} chatId=" . (string)$chatId, 'WARNING');
        return ['ok'=>false, 'stats'=>$stats, 'error'=>'missing telegram setup (chat_id or tg_notify)'];
    }

    if (!function_exists('build_sales_summary_text')) {
        $stats['skipped'] = 'missing_builder';
        if (function_exists('xhe_log')) xhe_log('sales', "FAIL build_sales_summary_text missing acc={$accName}", 'WARNING');
        return ['ok'=>false, 'stats'=>$stats, 'error'=>'build_sales_summary_text() not found'];
    }

    $res = build_sales_summary_text($acc, (int)$userId, (string)$cookieStr, (string)$accountKey, (string)$targetDateYmd);
    if (!($res['ok'] ?? false)) {
        $stats['skipped'] = 'builder_failed';
        if (function_exists('xhe_log')) xhe_log('sales', "FAIL builder acc={$accName} date={$targetDateYmd} err=" . (string)($res['error'] ?? 'unknown'), 'WARNING');
        return ['ok'=>false, 'stats'=>$stats, 'error'=>'builder_failed: ' . (string)($res['error'] ?? 'unknown'), 'builder'=>$res];
    }

    $text = (string)($res['text'] ?? '');
    if ($text === '') {
        $stats['skipped'] = 'empty_text';
        if (function_exists('xhe_log')) xhe_log('sales', "FAIL empty text acc={$accName} date={$targetDateYmd}", 'WARNING');
        return ['ok'=>false, 'stats'=>$stats, 'error'=>'empty sales text'];
    }

    if (function_exists('xhe_log')) xhe_log('sales', "SEND TG acc={$accName} date={$targetDateYmd} chat_id={$chatId} key={$key} len=" . strlen($text), 'INFO');

    $tgRes = tg_notify('sales', $text, (string)$chatId, (string)$key, $notifyState);
    $tgOk = false;
    if (is_array($tgRes)) $tgOk = (bool)($tgRes['ok'] ?? false);
    else $tgOk = (bool)$tgRes;

    $stats['tg_ok'] = $tgOk;

    if ($tgOk) {
        $stats['sent'] = 1;
        $state['sales_summary_sent'][$accountKey][$targetDateYmd] = time();
        if (function_exists('xhe_log')) xhe_log('sales', "SENT OK acc={$accName} date={$targetDateYmd} key={$key}", 'INFO');
        return ['ok'=>true, 'stats'=>$stats, 'builder'=>$res];
    }

    if (function_exists('xhe_log')) xhe_log('sales', "SENT FAIL acc={$accName} date={$targetDateYmd} key={$key} (tg_notify not ok)", 'WARNING');
    return ['ok'=>false, 'stats'=>$stats, 'error'=>'tg_notify_failed', 'builder'=>$res];
}

function sales_process_today(array $acc, int $userId, string $cookieStr, string $accountKey, array &$state, array &$notifyState): array {
    $today = date('Y-m-d');
    return sales_process_day($acc, $userId, $cookieStr, $accountKey, $today, $state, $notifyState);
}

} // end if !exists
