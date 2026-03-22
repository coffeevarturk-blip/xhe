<?php

// AUTO-GENERATED MODULE from all_in_one_2_0_refactored.php
// DO NOT EDIT LOGIC HERE unless you know what you're doing.


// ========== NOTIFY FILTERS (deny/allow by code) ==========
if (!function_exists('normalize_notify_code')) {
    function normalize_notify_code(string $code): string {
        $code = trim($code);
        if ($code === '') return '';
        // If code looks like "WARNING:Z0050" or "ERROR:7300", take suffix after last ":"
        if (strpos($code, ':') !== false) {
            $parts = explode(':', $code);
            $code = trim(end($parts));
        }
        return strtoupper($code);
    }
}

if (!function_exists('detect_notify_kind')) {
    function detect_notify_kind(string $rawCode, string $type): string {
        $c = strtoupper(trim($rawCode));
        if (strpos($c, 'WARNING:') === 0) return 'warnings';
        if (strpos($c, 'ERROR:') === 0) return 'errors';
        // fallback: try from type text
        $t = strtoupper(trim($type));
        if (strpos($t, 'UYARI') !== false || strpos($t, 'WARNING') !== false) return 'warnings';
        if (strpos($t, 'ERROR') !== false || strpos($t, 'HATA') !== false) return 'errors';
        return 'errors';
    }
}

if (!function_exists('should_skip_notify_code')) {
    function should_skip_notify_code(string $kind, string $rawCode, array $CFG): bool {
        $filters = $CFG['notify']['filters'] ?? null;
        if (!is_array($filters)) return false;

        $rule = $filters[$kind] ?? null;
        if (!is_array($rule)) return false;

        $mode = strtolower((string)($rule['mode'] ?? 'deny'));
        $codes = $rule['codes'] ?? [];
        if (!is_array($codes)) $codes = [];

        // normalize codes list to uppercase strings
        $set = [];
        foreach ($codes as $v) {
            $set[strtoupper(trim((string)$v))] = true;
        }

        $code = normalize_notify_code($rawCode);
        if ($code === '') return false;

        $in = isset($set[$code]);

        if ($mode === 'allow') return !$in; // skip if not allowed
        // default deny
        return $in; // skip if denied
    }
}



if (!function_exists('error_translations_csv_file')) {
    function error_translations_csv_file(): string {
        $base = function_exists('runtime_base_dir') ? rtrim((string)runtime_base_dir(), "\\/") : '';
        if ($base === '') return '';
        return $base . DIRECTORY_SEPARATOR . 'state' . DIRECTORY_SEPARATOR . 'error_translations.csv';
    }
}

if (!function_exists('error_translation_normalize_lang')) {
    function error_translation_normalize_lang($lang): string {
        $lang = strtolower(trim((string)$lang));
        if ($lang === '') return 'ru';
        if (strpos($lang, 'tr') === 0) return 'tr';
        if (strpos($lang, 'en') === 0) return 'en';
        if (strpos($lang, 'ru') === 0) return 'ru';
        return 'ru';
    }
}

if (!function_exists('error_translation_account_lang')) {
    function error_translation_account_lang(array $acc, array $CFG = []): string {
        $candidates = [
            $acc['lang'] ?? null,
            $acc['language'] ?? null,
            $acc['locale'] ?? null,
            $CFG['lang'] ?? null,
            $CFG['language'] ?? null,
            $CFG['locale'] ?? null,
        ];

        $accName = trim((string)($acc['name'] ?? $acc['account'] ?? $acc['acc_name'] ?? ''));
        if ($accName !== '' && isset($CFG['accounts']) && is_array($CFG['accounts']) && isset($CFG['accounts'][$accName]) && is_array($CFG['accounts'][$accName])) {
            $cfgAcc = $CFG['accounts'][$accName];
            $candidates[] = $cfgAcc['lang'] ?? null;
            $candidates[] = $cfgAcc['language'] ?? null;
            $candidates[] = $cfgAcc['locale'] ?? null;
        }

        foreach ($candidates as $candidate) {
            $lang = error_translation_normalize_lang($candidate);
            if ($lang !== '') return $lang;
        }
        return 'ru';
    }
}

