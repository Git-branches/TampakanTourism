<?php
declare(strict_types=1);

/**
 * FEATURE 5 — the municipal dashboard: destinations, mapping and feedback.
 *
 * Three things an officer uses daily and a visitor never sees the seams of. The
 * failure that matters here is not a crash — it is a destination that is edited
 * in the admin and does not change on the public map, or a review that is hidden
 * and stays readable.
 *
 * So each half is checked from BOTH ends: written in the admin, read on the
 * public site.
 *
 * Everything it creates, it deletes.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Repositories\DestinationRepository;

echo "\n=== feature 5: dashboard, destinations, mapping, feedback ===\n\n";

if (!test_server_up()) {
    echo "  SKIP — no web server answering at " . test_base_url() . "\n";
    exit(0);
}

[$sid, $csrf] = test_sign_in_officer();

register_shutdown_function(static function (): void {
    Database::run("DELETE FROM feedback WHERE visitor_name LIKE 'ZZ %'");
    Database::run("DELETE FROM destinations WHERE name LIKE 'ZZ %'");
    echo "  (probe destination and feedback removed)\n";
});

echo "--- the dashboard answers, and only to somebody signed in ---\n";

$anon = test_get('admin/dashboard.php');

check('an anonymous visitor is not given the dashboard',
    str_contains($anon, 'Municipal tourism at a glance'), false);

$dash = test_get_as($sid, 'admin/dashboard.php');

check('the officer gets it', str_contains($dash, 'Municipal tourism at a glance'), true);
check('it renders without diagnostics',
    (bool) preg_match('/Warning:|Fatal error:/', $dash), false);

$stats = json_decode(test_get_as($sid, 'api/admin/stats.php'), true);

check('the live counter endpoint returns JSON', is_array($stats), true);

foreach (['today', 'yesterday', 'month', 'total', 'records', 'destinations'] as $key) {
    check(sprintf('  it carries "%s"', $key), array_key_exists($key, $stats ?? []), true);
}

echo "\n--- a destination created in the admin reaches the public site ---\n";

$made = DestinationRepository::create([
    'category_id'       => null,
    'name'              => 'ZZ Mapped Ridge',
    'short_description' => 'ZZ a ridge with a view.',
    'description'       => 'ZZ the long description of the ridge.',
    'history'           => '',
    'cultural_heritage' => '',
    'operating_hours'   => '',
    'entrance_fee'      => '',
    'facilities'        => '',
    'reminders'         => '',
    'safety_notes'      => '',
    'barangay'          => 'ZZ Barangay',
    'address'           => 'ZZ Address',
    'latitude'          => '6.4123',
    'longitude'         => '124.9456',
    'contact_person'    => '',
    'contact_phone'     => '',
    'local_hotline'     => '',
    'contact_email'     => '',
    'is_featured'       => 0,
    'status'            => 'active',
], null);

$d = Database::first('SELECT * FROM destinations WHERE id = ?', [$made]);

check('it was created', $d !== null, true);
check('with a slug', trim((string) $d['slug']) !== '', true);

$page = test_get('destination.php?slug=' . urlencode((string) $d['slug']));

check('its public page renders', str_contains($page, 'ZZ Mapped Ridge'), true);
check('and shows the description', str_contains($page, 'a ridge with a view'), true);

echo "\n--- and onto the map, with its coordinates ---\n";

$map = test_get('map.php');

check('the map page renders without diagnostics',
    (bool) preg_match('/Warning:|Fatal error:/', $map), false);

/* THE MARKERS ARE NOT IN THE PAGE. map.php ships a container and the browser
   fetches /api/destinations/map.php for the pins — so searching the HTML for a
   destination name finds nothing whether it is on the map or not. My first
   version did exactly that, and its "it is off the map" check passed after
   archiving for the wrong reason: the name had never been there to begin with.
   A test that passes for the wrong reason is worse than one that fails. */
/* GeoJSON, not a flat list: a FeatureCollection whose features carry the name
   in `properties` and the position in `geometry.coordinates` — and in GeoJSON
   order, which is [longitude, latitude]. Reading them as [lat, lng] is the
   classic way to put a South Cotabato waterfall in the Indian Ocean. */
$geo = json_decode(test_get('api/destinations/map.php'), true);

check('the marker endpoint returns a FeatureCollection',
    (string) ($geo['type'] ?? ''), 'FeatureCollection');

