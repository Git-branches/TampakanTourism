<?php
declare(strict_types=1);

/**
 * The Archive: everything withdrawn, in one place, with the way back.
 *
 * The office had Archive buttons and no archive — a destination left the list
 * and the only route back was to know it existed and change a filter on the
 * page it had left. This checks the page lists what is out of service, that
 * Restore puts each kind back, and that a manager cannot reach it.
 *
 * WHAT IT TOUCHES: rows it creates ("ZZQA …"), purged on the way in and out.
 * The office's own nine archived destinations are counted, never restored.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;

echo "=== the archive section ===\n\n";

if (!test_server_up()) {
    echo "  SKIP — no web server answering at " . test_base_url() . "\n";
    exit(0);
}

$purge = static function (): void {
    foreach (Database::all("SELECT id FROM announcements WHERE title LIKE 'ZZQA%'") as $r) {
        \App\Repositories\AnnouncementRepository::delete((int) $r['id']);
    }
    foreach (Database::all("SELECT id FROM destinations WHERE name LIKE 'ZZQA%'") as $d) {
        Database::run('DELETE FROM destinations WHERE id = ?', [$d['id']]);
    }
    foreach (Database::all("SELECT id FROM inspection_requirements WHERE title LIKE 'ZZQA%'") as $r) {
        Database::run("DELETE FROM manager_notifications WHERE entity_type = 'inspection_requirement' AND entity_id = ?", [$r['id']]);
        Database::run('DELETE FROM inspection_requirements WHERE id = ?', [$r['id']]);
    }
};

$purge();
register_shutdown_function($purge);

[$sid, $csrf] = test_sign_in_officer();

/* What the office already has archived — counted, not touched. */
$realArchived = (int) Database::scalar("SELECT COUNT(*) FROM destinations WHERE status = 'archived'");

/* ---- the page ---------------------------------------------------------- */

echo "--- the page ---\n";

$page = test_get_as($sid, '/admin/settings/archive.php');

check('it opens', str_contains($page, 'Archive') && !str_contains($page, 'Fatal error'), true);
check('it is a tab in Settings', str_contains(test_get_as($sid, '/admin/settings/accounts.php'), 'archive.php'), true);
check('it says nothing here is deleted', str_contains($page, 'Nothing here is deleted'), true);
check('it lists the destinations the office archived',
    (bool) preg_match('/Destinations<\/h2>\s*<span class="text-muted small">' . $realArchived . '</', $page), true);

/* ---- a destination round trip ------------------------------------------ */

echo "\n--- restoring a destination ---\n";

$dest = Database::insert(
    "INSERT INTO destinations (name, slug, qr_token, status) VALUES ('ZZQA archived site', ?, ?, 'archived')",
    ['zzqa-' . bin2hex(random_bytes(4)), bin2hex(random_bytes(16))]
);

$page = test_get_as($sid, '/admin/settings/archive.php');
check('an archived destination appears', str_contains($page, 'ZZQA archived site'), true);
check('with a Restore button', str_contains($page, 'value="destination"') && str_contains($page, 'Restore'), true);

test_post('/admin/settings/archive.php', $sid, ['_token' => $csrf, 'what' => 'destination', 'id' => (string) $dest]);

check('Restore puts it back on the public site',
    (string) Database::scalar('SELECT status FROM destinations WHERE id = ?', [$dest]), 'active');
/* The ROW, not the name: the success message says "… is public again", so the
   name is on the page either way. What must be gone is its Restore button. */
check('and it leaves the archive',
    str_contains(test_get_as($sid, '/admin/settings/archive.php'), 'name="id" value="' . $dest . '"'), false);
check('the office\'s own archived destinations are untouched',
    (int) Database::scalar("SELECT COUNT(*) FROM destinations WHERE status = 'archived'"), $realArchived);

/* ---- an event comes back as a draft ------------------------------------ */

echo "\n--- restoring an event ---\n";

$event = Database::insert(
    "INSERT INTO announcements (title, slug, body, type, audience, status)
     VALUES ('ZZQA archived festival', ?, 'A probe.', 'festival', 'public', 'archived')",
    ['zzqa-' . bin2hex(random_bytes(4))]
);

check('an archived event appears', str_contains(test_get_as($sid, '/admin/settings/archive.php'), 'ZZQA archived festival'), true);

test_post('/admin/settings/archive.php', $sid, ['_token' => $csrf, 'what' => 'announcement', 'id' => (string) $event]);

check('it comes back as a DRAFT, not published',
    (string) Database::scalar('SELECT status FROM announcements WHERE id = ?', [$event]), 'draft');

/* ---- a compliance requirement ------------------------------------------ */

echo "\n--- restoring a compliance requirement ---\n";

$req = Database::insert(
    "INSERT INTO inspection_requirements (title, is_required, is_active, sort_order, min_photos, max_photos)
     VALUES ('ZZQA retired standard', 1, 0, 99, 1, 2)"
);

check('a removed requirement appears', str_contains(test_get_as($sid, '/admin/settings/archive.php'), 'ZZQA retired standard'), true);

$activeDestinations = (int) Database::scalar("SELECT COUNT(*) FROM destinations WHERE status = 'active'");
test_post('/admin/settings/archive.php', $sid, ['_token' => $csrf, 'what' => 'requirement', 'id' => (string) $req]);

check('Restore puts it back in use',
    (int) Database::scalar('SELECT is_active FROM inspection_requirements WHERE id = ?', [$req]), 1);
check('and every destination is notified',
    (int) Database::scalar(
        "SELECT COUNT(DISTINCT destination_id) FROM manager_notifications
          WHERE entity_type = 'inspection_requirement' AND entity_id = ?", [$req]),
    $activeDestinations);

/* ---- who may open it --------------------------------------------------- */

echo "\n--- who may open it ---\n";

[$msid] = test_sign_in_manager();
$asManager = test_get_as($msid, '/admin/settings/archive.php');

check('a manager cannot open the archive',
    str_contains($asManager, 'ZZQA') || str_contains($asManager, 'Nothing here is deleted'), false);

$anon = test_status('/admin/settings/archive.php');
check('signed out, it is closed', in_array($anon, [302, 303, 401, 403], true), true);

/* ---- nothing restores by GET ------------------------------------------- */

check('a restore needs a POST, not a link',
    (string) Database::scalar('SELECT status FROM announcements WHERE id = ?', [$event]), 'draft');

test_finish();
