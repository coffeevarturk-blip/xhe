PATCH: Конфиги и управление модулями (2026-02-20)

Что сделано:
1) config.php превращён в ЕДИНУЮ точку входа: он грузит config.app.php + config.secret.php и делает merge:
   $CFG = array_replace_recursive($APP, $SECRET);

2) all_in_one_2_0_modular.php теперь грузит ТОЛЬКО config.php.
   Это устраняет ситуацию "смотрим один конфиг, а работает другой".

Файлы:
- config.app.php     (логика/флаги, без секретов)
- config.secret.php  (секреты: токены/пароли, НЕ коммитить)
- config.php         (сборщик/merge)
- all_in_one_2_0_modular.php (обновлённый require config.php)

Проверка:
В логах после старта должен быть один источник конфигурации: config.php.
