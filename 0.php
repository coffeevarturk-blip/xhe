<?php
/**
 * 0.php — Тест выбора напитка по product_id
 * Положить в ту же папку, где лежит make_drink.php
 *
 * D:\XWeb\Human Emulator Studio DEMO 7.0.76\My Scripts\
 */
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

// -------------------------------------------------
// 1️⃣ Подключаем make_drink
// -------------------------------------------------
require_once __DIR__ . '/make_drink.php';

// -------------------------------------------------
// 2️⃣ Настройки теста
// -------------------------------------------------

$vmc        = 81941;  // номер машины
$productId  = 125;    // ID напитка
$password   = '';     // пароль (если нужен)

// Если нужно задать кастомные селекторы:
$CFG['make_drink'] = [
    'select_css'              => 'select[name="product_id"]',
    'select2_container_css'   => 'span.select2-selection',
    'confirm_btn_css'         => '', // если есть кнопка confirm — вставьте css
    'password_input_css'      => 'input[type="password"]',
    'submit_btn_css'          => '', // если есть submit — вставьте css
    'wait_timeout_sec'        => 12,
];

// -------------------------------------------------
// 3️⃣ Мини-логгер (если нет xhe_log)
// -------------------------------------------------

if (!function_exists('xhe_log')) {
    function xhe_log($module, $text, $level = 'INFO') {
        echo date('Y-m-d H:i:s') . " | [$module][$level] $text\n";
    }
}

// -------------------------------------------------
// 4️⃣ Проверка наличия $browser
// -------------------------------------------------

if (!isset($browser)) {
    echo "ERROR: \$browser object not found.\n";
    echo "Запускайте этот файл из Human Emulator Studio после открытия страницы device_info.\n";
    exit;
}

// -------------------------------------------------
// 5️⃣ Запуск make_drink
// -------------------------------------------------

xhe_log('test', "START make_drink vmc={$vmc} product_id={$productId}");

$ok = make_drink_run(
    $CFG,
    [],         // аккаунт не нужен для теста
    (int)$vmc,
    (int)$productId,
    (string)$password
);

if ($ok) {
    xhe_log('test', 'SUCCESS make_drink finished');
} else {
    xhe_log('test', 'FAILED make_drink returned false', 'ERROR');
}

xhe_log('test', 'DONE');