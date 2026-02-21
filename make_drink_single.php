<?php

$xhe_host = "127.0.0.1:7010";;

// init
require("../Templates/init.php");
$bUTF8Ver=true;
$PHP_Use_Trought_Shell=true;

/**
 * Сделать напиток по drink_id на vmc.
 *
 * Возврат:
 *  [
 *    'ok' => bool,
 *    'status' => 'success'|'vmc_invalid'|'drink_not_found'|'ui_error',
 *    'message' => string,
 *    'vmc' => string,
 *    'drink_id' => string,
 *    'matched_span_index' => int|null
 *  ]
 */
function make_drink_universal($vmc, $drink_id)
{
    global $browser, $image, $span, $btn, $input;

    $vmc = trim((string)$vmc);
    $drink_id = trim((string)$drink_id);

    if (!preg_match('/^\d{4,10}$/', $vmc)) {
        return ['status' => 'vmc_invalid'];
    }

    try {

        $browser->close_all_tabs();
        $browser->navigate("https://saas-hk.jetinno.com/device_info?vmc_no={$vmc}&admin=");
        $browser->wait(3);

        $image->click_by_src("https://saas-hk.jetinno.com/home/images/control.png", false);
        $browser->wait(3);

        $image->click_by_src("https://saas-hk.jetinno.com/home/images/products.png", false);
        $browser->wait(3);

        $span->click_by_number(398);
        $browser->wait(3);

     //   $span_count = $span->get_count("-1");

        for ($i = 400; $i < 450; $i++) {

            $span_text = trim($span->get_inner_text_by_number($i));

            if (strpos($span_text, $drink_id) === 0) {

                $span->click_by_number($i);
                $browser->wait(4);

                $btn->click_by_number(42);
                $browser->wait(4);

                $input->set_value_by_number(40, $vmc);
                $browser->wait(4);

                $btn->click_by_number(55);
                $browser->wait(4);

                return ['status' => 'success'];
            }
        }

        return ['status' => 'drink_not_found'];

    } catch (Throwable $e) {
        return ['status' => 'ui_error'];
    }
}
$result = make_drink_universal("81941", "1245");
echo $result['status'];
?>