<?php
declare(strict_types=1);

/**
 * FEATURE 2 — tourist arrival monitoring and centralised data synchronisation.
 *
 * The whole point of this feature is a JOIN between two people who never meet:
 * a destination manager submits the month's figures, and the Municipal Tourism
 * Officer approves them into the municipality's records. Either half can be
 * tested on its own and pass while the join is broken.
 *
 * So this drives the real workflow end to end — draft, days, submit, approve —
 * and then checks the figures actually landed in arrival_daily_summary, which
 * is what the dashboard, the analytics and the DOT return are all built from.
 *
 * EVERY ROW IT CREATES IS DELETED, including the summary rows approval writes.
 * The cleanup is registered before the first insert, so a fatal halfway through
 * cannot leave invented visitors in the official statistics.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Repositories\ArrivalReportRepository as Reports;

echo "\n=== feature 2: arrival monitoring and synchronisation ===\n\n";

if (!test_server_up()) {
    echo "  SKIP — no web server answering at " . test_base_url() . "\n";
    exit(0);
}

[$sid, $csrf] = test_sign_in_officer();
[$msid, $mcsrf, $managerDest] = test_sign_in_manager();

if ($managerDest === 0) {
    echo "  SKIP — no destination manager to submit as\n";
    exit(0);
}

$dest = Database::first('SELECT id, name FROM destinations WHERE id = ?', [$managerDest]);

printf("manager's destination: %s (id %d)\n\n", $dest['name'], $managerDest);

/* A period far enough back that it cannot collide with anything real. */
$start = '2019-01-07';
$end   = '2019-01-09';

$summaryBefore = (int) Database::scalar('SELECT COUNT(*) FROM arrival_daily_summary');
$reportsBefore = (int) Database::scalar('SELECT COUNT(*) FROM arrival_reports');

/* REGISTERED BEFORE ANYTHING IS WRITTEN. Approval copies figures into
   arrival_daily_summary, and a crash between the two would leave invented
   visitors counted in the municipality's own statistics. */
register_shutdown_function(static function () use ($managerDest, $start, $end): void {
    foreach (Database::all(
        'SELECT id FROM arrival_reports WHERE destination_id = ? AND period_start = ?',
        [$managerDest, $start]
    ) as $row) {
        Database::run('DELETE FROM arrival_report_days WHERE report_id = ?', [(int) $row['id']]);
        Database::run('DELETE FROM arrival_reports WHERE id = ?', [(int) $row['id']]);
    }

    Database::run(
        'DELETE FROM arrival_daily_summary WHERE destination_id = ? AND visit_date BETWEEN ? AND ?',
        [$managerDest, $start, $end]
    );

    echo "  (probe report and its summary rows removed)\n";
});

echo "--- a manager starts a report and enters the days ---\n";

$reportId = Reports::createDraft($managerDest, $start, $end, 'ZZ probe report');

check('a draft was created', $reportId > 0, true);
check('it starts as a draft',
    (string) Database::scalar('SELECT status FROM arrival_reports WHERE id = ?', [$reportId]), 'draft');

Reports::replaceDays($reportId, [
    ['visit_date' => '2019-01-07', 'local_count' => 10, 'domestic_count' => 4,
     'foreign_count' => 1, 'ofw_count' => 0, 'male_count' => 7, 'female_count' => 8,
     'children_count' => 2, 'adults_count' => 11, 'seniors_count' => 2],
    ['visit_date' => '2019-01-08', 'local_count' => 6, 'domestic_count' => 2,
     'foreign_count' => 0, 'ofw_count' => 1, 'male_count' => 4, 'female_count' => 5,
     'children_count' => 1, 'adults_count' => 7, 'seniors_count' => 1],
    ['visit_date' => '2019-01-09', 'local_count' => 3, 'domestic_count' => 0,
     'foreign_count' => 2, 'ofw_count' => 0, 'male_count' => 2, 'female_count' => 3,
     'children_count' => 0, 'adults_count' => 5, 'seniors_count' => 0],
]);

$days = Reports::days($reportId);

check('three days were recorded', count($days), 3);

$expected = (10 + 4 + 1 + 0) + (6 + 2 + 0 + 1) + (3 + 0 + 2 + 0);   /* 29 */

$total = 0;

foreach ($days as $day) {
    $total += (int) $day['total_visitors'];
}

check('the day totals add up', $total, $expected);

echo "\n--- the manager submits it ---\n";

$manager = Database::first('SELECT id FROM destination_managers WHERE destination_id = ? LIMIT 1',
    [$managerDest]);

Reports::submit($reportId, (int) $manager['id']);

check('the status moved to submitted',
    (string) Database::scalar('SELECT status FROM arrival_reports WHERE id = ?', [$reportId]),
    'submitted');

echo "\n--- it appears in the office queue ---\n";

$queue = test_get('admin/arrival-reports/index.php');

/* The officer is signed in by cookie for the POST below; this GET is anonymous
   and should be refused, which is itself worth knowing. */
