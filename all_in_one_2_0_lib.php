<?php

// all_in_one_2_0_lib.php
// Extracted helper functions from all_in_one_2_0.php to keep the main runner small.

if (!function_exists('acc_notify_enabled')) {
function acc_notify_enabled($acc, $key, $default = true) {
    if (!is_array($acc)) return $default;
    if (!isset($acc['telegram_notify']) || !is_array($acc['telegram_notify'])) return $default;
    if (!array_key_exists($key, $acc['telegram_notify'])) return $default;
    return (bool)$acc['telegram_notify'][$key];
}
}

// ========== REBOOT STATE (boiler ERROR:7300 detection log) ==========
// Stores when we saw boiler error per VMC in a persistent JSON file.
// This is separate from the in-memory $state used for anti-spam/cooldowns.

if (!function_exists('reboot_state_path')) {
function reboot_state_path(int $userId): string {
    $base = runtime_base_dir();
    $dir  = $base . "\\state";
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    return $dir . "\\jetinno_reboot_state_" . $userId . ".json";
}
}

if (!function_exists('reboot_state_load')) {
function reboot_state_load(int $userId): array {
    $path = reboot_state_path($userId);
    if (!file_exists($path)) return ['updated_at'=>0,'by_vmc'=>[]];
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return ['updated_at'=>0,'by_vmc'=>[]];
    $j = @json_decode($raw, true);
    if (!is_array($j)) return ['updated_at'=>0,'by_vmc'=>[]];
    if (!isset($j['by_vmc']) || !is_array($j['by_vmc'])) $j['by_vmc'] = [];
    if (!isset($j['updated_at'])) $j['updated_at'] = 0;
    return $j;
}
}

if (!function_exists('reboot_state_save')) {
function reboot_state_save(int $userId, array $data): bool {
    $path = reboot_state_path($userId);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    return (bool)@file_put_contents($path, $json);
}
}

if (!function_exists('reboot_state_mark_detected')) {
function reboot_state_mark_detected(int $userId, string $vmc, int $ts, string $timeStr, string $addr = '', string $code = 'ERROR:7300'): void {
    if ($vmc === '') return;
    $st = reboot_state_load($userId);
    $prev = is_array($st['by_vmc'][$vmc] ?? null) ? $st['by_vmc'][$vmc] : [];
    $prevTs = (int)($prev['ts'] ?? 0);

    // keep max ts
    if ($ts <= 0) $ts = time();
    if ($timeStr === '') $timeStr = date('Y-m-d H:i:s', $ts);

    $changed = false;
    if ($ts > $prevTs) { $prevTs = $ts; $changed = true; }

    $new = [
        'ts'   => $prevTs,
        'time' => $timeStr,
        'addr' => $addr,
        'code' => $code,
    ];

    // fill gaps if we have new data
    if (($new['addr'] === '' || $new['addr'] === 'Unknown') && $addr !== '') { $new['addr'] = $addr; $changed = true; }
    if (($new['time'] === '' || $new['time'] === '0') && $timeStr !== '') { $new['time'] = $timeStr; $changed = true; }

    // if we updated max ts, also update timeStr
    if ($changed && $new['ts'] === $ts) {
        $new['time'] = $timeStr;
    }

    $st['by_vmc'][$vmc] = $new;
    $st['updated_at'] = time();

    // Always save: this file is meant for debugging/ops visibility.
    reboot_state_save($userId, $st);
}
}

if (!function_exists('runtime_base_dir')) {
    function runtime_base_dir(): string { return "C:\\jetinno_runtime"; }
}

if (!function_exists('xhe_log')) {
    function xhe_log($section, $message, $level="INFO") {
        echo date("Y-m-d H:i:s") . " | [$section][$level] $message\n";
    }
}

if (!function_exists('xhe_get_cookie_string')) {
function xhe_get_cookie_string($browser, $webpage): string
{
    $candidates = [];

    if (is_object($webpage)) {
        if (method_exists($webpage, 'get_cookie'))  $candidates[] = $webpage->get_cookie();
        if (method_exists($webpage, 'get_cookies')) $candidates[] = $webpage->get_cookies();
    }
    if (is_object($browser)) {
        if (method_exists($browser, 'get_cookie'))  $candidates[] = $browser->get_cookie();
        if (method_exists($browser, 'get_cookies')) $candidates[] = $browser->get_cookies();
    }

    foreach ($candidates as $c) {
        if (is_string($c) && trim($c) !== '') return trim($c);
    }
    return '';
}
}

if (!function_exists('curl_get_with_cookies')) {
function curl_get_with_cookies(string $url, string $cookieStr, array $headers = [], string $referer = "https://saas-hk.jetinno.com/"): array
{
    $ch = curl_init();

    $baseHeaders = [
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36",
        "Referer: " . $referer,
        "Accept: */*",
    ];
    $allHeaders = array_merge($baseHeaders, $headers);

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING => "",
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => $allHeaders,
        CURLOPT_COOKIE => $cookieStr,
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct   = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($body === false || $code >= 400) {
        return ['ok'=>false, 'http'=>$code, 'ct'=>$ct, 'err'=>$err, 'bytes'=>0, 'body'=>null];
    }

    return ['ok'=>true, 'http'=>$code, 'ct'=>$ct, 'err'=>$err, 'bytes'=>strlen($body), 'body'=>$body];
}
}

