<?php
declare(strict_types=1);

/**
 * FEATURE 1 — the QR-coded digital tourist information page.
 *
 * The one screen a member of the public sees while standing at the destination,
 * reached by a token printed on a sign that stays in the field for years. If it
 * breaks, nobody in the office finds out: they are not the ones scanning it.
 *
 * What is checked here is the whole path — the token resolves, the right
 * destination answers, the emergency numbers a visitor might actually need are
 * on it, an archived site refuses, and a rotated token stops working while the
 * new one starts.
 *
 * It builds its own destination and deletes it, so no printed sign is affected.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Core\QrService;
use App\Repositories\DestinationRepository;

echo "\n=== feature 1: QR tourist information page ===\n\n";

if (!test_server_up()) {
    echo "  SKIP — no web server answering at " . test_base_url() . "\n";
    exit(0);
}

/* ---------------------------------------------------------------------------
   A destination of this test's own making.

   Rotating or archiving a REAL destination would invalidate a token already
   printed on a laminated sign at a waterfall, which is not something a test
   gets to do.
   ------------------------------------------------------------------------ */
$made = DestinationRepository::create([
    'category_id'       => null,
    'name'              => 'ZZ Probe Falls',
    'short_description' => 'ZZ short description.',
    'description'       => 'ZZ the long description.',
    'history'           => 'ZZ the history of this place.',
    'cultural_heritage' => 'ZZ the heritage text a visitor reads here.',
    'operating_hours'   => '6:00 AM – 5:00 PM',
    'entrance_fee'      => 'PHP 20',
    'facilities'        => '',
    'reminders'         => 'ZZ carry your rubbish out.',
    'safety_notes'      => 'ZZ the rocks are slippery after rain.',
    'barangay'          => 'ZZ Barangay',
    'address'           => 'ZZ Address',
    'latitude'          => '6.4000',
    'longitude'         => '124.9000',
    'contact_person'    => '',
    'contact_phone'     => '',
    'local_hotline'     => '',
    'contact_email'     => '',
    'is_featured'       => 0,
    'status'            => 'active',
], null);

register_shutdown_function(static function (): void {
    Database::run("DELETE FROM destinations WHERE name LIKE 'ZZ %'");
    echo "  (probe destination removed)\n";
});

$d = Database::first('SELECT * FROM destinations WHERE id = ?', [$made]);

printf("probe destination: %s (id %d)\n\n", $d['name'], $made);

echo "--- the token resolves to the right destination ---\n";

$token = (string) $d['qr_token'];

check('a token was generated', strlen($token), 32);
check('and it is hexadecimal', (bool) preg_match('/^[a-f0-9]{32}$/', $token), true);

$page = test_get('d/index.php?token=' . urlencode($token));

check('the page renders without diagnostics',
    (bool) preg_match('/Warning:|Fatal error:/', $page), false);
check('it names this destination', str_contains($page, 'ZZ Probe Falls'), true);
check('and not another one',
    substr_count($page, 'ZZ Probe Falls') > 0 && !str_contains($page, 'ZZ Other'), true);

echo "\n--- the short printed route works too ---\n";

/* /d/<token> is what is actually printed. The rewrite is in .htaccess, so this
   is testing Apache's configuration as much as the PHP. */
$short = test_get('d/' . $token);

check('the rewritten route answers', str_contains($short, 'ZZ Probe Falls'), true);

echo "\n--- what a visitor standing there is told ---\n";

check('the entrance fee is shown', str_contains($page, 'PHP 20'), true);
check('the opening hours are shown', str_contains($page, '6:00 AM'), true);
check('the safety note is shown', str_contains($page, 'slippery after rain'), true);
check('the reminder is shown', str_contains($page, 'carry your rubbish out'), true);
check('the heritage text is shown',
    str_contains($page, 'the heritage text a visitor reads here'), true);

echo "\n--- the emergency numbers ---\n";

/* These are settings, not destination columns: one police station for the whole
   municipality. A blank one is deliberately absent rather than shown empty — a
   number nobody answers, printed at a waterfall, is worse than no number. */
$shown  = 0;
$filled = 0;

foreach (['hotline_emergency', 'hotline_police', 'hotline_medical',
          'hotline_rescue', 'hotline_fire', 'hotline_tourism'] as $key) {
    $value = setting_fresh($key);

    if ($value === '') {
        continue;
    }

    $filled++;

    if (str_contains($page, $value)) {
        $shown++;
    }
}

printf("    %d hotlines configured, %d of them on the page\n", $filled, $shown);

check('every configured hotline reaches the page', $shown, $filled);

echo "\n--- the retired logbook cannot be posted to ---\n";

/* The digital logbook was closed deliberately: the monthly record filed with
   the DOT is built from reports a manager submitted and the office approved,
   and this endpoint used to write arrivals with nothing behind them.

   It answered 410 while the file was kept as a documented refusal. The file was
   deleted on 2026-09-03 once the office confirmed no printed sign carries the
   old address, so the answer is now 404. Still a POST rather than a GET: the
   thing being guarded is that nothing can WRITE here. */
$ch = curl_init(test_base_url() . '/api/arrivals/submit.php');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => ['name' => 'ZZ Nobody'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
]);
curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

check('the old submission endpoint is gone (404)', $code, 404);

echo "\n--- a token nobody issued is refused ---\n";

$ch = curl_init(test_base_url() . '/d/index.php?token=' . str_repeat('a', 32));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
curl_exec($ch);
$bad = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

check('an unknown token 404s', $bad, 404);

echo "\n--- an archived destination stops answering ---\n";

Database::run("UPDATE destinations SET status = 'archived' WHERE id = ?", [$made]);

$ch = curl_init(test_base_url() . '/d/index.php?token=' . urlencode($token));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
curl_exec($ch);
$archived = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

check('an archived destination 404s', $archived, 404);

Database::run("UPDATE destinations SET status = 'active' WHERE id = ?", [$made]);

echo "\n--- rotating the token retires the old sign ---\n";

$oldToken = $token;

QrService::rotate($made);

$newToken = (string) Database::scalar('SELECT qr_token FROM destinations WHERE id = ?', [$made]);

check('the token changed', $newToken !== $oldToken, true);

$ch = curl_init(test_base_url() . '/d/index.php?token=' . urlencode($oldToken));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
curl_exec($ch);
$oldCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

check('the old token no longer resolves', $oldCode, 404);
check('the new one does',
    str_contains(test_get('d/index.php?token=' . urlencode($newToken)), 'ZZ Probe Falls'), true);

test_finish();
