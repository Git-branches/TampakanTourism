<?php
declare(strict_types=1);

/**
 * TourSync — arrivals CSV export.
 *
 * Uses the same filter builder as the arrivals screen, so the file always
 * contains exactly the rows the officer was looking at. An export that
 * silently differs from the screen it came from is worse than no export.
 *
 * CSV rather than XLSX: it opens in Excel, Google Sheets, and LibreOffice
 * alike, and needs no library — which keeps the cPanel deployment intact.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Repositories\ArrivalRepository;

Auth::require();

$filters = [
    'from'           => trim((string) ($_GET['from'] ?? '')),
    'to'             => trim((string) ($_GET['to'] ?? '')),
    'destination_id' => (int) ($_GET['destination'] ?? 0) ?: null,
    'tourist_type'   => (string) ($_GET['type'] ?? ''),
    'status'         => (string) ($_GET['status'] ?? ''),
    'source'         => (string) ($_GET['source'] ?? ''),
    'search'         => trim((string) ($_GET['q'] ?? '')),
];

$rows = ArrivalRepository::forExport($filters);

ActivityLog::record('arrival.export', 'arrival', null, count($rows) . ' arrival record(s) exported to CSV');

$filename = 'tampakan-arrivals-' . date('Y-m-d-His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');

// UTF-8 BOM. Without it Excel on Windows misreads accented place names —
// "Peñaranda" arrives as "PeÃ±aranda" and the office assumes the data is corrupt.
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'Record ID', 'Destination', 'Barangay', 'Visit Date', 'Recorded At',
    'Tourist Type', 'Stay Type', 'Purpose',
    'Party Size', 'Companions',
    'Origin City', 'Origin Province', 'Origin Country',
    'Name', 'Age Group', 'Sex', 'Contact', 'Email', 'Consent Given',
    'Source', 'Status', 'Flag Reason', 'Void Reason',
]);

foreach ($rows as $r) {
    fputcsv($out, [
        $r['id'],
        $r['destination_name'],
        $r['barangay'],
        $r['visit_date'],
        $r['arrived_at'],
        ArrivalRepository::TYPES[$r['tourist_type']] ?? $r['tourist_type'],
        $r['stay_type'] === 'overnight' ? 'Overnight' : ($r['stay_type'] === 'day_trip' ? 'Day trip' : ''),
        ArrivalRepository::PURPOSES[$r['purpose']] ?? '',
        $r['total_visitors'],
        $r['companions_count'],
        $r['origin_city'],
        $r['origin_province'],
        $r['origin_country'],
        $r['full_name'],
        ArrivalRepository::AGE_BRACKETS[$r['age_bracket']] ?? '',
        $r['sex'],
        $r['contact_number'],
        $r['email'],
        (int) $r['consent_given'] === 1 ? 'Yes' : 'No',
        $r['source'] === 'qr' ? 'QR scan' : 'Manual entry',
        ucfirst($r['status']),
        $r['flag_reason'],
        $r['void_reason'],
    ]);
}

fclose($out);
exit;
