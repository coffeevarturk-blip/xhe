<?php
// modules/boiler_reboot.php
// Reboot machine on Boiler ERROR:7300 (global cooldown by VMC across accounts)
// - Reboot always (even if telegram_notify['reboot']=false)
// - Telegram notify about reboot only if enabled
// - Uses UI clicks (control.png -> reboot.png -> confirm -> makine tab -> input vmc -> reboot btn)

$cooldownSec = 420; // 7 minutes (global across accounts)
$codeNeedList = ['ERROR:7300', 'ERROR:7100', 'ERROR:5C00'];

if (!isset($state) || !is_array($state)) { $state = []; }
if (!isset($state['reboot_global']) || !is_array($state['reboot_global'])) $state['reboot_global'] = [];
if (!isset($state['reboot_global']['boiler']) || !is_array($state['reboot_global']['boiler'])) $state['reboot_global']['boiler'] = [];

// Daily limit for ERROR:7100 reboots: max 3 per VMC per day
if (!isset($state['reboot_daily']) || !is_array($state['reboot_daily'])) $state['reboot_daily'] = [];
if (!isset($state['reboot_daily']['7100']) || !is_array($state['reboot_daily']['7100'])) $state['reboot_daily']['7100'] = [];


// Pull XHE objects if we are inside a function scope
if (!isset($browser) && isset($GLOBALS['browser'])) $browser = $GLOBALS['browser'];
if (!isset($anchor)  && isset($GLOBALS['anchor']))  $anchor  = $GLOBALS['anchor'];
if (!isset($image)   && isset($GLOBALS['image']))   $image   = $GLOBALS['image'];
if (!isset($span)    && isset($GLOBALS['span']))    $span    = $GLOBALS['span'];
if (!isset($input)   && isset($GLOBALS['input']))   $input   = $GLOBALS['input'];
if (!isset($btn)     && isset($GLOBALS['btn']))     $btn     = $GLOBALS['btn'];

