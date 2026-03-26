<?php

/**
 * Extract ERROR/WARNING code from a message text.
 * Returns ['kind' => 'ERROR'|'WARNING', 'code' => int] or null.
 */
function tg_extract_code(string $text): ?array {
    // Accept codes like 7300, Z0050, 3B80, A1B2, etc.
    // We extract the token immediately following "ERROR:" or "WARNING:" (case-insensitive).
    // Token: letters/digits, may include internal letters (e.g. 3B80) and optional leading letters.
    if (preg_match('/\b(ERROR|WARNING)\s*:\s*([A-Z0-9]+)\b/i', $text, $m)) {
        return ['kind' => strtoupper($m[1]), 'code' => strtoupper($m[2])];
    }
    if (preg_match('/\bCode\s*:\s*(ERROR|WARNING)\s*:\s*([A-Z0-9]+)\b/i', $text, $m)) {
        return ['kind' => strtoupper($m[1]), 'code' => strtoupper($m[2])];
    }
    return null;
}

/**
 * Decide whether a given code should be filtered (i.e. NOT sent) according to config.
 * $cfgNotify - $CFG['notify'] or equivalent
 * $effectiveType - 'errors' or 'warnings'
 * $info - result of tg_extract_code or null
 * Returns true if message SHOULD be filtered (i.e. not sent).
 */
function tg_is_code_filtered(array $cfgNotify, string $effectiveType, ?array $info): bool {
    if (!$info) return false;
    if ($effectiveType !== 'errors' && $effectiveType !== 'warnings') return false;

    $filters = $cfgNotify['filters'] ?? [];
    $f = $filters[$effectiveType] ?? null;
    if (!is_array($f) || empty($f)) return false;

    $mode = strtolower((string)($f['mode'] ?? 'deny')); // deny = blacklist, allow = whitelist
    $codes = $f['codes'] ?? [];
    if (!is_array($codes)) $codes = [];

    $needle = strtoupper((string)($info['code'] ?? ''));
    if ($needle === '') return false;

    // Normalize list items to uppercase strings
    $list = [];
    foreach ($codes as $v) {
        $v = strtoupper(trim((string)$v));
        if ($v !== '') $list[] = $v;
    }

    $inList = in_array($needle, $list, true);

    if ($mode === 'allow') {
        // allow list: if code not in list => filter it out (do not send)
        return !$inList;
    }
    // deny list: if code in list => filter it out (do not send)
    return $inList;
}


// functions.php

// -------------------- CONFIG LOADER --------------------
if (!function_exists('cfg')) {
    function cfg(): array
    {
        static $CFG = null;
        if ($CFG !== null) return $CFG;

        $path = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
        if (!is_file($path)) {
            throw new RuntimeException("config.php not found at: " . $path);
        }
        $CFG = require $path;
        if (!is_array($CFG)) {
            throw new RuntimeException("config.php must return array");
        }
        return $CFG;
    }
}


// -------------------- STATE FILE PATHS --------------------
if (!function_exists('runtime_base_dir')) {
    /**
     * Базовая папка рантайма (state + logs) на диске C.
     * Не требует правок config.php: можно переопределить через $CFG['paths']['base_dir'].
     */
    function runtime_base_dir(): string
    {
        $c = cfg();
        $paths = $c['paths'] ?? [];
        $base = $paths['base_dir'] ?? 'c:\\jetinno_runtime';
        $base = (string)$base;
        $base = rtrim($base, "\\/ ");
        if ($base === '') $base = 'c:\\jetinno_runtime';
        return $base;
    }
}

if (!function_exists('state_path')) {
    /**
     * Получить путь к файлу состояния.
     * $kind:
     * - 'main'   : основное состояние (машины/продажи/ребуты)
     * - 'notify' : отдельное состояние для антиспама Telegram-уведомлений
     */
    function state_path(string $kind = 'main'): string
    {
        // ВАЖНО: по требованию — состояние всегда хранится в отдельной папке на C:
        // (игнорируем paths['state_files'] и paths['state_file'] из config.php, чтобы не писать в корень C:)
        $base = runtime_base_dir();
        $stateDir = $base . '\state';
        if ($kind === 'notify') return $stateDir . '\jetinno_notify_state.json';
        return $stateDir . '\jetinno_state.json';
    }

} // close if (!function_exists('state_path'))


if (!function_exists('log_dir')) {
    function log_dir(): string
    {
        return runtime_base_dir() . '\\logs';
    }
}

if (!function_exists('is_debug')) {
    /**
     * Debug режим:
     *  - $CFG['log']['debug'] = true
     *  - или $CFG['debug'] = true
     *  - или наличие файла-флага: <base_dir>\DEBUG_ON
     */
    function is_debug(): bool
    {
        $c = cfg();
        if (isset($c['log']['debug'])) return (bool)$c['log']['debug'];
        if (isset($c['debug'])) return (bool)$c['debug'];
        return is_file(runtime_base_dir() . '\\DEBUG_ON');
    }
}


