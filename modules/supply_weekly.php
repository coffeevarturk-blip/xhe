<?php

if (!function_exists('supply_weekly_log')) {
    function supply_weekly_log(string $level, string $msg): void
    {
        if (function_exists('xhe_log')) {
            xhe_log('supply_weekly', $msg, $level);
            return;
        }

        $line = date('Y-m-d H:i:s') . " | [supply_weekly][{$level}] {$msg}";
        @file_put_contents('C:\\jetinno_runtime\\supply_weekly_fallback.log', $line . PHP_EOL, FILE_APPEND);
    }
}

if (!function_exists('supply_weekly_run')) {
    function supply_weekly_run(string $accName, int $userId, array $acc = []): void
    {
        try {
            supply_weekly_log('INFO', "START account={$accName} user_id={$userId}");

            if (!supply_weekly_daily_gate($userId, $accName)) {
                supply_weekly_log('DEBUG', "SKIP daily_gate user_id={$userId} acc={$accName}");
                return;
            }

            $url = "https://saas-hk.jetinno.com/order_recipe?user_id={$userId}&datemonth=&daterange=&vmc_no=&recipe_id=&page=&perPage=25&order_by%5Bkey%5D=&order_by%5Bvalue%5D=&export=1";

            $csvText = supply_weekly_download_csv($url);
            if ($csvText === '') {
                supply_weekly_log('ERROR', "EMPTY CSV user_id={$userId}");
                return;
            }

            $runtimeDir = supply_weekly_runtime_dir();
            @mkdir($runtimeDir, 0777, true);

            $ts = date('Ymd_His');
            $rawCsvPath = $runtimeDir . DIRECTORY_SEPARATOR . "order_recipe_{$userId}_{$ts}.csv";
            @file_put_contents($rawCsvPath, $csvText);

            $rows = supply_weekly_parse_csv($csvText);
            if (!$rows) {
                supply_weekly_log('ERROR', "PARSE FAILED rows=0 user_id={$userId}");
                return;
            }

            $agg = supply_weekly_aggregate_last_7_days($rows);
            if (!$agg) {
                supply_weekly_log('WARNING', "AGG EMPTY user_id={$userId} parsed_rows=" . count($rows));
            }

            $csvOut  = $runtimeDir . DIRECTORY_SEPARATOR . "weekly_consumption_{$userId}.csv";
            $jsonOut = $runtimeDir . DIRECTORY_SEPARATOR . "weekly_consumption_{$userId}.json";

            supply_weekly_save_csv($csvOut, $agg);
            @file_put_contents($jsonOut, json_encode(array_values($agg), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            $state = [
                'account'      => $accName,
                'user_id'      => $userId,
                'generated_at' => date('Y-m-d H:i:s'),
                'rows'         => count($agg),
                'raw_csv'      => $rawCsvPath,
                'csv'          => $csvOut,
                'json'         => $jsonOut,
            ];

            @file_put_contents(
                $runtimeDir . DIRECTORY_SEPARATOR . "weekly_consumption_{$userId}_state.json",
                json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );

            supply_weekly_log(
                'INFO',
                "DONE acc={$accName} user_id={$userId} raw_rows=" . count($rows) .
                " agg_rows=" . count($agg) .
                " csv={$csvOut} json={$jsonOut}"
            );
        } catch (Throwable $e) {
            supply_weekly_log('ERROR', "EXCEPTION: " . $e->getMessage());
        }
    }
}

if (!function_exists('supply_weekly_daily_gate')) {
    function supply_weekly_daily_gate(int $userId, string $accName): bool
    {
        $hm = date('H:i');
        // TEST MODE: window disabled for now
        if ($hm < '07:00' || $hm > '07:59') {
             return false;
         }

        $stateDir = supply_weekly_state_dir();
        @mkdir($stateDir, 0777, true);

        $key = preg_replace('~[^a-zA-Z0-9_\-]~', '_', strtolower($accName));
        $path = $stateDir . DIRECTORY_SEPARATOR . "supply_weekly_gate_{$userId}_{$key}.json";

        $today = date('Y-m-d');
        $prev = [];

        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $prev = json_decode((string)$raw, true);
            if (!is_array($prev)) {
                $prev = [];
            }
        }

        if (($prev['date'] ?? '') === $today) {
            return false;
        }

        $data = [
            'date' => $today,
            'ts'   => date('Y-m-d H:i:s'),
        ];

        @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return true;
    }
}

if (!function_exists('supply_weekly_runtime_dir')) {
    function supply_weekly_runtime_dir(): string
    {
        if (defined('JETINNO_RUNTIME_DIR') && JETINNO_RUNTIME_DIR) {
            return rtrim(JETINNO_RUNTIME_DIR, '\\/') . DIRECTORY_SEPARATOR . 'export';
        }
        return 'C:\\jetinno_runtime\\export';
    }
}

if (!function_exists('supply_weekly_state_dir')) {
    function supply_weekly_state_dir(): string
    {
        if (defined('JETINNO_RUNTIME_DIR') && JETINNO_RUNTIME_DIR) {
            return rtrim(JETINNO_RUNTIME_DIR, '\\/') . DIRECTORY_SEPARATOR . 'state';
        }
        return 'C:\\jetinno_runtime\\state';
    }
}

if (!function_exists('supply_weekly_download_csv')) {
    function supply_weekly_download_csv(string $url): string
    {
        global $cookieStr;

        if (function_exists('curl_get_with_cookies')) {
            $res = curl_get_with_cookies(
                $url,
                (string)$cookieStr,
                ["Accept: text/csv,*/*"],
                "https://saas-hk.jetinno.com/order_recipe"
            );

            if (is_array($res) && !empty($res['ok']) && !empty($res['body'])) {
                supply_weekly_log(
                    'INFO',
                    "DOWNLOAD ok=" . (!empty($res['ok']) ? 'true' : 'false') .
                    " http=" . (int)($res['http'] ?? 0) .
                    " bytes=" . (int)($res['bytes'] ?? 0) .
                    " ct=" . (string)($res['ct'] ?? '')
                );
                return (string)$res['body'];
            }

            supply_weekly_log(
                'ERROR',
                "DOWNLOAD FAILED http=" . (int)($res['http'] ?? 0) .
                " bytes=" . (int)($res['bytes'] ?? 0) .
                " ct=" . (string)($res['ct'] ?? '')
            );
            return '';
        }

        supply_weekly_log('ERROR', 'curl_get_with_cookies not found');
        return '';
    }
}

if (!function_exists('supply_weekly_parse_csv')) {
    function supply_weekly_parse_csv(string $csvText): array
    {
        $csvText = supply_weekly_normalize_encoding($csvText);
        $csvText = preg_replace("/^\xEF\xBB\xBF/", '', $csvText);

        $lines = preg_split("/\r\n|\n|\r/", $csvText);
        if (!$lines || count($lines) < 2) {
            return [];
        }

        $delimiter = supply_weekly_detect_delimiter($lines[0]);
        $header = str_getcsv(array_shift($lines), $delimiter);
        $header = array_map('trim', $header);

        $rows = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $cols = str_getcsv($line, $delimiter);

            if (count($cols) === 1 && trim((string)$cols[0]) === '') {
                continue;
            }

            if (count($cols) < count($header)) {
                $cols = array_pad($cols, count($header), '');
            } elseif (count($cols) > count($header)) {
                $cols = array_slice($cols, 0, count($header));
            }

            $row = [];
            foreach ($header as $i => $name) {
                $row[$name] = isset($cols[$i]) ? trim((string)$cols[$i]) : '';
            }

            $rows[] = $row;
        }

        return $rows;
    }
}

