<?php
declare(strict_types=1);

/**
 * An event's photo gallery, the delete rules, and the smaller fixes that
 * shipped with them — through Apache, as the office would use them.
 *
 *   events     1, 4 and 6 photos; the 1 large + 3 layout; "View all photos";
 *              adding, removing and re-featuring; a duplicate and a fake image
 *              in one batch; the files leaving the disk with the event
 *   deletes    a destination, an alert, a message and a manager's draft are
 *              deleted only when the rule allows it, and refused otherwise —
 *              including a manager reaching for another destination's draft
 *   the rest   Back to Reports on the visitor record, and the Tourism Office
 *              mark as the favicon and sidebar logo, from nested folders
 *
 * WHAT IT TOUCHES: only rows it creates (titles and names beginning "ZZQA" or
 * "_qa_"), purged on the way in and on the way out. The refusal tests are
 * aimed at QA rows built to be refused — never at a real destination, where a
 * broken guard would mean real loss.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Core\ManagerAuth;
use App\Repositories\AnnouncementRepository;

echo "=== event photos and deletes ===\n\n";

if (!test_server_up()) {
    echo "  SKIP — no web server answering at " . test_base_url() . "\n";
    exit(0);
}

/* ---- clean-up, both ends ---------------------------------------------- */

$purge = static function (): void {
    foreach (Database::all("SELECT id FROM announcements WHERE title LIKE 'ZZQA%'") as $r) {
        AnnouncementRepository::delete((int) $r['id']);
    }

    Database::run("DELETE FROM destination_alerts WHERE message LIKE 'ZZQA%'");
    Database::run("DELETE FROM contact_messages WHERE subject LIKE 'ZZQA%'");

    foreach (Database::all("SELECT id FROM destinations WHERE name LIKE 'ZZQA%'") as $d) {
        $mids = array_column(Database::all('SELECT id FROM destination_managers WHERE destination_id = ?', [$d['id']]), 'id');
        foreach ($mids as $mid) {
            Database::run('DELETE FROM activity_logs WHERE manager_id = ?', [$mid]);
        }
        Database::run('DELETE FROM arrival_reports WHERE destination_id = ?', [$d['id']]);
        Database::run('DELETE FROM destinations WHERE id = ?', [$d['id']]);   // managers, photos cascade
    }
};

$purge();
register_shutdown_function($purge);

$tmpFiles = [];
register_shutdown_function(static function () use (&$tmpFiles): void {
    foreach ($tmpFiles as $f) { @unlink($f); }
});

$png = static function (string $label) use (&$tmpFiles): string {
    $path = sys_get_temp_dir() . '/zzqa-' . bin2hex(random_bytes(4)) . '.png';
    $tmpFiles[] = $path;
    return test_make_png($path, $label, 900, 600);
};

$files = static function (array $paths): array {
    $out = [];
    foreach (array_values($paths) as $i => $p) {
        $out['photos[' . $i . ']'] = new CURLFile($p, mime_content_type($p) ?: 'image/png', basename($p));
    }
    return $out;
};

[$sid, $csrf] = test_sign_in_officer();

$event = static function (string $title, array $paths) use ($sid, $csrf, $files): ?array {
    test_post('/admin/announcements/create.php', $sid, [
        '_token' => $csrf, 'title' => $title, 'body' => 'A gallery probe written by the test suite.',
        'type' => 'festival', 'audience' => 'public', 'status' => 'published',
        'event_date' => date('Y-m-d', strtotime('+30 days')), 'event_location' => 'Poblacion',
    ] + $files($paths));

    return Database::first('SELECT * FROM announcements WHERE title = ?', [$title]);
};

$publicPage = static fn (array $a): string => test_get('/events.php?slug=' . urlencode((string) $a['slug']));

/* =========================================================================
   Events
   ====================================================================== */

echo "--- one photo ---\n";

$one = $event('ZZQA one photo', [$png('ONE')]);
check('the event was created', $one !== null, true);
check('its photo is the featured one', ($one['banner_path'] ?? '') !== '' && file_on_disk((string) $one['banner_path']), true);
check('and there is no gallery behind it', count(AnnouncementRepository::photos((int) $one['id'])), 0);

$page = $publicPage($one);
check('the public page shows it as before, whole', str_contains($page, 'class="event-poster"'), true);
check('with no gallery layout', str_contains($page, 'class="event-gallery'), false);

echo "\n--- four photos ---\n";

$four = $event('ZZQA four photos', [$png('A'), $png('B'), $png('C'), $png('D')]);
check('one featured and three more', 1 + count(AnnouncementRepository::photos((int) $four['id'])), 4);

