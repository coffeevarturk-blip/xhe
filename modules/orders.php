<?php

// AUTO-GENERATED MODULE from all_in_one_2_0_refactored.php
// DO NOT EDIT LOGIC HERE unless you know what you're doing.

// ========== 3) FAILED ORDERS (isOK=0) ==========
        $datemonth = date('Y-m');
        $perPageOrd = 200;
        $maxPagesOrd = 200;

        $allOrd = [];
        for ($op=1; $op<=$maxPagesOrd; $op++) {
            $url = build_failed_orders_csv_url($userId, $datemonth, $op, $perPageOrd);
            $res = curl_get_with_cookies($url, $cookieStr, ["Accept: text/csv,*/*"], "https://saas-hk.jetinno.com/order");
            $looksCsv = ($res['ok'] && stripos((string)$res['ct'], 'text/csv') !== false);
            if (!$res['ok'] || !$looksCsv || $res['bytes'] < 5) break;

            $rows = parse_csv_rows((string)$res['body']);
            if (count($rows) < 2) break;

            $assoc = rows_to_assoc($rows);
            if (count($assoc) === 0) break;

            foreach ($assoc as $r) $allOrd[] = $r;
            xhe_log('orders_fail', "CSV page={$op} rows=" . count($assoc) . " total=" . count($allOrd), "INFO");

            if (count($assoc) < $perPageOrd) break;
        }

        $stats = process_failed_orders_csv_rows(
            $allOrd, $accName, $userId, $chatId, $state, $notifyState, $acc, $accountKey, 3600
        );
        xhe_log('orders_fail', "STATS found={$stats['found']} sent={$stats['sent']} skipped_known={$stats['skipped_known']} bad={$stats['bad_rows']}", "INFO");

        $jsonOrdPath = $outDir . "\\orders_fail_{$userId}_{$stamp}.json";
        @file_put_contents($jsonOrdPath, json_encode([
            'account' => $accName,
            'user_id' => $userId,
            'generated_at' => date('c'),
            'source' => 'orders_csv_isOK0_export_1',
            'datemonth' => $datemonth,
            'rows' => count($allOrd),
            'stats' => $stats,
        ], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));