// -------------------- LOG ROTATION --------------------
if (!function_exists('cleanup_old_logs')) {
    /**
     * Удаляет лог-файлы старше $keepDays дней.
     * Хранит последние $keepDays календарных дней (включая сегодня).
     */
    function cleanup_old_logs(int $keepDays = 7): void
    {
        $keepDays = max(1, (int)$keepDays);
        $dir = log_dir();
        if (!is_dir($dir)) return;

        // cutoff: начало дня (00:00) для даты (today - ($keepDays-1))
        $cutoff = strtotime(date('Y-m-d', strtotime('-' . ($keepDays - 1) . ' days')) . ' 00:00:00');

        foreach (glob($dir . '\\*.log') as $file) {
            $base = basename($file);
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})\.log$/', $base, $m)) {
                continue; // не трогаем "нестандартные" файлы
            }
            $ts = strtotime($m[1] . ' 00:00:00');
            if ($ts === false) continue;
            if ($ts < $cutoff) {
                @unlink($file);
            }
        }
    }
}

// -------------------- COMMON HELPERS --------------------
if (!function_exists('cleanText')) {
    function cleanText(string $s): string {
        $s = html_entity_decode($s, ENT_QUOTES);
        $s = strip_tags($s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }
}

// -------------------- TELEGRAM NOTIFY FILTERS (PER-ACCOUNT) --------------------
if (!function_exists('isTelegramNotifyEnabled')) {
    /**
     * Разрешены ли уведомления типа $type для конкретного аккаунта.
     *
     * Схема в config.php (вы добавляете сами):
     *  'jetinno' => [
     *      'telegram_notify_defaults' => ['sales'=>true, ...], // опционально
     *      'accounts' => [
     *          [
     *              ...
     *              'telegram_notify' => ['sales'=>false, 'supply'=>true, ...]
     *          ],
     *      ],
     *  ]
     *
     * Если ничего не задано — по умолчанию ВКЛ (совместимость).
     */
    function isTelegramNotifyEnabled(array $account, string $type): bool
    {
        $cfg = cfg();
        $defaults = [];
        if (isset($cfg['jetinno']['telegram_notify_defaults']) && is_array($cfg['jetinno']['telegram_notify_defaults'])) {
            $defaults = $cfg['jetinno']['telegram_notify_defaults'];
        }

        // по умолчанию всё включено
        $default = true;
        if (array_key_exists($type, $defaults)) {
            $default = (bool)$defaults[$type];
        }

        if (!isset($account['telegram_notify']) || !is_array($account['telegram_notify'])) {
            return $default;
        }

        if (!array_key_exists($type, $account['telegram_notify'])) {
            return $default;
        }

        return (bool)$account['telegram_notify'][$type];
    }
}


// -------------------- LOGGING (XHE) --------------------
if (!function_exists('xhe_log')) {
    /**
     * Логгер: пишет и в вывод (как раньше), и в файл.
     * Уровни: DEBUG / INFO / WARN / ERROR
     */
    function xhe_log(string $category, string $msg, string $level = 'INFO'): void
    {
        $c = cfg();
        $logCfg = $c['log'] ?? [];
        if (!(bool)($logCfg['enabled'] ?? true)) return;
        static $didCleanup = false;
        if (!$didCleanup) {
            $keep = (int)($logCfg['keep_days'] ?? 7);
            cleanup_old_logs($keep);
            $didCleanup = true;
        }


        $level = strtoupper(trim($level));
        if ($level === '') $level = 'INFO';

        // DEBUG печатаем только при включенном debug
        $debug = is_debug();
        if ($level === 'DEBUG' && !$debug) {
            // всё равно можно писать в файл при желании, но по запросу пользователя делаем тихо
            return;
        }

        $cats = $logCfg['categories'] ?? [];
        if (isset($cats[$category]) && !$cats[$category]) return;

        $withTs = (bool)($logCfg['with_ts'] ?? true);
        $prefix = $withTs ? (date('Y-m-d H:i:s') . " | ") : "";

        // 1) вывод в консоль/браузер (XHE)
        // В debug режиме — максимально детально, иначе — информационно.
        $printLevel = $debug ? true : ($level !== 'DEBUG');
        if ($printLevel) {
            echo $prefix . "[" . $category . "]" . "[" . $level . "] " . $msg . "<br>";
        }

        // 2) запись в файл по датам
        $dir = log_dir();
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $file = $dir . '\\' . date('Y-m-d') . '.log';
        $line = $prefix . "[" . $category . "]" . "[" . $level . "] " . $msg . PHP_EOL;
        @file_put_contents($file, $line, FILE_APPEND);
    }
}

if (!function_exists('log_debug')) {
    function log_debug(string $category, string $msg): void { xhe_log($category, $msg, 'DEBUG'); }
}
if (!function_exists('log_info')) {
    function log_info(string $category, string $msg): void { xhe_log($category, $msg, 'INFO'); }
}
if (!function_exists('log_warn')) {
    function log_warn(string $category, string $msg): void { xhe_log($category, $msg, 'WARN'); }
}
if (!function_exists('log_error')) {
    function log_error(string $category, string $msg): void { xhe_log($category, $msg, 'ERROR'); }
}

// -------------------- ADMIN NOTIFY (SYSTEM ERRORS) --------------------
if (!function_exists('notifyAdmin')) {
    /**
     * Системные ошибки -> telegram.admin_chat_id (с rate-limit).
     * $key нужен, чтобы одинаковая ошибка не спамила (cooldown).
     * Можно передать &$state (из all_in_one.php) — тогда не будет лишних чтений/записей state.
     */
    function notifyAdmin(string $text, string $key = 'system', ?array &$state = null): void
    {
        $c = cfg();
        $adminChat = $c['telegram']['admin_chat_id'] ?? null;
        if ($adminChat === null || $adminChat === '' || $adminChat === 0) return;

        $rl = $c['telegram']['admin_rate_limit'] ?? [];
        $rlEnabled = (bool)($rl['enabled'] ?? true);
        $cooldown = (int)($rl['cooldown_sec'] ?? 300);
        $maxPerHour = (int)($rl['max_per_hour'] ?? 10);

        $needSave = false;
        if ($state === null) {
            // fallback: загрузим state сами
            $stateFile = state_path('notify');
            $state = state_load($stateFile, [
                'admin_notify' => [
                    'keys' => [],
                    'hour' => ['start' => 0, 'count' => 0],
                ],
            ]);
            $needSave = true;
        }

        if (!isset($state['admin_notify']) || !is_array($state['admin_notify'])) { $state['admin_notify'] = ['keys' => [], 'hour' => ['start' => 0, 'count' => 0]]; }
        if (!isset($state['admin_notify']['keys']) || !is_array($state['admin_notify']['keys'])) { $state['admin_notify']['keys'] = []; }
        if (!isset($state['admin_notify']['hour']) || !is_array($state['admin_notify']['hour'])) { $state['admin_notify']['hour'] = ['start' => 0, 'count' => 0]; }

        $now = time();

        if ($rlEnabled) {
            // hour window
            $hourStart = (int)($state['admin_notify']['hour']['start'] ?? 0);
            $hourCount = (int)($state['admin_notify']['hour']['count'] ?? 0);

            if ($hourStart <= 0 || ($now - $hourStart) >= 3600) {
                $hourStart = $now;
                $hourCount = 0;
            }

            if ($maxPerHour > 0 && $hourCount >= $maxPerHour) {
                // достигнут лимит — молча пропускаем
                if ($needSave) {
                    $state['admin_notify']['hour'] = ['start' => $hourStart, 'count' => $hourCount];
                    state_save(state_path('notify'), $state);
                }
                return;
            }

            $lastTs = (int)($state['admin_notify']['keys'][$key] ?? 0);
            if ($lastTs > 0 && ($now - $lastTs) < $cooldown) {
                // cooldown по ключу
                if ($needSave) {
                    $state['admin_notify']['hour'] = ['start' => $hourStart, 'count' => $hourCount];
                    state_save(state_path('notify'), $state);
                }
                return;
            }

            // allow
            $state['admin_notify']['keys'][$key] = $now;
            $state['admin_notify']['hour'] = ['start' => $hourStart, 'count' => $hourCount + 1];

            if ($needSave) {
                state_save(state_path('notify'), $state);
            }
        }

        $msg = "🚨 <b>СИСТЕМНАЯ ОШИБКА</b>\n\n" . $text . "\n\n🕒 " . date('d.m.Y H:i:s');
        sendmessage($msg, $adminChat, 'HTML');
    }
}



// -------------------- TELEGRAM NOTIFY (ANTI-SPAM BY TYPE) --------------------
if (!function_exists('tg_notify')) {
    /**
     * Уведомления в Telegram с антиспамом по типам (config.notify.types).
     * $type: reboot/sales/supply/orders/... (см. config.php)
     * $key:  уникальный ключ для дедупликации/кулдауна (например accountKey|machineNo, orderNo, deviceId|supplyId)
     * Можно передать &$state (из all_in_one.php) — тогда не будет лишних чтений/записей state.
     */
    function tg_notify(string $type, string $text, $chatId, string $key, ?array &$state = null): void
    {
        $c = cfg();

        // --- subtype routing for "errors" (separate rate-limit buckets for WARNING vs ERROR) ---
        // Backward compatible: by default both WARNING and ERROR use the same limits ("errors"),
        // but WARNING messages get their own state bucket so you can rate-limit them independently.
        // Optional config overrides (if you add them later in config.php):
        //   $CFG['notify']['types']['warnings'] (preferred)
        //   $CFG['notify']['types']['errors_warning']
        // If not present, limits fall back to "errors".
        $effectiveType = $type;
        if ($type === 'errors') {
            // Detect WARNING from typical formatted messages:
            //  - "Code: WARNING:5901"
            //  - "WARNING:5901"
            //  - "Type: Erken uyarı"
            if (preg_match('/\bCode\s*:\s*WARNING\b/i', $text)
                || preg_match('/\bWARNING\s*:\s*\d+\b/i', $text)
                || preg_match('/\bType\s*:\s*Erken\s*uyar\xC4\xB1\b/ui', $text)
            ) {
                $effectiveType = 'warnings';
            }
        
        // --- ERROR/WARNING CODE FILTER (added) ---
        // Attempt to extract numeric error/warning code from message and consult config filters.
        // Config expected in $CFG['notify']['filters'], e.g.:
        // 'filters' => ['errors'=>['mode'=>'deny','codes'=>[7300]], 'warnings'=>['mode'=>'deny','codes'=>[5901]]]
        $cfg = cfg(); // ensure config loaded
        $cfgNotify = $cfg['notify'] ?? ($cfg['telegram'] ?? []);
        $codeInfo = null;
        try {
            $codeInfo = tg_extract_code($text ?? '');
        } catch (Throwable $e) {
            $codeInfo = null;
        }
        if (tg_is_code_filtered($cfgNotify, $effectiveType ?? '', $codeInfo)) {
            // filtered by config - do not send this notification
            $codeStr = 'n/a';
            if (is_array($codeInfo) && isset($codeInfo['kind']) && isset($codeInfo['code'])) {
                $codeStr = (string)$codeInfo['kind'] . ':' . (string)$codeInfo['code'];
            }
            // keep logs short to avoid entity issues
            $short = $text ?? '';
            if (is_string($short) && strlen($short) > 160) $short = substr($short, 0, 160) . '...';
            log_info('tg', "FILTERED notify type={$effectiveType} key={$key} code={$codeStr} text=" . str_replace(PHP_EOL, ' ', (string)$short));
            return;
        }
        // --- end filter ---
}

        // если не указан chatId — fallback на общий
        if ($chatId === null) {
            $chatId = $c['telegram']['chat_id'] ?? null;
        }

        // токен / chatId
        $token = (string)($c['telegram']['bot_token'] ?? '');
        if ($token === '' || $token === 'PUT_TELEGRAM_BOT_TOKEN_HERE' || $chatId === null) {
            echo date('Y-m-d H:i:s') . " | TG token/chat_id not set<br>";
            return;
        }

        $notifyCfg = $c['notify'] ?? [];
        $def = $notifyCfg['default'] ?? [];

        // load limits for effectiveType; fallback to original $type for compatibility
        $typeCfg = ($notifyCfg['types'][$effectiveType] ?? null);
        if (!is_array($typeCfg) && $type === 'errors' && $effectiveType === 'warnings') {
            // try alternative name
            $typeCfg = ($notifyCfg['types']['errors_warning'] ?? null);
            if (!is_array($typeCfg)) {
                // fall back to "errors" limits
                $typeCfg = ($notifyCfg['types']['errors'] ?? null);
            }
        }

        $lim = is_array($typeCfg) ? array_merge($def, $typeCfg) : $def;

        $enabled = (bool)($lim['enabled'] ?? true);
        if (!$enabled) return;

        $cooldown = (int)($lim['cooldown_sec'] ?? 60);
        $maxPerHour = (int)($lim['max_per_hour'] ?? 0);
        $maxPerDay  = (int)($lim['max_per_day']  ?? 0);

        $needSave = false;
        if ($state === null) {
            $stateFile = state_path('notify');
            $state = state_load($stateFile, [
                'tg_notify' => [],
            ]);
            $needSave = true;
        }

        if (!isset($state['tg_notify']) || !is_array($state['tg_notify'])) { $state['tg_notify'] = []; }
        if (!isset($state['tg_notify'][$effectiveType]) || !is_array($state['tg_notify'][$effectiveType])) {
            $state['tg_notify'][$effectiveType] = [
                'keys' => [],
                'hour' => ['start' => 0, 'count' => 0],
                'day'  => ['start' => 0, 'count' => 0],
            ];
        }
        $bucket = &$state['tg_notify'][$effectiveType];
        if (!isset($bucket['keys']) || !is_array($bucket['keys'])) { $bucket['keys'] = []; }
        if (!isset($bucket['hour']) || !is_array($bucket['hour'])) { $bucket['hour'] = ['start' => 0, 'count' => 0]; }
        if (!isset($bucket['day']) || !is_array($bucket['day'])) { $bucket['day'] = ['start' => 0, 'count' => 0]; }

        $now = time();

        // --- hourly window ---
        $hStart = (int)($bucket['hour']['start'] ?? 0);
        $hCount = (int)($bucket['hour']['count'] ?? 0);
        if ($hStart <= 0 || ($now - $hStart) >= 3600) {
            $hStart = $now;
            $hCount = 0;
        }

        // --- daily window ---
        $dStart = (int)($bucket['day']['start'] ?? 0);
        $dCount = (int)($bucket['day']['count'] ?? 0);
        if ($dStart <= 0 || ($now - $dStart) >= 86400) {
            $dStart = $now;
            $dCount = 0;
        }

 // max per hour/day
        if ($maxPerHour > 0 && $hCount >= $maxPerHour) {
            if ($needSave) {
                $bucket['hour'] = ['start' => $hStart, 'count' => $hCount];
                $bucket['day']  = ['start' => $dStart, 'count' => $dCount];
                state_save(state_path('notify'), $state);
            }

            if (function_exists('log_info')) {
                log_info(
                    'tg_notify',
                    "SKIP type={$effectiveType} key={$key} reason=max_per_hour count={$hCount} limit={$maxPerHour}"
                );
            }

            return;
        }

        if ($maxPerDay > 0 && $dCount >= $maxPerDay) {
            if ($needSave) {
                $bucket['hour'] = ['start' => $hStart, 'count' => $hCount];
                $bucket['day']  = ['start' => $dStart, 'count' => $dCount];
                state_save(state_path('notify'), $state);
            }

            if (function_exists('log_info')) {
                log_info(
                    'tg_notify',
                    "SKIP type={$effectiveType} key={$key} reason=max_per_day count={$dCount} limit={$maxPerDay}"
                );
            }

            return;
        }

        // cooldown by key
        $lastTs = (int)($bucket['keys'][$key] ?? 0);
        if ($lastTs > 0 && $cooldown > 0 && ($now - $lastTs) < $cooldown) {
            $leftSec = $cooldown - ($now - $lastTs);
            if ($leftSec < 0) $leftSec = 0;

            if ($needSave) {
                $bucket['hour'] = ['start' => $hStart, 'count' => $hCount];
                $bucket['day']  = ['start' => $dStart, 'count' => $dCount];
                state_save(state_path('notify'), $state);
            }

            if (function_exists('log_info')) {
                log_info(
                    'tg_notify',
                    "SKIP type={$effectiveType} key={$key} reason=cooldown left_sec={$leftSec} cooldown_sec={$cooldown}"
                );
            }

            return;
        }

        // pass -> send
        $res = sendmessage($text, $chatId);

        // продублируем в логах tg_notify (удобно фильтровать по типу)
        $okStr = ($res['ok'] ?? false) ? '1' : '0';
        $http  = (int)($res['http_code'] ?? 0);
        $et = ($effectiveType !== $type) ? "{$type}/{$effectiveType}" : $type;
        log_info('tg_notify', "type={$et} chat_id={$chatId} key={$key} ok={$okStr} http={$http}");
        if (is_debug()) {
            $r = (string)($res['response'] ?? '');
            if ($r !== '') {
                // не раздуваем файл: максимум 800 символов
                $rr = mb_substr($r, 0, 800, 'UTF-8');
                if (mb_strlen($r, 'UTF-8') > 800) $rr .= '…';
                log_debug('tg_notify', 'response=' . $rr);
            }
        }

        // update counters
        $bucket['keys'][$key] = $now;
        $bucket['hour'] = ['start' => $hStart, 'count' => $hCount + 1];
        $bucket['day']  = ['start' => $dStart, 'count' => $dCount + 1];

        if ($needSave) {
            state_save(state_path('notify'), $state);
        }
    }
}

// -------------------- TELEGRAM --------------------
if (!function_exists('sendmessage')) {
    /**
     * Отправка сообщения в Telegram.
     * Возвращает результат (ok/http_code/response/error).
     */
    function sendmessage(string $text, $chatIdOverride = null, string $parseMode = 'HTML'): array
    {
        $c = cfg();
        $token = (string)($c['telegram']['bot_token'] ?? '');
        $chatId = ($chatIdOverride !== null) ? $chatIdOverride : ($c['telegram']['chat_id'] ?? null);

        
        // FIX: Telegram parse_mode=HTML ломается на сравнениях типа "<=" (воспринимает как HTML-тег).
        // Чтобы не получать ошибку: "can't parse entities: Unsupported start tag "=" ...",
        // заменяем сравнения на Unicode-символы.
        if (strtoupper($parseMode) === 'HTML') {
            // <=  и  >=  (включая варианты с пробелами)
            $text = preg_replace('/<\s*=/u', '≤', $text);
            $text = preg_replace('/>\s*=/u', '≥', $text);
        }

if ($token === '' || $token === 'PUT_TELEGRAM_BOT_TOKEN_HERE' || $chatId === null) {
            echo date('Y-m-d H:i:s') . " | TG token/chat_id not set<br>";
            return ['ok' => false, 'http_code' => 0, 'response' => null, 'error' => 'TG token/chat_id not set', 'chat_id' => $chatId];
        }

        $url = "https://api.telegram.org/bot{$token}/sendMessage";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
            CURLOPT_POSTFIELDS => json_encode([
                "chat_id" => $chatId,
                "text" => $text,
                "parse_mode" => $parseMode,
                "disable_web_page_preview" => true
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_CAINFO => (string)($c['telegram']['ca_cert'] ?? ''),
        ]);

        $response = curl_exec($ch);
        $curlErr = ($response === false) ? curl_error($ch) : '';
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $ok = ($response !== false && $http >= 200 && $http < 300);

        // логируем детали отправки
        $preview = '';
        $trim = trim((string)$text);
        if ($trim !== '') {
            $preview = mb_substr($trim, 0, 120, 'UTF-8');
            if (mb_strlen($trim, 'UTF-8') > 120) $preview .= '…';
        }

        $cat = 'tg';
        if ($ok) {
            log_info($cat, "SEND ok chat_id={$chatId} http={$http} msg=\"{$preview}\"");
            if (is_debug()) {
                log_debug($cat, 'RESPONSE: ' . (string)$response);
            }
        } else {
            $errMsg = $curlErr !== '' ? $curlErr : 'http=' . $http;
            log_warn($cat, "SEND fail chat_id={$chatId} {$errMsg} msg=\"{$preview}\"");
            if (is_debug()) {
                log_debug($cat, 'RESPONSE: ' . (string)$response);
            }
        }

        return ['ok' => $ok, 'http_code' => $http, 'response' => $response, 'error' => ($curlErr !== '' ? $curlErr : null), 'chat_id' => $chatId];
    }
}

if (!function_exists('sendmessageAll')) {
    function sendmessageAll(string $text): void
    {
        sendmessage($text);
    }
}

// -------------------- LOCATIONS --------------------
if (!function_exists('getLocation')) {
    function getLocation(array $locations, int $machineNumber): ?string
    {
        return $locations[$machineNumber]['location'] ?? null;
    }
}

if (!function_exists('extractMachineNumberFromHref')) {
    function extractMachineNumberFromHref(string $href): int
    {
        if (preg_match('~[?&]vmc_no=([0-9]{3,10})~', $href, $m)) {
            return (int)$m[1];
        }
        $x = str_replace('https://saas-hk.jetinno.com/device_info?vmc_no=', '', $href);
        $x = preg_replace('~&.*$~', '', (string)$x);
        return (int)$x;
    }
}

// -------------------- STATE (ANTI-SPAM, PERSISTENCE, PRUNE) --------------------
if (!function_exists('state_get_ts')) {
    function state_get_ts(array $state, array $path, int $default = 0): int
    {
        $cur = $state;
        foreach ($path as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) return $default;
            $cur = $cur[$k];
        }
        if (is_array($cur)) return $default;
        return (int)$cur;
    }
}

