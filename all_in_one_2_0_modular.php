<?php

require_once __DIR__ . '/all_in_one_2_0_lib.php';


/**
 * all_in_one_2_0.php  (all_in_one 2.0 - errors + supply + failed orders)
 *
 * Источники:
 * 1) /error  -> CSV export=1  (ERROR + WARNING)
 * 2) /supply -> CSV export=1 (prefer) fallback HTML export=0 (если CSV не пригоден)
 * 3) /order  -> CSV export=1 + isOK=0 (напиток не сделан)
 *
 * Уведомления Telegram:
 * - errors  -> telegram_notify['errors']
 * - supply  -> telegram_notify['supply']
 * - orders  -> telegram_notify['orders'] (для isOK=0 тоже)
 *
 * Антиспам:
 * - errors: dedup key user|vmc|code by last timestamp
 * - supply: per account|device|key cooldown (default 3600s) + tg_notify key
 * - orders fail: per account|orderNo cooldown (default 3600s) + tg_notify key
 *
 * ВАЖНО:
 * - config.php НЕ трогаем.
 */

// ---- XHE init loader (robust) ----
$__initCandidates = [];

// Try relative to this script (old layout: My Scripts/../Templates)
$__initCandidates[] = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR . 'init.php';

// Try relative to current working directory (sometimes XHE sets CWD)
$__cwd = getcwd();
if (is_string($__cwd) && $__cwd !== '') {
    $__initCandidates[] = $__cwd . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR . 'init.php';
    $__initCandidates[] = $__cwd . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR . 'init.php';
}

// Climb up from __DIR__ and CWD to find Templates/init.php
$__roots = [__DIR__];
if (is_string($__cwd) && $__cwd !== '') $__roots[] = $__cwd;

foreach ($__roots as $__root) {
    $__p = $__root;
    for ($__k = 0; $__k < 7; $__k++) {
        $__cand = $__p . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR . 'init.php';
        $__initCandidates[] = $__cand;
        $__parent = dirname($__p);
        if ($__parent === $__p) break;
        $__p = $__parent;
    }
}

// Common install locations (best-effort)
$__initCandidates[] = 'D:\\XWeb\\Human Emulator Studio DEMO 7.0.76\\Templates\\init.php';
$__initCandidates[] = 'D:\\XWeb\\Human Emulator Studio\\Templates\\init.php';

$__initPath = null;
foreach ($__initCandidates as $__c) {
    $__c = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $__c);
    if (is_file($__c)) { $__initPath = $__c; break; }
}

if (!$__initPath) {
    die("XHE init.php not found. Checked:\n- " . implode("\n- ", array_unique($__initCandidates)) . "\n");
}
require $__initPath;
// ---- end XHE init loader ----

// load config
// load config (app + secrets)
$CFG = require __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
date_default_timezone_set($CFG['timezone'] ?? 'Europe/Istanbul');



// -------------------- Module framework --------------------
/**
 * Return module enabled flag with default.
 */
function cfg_module_enabled(array $CFG, string $key, bool $default): bool {
    $mods = $CFG['modules'] ?? null;
    if (!is_array($mods)) return $default;
    $v = $mods[$key] ?? $default;
    return (bool)$v;
}

/**
 * Run a module file if enabled, with safe logging.
 * $label is used in logs.
 */
function run_module_file(bool $enabled, string $label, string $filePath): void {
    if (!$enabled) {
        if (function_exists('xhe_log')) xhe_log('modules', "SKIP {$label}", "DEBUG");
        return;
    }
    if (!is_file($filePath)) {
        if (function_exists('xhe_log')) xhe_log('modules', "MISSING {$label} file={$filePath}", "ERROR");
        return;
    }

    // Bridge context into this function-scope include.
    // Many legacy modules expect variables like $CFG, $acc, $userId, $accName, $accountKey, $state, $notifyState
    // to be available in the current scope (they used to be included in the main script scope).
    $CFG        = $GLOBALS['CFG']        ?? null;
    $acc        = $GLOBALS['acc']        ?? null;
    $userId     = $GLOBALS['userId']     ?? null;
    $accName    = $GLOBALS['accName']    ?? null;
    $accountKey = $GLOBALS['accountKey'] ?? null;

    $cookieStr  = $GLOBALS['cookieStr']  ?? null;
    $stamp     = $GLOBALS['stamp']     ?? null;
    $outDir    = $GLOBALS['outDir']    ?? null;
    $chatId    = $GLOBALS['chatId']    ?? null;
    $locations = $GLOBALS['locations'] ?? null;
    // State arrays are commonly mutated by modules; keep them by-reference if present
    if (array_key_exists('state', $GLOBALS)) {
        $state = &$GLOBALS['state'];
    }
    if (array_key_exists('notifyState', $GLOBALS)) {
        $notifyState = &$GLOBALS['notifyState'];
    }

    if (function_exists('xhe_log')) xhe_log('modules', "RUN {$label}", "DEBUG");
    require $filePath;
}
// -------------------- End module framework --------------------