if (!function_exists('supply_weekly_normalize_encoding')) {
    function supply_weekly_normalize_encoding(string $s): string
    {
        if ($s === '') {
            return $s;
        }

        if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
            $enc = mb_detect_encoding($s, ['UTF-8', 'Windows-1254', 'Windows-1251', 'ISO-8859-9', 'ISO-8859-1'], true);
            if ($enc && strtoupper($enc) !== 'UTF-8') {
                $converted = @mb_convert_encoding($s, 'UTF-8', $enc);
                if (is_string($converted) && $converted !== '') {
                    return $converted;
                }
            }
        }

        return $s;
    }
}

if (!function_exists('supply_weekly_detect_delimiter')) {
    function supply_weekly_detect_delimiter(string $headerLine): string
    {
        $candidates = [',', ';', "\t", '|'];
        $best = ',';
        $bestCount = -1;

        foreach ($candidates as $d) {
            $count = substr_count($headerLine, $d);
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $d;
            }
        }

        return $best;
    }
}

if (!function_exists('supply_weekly_find_col')) {
    function supply_weekly_find_col(array $row, array $variants): ?string
    {
        $map = [];

        foreach ($row as $k => $v) {
            $map[mb_strtolower(trim((string)$k), 'UTF-8')] = $k;
        }

        foreach ($variants as $name) {
            $key = mb_strtolower(trim((string)$name), 'UTF-8');
            if (isset($map[$key])) {
                return $map[$key];
            }
        }

        return null;
    }
}

if (!function_exists('supply_weekly_parse_number')) {
    function supply_weekly_parse_number($value): float
    {
        $s = trim((string)$value);
        if ($s === '') {
            return 0.0;
        }

        $s = str_replace(',', '.', $s);
        $s = preg_replace('~[^0-9\.\-]+~', '', $s);

        if ($s === '' || $s === '-' || $s === '.') {
            return 0.0;
        }

        return (float)$s;
    }
}

if (!function_exists('supply_weekly_parse_dt')) {
    function supply_weekly_parse_dt(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $ts = strtotime($value);
        return ($ts === false) ? null : $ts;
    }
}

