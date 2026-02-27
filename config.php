<?php
// config.php
// Главная точка входа конфигурации.
// Логика (без секретов) хранится в config.app.php
// Секреты (токены/пароли) — в config.secret.php
//
// ВАЖНО: config.secret.php НЕ коммитьте.

$APP = require __DIR__ . DIRECTORY_SEPARATOR . 'config.app.php';

$SECRET_PATH = __DIR__ . DIRECTORY_SEPARATOR . 'config.secret.php';
$SECRET = [];
if (is_file($SECRET_PATH)) {
    $SECRET = require $SECRET_PATH;
}

// Merge: secrets override app defaults
$CFG = array_replace_recursive($APP, $SECRET);

// Optional: normalize modules (defensive)
if (!isset($CFG['modules']) || !is_array($CFG['modules'])) {
    $CFG['modules'] = [];
}

return $CFG;


// -------------------- ERROR / WARNING DENY FILTERS --------------------
$CFG['notify']['filters'] = [
    'errors' => [
        'mode'  => 'deny',
        'codes' => [
            '7300',    // example numeric error
        ],
    ],
    'warnings' => [
        'mode'  => 'deny',
        'codes' => [
            'Z0050',   // MDB Cihazı Çevrimdışı
            '3B80',    // example alphanumeric warning
        ],
    ],
];

