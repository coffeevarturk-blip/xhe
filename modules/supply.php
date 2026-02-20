<?php

// AUTO-GENERATED MODULE from all_in_one_2_0_refactored.php
// DO NOT EDIT LOGIC HERE unless you know what you're doing.

// ========== 2) SUPPLY ==========
        $notifySupply = function_exists('isTelegramNotifyEnabled')
            ? isTelegramNotifyEnabled($acc, 'supply')
            : (bool)($acc['telegram_notify']['supply'] ?? true);

        $parsedSupply = [];
        $supplySource = 'none';

        // CSV first
        $perPageSup = 500;
        $maxPagesSup = 50;
        $allSupplyRows = [];

        for ($sp=1; $sp<=$maxPagesSup; $sp++) {
            $url = build_supply_csv_url($userId, $sp, $perPageSup);
            $res = curl_get_with_cookies($url, $cookieStr, ["Accept: text/csv,*/*"], "https://saas-hk.jetinno.com/supply");
            $looksCsv = ($res['ok'] && stripos((string)$res['ct'], 'text/csv') !== false);
            if (!$res['ok'] || !$looksCsv || $res['bytes'] < 5) break;

            $rows = parse_csv_rows((string)$res['body']);
            if (count($rows) < 2) break;

            $assoc = rows_to_assoc($rows);
            if (count($assoc) === 0) break;

            foreach ($assoc as $r) $allSupplyRows[] = $r;
            xhe_log('supply', "CSV page={$sp} rows=" . count($assoc) . " total=" . count($allSupplyRows), "INFO");

            if (count($assoc) < $perPageSup) break;
        }

        if (count($allSupplyRows) > 0) {
            $parsedSupply = supply_rows_to_struct($allSupplyRows);
            if (count($parsedSupply) > 0) $supplySource = 'csv_export_1';
        }

        // fallback HTML
        if ($supplySource === 'none') {
            if (function_exists('parseSupplyListHtml')) {
                $url = build_supply_html_url($userId);
                $res = curl_get_with_cookies($url, $cookieStr, ["Accept: text/html,*/*"], "https://saas-hk.jetinno.com/supply");
                if ($res['ok'] && $res['bytes'] > 1000) {
                    $parsedSupply = parseSupplyListHtml((string)$res['body']);
                    if (is_array($parsedSupply)) $supplySource = 'html_export_0';
                }
            }
        }

        $jsonSupPath = $outDir . "\\supply_{$userId}_{$stamp}.json";
        @file_put_contents($jsonSupPath, json_encode([
            'account' => $accName,
            'user_id' => $userId,
            'generated_at' => date('c'),
            'source' => $supplySource,
            'devices' => count($parsedSupply),
            'rows' => $parsedSupply,
        ], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        xhe_log('supply', "SAVED JSON={$jsonSupPath} source={$supplySource} devices=" . count($parsedSupply), "INFO");

        // === UPDATE LOCATIONS FROM SUPPLY (all machines visible here) ===
        // Persist per-user map VMC => address in: state\jetinno_locations_<userId>.json
        // And load into $locations for downstream modules (orders, reboots, etc.)
        $locFile = runtime_base_dir() . "\\state\\jetinno_locations_{$userId}.json";
        $locState = [];
        if (is_file($locFile)) {
            $tmp = json_decode((string)@file_get_contents($locFile), true);
            if (is_array($tmp)) $locState = $tmp;
        }
        $locMap = is_array($locState['map'] ?? null) ? $locState['map'] : [];
        if (!is_array($locMap)) $locMap = [];

        if (is_array($parsedSupply) && count($parsedSupply) > 0) {
            foreach ($parsedSupply as $devRow) {
                if (!is_array($devRow)) continue;
                $vmc = safe_s((string)($devRow['device_id'] ?? ''));
                if ($vmc === '' || !ctype_digit($vmc)) continue;

                $addr = safe_s((string)($devRow['address'] ?? ''));
                if ($addr === '') continue;

                if (!isset($locMap[$vmc]) || $locMap[$vmc] !== $addr) {
                    $locMap[$vmc] = $addr;
                }
            }
            $locState = [
                'user_id' => (int)$userId,
                'updated_at' => date('c'),
                'updated_ts' => time(),
                'map' => $locMap,
            ];
            @file_put_contents($locFile, json_encode($locState, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
            xhe_log('supply', "LOCATIONS updated file={$locFile} machines=" . count($locMap), "DEBUG");
        }

        // Make locations available immediately in this run
        if (!isset($locations) || !is_array($locations)) $locations = [];
        $locations = $locMap + $locations;

        // === sync state update (machine status last sync time) ===
        // We capture "Yükleme süresi" (upload_time) from supply parsing and persist per VMC.
        // Separate file (per user): state\jetinno_sync_state_<userId>.json
        $syncStateFile = runtime_base_dir() . "\\state\\jetinno_sync_state_{$userId}.json";
        $syncState = [];
        if (is_file($syncStateFile)) {
            $tmp = json_decode((string)@file_get_contents($syncStateFile), true);
            if (is_array($tmp)) $syncState = $tmp;
        }
        if (!isset($syncState['machines']) || !is_array($syncState['machines'])) $syncState['machines'] = [];

        if (is_array($parsedSupply) && count($parsedSupply) > 0) {
            foreach ($parsedSupply as $row) {
                if (!is_array($row)) continue;
                $vmc = safe_s((string)($row['device_id'] ?? ''));
                if ($vmc === '' || !ctype_digit($vmc)) continue;

                $uploadTime = safe_s((string)($row['upload_time'] ?? '')); // "Yükleme süresi"
                $syncState['machines'][$vmc] = [
                    'vmc'         => $vmc,
                    'upload_time' => $uploadTime,
                    'updated_at'  => date('c'),
                    'updated_ts'  => time(),
                ];
            }
            @file_put_contents($syncStateFile, json_encode($syncState, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
            xhe_log('supply', "SYNC_STATE updated file={$syncStateFile} machines=" . count($syncState['machines']), "DEBUG");
        }

;

        // notify (one message per device, Address only)
        if ($notifySupply && $chatId !== null && is_array($parsedSupply) && count($parsedSupply) > 0) {

            if (!isset($state['supplies_alert'][$accountKey]) || !is_array($state['supplies_alert'][$accountKey])) {
                $state['supplies_alert'][$accountKey] = [];
            }

            foreach ($parsedSupply as $devRow) {
                $deviceId = (int)($devRow['device_id'] ?? 0);
                if ($deviceId <= 0) continue;

                $addressLine = safe_s((string)($devRow['address'] ?? ''));
                $supplies = $devRow['supplies'] ?? null;
                if (!is_array($supplies)) continue;

                $lowItems = [];
                $markKeys = [];

                foreach ($supplies as $supplyId => $s) {
                    $rawName = trim((string)($s['name'] ?? ''));
                    $value   = (int)($s['value'] ?? 0);
                    if ($rawName === '') continue;

                    $key = supply_key_from_name($rawName);
                    if ($key === null) continue;

                    $limit = (int)($supplyThresholds[$key] ?? -1);
                    if ($limit < 0) continue;

                    // anti-spam per device+key
                    if (!isset($state['supplies_alert'][$accountKey][$deviceId]) || !is_array($state['supplies_alert'][$accountKey][$deviceId])) {
                        $state['supplies_alert'][$accountKey][$deviceId] = [];
                    }

                    $lastTs = (int)($state['supplies_alert'][$accountKey][$deviceId][$key] ?? 0);
                    if ($lastTs > 0 && (time() - $lastTs) < $supplyCooldown) continue;

                    if ($value <= $limit) {
                        $lowItems[] = "{$rawName} (ID {$supplyId}) = {$value} ≤ {$limit}";
                        $markKeys[] = $key;
                    }
                }

                if (count($lowItems) === 0) continue;

                $msg = "LOW SUPPLY | {$deviceId}";
                if ($addressLine !== '') $msg .= " - {$addressLine}";
                $msg .= "\n" . implode("\n", $lowItems);

                // Supply recommendation: if there are refill tasks, suggest what else can be topped up by full packs.
                $recLines = [];
                $rules = supply_rec_rules();
                foreach ($supplies as $sid2 => $s2) {
                    $n2 = trim((string)($s2['name'] ?? ''));
                    if ($n2 === '') continue;
                    $v2 = (int)($s2['value'] ?? 0);

                    $k2 = supply_rec_key_from_name($n2);
                    if ($k2 === null) continue;
                    if (!isset($rules[$k2])) continue;

                    $cap = (int)($rules[$k2]['cap'] ?? 0);
                    $pack = (int)($rules[$k2]['pack'] ?? 0);
                    $label = (string)($rules[$k2]['label'] ?? $k2);
                    if ($cap <= 0 || $pack <= 0) continue;

                    $free = $cap - $v2;
                    if ($free < $pack) continue;

                    $packs = intdiv($free, $pack);
                    if ($packs <= 0) continue;

                                        // strip_supply_label_container_info
                    $label = preg_replace('/\\s*\\(контейнер[^)]*\\)/u', '', (string)$label);
$recLines[] = "• {$label} — {$packs} пач.";
                }

                if (count($recLines) > 0) {
                    $msg .= "\n\nРекомендация дозаправки:\n" . implode("\n", $recLines);
                }

                $tgKey = $accountKey . '|' . $deviceId . '|low_supply';
                if (function_exists('tg_notify')) {
                    tg_notify('supply', $msg, $chatId, $tgKey, $notifyState);
                    xhe_log('supply', "SEND TG type=supply account={$accName} dev={$deviceId} items=" . count($lowItems) . " chat_id={$chatId}", "INFO");
                } elseif (function_exists('sendmessage')) {
                    sendmessage($msg, $chatId);
                    xhe_log('supply', "SEND TG(fallback) type=supply account={$accName} dev={$deviceId} items=" . count($lowItems), "INFO");
                }

                $now = time();
                foreach ($markKeys as $k) $state['supplies_alert'][$accountKey][$deviceId][$k] = $now;
            }
        } else {
            if (!$notifySupply) xhe_log('supply', "SKIP notify disabled account={$accName}", "DEBUG");
        }

        //