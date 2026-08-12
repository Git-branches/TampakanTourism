<?php
declare(strict_types=1);

/**
 * TourSync — the Visitor Record as CSV.                              Feature 2
 *
 * For the office that wants the month in Excel — to send onward, or to keep
 * beside the signed paper copy. Same figures as the printed sheet, same order.
 *
 * A UTF-8 BOM is written first. Excel on Windows opens a BOM-less UTF-8 CSV as
 * the system codepage, and "Santo Niño" arrives mangled in a file the office
 * forwards to the province.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\VisitorRecord;

Auth::require();

$now   = time();
$year  = max(2000, min((int) date('Y', $now) + 1, (int) ($_GET['year'] ?? date('Y', $now))));
$month = max(1, min(12, (int) ($_GET['month'] ?? date('n', $now))));

$record      = VisitorRecord::build($year, $month, ($_GET['gensan'] ?? '') === 'local');
$signatories = VisitorRecord::signatories([
    'prepared_by'       => (string) ($_GET['prepared_by'] ?? ''),
    'prepared_by_title' => (string) ($_GET['prepared_by_title'] ?? ''),
    'approved_by'       => (string) ($_GET['approved_by'] ?? ''),
    'approved_by_title' => (string) ($_GET['approved_by_title'] ?? ''),
]);

ActivityLog::record(
    'report.visitor_record_exported', 'report', null,
    'Exported the Tourism Attraction Visitor Record for ' . $record['month_label']
);

$filename = 'visitor-record-' . $year . '-' . sprintf('%02d', $month) . '.csv';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'wb');

fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['Tourism Attraction Visitor Record']);
fputcsv($out, ['( This recording form can be used instead of just counting the visitors )']);
fputcsv($out, []);
fputcsv($out, ['Month/Year:', $record['month_label']]);
fputcsv($out, ['Name of Province:', $record['province']]);
fputcsv($out, []);

/* Two header rows, matching the sheet's banded columns. A single flattened row
   would be easier to write and harder to read next to the paper form. */
fputcsv($out, [
    'Visitor Attraction', '',
    'Philippines - This province', '', '',
    'Philippines - Other Province', '', '',
    'Foreign Country Residence', '', '',
    'Grand Total', '', '',
]);
fputcsv($out, [
    'Name', 'Attraction Code',
    'Male', 'Female', 'Total',
    'Male', 'Female', 'Total',
    'Male', 'Female', 'Total',
    'Male', 'Female', 'Total',
]);

fputcsv($out, [$record['municipality']]);

foreach ($record['rows'] as $row) {
    $f = $row['figures'];

    fputcsv($out, [
        $row['name'],
        $row['code'],
        $f['this_province']['male'],  $f['this_province']['female'],  $f['this_province']['total'],
        $f['other_province']['male'], $f['other_province']['female'], $f['other_province']['total'],
        $f['foreign']['male'],        $f['foreign']['female'],        $f['foreign']['total'],
        $f['grand']['male'],          $f['grand']['female'],          $f['grand']['total'],
    ]);
}

$t = $record['totals'];

fputcsv($out, [
    'Total of this Month', '',
    $t['this_province']['male'],  $t['this_province']['female'],  $t['this_province']['total'],
    $t['other_province']['male'], $t['other_province']['female'], $t['other_province']['total'],
    $t['foreign']['male'],        $t['foreign']['female'],        $t['foreign']['total'],
    $t['grand']['male'],          $t['grand']['female'],          $t['grand']['total'],
]);

fputcsv($out, []);
fputcsv($out, ['Note: *Total number must be recorded, ** Sex & ***Residence entries are optional. '
    . 'Total number of this month must be reported.']);

if ($t['grand']['unspecified'] > 0) {
    fputcsv($out, ['** ' . $t['grand']['unspecified'] . ' visitor(s) have no recorded sex, '
        . 'so Male + Female comes to less than the Total.']);
}

if ($record['unknown_province'] > 0) {
    fputcsv($out, ['*** ' . $record['unknown_province'] . ' visitor(s) have no recorded place of residence, '
        . 'so the three residence columns come to less than the Grand Total.']);
}

fputcsv($out, []);
fputcsv($out, ['Prepared by:', $signatories['prepared_by'], $signatories['prepared_by_title']]);
fputcsv($out, ['Approved by:', $signatories['approved_by'], $signatories['approved_by_title']]);

fclose($out);
