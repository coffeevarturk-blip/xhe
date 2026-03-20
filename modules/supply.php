
<?php

// AUTO-GENERATED MODULE from all_in_one_2_0_refactored.php
// DO NOT EDIT LOGIC HERE unless you know what you're doing.

if (!function_exists('supply_thresholds_by_id')) {
    function supply_thresholds_by_id() {
        return [
            10009 => ['limit' => 1000, 'label' => 'Кофе',            'state_key' => 'coffee_beans'],
            11019 => ['limit' => 300,  'label' => 'Клубника',         'state_key' => 'strawberry_syrup'],
            11018 => ['limit' => 300,  'label' => 'Банан',            'state_key' => 'banana_syrup'],
            11017 => ['limit' => 300,  'label' => 'Карамель',         'state_key' => 'caramel_syrup'],
            11001 => ['limit' => 300,  'label' => 'Орех',             'state_key' => 'hazelnut_syrup'],
            10428 => ['limit' => 1000, 'label' => 'Холодное молоко',  'state_key' => 'cold_milk'],
            10008 => ['limit' => 1000, 'label' => 'Горячее молоко',   'state_key' => 'hot_milk'],
            10006 => ['limit' => 1000, 'label' => 'Какао',            'state_key' => 'cocoa'],
            10003 => ['limit' => 300,  'label' => 'Чай',              'state_key' => 'tea'],
        ];
    }
}

if (!function_exists('supply_rule_by_id')) {
    function supply_rule_by_id($ingredientId) {
        $ingredientId = (int)$ingredientId;
        $thresholds = supply_thresholds_by_id();
        return $thresholds[$ingredientId] ?? null;
    }
}


if (!function_exists('supply_rec_key_by_id')) {
    function supply_rec_key_by_id($ingredientId) {
        $ingredientId = (int)$ingredientId;
        $map = [
            10009 => 'coffee_beans',
            11019 => 'strawberry_syrup',
            11018 => 'banana_syrup',
            11017 => 'caramel_syrup',
            11001 => 'hazelnut_syrup',
            10428 => 'cold_milk',
            10008 => 'hot_milk',
            10006 => 'cocoa',
            10003 => 'tea',
        ];
        return $map[$ingredientId] ?? null;
    }
}


if (!function_exists('supply_weekly_state_file')) {
    function supply_weekly_state_file($userId) {
        $userId = (int)$userId;
                $base = rtrim((string)runtime_base_dir(), '\\/');
        return $base . DIRECTORY_SEPARATOR . 'state' . DIRECTORY_SEPARATOR . "weekly_consumption_{$userId}_state.json";
    }
}

if (!function_exists('supply_weekly_json_file')) {
    function supply_weekly_json_file($userId) {
        $userId = (int)$userId;
        $base = rtrim((string)runtime_base_dir(), "\/");

        $stateCandidates = [
            $base . DIRECTORY_SEPARATOR . 'state' . DIRECTORY_SEPARATOR . "weekly_consumption_{$userId}_state.json",
            $base . DIRECTORY_SEPARATOR . 'export' . DIRECTORY_SEPARATOR . "weekly_consumption_{$userId}_state.json",
            $base . DIRECTORY_SEPARATOR . "weekly_consumption_{$userId}_state.json",
        ];

        foreach ($stateCandidates as $stateFile) {
            if ($stateFile === '' || !is_file($stateFile)) continue;
            $state = json_decode((string)@file_get_contents($stateFile), true);
            if (!is_array($state)) continue;

            $jsonCandidates = [
                trim((string)($state['json'] ?? '')),
                trim((string)($state['weekly_json'] ?? '')),
                trim((string)($state['json_path'] ?? '')),
                trim((string)($state['path'] ?? '')),
            ];
            foreach ($jsonCandidates as $jsonPath) {
                if ($jsonPath !== '' && is_file($jsonPath)) return $jsonPath;
            }
        }

        $fallbackCandidates = [
            $base . DIRECTORY_SEPARATOR . 'export' . DIRECTORY_SEPARATOR . "weekly_consumption_{$userId}.json",
            $base . DIRECTORY_SEPARATOR . 'state' . DIRECTORY_SEPARATOR . "weekly_consumption_{$userId}.json",
            $base . DIRECTORY_SEPARATOR . "weekly_consumption_{$userId}.json",
        ];
        foreach ($fallbackCandidates as $fallback) {
            if ($fallback !== '' && is_file($fallback)) return $fallback;
        }

        return '';
    }
}


if (!function_exists('supply_min_reserve_by_id')) {
    function supply_min_reserve_by_id($ingredientId) {
        $ingredientId = (int)$ingredientId;
        $syrupIds = [11019, 11018, 11017, 11001];
        return in_array($ingredientId, $syrupIds, true) ? 30 : 40;
    }
}

