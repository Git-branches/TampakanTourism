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

/* The fields the panel posts, as it posts them. */
$fields = [
    '_token'              => $token,
    'action'              => 'about_save',
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

$expected = array_keys(array_filter($fields, static fn(string $k): bool
    => str_starts_with($k, 'about_'), ARRAY_FILTER_USE_KEY));

sort($expected);

check('only the about_* keys changed', $touched, $expected);

echo "\n--- an empty badge removes the card rather than drawing a blank one ---\n";

$fields['about_badge_value'] = '';
$fields['about_badge_label'] = '';

test_post('admin/settings/index.php', $sid, $fields);

$home = test_get('index.php');

check('the badge card is gone', str_contains($home, 'about__badge'), false);
check('the rest of the block is still there', str_contains($home, 'ZZ Heading'), true);

echo "\n--- a photograph uploaded here replaces the stock one ---\n";

$png = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'toursync-about-probe.png';
test_make_png($png, 'ZZ ABOUT', 900, 1100);

register_shutdown_function(static function () use ($png): void {
    if (is_file($png)) {
        @unlink($png);
    }
});

$r = test_post('admin/settings/index.php', $sid, $fields, $png, 'image_main');

check('the upload was accepted', $r['code'], 302);

$stored = (string) setting_fresh('about_image_main');

check('a path was stored', $stored !== '', true);
check('the file is on disk', file_on_disk($stored), true);

$home = test_get('index.php');

check('the homepage shows the uploaded photograph', str_contains($home, $stored), true);
check('and no longer the stock one for that slot',
    str_contains($home, '1426604966848-d7adac402bff'), false);

/* Clean the file up: the settings restore below puts the row back to blank, so
   nothing would ever point at this again. */
if ($stored !== '' && file_on_disk($stored)) {
    unlink(dirname(APP_PATH) . '/' . $stored);
    check('the uploaded file was removed', file_on_disk($stored), false);
}

test_finish();
