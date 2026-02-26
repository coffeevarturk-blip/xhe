<?php
// modules/orders_success.php
// Successful (isOK=1) paid orders for last 24h (rolling window).
// Source: /order CSV export=1
//
// Requirements:
// - Do not paginate more than 2 pages per month.
// - Stop early if we see that the oldest order on the current page is already older than the 24h window.
//
// Behavior:
// - Fetch daterange as [yesterday .. today] to cover last-24h window, then filter by timestamp >= now-86400.
// - Only PAID orders (price > 0) are treated as "success sales" here.
// - Telegram notification is sent only if enabled in config: telegram_notify['orders']
//   and is routed through tg_notify('orders', ...) to respect anti-spam rules.
// - Dedup per accountKey by order_no stored in $state['orders_success_seen'].
//
// Expected helpers (already in your project):
// build_sales_orders_csv_url, curl_get_with_cookies, parse_csv_rows, find_col, safe_s, parse_ts, xhe_log,
// tg_notify, isTelegramNotifyEnabled, getLocation

$notifyOrders = function_exists('isTelegramNotifyEnabled')
    ? isTelegramNotifyEnabled($acc, 'orders')
    : (bool)($acc['telegram_notify']['orders'] ?? false);

$chatId = $acc['telegram_chat_id'] ?? null;

if (!isset($state['orders_success_seen']) || !is_array($state['orders_success_seen'])) $state['orders_success_seen'] = [];
if (!isset($state['orders_success_seen'][$accountKey]) || !is_array($state['orders_success_seen'][$accountKey])) $state['orders_success_seen'][$accountKey] = [];

$nowTs   = time();
$startTs = $nowTs - 86400; // last 24h rolling

$startDate = date('Y-m-d', $startTs);
$endDate   = date('Y-m-d', $nowTs);
$daterange = $startDate . ' - ' . $endDate;

$months = array_values(array_unique([date('Y-m', $startTs), date('Y-m', $nowTs)]));

$perPage  = 200;
$maxPages = 2; // hard limit per requirement

$stats = [
    'pages_fetched'     => 0,
    'rows_total'        => 0,
    'rows_in_window'    => 0,
    'rows_paid'         => 0,
    'sent'              => 0,
    'skipped_known'     => 0,
    'skipped_disabled'  => 0,
    'bad_rows'          => 0,
    'early_stop_pages'  => 0,
];

// --- local header column finder (case-insensitive + Turkish normalization) ---
$norm = function(string $s): string {
    $s = trim($s);
    if (function_exists('mb_strtolower')) $s = mb_strtolower($s, 'UTF-8');
    else $s = strtolower($s);
    // Turkish & common diacritics normalization
    $map = [
        'ı'=>'i','İ'=>'i','ş'=>'s','Ş'=>'s','ğ'=>'g','Ğ'=>'g','ü'=>'u','Ü'=>'u','ö'=>'o','Ö'=>'o','ç'=>'c','Ç'=>'c',
        'â'=>'a','Â'=>'a','ê'=>'e','Ê'=>'e','î'=>'i','Î'=>'i','ô'=>'o','Ô'=>'o','û'=>'u','Û'=>'u',
    ];
    $s = strtr($s, $map);
    // collapse whitespace
    $s = preg_replace('~\s+~', ' ', $s);
    return $s;
};

$find_col_ci = function(array $header, array $needles) use ($norm) {
    $need = [];
    foreach ($needles as $n) $need[] = $norm((string)$n);
    foreach ($header as $idx => $h) {
        $hn = $norm((string)$h);
        foreach ($need as $n) {
            if ($hn === $n) return $idx;
            // allow contains for slight variations
            if ($n !== '' && strpos($hn, $n) !== false) return $idx;
        }
    }
    return null;
};

$keptCompact = []; // compact rows for json (limited by <= 2 pages/month)