if (!function_exists('error_translations_cache_reset')) {
    function error_translations_cache_reset(): void {
        $GLOBALS['error_translations_map_cache'] = null;
    }
}


if (!function_exists('load_error_translations_map')) {
    function load_error_translations_map(): array {
        if (isset($GLOBALS['error_translations_map_cache']) && is_array($GLOBALS['error_translations_map_cache'])) {
            return $GLOBALS['error_translations_map_cache'];
        }

        $cache = [];
        $csvFile = error_translations_csv_file();
        if ($csvFile === '' || !is_file($csvFile)) {
            $GLOBALS['error_translations_map_cache'] = $cache;
            return $cache;
        }

        $fh = @fopen($csvFile, 'r');
        if (!$fh) {
            $GLOBALS['error_translations_map_cache'] = $cache;
            return $cache;
        }

        if (function_exists('flock')) {
            @flock($fh, LOCK_SH);
        }

        $header = fgetcsv($fh, 0, ';');
        if (!is_array($header)) {
            if (function_exists('flock')) {
                @flock($fh, LOCK_UN);
            }
            fclose($fh);
            $GLOBALS['error_translations_map_cache'] = $cache;
            return $cache;
        }

        $idx = [];
        foreach ($header as $i => $name) {
            $idx[strtolower(trim((string)$name))] = $i;
        }

        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            if (!is_array($row) || count($row) === 0) continue;

            $rawCode = trim((string)($row[$idx['code']] ?? ''));
            if ($rawCode === '') continue;

            $normCode = normalize_notify_code($rawCode);
            if ($normCode === '') continue;

            $cache[$normCode] = [
                'ru'   => trim((string)($row[$idx['desc_ru']] ?? '')),
                'en'   => trim((string)($row[$idx['desc_en']] ?? '')),
                'tr'   => trim((string)($row[$idx['desc_tr']] ?? '')),
                            ];
        }

        if (function_exists('flock')) {
            @flock($fh, LOCK_UN);
        }
        fclose($fh);
        $GLOBALS['error_translations_map_cache'] = $cache;
        return $cache;
    }
}


if (!function_exists('error_translation_should_skip')) {
    function error_translation_should_skip(array $entry): bool {
        return false;
    }
}

