<?php

if (!function_exists('cleaning_ctrl_log')) {
    function cleaning_ctrl_log(string $msg, string $level = 'INFO'): void {
        if (function_exists('xhe_log')) {
            xhe_log('cleaning', $msg, $level);
        } elseif (function_exists('log_msg')) {
            log_msg($msg);
        }
    }
}

/**
 * cleaning_control.php
 *
 * Downloads rinsing CSV from /rinsing export=1 and checks that each machine has ALL required rinses
 * within the last 7 days. If any rinse is missing/overdue -> sends Telegram message.
 *
 * Supports EN + TR CSV headers and EN + TR cleaning type names.
 *
 * Runs in all_in_one_2_0_modular.php include context (globals provided by account_prelude).
 */

if (!function_exists('cleaning_ctrl_norm_type')) {
    function cleaning_ctrl_norm_type(string $s): string {
        $s = trim($s);
        $s = preg_replace('/\s+\(/', '(', $s);

        $map = [
            // EN
            'Mixer 1 Rinse' => 'mixer1',
            'Mixer 2 Rinse' => 'mixer2',
            'Mixer 3 Rinse' => 'mixer3',
            'ES Brewer 1 (Quick Rinsing)' => 'es1',
            'ES Brewer 1(Quick Rinsing)' => 'es1',
            'Tea Brewer 1 (Quick Rinse)' => 'tea1',
            'Tea Brewer 1(Quick Rinse)' => 'tea1',

            // TR
            'Durulama Mikseri 1' => 'mixer1',
            'Durulama Mikseri 2' => 'mixer2',
            'Durulama Mikseri 3' => 'mixer3',
            'ES Demleme 1(Hızlı Durulama)' => 'es1',
            'FB Çay Makinesi 1(Hızlı Durulama)' => 'tea1',
        ];

        return $map[$s] ?? $s;
    }
}

if (!function_exists('cleaning_ctrl_daily_should_run')) {
    function cleaning_ctrl_daily_should_run(string $key, int $periodSec = 86400): bool {
        $base = function_exists('runtime_base_dir') ? runtime_base_dir() : 'C:\\jetinno_runtime';
        $file = $base . DIRECTORY_SEPARATOR . 'state' . DIRECTORY_SEPARATOR . $key . '.json';
        $now = time();
        if (!is_file($file)) return true;
        $raw = @file_get_contents($file);
        $data = json_decode((string)$raw, true);
        $last = (int)($data['last'] ?? 0);
        return ($now - $last) >= $periodSec;
    }
}

if (!function_exists('cleaning_ctrl_daily_mark_run')) {
    function cleaning_ctrl_daily_mark_run(string $key): void {
        $base = function_exists('runtime_base_dir') ? runtime_base_dir() : 'C:\\jetinno_runtime';
        $dir = $base . DIRECTORY_SEPARATOR . 'state';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $file = $dir . DIRECTORY_SEPARATOR . $key . '.json';
        @file_put_contents($file, json_encode(['last' => time()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}

if (!function_exists('cleaning_ctrl_http_get')) {
    function cleaning_ctrl_http_get(string $url, string $cookieStr, int &$httpCode = 0): ?string {
        $httpCode = 0;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            $headers = [
                'User-Agent: Mozilla/5.0',
                'Cookie: ' . $cookieStr,
            ];
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $body = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($body === false || $body === null) return null;
            return (string)$body;
        }

        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "Cookie: {$cookieStr}\r\nUser-Agent: Mozilla/5.0\r\n",
                'timeout' => 60,
            ]
        ];
        $ctx = stream_context_create($opts);
        $body = @file_get_contents($url, false, $ctx);

        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) {
                    $httpCode = (int)$m[1];
                    break;
                }
            }
        }

        if ($body === false || $body === null) return null;
        return (string)$body;
    }
}