$page = $publicPage($four);
check('laid out as one large and three beside it',
    substr_count($page, 'class="event-gallery__lead"') === 1 && substr_count($page, 'class="event-gallery__tile') === 3, true);
check('no "View all photos" when nothing is hidden', str_contains($page, 'View all photos'), false);
check('every photo opens in the viewer', substr_count($page, 'data-lightbox="event"'), 4);
check('and the viewer is on the page', str_contains($page, 'id="lightbox"'), true);

echo "\n--- six photos ---\n";

$six = $event('ZZQA six photos', array_map($png, ['1', '2', '3', '4', '5', '6']));
$page = $publicPage($six);

check('six photographs stored', AnnouncementRepository::photoCount((int) $six['id']), 6);
check('still four drawn', substr_count($page, 'class="event-gallery__tile') + substr_count($page, 'class="event-gallery__lead"'), 4);
check('the last tile says how many more', str_contains($page, '<strong>+2</strong>') && str_contains($page, 'View all photos'), true);
check('the other two are in the viewer, out of the layout', substr_count($page, 'class="event-gallery__hidden"'), 2);
check('all six are in the viewer\'s set', substr_count($page, 'data-lightbox="event"'), 6);

echo "\n--- a duplicate and a fake image in one batch ---\n";

$same = $png('SAME');
$fake = sys_get_temp_dir() . '/zzqa-fake.jpg';
file_put_contents($fake, "<?php echo 'not a picture'; ?>");
$tmpFiles[] = $fake;

$mixed = $event('ZZQA mixed batch', [$same, $same, $fake]);
check('the same picture twice is stored once', AnnouncementRepository::photoCount((int) $mixed['id']), 1);

$stored = array_merge([(string) $mixed['banner_path']], array_column(AnnouncementRepository::photos((int) $mixed['id']), 'file_path'));
check('nothing with a .php or the sent name was stored', array_filter($stored,
    static fn ($p): bool => str_contains($p, '.php') || str_contains($p, 'zzqa-')) === [], true);
check('it was stored under a random name in uploads/banners',
    preg_match('#^uploads/banners/[0-9a-f]{32}\.(png|jpg|webp)$#', (string) $mixed['banner_path']) === 1, true);

echo "\n--- editing the gallery ---\n";

$id      = (int) $four['id'];
$photos  = AnnouncementRepository::photos($id);
$oldLead = (string) $four['banner_path'];
$chosen  = $photos[1];

test_post('/admin/announcements/edit.php?id=' . $id, $sid, [
    '_token' => $csrf, 'title' => 'ZZQA four photos', 'body' => 'A gallery probe written by the test suite.',
    'type' => 'festival', 'audience' => 'public', 'status' => 'published',
    'event_date' => date('Y-m-d', strtotime('+30 days')), 'event_location' => 'Poblacion',
    'remove_photos[0]' => (string) $photos[0]['id'],
    'featured_photo'   => (string) $chosen['id'],
] + $files([$png('E'), $png('F')]));

$after      = Database::first('SELECT * FROM announcements WHERE id = ?', [$id]);
$afterPaths = array_column(AnnouncementRepository::photos($id), 'file_path');

check('the chosen photo became the featured one', $after['banner_path'], $chosen['file_path']);
check('the previous lead stayed, in the gallery', in_array($oldLead, $afterPaths, true), true);
check('the removed photo is gone from the gallery', in_array($photos[0]['file_path'], $afterPaths, true), false);
check('and from the disk', file_on_disk((string) $photos[0]['file_path']), false);
check('two new photos were added: 4 - 1 + 2', AnnouncementRepository::photoCount($id), 5);

test_post('/admin/announcements/edit.php?id=' . $id, $sid, [
    '_token' => $csrf, 'title' => 'ZZQA four photos', 'body' => 'A gallery probe written by the test suite.',
    'type' => 'festival', 'audience' => 'public', 'status' => 'published',
    'event_date' => date('Y-m-d', strtotime('+30 days')), 'event_location' => 'Poblacion',
    'remove_banner' => '1',
]);

$lead = (string) Database::scalar('SELECT banner_path FROM announcements WHERE id = ?', [$id]);
check('removing the featured photo promotes the next', $lead !== '' && $lead !== $chosen['file_path'], true);
check('so the event keeps a card picture', AnnouncementRepository::photoCount($id), 4);

$frag = test_get_as($sid, '/admin/announcements/edit.php?id=' . $id . '&modal=1');
check('the edit form lists each photo with its controls',
    substr_count($frag, 'name="remove_photos[]"') === 3 && str_contains($frag, 'name="featured_photo"'), true);

echo "\n--- deleting an event takes its files ---\n";