if (!function_exists('state_set_ts')) {
    function state_set_ts(array &$state, array $path, int $ts): void
    {
        $ref =& $state;
        $n = count($path);
        for ($i = 0; $i < $n; $i++) {
            $k = $path[$i];
            if ($i === $n - 1) {
                $ref[$k] = (int)$ts;
                return;
            }
            if (!isset($ref[$k]) || !is_array($ref[$k])) {
                $ref[$k] = [];
            }
            $ref =& $ref[$k];
        }
    }
}

if (!function_exists('state_can_send')) {
    function state_can_send(array &$state, array $path, int $cooldownSeconds, bool $touch = false): bool
    {
        $last = state_get_ts($state, $path, 0);
        $ok = ($last < 1) || ((time() - $last) > $cooldownSeconds);
        if ($ok && $touch) state_set_ts($state, $path, time());
        return $ok;
    }
}

if (!function_exists('state_load')) {
    function state_load(string $file, array $defaultState): array
    {
        if (!is_file($file)) return $defaultState;

        $json = @file_get_contents($file);
        if (!is_string($json) || $json === '') return $defaultState;

        $data = json_decode($json, true);
        if (!is_array($data)) return $defaultState;

        return array_replace_recursive($defaultState, $data);
    }
}

if (!function_exists('state_save')) {
    function state_save(string $file, array $state): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);

        $tmp = $file . '.tmp';
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $fp = @fopen($tmp, 'wb');
        if ($fp === false) return;

        if (flock($fp, LOCK_EX)) {
            fwrite($fp, $json);
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        @rename($tmp, $file);
    }
}

