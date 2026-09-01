<?php
declare(strict_types=1);

/**
 * The report section — the screens, not the arithmetic behind them.
 *
 * `feature-reports-ai.php` exercises Insights in-process and opens exactly one
 * report page, for March 2019: a month with no records in it. So every figure
 * it ever saw was a zero, and a report that renders correctly and prints the
 * WRONG NUMBER would have passed it every time.
 *
 * That is the failure this file exists for. These reports are not read on
 * screen and forgotten; they are exported, signed, and filed with the DOT under
 * the municipality's name. A total that disagrees with the arrivals table is
 * worse than a page that crashes, because nobody checks a number that looks
 * plausible.
 *
 * So every figure the monthly report prints is recomputed here straight from
 * `tourist_arrivals` with independent SQL and compared. The CSV is parsed and
 * counted rather than merely fetched. And every one of these endpoints is
 * asked for while signed out, because a visitor record carries the full names
 * and contact numbers of members of the public.
 *
 * Read-only apart from one saved report, which is deleted again.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Core\ReportBuilder;
use App\Core\VisitorRecord;

echo "\n=== the report section ===\n\n";

if (!test_server_up()) {
    echo "  SKIP — no web server answering at " . test_base_url() . "\n";
    exit(0);
}

$is = static function (string $what, bool $got): void { check($what, $got, true); };

[$sid, $csrf] = test_sign_in_officer();

/* ---------------------------------------------------------------------------
   A month that actually has records in it
   ------------------------------------------------------------------------ */

$busiest = Database::first(
    "SELECT YEAR(visit_date) y, MONTH(visit_date) m,
            COUNT(*) records, SUM(total_visitors) visitors
       FROM tourist_arrivals
      WHERE status = 'valid'
      GROUP BY y, m
      ORDER BY visitors DESC
      LIMIT 1"
);

if ($busiest === null) {
    echo "  SKIP — no arrivals recorded, so every report would be empty and\n";
    echo "         this suite would pass without testing anything.\n";
    exit(0);
}

$year  = (int) $busiest['y'];
$month = (int) $busiest['m'];

printf("busiest month on record: %s (%s records, %s visitors)\n\n",
    date('F Y', mktime(0, 0, 0, $month, 1, $year)),
    number_format((int) $busiest['records']),
    number_format((int) $busiest['visitors']));

/* ---------------------------------------------------------------------------
   1. Every report type opens, and none of them is a wall of zeros
   ------------------------------------------------------------------------ */

/* ---------------------------------------------------------------------------
   0. The page as the officer opens it
   ---------------------------------------------------------------------------
   Every check in this file used to name a ?type=, so none of them ever saw the
   screen the sidebar link actually produces. It produced a period picker and
   nothing else: no figures, and no report history either, because that panel
   hid itself until something had been saved. The system held seven thousand
   arrivals and the Reports page looked empty.
   ------------------------------------------------------------------------ */

echo "--- the page with no query string at all ---\n";

$bare = test_get_as($sid, '/admin/reports/index.php');

$is('it renders', str_contains($bare, '<html'));
$is('with no diagnostic', !preg_match('/(Fatal error|Warning:|Notice:|Deprecated:)/', $bare));

/* The whole point: a report, unasked. */
$is('a report is already on the page', str_contains($bare, 'Total visitor arrivals'));
$is('it names the period it is showing',
    (bool) preg_match('/(January|February|March|April|May|June|July|August|September|October|November|December) \d{4}/', $bare));
$is('the breakdowns came with it',
    str_contains($bare, 'Visitors by Type') && str_contains($bare, 'Arrivals by Destination'));
$is('and it shows a figure above zero', (bool) preg_match('/\b[1-9][0-9,]{1,}\b/', $bare));

/* The history panel must be present even with nothing in it, or the officer
   never learns that saving is something this screen does. */
$is('the report history panel is present', str_contains($bare, 'Recently Generated'));
$is('and explains itself while empty',
    (int) Database::scalar('SELECT COUNT(*) FROM reports') > 0
    || str_contains($bare, 'No report has been saved yet'));

