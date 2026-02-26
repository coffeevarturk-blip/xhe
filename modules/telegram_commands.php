<?php
// modules/telegram_commands.php
// Telegram inbound commands via getUpdates (long polling)
// - Authorization by chat_id allowlist
// - Rate limit per command/chat
// - Logging
// - Integrates with existing tg_notify/tg_send_admin if present, otherwise sends directly

if (!function_exists('tgcmd_runtime_dir')) {
    function tgcmd_runtime_dir(): string {
        // Prefer your existing runtime_base_dir() if present
        if (function_exists('runtime_base_dir')) return rtrim(runtime_base_dir(), "\\/");

        // Fallback
        $dir = 'c:\\jetinno_runtime';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        return $dir;
    }
}

if (!function_exists('tgcmd_log')) {
    function tgcmd_log(string $line, string $tag = 'tgcmd'): void {
        $base = tgcmd_runtime_dir();
        $logDir = $base . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0777, true);
        $path = $logDir . DIRECTORY_SEPARATOR . 'telegram_commands.log';
        @file_put_contents($path, date('Y-m-d H:i:s') . " | [$tag] " . $line . PHP_EOL, FILE_APPEND);
    }
}

if (!function_exists('tgcmd_state_path')) {
    function tgcmd_state_path(): string {
        $base = tgcmd_runtime_dir();
        $stateDir = $base . DIRECTORY_SEPARATOR . 'state';
        if (!is_dir($stateDir)) @mkdir($stateDir, 0777, true);
        return $stateDir . DIRECTORY_SEPARATOR . 'telegram_inbound_state.json';
    }
}

if (!function_exists('tgcmd_state_load')) {
    function tgcmd_state_load(): array {
        $p = tgcmd_state_path();
        if (!is_file($p)) return [
            'last_update_id' => 0,
            'rl' => [], // rate limit: rl[chat_id][key]=last_ts
        ];
        $raw = @file_get_contents($p);
        $j = json_decode((string)$raw, true);
        if (!is_array($j)) $j = [];
        return array_merge([
            'last_update_id' => 0,
            'rl' => [],
        ], $j);
    }
}

