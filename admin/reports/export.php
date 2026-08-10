<?php
declare(strict_types=1);

/**
 * Report export as CSV.
 *
 * Built from the same ReportBuilder::build() call as the screen and the print
 * view, so the downloaded file cannot disagree with what the officer saw.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\ReportBuilder;
use App\Repositories\ArrivalRepository;

Auth::require();

$type = (string) ($_GET['type'] ?? 'monthly');
if (!isset(ReportBuilder::PERIODS[$type])) {
    $type = 'monthly';
}

$report = ReportBuilder::build($type, [
    'date'    => (string) ($_GET['date'] ?? date('Y-m-d')),
    'year'    => (int) ($_GET['year'] ?? date('Y')),
    'month'   => (int) ($_GET['month'] ?? date('n')),
    'quarter' => (int) ($_GET['quarter'] ?? 1),
    'start'   => (string) ($_GET['start'] ?? date('Y-m-01')),
    'end'     => (string) ($_GET['end'] ?? date('Y-m-d')),
]);

ActivityLog::record('report.export', 'report', null,
    ReportBuilder::PERIODS[$type] . ' report exported for ' . $report['period']['label']);

$filename = 'tampakan-' . $type . '-report-' . $report['period']['start'] . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');

$out = fopen('php://output', 'w');

// UTF-8 BOM so Excel on Windows renders accented place names correctly.
fwrite($out, "\xEF\xBB\xBF");

$section = static function (string $title) use ($out): void {
    fputcsv($out, []);
    fputcsv($out, [mb_strtoupper($title)]);
};

// ---- Header -----------------------------------------------------------------
fputcsv($out, ['MUNICIPAL TOURISM OFFICE — ' . mb_strtoupper((string) setting('office_municipality', 'MUNICIPALITY OF TAMPAKAN'))]);
fputcsv($out, [mb_strtoupper(ReportBuilder::PERIODS[$type]) . ' TOURIST ARRIVAL REPORT']);
fputcsv($out, ['Period', $report['period']['label']]);
fputcsv($out, ['Covering', $report['period']['start'], 'to', $report['period']['end']]);
fputcsv($out, ['Generated', $report['generated_at']]);

// ---- Summary ----------------------------------------------------------------
$t = $report['totals'];
$section('Summary');
fputcsv($out, ['Total visitor arrivals', $t['visitors']]);
fputcsv($out, ['Logbook entries', $t['records']]);
fputcsv($out, ['Average party size', $t['avg_party']]);
fputcsv($out, ['Visitors per day', $t['daily_avg']]);
fputcsv($out, ['Destinations visited', $t['destinations']]);
fputcsv($out, ['Days with recorded arrivals', $t['active_days'] . ' of ' . $t['period_days']]);

$c = $report['comparison'];
fputcsv($out, ['Compared with ' . $c['label'], $c['previous']]);
fputcsv($out, ['Change', $c['change'], $c['change_pct'] === null ? 'no basis for comparison' : $c['change_pct'] . '%']);

// ---- Classification ---------------------------------------------------------
$section('Visitors by type');
fputcsv($out, ['Classification', 'Visitors', 'Share %']);
foreach ($report['types'] as $key => $count) {
    fputcsv($out, [
        ArrivalRepository::TYPES[$key] ?? $key,
        $count,
        $t['visitors'] > 0 ? round($count / $t['visitors'] * 100, 1) : 0,
    ]);
}

$section('Day visitors vs overnight');
fputcsv($out, ['Stay type', 'Visitors']);
fputcsv($out, ['Day visitors (excursionists)', $report['stay']['day_trip']]);
fputcsv($out, ['Overnight tourists', $report['stay']['overnight']]);
fputcsv($out, ['Not stated', $report['stay']['not_stated']]);

// ---- Destinations -----------------------------------------------------------
$section('Arrivals by destination');
fputcsv($out, ['Destination', 'Barangay', 'Entries', 'Visitors', 'Share %']);
foreach ($report['destinations'] as $d) {
    fputcsv($out, [
        $d['name'], $d['barangay'], $d['records'], $d['visitors'],
        $t['visitors'] > 0 ? round($d['visitors'] / $t['visitors'] * 100, 1) : 0,
    ]);
}

// ---- Demographics -----------------------------------------------------------
$section('Age groups');
fputcsv($out, ['Age group', 'Visitors']);
$ageLabels = ArrivalRepository::AGE_BRACKETS + ['not_stated' => 'Not stated'];
foreach ($report['demographics']['age'] as $key => $count) {
    fputcsv($out, [$ageLabels[$key] ?? $key, $count]);
}

$section('Sex');
fputcsv($out, ['Sex', 'Visitors']);
foreach (['male' => 'Male', 'female' => 'Female', 'prefer_not_to_say' => 'Prefer not to say', 'not_stated' => 'Not stated'] as $key => $label) {
    fputcsv($out, [$label, $report['demographics']['sex'][$key]]);
}

// ---- Origins ----------------------------------------------------------------
foreach (['cities' => 'Top cities / municipalities of origin', 'provinces' => 'Top provinces of origin', 'countries' => 'Top countries of origin'] as $key => $label) {
    if ($report['origins'][$key] === []) {
        continue;
    }
    $section($label);
    fputcsv($out, ['Place', 'Visitors']);
    foreach ($report['origins'][$key] as $o) {
        fputcsv($out, [$o['place'], $o['visitors']]);
    }
}

// ---- Purpose ----------------------------------------------------------------
$section('Purpose of visit');
fputcsv($out, ['Purpose', 'Visitors']);
$purposeLabels = ArrivalRepository::PURPOSES + ['not_stated' => 'Not stated'];
foreach ($report['purposes'] as $key => $count) {
    fputcsv($out, [$purposeLabels[$key] ?? $key, $count]);
}

// ---- Timeline ---------------------------------------------------------------
if ($report['timeline'] !== []) {
    $section('Arrivals over time');
    fputcsv($out, ['Period', 'Visitors']);
    foreach ($report['timeline'] as $point) {
        fputcsv($out, [$point['label'], $point['visitors']]);
    }
}

// ---- Integrity --------------------------------------------------------------
$i = $report['integrity'];
$section('Record integrity');
fputcsv($out, ['Counted entries', $i['valid_records']]);
fputcsv($out, ['Visitors self-recorded by QR scan', $i['qr_visitors']]);
fputcsv($out, ['Visitors recorded manually by staff', $i['manual_visitors']]);
fputcsv($out, ['Records excluded — awaiting review', $i['flagged_records'], $i['flagged_visitors'] . ' visitors']);
fputcsv($out, ['Records excluded — voided by an officer', $i['voided_records'], $i['voided_visitors'] . ' visitors']);
fputcsv($out, []);
fputcsv($out, ['Note: only records with status "valid" are included in the figures above.']);

fclose($out);
exit;