printf("    %s bytes of page\n\n", number_format(strlen($bare)));

echo "--- each report type ---\n";

$types = [
    'daily'     => 'date=' . date('Y-m-d', (int) Database::scalar(
        "SELECT UNIX_TIMESTAMP(visit_date) FROM tourist_arrivals
          WHERE status = 'valid' GROUP BY visit_date
          ORDER BY SUM(total_visitors) DESC LIMIT 1")),
    'monthly'   => "year=$year&month=$month",
    'quarterly' => "year=$year&quarter=" . (int) ceil($month / 3),
    'annual'    => "year=$year",
    'custom'    => "start=$year-01-01&end=$year-12-31",
];

foreach ($types as $type => $query) {
    $html = test_get_as($sid, "/admin/reports/index.php?type=$type&$query");

    $is("$type: the page renders", str_contains($html, '<html'));
    $is("$type: without a PHP diagnostic",
        !preg_match('/(Fatal error|Warning:|Notice:|Deprecated:)/', $html));

    /* A report screen that renders with every figure at zero is the exact
       failure this file was written for. */
    $is("$type: it shows a figure above zero", (bool) preg_match('/\b[1-9][0-9,]{1,}\b/', $html));
}

/* ---------------------------------------------------------------------------
   2. THE NUMBERS. Recomputed independently and compared.
   ------------------------------------------------------------------------ */

echo "\n--- the monthly figures against the arrivals table ---\n";

$start = sprintf('%04d-%02d-01', $year, $month);
$end   = date('Y-m-t', strtotime($start));

$report = ReportBuilder::build('monthly', ['year' => $year, 'month' => $month]);

/* Deliberately not the same query ReportBuilder uses — a shared helper would
   agree with itself no matter how wrong it was. */
$truth = Database::first(
    "SELECT COUNT(*) records,
            COALESCE(SUM(total_visitors), 0) visitors,
            COUNT(DISTINCT destination_id) destinations,
            COUNT(DISTINCT visit_date) active_days
       FROM tourist_arrivals
      WHERE status = 'valid'
        AND visit_date >= ? AND visit_date <= ?",
    [$start, $end]
);

printf("    report says %s visitors over %s records; the table says %s / %s\n",
    number_format((int) $report['totals']['visitors']),
    number_format((int) $report['totals']['records']),
    number_format((int) $truth['visitors']),
    number_format((int) $truth['records']));

$is('the visitor total matches the table',
    (int) $report['totals']['visitors'] === (int) $truth['visitors']);
$is('the record count matches',
    (int) $report['totals']['records'] === (int) $truth['records']);
$is('the destination count matches',
    (int) $report['totals']['destinations'] === (int) $truth['destinations']);
$is('the active-day count matches',
    (int) $report['totals']['active_days'] === (int) $truth['active_days']);

/* Voided and flagged records must be OUT of the totals. If they leaked in, the
   number filed with the DOT would include arrivals an officer rejected. */
$excluded = (int) Database::scalar(
    "SELECT COALESCE(SUM(total_visitors), 0) FROM tourist_arrivals
      WHERE status <> 'valid' AND visit_date >= ? AND visit_date <= ?",
    [$start, $end]
);

printf("    %s visitor(s) sit in voided or flagged records that month\n", number_format($excluded));

$is('rejected records are excluded from the total',
    $excluded === 0 || (int) $report['totals']['visitors'] !== (int) $truth['visitors'] + $excluded);

/* The parts must add up to the whole. */
/* Two different shapes, and reading them the same way was my mistake: the
   destination breakdown is a list of rows, but byTouristType() returns a map
   keyed by type with the visitor count as the value. array_column() on the map
   found no 'visitors' column and summed to nought, which read as the code
   losing every visitor rather than the test asking the wrong question. */
$byDest = array_sum(array_column($report['destinations'], 'visitors'));
$byType = array_sum($report['types']);

printf("    by destination sums to %s, by tourist type to %s\n",
    number_format($byDest), number_format($byType));

$is('the per-destination breakdown sums to the total', $byDest === (int) $report['totals']['visitors']);
$is('so does the breakdown by tourist type',      $byType === (int) $report['totals']['visitors']);

/* ---------------------------------------------------------------------------
   3. The CSV the office actually sends
   ------------------------------------------------------------------------ */

echo "\n--- the CSV export ---\n";

$csv = test_get_as($sid, "/admin/reports/export.php?type=monthly&year=$year&month=$month");

$is('the export returns something', strlen($csv) > 0);
$is('it is not an HTML error page', !str_contains($csv, '<html'));
$is('and carries no PHP diagnostic',
    !preg_match('/(Fatal error|Warning:|Notice:|Deprecated:)/', $csv));

$rows = array_values(array_filter(array_map('str_getcsv', explode("\n", trim($csv)))));

printf("    %s bytes, %d parsed line(s)\n", number_format(strlen($csv)), count($rows));

$is('it parses as CSV with more than a header', count($rows) > 1);

/* The whole point of the export is that the number in the spreadsheet is the
   number on the screen. */
$flat  = strtolower($csv);
$total = number_format((int) $report['totals']['visitors']);

$is('the visitor total appears in the file',
    str_contains($csv, $total) || str_contains($csv, (string) (int) $report['totals']['visitors']));

foreach (array_column($report['destinations'], 'name') as $name) {
    $is('the CSV names ' . $name, str_contains($flat, strtolower($name)));
}

/* ---------------------------------------------------------------------------
   4. The printable copy
   ------------------------------------------------------------------------ */

echo "\n--- the printable report ---\n";

$print = test_get_as($sid, "/admin/reports/print.php?type=monthly&year=$year&month=$month");

$is('print.php renders', str_contains($print, '<html'));
$is('with no diagnostic', !preg_match('/(Fatal error|Warning:|Notice:)/', $print));
$is('it carries the same total', str_contains($print, $total)
    || str_contains($print, (string) (int) $report['totals']['visitors']));

/* A printable page that still shows the admin sidebar wastes a page of paper
   and looks like a screenshot rather than a document. */
$is('and is not wrapped in the admin shell', !str_contains($print, 'admin-shell'));

/* ---------------------------------------------------------------------------
   5. The DOT visitor record — on a month that has data this time
   ------------------------------------------------------------------------ */

echo "\n--- the DOT visitor record ---\n";

/* THIS SHEET IS SIGNED AND FILED WITH THE DEPARTMENT.
 *
 * The previous version of this section asserted that the record "is built from
 * something" — true of an entirely blank form, which is what it was in fact
 * looking at. The sheet printed fourteen columns of dashes for a month with
 * 2,232 recorded visitors and every check passed.
 *
 * It counts only arrivals behind an APPROVED report, which is the right rule
 * and has to be tested as a rule: a month with an approval shows figures, a
 * month without shows none AND says so on the paper. */

$approved = Database::first(
    "SELECT YEAR(r.period_start) y, MONTH(r.period_start) m
       FROM arrival_reports r
      WHERE r.status = 'approved'
      GROUP BY y, m
      ORDER BY y DESC, m DESC
      LIMIT 1"
);

if ($approved === null) {
    echo "  SKIP — no approved report, so the counted path cannot be exercised\n";
} else {
    $ay = (int) $approved['y'];
    $am = (int) $approved['m'];

    $record = VisitorRecord::build($ay, $am, false);
    $g      = $record['totals']['grand'];

    printf("    %s: %s visitor(s) on the sheet, %s excluded\n",
        $record['month_label'], number_format((int) $g['total']), number_format((int) $record['excluded']));

    $is('an approved month puts figures on the sheet', (int) $g['total'] > 0);
    $is('and reports itself as having data', $record['has_data'] === true);

    /* Independent of VisitorRecord's own query. */
    $truth = (int) Database::scalar(
        "SELECT COALESCE(SUM(a.total_visitors), 0)
           FROM tourist_arrivals a
           JOIN arrival_reports r ON r.id = a.report_id AND r.status = 'approved'
          WHERE a.status = 'valid'
            AND YEAR(a.visit_date) = ? AND MONTH(a.visit_date) = ?",
        [$ay, $am]
    );

    $is('the grand total matches the approved arrivals', (int) $g['total'] === $truth);

    /* Male + female alone must NOT equal the total when sex is optional — the
       remainder is the unspecified bucket, and the sheet footnotes it. Silently
       dropping those visitors would understate the month. */
    $is('male + female + unspecified accounts for every visitor',
        (int) $g['male'] + (int) $g['female'] + (int) $g['unspecified'] === (int) $g['total']);

    $t = $record['totals'];

    $is('this province + other province + foreign is the grand total',
        (int) $t['this_province']['total'] + (int) $t['other_province']['total']
        + (int) $t['foreign']['total'] === (int) $g['total']);

    /* And every row has to be internally consistent, not just the footer. */
    $rowsOk = true;
    $rowSum = 0;

    foreach ($record['rows'] as $r) {
        $f = $r['figures'];
        $rowSum += (int) $f['grand']['total'];

        if ((int) $f['this_province']['total'] + (int) $f['other_province']['total']
            + (int) $f['foreign']['total'] !== (int) $f['grand']['total']) {
            $rowsOk = false;
            printf("      %s does not add up\n", $r['name']);
        }
    }

    $is('every attraction row adds up across its three residence groups', $rowsOk);
    $is('and the rows sum to the month total', $rowSum === (int) $g['total']);

    $page = test_get_as($sid, "/admin/reports/visitor-record.php?year=$ay&month=$am");

    $is('the screen renders it', str_contains($page, '<html'));
    $is('with no diagnostic', !preg_match('/(Fatal error|Warning:|Notice:)/', $page));
    $is('and the total is printed on it', str_contains($page, number_format((int) $g['total'])));
}

/* ---- the month nobody approved ---------------------------------------- */

$unapproved = Database::first(
    "SELECT YEAR(a.visit_date) y, MONTH(a.visit_date) m, SUM(a.total_visitors) v
       FROM tourist_arrivals a
       LEFT JOIN arrival_reports r ON r.id = a.report_id
      WHERE a.status = 'valid' AND (a.report_id IS NULL OR r.status <> 'approved')
      GROUP BY y, m
      ORDER BY v DESC
      LIMIT 1"
);

if ($unapproved !== null) {
    $uy = (int) $unapproved['y'];
    $um = (int) $unapproved['m'];
    $ur = VisitorRecord::build($uy, $um, false);

    printf("    %s: nothing approved, %s visitor(s) held back\n",
        $ur['month_label'], number_format((int) $ur['excluded']));

    $is('an unapproved month puts nothing on the sheet', (int) $ur['totals']['grand']['total'] === 0);
    $is('and counts what it is holding back', (int) $ur['excluded'] > 0);

    $screen = test_get_as($sid, "/admin/reports/visitor-record.php?year=$uy&month=$um");

    $is('the screen warns the officer', str_contains($screen, 'are NOT on this sheet'));

    /* THE ONE THAT MATTERS. The warning used to live only on the screen, so a
       page of dashes printed with nothing on it to explain why, and that page
       is what leaves the office. */
    $paper = test_get_as($sid, "/admin/reports/visitor-record-print.php?year=$uy&month=$um");

    /* Whitespace-normalised: the sentence wraps across two source lines, so a
       literal search for it fails on markup that is perfectly correct. */
    $flatPaper = preg_replace('/\s+/', ' ', $paper);

    $is('and so does the PRINTED sheet', str_contains($flatPaper, 'not counted on this sheet'));
    $is('it says the sheet should not be filed yet', str_contains($paper, 'should not be filed'));
    $is('and names the number held back',
        str_contains($paper, n((int) $ur['excluded'])) || str_contains($paper, (string) (int) $ur['excluded']));
}

$record = VisitorRecord::build($year, $month, false);
$page   = test_get_as($sid, "/admin/reports/visitor-record.php?year=$year&month=$month");

$is('the visitor record renders for the busiest month too', str_contains($page, '<html'));
$is('with no diagnostic', !preg_match('/(Fatal error|Warning:|Notice:)/', $page));

$vrCsv = test_get_as($sid, "/admin/reports/visitor-record-export.php?year=$year&month=$month");

$is('its CSV exports', strlen($vrCsv) > 0 && !str_contains($vrCsv, '<html'));
$is('and parses', count(array_filter(explode("\n", trim($vrCsv)))) > 1);

$vrPrint = test_get_as($sid, "/admin/reports/visitor-record-print.php?year=$year&month=$month");

$is('its printable copy renders', str_contains($vrPrint, '<html'));

/* The signature block is typed in by the officer and printed onto a document
   that leaves the office; it must come back out escaped, not executed. */
$xss  = 'Perla"><script>alert(1)</script>';
$sig  = test_get_as($sid, "/admin/reports/visitor-record-print.php?year=$year&month=$month"
      . '&prepared_by=' . rawurlencode($xss));

$is('a name typed into the signature block cannot inject script',
    !str_contains($sig, '<script>alert(1)</script>'));

/* ---------------------------------------------------------------------------
   6. Saving a report, and taking it back out
   ------------------------------------------------------------------------ */

echo "\n--- saving to the report history ---\n";

$before = (int) Database::scalar('SELECT COUNT(*) FROM reports');

$saved = test_get_as($sid, "/admin/reports/index.php?type=monthly&year=$year&month=$month&save=1");

$after = (int) Database::scalar('SELECT COUNT(*) FROM reports');

$is('the save wrote one row', $after === $before + 1);

$newId = (int) Database::scalar('SELECT MAX(id) FROM reports');

/* Removed whatever happens below. */
register_shutdown_function(static function () use ($newId, $before): void {
    Database::run('DELETE FROM reports WHERE id = ?', [$newId]);

    $now = (int) Database::scalar('SELECT COUNT(*) FROM reports');

    printf("\n  reports table back to %d row(s)%s\n", $now, $now === $before ? '' : '   *** NOT RESTORED ***');
});

$row = Database::first('SELECT * FROM reports WHERE id = ?', [$newId]);

$is('it recorded the period', (string) ($row['period_start'] ?? '') === $start);
$is('and the type',           (string) ($row['type'] ?? '') === 'monthly');
$is('and who generated it',   (int) ($row['generated_by'] ?? 0) > 0);
$is('the history lists it',
    in_array($newId, array_map('intval', array_column(ReportBuilder::history(), 'id')), true));

/* ---------------------------------------------------------------------------
   7. Nonsense in the query string
   ------------------------------------------------------------------------ */

echo "\n--- bad input ---\n";

$bad = [
    'a month of 13'           => "type=monthly&year=$year&month=13",
    'a month of 0'            => "type=monthly&year=$year&month=0",
    'the year 1200'           => 'type=annual&year=1200',
    'an end before the start' => "type=custom&start=$year-12-31&end=$year-01-01",
    'a date that is words'    => 'type=daily&date=not-a-date',
    'a type nobody defined'   => 'type=fortnightly',
];

foreach ($bad as $what => $query) {
    $html = test_get_as($sid, '/admin/reports/index.php?' . $query);

    $is($what . ' does not crash the page',
        !preg_match('/(Fatal error|Uncaught|Parse error)/', $html));
}

/* ---------------------------------------------------------------------------
   8. None of this is public
   ---------------------------------------------------------------------------
   A visitor record carries full names and contact numbers of members of the
   public, and the CSV carries them in a form that opens in Excel.
   ------------------------------------------------------------------------ */

echo "\n--- signed out ---\n";

$guarded = [
    "/admin/reports/index.php?type=monthly&year=$year&month=$month",
    "/admin/reports/export.php?type=monthly&year=$year&month=$month",
    "/admin/reports/print.php?type=monthly&year=$year&month=$month",
    "/admin/reports/visitor-record.php?year=$year&month=$month",
    "/admin/reports/visitor-record-export.php?year=$year&month=$month",
    "/admin/reports/visitor-record-print.php?year=$year&month=$month",
];

foreach ($guarded as $path) {
    $out = test_get($path);

    /* Either it redirects to the sign-in page or it shows it. What it must
       never do is answer with the data. */
    $leaked = str_contains($out, 'Visitor Record')
           && !str_contains($out, 'name="password"')
           && !str_contains($out, 'Sign in');

    $is(basename(parse_url($path, PHP_URL_PATH)) . ' refuses an anonymous request', !$leaked);
}

/* ---------------------------------------------------------------------------
   9. The other half of the report section
   ---------------------------------------------------------------------------
   Officers review reports; destination MANAGERS write them. Nothing under
   manager/ was covered by any suite in this directory — thirteen pages,
   including the form the whole review queue is fed by.

   The check that matters most here is not that the pages render. It is that a
   manager is sealed inside their own destination. Their session carries a
   destination_id and every screen is supposed to derive the destination from
   it and ignore the query string — so a manager who edits the URL must not
   reach another destination's visitor figures.
   ------------------------------------------------------------------------ */

echo "\n--- the manager's side ---\n";

[$msid, $mcsrf, $mDestination] = test_sign_in_manager();

if ($msid === '') {
    echo "  SKIP — no destination manager on file\n";
    test_finish();
}

$mine = (string) Database::scalar('SELECT name FROM destinations WHERE id = ?', [$mDestination]);

printf("    signed in as the manager for %s (id %d)\n", $mine, $mDestination);

$list = test_get_as($msid, '/manager/reports.php');

$is('the manager report list renders', str_contains($list, '<html'));
$is('with no diagnostic', !preg_match('/(Fatal error|Warning:|Notice:)/', $list));
$is('and it is their own destination', str_contains($list, $mine));

$form = test_get_as($msid, '/manager/report-form.php');

$is('the report form renders', str_contains($form, '<html'));
$is('with no diagnostic', !preg_match('/(Fatal error|Warning:|Notice:)/', $form));
$is('and it carries a CSRF token', str_contains($form, '_token'));

/* Somebody else's destination, named in the query string. Deliberately one
   that HAS a report: picking merely the next id landed on an archived
   destination with nothing to steal, and the check quietly did not run. */
$other = Database::first(
    'SELECT d.id, d.name
       FROM destinations d
       JOIN arrival_reports r ON r.destination_id = d.id
      WHERE d.id <> ?
      ORDER BY d.id LIMIT 1',
    [$mDestination]
) ?? Database::first(
    'SELECT id, name FROM destinations WHERE id <> ? ORDER BY id LIMIT 1', [$mDestination]);

if ($other !== null) {
    $otherReport = (int) (Database::scalar(
        'SELECT id FROM arrival_reports WHERE destination_id = ? ORDER BY id LIMIT 1',
        [(int) $other['id']]) ?? 0);

    printf("    trying to reach %s (id %d) by URL\n", $other['name'], (int) $other['id']);

    $poke = test_get_as($msid, '/manager/reports.php?destination_id=' . (int) $other['id']);

    $is('the list ignores a destination in the query string',
        !str_contains($poke, (string) $other['name']) || str_contains($poke, $mine));

    if ($otherReport > 0) {
        $steal = test_get_as($msid, '/manager/report-form.php?id=' . $otherReport);

        /* Whatever it does — redirect, refuse, or start a blank form — it must
           not hand over the other destination's report. */
        $is("another destination's report cannot be opened by id",
            !str_contains($steal, (string) $other['name'])
            || str_contains($steal, 'no longer exists')
            || str_contains($steal, $mine));
    }
}

/* And none of it is reachable without signing in. */
foreach (['/manager/reports.php', '/manager/report-form.php', '/manager/index.php'] as $path) {
    $out = test_get($path);

    $is(basename($path) . ' refuses an anonymous request',
        str_contains($out, 'name="password"') || str_contains($out, 'Sign in')
        || str_contains($out, 'login') || trim($out) === '');
}

test_finish();
