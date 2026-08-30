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

    /* Deliberately plain and black. It prints on the office's mono laser as
       clearly as it renders here, and it sits above the table so it cannot be
       read as a footnote to it. */
    .vr-caveat {
        border: 1.5px solid #111;
        padding: .55rem .75rem;
        margin-bottom: .9rem;
        font-size: .78rem;
        line-height: 1.35;
    }

    .vr-caveat p { margin: 0; }
    .vr-caveat p + p { margin-top: .35rem; }

    @media print {
        body { padding: 0; }
        .vr-actions { display: none; }

        /* ONE SHEET, INCLUDING THE SIGNATURES.
         *
         * The table always fitted: it ended 144px inside the page. What did not
         * was the signature block below it, so a filled-in April printed over
         * two pages and the second carried nothing but two ruled lines. The
         * officer signed a page with no figures on it, and it went to the
         * Department that way.
         *
         * Every millimetre taken back here is decorative white space — the
         * gaps above and between blocks. The ruled line each signature is
         * written on is NOT touched: it is the one measurement on this sheet
         * that exists for a physical reason. */
        .vr-head p    { margin-bottom: .4rem; }
        .vr-meta      { margin-bottom: .45rem; }
        .vr-meta div  { margin-bottom: .1rem; }
        .vr-caveat    { margin-bottom: .5rem; padding: .4rem .6rem; }
        .vr-footnote  { margin-top: .3rem; }
        .vr-signatures { margin-top: 1rem; gap: 1rem 4rem; }

        /* A hair off each row. Sixteen rows makes this worth more than any
           single margin above, and at print resolution it is invisible. */
        .visitor-record th,
        .visitor-record td { padding-top: .2rem; padding-bottom: .2rem; }

        /* If it ever does need a second page — more attractions than this
           municipality has today — the signatures travel as one block and the
           column headings repeat above the rows they label. */
        .vr-signatures { break-inside: avoid; page-break-inside: avoid; }
        .visitor-record thead { display: table-header-group; }
        .visitor-record tr { break-inside: avoid; page-break-inside: avoid; }
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

    <?php
    /* THE CAVEAT HAS TO BE ON THE PAPER.
     *
     * visitor-record.php shows two warnings on screen: that a month has no
     * approved arrivals, and how many recorded visitors this sheet therefore
     * leaves out. Neither of them survived onto the printed copy — so a sheet
     * of dashes for a month with two thousand recorded visitors printed with
     * nothing on it to say why, got signed, and went to the Department with
     * every figure blank and no explanation attached.
     *
     * The screen is read by the officer. The paper is read by everyone after
     * them, and it is the paper that leaves the office. */
    ?>
    <?php if (!$record['has_data'] || ($record['excluded'] ?? 0) > 0): ?>
        <div class="vr-caveat">
            <?php if (!$record['has_data']): ?>
                <p><strong>No approved arrivals for <?= e($record['month_label']) ?>.</strong>
                   This form counts only arrivals covered by a report the Office has approved, so
                   the rows below are blank by design and not for want of visitors.</p>
            <?php endif; ?>

            <?php if (($record['excluded'] ?? 0) > 0): ?>
                <p><strong><?= n((int) $record['excluded']) ?> recorded visitor(s) are not counted on
                   this sheet.</strong> They have no approved report behind them. This sheet is
                   therefore incomplete for the month and should not be filed until those
                   submissions have been reviewed.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php require __DIR__ . '/_visitor-record-table.php'; ?>

</div>

</body>
</html>
