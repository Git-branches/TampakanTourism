<?php
declare(strict_types=1);

/**
 * TourSync — report generation.        Feature 4 / Problem 5
 *
 * The Office previously built these figures by hand from paper logbooks. The
 * value here is not cleverness — it is that a query does not miscount on the
 * third page of tallies, and produces the same answer twice.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\ReportBuilder;

Auth::require();

$pageTitle    = 'Reports';
$pageIcon     = 'fa-file-lines';
$pageSubtitle = 'Daily, monthly, quarterly, and annual tourism reports';

$range   = ReportBuilder::dataRange();
$history = ReportBuilder::history(10);

/* OPENS ON A REPORT, NOT ON A FORM.
 *
 * This defaulted to '' , so clicking "Reports" in the sidebar produced a period
 * picker and nothing else — no figures, and no report history either, because
 * that panel hides itself when nothing has been saved. An officer with seven
 * thousand arrivals on file landed on a blank screen and had to know to choose
 * a period before the system would admit it had any data at all.
 *
 * The current month is what they were going to pick anyway. Naming the type in
 * the URL still overrides it, so every existing link keeps working. */
$type = (string) ($_GET['type'] ?? 'monthly');
$report = null;

/* WHICH MONTH IT OPENS ON, and why "this one" was not good enough.
 *
 * Defaulting to the current month is right for most of the month and wrong on
 * the first of it: at one minute past midnight on the 1st the current month
 * holds nothing, so the screen that was built to open on figures opened on a
 * row of zeros — every month, for a day or so, without anybody changing a line.
 *
 * So: this month if it has records, otherwise the most recent month that does.
 * The heading names the period either way, so there is no doubt which one is on
 * screen, and naming a month in the URL still overrides both. */
$landingYear  = (int) date('Y');
$landingMonth = (int) date('n');

if (!isset($_GET['year'], $_GET['month'])) {
    $recent = App\Core\Database::first(
        "SELECT YEAR(visit_date) y, MONTH(visit_date) m
           FROM tourist_arrivals
          WHERE status = 'valid'
            AND visit_date <= CURDATE()
          ORDER BY visit_date DESC
          LIMIT 1"
    );

    $thisMonth = (int) App\Core\Database::scalar(
        "SELECT COUNT(*) FROM tourist_arrivals
          WHERE status = 'valid' AND YEAR(visit_date) = ? AND MONTH(visit_date) = ?",
        [$landingYear, $landingMonth]
    );

    if ($thisMonth === 0 && $recent !== null) {
        $landingYear  = (int) $recent['y'];
        $landingMonth = (int) $recent['m'];
    }
}

if ($type !== '' && isset(ReportBuilder::PERIODS[$type])) {
    $params = [
        'date'    => (string) ($_GET['date'] ?? date('Y-m-d')),
        'year'    => (int) ($_GET['year'] ?? $landingYear),
        'month'   => (int) ($_GET['month'] ?? $landingMonth),
        'quarter' => (int) ($_GET['quarter'] ?? ceil((int) date('n') / 3)),
        'start'   => (string) ($_GET['start'] ?? date('Y-m-01')),
        'end'     => (string) ($_GET['end'] ?? date('Y-m-d')),
    ];

    $report = ReportBuilder::build($type, $params);

    // Recorded once per generation, so a figure quoted in a meeting can be
    // traced back to who produced it and when.
    if (!empty($_GET['save'])) {
        $id = ReportBuilder::save($type, $report['period'], Auth::id(), $params);
        ActivityLog::record('report.generate', 'report', $id,
            ReportBuilder::PERIODS[$type] . ' report for ' . $report['period']['label']);
    }
}

$firstYear = $range['first'] ? (int) date('Y', strtotime($range['first'])) : (int) date('Y');
$lastYear  = $range['last']  ? (int) date('Y', strtotime($range['last']))  : (int) date('Y');

