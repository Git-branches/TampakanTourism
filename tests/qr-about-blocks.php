<?php
declare(strict_types=1);

/**
 * The QR spot page's About blocks — what replaced Cultural Heritage there.
 *                                     Client feedback, 17 September 2026
 *
 * WHAT CHANGED AND WHY THIS SUITE EXISTS.
 *
 * The sign used to carry a per-destination Cultural Heritage block, fed by
 * destination_heritage and admin/destinations/heritage.php. The office was
 * maintaining the same material twice — once per destination, once on the
 * website's About page — so heritage is now written once for the whole
 * municipality, on About, and the sign carries three blocks instead: the
 * Tourism Office, the brief history, and About Tampakan.
 *
 * This replaces tests/destination-heritage.php, which tested the removed
 * feature and could only fail. Two things are worth guarding:
 *
 *   · the three blocks actually reach a scanned sign, reading from the SAME
 *     settings rows as the homepage, so the office writes each once;
 *   · Cultural Heritage does not quietly return to the sign.
 *
 * ADMIN NOTE: admin/destinations/heritage.php and the destination_heritage rows
 * are still on disk and in the database. Nothing links to them and nothing
 * public reads them. They are left for the office to delete once they are
 * satisfied the About section covers it.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;

echo "\n=== QR spot page: the About blocks ===\n\n";

if (!test_server_up()) {
    echo "  SKIP — no web server answering at " . test_base_url() . "\n";
    exit(0);
}

$dest = Database::first(
    "SELECT id, name, qr_token FROM destinations WHERE status = 'active' AND qr_token <> '' ORDER BY id LIMIT 1"
);

if ($dest === null) {
    echo "  SKIP — no active destination with a QR token\n";
    exit(0);
}

printf("  scanning %s\n\n", $dest['name']);

$page = test_get('d/index.php?token=' . urlencode((string) $dest['qr_token']));

check('the sign renders', str_contains($page, '</html>'), true);
check('and without diagnostics',
    (bool) preg_match('/Warning:|Fatal error:|Notice:/', $page), false);

/* ---------------------------------------------------------------------------
 | The three blocks, in the order the office asked for them.
 * ------------------------------------------------------------------------ */
echo "-- the three blocks --\n";

$blocks = [
    ['about_office_text', 'About the Tourism Office'],
    ['about_history',     'A Brief History'],
    ['about_lead',        'About Tampakan'],
];

$expectedHeadings = [];

foreach ($blocks as [$key, $heading]) {
    $body = trim((string) (setting_fresh($key) ?: ''));

    if ($body === '') {
        /* Nothing written means nothing drawn — a heading over an empty block is
           worse on a phone at a trailhead than anywhere else. */
        check("{$heading}: not written, so not drawn", str_contains($page, $heading), false);
        continue;
    }

    $expectedHeadings[] = $heading;

    check("{$heading}: the heading is on the sign", str_contains($page, $heading), true);

    /* The opening of the stored text rather than all of it: nl2br() and e()
       both transform it, so an exact match would assert on the escaping. */
    check("{$heading}: and the office's own words",
        str_contains($page, e(mb_substr($body, 0, 50))), true);
}

/* IN ORDER. The office named them in this sequence and a sign that opens on the
   history reads as a different page from the one they approved. */
if (count($expectedHeadings) > 1) {
    $positions = array_map(static fn(string $h): int => strpos($page, $h) ?: 0, $expectedHeadings);
    $sorted    = $positions;
    sort($sorted);

    check('they appear in the order the office asked for', $positions, $sorted);
}

/* ---------------------------------------------------------------------------
 | Cultural Heritage is gone from the sign.
 * ------------------------------------------------------------------------ */
echo "\n-- heritage has left the sign --\n";

check('no Cultural Heritage heading', str_contains($page, 'Cultural Heritage'), false);
check('no heritage item list', str_contains($page, 'lb-heritage'), false);

/* THE COLUMN ITSELF IS GONE (2026-09-20). It used to be checked for text that
   must not reach the sign; the honest end of that story is that the destination
   form saved into a column no page ever read, so the field was a trap and both
   went. If a migration ever puts it back, this says so. */
check('the destinations table no longer carries cultural_heritage',
    (int) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'destinations'
            AND COLUMN_NAME = 'cultural_heritage'"
    ), 0);

check('and the destination form no longer offers the field',
    str_contains((string) file_get_contents(dirname(__DIR__) . '/admin/destinations/_form.php'),
        'name="cultural_heritage"'), false);

/* ---------------------------------------------------------------------------
 | And the conduct line survived the move.
 * ------------------------------------------------------------------------ */
echo "\n-- what was kept --\n";

check('the "treat it with respect" line is still on the sign',
    str_contains($page, 'belongs to the people of Tampakan'), true);

test_finish();
