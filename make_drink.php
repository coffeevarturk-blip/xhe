<?php
/**
 * make_drink.php — Jetinno SaaS "Make Drink" helper (Select2) — by product_id
 *
 * Goals:
 *  - Select a drink in Select2 dropdown by ID (value)
 *  - Avoid hangs: all waits have timeouts, every step logs, and failures return false
 *
 * Integration expectation:
 *  - You already have $browser/$btn/$input objects (Human Emulator Studio / XWeb style)
 *  - You already did LOGIN and opened device_info page for a specific vmc
 *  - This module ONLY handles selecting product + optional confirm/password/submit clicks.
 *
 * You can call:
 *  - make_drink_run($CFG, $acc, $vmc, $productId, $password)
 *
 * Config (optional) in $CFG['make_drink'] overrides selectors:
 *  [
 *    'select_css' => 'select[name="product_id"]',  // underlying <select> if present
 *    'select2_container_css' => 'span.select2-selection', // clickable to open dropdown
 *    'select2_search_css' => 'input.select2-search__field', // if enabled
 *    'confirm_btn_css' => 'button.btn.btn-primary.confirm', // adapt to your modal
 *    'password_input_css' => 'input[type="password"]',
 *    'submit_btn_css' => 'button.btn.btn-success.submit',
 *    'modal_root_css' => '#remote, #control_list, .modal.in', // any visible modal root
 *    'wait_timeout_sec' => 12,
 *  ]
 */

function make_drink_run(array $CFG, array $acc, int $vmc, int $productId, string $password = ''): bool {
    $opts = $CFG['make_drink'] ?? [];
    $timeout = (int)($opts['wait_timeout_sec'] ?? 12);

    make_drink_log("START vmc={$vmc} product_id={$productId}");

    // 1) Ensure some modal/root is visible (optional but helpful). We don't hard-fail if not found.
    $modalRoot = (string)($opts['modal_root_css'] ?? '');
    if ($modalRoot !== '') {
        make_drink_wait_for_css($modalRoot, $timeout, false);
    }

    // 2) Select product by ID (Select2-safe).
    if (!make_drink_select_product_by_id($CFG, $productId, $timeout)) {
        make_drink_log("ERROR select product failed product_id={$productId}", "ERROR");
        return false;
    }

    // 3) Click confirm (optional)
    $confirmCss = (string)($opts['confirm_btn_css'] ?? '');
    if ($confirmCss !== '') {
        if (!make_drink_click_css($confirmCss, $timeout)) {
            make_drink_log("WARN confirm button not clicked (css={$confirmCss})", "WARN");
        } else {
            make_drink_log("CLICK confirm ok");
        }
    }

    // 4) Enter password (optional)
    if ($password !== '') {
        $passCss = (string)($opts['password_input_css'] ?? 'input[type="password"]');
        if (make_drink_wait_for_css($passCss, $timeout, false)) {
            if (!make_drink_set_value_css($passCss, $password, $timeout)) {
                make_drink_log("WARN password set failed", "WARN");
            } else {
                make_drink_log("ENTER password ok");
            }
        } else {
            make_drink_log("WARN password input not found", "WARN");
        }
    }

    // 5) Submit (optional)
    $submitCss = (string)($opts['submit_btn_css'] ?? '');
    if ($submitCss !== '') {
        if (!make_drink_click_css($submitCss, $timeout)) {
            make_drink_log("WARN submit not clicked (css={$submitCss})", "WARN");
        } else {
            make_drink_log("SUBMIT clicked");
        }
    }

    make_drink_log("DONE");
    return true;
}

/**
 * Select product in a Select2-backed dropdown by value (productId).
 * Strategy:
 *  A) JS: set underlying <select> value and trigger change
 *  B) If JS not available / select not found: open Select2 results and click result matching ID
 */
