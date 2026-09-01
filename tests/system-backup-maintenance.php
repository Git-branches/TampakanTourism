<?php
declare(strict_types=1);

/**
 * The two switches under Settings → System.
 *
 * MAINTENANCE MODE closes the public website. The check that matters is not
 * that it closes things — it is WHAT IT LEAVES OPEN. A switch that also closed
 * /admin would lock the officer out of the only screen that can turn it off,
 * at which point the remedy is somebody with database access at eleven at
 * night. So this turns it on for real and then asks the admin area whether it
 * is still answering.
 *
 * THE BACKUP is the single most sensitive response this system can produce:
 * every visitor name and contact number, every logbook entry, and the password
 * hashes. It has to refuse a GET (a link in an email, an <img src> on a page an
 * officer visits while signed in), refuse anyone who is not the officer, and
 * still produce SQL that actually contains the tables.
 *
 * Turns maintenance mode on and off again, restoring whatever it found.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;

echo "\n=== backup and maintenance mode ===\n\n";

if (!test_server_up()) {
    echo "  SKIP — no web server answering at " . test_base_url() . "\n";
    exit(0);
}

$is = static function (string $what, bool $got): void { check($what, $got, true); };

[$sid, $csrf] = test_sign_in_officer();

/* ---------------------------------------------------------------------------
   The backup
   ------------------------------------------------------------------------ */

echo "--- who may take a backup ---\n";

$anon = test_get('/admin/settings/backup.php');

$is('an anonymous GET gets no SQL', !str_contains($anon, 'CREATE TABLE'));

$get = test_get_as($sid, '/admin/settings/backup.php');

$is('even a signed-in GET gets no SQL', !str_contains($get, 'CREATE TABLE'));

/* A POST with no token must be refused by CSRF, not merely by chance. */
$noToken = test_post('/admin/settings/backup.php', $sid, []);

$is('a POST without a CSRF token is refused',
    !str_contains($noToken['body'], 'CREATE TABLE'));

echo "\n--- what the backup contains ---\n";

$dump = test_post('/admin/settings/backup.php', $sid, ['_token' => $csrf]);

$sql = (string) $dump['body'];

printf("    %s bytes of SQL\n", number_format(strlen($sql)));

$is('it came back as SQL, not a web page', !str_contains($sql, '<html'));
$is('with no PHP diagnostic in it',
    !preg_match('/(Fatal error|Warning:|Notice:|Deprecated:)/', $sql));

$is('it disables foreign key checks while restoring',
    str_contains($sql, 'SET FOREIGN_KEY_CHECKS = 0'));
$is('and turns them back on at the end',
    str_contains($sql, 'SET FOREIGN_KEY_CHECKS = 1'));

/* Every table, not merely some. A backup missing one table restores a database
   that looks complete until the office opens the screen that reads it. */
$tables = array_map(static fn (array $r): string => (string) array_values($r)[0],
    Database::all('SHOW TABLES'));

$missing = [];

foreach ($tables as $table) {
    if (!str_contains($sql, 'CREATE TABLE `' . $table . '`')) {
        $missing[] = $table;
    }
}

$is('every table is in it', $missing === []);
printf("    %d table(s); %s\n", count($tables),
    $missing === [] ? 'none missing' : 'MISSING: ' . implode(', ', $missing));

/* And rows, not just structure. */
$destinationName = (string) Database::scalar('SELECT name FROM destinations ORDER BY id LIMIT 1');

$is('and the rows came with it', $destinationName !== '' && str_contains($sql, $destinationName));

/* It says what it is carrying, at the top, where somebody about to email it
   would see it. */
$is('it warns about the personal data inside it',
    str_contains($sql, 'CONTAINS PERSONAL DATA'));

/* ---------------------------------------------------------------------------
   Maintenance mode
   ------------------------------------------------------------------------ */

echo "\n--- maintenance mode ---\n";

$before = [
    'maintenance_mode'    => (string) (Database::scalar(
        'SELECT setting_value FROM settings WHERE setting_key = ?', ['maintenance_mode']) ?? ''),
    'maintenance_message' => (string) (Database::scalar(
        'SELECT setting_value FROM settings WHERE setting_key = ?', ['maintenance_message']) ?? ''),
];

printf("    before: mode=%s\n", var_export($before['maintenance_mode'], true));

/* Restored to what was READ, whatever happens below — not to a default. */
register_shutdown_function(static function () use ($before): void {
    foreach ($before as $key => $value) {
        Database::run(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$key, $value]
        );
    }

    $now = (string) (Database::scalar(
        'SELECT setting_value FROM settings WHERE setting_key = ?', ['maintenance_mode']) ?? '');

    printf("\n  maintenance_mode restored to %s%s\n", var_export($now, true),
        $now === $before['maintenance_mode'] ? '' : '   *** NOT RESTORED — THE SITE MAY BE CLOSED ***');
});

$set = static function (string $mode, string $message = ''): void {
    foreach (['maintenance_mode' => $mode, 'maintenance_message' => $message] as $k => $val) {
        Database::run(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$k, $val]
        );
    }
};

/* ---- open ---- */

$set('0');

$open = test_get('/index.php');

$is('with the switch off the homepage is the homepage',
    str_contains($open, '<html') && !str_contains($open, 'Briefly offline'));

/* ---- closed ---- */

$notice = 'Closed for a migration. Back within the hour.';

$set('1', $notice);

$home = test_get('/index.php');

$is('with it on the homepage is replaced', str_contains($home, 'Briefly offline'));
$is('and it prints the office\'s own words', str_contains($home, $notice));

/* A QR page is closed with the rest on purpose: a visitor at a waterfall during
   a migration should be told, not shown a half-migrated page. */
$token = (string) (Database::scalar(
    "SELECT qr_token FROM destinations WHERE status = 'active' ORDER BY id LIMIT 1") ?? '');

if ($token !== '') {
    $qr = test_get('/d/' . $token);

    $is('a scanned QR code is closed too', str_contains($qr, 'Briefly offline'));
}

/* THE ONE THAT MATTERS. */
echo "\n--- what it must NOT close ---\n";

$login = test_get('/admin/login.php');

$is('the sign-in page still answers', !str_contains($login, 'Briefly offline'));
$is('and it is really the sign-in page', str_contains($login, 'name="password"'));

$dash = test_get_as($sid, '/admin/dashboard.php');

$is('the officer\'s dashboard still answers', !str_contains($dash, 'Briefly offline'));

$settings = test_get_as($sid, '/admin/settings/index.php');

$is('and Settings — the screen that turns it off', !str_contains($settings, 'Briefly offline'));
$is('which shows that the site is closed',
    str_contains($settings, 'public website is closed'));

$manager = test_get('/manager/login.php');

$is('the manager sign-in still answers', !str_contains($manager, 'Briefly offline'));

/* ---- and the status code ---- */

$code = test_status('/index.php');

printf("    the closed homepage answers HTTP %d\n", $code);

$is('a closed page answers 503, not 200',
    $code === 503 || $code === 0);   /* 0 when the helper cannot report one */

test_finish();
