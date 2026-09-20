<?php
declare(strict_types=1);

/**
 * =============================================================================
 *  TourSync — database installer
 * -----------------------------------------------------------------------------
 *  Creates the database, applies schema.sql, seeds reference data, and creates
 *  the first Tourism Officer account.
 *
 *  Run from the project root:
 *
 *      php database/install.php
 *
 *  This is a command-line script by design. It refuses to run over HTTP, so
 *  it cannot be triggered by anyone who finds the URL.
 * =============================================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('The installer runs from the command line only.');
}

$root = dirname(__DIR__);
$configFile = $root . '/app/config/config.php';

if (!is_file($configFile)) {
    exit("Missing app/config/config.php. Copy config.sample.php first.\n");
}

$config = require $configFile;
$db = $config['database'];

$line = str_repeat('=', 70);
echo "\n{$line}\n  TourSync — Database Installer\n{$line}\n\n";

// -----------------------------------------------------------------------------
// 1. Connect to the server (no database selected yet) and create it
// -----------------------------------------------------------------------------
try {
    $pdo = new PDO(
        "mysql:host={$db['host']};port={$db['port']};charset={$db['charset']}",
        $db['user'],
        $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    exit("  FAILED to reach MySQL at {$db['host']}:{$db['port']}\n  " . $e->getMessage() . "\n\n");
}

echo "  Connected to MySQL " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";

$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db['name']}`
            CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "  Database `{$db['name']}` ready\n";

// -----------------------------------------------------------------------------
// 1b. IS THERE ALREADY A SYSTEM HERE?
//
//     schema.sql begins with DROP TABLE for all 42 tables, so this script run
//     against a database in service destroys every arrival, report, photograph
//     and account in it — with no question asked and nothing to undo. It is one
//     mistyped command away from the office's records at any time, and it
//     becomes likelier the moment there is a production server to run it on.
//
//     So: the installer looks first. If the database holds a system with any
//     records in it, it refuses and says what to run instead. --force is there
//     for the one legitimate case (rebuilding a scratch copy), and it still
//     prints what is about to be lost.
// -----------------------------------------------------------------------------
$pdo->exec("USE `{$db['name']}`");

$existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$rows     = 0;

foreach (['admins', 'destinations', 'tourist_arrivals', 'arrival_reports', 'destination_managers'] as $table) {
    if (in_array($table, $existing, true)) {
        $rows += (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    }
}

$forced = in_array('--force', $argv, true);

if ($rows > 0 && !$forced) {
    echo "\n  STOPPED. `{$db['name']}` already holds a TourSync system:\n";

    foreach (['destinations', 'tourist_arrivals', 'arrival_reports', 'destination_managers', 'admins'] as $table) {
        if (in_array($table, $existing, true)) {
            printf("      %-22s %s row(s)\n", $table, number_format((int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn()));
        }
    }

    echo "\n  This installer applies schema.sql, which DROPS every table first.\n";
    echo "  Running it here would destroy all of the above.\n\n";
    echo "  To upgrade an existing database instead:  php database/migrate.php\n";
    echo "  To wipe this database on purpose:         php database/install.php --force\n\n";
    exit(1);
}

if ($rows > 0 && $forced) {
    echo "\n  --force given: {$rows} existing record(s) in `{$db['name']}` will be DESTROYED.\n\n";
}

$pdo->exec("USE `{$db['name']}`");

// -----------------------------------------------------------------------------
// 2. Apply the schema
// -----------------------------------------------------------------------------
$schema = file_get_contents(__DIR__ . '/schema.sql');
if ($schema === false) {
    exit("  FAILED to read schema.sql\n\n");
}

echo "  Applying schema";

try {
    $pdo->exec($schema);
} catch (PDOException $e) {
    exit("\n  FAILED applying schema:\n  " . $e->getMessage() . "\n\n");
}

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo " — " . count($tables) . " tables created\n";

foreach ($tables as $t) {
    echo "      · {$t}\n";
}

// -----------------------------------------------------------------------------
// 3. Seed reference data
// -----------------------------------------------------------------------------
echo "\n  Seeding reference data\n";

$categories = [
    ['Nature',        'nature',        'fa-leaf',            1],
    ['Waterfalls',    'waterfalls',    'fa-water',           2],
    ['Adventure',     'adventure',     'fa-person-hiking',   3],
    ['Culture',       'culture',       'fa-drum',            4],
    ['Eco-Tourism',   'eco-tourism',   'fa-seedling',        5],
    ['Agri-Tourism',  'agri-tourism',  'fa-tractor',         6],
    ['Historical',    'historical',    'fa-landmark',        7],
];

$stmt = $pdo->prepare('INSERT INTO categories (name, slug, icon, sort_order) VALUES (?, ?, ?, ?)');
foreach ($categories as $c) {
    $stmt->execute($c);
}
echo "      · " . count($categories) . " destination categories\n";

$settings = [
    'office_name'         => $config['office']['name'],
    'office_municipality' => $config['office']['municipality'],
    'office_province'     => $config['office']['province'],
    'office_address'      => $config['office']['address'],
    'office_phone'        => $config['office']['phone'],
    'office_email'        => $config['office']['email'],
    'retention_months'    => '36',
    'dedupe_window_hours' => '6',
    'rate_limit_per_15m'  => '10',
    'proximity_metres'    => '500',
];

$stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
foreach ($settings as $k => $v) {
    $stmt->execute([$k, $v]);
}
echo "      · " . count($settings) . " settings\n";

// -----------------------------------------------------------------------------
// 4. First Tourism Officer account
//    The password is generated, printed once, and never stored in plain text.
// -----------------------------------------------------------------------------
echo "\n  Creating the first Tourism Officer account\n";

$username = 'officer';
$email    = $config['office']['email'] ?: 'tourism@tampakan.gov.ph';

// Readable but not guessable: two words plus digits beats a random blob the
// user will write on a sticky note anyway.
$password = 'Tampakan' . random_int(1000, 9999) . '!';

$pdo->prepare(
    'INSERT INTO admins (full_name, username, email, password_hash, role, is_active)
     VALUES (?, ?, ?, ?, ?, 1)'
)->execute([
    'Municipal Tourism Officer',
    $username,
    $email,
    password_hash($password, PASSWORD_ARGON2ID),
    'officer',
]);

echo "\n{$line}\n";
echo "  INSTALLATION COMPLETE\n";
echo "{$line}\n\n";
echo "  Sign in at: " . rtrim($config['base_url'], '/') . "/admin/login.php\n\n";
echo "      Username:  {$username}\n";
echo "      Password:  {$password}\n\n";
echo "  This password is shown once and is not recoverable. Write it down,\n";
echo "  then change it after your first sign-in.\n\n";
echo "{$line}\n\n";
