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

require("../Templates/init.php");

// load config
$CFG = require __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
date_default_timezone_set($CFG['timezone'] ?? 'Europe/Istanbul');

// core helpers/state/telegram
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

//require __DIR__ . '/test_manual_sync_81941.php';
//exit;
// ---- MODULES ----
require_once __DIR__ . '/modules/state.php';

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
    require __DIR__ . '/modules/account_prelude.php';
    require __DIR__ . '/modules/errors.php';
    require __DIR__ . '/modules/boiler_reboot.php';
    require __DIR__ . '/modules/supply.php';
       require __DIR__ . '/modules/machine_sync.php';
    require __DIR__ . '/modules/orders.php';
    require __DIR__ . '/modules/orders_success.php';
    require __DIR__ . '/modules/sales_syrups.php';
       require_once __DIR__ . '/modules/manual_reboot.php';
          require_once __DIR__ . '/modules/manual_sync.php';
       require_once __DIR__ . '/modules/telegram_commands.php';
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
    tg_commands_poll($CFG);
    if (!isset($notifyState) || !is_array($notifyState)) $notifyState = [];
    manual_reboot_process_queue($acc, $accountKey, (int)$userId, (string)$cookieStr, $state, $locations, $notifyState);
    sleep(60);
    if (!isset($notifyState) || !is_array($notifyState)) $notifyState = [];
manual_sync_process_queue($acc, $accountKey, (int)$userId, (string)$cookieStr, $state, $locations, $notifyState);
}
if (isset($app)) $app->quit();