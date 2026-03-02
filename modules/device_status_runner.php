<?php
// ======================================================
// modules/device_status_runner.php  (TR columns hardcoded)
// CSV source: /device?user_id=...&export=1
// Detect ONLY ONLINE -> OFFLINE transition and notify once.
// Columns expected (Turkish):
//  - "Cihaz numarası"  (VMC)
//  - "Cihaz adresi"    (Location)
//  - "Ağ durumu"       (Network status: "çevrimiçi"/"çevrimdışı")
// ======================================================

if (!defined('DEVICE_STATUS_RUNNER_TR')) {
    define('DEVICE_STATUS_RUNNER_TR', true);
}

if (!function_exists('ds_runtime_base')) {
    function ds_runtime_base(): string {
        return function_exists('runtime_base_dir') ? runtime_base_dir() : 'c:\\jetinno_runtime';
    }
}
if (!function_exists('ds_export_dir')) {
    function ds_export_dir(): string {
        $base = rtrim(ds_runtime_base(), "\\/");
        $dir = $base . "\\export";
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        return $dir;
    }
}
if (!function_exists('ds_state_file')) {
    function ds_state_file(string $accName): string {
        $base = rtrim(ds_runtime_base(), "\\/");
        $dir = $base . "\\state";
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $accName);
        return $dir . "\\device_status_" . $safe . ".json";
    }
}

if (!function_exists('ds_load_state')) {
    function ds_load_state(string $accName): array {
        $p = ds_state_file($accName);
        if (is_file($p)) {
            $j = @file_get_contents($p);
            $a = json_decode($j, true);
            if (is_array($a)) return $a;
        }
        return ['updated_at'=>null, 'machines'=>[]];
    }
}
if (!function_exists('ds_save_state')) {
    function ds_save_state(string $accName, array $state): void {
        $state['updated_at'] = date('Y-m-d H:i:s');
        @file_put_contents(ds_state_file($accName), json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}

if (!function_exists('ds_http_get_save')) {
    function ds_http_get_save(string $url, string $savePath, string $cookieStr): array {
        $ch = curl_init($url);
        if (!$ch) return ['ok'=>false,'http'=>0,'bytes'=>0,'err'=>'curl_init_failed'];

        $fh = @fopen($savePath, 'wb');
        if (!$fh) { curl_close($ch); return ['ok'=>false,'http'=>0,'bytes'=>0,'err'=>'file_open_failed']; }

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_COOKIE => $cookieStr,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0',
                'Accept: text/csv,*/*'
            ],
        ]);

        $ok = curl_exec($ch);
        $err = $ok ? '' : (curl_error($ch) ?: 'curl_exec_failed');
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);

        $bytes = is_file($savePath) ? filesize($savePath) : 0;

        // quick sanity check: must look like CSV (contain commas) in first 1KB
        $head = @file_get_contents($savePath, false, null, 0, 1024);
        $looksCsv = is_string($head) && (strpos($head, ',') !== false);

        $finalOk = ($ok && $http === 200 && $bytes > 50 && $looksCsv);
        return ['ok'=>$finalOk,'http'=>$http,'bytes'=>$bytes,'err'=>$err];
    }
}

if (!function_exists('ds_find_col_exact')) {
    function ds_find_col_exact(array $headers, string $needle): int {
        foreach ($headers as $i => $h) {
            if (trim((string)$h) === $needle) return (int)$i;
        }
        return -1;
    }
}

if (!function_exists('ds_parse_conn_tr')) {
    function ds_parse_conn_tr(string $raw): ?int {
        $v = mb_strtolower(trim($raw));
        if ($v === '') return null;
        if (mb_strpos($v, 'çevrimiçi') !== false) return 1;
        if (mb_strpos($v, 'çevrimdışı') !== false) return 0;
        // fallback: english
        if ($v === 'online' || $v === '1' || $v === 'true' || $v === 'yes') return 1;
        if ($v === 'offline' || $v === '0' || $v === 'false' || $v === 'no') return 0;
        return null;
    }
}

/**
 * Main entry: download device CSV and notify on ONLINE->OFFLINE.
 * Usage:
 *   device_status_run_tr('MAIN', 629, $CFG);
 */
