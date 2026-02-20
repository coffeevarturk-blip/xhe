<?php

/**
 * Парсим страницу Supply List.
 * Делает устойчивое определение device_id даже если есть колонка "№".
 */
function parseSupplyListHtml(string $html): array
{
    $rows = [];

    if (!preg_match_all('/<tr[^>]*>(.*?)<\/tr>/si', $html, $m)) {
        return [];
    }

    foreach ($m[1] as $trHtml) {

        // 1) Вытаскиваем td
        preg_match_all('/<td[^>]*>(.*?)<\/td>/si', $trHtml, $tds);
        if (empty($tds[1]) || count($tds[1]) < 3) {
            continue;
        }

        $cells = array_map(fn($x) => cleanText($x), $tds[1]);

        // 2) Пытаемся определить device_id
        $device_id = '';

        // Страховка: часто в supply-ячейках есть data-title="81939【10007 - ...】"
        if (preg_match('/data-title="(\d{3,10})【/u', $trHtml, $mm)) {
            $device_id = $mm[1];
        } else {
            // Логика по колонкам:
            // если 1-я колонка маленькая (№), а 2-я похожа на device_id (>=4 цифры) — берём 2-ю
            $c0 = $cells[0] ?? '';
            $c1 = $cells[1] ?? '';

            if (ctype_digit($c0) && strlen($c0) <= 3 && ctype_digit($c1) && strlen($c1) >= 4) {
                $device_id = $c1;
            } else {
                // иначе: ищем первую ячейку, которая выглядит как ID устройства (>=4 цифры)
                foreach ($cells as $c) {
                    if (ctype_digit($c) && strlen($c) >= 4) {
                        $device_id = $c;
                        break;
                    }
                }
            }
        }

        if ($device_id === '' || !ctype_digit($device_id)) {
            continue;
        }

        // 3) address и upload_time
        // Попытаемся определить upload_time по формату даты, а address — ближайшую “текстовую” колонку
        $uploadTime = '';
        foreach ($cells as $c) {
            if (preg_match('~\b20\d{2}-\d{2}-\d{2}\b~', $c)) {
                $uploadTime = $c;
                break;
            }
        }

        // address: возьмём первую ячейку после device_id, которая не является числом и не является датой
        $address = '';
        $deviceIndex = array_search($device_id, $cells, true);
        if ($deviceIndex === false) $deviceIndex = 0;

        for ($i = $deviceIndex + 1; $i < count($cells); $i++) {
            $c = $cells[$i];
            if ($c === '' || ctype_digit($c)) continue;
            if (preg_match('~\b20\d{2}-\d{2}-\d{2}\b~', $c)) continue;
            $address = $c;
            break;
        }

        // 4) supplies
        $supplies = [];

        // data-title="81935【10003 - Tea】">694</a>
        if (preg_match_all('/data-title="[^"]*【(\d+)\s*-\s*([^】]+)】"[^>]*>\s*([^<]+)/u', $trHtml, $sm, PREG_SET_ORDER)) {
            foreach ($sm as $one) {
                $sid  = (int)($one[1] ?? 0);
                $name = cleanText((string)($one[2] ?? ''));
                $val  = (int)preg_replace('/[^\d]/', '', (string)($one[3] ?? '0'));

                if ($sid > 0 && $name !== '') {
                    $supplies[$sid] = [
                        'name'   => $name,
                        'value'  => $val,
                        'is_low' => false,
                    ];
                }
            }
        }

        $rows[] = [
            'device_id'   => $device_id,
            'address'     => $address,
            'upload_time' => $uploadTime,
            'supplies'    => $supplies,
        ];
    }

    return $rows;
}
