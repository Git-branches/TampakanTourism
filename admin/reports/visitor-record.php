<?php
declare(strict_types=1);

/**
 * TourSync — Tourism Attraction Visitor Record.                      Feature 2
 *
 * The monthly sheet the Municipal Tourism Office already submits, generated
 * from approved arrival reports instead of being typed up by hand.
 *
 * Built to the office's own form, column for column:
 *
 *   Visitor Attraction  |  Place of Residence                    |  Grand Total
 *   Name | Code         |  Philippines           | Foreign       |  M | F | Tot
 *                       |  This prov | Other prov| Country       |
 *
 * The two signatures at the foot are editable on this screen. They default to
 * whoever the office recorded in settings, and can be overridden for a single
 * print — a data encoder resigns, a coordinator is reassigned, and a report
 * that can only carry the names fixed at installation is a report somebody
 * retypes in Word.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\VisitorRecord;

Auth::require();

$now   = time();
$year  = (int) ($_GET['year']  ?? date('Y', $now));
$month = (int) ($_GET['month'] ?? date('n', $now));

/* Clamped rather than trusted. ?month=99 would otherwise produce a period
   strtotime rolls into the following year without saying so. */
$month = max(1, min(12, $month));
$year  = max(2000, min((int) date('Y', $now) + 1, $year));

/* General Santos is geographically inside South Cotabato and administratively
   independent of it. Which column it belongs in is the Tourism Officer's call,
   so it is a control on the screen rather than a decision buried in code. */
$gensanIsLocal = ($_GET['gensan'] ?? '') === 'local';

$record      = VisitorRecord::build($year, $month, $gensanIsLocal);
$signatories = VisitorRecord::signatories([
    'prepared_by'       => (string) ($_GET['prepared_by'] ?? ''),
    'prepared_by_title' => (string) ($_GET['prepared_by_title'] ?? ''),
    'approved_by'       => (string) ($_GET['approved_by'] ?? ''),
    'approved_by_title' => (string) ($_GET['approved_by_title'] ?? ''),
]);

/* Carried onto the print view so it prints exactly what is on screen. */
$carry = http_build_query([
    'year'              => $year,
    'month'             => $month,
    'gensan'            => $gensanIsLocal ? 'local' : '',
    'prepared_by'       => $signatories['prepared_by'],
    'prepared_by_title' => $signatories['prepared_by_title'],
    'approved_by'       => $signatories['approved_by'],
    'approved_by_title' => $signatories['approved_by_title'],
]);

$pageTitle    = 'Tourism Attraction Visitor Record';
$pageIcon     = 'fa-table-list';
$pageSubtitle = $record['month_label'] . ' · ' . $record['municipality'] . ', ' . $record['province'];

require __DIR__ . '/../_partials/head.php';
?>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-sliders"></i> Period and signatories</h2>
    </header>

    <div class="panel__body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-6 col-md-2">
                <label for="month" class="form-label">Month</label>
                <select id="month" name="month" class="form-select form-select-sm">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>>
                            <?= e(date('F', (int) mktime(0, 0, 0, $m, 1))) ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="col-6 col-md-2">
                <label for="year" class="form-label">Year</label>
                <select id="year" name="year" class="form-select form-select-sm">
                    <?php for ($y = (int) date('Y', $now) + 1; $y >= (int) date('Y', $now) - 5; $y--): ?>
                        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="col-12 col-md-4">
                <label for="gensan" class="form-label">General Santos City counts as</label>
                <select id="gensan" name="gensan" class="form-select form-select-sm">
                    <option value=""      <?= $gensanIsLocal ? '' : 'selected' ?>>Other Province</option>
                    <option value="local" <?= $gensanIsLocal ? 'selected' : '' ?>>This province</option>
                </select>
            </div>

            <div class="col-12 col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-brand btn-sm">
                    <i class="fa-solid fa-rotate"></i> Generate
                </button>
                <a href="visitor-record-print.php?<?= e($carry) ?>" target="_blank" rel="noopener"
                   class="btn btn-sm btn-outline-secondary">
                    <i class="fa-solid fa-print"></i> Print
                </a>
                <a href="visitor-record-export.php?<?= e($carry) ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="fa-solid fa-file-csv"></i> CSV
                </a>
            </div>

            <div class="col-12"><hr class="my-1"></div>

            <div class="col-12 col-md-3">
                <label for="prepared_by" class="form-label">Prepared by</label>
                <input type="text" id="prepared_by" name="prepared_by" class="form-control form-control-sm"
                       maxlength="120" value="<?= e($signatories['prepared_by']) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label for="prepared_by_title" class="form-label">Position</label>
                <input type="text" id="prepared_by_title" name="prepared_by_title" class="form-control form-control-sm"
                       maxlength="120" value="<?= e($signatories['prepared_by_title']) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label for="approved_by" class="form-label">Approved by</label>
                <input type="text" id="approved_by" name="approved_by" class="form-control form-control-sm"
                       maxlength="120" value="<?= e($signatories['approved_by']) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label for="approved_by_title" class="form-label">Position</label>
                <input type="text" id="approved_by_title" name="approved_by_title" class="form-control form-control-sm"
                       maxlength="120" value="<?= e($signatories['approved_by_title']) ?>">
            </div>

            <div class="col-12">
                <p class="text-muted small mb-0">
                    The names default to the office's saved settings and can be changed here for a single
                    print &mdash; useful when an encoder or coordinator has changed.
                    <?php if (Auth::isOfficer()): ?>
                        To change them permanently,
                        <a href="<?= e(base_url('/admin/settings/index.php')) ?>">update the office settings</a>.
                    <?php endif; ?>
                </p>
            </div>
        </form>
    </div>
</section>

<?php if (!$record['has_data']): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-circle-info"></i>
        <strong>No approved arrivals for <?= e($record['month_label']) ?>.</strong>
        This form is built only from reports the Office has approved &mdash; a submission still waiting
        for review is not counted, on purpose. The sheet below prints with empty rows.
    </div>
<?php endif; ?>

<?php if ($record['unknown_province'] > 0): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <strong><?= n($record['unknown_province']) ?> visitor(s) have no recorded place of residence.</strong>
        They are counted in the Grand Total but left out of the three residence columns, so those columns
        will come to less than the total. The address written in the logbook was blank or unrecognised.
    </div>
<?php endif; ?>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-table-list"></i> Tourism Attraction Visitor Record</h2>
        <span class="text-muted small"><?= e($record['month_label']) ?></span>
    </header>

    <div class="panel__body">
        <div class="table-responsive">
            <?php require __DIR__ . '/_visitor-record-table.php'; ?>
        </div>

        <p class="text-muted small mt-3 mb-0">
            <strong>Note:</strong> *Total number must be recorded, ** Sex &amp; ***Residence entries are
            optional. Total number of this month must be reported.
        </p>
    </div>
</section>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
