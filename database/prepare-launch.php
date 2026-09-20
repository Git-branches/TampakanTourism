<?php
declare(strict_types=1);

/**
 * =============================================================================
 *  TourSync — prepare the system for launch
 * -----------------------------------------------------------------------------
 *  Clears everything the office produced while TESTING, and keeps everything
 *  they AUTHORED. The difference is the whole point of this script:
 *
 *  KEPT   destinations and their photographs and routes, categories, tour
 *         guides with their certificates and credentials, the compliance
 *         requirements, office settings, the About photographs, the hero
 *         slides, and the officer's own sign-in.
 *
 *  CLEARED  arrivals, arrival reports and their logbook pages, inspections,
 *         alerts, inbound texts, messages, data requests, feedback, guide
 *         ratings, guide bookings, change requests, notifications, the
 *         activity log, the demo destinations, every announcement and event,
 *         the test videos, and the destination manager sign-ins.
 *
 *  WHY NOT reset.php: that one clears the destinations and the managers with
 *  everything else, which is right for a machine being prepared from nothing
 *  and wrong here — the office's fourteen destinations and nineteen guides are
 *  the content the site opens with.
 *
 *      php database/prepare-launch.php           shows what it would do
 *      php database/prepare-launch.php --yes     does it, after a backup
 *
 *  A DRY RUN BY DEFAULT, and a backup before any delete. This is not
 *  reversible from inside the application.
 * =============================================================================
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database;
use App\Repositories\AnnouncementRepository;
use App\Repositories\DestinationRepository;
use App\Repositories\VideoRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$go   = in_array('--yes', $argv, true);
$line = str_repeat('=', 68);

echo "\n{$line}\n  TourSync — prepare for launch\n{$line}\n\n";

/* ---------------------------------------------------------------------------
   What is here now
   ------------------------------------------------------------------------ */

$count = static function (string $sql): int {
    try {
        return (int) Database::scalar($sql);
    } catch (\Throwable) {
        return 0;
    }
};

/* The demo rows are known by id from the manifest seed-demo.php wrote, never by
   name: a demo destination renamed into a real one would otherwise be deleted
   as sample data. */
$manifest    = is_file(__DIR__ . '/../storage/demo-seed.json')
    ? (json_decode((string) file_get_contents(__DIR__ . '/../storage/demo-seed.json'), true) ?: [])
    : [];
$demoDestIds = array_map('intval', $manifest['destinations'] ?? []);

$demoDestinations = $demoDestIds === []
    ? []
    : Database::all(
        'SELECT id, name FROM destinations WHERE id IN (' . implode(',', array_fill(0, count($demoDestIds), '?')) . ')',
        $demoDestIds
    );

$keep = [
    'destinations (real)'       => $count('SELECT COUNT(*) FROM destinations') - count($demoDestinations),
    'destination photographs'   => $count('SELECT COUNT(*) FROM destination_photos p JOIN destinations d ON d.id = p.destination_id'
                                          . ($demoDestIds ? ' WHERE p.destination_id NOT IN (' . implode(',', $demoDestIds) . ')' : '')),
    'destination routes'        => $count('SELECT COUNT(*) FROM destination_routes'),
    'categories'                => $count('SELECT COUNT(*) FROM categories'),
    'tour guides'               => $count('SELECT COUNT(*) FROM tour_guides'),
    'guide certificates'        => $count('SELECT COUNT(*) FROM tour_guide_certificates'),
    'guide credentials'         => $count('SELECT COUNT(*) FROM tour_guide_credentials'),
    'compliance requirements'   => $count('SELECT COUNT(*) FROM inspection_requirements'),
    'office settings'           => $count('SELECT COUNT(*) FROM settings'),
    'About photographs'         => $count('SELECT COUNT(*) FROM about_photos'),
    'hero slides'               => $count('SELECT COUNT(*) FROM hero_slides'),
    'officer accounts'          => $count('SELECT COUNT(*) FROM admins'),
];

/* Child before parent, so a foreign key is never the reason a delete fails.
   tourist_arrivals and arrival_reports are RESTRICT against destinations,
   which is why the demo destinations go last of all. */
