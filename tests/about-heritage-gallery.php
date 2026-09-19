<?php
declare(strict_types=1);

/**
 * The Cultural Heritage gallery: several photographs, uploaded and removed
 * through the real Settings panel.        Client feedback, 17 September 2026
 *
 * WHAT THIS GUARDS.
 *
 * Cultural Heritage moved off the destination pages — the office was keeping the
 * same material once per destination and once on the website — and onto About,
 * where it now takes a gallery rather than one slot.
 *
 * Two things can go wrong quietly. An upload that ADDS can be written as an
 * upload that REPLACES, and the office loses the five photographs they uploaded
 * last week to the one they uploaded today. And a removal that deletes the row
 * but not the file leaves pictures on disk that nobody is managing, on a
 * municipal site, after somebody chose to take them down.
 *
 * Every row and file this suite creates is removed on the way out.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Repositories\AboutPhotoRepository as AboutPhotos;

echo "\n=== about: the cultural heritage gallery ===\n\n";

if (!test_server_up()) {
    echo "  SKIP — no web server answering at " . test_base_url() . "\n";
    exit(0);
}

[$sid, $token] = test_sign_in_officer();

/* What the office already has. Nothing here touches those rows; the suite only
   ever removes ids it created itself. */
$existing = array_column(AboutPhotos::all('heritage'), 'id');

$made = [];

register_shutdown_function(static function () use (&$made): void {
    $gone = 0;

    foreach ($made as $id) {
        if (Database::scalar('SELECT 1 FROM about_photos WHERE id = ?', [(int) $id]) !== null) {
            AboutPhotos::delete((int) $id);      // takes the file too
            $gone++;
        }
    }

    echo "  ({$gone} test photograph(s) removed)\n";
});

/** The panel posts every about_* field; build that from the rendered form. */
function heritage_post_fields(string $sid, string $token): array
{
    preg_match_all('/name="(about_[a-z0-9_]+)"/',
        test_get_as($sid, 'admin/settings/index.php'), $m);

    $fields = ['_token' => $token, 'action' => 'about_save',
               'return_panel' => 'aboutHeritagePanel'];

    foreach (array_unique($m[1]) as $key) {
        $fields[$key] = (string) (Database::scalar(
            'SELECT setting_value FROM settings WHERE setting_key = ?', [$key]) ?? '');
    }

    return $fields;
}

/**
 * Posts the heritage panel with any number of files under heritage_photos[].
 *
 * @param array<int, string> $files
 * @param array<int, int>    $remove
 */
function heritage_post(string $sid, string $token, array $files = [], array $remove = []): int
{
    $fields = heritage_post_fields($sid, $token);
    $post   = [];

    foreach ($fields as $k => $v) {
        $post[$k] = $v;
    }

    foreach ($remove as $i => $id) {
        $post["remove_about_photo[{$i}]"] = (string) $id;
    }

    foreach ($files as $i => $path) {
        $post["photos_heritage[{$i}]"] = new CURLFile($path, 'image/png', basename($path));
    }

    $ch = curl_init(test_base_url() . '/admin/settings/index.php');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIE         => session_name() . '=' . $sid,
        CURLOPT_TIMEOUT        => 90,
    ]);

    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $code;
}

/* ---------------------------------------------------------------------------
 | Three photographs in one go.
 * ------------------------------------------------------------------------ */
echo "-- uploading three at once --\n";

$pngs = [];

foreach ([1, 2, 3] as $n) {
    $pngs[] = test_make_png(sys_get_temp_dir() . "/zz-heritage-{$n}.png", "ZZ {$n}", 900, 600);
}

register_shutdown_function(static function () use ($pngs): void {
    foreach ($pngs as $p) { if (is_file($p)) { @unlink($p); } }
});

check('the upload is accepted', heritage_post($sid, $token, $pngs), 302);

$after = AboutPhotos::all('heritage');
$new   = array_values(array_diff(array_column($after, 'id'), $existing));
$made  = array_merge($made, $new);

check('three rows were added', count($new), 3);

/* THE ONE THAT MATTERS: the office's existing photographs are still there. An
   "upload" written as a replace would have taken them. */
$keptIds = array_values(array_intersect(array_column($after, 'id'), $existing));
check('and nothing already there was replaced', $keptIds, $existing);

/* Every file actually reached disk. */
$missing = [];

foreach ($after as $row) {
    if (in_array((int) $row['id'], $new, true) && !file_on_disk((string) $row['file_path'])) {
        $missing[] = (int) $row['id'];
    }
}

check('every uploaded file is on disk', $missing, []);

/* ---------------------------------------------------------------------------
 | The public page draws them.
 * ------------------------------------------------------------------------ */
echo "\n-- on the public page --\n";

$home  = test_get('index.php');
$total = count($after);

check('the heritage section is rendered', str_contains($home, 'id="about-heritage"'), true);