if (!function_exists('supply_weekly_aggregate_last_7_days')) {
    function supply_weekly_aggregate_last_7_days(array $rows): array
    {
        if (!$rows) {
            return [];
        }

        $first = $rows[0];

        $colDeviceId = supply_weekly_find_col($first, [
            'Device ID', 'Cihaz numarası', 'VMC', 'vmc_no'
        ]);
        $colAddress  = supply_weekly_find_col($first, [
            'Address', 'Cihaz adresi', 'Device Address'
        ]);
        $colSupplyId = supply_weekly_find_col($first, [
            'Supply ID', 'Ingredient ID', 'Malzeme ID', 'material_id', 'Öğe Kimliği'
        ]);
        $colSupplyNm = supply_weekly_find_col($first, [
            'Supply Name', 'Ingredient Name', 'Malzeme Adı', 'Malzeme adı', 'material_name', 'Öğe adı'
        ]);
        $colCharge   = supply_weekly_find_col($first, [
            'Supplementary Charge', 'SupplementaryCharge', 'Dosage', 'use_count', 'amount', 'Besleme miktarı'
        ]);
        $colTime     = supply_weekly_find_col($first, [
            'Update Time', 'Create Time', 'Time', 'Tarih', 'Date', 'create_time', 'Güncelleme zamanı'
        ]);

        if (!$colDeviceId || !$colSupplyId || !$colSupplyNm || !$colCharge || !$colTime) {
            supply_weekly_log(
                'ERROR',
                "REQUIRED COLUMNS NOT FOUND deviceId=" . ($colDeviceId ?: '-') .
                " address=" . ($colAddress ?: '-') .
                " supplyId=" . ($colSupplyId ?: '-') .
                " supplyName=" . ($colSupplyNm ?: '-') .
                " charge=" . ($colCharge ?: '-') .
                " time=" . ($colTime ?: '-')
            );

            if (!empty($first)) {
                supply_weekly_log('DEBUG', 'HEADERS: ' . json_encode(array_keys($first), JSON_UNESCAPED_UNICODE));
            }
            return [];
        }

        $maxTs = null;

        foreach ($rows as $r) {
            $ts = supply_weekly_parse_dt((string)($r[$colTime] ?? ''));
            if ($ts !== null && ($maxTs === null || $ts > $maxTs)) {
                $maxTs = $ts;
            }
        }

        if ($maxTs === null) {
            $maxTs = time();
        }

        $fromTs = $maxTs - (6 * 86400);
        $agg = [];

        foreach ($rows as $r) {
            $ts = supply_weekly_parse_dt((string)($r[$colTime] ?? ''));
            if ($ts === null) {
                continue;
            }
            if ($ts < $fromTs || $ts > $maxTs) {
                continue;
            }

            $deviceId = trim((string)($r[$colDeviceId] ?? ''));
            $address  = $colAddress ? trim((string)($r[$colAddress] ?? '')) : '';
            $supplyId = trim((string)($r[$colSupplyId] ?? ''));
            $supplyNm = trim((string)($r[$colSupplyNm] ?? ''));
            $charge   = supply_weekly_parse_number($r[$colCharge] ?? '');

            if ($deviceId === '' || $supplyId === '' || $supplyNm === '') {
                continue;
            }

            $key = $deviceId . '||' . $supplyId;

            if (!isset($agg[$key])) {
                $agg[$key] = [
                    'Device ID'          => $deviceId,
                    'Address'            => $address,
                    'Supply ID'          => $supplyId,
                    'Supply Name'        => $supplyNm,
                    'Count'              => 0,
                    'Weekly_Consumption' => 0.0,
                    '_from_ts'           => $fromTs,
                    '_to_ts'             => $maxTs,
                ];
            }

            $agg[$key]['Count'] += 1;
            $agg[$key]['Weekly_Consumption'] += $charge;
        }

        foreach ($agg as &$item) {
            $item['Weekly_Consumption'] = round((float)$item['Weekly_Consumption'], 2);
            $item['Period From'] = date('Y-m-d H:i:s', (int)$item['_from_ts']);
            $item['Period To']   = date('Y-m-d H:i:s', (int)$item['_to_ts']);
            unset($item['_from_ts'], $item['_to_ts']);
        }
        unset($item);

        $agg = array_values($agg);

        usort($agg, function ($a, $b) {
            $x = strcmp((string)$a['Device ID'], (string)$b['Device ID']);
            if ($x !== 0) {
                return $x;
            }
            return strcmp((string)$a['Supply ID'], (string)$b['Supply ID']);
        });

        return $agg;
    }
}

if (!function_exists('supply_weekly_save_csv')) {
    function supply_weekly_save_csv(string $path, array $rows): void
    {
        $fp = fopen($path, 'wb');
        if (!$fp) {
            supply_weekly_log('ERROR', "CSV OPEN FAILED path={$path}");
            return;
        }

        if (!$rows) {
            fclose($fp);
            return;
        }

        $header = array_keys($rows[0]);
        fputcsv($fp, $header);

        foreach ($rows as $row) {
            $out = [];
            foreach ($header as $h) {
                $out[] = $row[$h] ?? '';
            }
            fputcsv($fp, $out);
        }

        fclose($fp);
    }
}
