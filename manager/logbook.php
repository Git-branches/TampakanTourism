<?php
declare(strict_types=1);

/**
 * TourSync — typing one page of the paper logbook.                   Feature 2
 *
 * Laid out to match the page on the desk in front of the manager: the date at
 * the top, then Name / Address / Contact no. down the sheet, in the same order
 * and the same columns. A screen that reorders the source makes the person
 * transcribing look up and down instead of straight across, and that is where
 * a line gets skipped.
 *
 * The Signature column is not here. A signature cannot be typed, and pretending
 * otherwise would be recording something nobody captured — the photograph of
 * the page is what evidences it.
 *
 * Only the Name is required. Real pages have blanks in the Address and Contact
 * columns, and a form that refuses a blank cannot represent the page it exists
 * to copy.
 *
 * The four counts are never entered. They are derived from the Address column
 * and rebuilt every save, so a total cannot disagree with the lines behind it.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Csrf;
use App\Core\ManagerAuth;
use App\Core\OriginClassifier;
use App\Core\Session;
use App\Core\VisitorRecord;
use App\Repositories\ArrivalReportRepository as Reports;
use App\Repositories\LogbookEntryRepository as Entries;

ManagerAuth::require();

$destinationId = (int) ManagerAuth::destinationId();
$reportId      = (int) ($_GET['id'] ?? 0);
$report        = $reportId > 0 ? Reports::find($reportId) : null;

if ($report === null || (int) $report['destination_id'] !== $destinationId) {
    Session::flash('danger', 'That report could not be found.');
    redirect(base_url('/manager/reports.php'));
}

$editable = in_array($report['status'], ['draft', 'rejected'], true);

$date = (string) ($_GET['date'] ?? $_POST['visit_date'] ?? '');

/* The date has to be one the report actually covers. Otherwise a page typed
   against ?date= would sit outside the period and still be written into the
   municipality's summary at approval. */
if ($date === '' || strtotime($date) === false
    || $date < $report['period_start'] || $date > $report['period_end']) {
    Session::flash('danger', 'Pick a date inside the report period.');
    redirect(base_url('/manager/report-form.php?id=' . $reportId));
}

$date = date('Y-m-d', (int) strtotime($date));

// -----------------------------------------------------------------------------
// Saving the page
// -----------------------------------------------------------------------------