if ($total >= 4) {
    /* Four or more become the 2×2 the office drew: four tiles, the last carrying
       "+N · View all photos". */
    check('it draws the grid', str_contains($home, 'about-grid'), true);

    check('four tiles are shown',
        substr_count($home, 'about-grid__cell'), 4);

    if ($total > 4) {
        check('the last tile offers the rest',
            str_contains($home, '+' . ($total - 4)), true);
    }

    /* Everything past the fourth is still reachable by the viewer.
     *
     * COUNTED INSIDE THE HERITAGE BLOCK ONLY. Counted across the page it also
     * picks up the Tourism Office's hidden link — its second photograph — and
     * reports one too many for a reason that has nothing to do with heritage. */
    $block = '';

    if (preg_match('/id="about-heritage".*?(?=<div class="about-block"|<div class="row g-4 mv-row)/s',
        $home, $m)) {
        $block = $m[0];
    }

    check('the heritage block was located in the page', $block !== '', true);
    check('the remainder are in the document for the viewer',
        substr_count($block, 'about-block__hidden'), max(0, $total - 4));
} else {
    check('below four it stays a single image', str_contains($home, 'about-grid'), false);
}

/* ---------------------------------------------------------------------------
 | Removing takes the file with it.
 * ------------------------------------------------------------------------ */
echo "\n-- removing one --\n";

$victim = $new[0];
$path   = (string) Database::scalar('SELECT file_path FROM about_photos WHERE id = ?', [$victim]);

check('the file is there to begin with', file_on_disk($path), true);

check('the removal is accepted', heritage_post($sid, $token, [], [$victim]), 302);

check('the row is gone',
    Database::scalar('SELECT 1 FROM about_photos WHERE id = ?', [$victim]), null);

/* A PICTURE TAKEN OFF THE PAGE MUST NOT STAY ON THE SERVER. On a municipal site
   it is material somebody removed for a reason. */
check('and so is the file', file_on_disk($path), false);

$made = array_values(array_filter($made, static fn(int $id): bool => $id !== $victim));

/* ---------------------------------------------------------------------------
 | THE OTHER THREE BLOCKS TAKE GALLERIES TOO — AND MUST NOT GET THE GRID.
 |
 | The office asked for multiple photographs everywhere, and asked explicitly
 | that only Cultural Heritage should LOOK like a gallery. So a block with four
 | photographs in it must still draw one image with "View all photos" over its
 | corner. Getting this wrong is invisible in the admin and obvious on the page.
 * ------------------------------------------------------------------------ */
echo "\n-- the other three: many photographs, no grid --\n";

$sectionPngs = [];

foreach ([1, 2, 3, 4] as $n) {
    $sectionPngs[] = test_make_png(sys_get_temp_dir() . "/zz-office-{$n}.png", "ZZ O{$n}", 900, 600);
}

register_shutdown_function(static function () use ($sectionPngs): void {
    foreach ($sectionPngs as $p) { if (is_file($p)) { @unlink($p); } }
});

$officeBefore = array_column(AboutPhotos::all('office'), 'id');

/* Same panel, same handler, a different field name. */
$fields = heritage_post_fields($sid, $token);
$post   = $fields;
$post['return_panel'] = 'aboutOfficePanel';

foreach ($sectionPngs as $i => $p) {
    $post["photos_office[{$i}]"] = new CURLFile($p, 'image/png', basename($p));
}

$ch = curl_init(test_base_url() . '/admin/settings/index.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post,
    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_COOKIE => session_name() . '=' . $sid, CURLOPT_TIMEOUT => 90,
]);
curl_exec($ch);
check('the Tourism Office accepts a multi-upload too',
    (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 302);
curl_close($ch);

$officeAfter = AboutPhotos::all('office');
$officeNew   = array_values(array_diff(array_column($officeAfter, 'id'), $officeBefore));
$made        = array_merge($made, $officeNew);

check('four rows were added to it', count($officeNew), 4);

$page = test_get('index.php');

/* THE ASSERTION THE OFFICE ACTUALLY ASKED FOR. Four photographs is enough to
   make a 2×2, and this block must still refuse to. */
if (preg_match('/id="about-office".*?(?=<div class="about-block"|<div class="row g-4 mv-row)/s',
    $page, $m)) {
    $officeBlock = $m[0];

    check('it still shows ONE image, not a grid',
        str_contains($officeBlock, 'about-grid'), false);
    check('with exactly one visible photograph',
        substr_count($officeBlock, 'about-block__img'), 1);
    check('and the rest behind "View all photos"',
        str_contains($officeBlock, 'View all ' . count($officeAfter) . ' photos'), true);
} else {
    check('the office block was located in the page', false, true);
}

/* AT MOST ONE GRID ON THE PAGE, AND ONLY HERITAGE'S.
 *
 * By now this suite has removed one heritage photograph, so heritage may be
 * below the four a 2×2 needs — the count depends on what the office already
 * had. What must hold either way is that no OTHER block ever draws one, which
 * the office asked for explicitly and the Tourism Office above just proved with
 * four photographs of its own. */
$heritageNow = count(AboutPhotos::published('heritage'));
$grids       = substr_count($page, 'class="about-grid"');

check("at most one grid on the page (heritage holds {$heritageNow})",
    $grids <= 1, true);

check('and it is heritage\'s if there is one',
    $grids === 0 || (bool) preg_match(
        '/id="about-heritage".*?class="about-grid"/s', $page), true);

/* And the ones beside it survived. */
check('the other two are untouched',
    count(array_intersect(array_column(AboutPhotos::all('heritage'), 'id'), $made)), 2);

test_finish();
