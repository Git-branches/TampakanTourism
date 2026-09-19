<?php
declare(strict_types=1);

/**
 * "About the Municipal Tourism Office", end to end, through Apache.
 *
 * The same guard the hero has, for the same reason: the words and photographs
 * in this block are edited in Settings and read on the public homepage, and
 * nothing in between would notice if that connection were broken. The hero's
 * did break — a stale editor buffer removed the query and the front page went
 * back to stock photographs, looking entirely finished while doing so.
 *
 * It also checks the thing that could quietly destroy the office's
 * configuration: this panel posts to the same URL as the settings save, whose
 * handler writes over every key in $editable. One request reaching that loop
 * would blank the office name, the hotlines and the retention window.
 *
 * Every setting it touches is restored, whatever the outcome.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;

echo "\n=== about the office ===\n\n";

if (!test_server_up()) {
    echo "  SKIP — no web server answering at " . test_base_url() . "\n";
    exit(0);
}

[$sid, $token] = test_sign_in_officer();

/** Every setting, as stored right now. */
function snapshot(): array
{
    $out = [];

    foreach (Database::all('SELECT setting_key, setting_value FROM settings') as $row) {
        $out[$row['setting_key']] = (string) $row['setting_value'];
    }

    return $out;
}

$before = snapshot();

/* Put every setting back exactly as it was, whatever happens below. */
register_shutdown_function(static function () use ($before): void {
    foreach ($before as $key => $value) {
        Database::run(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$key, $value]
        );
    }

    echo "  (settings restored)\n";
});

/* THE FIELDS THE PANEL POSTS, AS IT POSTS THEM — and it must be ALL of them.
 *
 * The save handler writes `$_POST[$key] ?? ''` across its whole list, so a post
 * that omits a field stores an empty string over whatever was there. It now
 * refuses such a post outright rather than blanking, but a test that sends a
 * hand-written subset would simply be rejected and prove nothing.
 *
 * So the list is READ FROM THE DATABASE and only the values under test are
 * overridden. A field added to the panel is covered here the moment it has a
 * settings row, without anybody remembering to come back to this file — which
 * is what went wrong twice on 2026-09-17, once costing the cultural heritage
 * text and once nearly costing the mission and vision.
 */
/* READ OFF THE RENDERED FORM, which is what a browser would post.
 *
 * Not from the settings table: the handler writes keys that may not have a row
 * yet, and a list built from existing rows would be short — the handler would
 * refuse it, and this suite would fail for a reason that has nothing to do with
 * what it is testing. The panel's own inputs are the only list that is correct
 * by construction. */
$panel = test_get_as($sid, 'admin/settings/index.php');

preg_match_all('/name="(about_[a-z0-9_]+)"/', $panel, $names);

$current = [];

foreach (array_unique($names[1]) as $key) {
    $current[$key] = (string) (Database::scalar(
        'SELECT setting_value FROM settings WHERE setting_key = ?', [$key]
    ) ?? '');
}

if ($current === []) {
    fwrite(STDERR, "  no about_* inputs found in the settings panel — cannot post the form\n");
    exit(1);
}

$edits = [
    'about_eyebrow'       => 'ZZ Eyebrow',
    'about_title'         => 'ZZ Heading',
    'about_title_em'      => 'ZZ Coloured',
    'about_lead'          => 'ZZ introduction paragraph.',
    'about_badge_value'   => 'ZZ 9 Years',
    'about_badge_label'   => 'ZZ since 2017',
    'about_mission_title' => 'ZZ Mission',
    'about_mission_text'  => 'ZZ mission statement.',
    'about_vision_title'  => 'ZZ Vision',
    'about_vision_text'   => 'ZZ vision statement.',
];

$fields = ['_token' => $token, 'action' => 'about_save'];

foreach ($current as $key => $value) {
    $fields[$key] = $edits[$key] ?? $value;
}

echo "--- saving the block through the real form ---\n";

$r = test_post('admin/settings/index.php', $sid, $fields);

