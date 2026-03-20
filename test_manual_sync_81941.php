<?php
// TEST: remote make for VMC 81941, Tea product_id=125
// Assumes you are already logged in (LOGIN OK) and $browser is ready.
// Uses JS clicks/select to avoid unstable "by_number" indexes.
require("../Templates/init.php");

// test_make_81941_tea_125.php
// XWeb Human Emulator Studio PHP

error_reporting(E_ALL);
ini_set('display_errors', '1');

$vmc = '81941';
$productId = '125';          // чай 125
$remoteKey = '81941';    // <-- ВАШ ПАРОЛЬ/КЛЮЧ УПРАВЛЕНИЯ

function logi(string $tag, string $msg): void {
    $ts = date('Y-m-d H:i:s');
    echo "$ts | [$tag] $msg\n";
}

function find_text_in_buttons($browser, string $searchText)
{
    $buttons = $browser->find_elements_by_tag_name("button");
    $count = count($buttons);

    xhe_log('btn_scan', "TOTAL_BUTTONS={$count}", "DEBUG");

    foreach ($buttons as $i => $btn) {

        $text = trim($btn->get_attribute("innerText"));

        if ($text === '') {
            $text = trim($btn->get_attribute("textContent"));
        }

        if ($text === '') {
            $text = trim($btn->get_attribute("value"));
        }

        if ($text === '') {
            continue;
        }

        if (stripos($text, $searchText) !== false) {

            $num = $i + 1;

            xhe_log(
                'btn_scan',
                "MATCH button={$num} text=\"{$text}\"",
                "INFO"
            );
        }
    }
}

find_text_in_buttons($browser, "Makin");