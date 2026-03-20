<?php
// modules/sales_summary.php
//
// build_sales_summary_text() - builds "SALES UPDATE" Telegram text for a given day.
// Aggregates PAID + SUCCEED orders from Jetinno /order CSV export=1.
//
// COMPATIBILITY (IMPORTANT):
// Some legacy callers (e.g. telegram_commands.php) call build_sales_summary_text($accName) with ONLY 1 argument.
// This version supports both:
//   A) build_sales_summary_text($accArray, $userId, $cookieStr, $accountKey, $targetDateYmd)
//   B) build_sales_summary_text($accNameOrAccArray)  -> tries to resolve missing args from $GLOBALS and CFG
// If it still can't resolve required args, it returns ok=false instead of fatal.
//
// Expected helpers in your project:
// - build_sales_orders_csv_url($userId, $datemonth, $daterange, $page, $perPage)
// - curl_get_with_cookies($url, $cookieStr, $headers, $referer)
// - parse_csv_rows($csvBody)
// - safe_s($s)
// - xhe_log($tag,$msg,$level) [optional]
// - get_location($userId,$vmcNo) OR getLocation($userId,$vmcNo) [optional]

if (!function_exists('build_sales_summary_text')) {

function build_sales_summary_text($acc = null, $userId = null, $cookieStr = null, $accountKey = null, $targetDateYmd = null): array {

    // ---- LEGACY: 1-arg call support ----
    // If only 1 arg passed, PHP will set others to null automatically.
    // Try to resolve from $GLOBALS.
    if ($userId === null || $cookieStr === null || $accountKey === null) {

        // Resolve CFG accounts if acc is string
        $cfg = $GLOBALS['CFG'] ?? null;
        if (!is_array($acc) && is_array($cfg) && is_array($cfg['accounts'] ?? null)) {
            $name = (string)$acc;
            foreach ($cfg['accounts'] as $a) {
                if (!is_array($a)) continue;
                $nm = (string)($a['name'] ?? '');
                if ($nm !== '' && $nm === $name) { $acc = $a; break; }
            }
        }

        // Pull common globals (depends on your AIO)
        if ($userId === null && isset($GLOBALS['userId'])) $userId = $GLOBALS['userId'];
        if ($cookieStr === null && isset($GLOBALS['cookieStr'])) $cookieStr = $GLOBALS['cookieStr'];
        if ($accountKey === null && isset($GLOBALS['accountKey'])) $accountKey = $GLOBALS['accountKey'];

        // If acc array exists, fill from it
        if ($accountKey === null && is_array($acc)) {
            $accountKey = (string)($acc['name'] ?? $acc['user_id'] ?? '');
        }
        if ($userId === null && is_array($acc) && isset($acc['user_id'])) $userId = (int)$acc['user_id'];

        // If still missing cookieStr, try last_cookieStr (some builds store it)
        if ($cookieStr === null && isset($GLOBALS['LAST_COOKIE_STR'])) $cookieStr = $GLOBALS['LAST_COOKIE_STR'];
    }

    if ($targetDateYmd === null || $targetDateYmd === '') $targetDateYmd = date('Y-m-d');

    // Validate required inputs. If missing, return safe error (no fatal).
    if (!is_array($acc)) {
        $acc = ['name' => (string)($acc ?? $accountKey ?? 'ACC'), 'telegram_notify' => []];
    }
    $accName = (string)($acc['name'] ?? 'ACC');

    if ($userId === null || $cookieStr === null || $accountKey === null) {
        if (function_exists('xhe_log')) {
            xhe_log('sales', "build_sales_summary_text SKIP missing args acc={$accName} userId=" . (string)$userId . " cookieLen=" . (string)(is_string($cookieStr)?strlen($cookieStr):0) . " accountKey=" . (string)$accountKey, 'WARNING');
        }
        return ['ok'=>false, 'text'=>'', 'stats'=>[], 'error'=>'missing_args_for_sales_summary'];
    }

    $userId = (int)$userId;
    $cookieStr = (string)$cookieStr;
    $accountKey = (string)$accountKey;
    $targetDateYmd = (string)$targetDateYmd;

    $perPage  = 300;
    $maxPages = 5;
    $datemonth = date('Y-m', strtotime($targetDateYmd));
    $daterange = $targetDateYmd . ' - ' . $targetDateYmd;

    $stats = [
        'pages_fetched' => 0,
        'rows_total'    => 0,
        'rows_day'      => 0,
        'rows_paid'     => 0,
        'bad_rows'      => 0,
    ];

    $byMachine = [];
    $totalSum = 0.0;
    $totalCnt = 0;

    $norm = function(string $s): string {
        $s = trim($s);
        if (function_exists('mb_strtolower')) $s = mb_strtolower($s, 'UTF-8');
        else $s = strtolower($s);
        $map = [
            'ı'=>'i','İ'=>'i','ş'=>'s','Ş'=>'s','ğ'=>'g','Ğ'=>'g','ü'=>'u','Ü'=>'u','ö'=>'o','Ö'=>'o','ç'=>'c','Ç'=>'c',
            'â'=>'a','Â'=>'a','ê'=>'e','Ê'=>'e','î'=>'i','Î'=>'i','ô'=>'o','Ô'=>'o','û'=>'u','Û'=>'u',
        ];
        $s = strtr($s, $map);
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
                if ($n !== '' && strpos($hn, $n) !== false) return $idx;
            }
        }
        return null;
    };

    if (!function_exists('build_sales_orders_csv_url') || !function_exists('curl_get_with_cookies') || !function_exists('parse_csv_rows') || !function_exists('safe_s')) {
        return ['ok'=>false, 'text'=>'', 'stats'=>$stats, 'error'=>'missing helpers (build_sales_orders_csv_url/curl_get_with_cookies/parse_csv_rows/safe_s)'];
    }

    for ($page = 1; $page <= $maxPages; $page++) {
        $url = build_sales_orders_csv_url($userId, $datemonth, $daterange, $page, $perPage);
        $res = curl_get_with_cookies($url, $cookieStr, ["Accept: text/csv,*/*"], "https://saas-hk.jetinno.com/order");

        $looksCsv = (($res['ok'] ?? false) && stripos((string)($res['ct'] ?? ''), 'text/csv') !== false);
        if (!$looksCsv || (int)($res['bytes'] ?? 0) < 5) {
            if (function_exists('xhe_log')) xhe_log('sales', "CSV FAILED datemonth={$datemonth} date={$targetDateYmd} page={$page} http=" . (string)($res['http'] ?? '') . " ct=" . (string)($res['ct'] ?? '') . " bytes=" . (string)($res['bytes'] ?? '') . " err=" . (string)($res['err'] ?? ''), "WARNING");
            break;
        }

        $rows = parse_csv_rows((string)($res['body'] ?? ''));
        if (count($rows) < 2) break;

        $header = $rows[0];
        if (!is_array($header) || count($header) < 2) break;

        $colVmcNo  = $find_col_ci($header, ['vmc no','vmc_no','vmc','makine','cihaz numarası','cihaz numarasi','cihaz no','cihaz','设备编号','设备号','device id']);
        $colPrice  = $find_col_ci($header, ['price','amount','tutar','fiyat','ürün fiyatı','urun fiyati','金额']);
        $colTime   = $find_col_ci($header, ['buy time','time','create time','created','oluşma','olusturma','sipariş zamanı','siparis zamani','satın alma zamanı','satin alma zamani','order time','时间','更新时间']);
        $colStatus = $find_col_ci($header, ['status','durum','状态']);

        if ($colVmcNo === null || $colPrice === null) break;

        $stats['pages_fetched']++;

        for ($i = 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            if (!is_array($r) || count($r) < 2) { $stats['bad_rows']++; continue; }
            $stats['rows_total']++;

            $vmcNo = safe_s((string)($r[$colVmcNo] ?? ''));
            if ($vmcNo === '') { $stats['bad_rows']++; continue; }

            if ($colStatus !== null) {
                $st = safe_s((string)($r[$colStatus] ?? ''));
                $stNorm = strtolower($st);
                if ($stNorm !== 'succeed' && $stNorm !== 'success' && $stNorm !== 'ok') continue;
            }

            if ($colTime !== null) {
                $tStr = safe_s((string)($r[$colTime] ?? ''));
                if ($tStr !== '') {
                    $ts = strtotime($tStr);
                    if ($ts !== false && $ts > 0) {
                        $ymd = date('Y-m-d', $ts);
                        if ($ymd !== $targetDateYmd) continue;
                    }
                }
            }

            $stats['rows_day']++;

            $priceRaw = safe_s((string)($r[$colPrice] ?? ''));
            $priceVal = 0.0;
            if ($priceRaw !== '') $priceVal = (float)str_replace([',',' TL','₺'], ['.','',''], $priceRaw);
            if ($priceVal <= 0) continue;

            $stats['rows_paid']++;
            $totalSum += $priceVal;
            $totalCnt += 1;

            if (!isset($byMachine[$vmcNo])) {
                $loc = '';
                if (function_exists('get_location')) $loc = get_location((int)$userId, (string)$vmcNo);
                else if (function_exists('getLocation')) $loc = getLocation((int)$userId, (string)$vmcNo);
                if ($loc === '') $loc = 'Unknown';
                $byMachine[$vmcNo] = ['sum'=>0.0, 'cnt'=>0, 'loc'=>$loc];
            }
            $byMachine[$vmcNo]['sum'] += $priceVal;
            $byMachine[$vmcNo]['cnt'] += 1;
        }

        if ((count($rows) - 1) < $perPage) break;
    }

    uasort($byMachine, function($a, $b) {
        if ($a['sum'] == $b['sum']) return $b['cnt'] <=> $a['cnt'];
        return ($b['sum'] <=> $a['sum']);
    });

    $topN = 10;
    $lines = [];
    $lines[] = "SALES UPDATE | {$accName}";
    $lines[] = "Today: {$targetDateYmd}";
    $lines[] = "Total: " . number_format($totalSum, 2, '.', '') . " TL ({$totalCnt})";
    $lines[] = "Top:";

    $k = 0;
    foreach ($byMachine as $vmc => $m) {
        $k++;
        $lines[] = "{$k}) {$vmc} - {$m['loc']} | " . number_format((float)$m['sum'], 2, '.', '') . " TL ({$m['cnt']})";
        if ($k >= $topN) break;
    }

    if ($totalCnt === 0) $lines[] = "— no paid sales for this day —";

    return ['ok'=>true, 'text'=>implode("\n", $lines), 'stats'=>$stats];
}

} // end if !exists