if (!function_exists('tgcmd_state_save')) {
    function tgcmd_state_save(array $state): void {
        $p = tgcmd_state_path();
        @file_put_contents($p, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}

if (!function_exists('tgcmd_http_get')) {
    function tgcmd_http_get(string $url, int $timeoutSec = 40): string {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            tgcmd_log("HTTP GET fail http=$http err=$err url=$url", 'http');
            return '';
        }
        if ($http >= 400) {
            tgcmd_log("HTTP GET bad http=$http resp=" . substr((string)$resp, 0, 200), 'http');
        }
        return (string)$resp;
    }
}

if (!function_exists('tgcmd_send_message')) {
    function tgcmd_send_message(array $CFG, string $chatId, string $text): bool {
        // Prefer your existing tg_notify if present
        if (function_exists('tg_notify')) {
            // stable key so anti-spam in your tg_notify can work too
            $key = 'cmd|' . $chatId . '|' . substr(md5($text), 0, 8);
            $notifyState = null;
            if (isset($GLOBALS['notifyState'])) $notifyState = $GLOBALS['notifyState'];
            // type=admin-like, but keep separate
            return (bool)tg_notify('cmd', $text, $chatId, $key, $notifyState);
        }

        $token = $CFG['telegram']['bot_token'] ?? '';
        if ($token === '') return false;

        // Send plain text, no parse_mode to avoid entity errors
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $payload = http_build_query([
            'chat_id' => $chatId,
            'text'    => $text,
            'disable_web_page_preview' => 1,
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $ok = false;
        if ($resp !== false) {
            $j = json_decode((string)$resp, true);
            $ok = (bool)($j['ok'] ?? false);
        }

        tgcmd_log("SEND chat_id=$chatId http=$http ok=" . ($ok ? 'true' : 'false') . " err=$err", 'send');
        return $ok;
    }
}

if (!function_exists('tgcmd_get_updates')) {
    function tgcmd_get_updates(array $CFG, int $offset, int $timeoutSec = 30): array {
        $token = $CFG['telegram']['bot_token'] ?? '';
        if ($token === '') return [];

        $url = "https://api.telegram.org/bot{$token}/getUpdates?timeout={$timeoutSec}&offset={$offset}";
        $raw = tgcmd_http_get($url, $timeoutSec + 10);
        if ($raw === '') return [];

        $j = json_decode($raw, true);
        if (!is_array($j) || !($j['ok'] ?? false)) {
            tgcmd_log("getUpdates not ok raw=" . substr($raw, 0, 200), 'updates');
            return [];
        }
        return $j['result'] ?? [];
    }
}

if (!function_exists('tgcmd_is_allowed_chat')) {
    function tgcmd_is_allowed_chat(array $CFG, string $chatId): bool {
        // Allowlist: $CFG['telegram']['commands']['allowed_chat_ids'] = [ '123', '456' ]
        $allow = $CFG['telegram']['commands']['allowed_chat_ids'] ?? null;

        // Backward-friendly: if you already have admin_chat_id, allow it too
        $admin = (string)($CFG['telegram']['admin_chat_id'] ?? '');
        if ($admin !== '' && $chatId === $admin) return true;

        if (is_array($allow)) {
            foreach ($allow as $id) {
                if ((string)$id === (string)$chatId) return true;
            }
            return false;
        }

        // Default: ONLY admin_chat_id allowed (safer)
        return ($admin !== '' && $chatId === $admin);
    }
}

if (!function_exists('tgcmd_rate_limit_ok')) {
    function tgcmd_rate_limit_ok(array &$state, string $chatId, string $key, int $cooldownSec): bool {
        $now = time();
        if (!isset($state['rl'])) $state['rl'] = [];
        if (!isset($state['rl'][$chatId])) $state['rl'][$chatId] = [];
        $last = (int)($state['rl'][$chatId][$key] ?? 0);
        if ($cooldownSec > 0 && ($now - $last) < $cooldownSec) return false;
        $state['rl'][$chatId][$key] = $now;
        return true;
    }
}

if (!function_exists('tgcmd_help_text')) {
    function tgcmd_help_text(): string {
        return
"Команды:\n" .
"/help — список\n" .
"/sales — продажи (сегодня)\n" .
"/sales date YYYY-MM-DD — продажи за дату\n" .
"/reboot VMCNO — ребут машины (если поддерживается)\n" .
"/sync VMCNO — sync машины (если поддерживается)\n" .
"/ping — проверка связи";
    }
}

if (!function_exists('tgcmd_parse_cmd')) {
    function tgcmd_parse_cmd(string $text): array {
        $text = trim($text);
        // Remove bot username suffix: /cmd@BotName
        $text = preg_replace('~^/([a-zA-Z_]+)@[\w_]+~', '/$1', $text);

        $parts = preg_split('~\s+~', $text);
        $cmd = strtolower($parts[0] ?? '');
        $args = array_slice($parts, 1);
        return [$cmd, $args];
    }
}

if (!function_exists('tgcmd_sales_get_accounts')) {
    function tgcmd_sales_get_accounts(array $CFG): array {
        // Try common locations for accounts list
        $candidates = [];

        if (isset($CFG['accounts']) && is_array($CFG['accounts'])) $candidates[] = $CFG['accounts'];
        if (isset($CFG['jetinno']['accounts']) && is_array($CFG['jetinno']['accounts'])) $candidates[] = $CFG['jetinno']['accounts'];
        if (isset($CFG['jetinno_accounts']) && is_array($CFG['jetinno_accounts'])) $candidates[] = $CFG['jetinno_accounts'];
        if (isset($CFG['config']['accounts']) && is_array($CFG['config']['accounts'])) $candidates[] = $CFG['config']['accounts'];

        foreach ($candidates as $arr) {
            // accept first non-empty
            if (is_array($arr) && count($arr) > 0) return $arr;
        }
        return [];
    }
}

if (!function_exists('tgcmd_sales_resolve_account')) {
    function tgcmd_sales_resolve_account(array $CFG, string $chatId, array $args): array {
        // returns [acc(array), accountKey(string)]
        $accounts = tgcmd_sales_get_accounts($CFG);

        // If first arg matches account name, use it (admin can query any)
        $arg0 = strtolower(trim((string)($args[0] ?? '')));
        if ($arg0 !== '' && $arg0 !== 'week' && $arg0 !== 'month' && $arg0 !== 'date' && $arg0 !== 'today') {
            foreach ($accounts as $a) {
                if (!is_array($a)) continue;
                $nm = strtolower((string)($a['name'] ?? ''));
                if ($nm !== '' && $nm === $arg0) {
                    $key = (string)($a['name'] ?? $a['user_id'] ?? 'ACC');
                    return [$a, $key];
                }
            }
        }

        // Match by chat id
        foreach ($accounts as $a) {
            if (!is_array($a)) continue;
            $cid = (string)($a['telegram_chat_id'] ?? '');
            if ($cid !== '' && $cid === (string)$chatId) {
                $key = (string)($a['name'] ?? $a['user_id'] ?? 'ACC');
                return [$a, $key];
            }
        }

        // Fallback: prefer MAIN
        foreach ($accounts as $a) {
            if (!is_array($a)) continue;
            $nm = (string)($a['name'] ?? '');
            if (strtolower($nm) === 'main') {
                $key = (string)($a['name'] ?? $a['user_id'] ?? 'ACC');
                return [$a, $key];
            }
        }

        // Last resort
        if (count($accounts) > 0 && is_array($accounts[0])) {
            $a = $accounts[0];
            $key = (string)($a['name'] ?? $a['user_id'] ?? 'ACC');
            return [$a, $key];
        }

        return [['name'=>'ACC'], 'ACC'];
    }
}

if (!function_exists('tgcmd_sales_find_latest_json_for_day')) {
    function tgcmd_sales_find_latest_json_for_day(string $exportDir, int $userId, string $dateYmd): ?string {
        // sales_{userId}_YYYYMMDD_HHMMSS.json -> pick latest for given YYYYMMDD
        $ymdCompact = str_replace('-', '', $dateYmd); // YYYYMMDD
        $pat = $exportDir . DIRECTORY_SEPARATOR . "sales_{$userId}_{$ymdCompact}_*.json";
        $files = glob($pat);
        if (!is_array($files) || count($files) === 0) return null;

        usort($files, function($a, $b) { return strcmp($b, $a); }); // filename has timestamp, desc is ok
        return $files[0] ?? null;
    }
}


if (!function_exists('tgcmd_sales_find_machines')) {
    function tgcmd_sales_find_machines($j) {
        if (!is_array($j)) return null;

        // direct keys (preferred)
        foreach (['by_vmc','byVmc','by_vmc_list','byMachine','by_machine','machines','per_machine','items','top'] as $k) {
            if (isset($j[$k]) && is_array($j[$k])) return $j[$k];
        }

        // one-level deep search (some JSONs wrap under 'data' / 'result')
        foreach ($j as $v) {
            if (is_array($v)) {
                foreach (['by_vmc','byVmc','by_vmc_list','byMachine','by_machine','machines','per_machine','items','top'] as $k) {
                    if (isset($v[$k]) && is_array($v[$k])) return $v[$k];
                }
            }
        }

        // heuristic: find associative map vmc=>{amount,count}
        foreach ($j as $k => $v) {
            if (!is_array($v)) continue;
            // check if looks like vmc map
            $ok = 0; $checked = 0;
            foreach ($v as $kk => $vv) {
                if (!is_array($vv)) continue;
                $checked++;
                $hasAmt = (isset($vv['amount']) || isset($vv['sum']) || isset($vv['total']));
                $hasCnt = (isset($vv['count']) || isset($vv['cnt']) || isset($vv['num']));
                if ($hasAmt || $hasCnt) $ok++;
                if ($checked >= 5) break;
            }
            if ($checked > 0 && $ok > 0) return $v;
        }

        return null;
    }
}

if (!function_exists('tgcmd_sales_parse_json')) {
    function tgcmd_sales_parse_json(string $file): array {
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') return ['ok'=>false, 'error'=>'read_failed'];
        $j = json_decode($raw, true);
        if (!is_array($j)) return ['ok'=>false, 'error'=>'json_decode_failed'];

        // totals
        $total = null;
        foreach (['total','total_tl','sum','amount','total_amount','totalToday','total_today'] as $k) {
            if (isset($j[$k])) { $total = (float)$j[$k]; break; }
        }
        if ($total === null && isset($j['stats']['total'])) $total = (float)$j['stats']['total'];

        $count = null;
        foreach (['count','cnt','total_count','countToday','count_today'] as $k) {
            if (isset($j[$k])) { $count = (int)$j[$k]; break; }
        }
        if ($count === null && isset($j['stats']['count'])) $count = (int)$j['stats']['count'];

        // machine breakdown
        $machines = tgcmd_sales_find_machines($j);



        return ['ok'=>true, 'total'=>$total ?? 0.0, 'count'=>$count ?? 0, 'machines'=>$machines, 'raw'=>$j];
    }
}


if (!function_exists('tgcmd_sales_merge_machine_totals')) {
    function tgcmd_sales_merge_machine_totals(array &$agg, $machines, int $userId): void {
        if (!is_array($machines)) return;

        // normalize common shapes
        // A) list of rows: [['vmc'=>'81957','sum'=>830,'cnt'=>10,'loc'=>'...'], ...]
        // B) map: '81957' => ['sum'=>..., 'cnt'=>..., 'loc'=>...]
        foreach ($machines as $k => $v) {
            $vmc = '';
            $sum = 0.0;
            $cnt = 0;
            $loc = '';

            if (is_array($v)) {
                if (isset($v['vmc'])) $vmc = (string)$v['vmc'];
                else if (isset($v['vmcNo'])) $vmc = (string)$v['vmcNo'];
                else if (isset($v['vmc_no'])) $vmc = (string)$v['vmc_no'];
                else if (is_string($k) && preg_match('~^\d{4,}$~', $k)) $vmc = $k;

                foreach (['sum','total','amount'] as $sk) if (isset($v[$sk])) { $sum = (float)$v[$sk]; break; }
                foreach (['cnt','count','num'] as $ck) if (isset($v[$ck])) { $cnt = (int)$v[$ck]; break; }
                foreach (['loc','location','name','address'] as $lk) if (isset($v[$lk])) { $loc = (string)$v[$lk]; break; }
            } else if (is_numeric($v) && is_string($k)) {
                $vmc = $k;
                $sum = (float)$v;
            }

            if ($vmc === '') continue;

            if (!isset($agg[$vmc])) $agg[$vmc] = ['sum'=>0.0,'cnt'=>0,'loc'=>$loc];
            $agg[$vmc]['sum'] += $sum;
            $agg[$vmc]['cnt'] += $cnt;
            if ($agg[$vmc]['loc'] === '' && $loc !== '') $agg[$vmc]['loc'] = $loc;
        }
    }
}

if (!function_exists('tgcmd_sales_format_top')) {
    function tgcmd_sales_format_top(array $byMachine, int $topN = 5): array {
        uasort($byMachine, function($a, $b) {
            if (($a['sum'] ?? 0) == ($b['sum'] ?? 0)) return (int)($b['cnt'] ?? 0) <=> (int)($a['cnt'] ?? 0);
            return (float)($b['sum'] ?? 0) <=> (float)($a['sum'] ?? 0);
        });

        $out = [];
        $i = 0;
        foreach ($byMachine as $vmc => $m) {
            $i++;
            $loc = (string)($m['loc'] ?? 'Unknown');
            $sum = (float)($m['sum'] ?? 0);
            $cnt = (int)($m['cnt'] ?? 0);
            $out[] = "{$i}) {$vmc} - {$loc} | " . number_format($sum, 2, '.', '') . " TL ({$cnt})";
            if ($i >= $topN) break;
        }
        return $out;
    }
}


if (!function_exists('tgcmd_sales_load_locations')) {
    function tgcmd_sales_load_locations(int $userId): array {

        $base = tgcmd_runtime_dir();
        $file = $base . DIRECTORY_SEPARATOR . 'state'
              . DIRECTORY_SEPARATOR . "jetinno_locations_{$userId}.json";

        if (!is_file($file)) return [];

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') return [];

        $j = json_decode($raw, true);
        if (!is_array($j)) return [];

        return (array)($j['map'] ?? []);
    }
}

if (!function_exists('tgcmd_sales_summary_from_json_day')) {
    function tgcmd_sales_summary_from_json_day(array $CFG, array $acc, int $userId, string $accountKey, string $dateYmd): array {
        $base = tgcmd_runtime_dir();
        $exportDir = $base . DIRECTORY_SEPARATOR . 'export';

        $file = tgcmd_sales_find_latest_json_for_day($exportDir, (int)$userId, (string)$dateYmd);
        if ($file === null) return ['ok'=>false, 'error'=>'no_sales_json_for_day'];

        $parsed = tgcmd_sales_parse_json($file);
        if (!($parsed['ok'] ?? false)) return ['ok'=>false, 'error'=>'parse_failed'];

        $byMachine = [];
        tgcmd_sales_merge_machine_totals($byMachine, $parsed['machines'] ?? null, (int)$userId);

        
        
            $locMap = tgcmd_sales_load_locations((int)$userId);
            foreach ($byMachine as $vmc => &$m) {
                if (isset($locMap[$vmc])) $m['loc'] = $locMap[$vmc];
            }
            unset($m);
$locMap = tgcmd_sales_load_locations((int)$userId);
        foreach ($byMachine as $vmc => &$m) {
            if (isset($locMap[$vmc])) $m['loc'] = $locMap[$vmc];
        }
        unset($m);
$accName = (string)($acc['name'] ?? $accountKey);
        $lines = [];
        $lines[] = "💰 SALES UPDATE | {$accName}";
        $lines[] = "Today: {$dateYmd}";
        $lines[] = "Total: " . number_format((float)($parsed['total'] ?? 0), 2, '.', '') . " TL (" . (int)($parsed['count'] ?? 0) . ")";
        $lines[] = "Top:";

        $topLines = tgcmd_sales_format_top($byMachine, 5);
        
        if (function_exists('xhe_log')) xhe_log('tgcmd', 'SALES JSON machines=' . count($byMachine), 'DEBUG');
if (count($topLines) > 0) $lines = array_merge($lines, $topLines);
        else $lines[] = "— top not available —";

        return ['ok'=>true, 'text'=>implode("\n", $lines)];
    }
}

if (!function_exists('tgcmd_sales_summary_from_json_range')) {
    function tgcmd_sales_summary_from_json_range(array $CFG, array $acc, int $userId, string $accountKey, string $fromYmd, string $toYmd, string $title): array {
        $base = tgcmd_runtime_dir();
        $exportDir = $base . DIRECTORY_SEPARATOR . 'export';

        $fromTs = strtotime($fromYmd);
        $toTs   = strtotime($toYmd);
        if ($fromTs === false || $toTs === false) return ['ok'=>false, 'error'=>'bad_date'];
        if ($toTs < $fromTs) { $tmp = $fromTs; $fromTs = $toTs; $toTs = $tmp; }

        $totalSum = 0.0;
        $totalCnt = 0;
        $byMachine = [];
        $days = 0;

        for ($ts = $fromTs; $ts <= $toTs; $ts += 86400) {
            $d = date('Y-m-d', $ts);
            $file = tgcmd_sales_find_latest_json_for_day($exportDir, $userId, $d);
            if ($file === null) continue;

            $parsed = tgcmd_sales_parse_json($file);
            if (!($parsed['ok'] ?? false)) continue;

            $totalSum += (float)($parsed['total'] ?? 0);
            $totalCnt += (int)($parsed['count'] ?? 0);
            tgcmd_sales_merge_machine_totals($byMachine, $parsed['machines'] ?? null, $userId);
            $days++;
        }

        $accName = (string)($acc['name'] ?? $accountKey);
        $lines = [];
        $lines[] = "💰 SALES {$title} | {$accName}";
        $lines[] = "Range: {$fromYmd} - {$toYmd}";
        $lines[] = "Total: " . number_format($totalSum, 2, '.', '') . " TL ({$totalCnt})";
        $lines[] = "Days: {$days}";
        $lines[] = "Top:";

        $topLines = tgcmd_sales_format_top($byMachine, 7);
        if (count($topLines) > 0) $lines = array_merge($lines, $topLines);
        else $lines[] = "— top not available —";

        return ['ok'=>true, 'text'=>implode("\n", $lines)];
    }
}

if (!function_exists('tgcmd_sales_today_summary')) {
    
function tgcmd_sales_today_summary(array $CFG, string $chatId, array $args): string {
    // /sales: read only from sales_top_<user_id>.json produced by sales_syrups.php
    [$acc, $accountKey] = tgcmd_sales_resolve_account($CFG, $chatId, $args);
    $userId  = (int)($acc['user_id'] ?? 0);
    $accName = (string)($acc['name'] ?? $accountKey);

    if ($userId <= 0) {
        return "SALES: account {$accName} missing user_id ❌";
    }

    // Export dir detection
    $exportDir = 'C:\\jetinno_runtime\\export';
    if (function_exists('runtime_export_dir')) {
        $exportDir = rtrim(runtime_export_dir(), "/\\");
    } elseif (function_exists('runtime_base_dir')) {
        $exportDir = rtrim(runtime_base_dir(), "/\\") . DIRECTORY_SEPARATOR . 'export';
    }

    $file = $exportDir . DIRECTORY_SEPARATOR . "sales_top_{$userId}.json";
    if (!is_file($file)) {
        tgcmd_log("SALES_TOP not found file={$file}", "tgcmd");
        return "SALES: no snapshot yet for {$accName} ({$userId})";
    }

    $raw = @file_get_contents($file);
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) {
        tgcmd_log("SALES_TOP decode failed file={$file}", "tgcmd");
        return "SALES: failed to read snapshot for {$accName} ({$userId})";
    }

    $daterange = (string)($j['daterange'] ?? '');
    $updatedAt = (string)($j['updated_at'] ?? '');

    $total = (float)($j['total_amount'] ?? 0);
    $count = (int)($j['total_count'] ?? 0);

    $out  = "📊 SALES UPDATE | {$accName}\n";
    if ($daterange !== '') $out .= "Date: {$daterange}\n";
    if ($updatedAt !== '') $out .= "Updated: {$updatedAt}\n";
    $out .= "Total: " . number_format($total, 2, '.', '') . " TL ({$count})\n\n";
    $out .= "🏆 TOP 5 MACHINES:\n";

    $top = $j['top5'] ?? [];
    if (is_array($top) && count($top) > 0) {
        foreach ($top as $i => $row) {
            $rank = $i + 1;
            $vmc  = preg_replace('~\D+~', '', (string)($row['vmc'] ?? ''));
            if ($vmc === '') continue;
            $addr = (string)($row['address'] ?? '');
            $amt  = (float)($row['amount'] ?? 0);
            $cnt  = (int)($row['count'] ?? 0);
            if ($addr === '') $addr = '-';
            $out .= "{$rank}. {$vmc} - {$addr} | " . number_format($amt, 2, '.', '') . " TL ({$cnt})\n";
        }
    } else {
        $out .= "No sales today\n";
    }

    return $out;
}

}

if (!function_exists('tgcmd_handle_command')) {
    function tgcmd_handle_command(array $CFG, array &$state, string $chatId, string $from, string $text): void {
        [$cmd, $args] = tgcmd_parse_cmd($text);

        $cooldown = (int)($CFG['telegram']['commands']['cooldown_sec'] ?? 5);
        $cooldownHeavy = (int)($CFG['telegram']['commands']['cooldown_heavy_sec'] ?? 20);
		
		// ===== CMD: /make <VMC> <DRINK_ID>  (direct) =====
if ($cmd === '/make') {

    // ожидаем: /make 81941 125
    $vmc      = trim((string)($args[0] ?? ''));
    $drink_id = trim((string)($args[1] ?? ''));

    if ($vmc === '' || $drink_id === '') {
        tgcmd_send_message($CFG, $chatId, "Формат: /make 81941 125");
        return;
    }

    if (!function_exists('make_drink_universal')) {
        tgcmd_send_message($CFG, $chatId, "MAKE: make_drink_universal() не найдена ❌");
        return;
    }

    // прямое выполнение (без lock, без доп проверок)
    $res = make_drink_universal($vmc, $drink_id);
    $st  = (string)($res['status'] ?? 'ui_error');

    if ($st === 'success') {
        tgcmd_send_message($CFG, $chatId, "✅ MAKE OK | VMC: $vmc | DRINK: $drink_id");
    } elseif ($st === 'vmc_invalid') {
        tgcmd_send_message($CFG, $chatId, "❌ MAKE | VMC неверный: $vmc");
    } elseif ($st === 'drink_not_found') {
        tgcmd_send_message($CFG, $chatId, "❌ MAKE | Напиток не найден: $drink_id");
    } else {
        tgcmd_send_message($CFG, $chatId, "❌ MAKE | UI ERROR");
    }

    return;
}

        if ($cmd === '/start' || $cmd === '/help' || $cmd === '/?') {
            if (!tgcmd_rate_limit_ok($state, $chatId, 'help', $cooldown)) return;
            tgcmd_send_message($CFG, $chatId, tgcmd_help_text());
            return;
        }

        if ($cmd === '/ping') {
            if (!tgcmd_rate_limit_ok($state, $chatId, 'ping', $cooldown)) return;
            tgcmd_send_message($CFG, $chatId, "pong ✅ " . date('Y-m-d H:i:s'));
            return;
        }

        if ($cmd === '/sales') {
            if (!tgcmd_rate_limit_ok($state, $chatId, 'sales', $cooldownHeavy)) return;

            $date = '';
            if (($args[0] ?? '') === 'date' && isset($args[1])) $date = (string)$args[1];

            $txt = tgcmd_sales_today_summary($CFG, $chatId, $args);
tgcmd_send_message($CFG, $chatId, $txt);
            return;
        }

        if ($cmd === '/reboot') {
            if (!tgcmd_rate_limit_ok($state, $chatId, 'reboot', $cooldownHeavy)) return;
            $vmc = preg_replace('~\D+~', '', (string)($args[0] ?? ''));
            if ($vmc === '') {
                tgcmd_send_message($CFG, $chatId, "Формат: /reboot 81941");
                return;
            }

            // Hook: call your existing reboot trigger
            if (function_exists('request_machine_reboot')) {
                $ok = (bool)request_machine_reboot($vmc);
                tgcmd_send_message($CFG, $chatId, $ok ? "REBOOT queued ✅ VMC: $vmc" : "REBOOT failed ❌ VMC: $vmc");
            } else {
                tgcmd_send_message($CFG, $chatId, "REBOOT: нет функции request_machine_reboot() в сборке ❌");
            }
            return;
        }

        if ($cmd === '/sync') {
            if (!tgcmd_rate_limit_ok($state, $chatId, 'sync', $cooldownHeavy)) return;
            $vmc = preg_replace('~\D+~', '', (string)($args[0] ?? ''));
            if ($vmc === '') {
                tgcmd_send_message($CFG, $chatId, "Формат: /sync 81941");
                return;
            }

            if (function_exists('request_machine_sync')) {
                $ok = (bool)request_machine_sync($vmc);
                tgcmd_send_message($CFG, $chatId, $ok ? "SYNC queued ✅ VMC: $vmc" : "SYNC failed ❌ VMC: $vmc");
            } else {
                tgcmd_send_message($CFG, $chatId, "SYNC: нет функции request_machine_sync() в сборке ❌");
            }
            return;
        }

        // Unknown command
        if (str_starts_with($cmd, '/')) {
            if (!tgcmd_rate_limit_ok($state, $chatId, 'unknown', $cooldown)) return;
            tgcmd_send_message($CFG, $chatId, "Неизвестная команда. /help");
        }
    }
}

if (!function_exists('tg_commands_poll')) {
    /**
     * Call this inside your main loop.
     * Returns number of processed updates.
     */
    function tg_commands_poll(array $CFG): int {
        $enabled = (bool)($CFG['telegram']['commands']['enabled'] ?? true);
        if (!$enabled) return 0;

        $state = tgcmd_state_load();
        $offset = (int)($state['last_update_id'] ?? 0) + 1;

        $updates = tgcmd_get_updates($CFG, $offset, 25);
        if (!is_array($updates) || count($updates) === 0) return 0;

        $processed = 0;

        foreach ($updates as $upd) {
            $uid = (int)($upd['update_id'] ?? 0);
            if ($uid > (int)$state['last_update_id']) $state['last_update_id'] = $uid;

            $msg = $upd['message'] ?? ($upd['edited_message'] ?? null);
            if (!$msg) continue;

            $chatId = (string)($msg['chat']['id'] ?? '');
            $text   = (string)($msg['text'] ?? '');

            $fromUser = '';
            if (isset($msg['from'])) {
                $fn = (string)($msg['from']['first_name'] ?? '');
                $ln = (string)($msg['from']['last_name'] ?? '');
                $un = (string)($msg['from']['username'] ?? '');
                $fromUser = trim($fn . ' ' . $ln);
                if ($un !== '') $fromUser .= " (@$un)";
            }

            // Log inbound
            if ($chatId !== '' && $text !== '') {
                tgcmd_log("IN chat=$chatId from={$fromUser} text=" . str_replace(["\r","\n"], [' ',' '], $text), 'in');
            }

            // Authorization
            if ($chatId === '' || $text === '') continue;
            if (!tgcmd_is_allowed_chat($CFG, $chatId)) {
                // optional: silently ignore or notify
                $notify = (bool)($CFG['telegram']['commands']['notify_denied'] ?? false);
                if ($notify) tgcmd_send_message($CFG, $chatId, "Доступ запрещён ❌");
                continue;
            }

            tgcmd_handle_command($CFG, $state, $chatId, $fromUser, $text);
            $processed++;
        }

        tgcmd_state_save($state);
        return $processed;
    }
}