if (!function_exists('cleaning_ctrl_parse_csv')) {
    function cleaning_ctrl_parse_csv(string $csv): array {
        $rows = [];
        $lines = preg_split("/\r\n|\n|\r/", $csv);
        if (!$lines || count($lines) < 2) return [];

        $fp = fopen('php://temp', 'r+');
        fwrite($fp, $csv);
        rewind($fp);

        $header = null;
        while (($data = fgetcsv($fp, 0, ",")) !== false) {
            if ($header === null) {
                if (isset($data[0])) $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', $data[0]);
                $header = $data;
                continue;
            }
            $row = [];
            foreach ($header as $i => $k) {
                $row[$k] = $data[$i] ?? '';
            }
            $rows[] = $row;
        }
        fclose($fp);

        return $rows;
    }
}

if (!function_exists('cleaning_ctrl_get_vmc')) {
    function cleaning_ctrl_get_vmc(array $r): string {
        return trim((string)($r['Device ID'] ?? $r['Cihaz numarası'] ?? ''));
    }
}

if (!function_exists('cleaning_ctrl_get_addr')) {
    function cleaning_ctrl_get_addr(array $r): string {
        return trim((string)($r['Address'] ?? $r['Cihaz adresi'] ?? ''));
    }
}

if (!function_exists('cleaning_ctrl_get_type')) {
    function cleaning_ctrl_get_type(array $r): string {
        return trim((string)($r['Cleaning Type'] ?? $r['Temizlik türü'] ?? ''));
    }
}

if (!function_exists('cleaning_ctrl_get_time')) {
    function cleaning_ctrl_get_time(array $r): string {
        return trim((string)($r['Create Time'] ?? $r['Oluşturma zamanı'] ?? ''));
    }
}

