<?php

// AUTO-GENERATED MODULE from all_in_one_2_0_refactored.php
// DO NOT EDIT LOGIC HERE unless you know what you're doing.

// ========== 4) SALES (isOK=1) ==========
        $notifySales = function_exists('isTelegramNotifyEnabled')
            ? isTelegramNotifyEnabled($acc, 'sales')
            : (bool)($acc['telegram_notify']['sales'] ?? false);

        $datemonthSales = date('Y-m');
        $today = date('Y-m-d');
        // Jetinno обычно принимает daterange формата "YYYY-MM-DD - YYYY-MM-DD"
        $daterangeToday = $today . ' - ' . $today;
        $perPageSales = 200;
        // maxPagesSales disabled (only first page used)

        $allSalesRows = [];
        $salesSkip = false;

// --- листаем страницы, пока на странице есть заказы за СЕГОДНЯ ---
$todayStr = $today; // YYYY-MM-DD
$page = 1;
$perPageSales = 200;
$maxPagesSales = 300;

// если в CSV нет времени — не сможем понять где остановиться, тогда только первая страница
$canStopByDate = true;

for ($page=1; $page<=$maxPagesSales; $page++) {
    $url = build_sales_orders_csv_url($userId, $datemonthSales, $daterangeToday, $page, $perPageSales);
    $res = curl_get_with_cookies($url, $cookieStr, ["Accept: text/csv,*/*"], "https://saas-hk.jetinno.com/order");
    $looksCsv = ($res['ok'] && stripos((string)$res['ct'], 'text/csv') !== false);

    if (!$res['ok'] || !$looksCsv || $res['bytes'] < 5) {
        xhe_log('sales', "CSV page={$page} failed http={$res['http']} ct={$res['ct']} bytes={$res['bytes']} err={$res['err']}", "WARNING");
        break;
    }

    $rows = parse_csv_rows((string)$res['body']);
    if (count($rows) < 2) {
        xhe_log('sales', "CSV page={$page} empty -> stop", "INFO");
        break;
    }

    $assoc = rows_to_assoc($rows);
    if (count($assoc) === 0) {
        xhe_log('sales', "CSV page={$page} no rows -> stop", "INFO");
        break;
    }

    // Найдём колонку времени один раз по первой строке
    $timeCol = null;
    if (isset($assoc[0]) && is_array($assoc[0])) {
        $timeCol = find_col($assoc[0], ['time','create time','created','oluşma','sipariş zamanı','satın alma zamanı','satınalma zamanı','satın alma zamani','satinalma zamani','order time','更新时间','时间']);
    }
    if ($timeCol === null) $canStopByDate = false;

    if ($timeCol === null) {
        // В CSV не найдена колонка времени.
        // Доверяем фильтру daterangeToday (YYYY-MM-DD - YYYY-MM-DD) и считаем только первую страницу,
        // чтобы вернуть уведомления о продажах как раньше.
        $hdrKeys = isset($assoc[0]) && is_array($assoc[0]) ? implode(' | ', array_keys($assoc[0])) : '';
        xhe_log('sales', "NO TIME COLUMN in sales CSV; fallback to daterange-only mode (page=1). headers={$hdrKeys}", 'WARNING');
        $canStopByDate = false;
        // собираем всё что пришло на странице 1
        foreach ($assoc as $r) {
            $allSalesRows[] = $r;

            // --- Save TOP snapshot after EACH added sale row ---
            $aggNow = sales_aggregate($allSalesRows);

            // Build TOP-5 by amount
            $rowsNow = [];
            $byVmcNow = (array)($aggNow['by_vmc'] ?? []);
            foreach ($byVmcNow as $k => $v) {
                $vmc = '';
                if (is_string($k) && preg_match('~^\d{4,}$~', $k)) $vmc = $k;
                if (!$vmc && is_array($v)) $vmc = (string)($v['vmc'] ?? $v['vmc_no'] ?? $v['device_no'] ?? '');
                $vmc = preg_replace('~\D+~', '', (string)$vmc);
                if ($vmc === '') continue;

                $amount = (float)($v['amount'] ?? $v['total_amount'] ?? $v['sum'] ?? 0);
                $count  = (int)($v['count'] ?? $v['total_count'] ?? $v['cnt'] ?? 0);

                // Address: prefer what's already in row
                $addr = (string)($v['address'] ?? $v['location'] ?? $v['name'] ?? '');

                $rowsNow[] = ['vmc' => $vmc, 'address' => $addr, 'amount' => $amount, 'count' => $count];
            }

            usort($rowsNow, function($a, $b) {
                $da = (float)($a['amount'] ?? 0);
                $db = (float)($b['amount'] ?? 0);
                if ($db == $da) return (int)($b['count'] ?? 0) <=> (int)($a['count'] ?? 0);
                return $db <=> $da;
            });
            $top5 = array_slice($rowsNow, 0, 5);

            $topPath = $outDir . "\\sales_top_{$userId}.json";
            $payload = [
                'account'      => $accName,
                'user_id'      => $userId,
                'updated_at'   => date('c'),
                'datemonth'    => $datemonthSales,
                'daterange'    => $today . "~" . $today,
                'rows_today'   => count($allSalesRows),
                'total_amount' => (float)($aggNow['total_amount'] ?? 0),
                'total_count'  => (int)($aggNow['total_count'] ?? 0),
                'top5'         => $top5,
            ];

            // atomic write
            $tmp = $topPath . ".tmp";
            @file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            @rename($tmp, $topPath);
        }
break;
    }

    $added = 0;
    $hasToday = false;
    $hasOlder = false;

    foreach ($assoc as $r) {
        $timeVal = ($timeCol !== null) ? safe_s((string)($r[$timeCol] ?? '')) : '';
        if ($timeVal === '' && $timeCol !== null) {
            // no time in row - cannot decide
            $canStopByDate = false;
        }

        if ($timeCol === null) {
            // если не можем понять дату — берём всё и выходим после первой страницы
            $allSalesRows[] = $r;
            $added++;
            continue;
        }

        $ts = parse_ts($timeVal);
        $d  = $ts > 0 ? date('Y-m-d', $ts) : '';

        if ($d === $todayStr) {
            $allSalesRows[] = $r;
            $added++;
            $hasToday = true;
        } elseif ($d !== '') {
            $hasOlder = true;
        }
    }

    xhe_log('sales', "CSV page={$page} rows=" . count($assoc) . " added_today={$added} total_today=" . count($allSalesRows), "INFO");

    if (!$canStopByDate) {
        // нет колонок времени — выходим после первой страницы
        if ($page >= 1) break;
    }

    // логика остановки:
    // - если на странице нет today => дальше смысла нет (при сортировке по времени DESC)
    // - если есть older и есть today => дальше уже пойдут только older => стоп
    if (!$hasToday) break;
    if ($hasOlder) break;

    // иначе продолжаем на следующую страницу (всё ещё today)
}if ($salesSkip) {
        xhe_log('sales', "SKIP sales aggregation/notify", 'WARNING');
    } else {
$agg = sales_aggregate($allSalesRows);
        $salesJsonPath = $outDir . "\\sales_{$userId}_{$stamp}.json";
        @file_put_contents($salesJsonPath, json_encode([
            'account' => $accName,
            'user_id' => $userId,
            'generated_at' => date('c'),
            'source' => 'orders_csv_isOK1_export_1_today',
            'datemonth' => $datemonthSales,
            'daterange' => $daterangeToday,
            'rows' => count($allSalesRows),
            'total_amount' => $agg['total_amount'],
            'total_count' => $agg['total_count'],
            'by_vmc' => $agg['by_vmc'],
        ], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        xhe_log('sales', "SAVED JSON={$salesJsonPath} total=" . number_format($agg['total_amount'], 2, '.', '') . " count={$agg['total_count']}", "INFO");

        // notify sales (anti-spam by day: send only if total increased)
        if ($notifySales && $chatId !== null) {
            if (!isset($state['sales_notify']) || !is_array($state['sales_notify'])) $state['sales_notify'] = [];
            if (!isset($state['sales_notify'][$accountKey]) || !is_array($state['sales_notify'][$accountKey])) $state['sales_notify'][$accountKey] = [];

            $dayKey = date('Y-m-d') . '|' . $daterangeToday;
            $prevTotal = (float)($state['sales_notify'][$accountKey][$dayKey] ?? 0.0);
            $curTotal  = (float)$agg['total_amount'];

            // send if increased at least 0.01
            if ($curTotal > $prevTotal + 0.009) {
                // top 5 machines
                $topLines = [];
                $i = 0;
                foreach ($agg['by_vmc'] as $vmcNo => $info) {
                    $i++;
                    if ($i > 5) break;
                    $addr = get_location((int)$userId, (string)$vmcNo);
                    if ($addr === '') $addr = trim((string)($info['address'] ?? ''));
                    // guard: some exports put model (e.g. JL300) into address column
                    if (preg_match('/^JL\d+/i', $addr)) $addr = '';
                    $topLines[] = "{$i}) {$vmcNo}" . ($addr !== '' ? " - {$addr}" : "") .
                        " | " . number_format((float)$info['amount'], 2, '.', '') . " TL" .
                        " ({$info['count']})";
                }

                $msg =
                    "💰 SALES UPDATE | {$accName}\n" .
                    "Today: {$today}\n" .
                    "Total: " . number_format($curTotal, 2, '.', '') . " TL ({$agg['total_count']})\n" .
                    (count($topLines) ? ("Top:\n" . implode("\n", $topLines)) : "");

                $tgKey = $accountKey . '|sales|' . $dayKey;
                if (function_exists('tg_notify')) {
                    tg_notify('sales', $msg, $chatId, $tgKey, $notifyState);
                    xhe_log('sales', "SEND TG type=sales account={$accName} chat_id={$chatId} total=" . number_format($curTotal,2,'.',''), "INFO");
                } elseif (function_exists('sendmessage')) {
                    sendmessage($msg, $chatId);
                    xhe_log('sales', "SEND TG(fallback) type=sales account={$accName} total=" . number_format($curTotal,2,'.',''), "INFO");
                }

                $state['sales_notify'][$accountKey][$dayKey] = $curTotal;

                // Syrup stale check (7 days). Uses last 30 days orders to find the most recent sale time per syrup.
                $notifySyrup = $acc['telegram_notify']['syrup'] ?? true;
                if ($notifySyrup) {
                    $start30 = date('Y-m-d', time() - 30*86400);
                    $range30 = $start30 . " - " . $today;
                    $rows30 = [];

                    $maxPagesSyrup = 10;
                    for ($p=1; $p<=$maxPagesSyrup; $p++) {
                        $u = build_sales_orders_csv_url($userId, $datemonthSales, $range30, $p, $perPageSales);
                        $r = curl_get_with_cookies($u, $cookieStr, ["Accept: text/csv,*/*"], "https://saas-hk.jetinno.com/order");
                        $looksCsv = ($r['ok'] && stripos((string)$r['ct'], 'text/csv') !== false);

                        if (!$r['ok'] || !$looksCsv || $r['bytes'] < 5) {
                            xhe_log('syrup', "CSV page={$p} failed http={$r['http']} ct={$r['ct']} bytes={$r['bytes']} err={$r['err']}", "WARNING");
                            break;
                        }

                        $rows = parse_csv_rows((string)$r['body']);
                        if (count($rows) < 2) break;
                        $assoc = rows_to_assoc($rows);
                        if (count($assoc) === 0) break;

                        // filter by time >= start30 (safety)
                        $timeColS = (isset($assoc[0]) && is_array($assoc[0]))
                            ? find_col($assoc[0], ['time','create time','created','oluşma','sipariş zamanı','satın alma zamanı','satınalma zamanı','satın alma zamani','satinalma zamani','order time','更新时间','时间'])
                            : null;

                        if ($timeColS === null) {
                            $hdrKeys = isset($assoc[0]) && is_array($assoc[0]) ? implode(' | ', array_keys($assoc[0])) : '';
                            xhe_log('syrup', "NO TIME COLUMN in syrup scan CSV; headers={$hdrKeys}", 'WARNING');
                            break;
                        }

                        $startTs = strtotime($start30 . " 00:00:00");
                        $addedAny = 0;
                        foreach ($assoc as $ar) {
                            $t = safe_s((string)($ar[$timeColS] ?? ''));
                            $ts = parse_ts($t);
                            if ($ts >= $startTs) {
                                $rows30[] = $ar;
                                $addedAny++;
                            }
                        }
                        if ($addedAny === 0) break;
                    }

                    if (count($rows30) > 0) {
                        $scan = syrup_scan_last_sales($rows30);
                        $persist = syrup_state_merge_and_save($userId, $scan);
                        syrup_notify_stale($acc, $persist, $accName, $userId, $chatId, $state, $notifyState, 7);
                    } else {
                        xhe_log('syrup', "No rows for syrup scan in last 30 days acc={$accName} (using persisted syrup_last_sale)", 'INFO');
                        $persist = syrup_state_load($userId);
                        syrup_notify_stale($acc, $persist, $accName, $userId, $chatId, $state, $notifyState, 7);
                    }
                }

            } else {
                xhe_log('sales', "SKIP notify no change prev=" . number_format($prevTotal,2,'.','') . " cur=" . number_format($curTotal,2,'.',''), "DEBUG");
            }
        } else {
            if (!$notifySales) xhe_log('sales', "SKIP notify disabled account={$accName}", "DEBUG");
        }

    }
