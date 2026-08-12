<?php
declare(strict_types=1);

/**
 * Print view for a generated report.
 *
 * A print stylesheet rather than a PDF library: the browser's own print dialog
 * saves to PDF already, and adding dompdf would mean a Composer dependency the
 * cPanel deployment requirement rules out. One less moving part, and the
 * printed page comes from the same template as the screen — so the two cannot
 * report different totals.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\ReportBuilder;

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

$user = Auth::user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e(ReportBuilder::PERIODS[$type]) ?> Report — <?= e($report['period']['label']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<style>
    body { background: #E8ECE9; padding: 1.5rem 0; }

    .sheet {
        width: 210mm;
        min-height: 297mm;
        margin: 0 auto 1.5rem;
        background: #fff;
        padding: 16mm 15mm;
        box-shadow: 0 6px 24px rgba(0, 0, 0, .12);
    }

    .letterhead {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding-bottom: 1rem;
        border-bottom: 3px solid var(--green);
        margin-bottom: 1.4rem;
    }
    .letterhead img { width: 62px; height: 62px; object-fit: contain; }
    .letterhead h1 { font-size: 1.15rem; font-weight: 700; margin: 0; color: var(--green-dark); }
    .letterhead p { margin: 0; font-size: .78rem; color: var(--ink-3); }

    .report-head { text-align: center; margin-bottom: 1.5rem; }
    .report-head h2 { font-size: 1.35rem; font-weight: 700; margin: 0 0 .2rem; }
    .report-head p { margin: 0; font-size: .88rem; color: var(--ink-2); }

    .signature {
        margin-top: 3rem;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 3rem;
    }
    .signature div { text-align: center; }
    .signature span { display: block; border-top: 1px solid var(--ink-2); padding-top: .4rem; font-size: .82rem; }
    .signature small { font-size: .72rem; color: var(--ink-3); }

    .print-bar {
        width: 210mm;
        margin: 0 auto 1rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: .6rem;
    }

    /* Screen chrome disappears; the sheet becomes the page. */
    @media print {
        @page { size: A4 portrait; margin: 12mm; }
        body { background: #fff; padding: 0; }
        .print-bar { display: none; }
        .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
        .panel { border: 1px solid #ccc !important; box-shadow: none !important; break-inside: avoid; }
        .report-grid { display: block; }
        .report-grid .panel { margin-bottom: 1rem; }
        .figure { break-inside: avoid; }
        a { text-decoration: none; color: inherit; }
    }
</style>
</head>
<body>

<div class="print-bar">
    <a href="index.php?<?= e(http_build_query($_GET)) ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fa-solid fa-arrow-left"></i> Back
    </a>
    <button onclick="window.print()" class="btn btn-sm btn-brand">
        <i class="fa-solid fa-print"></i> Print / Save as PDF
    </button>
</div>

<div class="sheet">
    <header class="letterhead">
        <img src="<?= e(asset('img/tampakan_logo.png')) ?>" alt="Seal of the Municipality of Tampakan">
        <div>
            <h1>Municipal Tourism Office</h1>
            <p><?= e((string) setting('office_municipality', 'Municipality of Tampakan')) ?>
               &middot; <?= e((string) setting('office_province', 'South Cotabato')) ?></p>
            <p><?= e((string) setting('office_address', 'Tampakan Municipal Hall, Kamagong St., Brgy. Poblacion')) ?></p>
        </div>
    </header>

    <div class="report-head">
        <h2><?= e(mb_strtoupper(ReportBuilder::PERIODS[$type])) ?> TOURIST ARRIVAL REPORT</h2>
        <p><?= e($report['period']['label']) ?></p>
        <p class="small text-muted">
            Generated <?= e(format_date($report['generated_at'], 'F j, Y \a\t g:i A')) ?>
            by <?= e($user['full_name']) ?>
        </p>
    </div>

    <?php require __DIR__ . '/_report-body.php'; ?>

    <div class="signature">
        <div>
            <span>Prepared by</span>
            <small><?= e($user['full_name']) ?><br>Municipal Tourism Office</small>
        </div>
        <div>
            <span>Noted by</span>
            <small>Municipal Tourism Officer</small>
        </div>
    </div>
</div>

</body>
</html>