// core helpers/state/telegram
              require_once __DIR__ . '/modules/sales_summary.php';
require_once __DIR__ . '/modules/sales_process.php';
$functionsPath = __DIR__ . DIRECTORY_SEPARATOR . "functions.php";
if (file_exists($functionsPath)) {
    require_once $functionsPath;
}

// supply HTML parser (fallback)
$supplyParsingPath = __DIR__ . DIRECTORY_SEPARATOR . "supply_parsing.php";
if (file_exists($supplyParsingPath)) {
    require_once $supplyParsingPath;
}


// -------------------- CONFIG (flex) --------------------
$loginValue  = $CFG['auth']['login']    ?? ($CFG['jetinno']['login']    ?? ($CFG['login']    ?? ''));
$passValue   = $CFG['auth']['password'] ?? ($CFG['jetinno']['password'] ?? ($CFG['password'] ?? ''));
$maxAttempts = (int)($CFG['auth']['max_attempts'] ?? 10);

$antiKey      = $CFG['anticaptcha']['api_key']        ?? ($CFG['antiCaptcha']['api_key'] ?? '');
$captchaPath  = $CFG['anticaptcha']['captcha_path']   ?? (runtime_base_dir() . "\\captcha.png");
$captchaHost  = $CFG['anticaptcha']['host']           ?? "https://anti-captcha.com/";

// allow using global $anticapcha
if (isset($anticapcha) && is_object($anticapcha)) {
    $anticapcha->api_key = $antiKey;
}

// Jetinno accounts
$accounts = $CFG['jetinno']['accounts'] ?? [];
if (is_array($accounts) && isset($accounts['user_id'])) $accounts = [$accounts];
if (!is_array($accounts) || count($accounts) === 0) {
    $accounts = [[
        'name' => 'DEFAULT',
        'user_id' => (int)($CFG['jetinno']['user_id'] ?? 629),
        'telegram_chat_id' => ($CFG['telegram']['chat_id'] ?? null),
        'telegram_notify' => ['errors' => true, 'supply' => true, 'orders' => true],
    ]];
}

// -------------------- Paths --------------------
$baseDir = runtime_base_dir();
$outDir  = $baseDir . "\\export";
@mkdir($outDir, 0777, true);

// -------------------- Helpers --------------------


// ---------- CSV helpers ----------


// ---------- ERRORS ----------

// Turkish column names from your error CSV


// ---------- SUPPLY ----------


// For supply recommendations (pack/bunker logic). Keep separate from alert keys to avoid breaking thresholds.


// ---------- FAILED ORDERS (isOK=0) ----------


// ---------- SALES (via orders CSV isOK=1) ----------


// -------------------- Syrup stale detection --------------------


// -------------------- VMC LOCATIONS (from Errors/Warnings export) --------------------


/**
 * Update VMC locations map from errors/warnings CSV (Cihaz numarası + Cihaz adresi).
 * Throttled by $minIntervalSeconds based on state['updated_at'].
 */

