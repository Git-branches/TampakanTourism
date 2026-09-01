<?php
declare(strict_types=1);

/**
 * Edit, Photos, Route and Heritage opened inside the destinations list's dialog.
 *
 * Those four pages now answer twice: the whole screen at their own URL, and the
 * body alone when asked with ?modal=1, which is what the list fetches into its
 * dialog. Two things can quietly break and leave the office none the wiser.
 *
 * The first is the fragment losing something. The dialog shows whatever comes
 * back, so a page that starts emitting its shell again looks merely ugly, while
 * a page that stops emitting its form looks empty — and both are invisible to
 * anyone testing by opening the full URL, which still works perfectly.
 *
 * The second is worse and is the reason this file exists. Every form on those
 * pages omits `action`, meaning "post to the page I am on". Fetched into the
 * list, the page they are on is index.php. If the script's action-rewriting
 * ever stops running, Save posts the whole form to the list, the list ignores
 * it, and the officer is returned to a screen that looks right and saved
 * nothing. So the fragment is checked for the forms the script must find, and
 * the real POST is checked to still work at the URL they get pointed back at.
 *
 * Read-only apart from one destination's name, which is put back.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;

echo "\n=== destination modals ===\n\n";

if (!test_server_up()) {
    echo "  SKIP — no web server answering at " . test_base_url() . "\n";
    exit(0);
}

$dest = Database::first("SELECT id, name FROM destinations WHERE status = 'active' ORDER BY id LIMIT 1");

if ($dest === null) {
    echo "  SKIP — no active destination\n";
    exit(0);
}

$did  = (int) $dest['id'];
$name = (string) $dest['name'];

printf("destination: %s (id %d)\n", $name, $did);

[$sid, $csrf] = test_sign_in_officer();

/* Every assertion here is "is this true", so check()'s third argument would
   be the word true two dozen times. */
$is = static function (string $what, bool $got): void { check($what, $got, true); };

/* The name is edited below. Restored even if this file dies halfway. */
register_shutdown_function(static function () use ($did, $name): void {
    Database::run('UPDATE destinations SET name = ? WHERE id = ?', [$name, $did]);
});

$pages = [
    'edit'     => ['edit.php',     'the destination form',   'name="name"'],
    'photos'   => ['photos.php',   'the upload field',       'type="file"'],
    'routes'   => ['routes.php',   'a form',                 '<form'],
    'heritage' => ['heritage.php', 'the add-item button',    'data-dialog="heritageAdd"'],
];

/* ---------------------------------------------------------------------------
   The shell is gone and the page is not
   ------------------------------------------------------------------------ */
echo "\n--- each page answers as a fragment ---\n";

$fragments = [];

foreach ($pages as $key => [$file, $what, $needle]) {
    $full = test_get_as($sid, '/admin/destinations/' . $file . '?id=' . $did);
    $frag = test_get_as($sid, '/admin/destinations/' . $file . '?id=' . $did . '&modal=1');

    $fragments[$key] = $frag;

    $is($key . ': the full page still renders whole', str_contains($full, '<html') && str_contains($full, 'admin-shell'));
    $is($key . ': the fragment drops the shell', !str_contains($frag, '<html') && !str_contains($frag, 'admin-shell'));
    $is($key . ': the fragment still has ' . $what, str_contains($frag, $needle));

    /* A fragment that came back as the sign-in page, or as an error, would
       satisfy "no shell" perfectly well. */
    $is($key . ': the fragment is the page, not a redirect', !str_contains($frag, 'name="password"'));

    printf("    %-9s full %6d bytes, fragment %6d\n", $file, strlen($full), strlen($frag));
}

/* ---------------------------------------------------------------------------
   The forms the script has to repoint
   ------------------------------------------------------------------------ */
echo "\n--- every fetched form is one the script can repoint ---\n";

foreach ($fragments as $key => $frag) {
    preg_match_all('/<form\b[^>]*>/i', $frag, $m);

    $forms   = $m[0];
    $actions = 0;

    foreach ($forms as $tag) {
        if (preg_match('/\baction\s*=/i', $tag)) { $actions++; }
    }

    $is($key . ': the fragment carries at least one form', $forms !== []);

    /* An action already written into the markup is left alone by the script.
       That is correct for archive.php, which names its own — the check is only
       that we know how many there are, so a new one cannot appear unnoticed. */
    printf("    %-9s %d form%s, %d already naming an action\n",
        $key, count($forms), count($forms) === 1 ? '' : 's', $actions);
}

/* ---------------------------------------------------------------------------
   The URL the script points them back at still saves
   ------------------------------------------------------------------------ */
echo "\n--- posting to that URL still saves ---\n";

$probe = $name . ' (modal probe)';

$response = test_post('/admin/destinations/edit.php?id=' . $did, $sid, [
    '_token' => $csrf,
    'name'   => $probe,
    'status' => 'active',
]);

$is('the post was accepted', $response['code'] === 302 || $response['code'] === 200);

$saved = (string) Database::scalar('SELECT name FROM destinations WHERE id = ?', [$did]);

$is('the new name reached the database', $saved === $probe);

Database::run('UPDATE destinations SET name = ? WHERE id = ?', [$name, $did]);

$is('the name was put back', (string) Database::scalar(
    'SELECT name FROM destinations WHERE id = ?', [$did]) === $name);

/* ---------------------------------------------------------------------------
   The list still offers both ways in
   ------------------------------------------------------------------------ */
echo "\n--- the list itself ---\n";

$list = test_get_as($sid, '/admin/destinations/index.php');

$is('the dialog is on the page', str_contains($list, 'id="pageModal"'));
$is('its body is empty until something is fetched',
    (bool) preg_match('/id="pageModalBody"\s*>\s*<\/div>/', $list));

preg_match_all('/data-modal-page/', $list, $triggers);

$is('the cards carry modal triggers', count($triggers[0]) > 0);
printf("    %d trigger%s on the list\n", count($triggers[0]), count($triggers[0]) === 1 ? '' : 's');

/* Whatever the script does, the href must survive: it is what a middle-click,
   a Ctrl-click and a browser with JavaScript off all follow. */
foreach (['edit.php', 'photos.php', 'routes.php', 'heritage.php'] as $file) {
    $is('a plain link to ' . $file . ' is still there',
        (bool) preg_match('/href="' . preg_quote($file, '/') . '\?id=\d+"[^>]*data-modal-page/', $list));
}

/* The dialog must be declared before the Add sheet, or the coordinate picker
   injected into it finds the Add sheet's #pickerMap instead of its own. */
$posModal = strpos($list, 'id="pageModal"');
$posAdd   = strpos($list, 'id="addDestination"');

$is('the dialog is declared above the Add sheet',
    $posModal !== false && $posAdd !== false && $posModal < $posAdd);

/* ---------------------------------------------------------------------------
   Nothing else learned to drop its shell
   ------------------------------------------------------------------------ */
echo "\n--- ?modal=1 changes nothing anywhere else ---\n";

foreach (['/admin/index.php', '/admin/settings/index.php', '/admin/destinations/create.php'] as $path) {
    $plain = test_get_as($sid, $path);
    $asked = test_get_as($sid, $path . (str_contains($path, '?') ? '&' : '?') . 'modal=1');

    $is(basename($path) . ' ignores it', str_contains($asked, '<html') && strlen($asked) === strlen($plain));
}

test_finish();
