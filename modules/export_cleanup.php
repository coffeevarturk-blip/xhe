<?php

function export_cleanup_run(array $CFG = [])
{
    $baseDir = 'C:/jetinno_runtime/export';
    if (!is_dir($baseDir)) {
        xhe_log('cleanup_export', 'SKIP: export dir not found: ' . $baseDir, 'WARNING');
        return;
    }

    // сроки хранения (в днях)
    $keepDays = [
        'device' => 2,
        'errors' => 3,
        'supply' => 3,
        'sales'  => 7,
    ];

    $groups = [
        'device' => [],
        'errors' => [],
        'supply' => [],
        'sales'  => [],
    ];

    $files = glob($baseDir . '/*');
    if (!$files) {
        xhe_log('cleanup_export', 'DONE: no files', 'INFO');
        return;
    }

    // группировка файлов по типам
    foreach ($files as $file) {
        if (!is_file($file)) continue;

        $name = basename($file);

        foreach ($groups as $type => $_) {
            if (preg_match('/^' . $type . '_\d+_\d{8}_\d{6}\.(csv|json)$/i', $name)) {
                $groups[$type][] = $file;
                break;
            }
        }
    }

    $now = time();
    $today = date('Ymd');

    $deleted = 0;
    $kept = 0;

    foreach ($groups as $type => $typeFiles) {

        if (empty($typeFiles)) continue;

        // сортировка: новые сверху
        usort($typeFiles, function($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        // всегда оставляем самый свежий
        $protected = [$typeFiles[0] => true];

        // оставляем все файлы за сегодня
        foreach ($typeFiles as $file) {
            if (preg_match('/_(\d{8})_\d{6}\./', basename($file), $m)) {
                if ($m[1] === $today) {
                    $protected[$file] = true;
                }
            }
        }

        $maxAge = ($keepDays[$type] ?? 3) * 86400;

        foreach ($typeFiles as $file) {

            if (isset($protected[$file])) {
                $kept++;
                continue;
            }

            $age = $now - filemtime($file);

            if ($age < $maxAge) {
                $kept++;
                continue;
            }

            if (@unlink($file)) {
                $deleted++;
                xhe_log('cleanup_export', "DELETE {$type} " . basename($file), 'INFO');
            } else {
                xhe_log('cleanup_export', "FAIL DELETE " . basename($file), 'WARNING');
            }
        }
    }

    xhe_log('cleanup_export', "DONE deleted={$deleted} kept={$kept}", 'INFO');
}