while(true){
// -------------------- Start --------------------
try {
    if (is_object($browser)) {
        $browser->close_all_tabs();
        $browser->navigate("https://saas-hk.jetinno.com/index");
        $browser->wait(5);
    }

    $okLogin = loginIfNeeded($input, $btn, $browser, $image, $anticapcha, $loginValue, $passValue, $captchaPath, $captchaHost, $maxAttempts);
    if (!$okLogin) throw new RuntimeException("Login failed");

    $cookieStr = xhe_get_cookie_string($browser ?? null, $webpage ?? null);
    xhe_log('error_scan', "COOKIE_LEN=" . strlen($cookieStr), "DEBUG");
// $res = sales_process_today($acc, (int)$userId, (string)$cookieStr, (string)$accountKey, $state, $notifyState); // moved inside foreach
//require __DIR__ . '/test_manual_sync_81941.php';
//exit;
// ---- MODULES ----
require_once __DIR__ . '/modules/state.php';
require_once __DIR__ . '/modules/sales_summary.php';
require_once __DIR__ . '/modules/sales_process.php';
require_once __DIR__ . '/modules/manual_reboot_compat.php';
$accCount = 0;

foreach ($accounts as $acc) {
    $userId = (int)($acc['user_id'] ?? 0);

    // PRELOAD locations from supply-state (for errors/syrup before supply runs)
    $locFile = runtime_base_dir() . "\\state\\jetinno_locations_{$userId}.json";
    if (is_file($locFile)) {
        $tmp = json_decode((string)@file_get_contents($locFile), true);
        if (is_array($tmp) && is_array($tmp['map'] ?? null)) {
            if (!isset($locations) || !is_array($locations)) $locations = [];
            $locations = $tmp['map'] + $locations;
        }
    }

    $accName = (string)($acc['name'] ?? "user{$userId}");
    if ($userId <= 0) continue;
    $accCount++;
    $accountKey = $accName;


    // DAILY SALES SUMMARY
    if (!isset($notifyState) || !is_array($notifyState)) $notifyState = [];
//    if (function_exists('sales_process_today')) {
//        sales_process_today($acc, (int)$userId, (string)$cookieStr, (string)$accountKey, $state, $notifyState);
//    }

    // Expose per-account context for module includes (included inside a function scope)
    $GLOBALS['CFG']        = $CFG;
    $GLOBALS['acc']        = $acc;
    $GLOBALS['userId']     = $userId;
    $GLOBALS['accName']    = $accName;
    $GLOBALS['accountKey'] = $accountKey;
    $GLOBALS['cookieStr']  = $cookieStr;
    // runtime/export context for modules
    if (isset($stamp))     { $GLOBALS['stamp'] = $stamp; }
    if (isset($outDir))    { $GLOBALS['outDir'] = $outDir; }
    if (isset($chatId))    { $GLOBALS['chatId'] = $chatId; }
    if (isset($locations)) { $GLOBALS['locations'] = $locations; }
    // Always load per-account prelude (sets $state, $notifyState, chat ids, etc.)
    require __DIR__ . '/modules/account_prelude.php';


    // account_prelude typically sets $state and $notifyState in the current scope
    if (isset($state))       { $GLOBALS['state'] = &$state; }
    if (isset($notifyState)) { $GLOBALS['notifyState'] = &$notifyState; }
    // Common per-account runtime vars expected by legacy modules
    if (!isset($stamp) || $stamp === '' || $stamp === null) {
        $stamp = date('Ymd_His');
    }
    if (!isset($outDir) || $outDir === '' || $outDir === null) {
        $outDir = (function_exists('runtime_base_dir') ? runtime_base_dir() : 'C:\\jetinno_runtime') . DIRECTORY_SEPARATOR . 'export';
    }
    if (!is_dir($outDir)) {
        @mkdir($outDir, 0777, true);
    }

    // Chat id for this account (used by modules)
    if (!isset($chatId) || $chatId === null || $chatId === '') {
        $chatId = $acc['telegram_chat_id'] ?? ($CFG['telegram']['chat_id'] ?? null);
    }

    // Locations map (used by orders_success)
    if (!isset($locations) || !is_array($locations)) {
        $locFile = (function_exists('runtime_base_dir') ? runtime_base_dir() : 'C:\\jetinno_runtime') . DIRECTORY_SEPARATOR . 'state' . DIRECTORY_SEPARATOR . 'jetinno_locations_' . $userId . '.json';
        $locations = [];
        if (is_file($locFile)) {
            $raw = @file_get_contents($locFile);
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $locations = $decoded;
        }
    }

    // Expose to modules (included inside function scope)
    $GLOBALS['stamp'] = $stamp;
    $GLOBALS['outDir'] = $outDir;
    $GLOBALS['chatId'] = $chatId;
    $GLOBALS['locations'] = $locations;

    // Main modules (controlled by $CFG['modules'])
    run_module_file(cfg_module_enabled($CFG, 'errors', true),        'errors',        __DIR__ . '/modules/errors.php');
    run_module_file(cfg_module_enabled($CFG, 'reboot', true),        'boiler_reboot', __DIR__ . '/modules/boiler_reboot.php');
    run_module_file(cfg_module_enabled($CFG, 'supply', true),        'supply',        __DIR__ . '/modules/supply.php');
    run_module_file(cfg_module_enabled($CFG, 'sync', false),         'machine_sync',  __DIR__ . '/modules/machine_sync.php');
    run_module_file(cfg_module_enabled($CFG, 'orders_failed', true), 'orders_failed', __DIR__ . '/modules/orders.php');
    run_module_file(cfg_module_enabled($CFG, 'orders_success', true),'orders_success',__DIR__ . '/modules/orders_success.php');
    run_module_file(cfg_module_enabled($CFG, 'sales_syrups', true),  'sales_syrups',  __DIR__ . '/modules/sales_syrups.php');


    // Control/maintenance modules (usually always on, but configurable)
    run_module_file(cfg_module_enabled($CFG, 'telegram_commands', true), 'telegram_commands', __DIR__ . '/modules/telegram_commands.php');
    run_module_file(cfg_module_enabled($CFG, 'manual_reboot', true),    'manual_reboot',    __DIR__ . '/modules/manual_reboot.php');
    run_module_file(cfg_module_enabled($CFG, 'manual_sync', true),      'manual_sync',      __DIR__ . '/modules/manual_sync.php');
                    require_once __DIR__ . '/modules/manual_reboot_compat.php';
require_once __DIR__ . '/modules/manual_sync_compat.php';
}
// ---- END MODULES ----

if (function_exists('state_save')) state_save($mainStateFile, $state);

    xhe_log('error_scan', "DONE accounts={$accCount}", "INFO");
    echo "DONE\n";

} catch (Throwable $e) {
    xhe_log('error_scan', "EXCEPTION: " . $e->getMessage(), "ERROR");
        if (isset($ADMIN_NOTIFY_ENABLED) && $ADMIN_NOTIFY_ENABLED && isset($state) && is_array($state)) {
            $k = 'exception|' . md5($e->getMessage());
            admin_critical('exception', $e->getMessage(), $k, $ADMIN_CHAT_ID, $state, $notifyState);
        }
    }
    if (function_exists('tg_commands_poll')) { tg_commands_poll($CFG); }
    if (!isset($notifyState) || !is_array($notifyState)) $notifyState = [];
// if (function_exists('manual_reboot_process_queue')) manual_reboot_process_queue($acc, $accountKey, (int)$userId, (string)$cookieStr, $state, $locations, $notifyState); // old signature
    // MANUAL REBOOT QUEUE
    if (function_exists('manual_reboot_process_queue')) {
        if (function_exists('manual_reboot_process_queue')) manual_reboot_process_queue($acc, (int)$userId, (string)$cookieStr, (string)$accountKey, $state, $notifyState);
    }
$pollSec  = 5;   // как часто опрашивать Telegram
$totalSec = 10;  // общая пауза между проходами AIO

$steps = (int)ceil($totalSec / $pollSec);
for ($k = 0; $k < $steps; $k++) {

    if (function_exists('tg_commands_poll')) {
        tg_commands_poll($CFG);   // <-- частый опрос команд
    }

   // sleep($pollSec);
}
//sleep(60);
    if (!isset($notifyState) || !is_array($notifyState)) $notifyState = [];
if (function_exists('manual_sync_process_queue')) manual_sync_process_queue($acc, $accountKey, (int)$userId, (string)$cookieStr, $state, $locations, $notifyState);
}
if (isset($app)) $app->quit();

if (!function_exists('manual_sync_process_queue')) {
    if (function_exists('xhe_log')) {
        xhe_log('manual_sync', 'manual_sync_process_queue() not found - skipped', 'WARNING');
    }
}

if (!function_exists('manual_reboot_process_queue')) {
    if (function_exists('xhe_log')) {
        xhe_log('manual_reboot', 'manual_reboot_process_queue() not found - skipped', 'WARNING');
    }
}
