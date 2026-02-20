<?php
// App config (safe to commit). Secrets are loaded from config.secret.php
// config.php
// ВАЖНО: не коммитьте этот файл в публичные репозитории.

return [
    'timezone' => 'Europe/Istanbul',

    

    // Module toggles (true=run, false=skip)
    'modules' => [
        'errors'           => true,
        'reboot'           => true,
        'supply'           => true,
        'sync'             => true,
        'orders_failed'    => true,
        'orders_success'   => true,
        'sales_syrups'     => true,
        'telegram_commands'=> true,
        'manual_reboot'    => true,
        'manual_sync'      => true,
    ],
'xhe' => [
        'host' => '127.0.0.1:7010',
    ],

    'auth' => [
        'login' => null, // login in config.secret.php
        'password' => null, // password in config.secret.php
        'max_attempts' => 10,
    ],

    'anticaptcha' => [
        'api_key' => null, // api_key in config.secret.php
        'captcha_path' => 'c:\\screenshot.jpg',
    ],

    // Управление логами, которые выводятся прямо в XHE (echo).
    'log' => [
        'enabled' => true,
        'with_ts' => true,
        'categories' => [
            'main'   => true,
            'login'  => true,
            'machine'=> true,
            'reboot' => true,
            'sales'  => true,
            'supply' => true,
            'orders' => true,
            'system' => true,
            'debug'  => false,
        ],
    ],

        'telegram' => [
    'bot_token' => null, // bot_token in config.secret.php

    // Fallback чат (если у аккаунта не задан telegram_chat_id)
    'chat_id'   => 1602852137,

    // Чат администратора: сюда уходят ТОЛЬКО системные ошибки
    'admin_chat_id' => 1602852137,

    // Антиспам для admin_chat_id
    'admin_rate_limit' => [
        'enabled' => true,
        'cooldown_sec' => 300, // один и тот же ключ не чаще чем раз в 5 минут
        'max_per_hour' => 10,  // максимум 10 системных сообщений в час
    ],

    // ===== ВХОДЯЩИЕ КОМАНДЫ ОТ TELEGRAM =====
    'commands' => [
        'enabled' => true,

        // Кто может управлять ботом
        'allowed_chat_ids' => [
            '1602852137', // ваш ID
        ],

        // Антиспам команд
        'cooldown_sec' => 5,          // обычные команды
        'cooldown_heavy_sec' => 20,   // reboot / sync / sales

        // Отвечать ли чужим чатам
        'notify_denied' => false,
    ],

    'ca_cert'   => 'D:\\cacert.pem',
],

// -------------------- УВЕДОМЛЕНИЯ: АНТИСПАМ ПО ТИПАМ --------------------
// Это ограничители, чтобы Telegram не засыпало одинаковыми сообщениями.
// Логика:
// - cooldown_sec: одинаковый ключ ($key) по этому типу не отправлять чаще, чем раз в N секунд
// - max_per_hour: максимум сообщений этого типа в час (0 = без лимита)
// - max_per_day: максимум сообщений этого типа в сутки (0 = без лимита)
//
// ВАЖНО:
// - ключ ($key) формируем в коде (обычно machine_number / orderNo / deviceId+supplyId и т.п.)
// - лимиты считаются ОТДЕЛЬНО по каждому accountKey (если $key включает accountKey)
'notify' => [
    // значения по умолчанию для неизвестного типа
    'default' => [
        'enabled' => true,
        'cooldown_sec' => 60, // 1 минута
        'max_per_hour' => 0,
        'max_per_day'  => 0,
    ],

    'types' => [
        // Ребуты / критические ошибки по машине (boiler error etc.)
        'reboot' => [
            'enabled' => true,
            'cooldown_sec' => 600, // 10 минут на один и тот же ключ
            'max_per_hour' => 12,
            'max_per_day'  => 100,
        ],

        // Новая сумма продаж (агрегированное уведомление)
        'sales' => [
            'enabled' => true,
            'cooldown_sec' => 900, // 15 минут
            'max_per_hour' => 4,
            'max_per_day'  => 24,
        ],

    'warnings' => [
      'cooldown_sec' => 1800,   // например 15 минут
      'max_per_hour' => 4,
    ],


        // Низкие остатки (Supply List)
        'supply' => [
            'enabled' => true,
            'cooldown_sec' => 3600, // 1 час на один и тот же ингредиент/машину
            'max_per_hour' => 30,
            'max_per_day'  => 300,
        ],

        // Новые заказы (Last Order)
        // Обычно уже дедуплицируются по orderNo, но оставляем защиту от повторов/глюков.
        'orders' => [
            'enabled' => true,
            'cooldown_sec' => 300, // 5 минут на один и тот же orderNo
            'max_per_hour' => 60,
            'max_per_day'  => 1000,
        ],
        'sync' => [
        'enabled'        => false,   // включить уведомления
        'cooldown_sec'   => 900,    // минимум 15 минут между сообщениями по одной машине
        'max_per_hour'   => 3,      // максимум 3 уведомления в час
        'stale_hours'    => 6,      // считать устаревшей синхронизацию если > 6 часов
    ],
    ],
],

    // Глобальные системные лимиты/защиты
    'system' => [
        'auth_max_attempts' => 10, // если логин не удался за N попыток — уведомить админа
    ],

    // Jetinno аккаунты: для каждого user_id отдельный проход + свой Telegram чат
'jetinno' => [
  'accounts' => [
    [
      'name' => 'MAIN',
      'user_id' => 629,
      'telegram_chat_id' => 1602852137,
      'telegram_notify' => [
        'sales' => true,
        'supply' => true,
        'errors' => true,
        'reboot' => true,
        'orders' => true,
        'heartbeat' => true,
      ],
    ],
    [
      'name' => 'sergio',
      'user_id' => 617,
      'telegram_chat_id' => -5079392825,
      'telegram_notify' => [
        'sales' => false,
        'supply' => false,
        'errors' => false,
        'reboot' => false,
        'orders' => false,
        'heartbeat' => false,
      ],
    ],
  ],
],

    'paths' => [
        // Файлы состояния:
        // - main   : основное состояние (машины/продажи/ребуты/служебные данные)
        // - notify : отдельное состояние для антиспама Telegram-уведомлений (hour/day/cooldown)
        'state_files' => [
            'main'   => 'c:\jetinno_state.json',
            'notify' => 'c:\jetinno_notify_state.json',
        ],

        // (совместимость) старое имя. Можно оставить равным state_files.main
        'state_file'   => 'c:\jetinno_state.json',

        // БАЗОВОЕ имя. Для каждого аккаунта будет автоматически ..._USERID.txt
        'orders_store' => 'c:\\mdb_orders.txt',

        'debug_orders' => 'c:\\debug_order_page.html',
    ],
];