// If XHE objects are not available, skip safely (no fatal)
if (!isset($browser) || !is_object($browser) || !isset($image) || !is_object($image) || !isset($input) || !is_object($input) || !isset($btn) || !is_object($btn)) {
    xhe_log('reboot', 'NO_START reason=xhe_objects_missing', 'WARN');
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
            if (in_array($c, $codeNeedList, true)) {
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
    // Fallback: read last exported errors_{userId}_*.json (same source as TG error notifications)
    $base = function_exists('runtime_base_dir') ? runtime_base_dir() : 'c:\\jetinno_runtime';
    $exportDir = rtrim($base, "\\/") . "\\export";
    $pattern = $exportDir . "\\errors_{$userId}_*.json";
    $files = glob($pattern);
    if (is_array($files) && count($files) > 0) {
        usort($files, function($a,$b){ return filemtime($b) <=> filemtime($a); });
        $lastFile = $files[0];
        $raw = @file_get_contents($lastFile);
        $j = json_decode((string)$raw, true);
        if (is_array($j) && isset($j['by_vmc']) && is_array($j['by_vmc'])) {
            foreach ($j['by_vmc'] as $vmcNo => $levels) {
                if (!is_array($levels)) continue;
                $rows = $levels['ERROR'] ?? [];
                if (!is_array($rows) || count($rows) === 0) continue;
                foreach ($rows as $row) {
                    // exported JSON uses Turkish keys, but our helpers err_col_code/time may still work if keys match
                    $codeVal = (string)($row['Hata kodu'] ?? $row['Hata Kodu'] ?? $row['error_code'] ?? '');
                    $codeVal = safe_s($codeVal);
                    if (!in_array($codeVal, $codeNeedList, true)) continue;

                    $dt = (string)($row['Oluşma zamanı'] ?? $row['Olusma zamani'] ?? $row['time'] ?? '');
                    $dt = safe_s($dt);
                    $ts = parse_ts($dt);

                    if (!isset($targets[(string)$vmcNo])) {
                        $targets[(string)$vmcNo] = ['row'=>$row, 'ts'=>$ts];
                    } else {
                        $prevTs = (int)($targets[(string)$vmcNo]['ts'] ?? 0);
                        if ($ts > $prevTs) $targets[(string)$vmcNo] = ['row'=>$row, 'ts'=>$ts];
                    }
                }
            }
            if ($targets) {
                xhe_log('reboot', 'TARGETS source=last_errors_json file=' . basename($lastFile) . ' count=' . count($targets), 'INFO');
            }
        }
    }
}

if (!$targets) {
    xhe_log('reboot', 'NO_START reason=no_boiler_targets', 'INFO');
    return;
}

xhe_log('reboot', 'TARGETS final count=' . count($targets) . ' source=errors', 'INFO');


// HTML verification of boiler error page removed.
// Reboot works directly from data produced by modules/errors.php or last errors_*.json export.

// If reboot notify disabled, we still reboot
$notifyReboot = function_exists('isTelegramNotifyEnabled')
    ? isTelegramNotifyEnabled($acc, 'reboot')
    : (bool)($acc['telegram_notify']['reboot'] ?? true);

if (!$notifyReboot) {
    xhe_log('reboot', "Notify disabled (but reboot will run) account={$accountKey}", 'DEBUG');
}

// Telegram chat id for this account
$chatId = $acc['telegram_chat_id'] ?? null;



// Resolve latest device status per VMC from export files (prefer JSON, fallback CSV)
$resolveDeviceStatuses = function() use ($userId) {
    $base = function_exists('runtime_base_dir') ? runtime_base_dir() : 'c:\\jetinno_runtime';
    $exportDir = rtrim($base, "\\/") . "\\export";
    $out = [];

    $normalizeStatus = function($row) {
        if (!is_array($row)) return '';
        $candidates = [
            'Device status','device_status','status','Status','Durum','Cihaz durumu','Cihaz Durumu',
            'Online status','online_status','is_online','Çevrimiçi durumu','Cihaz durumu / status'
        ];
        foreach ($candidates as $k) {
            if (array_key_exists($k, $row)) {
                $v = safe_s((string)$row[$k]);
                if ($v !== '') return mb_strtolower($v, 'UTF-8');
            }
        }
        foreach ($row as $k => $v) {
            $kk = mb_strtolower(safe_s((string)$k), 'UTF-8');
            if (strpos($kk, 'status') !== false || strpos($kk, 'durum') !== false || strpos($kk, 'online') !== false) {
                $vv = safe_s((string)$v);
                if ($vv !== '') return mb_strtolower($vv, 'UTF-8');
            }
        }
        return '';
    };

    $extractVmc = function($row) {
        if (!is_array($row)) return '';
        $candidates = ['VMC','vmc','vmc_no','VMC No','Cihaz numarası','Cihaz Numarası','device_id','Device ID'];
        foreach ($candidates as $k) {
            if (array_key_exists($k, $row)) {
                $v = preg_replace('~\D+~', '', (string)$row[$k]);
                if ($v !== '') return $v;
            }
        }
        foreach ($row as $k => $v) {
            $kk = mb_strtolower(safe_s((string)$k), 'UTF-8');
            if (strpos($kk, 'vmc') !== false || strpos($kk, 'cihaz') !== false || strpos($kk, 'device') !== false) {
                $vv = preg_replace('~\D+~', '', (string)$v);
                if ($vv !== '') return $vv;
            }
        }
        return '';
    };

    $jsonFiles = glob($exportDir . "\\device_{$userId}_*.json");
    if (is_array($jsonFiles) && count($jsonFiles) > 0) {
        usort($jsonFiles, function($a,$b){ return filemtime($b) <=> filemtime($a); });
        $raw = @file_get_contents($jsonFiles[0]);
        $j = json_decode((string)$raw, true);
        $rows = [];
        if (is_array($j)) {
            if (isset($j['rows']) && is_array($j['rows'])) $rows = $j['rows'];
            elseif (isset($j[0]) && is_array($j[0])) $rows = $j;
        }
        foreach ($rows as $row) {
            $vmc = $extractVmc($row);
            if ($vmc === '') continue;
            $out[$vmc] = [
                'status' => $normalizeStatus($row),
                'source' => basename($jsonFiles[0]),
            ];
        }
        if ($out) return $out;
    }

    $csvFiles = glob($exportDir . "\\device_{$userId}_*.csv");
    if (is_array($csvFiles) && count($csvFiles) > 0) {
        usort($csvFiles, function($a,$b){ return filemtime($b) <=> filemtime($a); });
        $fp = @fopen($csvFiles[0], 'r');
        if ($fp) {
            $headers = fgetcsv($fp);
            if (is_array($headers)) {
                while (($rowVals = fgetcsv($fp)) !== false) {
                    $row = [];
                    foreach ($headers as $i => $h) $row[(string)$h] = $rowVals[$i] ?? '';
                    $vmc = $extractVmc($row);
                    if ($vmc === '') continue;
                    $out[$vmc] = [
                        'status' => $normalizeStatus($row),
                        'source' => basename($csvFiles[0]),
                    ];
                }
            }
            fclose($fp);
        }
    }

    return $out;
};

$deviceStatuses = $resolveDeviceStatuses();
$deviceIsOffline = function(string $vmc) use ($deviceStatuses) {
    $status = mb_strtolower((string)($deviceStatuses[$vmc]['status'] ?? ''), 'UTF-8');
    if ($status === '') return false; // unknown => do not block reboot
    return (
        strpos($status, 'offline') !== false ||
        strpos($status, 'off-line') !== false ||
        strpos($status, 'çevrimdışı') !== false ||
        strpos($status, 'cevrimdisi') !== false ||
        strpos($status, 'disconnected') !== false ||
        strpos($status, 'not online') !== false
    );
};

// --- UI procedure (as in legacy all_in_one.php) ---
$confirmSpanNumber = 350;
$machineInputNumber = 40;
$rebootBtnNumber = 55;

foreach ($targets as $vmcNo => $info) {
    $targetVmc = (string)$vmcNo;
    $targetRow = is_array($info['row'] ?? null) ? $info['row'] : [];

    $errCode = safe_s((string)($targetRow[err_col_code()] ?? ''));
    if ($errCode === 'ERROR:7100') {
        $today = date('Y-m-d');
        $bucket = $state['reboot_daily']['7100'][$targetVmc] ?? ['date' => $today, 'count' => 0];
        $bucketDate = (string)($bucket['date'] ?? $today);
        $bucketCount = (int)($bucket['count'] ?? 0);
        if ($bucketDate !== $today) {
            $bucketDate = $today;
            $bucketCount = 0;
        }
        if ($bucketCount >= 3) {
            xhe_log('reboot', "NO_START vmc={$targetVmc} reason=daily_limit_7100 limit=3 date={$today}", 'INFO');
            continue;
        }
    }

    // Global cooldown (across accounts) per machine
    $lastTs = (int)($state['reboot_global']['boiler'][$targetVmc]['ts'] ?? 0);
    if ($lastTs > 0 && (time() - $lastTs) <= $cooldownSec) {
        $byAcc = (string)($state['reboot_global']['boiler'][$targetVmc]['acc'] ?? '');
        xhe_log('reboot', "NO_START vmc={$targetVmc} reason=cooldown last_acc={$byAcc} age_sec=".(time()-$lastTs)." left_sec=".max(0, ($cooldownSec-(time()-$lastTs))), 'INFO');
        continue;
    }

    if ($deviceIsOffline($targetVmc)) {
        $statusSrc = (string)($deviceStatuses[$targetVmc]['source'] ?? 'device_status');
        $statusVal = (string)($deviceStatuses[$targetVmc]['status'] ?? 'offline');
        xhe_log('reboot', "POSTPONE vmc={$targetVmc} reason=device_offline status={$statusVal} source={$statusSrc}", 'INFO');
        continue;
    }

    $machineUrl = "https://saas-hk.jetinno.com/device_info?vmc_no=" . urlencode($targetVmc) . "&admin=";
    xhe_log('reboot', "MACHINE_URL direct_from_error vmc={$targetVmc} url={$machineUrl}", 'DEBUG');

    $location = function_exists('getLocation') ? (getLocation($locations, $targetVmc) ?? '') : '';
    if ($location === '') $location = 'Unknown';
    $dt = safe_s((string)($targetRow[err_col_time()] ?? date('Y-m-d H:i:s')));

    xhe_log('reboot', "START vmc={$targetVmc} loc={$location} reason=boiler_auto", 'INFO');

    $browser->close_all_tabs();
    $browser->navigate($machineUrl);
    $browser->wait(5);

    // control icon
    $image->click_by_src("https://saas-hk.jetinno.com/home/images/control.png", false);
    $browser->wait(4);

    // reboot icon
    $image->click_by_src("https://saas-hk.jetinno.com/home/images/reboot.png", false);
    $browser->wait(3);

    //// confirm$anchor->click_by_inner_text("makine", false);

    if (isset($span) && is_object($span)) {
        $span->click_by_number($confirmSpanNumber);
        $browser->wait(3);
    }

    // tab "makine" (machine)
    if (isset($anchor) && is_object($anchor)) {
        $anchor->click_by_inner_html("<span class=\"text\">makine</sp", false);
        $browser->wait(3);
    }
    $browser->wait(3);
$btn->click_by_number(37);

    // input vmc
    $input->click_by_number($machineInputNumber);
    $input->set_value_by_number($machineInputNumber, $targetVmc);
    $browser->wait(3);
    
  
  $btn->set_focus_by_number($rebootBtnNumber);
   $btn->click_by_number($rebootBtnNumber);
    $browser->wait(3);
//sleep(20);
    // save global reboot state
    $state['reboot_global']['boiler'][$targetVmc] = ['ts' => time(), 'acc' => (string)$accountKey];

    if ($errCode === 'ERROR:7100') {
        $today = date('Y-m-d');
        $bucket = $state['reboot_daily']['7100'][$targetVmc] ?? ['date' => $today, 'count' => 0];
        $bucketDate = (string)($bucket['date'] ?? $today);
        $bucketCount = (int)($bucket['count'] ?? 0);
        if ($bucketDate !== $today) {
            $bucketDate = $today;
            $bucketCount = 0;
        }
        $bucketCount++;
        $state['reboot_daily']['7100'][$targetVmc] = ['date' => $today, 'count' => $bucketCount];
        xhe_log('reboot', "LIMIT7100 vmc={$targetVmc} date={$today} count={$bucketCount}/3", 'INFO');
    }

    xhe_log('reboot', "DONE vmc={$targetVmc} saved_state=1", 'INFO');

    // telegram notify (optional)
    if ($notifyReboot && $chatId !== null && function_exists('tg_notify')) {
		$errCode = safe_s((string)($targetRow[err_col_code()] ?? 'UNKNOWN'));
        $msg = "🔥 BOILER REBOOT".
            "\nVMC: {$targetVmc}".
            "\nLocation: {$location}".
            "\nError: {$errCode}".
            "\nDetected: {$dt}".
            "\nAccount: {$accountKey}";
        tg_notify('reboot', $msg, (string)$chatId, $accountKey.'|boiler|'.$targetVmc, $notifyState);
    }
}

