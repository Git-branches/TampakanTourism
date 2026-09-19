<?php
declare(strict_types=1);

/**
 * Old notifications that everyone has read are cleared by themselves.
 *
 * Nothing ever deleted a bell entry, so both bells would have carried every
 * notification ever sent. App\Core\Housekeeping now clears, once a day, the
 * ones older than six months that every person concerned has read — and never
 * an unread one.
 *
 * In process: the rule is SQL, and a web request adds nothing but a daily stamp.
 *
 * WHAT IT TOUCHES: only notifications titled "ZZQA …", a QA officer, and a QA
 * destination with two QA managers — purged on the way in and out. The real
 * notifications are counted before and after; they are all under six months
 * old, and the count must not move.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Core\Housekeeping;

echo "=== notification housekeeping ===\n\n";

$purge = static function (): void {
    Database::run("DELETE FROM admin_notifications WHERE title LIKE 'ZZQA%'");
    Database::run("DELETE FROM manager_notifications WHERE title LIKE 'ZZQA%'");
    Database::run("DELETE FROM admins WHERE username LIKE 'qahouse%'");
    foreach (Database::all("SELECT id FROM destinations WHERE name LIKE 'ZZQA%'") as $d) {
        Database::run('DELETE FROM destinations WHERE id = ?', [$d['id']]);   // managers, bell cascade
    }
    Database::run("DELETE FROM activity_logs WHERE action = 'housekeeping.notifications' AND description LIKE 'Cleared%'
                   AND created_at > NOW() - INTERVAL 1 HOUR AND admin_id IS NULL AND manager_id IS NULL");
};

$purge();
register_shutdown_function($purge);

$realOffice   = (int) Database::scalar("SELECT COUNT(*) FROM admin_notifications WHERE title NOT LIKE 'ZZQA%'");
$realManagers = (int) Database::scalar("SELECT COUNT(*) FROM manager_notifications WHERE title NOT LIKE 'ZZQA%'");

/* ---- the office bell --------------------------------------------------- */

/* A second active officer, so "read by one of two" can be told apart from
   "read by the office". */
$qaOfficer = Database::insert(
    "INSERT INTO admins (full_name, username, email, password_hash, role, is_active)
     VALUES ('QA Housekeeping', ?, ?, 'x', 'officer', 1)",
    ['qahouse' . random_int(1000, 9999), 'qahouse' . random_int(1000, 9999) . '@example.com']
);
$officers = array_map('intval', array_column(Database::all('SELECT id FROM admins WHERE is_active = 1'), 'id'));

$office = static function (string $title, int $daysOld, array $readBy): int {
    $id = Database::insert(
        "INSERT INTO admin_notifications (type, title, created_at) VALUES ('alert', ?, NOW() - INTERVAL ? DAY)",
        [$title, $daysOld]
    );
    foreach ($readBy as $admin) {
        Database::run('INSERT INTO admin_notification_reads (notification_id, admin_id) VALUES (?, ?)', [$id, $admin]);
    }
    return $id;
};

$oldReadByAll   = $office('ZZQA old, read by every officer', 200, $officers);
$oldReadBySome  = $office('ZZQA old, read by one officer', 200, [$officers[0] === $qaOfficer ? $officers[1] : $officers[0]]);
$oldUnread      = $office('ZZQA old, unread', 400, []);
$recentReadAll  = $office('ZZQA recent, read by every officer', 20, $officers);

/* ---- a destination's bell ---------------------------------------------- */

$dest = Database::insert(
    "INSERT INTO destinations (name, slug, qr_token, status) VALUES ('ZZQA housekeeping site', ?, ?, 'active')",
    ['zzqa-' . bin2hex(random_bytes(4)), bin2hex(random_bytes(16))]
);
$managers = [];
foreach (['A', 'B'] as $n) {
    $managers[] = Database::insert(
        "INSERT INTO destination_managers (destination_id, full_name, mobile_number, is_active) VALUES (?, ?, ?, 1)",
        [$dest, '_qa_ Housekeeping ' . $n, '0994' . random_int(1000000, 9999999)]
    );
}

$site = static function (string $title, int $daysOld, array $readBy) use ($dest): int {
    $id = Database::insert(
        "INSERT INTO manager_notifications (destination_id, type, title, created_at) VALUES (?, 'office', ?, NOW() - INTERVAL ? DAY)",
        [$dest, $title, $daysOld]
    );
    foreach ($readBy as $m) {
        Database::run('INSERT INTO manager_notification_reads (notification_id, manager_id) VALUES (?, ?)', [$id, $m]);
    }
    return $id;
};

$siteReadByBoth = $site('ZZQA old, read by both managers', 200, $managers);
$siteReadByOne  = $site('ZZQA old, read by one manager', 200, [$managers[0]]);

/* ---- run it ------------------------------------------------------------- */

$result = Housekeeping::pruneNotifications();

$exists = static fn (string $table, int $id): bool => Database::scalar("SELECT 1 FROM {$table} WHERE id = ?", [$id]) !== null;

echo "--- the office bell ---\n";
check('old and read by every officer: cleared', $exists('admin_notifications', $oldReadByAll), false);
check('and its read marks went with it',
    (int) Database::scalar('SELECT COUNT(*) FROM admin_notification_reads WHERE notification_id = ?', [$oldReadByAll]), 0);
check('old but read by only one officer: kept', $exists('admin_notifications', $oldReadBySome), true);
check('old and unread, however old: kept', $exists('admin_notifications', $oldUnread), true);
check('recent, even if read by all: kept', $exists('admin_notifications', $recentReadAll), true);

echo "\n--- a destination's bell ---\n";
check('old and read by both managers: cleared', $exists('manager_notifications', $siteReadByBoth), false);
check('old and read by one of the two: kept', $exists('manager_notifications', $siteReadByOne), true);

echo "\n--- nothing real ---\n";
check('the office\'s real notifications are all still there',
    (int) Database::scalar("SELECT COUNT(*) FROM admin_notifications WHERE title NOT LIKE 'ZZQA%'"), $realOffice);
check('and the managers\'',
    (int) Database::scalar("SELECT COUNT(*) FROM manager_notifications WHERE title NOT LIKE 'ZZQA%'"), $realManagers);
check('it reported what it cleared', $result, ['office' => 1, 'managers' => 1]);

echo "\n--- once a day ---\n";
$stamp = dirname(APP_PATH) . '/storage/cache/housekeeping-notifications.stamp';
@unlink($stamp);
Housekeeping::runDaily();
check('the first call of the day leaves a stamp', is_file($stamp), true);

$later = $office('ZZQA old, read by all, after the daily run', 200, $officers);
Housekeeping::runDaily();
check('a second call the same day does nothing', $exists('admin_notifications', $later), true);

test_finish();