$every = AnnouncementRepository::gallery(Database::first('SELECT * FROM announcements WHERE id = ?', [(int) $six['id']]));
AnnouncementRepository::delete((int) $six['id']);
check('the six files are gone from the disk', array_filter($every, 'file_on_disk'), []);

/* =========================================================================
   Deletes
   ====================================================================== */

echo "\n--- destinations ---\n";

$newDestination = static function (string $name): int {
    return Database::insert(
        "INSERT INTO destinations (name, slug, qr_token, status) VALUES (?, ?, ?, 'active')",
        [$name, 'zzqa-' . bin2hex(random_bytes(4)), bin2hex(random_bytes(16))]
    );
};

$empty = $newDestination('ZZQA empty destination');
$kept  = $newDestination('ZZQA destination with a manager');

Database::insert(
    "INSERT INTO destination_managers (destination_id, full_name, mobile_number, is_active) VALUES (?, '_qa_ Keeper', ?, 1)",
    [$kept, '0998' . random_int(1000000, 9999999)]
);

$list = test_get_as($sid, '/admin/destinations/index.php?status=all&q=ZZQA');
check('the card menu offers Archive', str_contains($list, 'value="archived"'), true);

test_post('/admin/destinations/archive.php', $sid, ['_token' => $csrf, 'id' => (string) $kept, 'action' => 'delete']);
check('one with a manager is refused', Database::scalar('SELECT 1 FROM destinations WHERE id = ?', [$kept]) !== null, true);
check('and its manager is untouched', (int) Database::scalar('SELECT COUNT(*) FROM destination_managers WHERE destination_id = ?', [$kept]), 1);

test_post('/admin/destinations/archive.php', $sid, ['_token' => $csrf, 'id' => (string) $empty, 'action' => 'delete']);
check('one with nothing behind it is deleted', Database::scalar('SELECT 1 FROM destinations WHERE id = ?', [$empty]), null);

test_post('/admin/destinations/archive.php', $sid, [
    '_token' => $csrf, 'id' => (string) $kept, 'status' => 'archived', 'return' => 'index.php?status=all',
]);
check('archive still works, from the list', Database::scalar('SELECT status FROM destinations WHERE id = ?', [$kept]), 'archived');

echo "\n--- alerts ---\n";

$alert = Database::insert("INSERT INTO destination_alerts (destination_id, message, status) VALUES (?, 'ZZQA open alert', 'new')", [$kept]);

test_post('/admin/alerts/index.php', $sid, ['_token' => $csrf, 'id' => (string) $alert, 'action' => 'delete']);
check('an open alert is not deleted', Database::scalar('SELECT 1 FROM destination_alerts WHERE id = ?', [$alert]) !== null, true);

Database::run("UPDATE destination_alerts SET status = 'dismissed' WHERE id = ?", [$alert]);
test_post('/admin/alerts/index.php', $sid, ['_token' => $csrf, 'id' => (string) $alert, 'action' => 'delete']);
check('a dismissed one is', Database::scalar('SELECT 1 FROM destination_alerts WHERE id = ?', [$alert]), null);

echo "\n--- messages ---\n";

$msg = Database::insert(
    "INSERT INTO contact_messages (name, email, subject, message, status) VALUES ('QA', 'qa@example.com', 'ZZQA enquiry', 'A probe.', 'new')"
);

test_post('/admin/messages/index.php', $sid, ['_token' => $csrf, 'id' => (string) $msg, 'action' => 'delete']);
check('an enquiry is not deleted', Database::scalar('SELECT 1 FROM contact_messages WHERE id = ?', [$msg]) !== null, true);

Database::run("UPDATE contact_messages SET status = 'spam' WHERE id = ?", [$msg]);
test_post('/admin/messages/index.php', $sid, ['_token' => $csrf, 'id' => (string) $msg, 'action' => 'delete']);
check('a message marked spam is', Database::scalar('SELECT 1 FROM contact_messages WHERE id = ?', [$msg]), null);

echo "\n--- a manager's draft report ---\n";

$mine   = $newDestination('ZZQA manager home');
$theirs = $newDestination('ZZQA someone else');
$pass   = 'Qa-Drafts-5521x';
$user   = 'zzqa.drafts.' . bin2hex(random_bytes(2));

Database::insert(
    "INSERT INTO destination_managers (destination_id, full_name, mobile_number, username, password_hash, is_active)
     VALUES (?, '_qa_ Draft Owner', ?, ?, ?, 1)",
    [$mine, '0997' . random_int(1000000, 9999999), $user, ManagerAuth::hash($pass)]
);

$report = static fn (int $dest, string $status): int => Database::insert(
    'INSERT INTO arrival_reports (destination_id, period_start, period_end, status) VALUES (?, ?, ?, ?)',
    [$dest, '2026-01-01', '2026-01-31', $status]
);