if (!function_exists('device_status_run_tr')) {
    function device_status_run_tr(string $accName, int $userId, array $CFG): array {
        $cookieStr = (string)($GLOBALS['cookieStr'] ?? '');
        if ($cookieStr === '') {
            if (function_exists('xhe_log')) xhe_log('device_status', 'cookieStr is empty (run after LOGIN OK)', 'ERROR');
            return ['ok'=>false,'err'=>'cookie_empty'];
        }

        $url = "https://saas-hk.jetinno.com/device?user_id={$userId}"
             . "&is_connected=&is_error=&is_warning=&is_supply=&scene=&vmcs=&vmc_no=&location=&vmc_model="
             . "&app_version=&io_version=&stum_version=&cup_version=&ice_version=&milk_version=&syrup_version="
             . "&custom_id=&remark1=&page_mode=0&page=&perPage=25&order_by%5Bkey%5D=&order_by%5Bvalue%5D=&export=1";

        $ts = date('Ymd_His');
        $csvPath = ds_export_dir() . "\\device_{$userId}_{$ts}.csv";

        if (function_exists('xhe_log')) xhe_log('device_status', "DOWNLOAD device export user_id={$userId}", 'INFO');
        $dl = ds_http_get_save($url, $csvPath, $cookieStr);
        if (function_exists('xhe_log')) {
            xhe_log('device_status', "DOWNLOAD ok=" . ($dl['ok']?'true':'false') . " http={$dl['http']} bytes={$dl['bytes']} err={$dl['err']} file=" . basename($csvPath), $dl['ok']?'INFO':'ERROR');
        }
        if (!$dl['ok']) return ['ok'=>false,'err'=>'download_failed'] + $dl;

        $fh = @fopen($csvPath, 'r');
        if (!$fh) return ['ok'=>false,'err'=>'csv_open_failed','csv'=>$csvPath];

        $headers = fgetcsv($fh);
        if (!$headers) { fclose($fh); return ['ok'=>false,'err'=>'csv_empty','csv'=>$csvPath]; }

        $colVmc = ds_find_col_exact($headers, 'Cihaz numarası');
        $colLoc = ds_find_col_exact($headers, 'Cihaz adresi');
        $colNet = ds_find_col_exact($headers, 'Ağ durumu');

        if ($colVmc < 0 || $colNet < 0) {
            fclose($fh);
            if (function_exists('xhe_log')) xhe_log('device_status', "Missing columns. colVmc={$colVmc} colNet={$colNet}", 'ERROR');
            return ['ok'=>false,'err'=>'missing_columns','colVmc'=>$colVmc,'colNet'=>$colNet];
        }

        $state = ds_load_state($accName);
        $machines = $state['machines'] ?? [];
        if (!is_array($machines)) $machines = [];

        $rows = 0; $changed = 0; $sent = 0;

        while (($row = fgetcsv($fh)) !== false) {
            $rows++;
            $vmc = trim((string)($row[$colVmc] ?? ''));
            if ($vmc === '') continue;

            $rawNet = (string)($row[$colNet] ?? '');
            $conn = ds_parse_conn_tr($rawNet);
            if ($conn === null) continue;

            $loc = ($colLoc >= 0) ? trim((string)($row[$colLoc] ?? '')) : '';
            $prev = $machines[$vmc]['last_connected'] ?? null;

            if ($prev === null) {
                // first seen: record only
                $machines[$vmc] = [
                    'last_connected' => $conn,
                    'last_seen' => date('Y-m-d H:i:s'),
                    'location' => $loc,
                ];
                continue;
            }

            if ((int)$prev !== (int)$conn) {
                $changed++;

                // Notify ONLY ONLINE -> OFFLINE
                if ((int)$prev === 1 && (int)$conn === 0) {
                    $enabled = (bool)($CFG['telegram']['notify']['device_status_offline'] ?? true);
                    $chatId = (string)($CFG['telegram']['chat_id'] ?? ($CFG['telegram']['main_chat_id'] ?? ''));

                    if ($enabled && $chatId !== '' && function_exists('tg_notify')) {
                        $msg = "🔴 MACHINE OFFLINE | {$accName}\n"
                             . "VMC: {$vmc}" . ($loc !== '' ? " - {$loc}" : '') . "\n"
                             . "Time: " . date('Y-m-d H:i:s');

                        // ensure notifyState exists as VARIABLE (required for by-reference param)

                        if (!isset($notifyState) || !is_array($notifyState)) {

                            $notifyState = [];

                        }

                        $notifyState = &$notifyState;


                        tg_notify('device_status', $msg, $chatId, "offline|{$vmc}", $notifyState);
                        $sent++;
                    } else {
                        if (function_exists('xhe_log')) xhe_log('device_status', "Skip TG enabled=" . ($enabled?'1':'0') . " chat_id_len=" . strlen($chatId), 'DEBUG');
                    }
                }

                $machines[$vmc]['last_connected'] = $conn;
                $machines[$vmc]['last_seen'] = date('Y-m-d H:i:s');
                if ($loc !== '') $machines[$vmc]['location'] = $loc;
            } else {
                $machines[$vmc]['last_seen'] = date('Y-m-d H:i:s');
                if ($loc !== '') $machines[$vmc]['location'] = $loc;
            }
        }

        fclose($fh);

        $state['machines'] = $machines;
        ds_save_state($accName, $state);

        if (function_exists('xhe_log')) xhe_log('device_status', "DONE acc={$accName} rows={$rows} changed={$changed} sent={$sent}", 'INFO');

        return ['ok'=>true,'csv'=>$csvPath,'rows'=>$rows,'changed'=>$changed,'sent'=>$sent];
    }
}