if (!function_exists('cleaning_ctrl_run_for_account')) {
    function cleaning_ctrl_run_for_account(array $acc, int $userId, string $cookieStr, string $chatId, array &$notifyState): void {
        global $CFG;

 // Run report once per day after 08:00
$gateKey = 'cleaning_control_' . $userId;

$hour = (int)date('G');
if ($hour < 8) {
    if (function_exists('xhe_log')) {
        xhe_log('cleaning', "[cleaning][DEBUG] SKIP before 08:00 user_id={$userId}", 'INFO');
    }
    return;
}

if (!cleaning_ctrl_daily_should_run($gateKey, 86400)) {
    if (function_exists('xhe_log')) {
        xhe_log('cleaning', "[cleaning][DEBUG] SKIP already_sent_today user_id={$userId}", 'INFO');
    }
    return;
}
    

        $required = [
            'Mixer 1 Rinse',
            'Mixer 2 Rinse',
            'Mixer 3 Rinse',
            'ES Brewer 1 (Quick Rinsing)',
            'Tea Brewer 1 (Quick Rinse)',
        ];
        $requiredNorm = array_map('cleaning_ctrl_norm_type', $required);

        $url = "https://saas-hk.jetinno.com/rinsing?"
             . "user_id=" . urlencode((string)$userId)
             . "&daterange=&rinsing_code=&type=&is_ok=&vmc_no="
             . "&perPage=25&order_by%5Bkey%5D=&order_by%5Bvalue%5D="
             . "&export=1";

        $http = 0;
        $csv = cleaning_ctrl_http_get($url, $cookieStr, $http);

        if ($csv === null || strlen($csv) < 50) {
            if (function_exists('xhe_log')) {
                xhe_log('cleaning', "[cleaning][ERROR] DOWNLOAD failed user_id={$userId} http={$http}", 'INFO');
            }
            cleaning_ctrl_daily_mark_run($gateKey);
            return;
        }

        $base = function_exists('runtime_base_dir') ? runtime_base_dir() : 'C:\\jetinno_runtime';
        $outDir = $base . DIRECTORY_SEPARATOR . 'export';
        if (!is_dir($outDir)) @mkdir($outDir, 0777, true);
        $stamp = date('Ymd_His');
        $outPath = $outDir . DIRECTORY_SEPARATOR . "rinsing_{$userId}_{$stamp}.csv";
        @file_put_contents($outPath, $csv);

        $rows = cleaning_ctrl_parse_csv($csv);
        if (empty($rows)) {
            if (function_exists('xhe_log')) {
                xhe_log('cleaning', "[cleaning][ERROR] CSV parse empty user_id={$userId} http={$http}", 'INFO');
            }
            cleaning_ctrl_daily_mark_run($gateKey);
            return;
        }

        $now = time();
        $sevenDaysAgo = $now - 7 * 24 * 60 * 60;

        $byVmc = [];
        foreach ($rows as $r) {
            $vmc = cleaning_ctrl_get_vmc($r);
            if ($vmc === '') continue;
            $byVmc[$vmc][] = $r;
        }

        if (function_exists('xhe_log')) {
            xhe_log('cleaning', "[cleaning][DEBUG] CSV rows=" . count($rows), 'INFO');
            xhe_log('cleaning', "[cleaning][DEBUG] VMC found=" . count($byVmc), 'INFO');
        }

        $viol = [];
        foreach ($byVmc as $vmc => $list) {
            $addr = cleaning_ctrl_get_addr($list[0]);

            $missing = [];
            foreach ($requiredNorm as $idx => $needNorm) {
                $last = 0;

                foreach ($list as $rr) {
                    $ct = cleaning_ctrl_norm_type(cleaning_ctrl_get_type($rr));
                    if ($ct !== $needNorm) continue;

                    $timeStr = cleaning_ctrl_get_time($rr);
                    $ts = strtotime($timeStr);
                    if ($ts !== false && $ts > $last) $last = $ts;
                }

                if ($last === 0) {
                    $missing[] = $required[$idx] . " — никогда";
                } elseif ($last < $sevenDaysAgo) {
                    $missing[] = $required[$idx] . " — " . date('Y-m-d H:i', $last);
                }
            }

            if (!empty($missing)) {
                $viol[] = [
                    'vmc' => $vmc,
                    'addr' => $addr,
                    'missing' => $missing,
                ];
            }
        }

        if (!empty($viol)) {
            $accName = (string)($acc['name'] ?? ('user_' . $userId));
            $text = "⚠️ CLEANING CONTROL (7 дней)\n";
            $text .= "Account: {$accName} (user_id={$userId})\n\n";

            foreach ($viol as $v) {
                $text .= "VMC: {$v['vmc']}";
                if ($v['addr'] !== '') $text .= " — {$v['addr']}";
                $text .= "\n";
                foreach ($v['missing'] as $m) {
                    $text .= "• {$m}\n";
                }
                $text .= "\n";
            }

            $targetChat = (string)($acc['telegram_chat_id'] ?? $chatId);
            if ($targetChat !== '' && function_exists('tg_notify')) {
                tg_notify('cleaning', $text, $targetChat, "cleaning_control|{$userId}", $notifyState);
            }
        } else {
            if (function_exists('xhe_log')) {
                xhe_log('cleaning', "[cleaning][INFO] OK user_id={$userId} devices=" . count($byVmc), 'INFO');
            }
        }

        cleaning_ctrl_daily_mark_run($gateKey);
    }
}

// ---- RUN ----
global $CFG, $acc, $userId, $cookieStr, $chatId, $notifyState;

$enabled = cfg_module_enabled($CFG, 'cleaning_control', true);
if ($enabled) {
    if (function_exists('xhe_log')) {
        xhe_log(
            'cleaning',
            '[cleaning][INFO] START module for account=' . (string)($acc['key'] ?? $acc['name'] ?? 'ACC') . ' user_id=' . (string)$userId,
            'INFO'
        );
    }
    cleaning_ctrl_run_for_account((array)$acc, (int)$userId, (string)$cookieStr, (string)$chatId, $notifyState);
}