$mine = null;

foreach ($geo['features'] ?? [] as $feature) {
    if (($feature['properties']['name'] ?? '') === 'ZZ Mapped Ridge') {
        $mine = $feature;
        break;
    }
}

check('the destination is among the markers', $mine !== null, true);
check('longitude comes first, as GeoJSON says',
    (float) ($mine['geometry']['coordinates'][0] ?? 0), 124.9456);
check('and latitude second',
    (float) ($mine['geometry']['coordinates'][1] ?? 0), 6.4123);

echo "\n--- archiving takes it off the public site but keeps the record ---\n";

Database::run("UPDATE destinations SET status = 'archived' WHERE id = ?", [$made]);

$geo   = json_decode(test_get('api/destinations/map.php'), true);
$names = array_column(array_column($geo['features'] ?? [], 'properties'), 'name');

check('its pin is gone from the map', in_array('ZZ Mapped Ridge', $names, true), false);

$ch = curl_init(test_base_url() . '/destination.php?slug=' . urlencode((string) $d['slug']));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
curl_exec($ch);
$archivedCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

check('its page 404s', $archivedCode, 404);
check('but the row is still there',
    (int) Database::scalar('SELECT COUNT(*) FROM destinations WHERE id = ?', [$made]), 1);

Database::run("UPDATE destinations SET status = 'active' WHERE id = ?", [$made]);

echo "\n--- a visitor leaves feedback, from where the form actually is ---\n";

$before = (int) Database::scalar('SELECT COUNT(*) FROM feedback');

/* The rating form lives on the QR PAGE, not on destination.php — you rate a
   place after standing in it, and the QR code is how you got there. My first
   version posted to destination.php, got a cheerful 200 back, and stored
   nothing.

   rendered_at is a timing guard: a submission less than two seconds after the
   page was drawn is treated as a bot and silently redirected. Backdating it is
   the honest way for a test to be a slow human. `website` is the honeypot and
   must stay empty. */
$left = test_public_form(
    'd/index.php?token=' . urlencode((string) $d['qr_token']),
    'api/feedback/submit.php',
    [
        'destination_id' => $made,
        'visitor_name'   => 'ZZ Reviewer',
        'rating'         => 5,
        'comment'        => 'ZZ the view from the top is worth the climb.',
        'rendered_at'    => time() - 30,
        'website'        => '',
    ]
);

printf("    the form answered %d\n", $left['code']);

$review = Database::first("SELECT * FROM feedback WHERE visitor_name = 'ZZ Reviewer'");

check('the review was stored', $review !== null, true);

if ($review !== null) {
    check('with its rating', (int) $review['rating'], 5);

    echo "\n--- and the office can hide it ---\n";

    $inbox = test_get_as($sid, 'admin/feedback/index.php');

    check('it is in the moderation queue', str_contains($inbox, 'ZZ Reviewer'), true);

    /* The form posts `status`, not `action` — the handler validates it against
       the column's enum and calls moderate(). My first attempt sent
       action=hide, the handler found no recognised status, flashed
       "Unrecognised moderation action" and redirected — and a 302 from a
       refusal looks exactly like a 302 from a success. */
    test_post('admin/feedback/index.php', $sid, [
        '_token' => $csrf,
        'status' => 'hidden',
        'id'     => (int) $review['id'],
    ]);

    $after = Database::first('SELECT * FROM feedback WHERE id = ?', [(int) $review['id']]);

    printf("    status after hiding: %s\n", (string) ($after['status'] ?? '?'));

    check('it is now hidden', (string) ($after['status'] ?? ''), 'hidden');

    $public = test_get('d/index.php?token=' . urlencode((string) $d['qr_token']));

    check('and a visitor can no longer read it',
        str_contains($public, 'the view from the top is worth the climb'), false);
}

echo "\n--- clean up ---\n";

Database::run("DELETE FROM feedback WHERE visitor_name LIKE 'ZZ %'");
Database::run("DELETE FROM destinations WHERE name LIKE 'ZZ %'");

check('feedback is back to where it started',
    (int) Database::scalar('SELECT COUNT(*) FROM feedback'), $before);
check('no probe destination survives',
    (int) Database::scalar("SELECT COUNT(*) FROM destinations WHERE name LIKE 'ZZ %'"), 0);

test_finish();
