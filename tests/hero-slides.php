<?php
declare(strict_types=1);

/**
 * The homepage hero, end to end, through Apache.
 *
 * WHAT THIS GUARDS
 *
 * That a photograph the office uploads in Settings actually reaches the public
 * homepage. That sounds too obvious to test until it fails: index.php was once
 * saved back over from a stale editor buffer, the query that reads hero_slides
 * disappeared with it, and the front page went back to stock photographs of
 * somewhere else. The admin screen still showed the uploaded pictures, so
 * everything looked right from the only place anybody was looking.
 *
 * It goes over real HTTP on purpose. Uploader::store() calls is_uploaded_file(),
 * which is false for anything a CLI script fakes into $_FILES — a test that
 * worked around that would pass while the browser path stayed broken.
 *
 * Everything it creates, it deletes.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Repositories\HeroSlideRepository as Hero;

echo "\n=== homepage hero ===\n\n";

if (!test_server_up()) {
    echo "  SKIP — no web server answering at " . test_base_url() . "\n";
    echo "  Start Apache, or set TOURSYNC_TEST_URL.\n";
    exit(0);
}

[$sid, $token] = test_sign_in_officer();

$png = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'toursync-hero-probe.png';
test_make_png($png, 'ZZ HERO PROBE');

register_shutdown_function(static function () use ($png): void {
    if (is_file($png)) {
        @unlink($png);
    }
});

$before = Hero::countAll();

/* Nothing of this test's making may survive a crash halfway through. */
register_shutdown_function(static function (): void {
    foreach (Database::all("SELECT id FROM hero_slides WHERE title LIKE 'ZZ %'") as $row) {
        Hero::delete((int) $row['id']);
    }
});

echo "--- uploading a slide through the real form ---\n";

$r = test_post('admin/settings/index.php', $sid, [
    '_token'  => $token,
    'action'  => 'hero_create',
    'title'   => 'ZZ Hero Probe',
    'eyebrow' => 'probe eyebrow',
    'body'    => 'probe paragraph',
    'status'  => 'published',
], $png);

check('the form was accepted (302)', $r['code'], 302);
check('a slide was created', Hero::countAll(), $before + 1);

$made = Database::first("SELECT * FROM hero_slides WHERE title = 'ZZ Hero Probe'");
check('the slide exists', $made !== null, true);

if ($made === null) {
    test_finish();
}

$id   = (int) $made['id'];
$path = trim((string) $made['image_path']);

check('image_path was written', $path !== '', true);
check('the stored file is on disk', is_file(dirname(APP_PATH) . '/' . $path), true);

echo "\n--- the file is served ---\n";

$ch = curl_init(test_base_url() . '/' . $path);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
$bin  = (string) curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

check('the uploaded file serves 200', $code, 200);
check('as an image', str_starts_with($type, 'image/'), true);
check('with bytes in it', strlen($bin) > 1000, true);

echo "\n--- and it reaches the PUBLIC homepage ---\n";

$home = test_get('index.php');

check('the homepage renders without diagnostics',
    (bool) preg_match('/Warning:|Fatal error:/', $home), false);
check('the uploaded photograph is on it', str_contains($home, $path), true);
check('the slide title is on it', str_contains($home, 'ZZ Hero Probe'), true);

/* THE REGRESSION ITSELF. When index.php stopped reading the table, every slide
   silently fell back to an images.unsplash.com URL. A slide that has a file on
   disk must never render as stock. */
preg_match_all('#hero__bg[^>]*background-image:url\(.([^\'")]+).#', $home, $m);
$stock = array_filter($m[1], static fn(string $u): bool => str_contains($u, 'unsplash.com'));

printf("    %d slides rendered, %d falling back to stock\n", count($m[1]), count($stock));
check('no slide falls back to stock while it has a file', count($stock), 0);

echo "\n--- replacing the photograph ---\n";

$first = $path;

$r = test_post('admin/settings/index.php', $sid, [
    '_token'  => $token,
    'action'  => 'hero_update',
    'id'      => $id,
    'title'   => 'ZZ Hero Probe',
    'eyebrow' => 'probe eyebrow',
    'body'    => 'probe paragraph',
    'status'  => 'published',
], $png);

check('the update was accepted', $r['code'], 302);

$second = trim((string) Hero::find($id)['image_path']);

check('the stored path changed', $second !== $first, true);
check('the new file is on disk', is_file(dirname(APP_PATH) . '/' . $second), true);
check('the replaced file was deleted', is_file(dirname(APP_PATH) . '/' . $first), false);

echo "\n--- a draft stays off the public site ---\n";

test_post('admin/settings/index.php', $sid, ['_token' => $token, 'action' => 'hero_status', 'id' => $id]);

check('the slide is a draft', (string) Hero::find($id)['status'], 'draft');

$home = test_get('index.php');

check('the draft is gone from the homepage', str_contains($home, 'ZZ Hero Probe'), false);
check('its photograph is gone with it', str_contains($home, $second), false);

echo "\n--- the settings screen must not lose its settings ---\n";

/* The hero actions post to the same URL as the settings save, whose handler
   writes `$_POST[$key] ?? ''` over every editable key. One of these actions
   reaching that loop would blank the office name, the hotlines and the
   retention window. */
$snapshot = [];

foreach (Database::all('SELECT setting_key, setting_value FROM settings') as $row) {
    $snapshot[$row['setting_key']] = (string) $row['setting_value'];
}

test_post('admin/settings/index.php', $sid, ['_token' => $token, 'action' => 'hero_status', 'id' => $id]);

$after = [];

foreach (Database::all('SELECT setting_key, setting_value FROM settings') as $row) {
    $after[$row['setting_key']] = (string) $row['setting_value'];
}

check('a hero action changed no setting', $after, $snapshot);

echo "\n--- clean up ---\n";

Hero::delete($id);

check('the probe slide is gone', Database::first("SELECT id FROM hero_slides WHERE title = 'ZZ Hero Probe'"), null);
check('its file went with it', is_file(dirname(APP_PATH) . '/' . $second), false);
check('the roster is back to its original size', Hero::countAll(), $before);

test_finish();