if (!function_exists('state_prune')) {
    function state_prune(array &$state, int $maxAgeSeconds = 2592000): void
    {
        $cutoff = time() - $maxAgeSeconds;

        $pruneRecursive = function (&$node) use (&$pruneRecursive, $cutoff) {
            if (!is_array($node)) return;

            foreach ($node as $k => &$v) {
                if (is_array($v)) {
                    $pruneRecursive($v);
                    if (is_array($v) && count($v) === 0) unset($node[$k]);
                } else {
                    if (is_int($v) || ctype_digit((string)$v)) {
                        $ts = (int)$v;
                        if ($ts > 0 && $ts < $cutoff) unset($node[$k]);
                    }
                }
            }
            unset($v);
        };

        foreach (['machine', 'reboot', 'supplies_alert'] as $section) {
            if (isset($state[$section]) && is_array($state[$section])) {
                $pruneRecursive($state[$section]);
            }
        }
    }
}

// -------------------- PROCESS MACHINE --------------------
if (!function_exists('processMachine')) {
    function processMachine(
        object $anchor,
        int|string $anchorNumber,
        string $label,
        array $locations,
        array &$state,
        string $group,
        string $typeKey,
        int $antispamSeconds = 300,
        int $sendAfterHour = 9,
        string $timezone = 'Europe/Istanbul',
        bool $countAntispamBefore9 = false,
        string $accountKey = 'default',
        $chatId = null,
        bool $tgEnabled = true
    ): void
    {
        if (!$tgEnabled) {
            return;
        }
        if (!$anchor->is_exist_by_number($anchorNumber)) {
            xhe_log('machine', 'no ' . $label);
            return;
        }

        $href = $anchor->get_href_by_number($anchorNumber);
        if (!is_string($href) || $href === '' || !str_contains($href, 'vmc_no=')) {
            xhe_log('machine', 'no ' . $label);
            return;
        }

        $vmc = extractMachineNumberFromHref($href);

        $tz = new DateTimeZone($timezone);
        $now = new DateTime('now', $tz);
        $dateStr = $now->format('Y-m-d H:i:s');

        $location = getLocation($locations, $vmc) ?? 'Unknown location';
        xhe_log('machine', $label . ' - ' . $vmc . ' - ' . $location);

        $path = [$group, $accountKey, $vmc, $typeKey];
        if (!state_can_send($state, $path, $antispamSeconds, false)) return;

        $currentHour = (int)$now->format('G');
        $canSendByTime = ($currentHour >= $sendAfterHour);

        if ($canSendByTime) {
            sendmessage($dateStr . ' ' . $vmc . ' - ' . $label . ' - ' . $location, $chatId);
            state_set_ts($state, $path, time());
        } else {
            if ($countAntispamBefore9) state_set_ts($state, $path, time());
        }
    }
}

