<?php
// modules/boiler_reboot.php
// Reboot machine on Boiler ERROR:7300 (global cooldown by VMC across accounts)
// - Reboot always (even if telegram_notify['reboot']=false)
// - Telegram notify about reboot only if enabled
// - Uses UI clicks (control.png -> reboot.png -> confirm -> makine tab -> input vmc -> reboot btn)

$cooldownSec = 420; // 7 minutes (global across accounts)
$codeNeed = 'ERROR:7300';

if (!isset($state) || !is_array($state)) { $state = []; }
if (!isset($state['reboot_global']) || !is_array($state['reboot_global'])) $state['reboot_global'] = [];
if (!isset($state['reboot_global']['boiler']) || !is_array($state['reboot_global']['boiler'])) $state['reboot_global']['boiler'] = [];

// Pull XHE objects if we are inside a function scope
if (!isset($browser) && isset($GLOBALS['browser'])) $browser = $GLOBALS['browser'];
if (!isset($anchor)  && isset($GLOBALS['anchor']))  $anchor  = $GLOBALS['anchor'];
if (!isset($image)   && isset($GLOBALS['image']))   $image   = $GLOBALS['image'];
if (!isset($span)    && isset($GLOBALS['span']))    $span    = $GLOBALS['span'];
if (!isset($input)   && isset($GLOBALS['input']))   $input   = $GLOBALS['input'];
if (!isset($btn)     && isset($GLOBALS['btn']))     $btn     = $GLOBALS['btn'];

// If XHE objects are not available, skip safely (no fatal)
if (!isset($browser) || !is_object($browser) || !isset($image) || !is_object($image) || !isset($input) || !is_object($input) || !isset($btn) || !is_object($btn)) {
    xhe_log('reboot', 'SKIP boiler reboot: XHE objects missing', 'WARN');
    return;
}

// Collect ALL VMCs that have ERROR:7300 from $byVmc produced by modules/errors.php
$targets = []; // vmc => row
if (isset($byVmc) && is_array($byVmc)) {
    foreach ($byVmc as $vmcNo => $levels) {
        if (!is_array($levels)) continue;
        $rows = $levels['ERROR'] ?? [];
        if (!is_array($rows) || count($rows) === 0) continue;
        foreach ($rows as $row) {
            $c = safe_s((string)($row[err_col_code()] ?? ''));
            if ($c === $codeNeed) {
                // keep the newest row if possible
                $dt = safe_s((string)($row[err_col_time()] ?? ''));
                $ts = parse_ts($dt);
                if (!isset($targets[(string)$vmcNo])) {
                    $targets[(string)$vmcNo] = ['row'=>$row, 'ts'=>$ts];
                } else {
                    $prevTs = (int)($targets[(string)$vmcNo]['ts'] ?? 0);
                    if ($ts > $prevTs) $targets[(string)$vmcNo] = ['row'=>$row, 'ts'=>$ts];
                }
            }
        }
    }
}

if (!$targets) {
    // no boiler error
    return;
}

// Fetch boiler error page once (HTML) to extract machine links
$urlBoilerError = "https://saas-hk.jetinno.com/error?user_id={$userId}&data_type=0&daterange=&vmc_no=&like=ERROR%3A7300&vmc_model=&error_code=&is_set=1&perPage=25&order_by%5Bkey%5D=&order_by%5Bvalue%5D=&export=0";
$res = curl_get_with_cookies($urlBoilerError, $cookieStr, ["Accept: text/html,*/*"], "https://saas-hk.jetinno.com/error");
$html = (string)($res['body'] ?? '');

if ($html === '') {
    xhe_log('reboot', "FAIL fetch boiler error page (empty HTML) account={$accountKey}", 'WARN');
    return;
}

// If reboot notify disabled, we still reboot
$notifyReboot = function_exists('isTelegramNotifyEnabled')
    ? isTelegramNotifyEnabled($acc, 'reboot')
    : (bool)($acc['telegram_notify']['reboot'] ?? true);

if (!$notifyReboot) {
    xhe_log('reboot', "Notify disabled (but reboot will run) account={$accountKey}", 'DEBUG');
}

// Telegram chat id for this account
$chatId = $acc['telegram_chat_id'] ?? null;

