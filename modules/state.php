<?php

// AUTO-GENERATED MODULE from all_in_one_2_0_refactored.php
// DO NOT EDIT LOGIC HERE unless you know what you're doing.

// state: main
    $mainStateFile = function_exists('state_path') ? state_path('main') : (runtime_base_dir() . "\\state\\jetinno_state.json");
    $state = function_exists('state_load') ? state_load($mainStateFile, [
        'errors_notify' => [],
        'errors_notify_ser' => [],
        'supplies_alert' => [],
        'orders_fail_notify' => [],
    ]) : [
        'errors_notify' => [],
        'errors_notify_ser' => [],
        'supplies_alert' => [],
        'orders_fail_notify' => [],
    ];

    if (!isset($state['errors_notify']) || !is_array($state['errors_notify'])) $state['errors_notify'] = [];
    if (!isset($state['errors_notify_ser']) || !is_array($state['errors_notify_ser'])) $state['errors_notify_ser'] = [];
    if (!isset($state['supplies_alert']) || !is_array($state['supplies_alert'])) $state['supplies_alert'] = [];
    if (!isset($state['orders_fail_notify']) || !is_array($state['orders_fail_notify'])) $state['orders_fail_notify'] = [];

    $notifyState = null; // tg_notify will manage state internally via state_path('notify') if implemented

    $stamp = date("Ymd_His");
    $locations = $CFG['locations'] ?? [];
    $supplyThresholds = supply_thresholds_old();
    $supplyCooldown = 3600;

    $accCount = 0;
