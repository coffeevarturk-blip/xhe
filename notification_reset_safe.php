<?php
/**
 * notification_reset_safe.php
 *
 * SAFE RESET: сбрасывает только антиспам/дедуп для уведомлений,
 * НЕ трогает config.php и НЕ трогает syrup_last_sale_*.json.
 *
 * Сбрасывает:
 *  - state\jetinno_notify_state.json : tg_notify (hour/day/keys)
 *  - state\jetinno_state.json :
 *      errors_notify, errors_notify_ser, supplies_alert, sales_notify, syrup_notify
 *      orders_fail_notify: удаляет только 2 записи (если вдруг накопились)
 *
 * Запуск: как обычный PHP-скрипт в XHE.
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

$CFG = @require __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
if (is_array($CFG) && !empty($CFG['timezone'])) {
    @date_default_timezone_set($CFG['timezone']);
}

// Подключим functions.php если есть (runtime_base_dir/state_load/state_save/xhe_log и т.п.)
$functionsPath = __DIR__ . DIRECTORY_SEPARATOR . "functions.php";
if (file_exists($functionsPath)) {
    require_once $functionsPath;
}

// fallback logger
if (!function_exists('xhe_log')) {
    function xhe_log($section, $message, $level="INFO") {
        echo date("Y-m-d H:i:s") . " | [$section][$level] $message\n";
    }
}

// fallback runtime dir
if (!function_exists('runtime_base_dir')) {
    function runtime_base_dir(): string { return "C:\\jetinno_runtime"; }
}

function json_load_file(string $path) {
    if (!file_exists($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return null;
    $j = @json_decode($raw, true);
    return is_array($j) ? $j : null;
}

function json_save_file(string $path, array $data): bool {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return (bool)@file_put_contents($path, $json);
}

function reset_orders_only_two(array &$state): array {
    $removed = [];
    if (!isset($state['orders_fail_notify']) || !is_array($state['orders_fail_notify'])) {
        $state['orders_fail_notify'] = [];
        return $removed;
    }

    $count = 0;
    foreach ($state['orders_fail_notify'] as $accKey => $orders) {
        if (!is_array($orders)) continue;

        foreach ($orders as $orderNo => $ts) {
            unset($state['orders_fail_notify'][$accKey][$orderNo]);
            $removed[] = (string)$accKey . '|' . (string)$orderNo;
            $count++;

            if (empty($state['orders_fail_notify'][$accKey])) {
                unset($state['orders_fail_notify'][$accKey]);
            }

            if ($count >= 2) return $removed;
        }
    }
    return $removed;
}

try {
    $base = runtime_base_dir();
    $stateDir = $base . "\\state";

    if (!is_dir($stateDir)) {
        xhe_log('reset', "State dir not found: {$stateDir}", 'ERROR');
        die();
    }

    xhe_log('reset', "SAFE RESET start. State dir: {$stateDir}", 'INFO');

    // 1) Telegram anti-spam state
    $notifyFile = $stateDir . "\\jetinno_notify_state.json";
    $notify = json_load_file($notifyFile);

    if (!is_array($notify)) {
        xhe_log('reset', "notify state not found or invalid JSON: {$notifyFile}", 'WARNING');
    } else {
        // гарантируем структуру и чистим tg_notify целиком
        $notify['tg_notify'] = [];
        if (json_save_file($notifyFile, $notify)) {
            xhe_log('reset', "Cleared tg_notify in jetinno_notify_state.json", 'INFO');
        } else {
            xhe_log('reset', "FAILED to save {$notifyFile}", 'ERROR');
        }
    }

    // 2) Main anti-spam state
    $mainFile = $stateDir . "\\jetinno_state.json";

    // если у вас есть state_load/state_save — используем их
    if (function_exists('state_load')) {
        $state = state_load($mainFile, []);
    } else {
        $state = json_load_file($mainFile) ?? [];
    }
    if (!is_array($state)) $state = [];

    // Сбрасываем только антиспам/дедуп
    $state['errors_notify'] = [];
    $state['errors_notify_ser'] = [];
    $state['supplies_alert'] = [];
    $state['sales_notify'] = [];
    $state['syrup_notify'] = [];

    // Orders: только 2 (на случай если там накопилось)
    $removedOrders = reset_orders_only_two($state);

    $ok = function_exists('state_save')
        ? (bool)state_save($mainFile, $state)
        : json_save_file($mainFile, $state);

    xhe_log('reset', "jetinno_state.json saved=" . ($ok ? 'true' : 'false')
        . " removed_orders=" . count($removedOrders), 'INFO');

    if (!empty($removedOrders)) {
        xhe_log('reset', "Orders cleared (2 max): " . implode(', ', $removedOrders), 'INFO');
    }

    xhe_log('reset', "SAFE RESET done. syrup_last_sale_*.json NOT touched.", 'INFO');

} catch (Throwable $e) {
    xhe_log('reset', "EXCEPTION: " . $e->getMessage(), 'ERROR');
}