// Helper: find machine URL for a given VMC in boiler error page HTML
$findMachineUrl = function(string $html, string $vmc) {
    $machineUrl = '';
    $vmcEsc = preg_quote($vmc, '~');
    if (preg_match('~href="([^"]*vmc_no='.$vmcEsc.'[^"]*)"~i', $html, $m)) {
        $machineUrl = html_entity_decode($m[1], ENT_QUOTES);
    } elseif (preg_match('~href="([^"]*vmc_no=\d+[^"]*)"~i', $html, $m2)) {
        // fallback: first vmc link
        $machineUrl = html_entity_decode($m2[1], ENT_QUOTES);
    }
    if ($machineUrl !== '' && strpos($machineUrl, 'http') !== 0) {
        $machineUrl = 'https://saas-hk.jetinno.com' . (substr($machineUrl, 0, 1) === '/' ? '' : '/') . $machineUrl;
    }
    return $machineUrl;
};

// --- UI procedure (as in legacy all_in_one.php) ---
$confirmSpanNumber = 344;
$machineInputNumber = 40;
$rebootBtnNumber = 55;

foreach ($targets as $vmcNo => $info) {
    $targetVmc = (string)$vmcNo;
    $targetRow = is_array($info['row'] ?? null) ? $info['row'] : [];

    // Global cooldown (across accounts) per machine
    $lastTs = (int)($state['reboot_global']['boiler'][$targetVmc]['ts'] ?? 0);
    if ($lastTs > 0 && (time() - $lastTs) <= $cooldownSec) {
        $byAcc = (string)($state['reboot_global']['boiler'][$targetVmc]['acc'] ?? '');
        xhe_log('reboot', "SKIP cooldown global vmc={$targetVmc} last_acc={$byAcc} age_sec=".(time()-$lastTs), 'INFO');
        continue;
    }

    $machineUrl = $findMachineUrl($html, $targetVmc);
    if ($machineUrl === '') {
        xhe_log('reboot', "FAIL find machine url for vmc={$targetVmc} on boiler page", 'WARN');
        continue;
    }

    $location = function_exists('getLocation') ? (getLocation($locations, $targetVmc) ?? '') : '';
    if ($location === '') $location = 'Unknown';
    $dt = safe_s((string)($targetRow[err_col_time()] ?? date('Y-m-d H:i:s')));

    xhe_log('reboot', "Boiler error detected vmc={$targetVmc} loc={$location} -> reboot", 'INFO');

    $browser->close_all_tabs();
    $browser->navigate($machineUrl);
    $browser->wait(5);

    // control icon
    $image->click_by_src("https://saas-hk.jetinno.com/home/images/control.png", false);
    $browser->wait(4);

    // reboot icon
    $image->click_by_src("https://saas-hk.jetinno.com/home/images/reboot.png", false);
    $browser->wait(3);

    // confirm
    if (isset($span) && is_object($span)) {
        $span->click_by_number($confirmSpanNumber);
        $browser->wait(3);
    }

    // tab "makine" (machine)
    if (isset($anchor) && is_object($anchor)) {
        $anchor->click_by_inner_html("<span class=\"text\">makine</sp", false);
        $browser->wait(3);
    }

    // input vmc
    $input->click_by_number($machineInputNumber);
    $input->set_value_by_number($machineInputNumber, $targetVmc);
    $browser->wait(1);

    // reboot button
    $btn->set_focus_by_number($rebootBtnNumber);
    $btn->click_by_number($rebootBtnNumber);
    $browser->wait(3);

    // save global reboot state
    $state['reboot_global']['boiler'][$targetVmc] = ['ts' => time(), 'acc' => (string)$accountKey];

    // telegram notify (optional)
    if ($notifyReboot && $chatId !== null && function_exists('tg_notify')) {
        $msg = "🔥 BOILER REBOOT".
            "\nVMC: {$targetVmc}".
            "\nLocation: {$location}".
            "\nError: 7300".
            "\nDetected: {$dt}".
            "\nAccount: {$accountKey}";
        tg_notify('reboot', $msg, (string)$chatId, $accountKey.'|boiler|'.$targetVmc, $notifyState);
    }
}