if (is_post()) {
    Csrf::verify();

    if (!$editable) {
        Session::flash('danger', 'This report has been submitted and can no longer be edited.');
        redirect(base_url('/manager/report-form.php?id=' . $reportId));
    }

    $rows = [];

    foreach ((array) ($_POST['row'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $rows[] = [
            'full_name'      => (string) ($row['full_name'] ?? ''),
            'address_text'   => (string) ($row['address_text'] ?? ''),
            'contact_number' => (string) ($row['contact_number'] ?? ''),
            /* Optional. The paper page has no sex column, so this stays blank
               unless the manager happens to know — and blank is a supported
               answer on the office's own monthly form. */
            'sex'            => (string) ($row['sex'] ?? ''),
            'tourist_type'   => (string) ($row['tourist_type'] ?? ''),
            /* What the dropdown was showing when the manager looked at it. The
               repository treats a difference between this and tourist_type as a
               deliberate override; without it, an untouched default would
               override the classifier on every line. */
            'suggested_type' => (string) ($row['suggested_type'] ?? ''),
        ];
    }

    Entries::replaceForDate($reportId, $date, $rows);

    $saved = count(Entries::forDate($reportId, $date));

    /* "Next page" walks to the following day rather than back to the list. A
       manager transcribing a month has a stack of pages and no reason to
       return to a menu twenty-nine times. */
    if (($_POST['action'] ?? '') === 'next' && $date < $report['period_end']) {
        Session::flash('success', $saved . ' line(s) saved for ' . format_date($date, 'M j') . '.');
        redirect(base_url('/manager/logbook.php?id=' . $reportId . '&date=' . date('Y-m-d', (int) strtotime($date . ' +1 day'))));
    }

    Session::flash('success', $saved === 0
        ? 'Page cleared for ' . format_date($date, 'M j') . '.'
        : $saved . ' line(s) saved for ' . format_date($date, 'M j') . '.');

    redirect(base_url('/manager/logbook.php?id=' . $reportId . '&date=' . $date));
}

// -----------------------------------------------------------------------------
// The page
// -----------------------------------------------------------------------------

$entries = Entries::forDate($reportId, $date);

/* Blank lines below the last one, the way the paper has ruled lines below the
   last visitor. Enough to keep typing without stopping to press a button. */
$blanks = $editable ? max(6, 22 - count($entries)) : 0;

$prevDate = $date > $report['period_start'] ? date('Y-m-d', (int) strtotime($date . ' -1 day')) : null;
$nextDate = $date < $report['period_end']   ? date('Y-m-d', (int) strtotime($date . ' +1 day')) : null;

/* Labelled by where each choice LANDS on the office's monthly form, not by the
   name the database uses. "Domestic" is the one that misleads: a visitor from
   Polomolok and a visitor from Manila are both domestic, but they go in
   different columns of the Tourism Attraction Visitor Record. The label has to
   carry that, or a manager correcting a line puts it in the wrong column while
   believing they fixed it. */
$officeProvince = (string) (setting('office_province') ?: 'South Cotabato');

$types = [
    'local'             => 'Resident of ' . VisitorRecord::municipality(),
    'domestic'          => 'Elsewhere in the Philippines',
    'foreign'           => 'Foreign national',
    'overseas_filipino' => 'Overseas Filipino (OFW)',
];

/** Which column of the office's form each choice reports into. */
$typeColumns = [
    'local'             => 'This province',
    'domestic'          => 'depends on the address',
    'foreign'           => 'Foreign Country',
    'overseas_filipino' => 'Foreign Country',
];

$pageTitle    = 'Logbook — ' . format_date($date, 'F j, Y');
$pageIcon     = 'fa-book-open';
$pageSubtitle = ManagerAuth::destinationName() . ' · page '
    . format_date($report['period_start'], 'M j') . '–' . format_date($report['period_end'], 'M j');

require __DIR__ . '/_partials/head.php';
?>

<p class="mb-3 d-flex gap-2 flex-wrap align-items-center">
    <a href="report-form.php?id=<?= $reportId ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fa-solid fa-arrow-left"></i> Back to the report
    </a>

    <?php if ($prevDate !== null): ?>
        <a href="logbook.php?id=<?= $reportId ?>&amp;date=<?= e($prevDate) ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-chevron-left"></i> <?= e(format_date($prevDate, 'M j')) ?>
        </a>
    <?php endif; ?>

    <?php if ($nextDate !== null): ?>
        <a href="logbook.php?id=<?= $reportId ?>&amp;date=<?= e($nextDate) ?>" class="btn btn-sm btn-outline-secondary">
            <?= e(format_date($nextDate, 'M j')) ?> <i class="fa-solid fa-chevron-right"></i>
        </a>
    <?php endif; ?>
</p>

<?php if (!$editable): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-lock"></i>
        This report is <strong><?= e(Reports::STATUSES[$report['status']]) ?></strong>. The page is shown as it was submitted.
    </div>
<?php endif; ?>

<form method="post" id="logbookForm">
    <?= csrf_field() ?>
    <input type="hidden" name="visit_date" value="<?= e($date) ?>">

    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-solid fa-calendar-day"></i> <?= e(format_date($date, 'l, F j, Y')) ?></h2>
            <span class="text-muted small">
                <span id="lineCount"><?= n(count($entries)) ?></span> line(s) on this page
            </span>
        </header>

        <div class="panel__body">
            <p class="text-muted small">
                Copy the page exactly as it is written. Leave the Address or Contact blank when the
                visitor left it blank &mdash; only the name is needed. The type is worked out from the
                address; where it says <strong>check</strong>, the address was not recognised and it is
                worth a second look before you submit.
            </p>

            <p class="text-muted small">
                Under each type is the column that line will occupy on the Municipal Tourism Office's
                monthly form &mdash; <strong>This province</strong> (<?= e($officeProvince) ?>),
                <strong>Other Province</strong>, or <strong>Foreign Country</strong>. A line reading
                <span class="text-danger">no residence column</span> is counted in the total and appears
                in none of the three; fixing its address puts it where it belongs.
                Sex is optional &mdash; the paper page does not ask for it.
            </p>

            <!-- logbook-scroll rather than table-responsive: below 768px the
                 table becomes cards and must NOT sit in a horizontal scroller,
                 which is the behaviour this replaces. -->
            <div class="logbook-scroll table-responsive">
                <table class="table table-sm align-middle mb-0 logbook-table" id="logbookTable">
                    <thead>
                        <tr>
                            <th style="width:3rem">#</th>
                            <th>Name</th>
                            <th>Address</th>
                            <th>Contact no.</th>
                            <th style="width:6.5rem">Sex</th>
                            <th style="width:12rem">Type</th>
                            <?php if ($editable): ?><th style="width:3rem"><span class="visually-hidden">Remove</span></th><?php endif; ?>
                        </tr>
                    </thead>

                    <tbody>
                        <?php
                        $lineNo = 0;

                        foreach ($entries as $entry):
                            $lineNo++;
                            ?>
                            <tr class="<?= $entry['confidence'] === 'low' ? 'row-unsure' : '' ?>">
                                <td class="text-muted num" data-label="#"><?= n($lineNo) ?></td>
                                <td data-label="Name">
                                    <input type="text" name="row[<?= $lineNo ?>][full_name]" maxlength="160"
                                           class="form-control form-control-sm lb-name"
                                           value="<?= e((string) $entry['full_name']) ?>"
                                           aria-label="Line <?= $lineNo ?> name" <?= $editable ? '' : 'disabled' ?>>
                                </td>
                                <td data-label="Address">
                                    <input type="text" name="row[<?= $lineNo ?>][address_text]" maxlength="160"
                                           class="form-control form-control-sm"
                                           value="<?= e((string) ($entry['address_text'] ?? '')) ?>"
                                           aria-label="Line <?= $lineNo ?> address" <?= $editable ? '' : 'disabled' ?>>
                                </td>
                                <td data-label="Contact no.">
                                    <input type="text" name="row[<?= $lineNo ?>][contact_number]" maxlength="40"
                                           inputmode="tel" class="form-control form-control-sm"
                                           value="<?= e((string) ($entry['contact_number'] ?? '')) ?>"
                                           aria-label="Line <?= $lineNo ?> contact number" <?= $editable ? '' : 'disabled' ?>>
                                </td>
                                <td data-label="Sex">
                                    <!-- Not on the paper page. Blank is the default and a valid
                                         answer: the office's monthly form marks the sex columns
                                         optional and only the total as required. -->
                                    <select name="row[<?= $lineNo ?>][sex]" class="form-select form-select-sm"
                                            aria-label="Line <?= $lineNo ?> sex" <?= $editable ? '' : 'disabled' ?>>
                                        <option value="">&mdash;</option>
                                        <option value="male"   <?= $entry['sex'] === 'male'   ? 'selected' : '' ?>>M</option>
                                        <option value="female" <?= $entry['sex'] === 'female' ? 'selected' : '' ?>>F</option>
                                    </select>
                                </td>
                                <td data-label="Type">
                                    <?php
                                    /* The classifier's current reading of the
                                       address in this row, posted alongside the
                                       dropdown. A saved override shows as the
                                       dropdown differing from this. */
                                    $suggested = OriginClassifier::classify((string) ($entry['address_text'] ?? ''))['tourist_type'];
                                    ?>
                                    <input type="hidden" name="row[<?= $lineNo ?>][suggested_type]" value="<?= e($suggested) ?>">

                                    <select name="row[<?= $lineNo ?>][tourist_type]"
                                            class="form-select form-select-sm"
                                            aria-label="Line <?= $lineNo ?> tourist type" <?= $editable ? '' : 'disabled' ?>>
                                        <?php foreach ($types as $value => $label): ?>
                                            <option value="<?= e($value) ?>" <?= $entry['tourist_type'] === $value ? 'selected' : '' ?>>
                                                <?= e($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <?php
                                    /* The column this line will occupy on the
                                       office's monthly form. Shown per line
                                       because "domestic" alone does not say:
                                       Polomolok is This province, Manila is
                                       Other Province, and the difference is
                                       only visible once the address resolves. */
                                    $column = match ($entry['tourist_type']) {
                                        'foreign', 'overseas_filipino' => 'Foreign Country',
                                        'local'                        => 'This province',
                                        default => $entry['origin_province'] === null
                                            ? null
                                            : (strcasecmp((string) $entry['origin_province'], $officeProvince) === 0
                                                ? 'This province'
                                                : 'Other Province'),
                                    };
                                    ?>

                                    <?php if ($entry['confidence'] === 'low'): ?>
                                        <span class="cell-sub text-danger">check</span>
                                    <?php elseif ($entry['tourist_type'] !== $suggested): ?>
                                        <span class="cell-sub">set by you</span>
                                    <?php endif; ?>

                                    <?php if ($column === null): ?>
                                        <span class="cell-sub text-danger">no residence column</span>
                                    <?php else: ?>
                                        <span class="cell-sub"><?= e($column) ?></span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($editable): ?>
                                    <td data-label="" class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-danger lb-remove"
                                                title="Remove this line" aria-label="Remove line <?= $lineNo ?>">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>
                                    </td>
                                <?php endif; ?>

                            </tr>
                        <?php endforeach; ?>

                        <?php for ($i = 1; $i <= $blanks; $i++): $lineNo++; ?>
                            <tr>
                                <td class="text-muted num" data-label="#"><?= n($lineNo) ?></td>
                                <td data-label="Name">
                                    <input type="text" name="row[<?= $lineNo ?>][full_name]" maxlength="160"
                                           class="form-control form-control-sm lb-name"
                                           aria-label="Line <?= $lineNo ?> name">
                                </td>
                                <td data-label="Address">
                                    <input type="text" name="row[<?= $lineNo ?>][address_text]" maxlength="160"
                                           class="form-control form-control-sm" aria-label="Line <?= $lineNo ?> address">
                                </td>
                                <td data-label="Contact no.">
                                    <input type="text" name="row[<?= $lineNo ?>][contact_number]" maxlength="40"
                                           inputmode="tel" class="form-control form-control-sm"
                                           aria-label="Line <?= $lineNo ?> contact number">
                                </td>
                                <td data-label="Sex">
                                    <select name="row[<?= $lineNo ?>][sex]" class="form-select form-select-sm"
                                            aria-label="Line <?= $lineNo ?> sex">
                                        <option value="">&mdash;</option>
                                        <option value="male">M</option>
                                        <option value="female">F</option>
                                    </select>
                                </td>
                                <td data-label="Type">
                                    <!-- A blank row shows the classifier's answer for a blank
                                         address, which is 'domestic'. Leaving the dropdown alone
                                         therefore reads as "let the address decide" — the type is
                                         worked out from whatever gets typed into this line. -->
                                    <input type="hidden" name="row[<?= $lineNo ?>][suggested_type]" value="domestic">

                                    <select name="row[<?= $lineNo ?>][tourist_type]" class="form-select form-select-sm"
                                            aria-label="Line <?= $lineNo ?> tourist type">
                                        <?php foreach ($types as $value => $label): ?>
                                            <option value="<?= e($value) ?>" <?= $value === 'domestic' ? 'selected' : '' ?>>
                                                <?= e($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <?php if ($editable): ?>
                                    <td data-label="" class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-danger lb-remove"
                                                title="Remove this line" aria-label="Remove line <?= $lineNo ?>">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>
                                    </td>
                                <?php endif; ?>

                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($editable): ?>
                <div class="mt-3 d-flex gap-2 flex-wrap">
                    <button type="button" id="addRows" class="btn btn-sm btn-outline-secondary">
                        <i class="fa-solid fa-plus"></i> Add 10 more lines
                    </button>

                    <button type="submit" name="action" value="save" class="btn btn-brand btn-sm">
                        <i class="fa-solid fa-floppy-disk"></i> Save page
                    </button>

                    <?php if ($nextDate !== null): ?>
                        <button type="submit" name="action" value="next" class="btn btn-sm btn-outline-secondary">
                            Save and go to <?= e(format_date($nextDate, 'M j')) ?>
                            <i class="fa-solid fa-chevron-right"></i>
                        </button>
                    <?php endif; ?>
                </div>

                <p class="text-muted small mt-2 mb-0">
                    Lines left blank are ignored. Clearing a name deletes that line when you save.
                </p>
            <?php endif; ?>
        </div>
    </section>
</form>

<style>
/* Only the two things this screen needs that the shared stylesheet has no
   reason to carry. Kept here rather than in admin.css so a page of the paper
   logbook does not leave classes behind in the officer's dashboard. */
.row-unsure td { background: rgba(214, 158, 46, .10); }
#logbookTable input, #logbookTable select { min-width: 7rem; }
</style>

<script>
(function () {
    var table = document.getElementById('logbookTable');
    var form  = document.getElementById('logbookForm');
    if (!table || !form) { return; }

    var body    = table.querySelector('tbody');
    var counter = document.getElementById('lineCount');

    function recount() {
        var filled = 0;
        body.querySelectorAll('.lb-name').forEach(function (input) {
            if (input.value.trim() !== '') { filled++; }
        });
        if (counter) { counter.textContent = filled.toLocaleString(); }
    }

    form.addEventListener('input', function (e) {
        if (e.target.classList.contains('lb-name')) { recount(); }
    });

    /* Delete a record. Clears the line rather than removing the row, so the
       numbering the manager is reading against the paper page does not shift
       under them mid-transcription. A cleared line is dropped on save. */
    form.addEventListener('click', function (e) {
        var button = e.target.closest ? e.target.closest('.lb-remove') : null;
        if (!button) { return; }

        var tr   = button.closest('tr');
        var name = tr ? tr.querySelector('.lb-name') : null;

        if (name && name.value.trim() !== '' &&
            !confirm('Remove ' + name.value.trim() + ' from this page?')) {
            return;
        }

        tr.querySelectorAll('input[type=text]').forEach(function (input) { input.value = ''; });
        if (name) { name.focus(); }
        recount();
    });

    /* Enter moves down the Name column instead of submitting. Transcribing a
       page is twenty-five names in a row, and a form that submits on Enter
       turns that into twenty-five reloads. */
    form.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.target.tagName === 'BUTTON') { return; }
        e.preventDefault();

        /* Hidden inputs are excluded: they are not focusable, and counting them
           would land the jump on one and silently do nothing. Five focusable
           fields per line — name, address, contact, sex, type. */
        var fields = Array.prototype.slice.call(form.querySelectorAll('input:not([type=hidden]), select'));
        var next   = fields[fields.indexOf(e.target) + 5];   // same column, next line
        if (next) { next.focus(); }
    });

    var addBtn = document.getElementById('addRows');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            var last = body.rows.length;

            for (var i = 1; i <= 10; i++) {
                var n  = last + i;
                var tr = body.insertRow();
                tr.innerHTML =
                    /* data-label on every cell, matching the server-rendered
                       rows — that is what the mobile card layout reads to
                       caption each field. A row added here without them would
                       be an unlabelled card on a phone. */
                    '<td class="text-muted num" data-label="#">' + n + '</td>' +
                    '<td data-label="Name"><input type="text" name="row[' + n + '][full_name]" maxlength="160" class="form-control form-control-sm lb-name" aria-label="Line ' + n + ' name"></td>' +
                    '<td data-label="Address"><input type="text" name="row[' + n + '][address_text]" maxlength="160" class="form-control form-control-sm" aria-label="Line ' + n + ' address"></td>' +
                    '<td data-label="Contact no."><input type="text" name="row[' + n + '][contact_number]" maxlength="40" inputmode="tel" class="form-control form-control-sm" aria-label="Line ' + n + ' contact"></td>' +
                    '<td data-label="Sex"><select name="row[' + n + '][sex]" class="form-select form-select-sm" aria-label="Line ' + n + ' sex">' +
                        '<option value="">—</option><option value="male">M</option><option value="female">F</option>' +
                    '</select></td>' +
                    '<td data-label="Type"><input type="hidden" name="row[' + n + '][suggested_type]" value="domestic">' +
                    '<select name="row[' + n + '][tourist_type]" class="form-select form-select-sm" aria-label="Line ' + n + ' type">' +
                        /* Built from the server's list so a label change is made
                           once. Two hand-written copies of the same four options
                           is two places for them to disagree. */
                        <?= json_encode(implode('', array_map(
                            static fn (string $v, string $l): string =>
                                '<option value="' . $v . '"' . ($v === 'domestic' ? ' selected' : '') . '>' . $l . '</option>',
                            array_keys($types),
                            $types
                        )), JSON_UNESCAPED_UNICODE) ?> +
                    '</select></td>' +
                    '<td data-label="" class="text-end"><button type="button" class="btn btn-sm btn-outline-danger lb-remove" title="Remove this line" aria-label="Remove line ' + n + '"><i class="fa-solid fa-xmark"></i></button></td>';
            }
        });
    }

    recount();
})();
</script>

<?php require __DIR__ . '/_partials/foot.php'; ?>