$queueAuthed = test_post('admin/arrival-reports/index.php', $sid, ['_token' => $csrf]);

check('the queue is not readable without signing in',
    str_contains($queue, 'ZZ probe report'), false);

$review = test_get_as($sid, 'admin/arrival-reports/review.php?id=' . $reportId);

check('the officer can open it', str_contains($review, (string) $dest['name']), true);
check('and sees the total', str_contains($review, (string) $expected), true);

echo "\n--- THE JOIN: the officer approves it ---\n";

$r = test_post('admin/arrival-reports/review.php?id=' . $reportId, $sid, [
    '_token' => $csrf,
    'action' => 'approve',
]);

check('the approval was accepted (302)', $r['code'], 302);
check('the report is approved',
    (string) Database::scalar('SELECT status FROM arrival_reports WHERE id = ?', [$reportId]),
    'approved');

echo "\n--- and the figures reached the municipality's records ---\n";

$summary = Database::all(
    'SELECT * FROM arrival_daily_summary WHERE destination_id = ? AND visit_date BETWEEN ? AND ?
     ORDER BY visit_date',
    [$managerDest, $start, $end]
);

check('three days were synchronised', count($summary), 3);

$synced = 0;

foreach ($summary as $row) {
    $synced += (int) $row['total_visitors'];
}

check('every visitor carried across', $synced, $expected);
check('the foreign count survived the copy',
    array_sum(array_map('intval', array_column($summary, 'foreign_count'))), 3);

echo "\n--- the live dashboard endpoint reflects it ---\n";

$stats = test_get_as($sid, 'api/admin/stats.php');
$json  = json_decode($stats, true);

check('the endpoint returns JSON', is_array($json), true);

if (is_array($json)) {
    printf("    keys: %s\n", implode(', ', array_slice(array_keys($json), 0, 8)));

    /* WHICH COUNTER MOVES DEPENDS ON WHAT WAS SUBMITTED, and that is worth
       stating rather than asserting away.

       Approving does two things: it writes the DAY TOTALS into
       arrival_daily_summary, and it publishes any TRANSCRIBED NAMES from
       arrival_report_entries into tourist_arrivals. The headline counters —
       today, month, total, records — count individual rows in
       tourist_arrivals; "most visited" reads the summary.

       This report carried day totals and no transcribed names, which is a real
       and ordinary way to file one. So the summary moves and the per-record
       counters correctly do not. My first version of this test expected the
       total to rise and read the result as a broken dashboard. It was the test
       that was wrong. */
    check('the endpoint answers with the counters the dashboard draws',
        array_key_exists('total', $json) && array_key_exists('most_visited', $json), true);

    check('most visited reflects the approved summary',
        $json['most_visited'] !== null
        && (int) $json['most_visited']['visitors'] >= $expected, true);

    printf("    most visited: %s\n", $json['most_visited'] === null
        ? '(none)'
        : $json['most_visited']['name'] . ' — ' . $json['most_visited']['visitors'] . ' visitors');
}

echo "\n--- an empty report cannot be approved ---\n";

$emptyId = Reports::createDraft($managerDest, '2019-02-04', '2019-02-05', 'ZZ empty probe');
Reports::submit($emptyId, (int) $manager['id']);

$r = test_post('admin/arrival-reports/review.php?id=' . $emptyId, $sid, [
    '_token' => $csrf,
    'action' => 'approve',
]);

check('it stays submitted',
    (string) Database::scalar('SELECT status FROM arrival_reports WHERE id = ?', [$emptyId]),
    'submitted');

Database::run('DELETE FROM arrival_reports WHERE id = ?', [$emptyId]);

echo "\n--- the offline queue endpoint is reachable ---\n";

/* A manager standing at a waterfall has no signal. The queue holds entries in
   the browser and needs a CSRF token from the server to flush them later. */
$tokenJson = test_get('api/arrivals/token.php');

check('the token endpoint answers with JSON',
    is_array(json_decode($tokenJson, true)), true);

echo "\n--- clean up ---\n";

Database::run('DELETE FROM arrival_report_days WHERE report_id = ?', [$reportId]);
/* The bell row this report's approval created goes with it. Without
   this the table collects one orphan per suite run, pointing at a
   report id that no longer exists. */
Database::run(
    'DELETE FROM manager_notifications WHERE entity_type = ? AND entity_id = ?',
    ['arrival_report', $reportId]
);

Database::run('DELETE FROM arrival_reports WHERE id = ?', [$reportId]);
Database::run(
    'DELETE FROM arrival_daily_summary WHERE destination_id = ? AND visit_date BETWEEN ? AND ?',
    [$managerDest, $start, $end]
);

check('reports are back to where they started',
    (int) Database::scalar('SELECT COUNT(*) FROM arrival_reports'), $reportsBefore);
check('and the summary table too',
    (int) Database::scalar('SELECT COUNT(*) FROM arrival_daily_summary'), $summaryBefore);

test_finish();
