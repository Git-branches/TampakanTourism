<?php
declare(strict_types=1);

/**
 * The office adds a compliance requirement; every manager is shown it.
 *
 * The office did not know this existed: the page was a grey "Standards" button,
 * the form could not say how many photographs a requirement needs, and a
 * manager was never told a new one had been added — it simply appeared on the
 * checklist whenever they next happened to open it.
 *
 * Through Apache: the officer adds one with a photo count; a manager of another
 * destination signs in, has a notification, and finds it on their checklist
 * with that count; retiring it takes it off the checklist again.
 *
 * WHAT IT TOUCHES: adding a requirement writes a notification to every active
 * destination and a checklist row to every open report. All of that hangs off
 * the one requirement this suite creates ("ZZQA …"): its checklist rows
 * cascade when it is deleted, and its notifications are deleted by entity. Both
 * are cleared on the way in and on the way out.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Core\ManagerAuth;

echo "=== compliance requirements reach the managers ===\n\n";

if (!test_server_up()) {
    echo "  SKIP — no web server answering at " . test_base_url() . "\n";
    exit(0);
}

$purge = static function (): void {
    foreach (Database::all("SELECT id FROM inspection_requirements WHERE title LIKE 'ZZQA%'") as $r) {
        Database::run("DELETE FROM manager_notifications WHERE entity_type = 'inspection_requirement' AND entity_id = ?", [$r['id']]);
        Database::run('DELETE FROM inspection_requirements WHERE id = ?', [$r['id']]);   // items cascade
    }

    foreach (Database::all("SELECT id FROM destinations WHERE name LIKE 'ZZQA%'") as $d) {
        foreach (Database::all('SELECT id FROM destination_managers WHERE destination_id = ?', [$d['id']]) as $m) {
            Database::run('DELETE FROM activity_logs WHERE manager_id = ?', [$m['id']]);
        }
        Database::run('DELETE FROM destinations WHERE id = ?', [$d['id']]);   // managers, reports, bell cascade
    }
};

$purge();
register_shutdown_function($purge);

$jar = tempnam(sys_get_temp_dir(), 'qajar');
register_shutdown_function(static fn () => @unlink($jar));

$get = static function (string $path, ?array $post = null) use ($jar): string {
    $ch = curl_init(test_base_url() . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
                            CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = (string) curl_exec($ch);
    curl_close($ch);
    return $body;
};
$tok = static fn (string $html): string => preg_match('/name="_token"\s+value="([^"]+)"/', $html, $m) ? $m[1] : '';

[$sid, $csrf] = test_sign_in_officer();

/* ---- the way in ------------------------------------------------------- */

echo "--- finding it ---\n";

$review = test_get_as($sid, '/admin/inspections/index.php');
$page   = test_get_as($sid, '/admin/inspections/requirements.php');

$tabOrder = static function (string $html): array {
    preg_match_all('#class="screen-tabs__tab[^"]*"\s+href="([^"]+)"#', $html, $m);
    return $m[1];
};

check('Compliance Review has two tabs, Manage Requirements first',
    $tabOrder($review), ['requirements.php', 'index.php']);
check('Submitted Reports is the active tab there',
    (bool) preg_match('#screen-tabs__tab is-active"\s+href="index\.php"#', $review), true);
check('and Manage Requirements is active on its own page',
    (bool) preg_match('#screen-tabs__tab is-active"\s+href="requirements\.php"#', $page), true);
check('the old button is gone from the reports panel', str_contains($review, 'btn btn-sm btn-brand">'
    . "\n                <i class=\"fa-solid fa-list-check\""), false);
check('Add Requirement opens a pop-up', str_contains($page, 'data-dialog="requirementSheet"')
    && str_contains($page, '<dialog class="sheet" id="requirementSheet"'), true);
check('whose form asks how many photos are needed', str_contains($page, 'name="min_photos"') && str_contains($page, 'name="max_photos"'), true);
check('each requirement\'s actions sit behind a ⋮ menu',
    substr_count($page, 'class="kebab kebab--pop"') >= 1 && !str_contains($page, '>Retire<'), true);

/* ---- a manager to receive it ------------------------------------------ */

$destination = Database::insert(
    "INSERT INTO destinations (name, slug, qr_token, status) VALUES ('ZZQA compliance site', ?, ?, 'active')",
    ['zzqa-' . bin2hex(random_bytes(4)), bin2hex(random_bytes(16))]
);
$user = 'zzqa.compliance.' . bin2hex(random_bytes(2));
$pass = 'Qa-Compliance-6612x';

Database::insert(
    "INSERT INTO destination_managers (destination_id, full_name, mobile_number, username, password_hash, is_active)
     VALUES (?, '_qa_ Compliance', ?, ?, ?, 1)",
    [$destination, '0995' . random_int(1000000, 9999999), $user, ManagerAuth::hash($pass)]
);

/* ---- the officer adds one --------------------------------------------- */

echo "\n--- adding a requirement ---\n";

$title = 'ZZQA Fire Exit Plan ' . bin2hex(random_bytes(2));

