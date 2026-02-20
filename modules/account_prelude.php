<?php

// AUTO-GENERATED MODULE

// load cached VMC locations (built from errors/warnings export)
    $locState = vmc_locations_load((int)$userId);
    $locations = is_array($locState['map'] ?? null) ? $locState['map'] : [];


        xhe_log('error_scan', "ACCOUNT {$accName} user_id={$userId}", "INFO");

        $chatId = $acc['telegram_chat_id'] ?? ($CFG['telegram']['chat_id'] ?? null);