if (!function_exists('supply_days_left_by_weekly')) {
    function supply_days_left_by_weekly($currentValue, $weeklyNeed, $ingredientId) {
        $currentValue = (float)$currentValue;
        $weeklyNeed = (float)$weeklyNeed;
        $ingredientId = (int)$ingredientId;

        if ($weeklyNeed <= 0) return null;

        $dailyNeed = $weeklyNeed / 7.0;
        if ($dailyNeed <= 0) return null;

        $reserve = (float)supply_min_reserve_by_id($ingredientId);
        $usable = $currentValue - $reserve;
        if ($usable < 0) $usable = 0.0;

        return $usable / $dailyNeed;
    }
}
if (!function_exists('load_supply_weekly_map')) {
    function load_supply_weekly_map($userId) {
        $jsonFile = supply_weekly_json_file($userId);
        if ($jsonFile === '' || !is_file($jsonFile)) {
            return [[], $jsonFile];
        }

        $rows = json_decode((string)@file_get_contents($jsonFile), true);
        if (!is_array($rows)) {
            return [[], $jsonFile];
        }

        $map = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;

            $deviceId = (int)($row['Device ID'] ?? 0);
            $ingredientId = (int)($row['Supply ID'] ?? 0);
            if ($deviceId <= 0 || $ingredientId <= 0) continue;

            $weekly = (float)($row['Weekly_Consumption'] ?? 0);
            if ($weekly <= 0) continue;

            if (!isset($map[$deviceId]) || !is_array($map[$deviceId])) {
                $map[$deviceId] = [];
            }
            $map[$deviceId][$ingredientId] = $weekly;
        }

        return [$map, $jsonFile];
    }
}