$clear = [
    'arrival_report_entries'      => 'arrival report lines (logbook pages)',
    'arrival_report_days'         => 'arrival report day totals',
    'arrival_report_documents'    => 'uploaded logbook documents',
    'tourist_arrivals'            => 'tourist arrival records',
    'arrival_reports'             => 'arrival reports',
    'arrival_daily_summary'       => 'daily arrival summaries',
    'inspection_photos'           => 'compliance photographs',
    'inspection_items'            => 'compliance checklist rows',
    'inspection_reports'          => 'compliance reports',
    'destination_alerts'          => 'destination alerts',
    'sms_inbox'                   => 'inbound text messages',
    'contact_messages'            => 'messages from the public',
    'data_requests'               => 'data requests',
    'feedback'                    => 'visitor reviews',
    'guide_reviews'               => 'guide ratings',
    'tour_request_destinations'   => 'guide booking destinations',
    'tour_guide_requests'         => 'guide bookings',
    'destination_change_requests' => 'manager change requests',
    'notifications'               => 'SMS delivery records',
    'manager_notification_reads'  => 'manager bell read marks',
    'manager_notifications'       => 'manager bell entries',
    'admin_notification_reads'    => 'office bell read marks',
    'admin_notifications'         => 'office bell entries',
    'destination_managers'        => 'destination manager sign-ins',
    'reports'                     => 'saved report history',
    'activity_logs'               => 'activity log',
];

echo "  KEPT — the office's own content\n";
foreach ($keep as $what => $n) {
    printf("      %-28s %s\n", $what, number_format($n));
}

echo "\n  CLEARED — records produced while testing\n";
$clearTotal = 0;
foreach ($clear as $table => $what) {
    $n = $count("SELECT COUNT(*) FROM `{$table}`");
    $clearTotal += $n;
    if ($n > 0) {
        printf("      %-28s %s\n", $what, number_format($n));
    }
}

$announcements = Database::all('SELECT id FROM announcements');
$videos        = Database::all('SELECT id FROM promo_videos');

printf("      %-28s %s\n", 'announcements and events', number_format(count($announcements)));
printf("      %-28s %s\n", 'promotional videos', number_format(count($videos)));
printf("      %-28s %s\n", 'demo destinations', number_format(count($demoDestinations)));

foreach ($demoDestinations as $d) {
    printf("            #%-5d %s\n", $d['id'], $d['name']);
}

$clearTotal += count($announcements) + count($videos) + count($demoDestinations);

if (!$go) {
    echo "\n  DRY RUN — nothing has been changed.\n";
    echo "  " . number_format($clearTotal) . " row(s) would be deleted.\n\n";
    echo "  When you are ready:  php database/prepare-launch.php --yes\n";
    echo "  (a backup is written automatically before anything is deleted)\n\n";
    exit(0);
}

/* ---------------------------------------------------------------------------
   The backup, first and always
   ------------------------------------------------------------------------ */

$config = require __DIR__ . '/../app/config/config.php';
$db     = $config['database'];
$stamp  = date('Ymd-His');

/* OUTSIDE THE WEBROOT. A dump holds every visitor name and contact number in
   the system; written next to the site it would be downloadable by anyone who
   guessed the filename. C:\xampp\ is one level above what Apache serves. */
$backupDir = 'C:/xampp/TourSync-backups';

if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
    exit("\n  STOPPED: could not create {$backupDir} for the backup.\n\n");
}

$target = "{$backupDir}/toursync-before-launch-{$stamp}.sql";
$dump   = 'C:/xampp/mysql/bin/mysqldump.exe';

