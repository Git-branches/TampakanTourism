<?php
declare(strict_types=1);

/**
 * =============================================================================
 *  TourSync — reset to a clean state
 * -----------------------------------------------------------------------------
 *  Clears every record produced by testing and demonstration, leaving the
 *  system ready for real Tampakan data.
 *
 *      php database/reset.php
 *
 *  REMOVED                          KEPT
 *    tourist arrivals                 one Tourism Officer account
 *    daily summaries                  destination categories
 *    feedback                         office settings
 *    announcements + notifications
 *    destination managers
 *    destinations + their photos
 *    saved reports
 *    activity log
 *
 *  Unlike install.php this does not touch the schema — no table is dropped or
 *  recreated, so a system already carrying real data keeps its structure and
 *  every migration that has been applied to it.
 * =============================================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('The reset script runs from the command line only.');
}

$root   = dirname(__DIR__);
$config = require $root . '/app/config/config.php';
$db     = $config['database'];

$pdo = new PDO(
    "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}",
    $db['user'], $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$line = str_repeat('=', 66);
echo "\n{$line}\n  TourSync — reset to a clean state\n{$line}\n\n";

/* Uploaded files are removed from disk before their rows disappear —
   afterwards there is no record of which files belonged to the system, and
   they would sit in uploads/ forever. */
$orphans = $pdo->query('SELECT file_path FROM destination_photos')->fetchAll(PDO::FETCH_COLUMN);
$deleted = 0;

foreach ($orphans as $relative) {
    if (!is_string($relative) || !str_starts_with($relative, 'uploads/') || str_contains($relative, '..')) {
        continue;
    }
    $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (is_file($absolute) && @unlink($absolute)) {
        $deleted++;
    }
}

echo "  Removed {$deleted} uploaded photo file(s) from disk\n\n";

/* Child tables first so foreign keys are never the reason a delete fails.
   tourist_arrivals in particular is ON DELETE RESTRICT against destinations,
   which is deliberate — arrivals are official statistics and must not vanish
   because a destination was removed. Clearing them explicitly, in order, is
   the honest way to reset rather than switching the constraint off. */
$order = [
    'notifications'         => 'SMS delivery records',
    'announcements'         => 'announcements and advisories',
    'feedback'              => 'visitor reviews',
    'arrival_daily_summary' => 'daily rollups',
    'tourist_arrivals'      => 'arrival records',
    'destination_managers'  => 'manager contacts',
    'destination_photos'    => 'destination photos',
    'destinations'          => 'destinations',
    'reports'               => 'saved reports',
    'activity_logs'         => 'activity log entries',
];

echo "  Clearing tables\n";

foreach ($order as $table => $label) {
    $before = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    $pdo->exec("DELETE FROM `{$table}`");
    $pdo->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
    printf("      %-24s %d %s removed\n", $table, $before, $label);
}

/* Exactly one administrator. The account kept is the active Tourism Officer
   with the lowest id — the one the installer created. */
echo "\n  Administrator accounts\n";

$keep = $pdo->query(
    "SELECT id, username, full_name FROM admins
      WHERE role = 'officer' AND is_active = 1
      ORDER BY id LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if ($keep === false) {
    echo "      No active officer found — nothing was removed, so the system\n";
    echo "      cannot be left without a way in. Check the admins table.\n";
} else {
    $stmt = $pdo->prepare('DELETE FROM admins WHERE id <> ?');
    $stmt->execute([$keep['id']]);
    printf("      removed %d other account(s)\n", $stmt->rowCount());
    printf("      kept    %s (%s)\n", $keep['username'], $keep['full_name']);
}

/* Rate-limit buckets and the SMS test log are working files, not records. */
foreach ([$root . '/storage/ratelimit', $root . '/storage/logs'] as $dir) {
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file)) { @unlink($file); }
    }
}
echo "\n  Cleared rate-limit buckets and the SMS test log\n";

echo "\n{$line}\n  Final state\n{$line}\n";

foreach (['admins', 'categories', 'settings', 'destinations', 'tourist_arrivals',
          'feedback', 'announcements', 'destination_managers', 'activity_logs'] as $table) {
    printf("      %-24s %d\n", $table, (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn());
}

echo "\n  Ready for real Tampakan data.\n\n";