foreach ($months as $datemonth) {

    $stopThisMonth = false;

    for ($page = 1; $page <= $maxPages; $page++) {

        $url = build_sales_orders_csv_url($userId, $datemonth, $daterange, $page, $perPage); // isOK=1
        $res = curl_get_with_cookies($url, $cookieStr, ["Accept: text/csv,*/*"], "https://saas-hk.jetinno.com/order");

        $looksCsv = ($res['ok'] && stripos((string)($res['ct'] ?? ''), 'text/csv') !== false);

        if (!$res['ok'] || !$looksCsv || (int)($res['bytes'] ?? 0) < 5) {
            xhe_log('orders_ok', "CSV FAILED datemonth={$datemonth} page={$page} http={$res['http']} ct={$res['ct']} bytes={$res['bytes']} err={$res['err']}", "WARNING");
            break;
        }

        $rows = parse_csv_rows((string)($res['body'] ?? ''));
        if (count($rows) < 2) break;

        $header = $rows[0];
        if (!is_array($header) || count($header) < 2) break;

        // Find columns by header row (multilang-friendly)
        $colOrderNo = $find_col_ci($header, ['order no','order_no','sipariş no','siparis no','sipariş numarası','siparis numarasi','sipariş numarasi','sipariş numarası','订单号']);
        $colVmcNo   = $find_col_ci($header, ['vmc no','vmc_no','vmc','makine','cihaz numarası','cihaz numarasi','cihaz no','cihaz','设备编号','设备号']);
$colProd    = $find_col_ci($header, ['product name','product','ürün adı','urun adi','urun adi']);
        $colPrice   = $find_col_ci($header, ['price','amount','tutar','fiyat','ürün fiyatı','urun fiyati','金额']);
        $colTime    = $find_col_ci($header, ['time','create time','created','oluşma','olusturma','sipariş zamanı','siparis zamani','satın alma zamanı','satin alma zamani','order time','时间','更新时间']);

        $colPay    = $find_col_ci($header, ['pay type','paytype','payment','payment type','ödeme','odeme','ödeme tipi','odeme tipi','pay','支付方式','支付类型']);
        if ($colOrderNo === null || $colVmcNo === null) {
            xhe_log('orders_ok', "CSV BAD HEADER datemonth={$datemonth} page={$page} (missing order/vmc cols) header=" . json_encode($header, JSON_UNESCAPED_UNICODE), "WARNING");
            break;
        }

        $stats['pages_fetched']++;

        $pageOldestTs = 0;
        $pageHasTs    = false;

        // Iterate data rows
        for ($i = 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            if (!is_array($r) || count($r) < 2) { $stats['bad_rows']++; continue; }

            $stats['rows_total']++;

            $orderNo = safe_s((string)($r[$colOrderNo] ?? ''));
            $vmcNo   = safe_s((string)($r[$colVmcNo] ?? ''));
            if ($orderNo === '' || $vmcNo === '') { $stats['bad_rows']++; continue; }

            $ts = 0;
            $tStr = '';
            if ($colTime !== null) {
                $tStr = safe_s((string)($r[$colTime] ?? ''));
                $ts = parse_ts($tStr);
                if ($ts > 0) {
                    $pageHasTs = true;
                    if ($pageOldestTs === 0 || $ts < $pageOldestTs) $pageOldestTs = $ts;
                }
            }

            // Filter to last 24h (if ts available)
            if ($ts > 0 && $ts < $startTs) {
                continue;
            }

            $stats['rows_in_window']++;

            // Paid only
            $priceRaw = ($colPrice !== null) ? safe_s((string)($r[$colPrice] ?? '')) : '';
            $priceVal = 0.0;
            if ($priceRaw !== '') {
                $priceVal = (float)str_replace([',',' TL','₺'], ['.','',''], $priceRaw);
            }
            if ($priceVal <= 0) continue;

            $stats['rows_paid']++;

            // compact row for json
            $prod = ($colProd !== null) ? safe_s((string)($r[$colProd] ?? '')) : '';
            $keptCompact[] = [
                'order_no' => $orderNo,
                'vmc_no'   => $vmcNo,
                'product'  => $prod,
                'price'    => $priceVal,
                'time'     => ($tStr !== '' ? $tStr : null),
                            'pay_type' => ($colPay !== null ? safe_s((string)($r[$colPay] ?? '')) : ''),
            ];

            // Dedup notify
            if (isset($state['orders_success_seen'][$accountKey][$orderNo])) {
                $stats['skipped_known']++;
                continue;
            }

            if (!$notifyOrders || $chatId === null || !function_exists('tg_notify')) {
                $stats['skipped_disabled'] = 1;
                $state['orders_success_seen'][$accountKey][$orderNo] = $nowTs;
                continue;
            }

            $loc = get_location((int)$userId, (string)$vmcNo);
            if ($loc === '') $loc = 'Unknown';


            $payType = '';
            if ($colPay !== null) $payType = safe_s((string)($r[$colPay] ?? ''));
            if ($payType === '') $payType = 'Unknown';
            $msg = "✅ NEW SALE | {$accName}\n".
                   "VMC: {$vmcNo} - {$loc}\n".
                   ($prod !== '' ? "{$prod}\n" : "").
                                      "Pay: {$payType}
".
                   "Price: " . number_format($priceVal, 2, '.', '') . " TL\n".
                   "Time: " . ($tStr !== '' ? $tStr : date('Y-m-d H:i:s'));

            tg_notify('orders', $msg, (string)$chatId, $accountKey . "|ok|" . $orderNo, $notifyState);
            $stats['sent']++;

            $state['orders_success_seen'][$accountKey][$orderNo] = $nowTs;
        }

        // Early stop: if oldest on this page is already older than window, no point going further pages.
        // Assumption: order listing is roughly in DESC time order (newest first).
        if ($pageHasTs && $pageOldestTs > 0 && $pageOldestTs < $startTs) {
            $stats['early_stop_pages']++;
            $stopThisMonth = true;
        }

        xhe_log('orders_ok', "CSV OK datemonth={$datemonth} page={$page} total_rows={$stats['rows_total']} paid={$stats['rows_paid']} early_stop=" . ($stopThisMonth ? '1' : '0'), "INFO");

        if ($stopThisMonth) break;

        // If fewer than perPage data rows, stop
        if ((count($rows) - 1) < $perPage) break;
    }

    if ($stopThisMonth) continue;
}

// Save JSON summary
$jsonPath = $outDir . "\\orders_success_{$userId}_{$stamp}.json";
@file_put_contents($jsonPath, json_encode([
    'account' => $accName,
    'user_id' => $userId,
    'generated_at' => date('c'),
    'source' => 'orders_csv_isOK1_export_1_last24h_paid_with_pay_type',
    'daterange' => $daterange,
    'window_start_ts' => $startTs,
    'window_end_ts' => $nowTs,
    'stats' => $stats,
    'paid_rows' => $keptCompact,
], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));

xhe_log('orders_ok', "DONE pages={$stats['pages_fetched']} rows_total={$stats['rows_total']} in_window={$stats['rows_in_window']} paid={$stats['rows_paid']} sent={$stats['sent']} skipped_known={$stats['skipped_known']} bad={$stats['bad_rows']} early_stop_pages={$stats['early_stop_pages']}", "INFO");