$draft     = $report($mine, 'draft');
$submitted = $report($mine, 'submitted');
$foreign   = $report($theirs, 'draft');

$jar = tempnam(sys_get_temp_dir(), 'qajar');
$tmpFiles[] = $jar;

$get = static function (string $path, ?array $post = null) use ($jar): string {
    $ch = curl_init(test_base_url() . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = (string) curl_exec($ch);
    curl_close($ch);
    return $body;
};
$tok = static fn (string $html): string => preg_match('/name="_token"\s+value="([^"]+)"/', $html, $m) ? $m[1] : '';

$get('/manager/login.php', ['_token' => $tok($get('/manager/login.php')), 'username' => $user, 'password' => $pass]);

$form = $get('/manager/report-form.php?id=' . $draft);
check('a draft offers Discard Draft', str_contains($form, 'form="rfDiscard"'), true);
check('a submitted report does not', str_contains($get('/manager/report-form.php?id=' . $submitted), 'rfDiscard'), false);

$get('/manager/report-form.php?id=' . $foreign, ['_token' => $tok($form), 'id' => (string) $foreign, 'action' => 'discard']);
check('another destination\'s draft cannot be discarded', Database::scalar('SELECT 1 FROM arrival_reports WHERE id = ?', [$foreign]) !== null, true);

$get('/manager/report-form.php?id=' . $submitted, ['_token' => $tok($form), 'id' => (string) $submitted, 'action' => 'discard']);
check('nor can a submitted one', Database::scalar('SELECT 1 FROM arrival_reports WHERE id = ?', [$submitted]) !== null, true);

$get('/manager/report-form.php?id=' . $draft, ['_token' => $tok($form), 'id' => (string) $draft, 'action' => 'discard']);
check('their own draft is discarded', Database::scalar('SELECT 1 FROM arrival_reports WHERE id = ?', [$draft]), null);

/* =========================================================================
   The rest
   ====================================================================== */

echo "\n--- visitor record ---\n";

$vr = test_get_as($sid, '/admin/reports/visitor-record.php');
check('it has a Back to Reports button', str_contains($vr, 'Back to Reports'), true);
check('pointing at the Reports page', (bool) preg_match(
    '#href="[^"]*/admin/reports/index\.php"[^>]*>\s*<i[^>]*></i> Back to Reports#', $vr), true);
check('the Print button is still there', str_contains($vr, 'visitor-record-print.php'), true);

echo "\n--- the Tourism Office mark ---\n";

$pages = [
    'homepage'              => test_get('/index.php'),
    'events'                => test_get('/events.php?slug=' . urlencode((string) $four['slug'])),
    'map'                   => test_get('/map.php'),
    'admin login'           => test_get('/admin/login.php'),
    'manager login'         => test_get('/manager/login.php'),
    'admin dashboard'       => test_get_as($sid, '/admin/dashboard.php'),
    'admin/reports/'        => test_get_as($sid, '/admin/reports/index.php'),
    'admin/announcements/'  => test_get_as($sid, '/admin/announcements/index.php?section=events'),
    'admin/destinations/'   => test_get_as($sid, '/admin/destinations/index.php'),
    'report print'          => test_get_as($sid, '/admin/reports/print.php?type=annual&year=2026'),
];

foreach ($pages as $label => $html) {
    preg_match('/<link rel="icon" href="([^"]+)"/', $html, $icon);
    check($label . ': favicon is the Tourism mark', str_contains($icon[1] ?? '', 'tourism-logo-mark.png'), true);
}

check('the admin sidebar carries the mark', (bool) preg_match(
    '#sidebar__brand">\s*<img src="[^"]*tourism-logo-mark\.png#', $pages['admin dashboard']), true);
check('the report letterhead pairs the seal with the mark',
    str_contains($pages['report print'], 'tampakan_logo.png') && str_contains($pages['report print'], 'letterhead__mark'), true);

/* The favicon URL must actually load from a nested folder, not just be named. */
preg_match('/<link rel="icon" href="([^"]+)"/', $pages['admin/reports/'], $icon);
/* base_url() writes absolute addresses, which is what makes them work from any
   folder depth; a relative one is resolved against the site root. */
$iconUrl = html_entity_decode($icon[1] ?? '');
$ch = curl_init(str_starts_with($iconUrl, 'http') ? $iconUrl : 'http://localhost' . $iconUrl);
curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true]);
curl_exec($ch);
check('and it loads from a nested page (HTTP 200, image/png)',
    curl_getinfo($ch, CURLINFO_HTTP_CODE) . ' ' . curl_getinfo($ch, CURLINFO_CONTENT_TYPE), '200 image/png');
curl_close($ch);

test_finish();
