<?php
declare(strict_types=1);

/**
 * TourSync — the Visitor Record, ready for the printer.              Feature 2
 *
 * A standalone page rather than the dashboard with a print stylesheet: the
 * sheet is landscape, edge to edge, and carrying the sidebar and topbar into
 * the print CSS only to hide them again is more code that can go wrong on the
 * one output that gets signed and filed.
 *
 * Everything comes from the query string the screen handed over, so what
 * prints is exactly what the officer approved on screen.
 */

require_once __DIR__ . '/../../bootstrap.php';

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Tourism Attraction Visitor Record — <?= e($record['month_label']) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<style>
    /* Landscape: fourteen columns do not fit portrait at a legible size. */
    @page { size: A4 landscape; margin: 10mm; }

    body {
        background: #fff;
        color: #111;
        font-family: 'Segoe UI', Arial, sans-serif;
        padding: 1.5rem;
    }

    .vr-sheet { max-width: 1400px; margin: 0 auto; }

    .vr-head h1 { font-size: 1.05rem; margin: 0 0 .1rem; font-weight: 700; }
    .vr-head p  { font-size: .78rem; margin: 0 0 1rem; font-style: italic; color: #333; }

    .vr-meta { margin-bottom: .9rem; font-size: .82rem; }
    .vr-meta div { margin-bottom: .25rem; }
    .vr-meta .vr-meta__label { display: inline-block; width: 10rem; }

    /* The office writes these on a ruled line, so the printed sheet keeps one. */
    .vr-meta .vr-meta__value {
        display: inline-block;
        min-width: 22rem;
        border-bottom: 1px solid #333;
        font-weight: 600;
    }

    .vr-actions { margin-bottom: 1.2rem; }

    @media print {
        body { padding: 0; }
        .vr-actions { display: none; }
    }
</style>
</head>
<body>

<div class="vr-sheet">

    <div class="vr-actions">
        <button type="button" onclick="window.print()" class="btn btn-brand btn-sm">Print this sheet</button>
        <a href="visitor-record.php?year=<?= $year ?>&amp;month=<?= $month ?>"
           class="btn btn-sm btn-outline-secondary">Back</a>
    </div>

    <div class="vr-head">
        <h1>Tourism Attraction Visitor Record</h1>
        <p>( This recording form can be used instead of just counting the visitors )</p>
    </div>

    <div class="vr-meta">
        <div>
            <span class="vr-meta__label">Month/Year:</span>
            <span class="vr-meta__value"><?= e($record['month_label']) ?></span>
        </div>
        <div>
            <span class="vr-meta__label">Name of Province:</span>
            <span class="vr-meta__value"><?= e($record['province']) ?></span>
        </div>
    </div>

    <?php require __DIR__ . '/_visitor-record-table.php'; ?>

</div>

</body>
</html>
