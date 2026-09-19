<?php
declare(strict_types=1);

/**
 * The destination-scoped report: does the filter reach the database?
 *
 * The office generates these to check what the system holds against what a site
 * submitted to them. A report headed "Kolondatal Nature Park" that counts the
 * whole municipality underneath is worse than no report at all, because it looks
 * right — so every figure is checked against a hand-written query over
 * tourist_arrivals, not against another ReportBuilder call.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Core\ReportBuilder;

echo "=== reports: one destination at a time ===\n";

/* A period wide enough to hold everything on file, so the comparisons below are
   about the destination filter and not about the dates. */
$range = ReportBuilder::dataRange();

if ($range['first'] === null) {
    echo "  no arrivals on file — nothing to check\n";
    test_finish();
    return;
}

$start = (string) $range['first'];
$end   = (string) $range['last'];

$params = ['start' => $start, 'end' => $end];

$destinations = Database::all(
    "SELECT DISTINCT d.id, d.name
       FROM destinations d
       JOIN tourist_arrivals a ON a.destination_id = d.id AND a.status = 'valid'
      ORDER BY d.id"
);

check('at least one destination has arrivals', count($destinations) > 0, true);

echo "\n-- the unfiltered report still counts everything --\n";

$all = ReportBuilder::build('custom', $params);

$rawAll = Database::first(
    "SELECT COUNT(*) records, COALESCE(SUM(total_visitors),0) visitors
       FROM tourist_arrivals
      WHERE status = 'valid' AND visit_date BETWEEN ? AND ?",
    [$start, $end]
);

check('records match the database', $all['totals']['records'], (int) $rawAll['records']);
check('visitors match the database', $all['totals']['visitors'], (int) $rawAll['visitors']);
check('no destination is named on it', $all['destination'], null);

$sumOfParts = ['records' => 0, 'visitors' => 0];

foreach ($destinations as $d) {
    $id = (int) $d['id'];

    echo "\n-- #{$id} " . mb_substr((string) $d['name'], 0, 34) . " --\n";

    $report = ReportBuilder::build('custom', $params, $id);

    $raw = Database::first(
        "SELECT COUNT(*) records,
                COALESCE(SUM(total_visitors),0) visitors,
                COALESCE(SUM(CASE WHEN sex='male'   THEN total_visitors END),0) male,
                COALESCE(SUM(CASE WHEN sex='female' THEN total_visitors END),0) female,
                COUNT(DISTINCT visit_date) active_days
           FROM tourist_arrivals
          WHERE status = 'valid' AND visit_date BETWEEN ? AND ? AND destination_id = ?",
        [$start, $end, $id]
    );

    check('  the report names the destination',
        (int) ($report['destination']['id'] ?? 0), $id);
    check('  records match the database', $report['totals']['records'], (int) $raw['records']);
    check('  visitors match the database', $report['totals']['visitors'], (int) $raw['visitors']);
    check('  active days match', $report['totals']['active_days'], (int) $raw['active_days']);

    /* Gender totals are the figure the office reconciles against the paper
       sheet, so they are checked directly rather than trusted. */
    check('  male total matches', $report['demographics']['sex']['male'], (int) $raw['male']);
    check('  female total matches', $report['demographics']['sex']['female'], (int) $raw['female']);

    /* THE TABLE OF DESTINATIONS BECOMES ONE ROW. A filtered report that still
       lists every site reads as though the filter did nothing. */
    check('  it lists exactly one destination', count($report['destinations']), 1);
    check('  and it is the one asked for',
        (int) ($report['destinations'][0]['id'] ?? 0), $id);
    check('  that row agrees with the totals',
        (int) $report['destinations'][0]['visitors'], (int) $raw['visitors']);

    /* Every other section must be narrowed too — an unfiltered origins list on a
       filtered report is a privacy and accuracy problem at once. */
    $typeSum = array_sum($report['types']);
    check('  visitors by type sum to the total', $typeSum, (int) $raw['visitors']);

    $staySum = array_sum($report['stay']);
    check('  day/overnight sums to the total', $staySum, (int) $raw['visitors']);

    $sexSum = array_sum($report['demographics']['sex']);
    check('  the sex breakdown sums to the total', $sexSum, (int) $raw['visitors']);

    $timelineSum = array_sum(array_column($report['timeline'], 'visitors'));
    check('  the timeline sums to the total', $timelineSum, (int) $raw['visitors']);

    $sumOfParts['records']  += (int) $raw['records'];
    $sumOfParts['visitors'] += (int) $raw['visitors'];
}

echo "\n-- the parts add up to the whole --\n";
check('every record belongs to some destination',
    $sumOfParts['records'], (int) $rawAll['records']);
check('every visitor is counted once',
    $sumOfParts['visitors'], (int) $rawAll['visitors']);

echo "\n-- a destination with nothing in the period --\n";

/* Picking a window before the first record: the filter must produce an honest
   zero rather than falling back to the unfiltered figures. */
$empty = ReportBuilder::build('custom', ['start' => '2000-01-01', 'end' => '2000-01-02'],
    (int) $destinations[0]['id']);

check('visitors are zero', $empty['totals']['visitors'], 0);
check('records are zero', $empty['totals']['records'], 0);
check('the destination is still named', (int) ($empty['destination']['id'] ?? 0),
    (int) $destinations[0]['id']);
check('it still lists its one destination row', count($empty['destinations']), 1);

echo "\n-- an id that is not a destination --\n";

$bogus = ReportBuilder::build('custom', $params, 999999);

check('no figures are returned', $bogus['totals']['visitors'], 0);
check('and no destination is named', $bogus['destination'], null);
check('the destination table is empty', count($bogus['destinations']), 0);

test_finish();
