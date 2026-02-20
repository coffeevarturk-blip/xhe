<?php

// AUTO-GENERATED MODULE from all_in_one_2_0_refactored.php
// DO NOT EDIT LOGIC HERE unless you know what you're doing.

// ========== 1) ERRORS ==========
        $perPageErr = 200;
        $maxPagesErr = 200;

        $allErr = [];
        $csvErrOut = "";
        $headerWritten = false;

        for ($p=1; $p<=$maxPagesErr; $p++) {
            $url = build_error_url($userId, $p, $perPageErr);
            $res = curl_get_with_cookies($url, $cookieStr, ["Accept: text/csv,*/*"], "https://saas-hk.jetinno.com/error");

            xhe_log('error_scan', "DOWNLOAD export=1 ok=" . ($res['ok']?'true':'false') . " http={$res['http']} ct={$res['ct']} bytes={$res['bytes']} err={$res['err']}", $res['ok'] ? "INFO" : "ERROR");

            $looksCsv = ($res['ok'] && stripos((string)$res['ct'], 'text/csv') !== false);
            if (!$res['ok'] || !$looksCsv || $res['bytes'] < 5) break;

            $rows = parse_csv_rows((string)$res['body']);
            if (count($rows) < 2) break;

            if (!$headerWritten) {
                $csvErrOut .= implode(";", $rows[0]) . "\n";
                $headerWritten = true;
            }
            for ($i=1; $i<count($rows); $i++) $csvErrOut .= implode(";", $rows[$i]) . "\n";

            $assoc = rows_to_assoc($rows);
            if (count($assoc) === 0) break;
            foreach ($assoc as $r) $allErr[] = $r;

            if (count($assoc) < $perPageErr) break;
        }

        $csvErrPath  = $outDir . "\\errors_{$userId}_{$stamp}.csv";
        $jsonErrPath = $outDir . "\\errors_{$userId}_{$stamp}.json";
        @file_put_contents($csvErrPath, $csvErrOut);

        $byVmc = [];

        $notifyErrors = function_exists('isTelegramNotifyEnabled')
            ? isTelegramNotifyEnabled($acc, 'errors')
            : (bool)($acc['telegram_notify']['errors'] ?? true);

        foreach ($allErr as $row) {
            $vmc   = safe_s((string)($row[err_col_vmc()] ?? ''));
            $addr  = safe_s((string)($row[err_col_addr()] ?? ''));
            $code  = safe_s((string)($row[err_col_code()] ?? ''));
            $desc  = safe_s((string)($row[err_col_desc()] ?? ''));
            $type  = safe_s((string)($row[err_col_type()] ?? ''));
            $st    = safe_s((string)($row[err_col_status()] ?? ''));
            $dt    = safe_s((string)($row[err_col_time()] ?? ''));
            $seri  = safe_s((string)($row[err_col_serial()] ?? ''));

            if ($vmc === '') $vmc = 'UNKNOWN';
            $lvl = parse_level_from_code($code);

            if (!isset($byVmc[$vmc])) $byVmc[$vmc] = ['ERROR'=>[], 'WARNING'=>[], 'UNKNOWN'=>[]];
            $byVmc[$vmc][$lvl][] = $row;

            // notify (dedup by user|vmc|code)
            if (!$notifyErrors || $chatId === null) continue;

            $dedupKey = $userId . '|' . $vmc . '|' . $code;
            $rowTs = parse_ts($dt);
            $prevTs = (int)($state['errors_notify'][$dedupKey] ?? 0);

            $should = true;
            if ($rowTs > 0 && $rowTs <= $prevTs) $should = false;
            if ($should && $rowTs === 0 && $seri !== '') {
                $prevSer = (string)($state['errors_notify_ser'][$dedupKey] ?? '');
                if ($prevSer === $seri) $should = false;
            }

            if (!$should) continue;

            $title = ($lvl === 'ERROR') ? '🚨 ERROR' : (($lvl === 'WARNING') ? '⚠️ WARNING' : '⚠️ ALERT');
            $msg =
                "{$title} | {$accName}\n" .
                "VMC: {$vmc} - {$addr}\n" .
                "Code: {$code}\n" .
                "Desc: {$desc}\n" .
                "Type: {$type}\n" .
                "Status: {$st}\n" .
                "Time: {$dt}";

            if (function_exists('tg_notify')) {
                $tgKey = $accName . '|' . $vmc . '|' . $code;
                tg_notify('errors', $msg, $chatId, $tgKey, $notifyState);
                xhe_log('errors', "SEND TG type=errors account={$accName} vmc={$vmc} code={$code} chat_id={$chatId}", "INFO");
            } elseif (function_exists('sendmessage')) {
                sendmessage($msg, $chatId);
                xhe_log('errors', "SEND TG(fallback) type=errors account={$accName} vmc={$vmc} code={$code}", "INFO");
            }

            if ($rowTs > 0) $state['errors_notify'][$dedupKey] = $rowTs;
            if ($seri !== '') $state['errors_notify_ser'][$dedupKey] = $seri;
        }

        $summary = [];
        foreach ($byVmc as $vmc => $bucket) {
            $summary[$vmc] = [
                'errors' => count($bucket['ERROR']),
                'warnings' => count($bucket['WARNING']),
                'unknown' => count($bucket['UNKNOWN']),
            ];
        }

        @file_put_contents($jsonErrPath, json_encode([
            'account' => $accName,
            'user_id' => $userId,
            'generated_at' => date('c'),
            'source' => 'error_csv_export_1',
            'items_count' => count($allErr),
            'machines' => count($byVmc),
            'summary' => $summary,
            'by_vmc' => $byVmc,
        ], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));

        xhe_log('error_scan', "SAVED CSV={$csvErrPath} JSON={$jsonErrPath} machines=" . count($byVmc), "INFO");

        // If boiler ERROR:7300 is present, persist a reboot-detection state file for ops visibility.
        // File: C:\jetinno_runtime\state\jetinno_reboot_state_<userId>.json
        if (function_exists('reboot_state_mark_detected')) {
            foreach ($byVmc as $vmcNo => $levels) {
                $rowsErr = is_array($levels['ERROR'] ?? null) ? $levels['ERROR'] : [];
                if (!$rowsErr) continue;
                foreach ($rowsErr as $row) {
                    $code = safe_s((string)($row[err_col_code()] ?? ''));
                    if ($code !== 'ERROR:7300') continue;
                    $dt   = safe_s((string)($row[err_col_time()] ?? ''));
                    $addr = safe_s((string)($row[err_col_addr()] ?? ''));
                    $ts   = parse_ts($dt);
                    reboot_state_mark_detected((int)$userId, (string)$vmcNo, (int)$ts, (string)$dt, (string)$addr, 'ERROR:7300');
                }
            }
        }


        