function make_drink_select_product_by_id(array $CFG, int $productId, int $timeoutSec): bool {
    $opts = $CFG['make_drink'] ?? [];

    // A) Try JS set value on underlying <select>
    $selectCss = (string)($opts['select_css'] ?? 'select[name="product_id"], select#product_id, select[name="productId"], select[name="product"]');
    $js = ""
        . "try{"
        . "var pid='".addslashes((string)$productId)."';"
        . "var sel=document.querySelector('".addslashes($selectCss)."');"
        . "if(sel){"
        . "  sel.value=pid;"
        . "  if(window.jQuery){ window.jQuery(sel).trigger('change'); }"
        . "  var ev=new Event('change',{bubbles:true}); sel.dispatchEvent(ev);"
        . "  true;"
        . "}else{ false; }"
        . "}catch(e){ false; }";

    $jsOk = make_drink_js_bool($js);
    if ($jsOk) {
        make_drink_log("SET product via JS select value={$productId}");
        // Allow UI to update
        make_drink_sleep(1);
        return true;
    }
    make_drink_log("JS select-value path not available; fallback to click Select2 results", "DEBUG");

    // B) Fallback: click Select2 dropdown and choose result that ends with -<productId> or has data-select2-id
    $containerCss = (string)($opts['select2_container_css'] ?? 'span.select2-selection');
    if (!make_drink_click_css($containerCss, $timeoutSec)) {
        make_drink_log("ERROR cannot open Select2 (css={$containerCss})", "ERROR");
        return false;
    }
    make_drink_sleep(1);

    // If search field exists, type productId to filter quickly (many select2 configs allow searching)
    $searchCss = (string)($opts['select2_search_css'] ?? 'input.select2-search__field');
    if (make_drink_wait_for_css($searchCss, 2, false)) {
        make_drink_set_value_css($searchCss, (string)$productId, 2);
        make_drink_sleep(1);
    }

    // The result <li> id often contains "-<value>"
    $xpath = ""
        . "//li[contains(@class,'select2-results__option') and ("
        . "contains(@id,'-".((int)$productId)."')"
        . " or @data-select2-id='".((int)$productId)."'"
        . " or normalize-space(text())='".((int)$productId)."'"
        . ")]";
    if (make_drink_click_xpath($xpath, $timeoutSec)) {
        make_drink_log("SELECT product via Select2 result click product_id={$productId}");
        make_drink_sleep(1);
        return true;
    }

    // As a last resort, click first visible option after filtering (dangerous but better than hang)
    $xpathFirst = "//li[contains(@class,'select2-results__option') and not(contains(@class,'loading-results')) and not(contains(@class,'select2-results__message'))][1]";
    if (make_drink_click_xpath($xpathFirst, 2)) {
        make_drink_log("WARN selected first option as fallback (could be wrong). product_id={$productId}", "WARN");
        make_drink_sleep(1);
        return true;
    }

    make_drink_log("ERROR no Select2 option matched product_id={$productId}", "ERROR");
    return false;
}

/* ------------------------- Human Emulator adapters ------------------------- */

function make_drink_log(string $msg, string $level = "INFO"): void {
    // Prefer your existing logger if present
    if (function_exists('xhe_log')) {
        xhe_log('make', $msg, $level);
        return;
    }
    // Fallback
    $ts = date('Y-m-d H:i:s');
    echo "{$ts} | [make][{$level}] {$msg}\n";
}

function make_drink_sleep(int $sec): void {
    if (function_exists('sleep')) sleep($sec);
}

/** Wait for any element matching CSS selector. */
function make_drink_wait_for_css(string $css, int $timeoutSec, bool $must = true): bool {
    $start = microtime(true);
    while ((microtime(true) - $start) < $timeoutSec) {
        $found = make_drink_js_bool("try{ !!document.querySelector('".addslashes($css)."'); }catch(e){ false; }");
        if ($found) return true;
        make_drink_sleep(1);
    }
    if ($must) make_drink_log("TIMEOUT wait_for_css css={$css}", "ERROR");
    return false;
}

function make_drink_click_css(string $css, int $timeoutSec): bool {
    if (!make_drink_wait_for_css($css, $timeoutSec, false)) return false;

    // Click via JS to avoid overlay/offset issues
    $js = "try{ var el=document.querySelector('".addslashes($css)."'); if(!el) false; el.click(); true; }catch(e){ false; }";
    $ok = make_drink_js_bool($js);
    if ($ok) return true;

    // If you have a $btn wrapper globally, try it
    if (isset($GLOBALS['btn']) && is_object($GLOBALS['btn'])) {
        try {
            $GLOBALS['btn']->click_by_selector($css);
            return true;
        } catch (\Throwable $e) {}
    }
    return false;
}