if (!function_exists('loginIfNeeded')) {
function loginIfNeeded(
    $input, $btn, $browser, $image, $anticapcha,
    string $loginValue, string $passValue, string $captchaPath, string $captchaHost, int $maxAttempts
): bool {
    $attempt = 0;

    while (is_object($input) && $input->is_exist_by_attribute("id", "username", false) && $attempt < $maxAttempts)
    {
        $attempt++;
        xhe_log('login', "login try {$attempt}", "INFO");

        $input->set_value_by_attribute("id", "username", false, $loginValue);
        $input->set_value_by_attribute("id", "password", false, $passValue);

        if (is_object($image)) $image->screenshot_by_number($captchaPath, 0);

        $captchaText = "";
        if (is_object($anticapcha) && method_exists($anticapcha, 'recognize')) {
            $captchaText = $anticapcha->recognize(
                $captchaPath,
                $anticapcha->api_key ?? "",
                $captchaHost,
                true, 5, 120, 0, 1, 0
            );
        }

        $input->set_value_by_attribute_by_form_number("id", "code", false, $captchaText, -1);
        $btn->click_by_attribute("id", "login", false);

        if (is_object($browser)) $browser->wait(3);
        sleep(1);
    }

    $stillLogin = (is_object($input) && $input->is_exist_by_attribute("id", "username", false));
    if ($stillLogin) {
        xhe_log('login', "LOGIN FAILED after {$attempt} attempts", "ERROR");
        return false;
    }

    xhe_log('login', "LOGIN OK", "INFO");
    return true;
}
}

if (!function_exists('csv_detect_delimiter')) {
function csv_detect_delimiter(string $csv): string {
    $lines = preg_split("/\r\n|\n|\r/", $csv);
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === '') continue;
        $cComma = substr_count($ln, ',');
        $cSemi  = substr_count($ln, ';');
        $cTab   = substr_count($ln, "\t");
        if ($cSemi >= $cComma && $cSemi >= $cTab && $cSemi > 0) return ';';
        if ($cTab  >= $cComma && $cTab  >= $cSemi && $cTab  > 0) return "\t";
        return ',';
    }
    return ',';
}
}

if (!function_exists('parse_csv_rows')) {
function parse_csv_rows(string $csv): array {
    $delim = csv_detect_delimiter($csv);
    $rows = [];
    $fp = fopen("php://temp", "r+");
    fwrite($fp, $csv);
    rewind($fp);
    while (($data = fgetcsv($fp, 0, $delim)) !== false) $rows[] = $data;
    fclose($fp);
    return $rows;
}
}

if (!function_exists('rows_to_assoc')) {
function rows_to_assoc(array $rows): array {
    if (count($rows) < 2) return [];
    $header = $rows[0];
    $out = [];
    for ($i=1; $i<count($rows); $i++) {
        $r = $rows[$i];
        if (count(array_filter($r, fn($x)=>trim((string)$x)!=='')) === 0) continue;
        $item = [];
        for ($c=0; $c<count($header); $c++) {
            $k = trim((string)($header[$c] ?? "col{$c}"));
            if ($k === '') $k = "col{$c}";
            $item[$k] = $r[$c] ?? '';
        }
        $out[] = $item;
    }
    return $out;
}
}