// ========== 2) SUPPLY ==========
        $notifySupply = function_exists('isTelegramNotifyEnabled')
            ? isTelegramNotifyEnabled($acc, 'supply')
            : (bool)($acc['telegram_notify']['supply'] ?? true);

        // Supply anti-spam / cooldown (per machine + ingredient key)
        $lowCooldown  = 24 * 3600;
        $needCooldown = 6 * 3600;
        if (isset($CFG) && is_array($CFG) && isset($CFG['supply']) && is_array($CFG['supply'])) {
            if (isset($CFG['supply']['low_cooldown_sec'])) {
                $lowCooldown = (int)$CFG['supply']['low_cooldown_sec'];
            } elseif (isset($CFG['supply']['cooldown_sec'])) {
                $lowCooldown = (int)$CFG['supply']['cooldown_sec'];
            }

            if (isset($CFG['supply']['need_cooldown_sec'])) {
                $needCooldown = (int)$CFG['supply']['need_cooldown_sec'];
            }
        }
        if ($lowCooldown < 0) $lowCooldown = 0;
        if ($needCooldown < 0) $needCooldown = 0;

        list($weeklyConsumptionMap, $weeklyConsumptionFile) = load_supply_weekly_map($userId);
        if (count($weeklyConsumptionMap) > 0) {
            xhe_log('supply', "WEEKLY map loaded file={$weeklyConsumptionFile} devices=" . count($weeklyConsumptionMap), "DEBUG");
        } else {
            xhe_log('supply', "WEEKLY map empty file={$weeklyConsumptionFile} user_id={$userId}", "DEBUG");
        }

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

                if (!isset($state['supplies_alert'][$accountKey][$deviceId]) || !is_array($state['supplies_alert'][$accountKey][$deviceId])) {
                    $state['supplies_alert'][$accountKey][$deviceId] = [];
                }

                $needItems = [];
                $lowItems = [];
                $markNeedKeys = [];
                $markLowKeys = [];

                foreach ($supplies as $supplyId => $s) {
                    $ingredientId = (int)$supplyId;
                    $rule = supply_rule_by_id($ingredientId);
                    if (!is_array($rule)) continue;

                    $value = (int)($s['value'] ?? 0);
                    $label = trim((string)($s['name'] ?? ''));
                    if ($label === '') $label = (string)($rule['label'] ?? ('ID ' . $ingredientId));

                    $stateKey = trim((string)($rule['state_key'] ?? ('ingredient_' . $ingredientId)));
                    if ($stateKey === '') $stateKey = 'ingredient_' . $ingredientId;

                    $lowAlertKey  = $stateKey . '_low';
                    $needAlertKey = $stateKey . '_need';

                    $weeklyNeed = (float)($weeklyConsumptionMap[$deviceId][$ingredientId] ?? 0);
                    if ($weeklyNeed <= 0) continue;

                    $weeklyNeedText = rtrim(rtrim(number_format($weeklyNeed, 1, '.', ''), '0'), '.');
                    $reserveValue = (int)supply_min_reserve_by_id($ingredientId);
                    $daysLeft = supply_days_left_by_weekly($value, $weeklyNeed, $ingredientId);
                    $daysLeftText = ($daysLeft === null)
                        ? '-'
                        : rtrim(rtrim(number_format($daysLeft, 1, '.', ''), '0'), '.');

                    if ((float)$value > $weeklyNeed) {
                        xhe_log('supply', "NEED_CHECK_OK account={$accName} vmc={$deviceId} ingredient={$ingredientId} key={$stateKey} value={$value} weekly={$weeklyNeedText} reserve={$reserveValue} days_left={$daysLeftText}", "INFO");
                        continue;
                    }

                    $line = "{$label} (ID {$ingredientId}): {$value} | week={$weeklyNeedText} | days={$daysLeftText}";

                    if ($daysLeft !== null && $daysLeft < 2) {
                        $lastNeedTs = (int)($state['supplies_alert'][$accountKey][$deviceId][$needAlertKey] ?? 0);
                        if ($lastNeedTs > 0 && (time() - $lastNeedTs) < $needCooldown) {
                            xhe_log('supply', "SKIP NEED cooldown account={$accName} vmc={$deviceId} ingredient={$ingredientId} key={$needAlertKey}", "DEBUG");
                            continue;
                        }

                        $needItems[] = $line;
                        $markNeedKeys[] = $needAlertKey;
                    } else {
                        $lastLowTs = (int)($state['supplies_alert'][$accountKey][$deviceId][$lowAlertKey] ?? 0);
                        if ($lastLowTs > 0 && (time() - $lastLowTs) < $lowCooldown) {
                            xhe_log('supply', "SKIP LOW cooldown account={$accName} vmc={$deviceId} ingredient={$ingredientId} key={$lowAlertKey}", "DEBUG");
                            continue;
                        }

                        $lowItems[] = $line;
                        $markLowKeys[] = $lowAlertKey;
                    }
                }

                $recLines = [];
                if (count($needItems) > 0 || count($lowItems) > 0) {
                    $rules = supply_rec_rules();
                    foreach ($supplies as $sid2 => $s2) {
                        $ingredientId2 = (int)$sid2;
                        $n2 = trim((string)($s2['name'] ?? ''));
                        $v2 = (int)($s2['value'] ?? 0);

                        $k2 = supply_rec_key_by_id($ingredientId2);
                        if ($k2 === null && $n2 !== '' && function_exists('supply_rec_key_from_name')) {
                            $k2 = supply_rec_key_from_name($n2);
                        }
                        if ($k2 === null) continue;
                        if (!isset($rules[$k2])) continue;

                        $cap = (int)($rules[$k2]['cap'] ?? 0);
                        $pack = (int)($rules[$k2]['pack'] ?? 0);
                        $label2 = (string)($rules[$k2]['label'] ?? $k2);
                        if ($cap <= 0 || $pack <= 0) continue;

                        $free = $cap - $v2;
                        if ($free < $pack) continue;

                        $packs = intdiv($free, $pack);
                        if ($packs <= 0) continue;

                        $label2 = preg_replace('/\\s*\\(контейнер[^)]*\\)/u', '', (string)$label2);
                        $recLines[] = "• {$label2} — {$packs} пач.";
                    }
                }

                if (count($needItems) > 0) {
                    $msg = "🚨 NEED SUPPLY | {$deviceId}";
                    if ($addressLine !== '') $msg .= " - {$addressLine}";
                    $msg .= "\n" . implode("\n", $needItems);
                    if (count($recLines) > 0) {
                        $msg .= "\n\nРекомендация дозаправки:\n" . implode("\n", $recLines);
                    }

                    $tgKey = $accountKey . '|' . $deviceId . '|need_supply';
                    if (function_exists('tg_notify')) {
                        tg_notify('supply', $msg, $chatId, $tgKey, $notifyState);
                        xhe_log('supply', "SEND TG type=need_supply account={$accName} dev={$deviceId} items=" . count($needItems) . " chat_id={$chatId}", "INFO");
                    } elseif (function_exists('sendmessage')) {
                        sendmessage($msg, $chatId);
                        xhe_log('supply', "SEND TG(fallback) type=need_supply account={$accName} dev={$deviceId} items=" . count($needItems), "INFO");
                    }

                    $now = time();
                    foreach ($markNeedKeys as $k) $state['supplies_alert'][$accountKey][$deviceId][$k] = $now;
                }

                if (count($lowItems) > 0) {
                    $msg = "⚠️ LOW SUPPLY | {$deviceId}";
                    if ($addressLine !== '') $msg .= " - {$addressLine}";
                    $msg .= "\n" . implode("\n", $lowItems);
                    if (count($recLines) > 0) {
                        $msg .= "\n\nРекомендация дозаправки:\n" . implode("\n", $recLines);
                    }

                    $tgKey = $accountKey . '|' . $deviceId . '|low_supply';
                    if (function_exists('tg_notify')) {
                        tg_notify('supply', $msg, $chatId, $tgKey, $notifyState);
                        xhe_log('supply', "SEND TG type=low_supply account={$accName} dev={$deviceId} items=" . count($lowItems) . " chat_id={$chatId}", "INFO");
                    } elseif (function_exists('sendmessage')) {
                        sendmessage($msg, $chatId);
                        xhe_log('supply', "SEND TG(fallback) type=low_supply account={$accName} dev={$deviceId} items=" . count($lowItems), "INFO");
                    }

                    $now = time();
                    foreach ($markLowKeys as $k) $state['supplies_alert'][$accountKey][$deviceId][$k] = $now;
                }
            }
        } else {
            if (!$notifySupply) xhe_log('supply', "SKIP notify disabled account={$accName}", "DEBUG");
        }

        //
