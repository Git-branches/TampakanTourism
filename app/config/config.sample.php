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

    // -------------------------------------------------------------------------
    // Gemini — the AI half of the visitor assistant.
    //
    // NEVER put the real key in this file. This is the committed template; the
    // copy at config.php is git-ignored, and even there the key should come
    // from the environment rather than be typed in:
    //
    //   Apache / cPanel:  SetEnv GEMINI_API_KEY "..."   in the vhost
    //   CLI / systemd:    export GEMINI_API_KEY="..."
    //
    // Left empty, the assistant answers from the database alone. That is a
    // supported state, not a broken one — every factual question about hours,
    // fees, facilities and closures is answered without Gemini anyway.
    // -------------------------------------------------------------------------
    'gemini' => [
        'api_key'           => getenv('GEMINI_API_KEY') ?: '',
        'model'             => getenv('GEMINI_MODEL') ?: 'gemini-flash-lite-latest',
        'max_output_tokens' => 320,
        'timeout'           => 18,
        'per_hour'          => 20,

        // Pin outbound calls to IPv4. On hosts that advertise IPv6 without a
        // working route, curl stalls on it first and every question times out.
        // Set false only on an IPv6-only network.
        'ipv4_only' => true,
    ],
];
