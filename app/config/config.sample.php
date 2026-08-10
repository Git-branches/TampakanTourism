<?php
/**
 * TourSync — configuration template.
 *
 * Copy this file to config.php and fill in the real values. config.php holds
 * live credentials and must never be committed to version control.
 */

return [

    // -------------------------------------------------------------------------
    // Environment: 'development' shows errors on screen, 'production' logs them.
    // -------------------------------------------------------------------------
    'env' => 'development',

    // -------------------------------------------------------------------------
    // Base URL with no trailing slash. Used to build QR code targets, so it
    // must match the address printed on the signage exactly.
    // -------------------------------------------------------------------------
    'base_url' => 'http://tampakantourism.test',

    'database' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'toursync',
        'user'     => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],

    'security' => [
        // Salt for device fingerprint hashing. Generate a fresh 64-character
        // random string per installation; changing it resets duplicate
        // detection but harms nothing else.
        'device_salt'      => 'CHANGE_ME_TO_A_LONG_RANDOM_STRING',

        // Failed logins allowed before the account locks.
        'max_login_attempts' => 5,
        'lockout_minutes'    => 15,

        // Session lifetimes in minutes.
        'session_idle'     => 30,
        'session_absolute' => 480,
    ],

    'office' => [
        'name'         => 'Municipal Tourism Office',
        'municipality' => 'Municipality of Tampakan',
        'province'     => 'South Cotabato',
        'address'      => 'Tampakan Municipal Hall, Kamagong St., Brgy. Poblacion, Tampakan, South Cotabato',
        'phone'        => '',
        'email'        => '',
    ],

    'sms' => [
        // 'log' writes messages to storage/logs instead of sending them —
        // use it for development and rehearsals so no credits are spent.
        'driver'    => 'log',
        'api_key'   => '',
        'sender_id' => 'TourSync',
    ],
];
