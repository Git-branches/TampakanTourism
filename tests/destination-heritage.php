<?php
declare(strict_types=1);

/**
 * Heritage items, end to end: uploaded in the admin, read off a scanned QR code.
 *
 * The QR page is the one screen in this system a member of the public sees while
 * standing at the destination, and it is reached by a token nobody types. If the
 * link between the admin screen and that page breaks, the office has no way to
 * notice — they see their items listed in the admin and assume.
 *
 * Everything it creates, it deletes.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Repositories\HeritageRepository as Heritage;

echo "\n=== destination heritage ===\n\n";

if (!test_server_up()) {
    echo "  SKIP — no web server answering at " . test_base_url() . "\n";
    exit(0);
}

$dest = Database::first("SELECT id, name, qr_token FROM destinations WHERE status = 'active' ORDER BY id LIMIT 1");

if ($dest === null) {
    echo "  SKIP — no active destination\n";
    exit(0);
}

$did   = (int) $dest['id'];
$token = (string) $dest['qr_token'];

printf("destination: %s (id %d)\n\n", $dest['name'], $did);

[$sid, $csrf] = test_sign_in_officer();

$before = Heritage::countFor($did);

/* Nothing this test makes may survive it, including after a fatal. */
register_shutdown_function(static function () use ($did): void {
    foreach (Database::all(
        "SELECT id FROM destination_heritage WHERE destination_id = ? AND title LIKE 'ZZ %'", [$did]
    ) as $row) {
        Heritage::delete((int) $row['id']);
    }
});

$png = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'toursync-heritage-probe.png';
test_make_png($png, 'ZZ HERITAGE', 900, 600);

register_shutdown_function(static function () use ($png): void {
    if (is_file($png)) {
        @unlink($png);
    }
});

function heritage_post(string $sid, array $fields, ?string $file = null): array
{
    return test_post('admin/destinations/heritage.php', $sid, $fields, $file);
}

echo "--- adding an item with a photograph ---\n";

$r = heritage_post($sid, [
    '_token'         => $csrf,
    'destination_id' => $did,
    'action'         => 'create',
    'title'          => 'ZZ Backstrap weaving',
    'body'           => 'ZZ the description a visitor reads standing here.',
], $png);

check('the form was accepted (302)', $r['code'], 302);
check('an item appeared', Heritage::countFor($did), $before + 1);

$item = Database::first("SELECT * FROM destination_heritage WHERE title = 'ZZ Backstrap weaving'");

check('it exists', $item !== null, true);

if ($item === null) {
    test_finish();
}

$iid  = (int) $item['id'];
$path = trim((string) $item['image_path']);

check('a photograph was stored', $path !== '', true);
check('the file is on disk', file_on_disk($path), true);

echo "\n--- THE POINT: it is on the scanned QR page ---\n";

$qr = test_get('d/index.php?token=' . urlencode($token));

check('the QR page renders without diagnostics',
    (bool) preg_match('/Warning:|Fatal error:/', $qr), false);
check('the heading is on it', str_contains($qr, 'ZZ Backstrap weaving'), true);
check('the description is on it',
    str_contains($qr, 'ZZ the description a visitor reads standing here.'), true);
check('the photograph is on it', str_contains($qr, $path), true);

echo "\n--- and the short /d/<token> route shows it too ---\n";

$short = test_get('d/' . $token);

check('the rewritten route renders it', str_contains($short, 'ZZ Backstrap weaving'), true);

echo "\n--- an item with a picture but no words is not published ---\n";

heritage_post($sid, [
    '_token'         => $csrf,
    'destination_id' => $did,
    'action'         => 'create',
    'title'          => '',
    'body'           => '',
], $png);

check('a wordless item is refused outright', Heritage::countFor($did), $before + 1);

echo "\n--- editing the words keeps the photograph ---\n";

heritage_post($sid, [
    '_token'         => $csrf,
    'destination_id' => $did,
    'action'         => 'update',
    'item_id'        => $iid,
    'title'          => 'ZZ Backstrap weaving',
    'body'           => 'ZZ edited description.',
]);

$after = Heritage::find($iid);

check('the description changed', (string) $after['body'], 'ZZ edited description.');
check('the photograph is the same file', (string) $after['image_path'], $path);

$qr = test_get('d/index.php?token=' . urlencode($token));

check('the QR page shows the edit', str_contains($qr, 'ZZ edited description.'), true);

echo "\n--- an item belonging to another destination cannot be touched here ---\n";

$other = Database::first('SELECT id FROM destinations WHERE id <> ? ORDER BY id LIMIT 1', [$did]);

if ($other !== null) {
    $foreign = Heritage::create((int) $other['id'], ['title' => 'ZZ Foreign', 'body' => 'ZZ foreign body']);

    heritage_post($sid, [
        '_token'         => $csrf,
        'destination_id' => $did,          /* this destination … */
        'action'         => 'delete',
        'item_id'        => $foreign,      /* … another destination's item */
    ]);

    check('the foreign item survives', Heritage::find($foreign) !== null, true);

    Heritage::delete($foreign);
} else {
    echo "  (only one destination — cross-destination check skipped)\n";
}

echo "\n--- deleting takes the photograph with it ---\n";

heritage_post($sid, [
    '_token'         => $csrf,
    'destination_id' => $did,
    'action'         => 'delete',
    'item_id'        => $iid,
]);

check('the item is gone', Heritage::find($iid), null);
check('its file was deleted', file_on_disk($path), false);
check('the list is back to its original size', Heritage::countFor($did), $before);

$qr = test_get('d/index.php?token=' . urlencode($token));

check('and it is off the QR page', str_contains($qr, 'ZZ Backstrap weaving'), false);
check('the QR page still renders', str_contains($qr, 'Cultural Heritage') || strlen($qr) > 2000, true);

test_finish();