if (!function_exists('safe_s')) {
function safe_s(string $s): string {
    $s = trim($s);
    $s = str_replace(["\r","\n"], ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return $s;
}
}

if (!function_exists('normalize_key')) {
function normalize_key(string $k): string {
    $k = trim($k);
    $k = mb_strtolower($k, 'UTF-8');
    $k = str_replace([':', '：', "\t", "\r", "\n"], ' ', $k);
    $k = preg_replace('/\s+/u', ' ', $k);
    return $k;
}
}

if (!function_exists('find_col')) {
function find_col(array $row, array $needles): ?string {
    $map = [];
    foreach ($row as $k => $v) $map[normalize_key($k)] = $k;

    foreach ($needles as $n) {
        $n = normalize_key($n);
        foreach ($map as $nk => $orig) if ($nk === $n) return $orig;
    }
    foreach ($needles as $n) {
        $n = normalize_key($n);
        foreach ($map as $nk => $orig) if (str_contains($nk, $n)) return $orig;
    }
    return null;
}
}

if (!function_exists('parse_ts')) {
function parse_ts(string $dt): int {
    $dt = trim($dt);
    if ($dt === '') return 0;
    $ts = strtotime($dt);
    return $ts === false ? 0 : (int)$ts;
}
}

if (!function_exists('build_error_url')) {
function build_error_url(int $userId, int $page = 1, int $perPage = 200): string {
    $params = [
        'user_id' => $userId,
        'data_type' => 0,
        'daterange' => '',
        'vmc_no' => '',
        'like' => '',
        'vmc_model' => '',
        'error_code' => '',
        'is_set' => 1,
        'perPage' => $perPage,
        'order_by[key]' => '',
        'order_by[value]' => '',
        'export' => 1,
        'page' => $page,
    ];
    return "https://saas-hk.jetinno.com/error?" . http_build_query($params);
}
}

if (!function_exists('parse_level_from_code')) {
function parse_level_from_code(string $code): string {
    $u = strtoupper(trim($code));
    if (str_starts_with($u, 'ERROR:')) return 'ERROR';
    if (str_starts_with($u, 'WARNING:')) return 'WARNING';
    if (str_contains($u, 'ERROR')) return 'ERROR';
    if (str_contains($u, 'WARNING')) return 'WARNING';
    return 'UNKNOWN';
}
}

if (!function_exists('err_col_vmc')) {
function err_col_vmc(): string { return 'Cihaz numarası'; }
}

if (!function_exists('err_col_addr')) {
function err_col_addr(): string { return 'Cihaz adresi'; }
}

if (!function_exists('err_col_code')) {
function err_col_code(): string { return 'Hata kodu'; }
}

if (!function_exists('err_col_desc')) {
function err_col_desc(): string { return 'Arıza açıklaması'; }
}

if (!function_exists('err_col_type')) {
function err_col_type(): string { return 'tür'; }
}

if (!function_exists('err_col_status')) {
function err_col_status(): string { return 'Hata durumu'; }
}

if (!function_exists('err_col_time')) {
function err_col_time(): string { return 'Oluşma zamanı'; }
}

if (!function_exists('err_col_serial')) {
function err_col_serial(): string { return 'seri numarası'; }
}

if (!function_exists('build_supply_csv_url')) {
function build_supply_csv_url(int $userId, int $page = 1, int $perPage = 500): string {
    $params = [
        'user_id' => $userId,
        'date_range' => '',
        'vmc_no' => '',
        'vmc_model' => '',
        'page' => $page,
        'perPage' => $perPage,
        'export' => 1,
    ];
    return "https://saas-hk.jetinno.com/supply?" . http_build_query($params);
}
}

if (!function_exists('build_supply_html_url')) {
function build_supply_html_url(int $userId): string {
    $params = [
        'user_id' => $userId,
        'date_range' => '',
        'vmc_no' => '',
        'vmc_model' => '',
        'export' => 0,
    ];
    return "https://saas-hk.jetinno.com/supply?" . http_build_query($params);
}
}

if (!function_exists('supply_thresholds_old')) {
function supply_thresholds_old(): array {
    return [
        'tea'       => 200,
        'chocolate' => 500,
        'milk'      => 500,
        'syrup'     => 200,
        'water'     => 19,
        'coffee'    => 1000,
    ];
}
}

if (!function_exists('supply_key_from_name')) {
function supply_key_from_name(string $rawName): ?string {
    $name = mb_strtolower(trim($rawName), 'UTF-8');
    if ($name === '') return null;

    if (str_contains($name, 'çay') || str_contains($name, 'tea')) return 'tea';
    if (str_contains($name, 'çikol') || str_contains($name, 'choco') || str_contains($name, 'kakao')) return 'chocolate';
    if (str_contains($name, 'süt') || str_contains($name, 'milk')) return 'milk';
    if (str_contains($name, 'şurup') || str_contains($name, 'syrup')) return 'syrup';
    if ($name === 'su' || str_contains($name, ' water') || str_contains($name, 'water') || str_contains($name, ' su')) return 'water';
    if (str_contains($name, 'kahve') || str_contains($name, 'bean') || str_contains($name, 'coffee')) return 'coffee';
    return null;
}
}

if (!function_exists('supply_rec_key_from_name')) {
function supply_rec_key_from_name(string $rawName): ?string {
    $name = mb_strtolower(trim($rawName), 'UTF-8');
    if ($name === '') return null;

    if (str_contains($name, 'çay') || str_contains($name, 'tea')) return 'tea';
    // sugar
    if (str_contains($name, 'şeker') || str_contains($name, 'seker') || str_contains($name, 'sugar')) return 'sugar';
    // cocoa
    if (str_contains($name, 'kakao') || str_contains($name, 'cocoa')) return 'cocoa';
    // chocolate (exclude cocoa which is handled above)
    if (str_contains($name, 'çikol') || str_contains($name, 'choco') || str_contains($name, 'chocolate')) return 'chocolate';

    if (str_contains($name, 'süt') || str_contains($name, 'milk')) return 'milk';
    if (str_contains($name, 'kahve') || str_contains($name, 'bean') || str_contains($name, 'coffee')) return 'coffee';

    // syrups are tracked elsewhere (syrup-alerts). We don't recommend packs for syrups here.
    return null;
}
}

if (!function_exists('supply_rec_rules')) {
function supply_rec_rules(): array {
    // All weights in grams
    return [
        'coffee'    => ['cap' => 3000, 'pack' => 500,  'label' => 'Кофе'],
        'milk'      => ['cap' => 2000, 'pack' => 1000, 'label' => 'Молоко'],
        'sugar'     => ['cap' => 2000, 'pack' => 1000, 'label' => 'Сахар'],
        'cocoa'     => ['cap' => 2000, 'pack' => 1000, 'label' => 'Какао'],
        'chocolate' => ['cap' => 2000, 'pack' => 1000, 'label' => 'Шоколад'],
        'tea'       => ['cap' => 750,  'pack' => 300,  'label' => 'Чай'],
    ];
}
}

if (!function_exists('supply_rows_to_struct')) {
function supply_rows_to_struct(array $assocRows): array
{
    $byDev = [];
    foreach ($assocRows as $row) {
        if (!is_array($row) || count($row) < 2) continue;

        $cDev   = find_col($row, ['device id','device_id','vmc no','vmc_no','cihaz numarası','cihaz no','machine no','机器编号']);
        $cAddr  = find_col($row, ['address','cihaz adresi','cihaz adres','konum','location','地址']);
        $cSid   = find_col($row, ['supply id','supply_id','ingredient id','material id','malzeme id','id','原料id','配料id']);
        $cName  = find_col($row, ['name','ingredient','material','malzeme','ürün','原料','配料']);
        $cVal   = find_col($row, ['value','remain','left','qty','quantity','miktar','kalan','rest','剩余','余量']);

        $dev = $cDev ? (int)preg_replace('~\D+~','', (string)$row[$cDev]) : 0;
        if ($dev <= 0) continue;

        $addr = $cAddr ? safe_s((string)$row[$cAddr]) : '';

        $sid  = $cSid ? (int)preg_replace('~\D+~','', (string)$row[$cSid]) : 0;
        $name = $cName ? safe_s((string)$row[$cName]) : '';
        $val  = $cVal ? (int)preg_replace('~[^\d\-]+~','', (string)$row[$cVal]) : 0;

        if (!isset($byDev[$dev])) {
            $byDev[$dev] = ['device_id'=>$dev,'address'=>$addr,'supplies'=>[]];
        } else {
            if ($byDev[$dev]['address'] === '' && $addr !== '') $byDev[$dev]['address'] = $addr;
        }

        // "wide" case: try treat columns as ingredients
        if ($name === '' && $sid === 0) {
            foreach ($row as $k => $v) {
                $nk = normalize_key((string)$k);
                if (str_contains($nk,'device') || str_contains($nk,'vmc') || str_contains($nk,'address') || str_contains($nk,'konum') || str_contains($nk,'model')) continue;
                $maybeName = trim((string)$k);
                $maybeVal  = (int)preg_replace('~[^\d\-]+~','', (string)$v);
                $key = supply_key_from_name($maybeName);
                if ($key === null) continue;
                $synId = crc32($key);
                $byDev[$dev]['supplies'][$synId] = ['name'=>$maybeName,'value'=>$maybeVal];
            }
            continue;
        }

        if ($name !== '') {
            if ($sid <= 0) $sid = crc32($name . '|' . $dev);
            $byDev[$dev]['supplies'][$sid] = ['name'=>$name,'value'=>$val];
        }
    }

    return array_values($byDev);
}
}

if (!function_exists('build_failed_orders_csv_url')) {
function build_failed_orders_csv_url(int $userId, string $datemonth, int $page = 1, int $perPage = 200): string {
    $params = [
        'user_id' => $userId,
        'datemonth' => $datemonth,
        'daterange' => '',
        'daterange_update_time' => '',
        'order_no' => '',
        'vmc_no' => '',
        'board_sn' => '',
        'stum_version' => '',
        'io_version' => '',
        'cup_version' => '',
        'ice_version' => '',
        'milk_version' => '',
        'syrup_version' => '',
        'vmc_model' => '',
        'product_id' => '',
        'isOK' => 0,
        'page' => $page,
        'perPage' => $perPage,
        'order_by[key]' => '',
        'order_by[value]' => '',
        'export' => 1,
    ];
    return "https://saas-hk.jetinno.com/order?" . http_build_query($params);
}
}

if (!function_exists('process_failed_orders_csv_rows')) {
function process_failed_orders_csv_rows(
    array $assocRows,
    string $accName,
    int $userId,
    ?string $chatId,
    array &$state,
    &$notifyState,
    array $acc,
    string $accountKey,
    int $antispamSeconds = 3600
): array
{
    $stats = ['found'=>0,'sent'=>0,'skipped_known'=>0,'skipped_disabled'=>0,'bad_rows'=>0];

    if (!isset($state['orders_fail_notify']) || !is_array($state['orders_fail_notify'])) $state['orders_fail_notify'] = [];
    if (!isset($state['orders_fail_notify'][$accountKey]) || !is_array($state['orders_fail_notify'][$accountKey])) $state['orders_fail_notify'][$accountKey] = [];

    $notifyOrders = function_exists('isTelegramNotifyEnabled')
        ? isTelegramNotifyEnabled($acc, 'orders')
        : (bool)($acc['telegram_notify']['orders'] ?? true);

    if (!$notifyOrders || $chatId === null) {
        $stats['skipped_disabled'] = 1;
        return $stats;
    }

    foreach ($assocRows as $row) {
        if (!is_array($row) || count($row) < 2) { $stats['bad_rows']++; continue; }

        $colOrderNo = find_col($row, ['order no', 'order_no', 'sipariş no', 'sipariş numarası', '订单号']);
        $colVmcNo   = find_col($row, ['vmc no', 'vmc_no', 'cihaz numarası', 'machine no', '机器编号']);
        $colAddr    = find_col($row, ['address', 'cihaz adresi', 'cihaz adres', 'konum', 'location', '地址']);
        $colName    = find_col($row, ['name', 'product name', 'ürün adı', 'ürün', '产品名称']);
        $colPay     = find_col($row, ['pay type', 'payment type', 'ödem', 'pay', '支付方式']);
        $colPrice   = find_col($row, ['price', 'amount', 'tutar', 'fiyat', '金额']);
        $colReason  = find_col($row, ['reason', 'fail reason', 'hata', 'açıklama', 'description', '原因']);
        $colTime    = find_col($row, ['time', 'create time', 'created', 'oluşma', 'sipariş zamanı', '时间', 'satın alma zamanı' , 'satın alma zamanı','satın alma zamani' , 'satın alma zamanı','satın alma zamani']);

        $orderNo = $colOrderNo ? safe_s((string)$row[$colOrderNo]) : '';
        if ($orderNo === '') { $stats['bad_rows']++; continue; }

        $stats['found']++;

        $lastTs = (int)($state['orders_fail_notify'][$accountKey][$orderNo] ?? 0);
        if ($lastTs > 0 && (time() - $lastTs) < $antispamSeconds) {
            $stats['skipped_known']++;
            continue;
        }

        $vmcNo = $colVmcNo ? safe_s((string)$row[$colVmcNo]) : '';
        $addr  = $colAddr  ? safe_s((string)$row[$colAddr])  : '';
        $name  = $colName  ? safe_s((string)$row[$colName])  : '';
        $pay   = $colPay   ? safe_s((string)$row[$colPay])   : '';
        $price = $colPrice ? safe_s((string)$row[$colPrice]) : '';
        $reason= $colReason? safe_s((string)$row[$colReason]): '';
        $time  = $colTime  ? safe_s((string)$row[$colTime])  : '';

        // BARDak (cup) error: send only if the error time is present and not older than 3 hours.
        if ($time === '') {
            xhe_log('orders_fail', "SKIP bardak error (no time): account={$accName} user_id={$userId} order_no={$orderNo} vmc={$vmcNo}", 'WARN');
            continue;
        }
        $ts = strtotime($time);
        if ($ts === false || $ts <= 0) {
            xhe_log('orders_fail', "SKIP bardak error (bad time): account={$accName} user_id={$userId} order_no={$orderNo} vmc={$vmcNo} time={$time}", 'WARN');
            continue;
        }
        $age = time() - $ts;
        if ($age > 3 * 3600) {
            xhe_log('orders_fail', "SKIP old bardak error: account={$accName} user_id={$userId} order_no={$orderNo} vmc={$vmcNo} time={$time} age_sec={$age}", 'INFO');
            continue;
        }

$title = "🔴 ПРОБЛЕМА СО СТАКАНОМ: напиток не сделан";
        $msg =
            "{$title} | {$accName}\n" .
            "VMC: {$vmcNo}\n" .
            "Дата ошибки: {$time}\n" .
            ($pay !== '' ? "Pay: {$pay}\n" : "") .
            ($name !== '' ? "Drink: {$name}\n" : "") .
            ($price !== '' ? "Price: {$price}\n" : "");

        // reason removed for bardak error notifications

if (function_exists('tg_notify')) {
            $tgKey = $accountKey . '|' . $orderNo;
            if (!acc_notify_enabled($acc, 'orders', true)) {
                xhe_log('orders', "SKIP orders notify (disabled in config) account={$accName} user_id={$userId}", 'INFO');
                continue;
            }
            tg_notify('orders', $msg, $chatId, $tgKey, $notifyState);
            xhe_log('orders_fail', "SEND TG type=orders_fail account={$accName} user_id={$userId} order_no={$orderNo} vmc={$vmcNo} chat_id={$chatId}", "INFO");
        } elseif (function_exists('sendmessage')) {
            sendmessage($msg, $chatId);
            xhe_log('orders_fail', "SEND TG(fallback) type=orders_fail account={$accName} user_id={$userId} order_no={$orderNo}", "INFO");
        }

        $state['orders_fail_notify'][$accountKey][$orderNo] = time();
        $stats['sent']++;
    }

    return $stats;
}
}

if (!function_exists('build_sales_orders_csv_url')) {
function build_sales_orders_csv_url(int $userId, string $datemonth, string $daterange, int $page = 1, int $perPage = 200): string {
    // same /order endpoint, but isOK=1 (успешные)
    $params = [
        'user_id' => $userId,
        'datemonth' => $datemonth,
        'daterange' => $daterange,

        'daterange_update_time' => '',
        'order_no' => '',
        'vmc_no' => '',
        'board_sn' => '',
        'stum_version' => '',
        'io_version' => '',
        'cup_version' => '',
        'ice_version' => '',
        'milk_version' => '',
        'syrup_version' => '',
        'vmc_model' => '',
        'product_id' => '',
        'isOK' => 1,
        'page' => $page,
        'perPage' => $perPage,
        'order_by[key]' => '',
        'order_by[value]' => '',
        'export' => 1,
    ];
    return "https://saas-hk.jetinno.com/order?" . http_build_query($params);
}
}

if (!function_exists('parse_money')) {
function parse_money(string $s): float {
    $s = trim($s);
    if ($s === '') return 0.0;
    $s = str_replace(["\xC2\xA0", " ", ","], ['', '', '.'], $s); // nbsp, space, comma->dot
    $s = preg_replace('~[^0-9\.\-]~', '', $s);
    if ($s === '' || $s === '.' || $s === '-') return 0.0;
    return (float)$s;
}
}

if (!function_exists('sales_aggregate')) {
function sales_aggregate(array $assocRows): array {
    // returns totals + by_vmc
    $totalAmount = 0.0;
    $totalCount = 0;

    $byVmc = [];

    foreach ($assocRows as $row) {
        if (!is_array($row) || count($row) < 2) continue;

        $colVmc  = find_col($row, ['vmc no','vmc_no','cihaz numarası','machine no','机器编号']);
        $colAddr = find_col($row, ['address','cihaz adresi','cihaz adres','konum','location','地址']);
        $colAmt  = find_col($row, ['amount','price','tutar','fiyat','金额']);
        $colTime = find_col($row, ['time','create time','created','oluşma','sipariş zamanı','时间']);

        $vmc  = $colVmc ? safe_s((string)$row[$colVmc]) : '';
        $addr = $colAddr ? safe_s((string)$row[$colAddr]) : '';
        $amt  = $colAmt ? parse_money((string)$row[$colAmt]) : 0.0;
        $time = $colTime ? safe_s((string)$row[$colTime]) : '';

        $totalAmount += $amt;
        $totalCount++;

        if ($vmc === '') $vmc = 'UNKNOWN';
        if (!isset($byVmc[$vmc])) {
            $byVmc[$vmc] = ['count'=>0,'amount'=>0.0,'address'=>$addr,'last_time'=>$time];
        }
        $byVmc[$vmc]['count'] += 1;
        $byVmc[$vmc]['amount'] += $amt;

        // best-effort last_time max by string/ts
        $ts = parse_ts($time);
        $prevTs = parse_ts((string)$byVmc[$vmc]['last_time']);
        if ($ts > $prevTs) $byVmc[$vmc]['last_time'] = $time;
        if ($byVmc[$vmc]['address'] === '' && $addr !== '') $byVmc[$vmc]['address'] = $addr;
    }

    // sort by amount desc
    uasort($byVmc, function($a,$b){
        if ($a['amount'] == $b['amount']) return 0;
        return ($a['amount'] < $b['amount']) ? 1 : -1;
    });

    return [
        'total_amount' => $totalAmount,
        'total_count' => $totalCount,
        'by_vmc' => $byVmc,
    ];
}
}

if (!function_exists('tr_fold')) {
function tr_fold(string $s): string {
    // normalize Turkish chars + lowercase for robust substring checks
    $s = mb_strtolower(trim($s), 'UTF-8');
    $map = [
        'ı'=>'i','İ'=>'i','ş'=>'s','Ş'=>'s','ğ'=>'g','Ğ'=>'g','ü'=>'u','Ü'=>'u','ö'=>'o','Ö'=>'o','ç'=>'c','Ç'=>'c',
    ];
    return strtr($s, $map);
}
}

if (!function_exists('syrup_from_drink')) {
function syrup_from_drink(string $drinkName): string {
    $n = tr_fold($drinkName);
    // Keywords based on your real menu naming
    if (strpos($n, 'caramelli') !== false) return 'caramel';
    if (strpos($n, 'findikli') !== false) return 'hazelnut';
    if (strpos($n, 'muzlu') !== false) return 'banana';
    if (strpos($n, 'cilekli') !== false) return 'strawberry';
    return '';
}
}

if (!function_exists('syrup_display_name')) {
function syrup_display_name(string $key): string {
    switch ($key) {
        case 'caramel': return 'Caramelli (карамель)';
        case 'hazelnut': return 'Fındıklı (орех)';
        case 'banana': return 'Muzlu (банан)';
        case 'strawberry': return 'Çilekli (клубника)';
        default: return $key;
    }
}
}

if (!function_exists('syrup_state_path')) {
function syrup_state_path(int $userId): string {
    $base = runtime_base_dir();
    $dir = $base . "\\state";
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    return $dir . "\\syrup_last_sale_" . $userId . ".json";
}
}

if (!function_exists('syrup_state_load')) {
function syrup_state_load(int $userId): array {
    $path = syrup_state_path($userId);
    if (!file_exists($path)) return ['by_vmc'=>[]];
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return ['by_vmc'=>[]];
    $data = json_decode($raw, true);
    if (!is_array($data)) return ['by_vmc'=>[]];
        if (!isset($data['by_vmc']) || !is_array($data['by_vmc'])) $data['by_vmc'] = [];
    return $data;
}
}

if (!function_exists('syrup_state_save')) {
function syrup_state_save(int $userId, array $data): void {
    $path = syrup_state_path($userId);
    // Keep only per-machine data; drop legacy 'overall' if present.
    if (isset($data['overall'])) unset($data['overall']);
    @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}
}

if (!function_exists('vmc_locations_state_path')) {
function vmc_locations_state_path(int $userId): string {
    $base = runtime_base_dir();
    $dir  = $base . "\\state";
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    return $dir . "\\vmc_locations_" . $userId . ".json";
}
}

if (!function_exists('vmc_locations_load')) {
function vmc_locations_load(int $userId): array {
    $path = vmc_locations_state_path($userId);
    if (function_exists('state_load')) {
        $data = state_load($path, ['updated_at' => 0, 'map' => []]);
        return is_array($data) ? $data : ['updated_at' => 0, 'map' => []];
    }
    if (!file_exists($path)) return ['updated_at' => 0, 'map' => []];
    $raw = @file_get_contents($path);
    $j = @json_decode($raw, true);
    if (!is_array($j)) return ['updated_at' => 0, 'map' => []];
    if (!isset($j['map']) || !is_array($j['map'])) $j['map'] = [];
    if (!isset($j['updated_at'])) $j['updated_at'] = 0;
    return $j;
}
}

if (!function_exists('vmc_locations_save')) {
function vmc_locations_save(int $userId, array $data): bool {
    $path = vmc_locations_state_path($userId);
    if (function_exists('state_save')) {
        return (bool)state_save($path, $data);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    return (bool)@file_put_contents($path, $json);
}
}

if (!function_exists('vmc_locations_update_from_errors_csv')) {
function vmc_locations_update_from_errors_csv(int $userId, string $csvPath, int $minIntervalSeconds = 21600): bool {
    if ($csvPath === '' || !file_exists($csvPath)) return false;

    $state = vmc_locations_load($userId);
    $last  = (int)($state['updated_at'] ?? 0);
    if ($last > 0 && (time() - $last) < $minIntervalSeconds) {
        return false; // too soon
    }

    $fh = @fopen($csvPath, 'r');
    if (!$fh) return false;

    $header = fgetcsv($fh);
    if (!is_array($header)) { fclose($fh); return false; }

    // build header map lower
    $hmap = [];
    foreach ($header as $i => $h) {
        $key = mb_strtolower(trim((string)$h));
        $hmap[$key] = $i;
    }

    $colVmc  = $hmap['cihaz numarası'] ?? null;
    $colAddr = $hmap['cihaz adresi']   ?? null;

    if ($colVmc === null || $colAddr === null) {
        fclose($fh);
        xhe_log('locations', "WARN: can't find columns in errors CSV for locations. Need 'Cihaz numarası' and 'Cihaz adresi'.", 'WARN');
        return false;
    }

    $map = is_array($state['map'] ?? null) ? $state['map'] : [];
    $changed = false;
    $rows = 0;

    while (($row = fgetcsv($fh)) !== false) {
        $rows++;
        $vmc  = trim((string)($row[$colVmc]  ?? ''));
        $addr = trim((string)($row[$colAddr] ?? ''));
        if ($vmc === '' || $addr === '') continue;

        // guard: don't store model-like values as address
        if (preg_match('/^JL\d+/i', $addr)) continue;

        if (!isset($map[$vmc]) || $map[$vmc] !== $addr) {
            $map[$vmc] = $addr;
            $changed = true;
        }
    }
    fclose($fh);

    $state['updated_at'] = time();
    $state['map'] = $map;

    if ($changed) {
        vmc_locations_save($userId, $state);
        xhe_log('locations', "UPDATED from errors CSV: {$csvPath} rows={$rows} map_count=" . count($map), 'INFO');
    } else {
        // still touch updated_at so we don't re-parse too often
        vmc_locations_save($userId, $state);
        xhe_log('locations', "CHECK OK (no changes) from errors CSV. rows={$rows} map_count=" . count($map), 'DEBUG');
    }

    return true;
}
}

if (!function_exists('syrup_state_merge_and_save')) {
function syrup_state_merge_and_save(int $userId, array $scan): array {
    // Merge scan into persisted state (keep max timestamps) and save only if changed.
    $state = syrup_state_load($userId);
    $changed = false;

    if (!isset($scan['by_vmc']) || !is_array($scan['by_vmc'])) $scan['by_vmc'] = [];

    foreach ($scan['by_vmc'] as $syr => $vmcs) {
        if (!is_array($vmcs)) continue;
        if (!isset($state['by_vmc'][$syr]) || !is_array($state['by_vmc'][$syr])) { $state['by_vmc'][$syr] = []; $changed = true; }
        foreach ($vmcs as $vmc => $info) {
            $ts = (int)($info['ts'] ?? 0);
            $addr = safe_s((string)($info['addr'] ?? ''));
            $timeStr = safe_s((string)($info['time'] ?? ''));
            $prevTs = isset($state['by_vmc'][$syr][$vmc]['ts']) ? (int)$state['by_vmc'][$syr][$vmc]['ts'] : 0;

            if ($ts > $prevTs) {
                $state['by_vmc'][$syr][$vmc] = ['ts'=>$ts,'addr'=>$addr,'time'=>$timeStr];
                $changed = true;
            } elseif ($prevTs > 0 && $addr !== '' && safe_s((string)($state['by_vmc'][$syr][$vmc]['addr'] ?? '')) === '') {
                $state['by_vmc'][$syr][$vmc]['addr'] = $addr;
                $changed = true;
            }
        }
    }

    if ($changed) syrup_state_save($userId, $state);
    return $state;
}
}

if (!function_exists('syrup_scan_last_sales')) {
function syrup_scan_last_sales(array $assocRows): array {
    // returns ['by_vmc'=>[syrup=>[vmc=>['ts'=>ts,'addr'=>addr,'time'=>time]]]]
    $by = [];

    foreach ($assocRows as $row) {
        if (!is_array($row)) continue;

        $colVmc  = find_col($row, ['vmc no','vmc_no','cihaz numarası','machine no','机器编号']);
        $colAddr = find_col($row, ['address','cihaz adresi','cihaz adres','konum','location','地址','vmc adı','vmc adi']);
        $colTime = find_col($row, ['time','create time','created','oluşma','sipariş zamanı','satın alma zamanı','satın alma zamani','时间']);
        $colDrink= find_col($row, ['product','drink','ürün adı','urun adi','商品名称','product name']);

        $vmc  = $colVmc ? safe_s((string)$row[$colVmc]) : '';
        $addr = $colAddr ? safe_s((string)$row[$colAddr]) : '';
        $time = $colTime ? safe_s((string)$row[$colTime]) : '';
        $drink= $colDrink? safe_s((string)$row[$colDrink]) : '';

        if ($vmc === '' || $drink === '' || $time === '') continue;

        $syr = syrup_from_drink($drink);
        if ($syr === '') continue;

        $ts = parse_ts($time);
        if ($ts <= 0) continue;

        if (!isset($by[$syr])) $by[$syr] = [];
        if (!isset($by[$syr][$vmc]) || $ts > $by[$syr][$vmc]['ts']) {
            $by[$syr][$vmc] = ['ts'=>$ts,'addr'=>$addr,'time'=>$time];
        } elseif ($by[$syr][$vmc]['addr'] === '' && $addr !== '') {
            $by[$syr][$vmc]['addr'] = $addr;
        }
    }

    return ['by_vmc'=>$by];
}
}

if (!function_exists('syrup_notify_stale')) {
function syrup_notify_stale(array $acc, array $scan, string $accName, int $userId, string $chatId, array &$state, array &$notifyState, int $staleDays = 7): void {
    $now = time();
    $limit = $staleDays * 86400;
    $todayKey = date('Y-m-d', $now);

    $allSyrups = ['caramel','hazelnut','banana','strawberry'];

    // Ensure anti-spam storage
    if (!isset($state['syrup_notify']) || !is_array($state['syrup_notify'])) $state['syrup_notify'] = [];

    // Build set of vmc numbers seen in syrup state
    $vmcSet = [];
    foreach ($allSyrups as $syr) {
        $by = $scan['by_vmc'][$syr] ?? [];
        if (!is_array($by)) continue;
        foreach ($by as $vmc => $info) {
            if ($vmc === '' || $vmc === null) continue;
            $vmcSet[(string)$vmc] = true;
        }
    }

    foreach (array_keys($vmcSet) as $vmc) {
        $staleLines = [];

        foreach ($allSyrups as $syr) {
            $info = $scan['by_vmc'][$syr][$vmc] ?? null;
            $ts = (int)($info['ts'] ?? 0);
            if ($ts <= 0) continue;

            $age = $now - $ts;
            if ($age < $limit) continue;

            // anti-spam: once per day per machine+ syrup
            $k = $accName.'|'.$userId.'|'.$vmc.'|'.$syr;
            if (($state['syrup_notify'][$k] ?? '') === $todayKey) continue;

            $days = floor($age / 86400);
            $lastStr = date('Y-m-d H:i:s', $ts);
            $staleLines[] = "• ".syrup_display_name($syr)." — {$days} дн. (посл.: {$lastStr})";

            // mark sent for this machine+syrup (set after successful send)
        }

        if (count($staleLines) === 0) continue;

        // Determine best address for the machine from any syrup entry
        $addr = '';
        foreach ($allSyrups as $syr) {
            $info = $scan['by_vmc'][$syr][$vmc] ?? null;
            $a = safe_s((string)($info['addr'] ?? ''));
            if ($a !== '') { $addr = $a; break; }
        }

        // Prefer real address from locations map (from config/state), fallback to scanned addr
        if (isset($locations) && is_array($locations) && isset($locations[$vmc])) {
            $addr = safe_s((string)$locations[$vmc]);
        }
        // guard: some exports put model (e.g. JL300) into address column
        if (preg_match('/^JL\d+/i', $addr)) $addr = '';


        if (!acc_notify_enabled($acc, 'syrup', true)) {
            xhe_log('syrup', "SKIP syrup notify (disabled in config) acc=" . (isset($acc['name'])?$acc['name']:'') , 'INFO');
            return;
        }
		$addr = get_location((int)$userId, (string)$vmc);
        $msg = "🧴 SYRUP ALERT | {$accName}
"
             . "VMC: {$vmc}" . ($addr !== '' ? " - {$addr}" : "") . "
"
             . "Нет продаж сиропов {$staleDays}+ дней:
"
             . implode("\n", $staleLines);

        $sentOk = false;
        if (function_exists('tg_notify')) {
            $tgKey = $accName . '|syrup|' . $todayKey . '|' . $vmc;
            tg_notify('syrup', $msg, $chatId, $tgKey, $notifyState);
            $sentOk = true;
            xhe_log('syrup', "SEND TG type=syrup account={$accName} vmc={$vmc} ok=" . ($sentOk?'true':'false'), 'INFO');
        } elseif (function_exists('sendmessage')) {
            sendmessage($msg, $chatId);
            $sentOk = true;
            xhe_log('syrup', "SEND TG(fallback) type=syrup account={$accName} vmc={$vmc}", 'INFO');
        }

        if ($sentOk) {
            foreach ($allSyrups as $syr) {
                $info = $scan['by_vmc'][$syr][$vmc] ?? null;
                $ts = (int)($info['ts'] ?? 0);
                if ($ts <= 0) continue;
                $age = $now - $ts;
                if ($age < $limit) continue;

                $k = $accName.'|'.$userId.'|'.$vmc.'|'.$syr;
                $state['syrup_notify'][$k] = $todayKey;
            }
        }
    }
}
}
