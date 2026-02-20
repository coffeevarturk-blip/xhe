PATCH: Управление модулями через config.php

Что сделано:
1) config.php — добавлен блок 'modules' для глобального включения/выключения модулей.
2) all_in_one_2_0_modular.php — уже использует $CFG['modules'] через cfg_module_enabled() и run_module_file().

Ключи modules (config.php):
- errors            : парсинг ошибок/варнингов
- reboot            : boiler reboot (boiler_reboot.php)
- supply            : остатки/дозаправка (supply.php)
- sync              : auto machine sync (machine_sync.php)
- orders_failed     : заказы success=0 (orders.php)
- orders_success    : заказы success=1 (orders_success.php)
- sales_syrups      : сиропы/продажи по сиропам (sales_syrups.php)
- telegram_commands : обработка команд/контрол (telegram_commands.php)
- manual_reboot     : ручной reboot (manual_reboot.php)
- manual_sync       : ручной sync (manual_sync.php)

Важно:
- Локальные настройки по аккаунтам (telegram_notify / кому слать) остаются как раньше.
- Если модуль выключен (false), файл модуля не подключается и код не исполняется.

Файлы в архиве:
- config.php
- all_in_one_2_0_modular.php
- README_patch.txt