check('the form was accepted (302)', $r['code'], 302);
check('the eyebrow was stored', (string) setting_fresh('about_eyebrow'), 'ZZ Eyebrow');
check('the mission text was stored', (string) setting_fresh('about_mission_text'), 'ZZ mission statement.');

echo "\n--- and it reaches the public homepage ---\n";

$home = test_get('index.php');

check('the homepage renders without diagnostics',
    (bool) preg_match('/Warning:|Fatal error:/', $home), false);
check('the heading is on it', str_contains($home, 'ZZ Heading'), true);
check('the coloured half is on it', str_contains($home, 'ZZ Coloured'), true);
check('the introduction is on it', str_contains($home, 'ZZ introduction paragraph.'), true);
check('the badge is on it', str_contains($home, 'ZZ 9 Years'), true);
check('the mission statement is on it', str_contains($home, 'ZZ mission statement.'), true);
check('the vision statement is on it', str_contains($home, 'ZZ vision statement.'), true);

echo "\n--- THE SETTINGS MUST SURVIVE IT ---\n";

$after   = snapshot();
$touched = array_keys(array_diff_assoc($after, $before));

sort($touched);

/* Only the keys this suite actually gave a NEW value to. Every other about_*
   field was posted back at its current value, so it must come out unchanged —
   which is the real assertion here: a save must not disturb the fields it was
   not asked to touch. */
$expected = array_keys($edits);

sort($expected);

check('only the about_* keys under test changed', $touched, $expected);

echo "\n--- an empty badge removes the card rather than drawing a blank one ---\n";

$fields['about_badge_value'] = '';
$fields['about_badge_label'] = '';

test_post('admin/settings/index.php', $sid, $fields);

$home = test_get('index.php');

check('the badge card is gone', str_contains($home, 'about__badge'), false);
check('the rest of the block is still there', str_contains($home, 'ZZ Heading'), true);

echo "\n--- a photograph uploaded here reaches the homepage ---\n";

$png = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'toursync-about-probe.png';
test_make_png($png, 'ZZ ABOUT', 900, 1100);

register_shutdown_function(static function () use ($png): void {
    if (is_file($png)) {
        @unlink($png);
    }
});

/* UPLOADED INTO THE BLOCK'S GALLERY, which is the only path an officer has.
 *
 * This used to post image_main, writing about_image_main. Every About block now
 * holds a gallery in about_photos and the single-slot upload fields are gone —
 * about_image_main survives only as a read-only fallback behind an empty
 * gallery, with no field to upload into. Posting to it wrote nowhere, and the
 * suite reported a broken upload for a route that no longer exists.
 *
 * The row this creates is deleted at the end, file and all.
 */
$r = test_post('admin/settings/index.php', $sid, $fields, $png, 'photos_tampakan[0]');

check('the upload was accepted', $r['code'], 302);

$shot = Database::first(
    "SELECT * FROM about_photos WHERE section = 'tampakan' ORDER BY id DESC LIMIT 1"
);

check('a row was stored', $shot !== null, true);

if ($shot !== null) {
    $stored = (string) $shot['file_path'];

    register_shutdown_function(static function () use ($shot): void {
        App\Repositories\AboutPhotoRepository::delete((int) $shot['id']);
        echo "  (the probe photograph was removed)\n";
    });

    check('the file is on disk', file_on_disk($stored), true);

    $home = test_get('index.php');

    check('the homepage shows the uploaded photograph', str_contains($home, $stored), true);

    /* The stock picture only ever stood in for an empty gallery. With one
       uploaded it must be gone from that column. */
    check('and no longer the stock one for that slot',
        str_contains($home, '1426604966848-d7adac402bff'), false);
}

/* Clean the file up: the settings restore below puts the row back to blank, so
   nothing would ever point at this again. */
if ($stored !== '' && file_on_disk($stored)) {
    unlink(dirname(APP_PATH) . '/' . $stored);
    check('the uploaded file was removed', file_on_disk($stored), false);
}

test_finish();