if (is_file($dump)) {
    $cmd = sprintf(
        '"%s" -u %s %s --single-transaction --routines %s > "%s"',
        $dump,
        escapeshellarg($db['user']),
        $db['password'] !== '' ? '-p' . escapeshellarg($db['password']) : '',
        escapeshellarg($db['name']),
        $target
    );

    exec($cmd, $out, $code);

    if ($code !== 0 || !is_file($target) || filesize($target) < 10000) {
        exit("\n  STOPPED: the backup did not write. Nothing was deleted.\n"
             . "  Take one by hand first:  mysqldump -u root toursync > backup.sql\n\n");
    }

    printf("\n  Backup written: %s (%s MB)\n", $target, number_format(filesize($target) / 1048576, 1));
} else {
    exit("\n  STOPPED: mysqldump was not found, so no backup could be taken.\n"
         . "  Take one by hand first, then run this again.\n\n");
}

/* ---------------------------------------------------------------------------
   The clearing
   ------------------------------------------------------------------------ */

echo "\n  Clearing:\n";

/* Files first for the two that own them, through the repositories that know
   which file belongs to which row — a plain DELETE would leave the video and
   the banners on disk with nothing pointing at them. */
foreach ($announcements as $a) {
    AnnouncementRepository::delete((int) $a['id']);
}
printf("      %-34s %s\n", 'announcements and events', number_format(count($announcements)));

foreach ($videos as $v) {
    VideoRepository::delete((int) $v['id']);
}
printf("      %-34s %s\n", 'promotional videos', number_format(count($videos)));

/* Compliance photographs live in storage/, named by the row. */
$inspectionFiles = array_column(Database::all('SELECT stored_name FROM inspection_photos'), 'stored_name');

Database::transaction(static function () use ($clear): void {
    foreach (array_keys($clear) as $table) {
        Database::run("DELETE FROM `{$table}`");
    }
});

foreach ($clear as $table => $what) {
    printf("      %-34s cleared\n", $what);
}

foreach ($inspectionFiles as $name) {
    $path = dirname(__DIR__) . '/storage/inspections/' . basename((string) $name);
    if (is_file($path)) {
        @unlink($path);
    }
}
printf("      %-34s %s file(s) removed\n", 'compliance photographs on disk', number_format(count($inspectionFiles)));

/* The demo destinations last: their arrivals and reports are gone by now, so
   nothing holds them, and deleteIfUnused() refuses if anything still does. */
$removed = 0;
foreach ($demoDestinations as $d) {
    if (DestinationRepository::deleteIfUnused((int) $d['id'])) {
        $removed++;
    } else {
        printf("      kept #%d %s — something still refers to it\n", $d['id'], $d['name']);
    }
}
printf("      %-34s %s\n", 'demo destinations', number_format($removed));

/* The manifest described rows that no longer exist. Left in place but emptied
   of what is gone, so seed-demo.php --undo cannot later delete a real row that
   happens to have taken a freed id. */
if ($manifest !== []) {
    file_put_contents(
        __DIR__ . '/../storage/demo-seed.json',
        json_encode(['_note' => 'cleared by prepare-launch.php on ' . date('Y-m-d'), '_files' => []],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    echo "      demo manifest emptied\n";
}

/* ---------------------------------------------------------------------------
   What is left
   ------------------------------------------------------------------------ */

echo "\n  What the system now holds:\n";
foreach ([
    'destinations'            => 'SELECT COUNT(*) FROM destinations',
    'destination photographs' => 'SELECT COUNT(*) FROM destination_photos',
    'tour guides'             => 'SELECT COUNT(*) FROM tour_guides',
    'guide certificates'      => 'SELECT COUNT(*) FROM tour_guide_certificates',
    'compliance requirements' => 'SELECT COUNT(*) FROM inspection_requirements',
    'office settings'         => 'SELECT COUNT(*) FROM settings',
    'hero slides'             => 'SELECT COUNT(*) FROM hero_slides',
    'About photographs'       => 'SELECT COUNT(*) FROM about_photos',
    'officer accounts'        => 'SELECT COUNT(*) FROM admins',
    'tourist arrivals'        => 'SELECT COUNT(*) FROM tourist_arrivals',
    'managers'                => 'SELECT COUNT(*) FROM destination_managers',
    'announcements'           => 'SELECT COUNT(*) FROM announcements',
] as $what => $sql) {
    printf("      %-28s %s\n", $what, number_format($count($sql)));
}

echo "\n  Done. The backup above is the way back if anything was wanted after all.\n\n";
