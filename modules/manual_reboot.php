<?php
// modules/manual_reboot.php
// Execute queued manual reboot tasks (created by request_machine_reboot()) using Jetinno UI (XHE)
// Uses the same UI sequence as modules/boiler_reboot.php

if (!function_exists('manual_reboot_device_info_url')) {
    function manual_reboot_device_info_url(string $vmc): string {
        $vmc = preg_replace('~\D+~', '', $vmc);
        return "https://saas-hk.jetinno.com/device_info?vmc_no={$vmc}&admin=";
    }
}

if (!function_exists('manual_reboot_queue_path')) {
    function manual_reboot_queue_path(): string {
        $base = function_exists('runtime_base_dir') ? rtrim(runtime_base_dir(), "\\/") : 'c:\\jetinno_runtime';
        $dir = $base . DIRECTORY_SEPARATOR . 'state';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        return $dir . DIRECTORY_SEPARATOR . 'queue_reboot.json';
    }
}

if (!function_exists('manual_reboot_load_queue')) {
    function manual_reboot_load_queue(): array {
        $p = manual_reboot_queue_path();
        if (!is_file($p)) return [];
        $j = json_decode((string)@file_get_contents($p), true);
        return is_array($j) ? $j : [];
    }
}

if (!function_exists('manual_reboot_save_queue')) {
    function manual_reboot_save_queue(array $q): void {
        @file_put_contents(manual_reboot_queue_path(), json_encode($q, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}

if (!function_exists('manual_reboot_pop_one')) {
    function manual_reboot_pop_one(): ?array {
        $q = manual_reboot_load_queue();
        if (!$q) return null;
        $task = array_shift($q);
        manual_reboot_save_queue($q);
        return is_array($task) ? $task : null;
    }
}

if (!function_exists('manual_reboot_find_machine_url')) {
    function manual_reboot_find_machine_url(string $html, string $vmc): string {
        $vmcEsc = preg_quote($vmc, '~');
        $machineUrl = '';

        // try exact vmc
        if (preg_match('~href="([^"]*vmc_no=' . $vmcEsc . '[^"]*)"~i', $html, $m)) {
            $machineUrl = html_entity_decode($m[1], ENT_QUOTES);
        } elseif (preg_match('~href="([^"]*vmc_no=\d+[^"]*)"~i', $html, $m2)) {
            // fallback first
            $machineUrl = html_entity_decode($m2[1], ENT_QUOTES);
        }

        if ($machineUrl !== '' && strpos($machineUrl, 'http') !== 0) {
            $machineUrl = 'https://saas-hk.jetinno.com' . (substr($machineUrl, 0, 1) === '/' ? '' : '/') . $machineUrl;
        }
        return $machineUrl;
    }
}

if (!function_exists('manual_reboot_process_queue')) {
    /**
     * Call this inside account loop AFTER login/cookies available.
     * Needs: $acc, $accountKey, $userId, $cookieStr, &$state, $locations, $notifyState
     */
    function manual_reboot_process_queue(array $acc, string $accountKey, int $userId, string $cookieStr, array &$state, $locations, &$notifyState): void {

        if (!is_array($notifyState)) $notifyState = [];

        // Pull XHE objects (same style as boiler_reboot.php)
        if (!isset($browser) && isset($GLOBALS['browser'])) $browser = $GLOBALS['browser'];
        if (!isset($anchor)  && isset($GLOBALS['anchor']))  $anchor  = $GLOBALS['anchor'];
        if (!isset($image)   && isset($GLOBALS['image']))   $image   = $GLOBALS['image'];
        if (!isset($span)    && isset($GLOBALS['span']))    $span    = $GLOBALS['span'];
        if (!isset($input)   && isset($GLOBALS['input']))   $input   = $GLOBALS['input'];
        if (!isset($btn)     && isset($GLOBALS['btn']))     $btn     = $GLOBALS['btn'];

        if (!isset($browser) || !is_object($browser) || !isset($image) || !is_object($image) || !isset($input) || !is_object($input) || !isset($btn) || !is_object($btn)) {
            if (function_exists('xhe_log')) xhe_log('reboot', 'SKIP manual reboot: XHE objects missing', 'WARN');
            return;
        }

        if (!isset($state['reboot_global']) || !is_array($state['reboot_global'])) $state['reboot_global'] = [];
        if (!isset($state['reboot_global']['manual']) || !is_array($state['reboot_global']['manual'])) $state['reboot_global']['manual'] = [];

        // One task per loop pass (so it doesn't block)
        $task = manual_reboot_pop_one();
        if (!$task) return;

        $vmc = preg_replace('~\D+~', '', (string)($task['vmc'] ?? ''));
        if ($vmc === '') return;

        // cooldown per vmc (manual): 7 min
        $cooldownSec = 420;
        $lastTs = (int)($state['reboot_global']['manual'][$vmc]['ts'] ?? 0);
        if ($lastTs > 0 && (time() - $lastTs) <= $cooldownSec) {
            if (function_exists('xhe_log')) xhe_log('reboot', "SKIP manual cooldown vmc={$vmc} age_sec=".(time()-$lastTs), 'INFO');
            return;
        }
        // Direct machine page (no search needed)
        $machineUrl = manual_reboot_device_info_url($vmc);

        $location = (function_exists('getLocation') ? (getLocation($locations, $vmc) ?? '') : '');
        if ($location === '') $location = 'Unknown';

        // notify toggle: for manual reboot we respect telegram_notify['reboot']
        $notifyReboot = function_exists('isTelegramNotifyEnabled')
            ? isTelegramNotifyEnabled($acc, 'reboot')
            : (bool)($acc['telegram_notify']['reboot'] ?? true);

        $chatId = $acc['telegram_chat_id'] ?? ($GLOBALS['CFG']['telegram']['chat_id'] ?? null);

        // --- UI procedure (same as boiler_reboot.php) ---
        $confirmSpanNumber  = 344;
        $machineInputNumber = 40;
        $rebootBtnNumber    = 55;

        if (function_exists('xhe_log')) xhe_log('reboot', "MANUAL reboot vmc={$vmc} loc={$location} url={$machineUrl}", 'INFO');

        $browser->close_all_tabs();
        $browser->navigate($machineUrl);
        $browser->wait(5);

        $image->click_by_src("https://saas-hk.jetinno.com/home/images/control.png", false);
        $browser->wait(4);

        $image->click_by_src("https://saas-hk.jetinno.com/home/images/reboot.png", false);
        $browser->wait(3);

        if (isset($span) && is_object($span)) {
            $span->click_by_number($confirmSpanNumber);
            $browser->wait(3);
        }

        if (isset($anchor) && is_object($anchor)) {
            $anchor->click_by_inner_html("<span class=\"text\">makine</sp", false);
            $browser->wait(3);
        }

        $input->click_by_number($machineInputNumber);
        $input->set_value_by_number($machineInputNumber, $vmc);
        $browser->wait(1);

        $btn->set_focus_by_number($rebootBtnNumber);
        $btn->click_by_number($rebootBtnNumber);
        $browser->wait(3);

        // state
        $state['reboot_global']['manual'][$vmc] = ['ts' => time(), 'acc' => $accountKey];

        // telegram
        if ($notifyReboot && $chatId !== null && function_exists('tg_notify')) {
            $msg = "🔁 MANUAL REBOOT\nVMC: {$vmc}\nLocation: {$location}\nAccount: {$accountKey}\nTime: " . date('Y-m-d H:i:s');
            tg_notify('reboot', $msg, (string)$chatId, $accountKey . '|manual|' . $vmc, $notifyState);
        }
    }
}