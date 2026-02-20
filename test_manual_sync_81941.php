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

function browser_js_exec($browser, string $js): void {
    // В вашей версии XHEBrowser нет execute_js(), но есть run_java_script / call_java_script / run_jquery.
    if (method_exists($browser, 'run_java_script')) {
        $browser->run_java_script($js);
        return;
    }
    if (method_exists($browser, 'call_java_script')) {
        // call_java_script требует 2 аргумента (script, result_var_name)
        $browser->call_java_script($js, ''); // result нам не нужен
        return;
    }
    if (method_exists($browser, 'run_jquery')) {
        $browser->run_jquery($js);
        return;
    }
    throw new Exception('No JS exec method found on browser');
}

function js_escape(string $s): string {
    // безопасно для вставки в одинарные кавычки JS
    return str_replace(["\\", "'"], ["\\\\", "\\'"], $s);
}

// -------------------- MAIN --------------------
$browser = new XHEBrowser;
$browser->enable_java_script(true);
$browser->enable_images(true);

$url = "https://saas-hk.jetinno.com/device_info?vmc_no={$vmc}&admin=";

logi('make', "START vmc={$vmc} product_id={$productId}");
logi('make', "OPEN device_info: $url");
$browser->navigate($url);
$browser->wait(5);

// 1) Показать remote-control modal (#control_list)
logi('make', "OPEN remote modal (#control_list)");
browser_js_exec($browser, <<<JS
try {
  if (window.jQuery) {
    // на всякий: если модалка скрыта
    jQuery('#control_list').modal('show');
  }
} catch(e) {}
JS);
$browser->wait(1);

// 2) Открыть Product Making (это tile внутри control_list, который ставит remote.routes='products' и открывает #product_select)
// В HTML tile выглядит как data-target="#product_select" data-toggle="modal" onclick="remote.routes='products'; ..." :contentReference[oaicite:2]{index=2}
logi('make', "OPEN Product Making (product_select) + set routes=products");
browser_js_exec($browser, <<<JS
try {
  if (window.jQuery) {
    // имитируем onclick плитки "Product Making"
    if (window.remote) remote.routes = 'products';

    jQuery('#product_select .modal-title').text('Product Making');
    jQuery('#product_select .product_id').removeClass('hidden');
    jQuery('#product_select .product_ids, #product_select .price').addClass('hidden');

    jQuery('#product_select').modal('show');
  }
} catch(e) {}
JS);
$browser->wait(1);

// 3) Выбрать напиток (selectpicker). Делаем и обычный val+change, и selectpicker('val', ...)
logi('make', "SET product_id={$productId}");
$p = js_escape($productId);
browser_js_exec($browser, <<<JS
try {
  if (window.jQuery) {
    var \$sel = jQuery('#product_select select[name="product_id"]');
    if (\$sel.length) {
      \$sel.val('{$p}').trigger('change');
      // если bootstrap-select (selectpicker) подключен:
      if (typeof \$sel.selectpicker === 'function') {
        \$sel.selectpicker('val', '{$p}');
        \$sel.trigger('changed.bs.select');
      }
    }

    // на всякий — remote.product_id тоже проставим
    if (window.remote) remote.product_id = '{$p}';
  }
} catch(e) {}
JS);
$browser->wait(1);

// 4) Нажать Submit в product_select так, чтобы открылся #key (по логике страницы кнопка обычно data-target="#key")
logi('make', "CLICK confirm (open #key)");
browser_js_exec($browser, <<<JS
try {
  if (window.jQuery) {
    // сначала попробуем клик по кнопке Submit в product_select
    var \$btn = jQuery('#product_select .modal-footer .btn.btn-primary');
    if (\$btn.length) {
      \$btn.first().trigger('click');
    } else {
      // fallback: просто открыть модалку key
      jQuery('#key').modal('show');
    }
  }
} catch(e) {}
JS);
$browser->wait(1);

// 5) Ввести пароль/ключ и нажать Submit в #key
logi('make', "ENTER password/key");
$k = js_escape($remoteKey);
browser_js_exec($browser, <<<JS
try {
  if (window.jQuery) {
    var \$inp = jQuery('#key input[name="key"], #key input[type="password"]');
    if (\$inp.length) {
      \$inp.first().val('{$k}').trigger('input').trigger('change');
    }
    if (window.remote) remote.key = '{$k}';
  }
} catch(e) {}
JS);
$browser->wait(1);

logi('make', "SUBMIT (#key)");
browser_js_exec($browser, <<<JS
try {
  if (window.jQuery) {
    // кликаем по submit-кнопке в key-модалке (чтобы отработал onclick и AjaxRequest)
    var \$btn = jQuery('#key .modal-footer .btn.btn-primary');
    if (\$btn.length) {
      \$btn.first().trigger('click');
    } else {
      // fallback: ищем кнопку по тексту
      jQuery('#key button').filter(function(){ 
        return (jQuery(this).text() || '').toLowerCase().indexOf('submit') >= 0; 
      }).first().trigger('click');
    }
  }
} catch(e) {}
JS);

$browser->wait(3);
logi('make', "DONE (если команда ушла, появится запись в Remote operation/record_control спустя время; иногда нужно refresh и подождать)");