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

<?php /* A PAGE THAT DECLARES NO ICON GETS THE SERVER'S.
         This sheet stands outside the admin shell, so it inherited nothing —
         and the browser fell back to /favicon.ico at the document root, which
         on this machine is XAMPP's own, dated 2015. The office opened the
         printable copy of a Department of Tourism return and saw XAMPP's
         orange logo on the tab. Same icon the rest of the system uses. */ ?>
<link rel="icon" href="<?= e(asset('img/tampakan_logo.png')) ?>" sizes="any">

<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<style>
    /* Landscape: fourteen columns do not fit portrait at a legible size.
     *
     * MARGIN 0, AND THAT IS WHAT REMOVES THE BROWSER'S OWN HEADER.
     *
     * "9/7/26, 11:18 PM", the page title and the localhost URL along the bottom
     * are not printed by this page — the browser draws them in the margin box
     * @page reserves. This is a form the Municipal Tourism Office signs and
     * sends to the Department of Tourism, and a government return should not
     * arrive with a developer's localhost address across the foot of it.
     *
     * Take the margin box away and there is nowhere for them to go. The paper
     * still gets its border: the 10mm moves onto the body below, which is the
     * sheet's own padding rather than the page's.
     *
     * A reader can still switch them back on with "Headers and footers" in the
     * print dialog — that tick is theirs, not something CSS can hold shut. */
    @page { size: A4 landscape; margin: 0; }

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

    /* .vr-caveat was styled here for the boxed warning above the table. The box
       is gone from this sheet — see the note further down — and its rules went
       with it rather than being left as CSS for markup nobody renders. */

    @media print {
        /* The sheet's own margin, now that @page has none. Without this the
           table would start at the very edge of the paper and most printers
           would clip the first column. */
        body { padding: 10mm; }
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

    <?php /* PUT BACK 2026-09-07, AND IT STAYS.
             This parenthetical was briefly removed as decoration. It is not: the
             office's own filed copy carries it directly under the title, so it
             is part of the form the Department of Tourism receives. Anything on
             this sheet that appears on the paper form is there because the form
             has it, not because it reads well. */ ?>
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
    /* THE CAVEAT IS ON THE SCREEN, NOT ON THE PAPER.
     *
     * A box used to print here saying that a month had no approved arrivals, or
     * how many recorded visitors this sheet leaves out. It was added because a
     * sheet of dashes for a month with two thousand recorded visitors printed
     * with nothing on it to say why, got signed, and went to the Department
     * that way.
     *
     * It is gone from the paper on the office's instruction, and they are
     * right: this is a Department of Tourism return with a fixed layout, and
     * nothing belongs on it that is not on the form the Department issued. A
     * box this system invented, sitting above the table on a signed government
     * document, is this system editing a form that is not its to edit.
     *
     * THE PROTECTION IS NOT LOST — it moved rather than went. The same two
     * warnings are on visitor-record.php (lines 155 and 164), which is the page
     * with the Print button on it. The officer is told before they print; the
     * paper stays exactly what the Department expects to receive. */
    ?>
    <?php require __DIR__ . '/_visitor-record-table.php'; ?>

</div>

</body>
</html>
