ALL_IN_ONE 2.0 — SETTINGS README

1) TELEGRAM SETTINGS

Main chat (regular notifications):

‘telegram’ => [ ‘chat_id’ => 1602852137,],

Admin chat (CRITICAL alerts) — optional. If not set, main chat_id will
be used.

You can define one of:

‘telegram’ => [ ‘admin_chat_id’ => 123456789,],

or

‘admin_telegram_chat_id’ => 123456789,

To disable admin critical alerts:

‘admin_notify’ => false,

2) JETINNO ACCOUNTS

‘jetinno’ => [ ‘accounts’ => [ [ ‘name’ => ‘MAIN’, ‘user_id’ => 629,
‘telegram_chat_id’ => 1602852137, ‘telegram_notify’ => [ ‘errors’ =>
true, ‘supply’ => true, ‘orders’ => true, ‘sales’ => true, ], ], ],],

3) TELEGRAM FLAGS

errors → Machine errors & warnings supply → Low ingredient levels orders
→ Drink not made (critical cup issues) sales → Today’s sales updates
admin_notify → System critical alerts

4) ANTISPAM LOGIC

errors: - Dedup by user_id + vmc + error_code

supply: - 3600 sec cooldown - Per device + ingredient type

orders_fail: - 3600 sec cooldown

sales: - Sends only if today’s total increased

admin: - 600 sec cooldown per critical key

5) LOOP INTERVAL

At bottom of script:

sleep(60);

Change value to adjust cycle delay (seconds).

6) WHAT IS CRITICAL

Admin receives alert when:

-   Login failed
-   CSV download failed (errors / sales / orders)
-   Supply parsing failed
-   Any uncaught exception