function request_machine_reboot(string $vmcNo): bool {
    $vmcNo = preg_replace('~\D+~', '', $vmcNo);
    if ($vmcNo === '') return false;
    return enqueue_machine_task('reboot', $vmcNo);
}

function enqueue_machine_task(string $type, string $vmcNo): bool {
    $base = function_exists('runtime_base_dir') ? rtrim(runtime_base_dir(), "\\/") : 'c:\\jetinno_runtime';
    $stateDir = $base . DIRECTORY_SEPARATOR . 'state';
    if (!is_dir($stateDir)) @mkdir($stateDir, 0777, true);

    $path = $stateDir . DIRECTORY_SEPARATOR . "queue_{$type}.json";

    $q = [];
    if (is_file($path)) {
        $j = json_decode((string)@file_get_contents($path), true);
        if (is_array($j)) $q = $j;
    }

    // антидубль на 10 минут
    $now = time();
    foreach ($q as $item) {
        if (!is_array($item)) continue;
        if (($item['vmc'] ?? '') === $vmcNo && (int)($item['ts'] ?? 0) > ($now - 600)) {
            return true;
        }
    }

    $q[] = [
        'vmc' => $vmcNo,
        'ts'  => $now,
        'id'  => substr(md5($type.'|'.$vmcNo.'|'.$now), 0, 10),
        'source' => 'telegram_cmd',
    ];

    return (bool)@file_put_contents($path, json_encode($q, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function request_machine_sync(string $vmcNo): bool {
    $vmcNo = preg_replace('~\D+~', '', $vmcNo);
    if ($vmcNo === '') return false;
    return enqueue_machine_task('sync', $vmcNo); // enqueue_machine_task у вас уже есть
}

function make_drink_universal($vmc, $drink_id)
{
    global $browser, $image, $span, $btn, $input;

    $vmc = trim((string)$vmc);
    $drink_id = trim((string)$drink_id);

    if (!preg_match('/^\d{4,10}$/', $vmc)) {
        return ['status' => 'vmc_invalid'];
    }

    try {

        $browser->close_all_tabs();
        $browser->navigate("https://saas-hk.jetinno.com/device_info?vmc_no={$vmc}&admin=");
        $browser->wait(3);

        $image->click_by_src("https://saas-hk.jetinno.com/home/images/control.png", false);
        $browser->wait(3);

        $image->click_by_src("https://saas-hk.jetinno.com/home/images/products.png", false);
        $browser->wait(3);

        $span->click_by_number(398);
        $browser->wait(3);

     //   $span_count = $span->get_count("-1");

        for ($i = 400; $i < 450; $i++) {

            $span_text = trim($span->get_inner_text_by_number($i));

            if (strpos($span_text, $drink_id) === 0) {

                $span->click_by_number($i);
                $browser->wait(4);

                $btn->click_by_number(42);
                $browser->wait(4);

                $input->set_value_by_number(40, $vmc);
                $browser->wait(4);

                $btn->click_by_number(55);
                $browser->wait(4);

                return ['status' => 'success'];
            }
        }

        return ['status' => 'drink_not_found'];

    } catch (Throwable $e) {
        return ['status' => 'ui_error'];
    }
}
function get_location(int $userId, string $vmc): string {

    $path = runtime_base_dir() . "\\state\\jetinno_locations_{$userId}.json";
    if (!file_exists($path)) return '';

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return '';

    $j = json_decode($raw, true);
    if (!is_array($j)) return '';

    $vmc = (string)$vmc;

    // 1) ваш текущий формат: {"map": {"81941":"Depo"}}
    if (isset($j['map']) && is_array($j['map']) && isset($j['map'][$vmc])) {
        return trim((string)$j['map'][$vmc]);
    }

    // 2) альтернативный формат: {"by_vmc": {"81941":"Depo"}}
    if (isset($j['by_vmc']) && is_array($j['by_vmc']) && isset($j['by_vmc'][$vmc])) {
        return trim((string)$j['by_vmc'][$vmc]);
    }

    // 3) плоский формат: {"81941":"Depo"}
    if (isset($j[$vmc]) && is_string($j[$vmc])) {
        return trim((string)$j[$vmc]);
    }

    // 4) иногда ключи могут быть int в массиве (редко, но бывает) — пробуем найти по строковому сравнению
    foreach (['map', 'by_vmc'] as $k) {
        if (!isset($j[$k]) || !is_array($j[$k])) continue;
        foreach ($j[$k] as $key => $val) {
            if ((string)$key === $vmc && is_string($val) && trim($val) !== '') {
                return trim($val);
            }
        }
    }

    return '';
}
function download_rinsing_csv(string $userId): ?string
{
    // ВАЖНО: cookieStr должен быть после LOGIN OK
    $cookie = $GLOBALS['cookieStr'] ?? '';
    if (!$cookie) {
        log_msg('[rinsing][ERROR] cookieStr is empty (run after LOGIN OK)');
        return null;
    }

    $url = "https://saas-hk.jetinno.com/rinsing?"
         . "user_id=" . urlencode($userId)
         . "&daterange=&rinsing_code=&type=&is_ok=&vmc_no="
         . "&perPage=25&order_by%5Bkey%5D=&order_by%5Bvalue%5D="
         . "&export=1";

    $outDir = runtime_base_dir() . "/export";
    if (!is_dir($outDir)) @mkdir($outDir, 0777, true);

    $ts = date('Ymd_His');
    $outPath = $outDir . "/rinsing_{$userId}_{$ts}.csv";

    // простой HTTP GET с cookie
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' =>
                "Cookie: {$cookie}\r\n" .
                "User-Agent: Mozilla/5.0\r\n",
            'timeout' => 60,
        ]
    ];

    $ctx = stream_context_create($opts);
    $csv = @file_get_contents($url, false, $ctx);

    // логируем HTTP код если получится
    $httpCode = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) { $httpCode = (int)$m[1]; break; }
        }
    }

    if ($csv === false || strlen($csv) < 10) {
        log_msg("[rinsing][ERROR] DOWNLOAD failed http={$httpCode} url={$url}");
        return null;
    }

    file_put_contents($outPath, $csv);
    log_msg("[rinsing][INFO] SAVED CSV={$outPath} http={$httpCode} bytes=" . strlen($csv));

    return $outPath;
}