if (!function_exists('error_translations_append_missing')) {
    function error_translations_append_missing(string $code, string $desc = ''): bool {
        $csvFile = error_translations_csv_file();
        if ($csvFile === '') return false;

        $normCode = normalize_notify_code($code);
        if ($normCode === '') return false;

        $dir = dirname($csvFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $exists = is_file($csvFile);
        $fh = @fopen($csvFile, $exists ? 'c+' : 'w+');
        if (!$fh) return false;

        if (function_exists('flock')) {
            @flock($fh, LOCK_EX);
        }

        rewind($fh);
        $header = fgetcsv($fh, 0, ';');
        $needHeader = !is_array($header);
        $codeIdx = 0;
        if (is_array($header)) {
            $idx = [];
            foreach ($header as $i => $name) {
                $idx[strtolower(trim((string)$name))] = $i;
            }
            $codeIdx = (int)($idx['code'] ?? 0);
        }

        if (!$needHeader) {
            while (($row = fgetcsv($fh, 0, ';')) !== false) {
                if (!is_array($row) || count($row) === 0) continue;
                $existing = normalize_notify_code(trim((string)($row[$codeIdx] ?? '')));
                if ($existing !== '' && $existing === $normCode) {
                    if (function_exists('flock')) {
                        @flock($fh, LOCK_UN);
                    }
                    fclose($fh);
                    error_translations_cache_reset();
                    if (function_exists('xhe_log')) {
                        xhe_log('errors', "SKIP duplicate translation code={$normCode} file={$csvFile}", "DEBUG");
                    }
                    return false;
                }
            }
        }

        fseek($fh, 0, SEEK_END);
        if ($needHeader || filesize($csvFile) === 0) {
            @fwrite($fh, "code;desc_ru;desc_en;desc_tr
");
        }

        $line = [
            $normCode,
            '',
            trim((string)$desc),
            ''
        ];

        $ok = fputcsv($fh, $line, ';') !== false;
        fflush($fh);
        if (function_exists('flock')) {
            @flock($fh, LOCK_UN);
        }
        fclose($fh);
        error_translations_cache_reset();
        if ($ok && function_exists('xhe_log')) {
            xhe_log('errors', "APPEND missing translation code={$normCode} file={$csvFile}", "INFO");
        }
        return $ok;
    }
}

if (!function_exists('translate_error_desc')) {
    function translate_error_desc(string $code, string $fallbackDesc, array $acc = [], array $CFG = []): string {
        $map = load_error_translations_map();
        $normCode = normalize_notify_code($code);
        if ($normCode === '' || !isset($map[$normCode]) || !is_array($map[$normCode])) {
            return $fallbackDesc;
        }

        $entry = $map[$normCode];
        if (is_array($entry) && error_translation_should_skip($entry)) {
            return '__SKIP__';
        }

        $lang = error_translation_account_lang($acc, $CFG);
        $translated = trim((string)($entry[$lang] ?? ''));
        return ($translated !== '') ? $translated : $fallbackDesc;
    }
}

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
// ===== TEST INJECT 7300 =====
//$allErr[] = [
  //  err_col_vmc()   => '81952',
    //err_col_addr()  => 'TEST MACHINE',
    //err_col_code()  => 'ERROR:7300',
    //err_col_desc()  => 'TEST BOILER ERROR',
    //err_col_type()  => 'Error',
    //err_col_status()=> 'Unresolved',
    //err_col_time()  => date('Y-m-d H:i:s'),
//];

xhe_log('errors', 'TEST inject ERROR:7300 vmc=81952', 'INFO');
// ===== END TEST =====
            if (count($assoc) < $perPageErr) break;
        }

        $csvErrPath  = $outDir . "\\errors_{$userId}_{$stamp}.csv";
        $jsonErrPath = $outDir . "\\errors_{$userId}_{$stamp}.json";
        @file_put_contents($csvErrPath, $csvErrOut);

        $byVmc = [];

        $notifyErrors = function_exists('isTelegramNotifyEnabled')
            ? isTelegramNotifyEnabled($acc, 'errors')
            : (bool)($acc['telegram_notify']['errors'] ?? true);

        $errLang = error_translation_account_lang(is_array($acc ?? null) ? $acc : [], is_array($CFG ?? null) ? $CFG : []);
        $errTrFile = error_translations_csv_file();
        xhe_log('errors', "TRANSLATIONS lang={$errLang} file={$errTrFile}", "DEBUG");

        foreach ($allErr as $row) {
            $vmc   = safe_s((string)($row[err_col_vmc()] ?? ''));
            $addr  = safe_s((string)($row[err_col_addr()] ?? ''));
            $code  = safe_s((string)($row[err_col_code()] ?? ''));
            $desc  = safe_s((string)($row[err_col_desc()] ?? ''));
            $type  = safe_s((string)($row[err_col_type()] ?? ''));
            $st    = safe_s((string)($row[err_col_status()] ?? ''));
            $dt    = safe_s((string)($row[err_col_time()] ?? ''));

            // Apply deny/allow filters by code (from $CFG['notify']['filters'])
            $kind = detect_notify_kind($code, $type); // 'errors' or 'warnings'
            if (should_skip_notify_code($kind, $code, $CFG)) {
                xhe_log('errors', "SKIP by filter account={$accName} vmc={$vmc} code={$code}", "DEBUG");
                continue;
            }
            $seri  = safe_s((string)($row[err_col_serial()] ?? ''));
            $normCode = normalize_notify_code($code);
            $translationsMap = load_error_translations_map();

            if ($normCode !== '' && !isset($translationsMap[$normCode])) {
                error_translations_append_missing($code, $desc);
                $translationsMap = load_error_translations_map();
            }

            $descTranslated = translate_error_desc($code, $desc, is_array($acc ?? null) ? $acc : [], is_array($CFG ?? null) ? $CFG : []);
            if ($descTranslated === '__SKIP__') {
                xhe_log('errors', "SKIP by CSV rule account={$accName} vmc={$vmc} code={$code}", "INFO");
                continue;
            }

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
                "Desc: {$descTranslated}\n" .
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


        