require __DIR__ . '/../_partials/head.php';
?>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-sliders"></i> Generate a Report</h2>
        <!-- The office's own monthly submission form. Given its own entry rather
             than folded into the generic report types below: this is the sheet
             that gets signed and filed, and it has a fixed layout the others
             do not share. -->
        <a href="visitor-record.php" class="btn btn-brand btn-sm">
            <i class="fa-solid fa-table-list"></i> Tourism Attraction Visitor Record
        </a>
    </header>
    <div class="panel__body">

        <?php if ($range['first'] === null): ?>
            <div class="empty">
                <i class="fa-solid fa-file-lines"></i>
                <p><strong>No arrival records to report on yet.</strong></p>
                <p>Reports are built from recorded arrivals. Once visitors begin using the digital
                   logbook, daily, monthly, quarterly, and annual reports become available here.</p>
            </div>
        <?php else: ?>

            <p class="text-muted small mb-3">
                <i class="fa-solid fa-database"></i>
                Records available from <strong><?= e(format_date($range['first'])) ?></strong>
                to <strong><?= e(format_date($range['last'])) ?></strong>.
            </p>

            <form method="get" class="report-form">
                <div class="report-form__types">
                    <?php foreach (ReportBuilder::PERIODS as $value => $label): ?>
                        <label class="report-type <?= $type === $value ? 'is-active' : '' ?>">
                            <input type="radio" name="type" value="<?= e($value) ?>" <?= $type === $value ? 'checked' : '' ?>>
                            <span><?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="report-form__params">
                    <label class="param" data-for="daily">Date
                        <input type="date" name="date" value="<?= e((string) ($_GET['date'] ?? date('Y-m-d'))) ?>">
                    </label>

                    <label class="param" data-for="monthly quarterly annual">Year
                        <select name="year">
                            <?php for ($y = $lastYear; $y >= $firstYear; $y--): ?>
                                <option value="<?= $y ?>" <?= (int) ($_GET['year'] ?? date('Y')) === $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>

                    <label class="param" data-for="monthly">Month
                        <select name="month">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= (int) ($_GET['month'] ?? date('n')) === $m ? 'selected' : '' ?>>
                                    <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </label>

                    <label class="param" data-for="quarterly">Quarter
                        <select name="quarter">
                            <?php foreach ([1 => 'Q1 (Jan–Mar)', 2 => 'Q2 (Apr–Jun)', 3 => 'Q3 (Jul–Sep)', 4 => 'Q4 (Oct–Dec)'] as $q => $label): ?>
                                <option value="<?= $q ?>" <?= (int) ($_GET['quarter'] ?? 1) === $q ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="param" data-for="custom">From
                        <input type="date" name="start" value="<?= e((string) ($_GET['start'] ?? date('Y-m-01'))) ?>">
                    </label>
                    <label class="param" data-for="custom">To
                        <input type="date" name="end" value="<?= e((string) ($_GET['end'] ?? date('Y-m-d'))) ?>">
                    </label>

                    <button type="submit" class="btn btn-brand btn-sm">
                        <i class="fa-solid fa-play"></i> Generate
                    </button>
                </div>
            </form>

        <?php endif; ?>
    </div>
</section>

<?php if ($report !== null): ?>

    <div class="report-actions">
        <div>
            <h2 class="report-actions__title"><?= e(ReportBuilder::PERIODS[$type]) ?> Report</h2>
            <p class="report-actions__period"><?= e($report['period']['label']) ?></p>
        </div>
        <div class="report-actions__buttons">
            <a href="print.php?<?= e(http_build_query($_GET)) ?>" target="_blank" rel="noopener"
               class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-print"></i> Print View</a>
            <a href="export.php?<?= e(http_build_query($_GET)) ?>"
               class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-file-csv"></i> Download CSV</a>
            <a href="?<?= e(http_build_query(array_merge($_GET, ['save' => 1]))) ?>"
               class="btn btn-sm btn-brand"><i class="fa-solid fa-floppy-disk"></i> Save to History</a>
        </div>
    </div>

    <?php require __DIR__ . '/_report-body.php'; ?>

<?php endif; ?>

<?php /* The panel used to vanish entirely until something had been saved, which
         is exactly when an officer most needs telling that saving is a thing
         this screen does. It stays, and says so. */ ?>
<section class="panel">
    <header class="panel__head"><h2><i class="fa-solid fa-clock-rotate-left"></i> Recently Generated</h2></header>
    <div class="panel__body">
        <?php if ($history === []): ?>
            <div class="empty">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <p><strong>No report has been saved yet.</strong></p>
                <p>Generate a report above and press <em>Save to history</em>. Saved reports are
                   listed here with who produced them and when, so a figure quoted in a meeting can
                   be traced back afterwards.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Report</th><th>Period</th><th>Generated by</th><th>When</th></tr></thead>
                    <tbody>
                    <?php foreach ($history as $h): ?>
                        <tr>
                            <td><?= e((string) $h['title']) ?></td>
                            <td class="small"><?= e(format_date($h['period_start'])) ?> – <?= e(format_date($h['period_end'])) ?></td>
                            <td class="small"><?= e((string) ($h['generated_by_name'] ?? '—')) ?></td>
                            <td class="small"><?= e(format_date($h['created_at'], 'M j, Y g:i A')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php
$pageScripts = <<<'HTML'
<script>
/* Show only the inputs the chosen report type actually uses — a quarter picker
   on a daily report is a question with no answer. */
(function () {
    const radios = document.querySelectorAll('input[name="type"]');
    const params = document.querySelectorAll('.param');

    function sync() {
        const chosen = document.querySelector('input[name="type"]:checked');
        const type = chosen ? chosen.value : '';

        params.forEach((p) => {
            p.style.display = p.dataset.for.split(' ').includes(type) ? '' : 'none';
        });
        document.querySelectorAll('.report-type').forEach((l) => {
            l.classList.toggle('is-active', l.contains(chosen));
        });
    }

    radios.forEach((r) => r.addEventListener('change', sync));
    sync();
})();
</script>
HTML;

require __DIR__ . '/../_partials/foot.php';
