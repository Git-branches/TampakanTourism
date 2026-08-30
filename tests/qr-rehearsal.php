<?php
declare(strict_types=1);

/**
 * Showing the system to the tourism office on a laptop, before anything launches.
 *
 * This is a real stage of the project and it has a trap in it. On the laptop,
 * "localhost" means the laptop, so a QR code carrying it opens the phone of
 * whoever scans it — the demonstration fails in front of the office and the
 * reason is not obvious to anyone in the room.
 *
 * The way through is the laptop's own WiFi address, which QrService allows on
 * purpose. But a router hands that address out and takes it back: set at home
 * on Tuesday, the laptop arrives at the office on Thursday holding a different
 * one, and every code still renders perfectly and opens nothing.
 *
 * So three things are checked here: that loopback stays blocked, that a LAN
 * address unlocks printing while still warning, and that an address which has
 * stopped being this machine says so out loud.
 *
 * Writes one setting and puts it back — its value is read first and restored
 * to exactly what was found, never to a guess.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Core\QrService;

/* setting() caches its answer in a static, so a second address examined in this
   same process would be judged against the first one's value. Each is judged in
   a child process instead, and this file IS that child — invoked with --judge.
   Before any output, so the child says nothing but its verdict.

   Self-invocation rather than `php -r`: on Windows escapeshellarg() replaces a
   double quote with a SPACE, so an array literal passed that way arrives as a
   parse error. The same trap once turned a password into a different one. */
if (($argv[1] ?? '') === '--judge') {
    echo json_encode([
        'printable' => QrService::isPublishable(),
        'warns'     => QrService::warning() !== '',
        'drifts'    => QrService::drift()   !== '',
    ]);

    exit(0);
}

echo "\n=== QR rehearsal address ===\n\n";

$is = static function (string $what, bool $got): void { check($what, $got, true); };

/* ---------------------------------------------------------------------------
   Detecting this machine
   ------------------------------------------------------------------------ */
$lan = QrService::lanAddress();

printf("  this machine answers on: %s\n\n", $lan === '' ? '(no LAN address found)' : $lan);

if ($lan === '') {
    echo "  SKIP — no LAN address on this machine, so the rehearsal path cannot be exercised\n";
    test_finish();
}

$is('the detected address is not loopback', !str_starts_with($lan, '127.'));
$is('it is a real IPv4', filter_var($lan, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false);
$is('the rehearsal URL keeps the application path',
    str_contains(QrService::rehearsalUrl(), $lan));

/* ---------------------------------------------------------------------------
   Which addresses are allowed, and which are merely warned about
   ------------------------------------------------------------------------ */
$key  = 'public_url';
$orig = (string) (Database::scalar(
    'SELECT setting_value FROM settings WHERE setting_key = ?', [$key]) ?? '');

printf("  public_url before this suite: %s\n", var_export($orig, true));

/* Restored even if an assertion below throws. Restored to what was READ, not
   to a default — that mistake once destroyed a real password here. */
register_shutdown_function(static function () use ($key, $orig): void {
    Database::run(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
        [$key, $orig]
    );

    $now = (string) (Database::scalar(
        'SELECT setting_value FROM settings WHERE setting_key = ?', [$key]) ?? '');

    printf("\n  public_url restored to %s%s\n", var_export($now, true),
        $now === $orig ? '' : '   *** RESTORE FAILED ***');
});

/** Sets the address, then asks a fresh copy of this file what it makes of it. */
$judge = static function (string $url): array {
    Database::run(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
        ['public_url', $url]
    );

    $out = shell_exec(sprintf('%s %s --judge',
        escapeshellarg(PHP_BINARY), escapeshellarg(__FILE__)));

    $verdict = json_decode(trim((string) $out), true);

    if (!is_array($verdict)) {
        fwrite(STDERR, "    child said: " . var_export($out, true) . "\n");

        return ['printable' => null, 'warns' => null, 'drifts' => null];
    }

    return $verdict;
};

echo "\n--- what the office may print ---\n";

$loopback = $judge('http://localhost/TampakanTourism');

$is('localhost is refused', $loopback['printable'] === false);
$is('and does not merely warn', $loopback['warns'] === false);

$here = $judge(QrService::rehearsalUrl());

$is('this machine\'s own WiFi address is allowed', $here['printable'] === true);
$is('it warns that these are test prints', $here['warns'] === true);
$is('it does NOT claim to have drifted', $here['drifts'] === false);

$real = $judge('https://tourism.tampakan.gov.ph');

$is('a real domain is allowed', $real['printable'] === true);
$is('and says nothing about test prints', $real['warns'] === false);
$is('and never reports drift', $real['drifts'] === false);

/* ---------------------------------------------------------------------------
   The silent one
   ------------------------------------------------------------------------ */
echo "\n--- the address that stopped being this machine ---\n";

/* A private address this machine certainly does not hold. */
$stale = $judge('http://10.77.77.77/TampakanTourism');

$is('a stale rehearsal address still counts as printable', $stale['printable'] === true);
$is('but it is reported as drifted', $stale['drifts'] === true);

/* ---------------------------------------------------------------------------
   What the officer actually sees
   ------------------------------------------------------------------------ */
echo "\n--- on screen ---\n";

[$sid, $csrf] = test_sign_in_officer();

Database::run(
    'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
    ['public_url', 'http://10.77.77.77/TampakanTourism']
);

$page = test_get_as($sid, '/admin/qrcodes/index.php');

$is('the QR page shouts about the stale address', str_contains($page, 'out of date'));
$is('it names the address this machine now has', str_contains($page, $lan));

Database::run(
    'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
    ['public_url', 'http://localhost/TampakanTourism']
);

$blocked = test_get_as($sid, '/admin/qrcodes/index.php');

$is('a blocked page offers the rehearsal address', str_contains($blocked, $lan));
$is('and still refuses to print', !str_contains($blocked, 'Print All Posters')
    || str_contains($blocked, 'disabled'));

$settings = test_get_as($sid, '/admin/settings/index.php');

$is('Settings offers to fill the address in', str_contains($settings, 'data-fill="public_url"'));
$is('with this machine\'s address', str_contains($settings, $lan));

test_finish();