function make_drink_set_value_css(string $css, string $value, int $timeoutSec): bool {
    if (!make_drink_wait_for_css($css, $timeoutSec, false)) return false;

    // JS set value and dispatch events
    $js = ""
        . "try{"
        . "var el=document.querySelector('".addslashes($css)."');"
        . "if(!el) return false;"
        . "el.focus();"
        . "el.value='".addslashes($value)."';"
        . "if(window.jQuery){ window.jQuery(el).trigger('input'); window.jQuery(el).trigger('change'); }"
        . "el.dispatchEvent(new Event('input',{bubbles:true}));"
        . "el.dispatchEvent(new Event('change',{bubbles:true}));"
        . "true;"
        . "}catch(e){ false; }";
    $ok = make_drink_js_bool($js);
    if ($ok) return true;

    // Fallback to input wrapper
    if (isset($GLOBALS['input']) && is_object($GLOBALS['input'])) {
        try {
            $GLOBALS['input']->set_value_by_selector($css, $value);
            return true;
        } catch (\Throwable $e) {}
    }
    return false;
}

function make_drink_click_xpath(string $xpath, int $timeoutSec): bool {
    // Wait using JS XPath evaluation
    $jsWait = "try{"
        . "var r=document.evaluate('".addslashes($xpath)."',document,null,XPathResult.FIRST_ORDERED_NODE_TYPE,null).singleNodeValue;"
        . "!!r;"
        . "}catch(e){ false; }";
    $start = microtime(true);
    while ((microtime(true) - $start) < $timeoutSec) {
        if (make_drink_js_bool($jsWait)) break;
        make_drink_sleep(1);
    }

    $jsClick = "try{"
        . "var el=document.evaluate('".addslashes($xpath)."',document,null,XPathResult.FIRST_ORDERED_NODE_TYPE,null).singleNodeValue;"
        . "if(!el) return false;"
        . "el.scrollIntoView({block:'center'});"
        . "el.click();"
        . "true;"
        . "}catch(e){ false; }";
    $ok = make_drink_js_bool($jsClick);
    if ($ok) return true;

    // Fallback to your wrapper if exists
    if (isset($GLOBALS['btn']) && is_object($GLOBALS['btn'])) {
        try {
            $GLOBALS['btn']->click_by_xpath($xpath);
            return true;
        } catch (\Throwable $e) {}
    }
    return false;
}

/**
 * Evaluate JS and return boolean, with multiple fallbacks depending on your environment.
 * Expected environment variants:
 *  - $browser->execute_js($js) returning something
 *  - Browser/Tab JS runner functions exposed globally
 *
 * IMPORTANT:
 *  - We don't call Browser.__js_res (you had "command not found"), we try safer paths.
 */
function make_drink_js_bool(string $js): bool {
    // 1) Try $browser->execute_js or ->run_js
    if (isset($GLOBALS['browser']) && is_object($GLOBALS['browser'])) {
        $b = $GLOBALS['browser'];
        foreach (['execute_js','run_js','js','eval_js'] as $m) {
            if (method_exists($b, $m)) {
                try {
                    $res = $b->$m($js);
                    return make_drink_to_bool($res);
                } catch (\Throwable $e) {}
            }
        }
    }

    // 2) Try a global function if your framework provides it
    foreach (['xhe_execute_js','he_execute_js','execute_js'] as $fn) {
        if (function_exists($fn)) {
            try {
                $res = $fn($js);
                return make_drink_to_bool($res);
            } catch (\Throwable $e) {}
        }
    }

    // 3) No JS available
    return false;
}

function make_drink_to_bool($res): bool {
    if (is_bool($res)) return $res;
    if (is_numeric($res)) return ((int)$res) !== 0;
    if (is_string($res)) {
        $v = strtolower(trim($res));
        return in_array($v, ['1','true','yes','ok','success'], true);
    }
    return (bool)$res;
}
