<?php
// include this AFTER you load $CFG from config.app.php/config.php
// This ensures filters are actually present in $CFG at runtime.
$CFG['notify']['filters'] = [
    'errors' => [
        'mode'  => 'deny',
        'codes' => ['7300'],
    ],
    'warnings' => [
        'mode'  => 'deny',
        'codes' => ['Z0050'],
    ],
];