test_post('/admin/inspections/requirements.php', $sid, [
    '_token' => $csrf, 'action' => 'save', 'id' => '0', 'title' => $title,
    'guidance' => 'Photograph the posted exit plan, and the exit it points to.',
    'is_required' => '1', 'is_active' => '1', 'sort_order' => '90',
    'min_photos' => '2', 'max_photos' => '3',
]);

$req = Database::first('SELECT * FROM inspection_requirements WHERE title = ?', [$title]);

check('it was saved', $req !== null, true);

if ($req === null) {
    test_finish();
}

check('with the photo counts the officer set', [(int) $req['min_photos'], (int) $req['max_photos']], [2, 3]);

$active = (int) Database::scalar("SELECT COUNT(*) FROM destinations WHERE status = 'active'");
$told   = (int) Database::scalar(
    "SELECT COUNT(DISTINCT destination_id) FROM manager_notifications WHERE entity_type = 'inspection_requirement' AND entity_id = ?",
    [$req['id']]
);
check('every active destination was notified', $told, $active);

/* ---- the manager sees it ---------------------------------------------- */

echo "\n--- on the manager's side ---\n";

$get('/manager/login.php', ['_token' => $tok($get('/manager/login.php')), 'username' => $user, 'password' => $pass]);

$bell = $get('/api/manager/notifications.php');
check('the manager\'s bell carries it', str_contains($bell, 'New compliance requirement') && str_contains($bell, $title), true);

$checklist = $get('/manager/inspection.php');
check('it is on their compliance checklist', str_contains($checklist, e($title)), true);
check('the report cannot go without its two photos',
    in_array($title, \App\Repositories\InspectionRepository::missingRequired(
        (int) Database::scalar('SELECT id FROM inspection_reports WHERE destination_id = ?', [$destination])
    ), true), true);

$reportId = (int) Database::scalar('SELECT id FROM inspection_reports WHERE destination_id = ?', [$destination]);
$onList   = static fn (): array => array_column(\App\Repositories\InspectionRepository::items($reportId), 'title');

/* ---- deleting one nobody has used --------------------------------------- */

echo "\n--- deleting a requirement no report has used ---\n";

/* Only on open drafts, with no photograph and no decision: nothing to lose. */
test_post('/admin/inspections/requirements.php', $sid, ['_token' => $csrf, 'action' => 'delete', 'id' => (string) $req['id']]);

check('it is deleted permanently', Database::scalar('SELECT 1 FROM inspection_requirements WHERE id = ?', [$req['id']]), null);
check('and its empty checklist rows went with it',
    (int) Database::scalar('SELECT COUNT(*) FROM inspection_items WHERE requirement_id = ?', [$req['id']]), 0);
check('the rest of the checklist is still there', count($onList()) >= 1, true);

/* ---- deleting one a report has used ------------------------------------ */

echo "\n--- deleting a requirement a submitted report used ---\n";

$used = 'ZZQA First Aid Posting ' . bin2hex(random_bytes(2));
test_post('/admin/inspections/requirements.php', $sid, [
    '_token' => $csrf, 'action' => 'save', 'id' => '0', 'title' => $used,
    'is_required' => '1', 'sort_order' => '95', 'min_photos' => '1', 'max_photos' => '1',
]);
$usedId = (int) Database::scalar('SELECT id FROM inspection_requirements WHERE title = ?', [$used]);

/* The QA destination's report takes the requirement on, and is handed over. */
$get('/manager/inspection.php');
Database::run("UPDATE inspection_reports SET status = 'submitted' WHERE id = ?", [$reportId]);
$itemsBefore = (int) Database::scalar('SELECT COUNT(*) FROM inspection_items WHERE requirement_id = ?', [$usedId]);

test_post('/admin/inspections/requirements.php', $sid, ['_token' => $csrf, 'action' => 'delete', 'id' => (string) $usedId]);

check('it is removed from use, not erased', (int) Database::scalar('SELECT is_active FROM inspection_requirements WHERE id = ?', [$usedId]), 0);
check('the submitted report keeps its row for it',
    (int) Database::scalar('SELECT COUNT(*) FROM inspection_items WHERE requirement_id = ?', [$usedId]) >= 1 && $itemsBefore >= 1, true);

$page = test_get_as($sid, '/admin/inspections/requirements.php');
check('it is listed under Removed requirements, with Restore',
    str_contains($page, 'Removed requirements') && str_contains($page, 'value="restore"'), true);

test_post('/admin/inspections/requirements.php', $sid, ['_token' => $csrf, 'action' => 'restore', 'id' => (string) $usedId]);
$again = (int) Database::scalar(
    "SELECT COUNT(*) FROM manager_notifications WHERE entity_type = 'inspection_requirement' AND entity_id = ? AND title LIKE 'Compliance requirement back in use%'",
    [$usedId]
);
check('restoring it puts it back in use', (int) Database::scalar('SELECT is_active FROM inspection_requirements WHERE id = ?', [$usedId]), 1);
check('and notifies every destination again', $again, $active);

test_finish();
