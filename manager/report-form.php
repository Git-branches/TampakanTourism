<?php
declare(strict_types=1);

/**
 * TourSync — one arrival report: its period, and its pages.          Feature 2
 *
 * A report is a stack of paper logbook pages, so this screen is the cover of
 * the stack: the dates it covers, and one line per page with what is on it.
 * The typing happens on logbook.php, a page at a time, laid out like the paper.
 *
 * NOTHING ON THIS SCREEN IS A COUNT THE MANAGER TYPES. The local / domestic /
 * foreign / OFW figures are derived from the Address column of the pages behind
 * them and rebuilt on every save. There is no field in which to enter a total
 * that disagrees with its lines, which removes the whole class of error that a
 * hand tally introduces — and that error is the reason the office cannot trust
 * a figure today.
 *
 * Two things are refused rather than accepted and corrected later, because both
 * end as a wrong number in a report to the Mayor:
 *
 *   overlapping periods   two live reports covering the same Tuesday would
 *                         double or silently overwrite that day in the summary
 *   pages outside the     narrowing the dates after typing would otherwise
 *   period                submit lines for days the report claims not to cover
 *
 * A submitted report is read-only. Once handed over it belongs to the review,
 * and a manager editing figures an officer is looking at is how two people end
 * up describing different numbers to each other on the phone.
 *
 * All writes happen before any output — head.php starts the page, and a
 * redirect after that is a redirect that cannot send its header.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Csrf;
use App\Core\DocumentUploader;
use App\Core\ManagerAuth;
use App\Core\Session;
use App\Repositories\ArrivalReportRepository as Reports;
use App\Repositories\LogbookEntryRepository as Entries;
use App\Repositories\ReportDocumentRepository as Documents;

ManagerAuth::require();

$destinationId = (int) ManagerAuth::destinationId();

/* Accepted from either place. The forms on this page post back to the same URL
   and so normally carry ?id= already, but the upload form also names it in a
   hidden field: a POST that lost the query string would otherwise fall through
   every branch and report success while having done nothing. */
$id     = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$report = $id > 0 ? Reports::find($id) : null;

/* The guard that makes ?id= safe. A report belonging to another destination is
   treated as though it does not exist — no "access denied" that confirms it
   does, and so no way to enumerate a neighbour's submissions by watching which
   ids answer differently. */
if ($id > 0 && ($report === null || (int) $report['destination_id'] !== $destinationId)) {
    Session::flash('danger', 'That report could not be found.');
    redirect(base_url('/manager/reports.php'));
}

$editable = $report === null || in_array($report['status'], ['draft', 'rejected'], true);
$errors   = [];

// -----------------------------------------------------------------------------
// Save / submit
// -----------------------------------------------------------------------------

if (is_post()) {
    Csrf::verify();

    if (!$editable) {
        Session::flash('danger', 'This report has already been submitted and can no longer be edited.');
        redirect(base_url('/manager/report-form.php?id=' . $id));
    }

    $action = (string) ($_POST['action'] ?? 'save');

    // -------------------------------------------------------------------------
    // Method 2 — the photograph or PDF of the paper page
    //
    // Handled before the period logic and returned from, because these posts
    // carry no dates and running them through the period validation would
    // reject an upload for a reason that has nothing to do with the file.
    // -------------------------------------------------------------------------
    if ($action === 'upload' && $id > 0) {
        $uploader = new DocumentUploader();
        $stored   = $uploader->store($_FILES['document'] ?? []);

        if ($stored === null) {
            Session::flash('danger', $uploader->firstError() ?? 'That file could not be uploaded.');
            redirect(base_url('/manager/report-form.php?id=' . $id));
        }

        $covers = trim((string) ($_POST['covers_date'] ?? ''));

        /* A date on the document has to be one the report covers, for the same
           reason a logbook page does — otherwise the office is handed evidence
           for a day the submission claims not to include. */
        if ($covers !== '' && ($covers < $report['period_start'] || $covers > $report['period_end'])) {
            $covers = '';
        }

        $documentId = Documents::add(
            $id,
            $stored,
            (int) ManagerAuth::id(),
            $covers,
            trim((string) ($_POST['caption'] ?? ''))
        );

        ActivityLog::record(
            'report.document_uploaded', 'arrival_report', $id,
            'Uploaded ' . $stored['original_name'] . ' (' . Documents::humanSize($stored['byte_size']) . ') '
            . 'to the ' . ManagerAuth::destinationName() . ' report'
        );

        Session::flash('success', 'Logbook document uploaded. The Municipal Tourism Office can open it during review.');
        redirect(base_url('/manager/report-form.php?id=' . $id . '#documents'));
    }

    if ($action === 'delete-document' && $id > 0) {
        $documentId = (int) ($_POST['document_id'] ?? 0);

        if (Documents::remove($documentId, $id)) {
            ActivityLog::record(
                'report.document_removed', 'arrival_report', $id,
                'Removed a supporting document from the ' . ManagerAuth::destinationName() . ' report'
            );

            Session::flash('success', 'Document removed.');
        }

        redirect(base_url('/manager/report-form.php?id=' . $id . '#documents'));
    }

    $start  = trim((string) ($_POST['period_start'] ?? ''));
    $end    = trim((string) ($_POST['period_end'] ?? ''));
    $notes  = trim((string) ($_POST['notes'] ?? ''));

    $startTs = $start !== '' ? strtotime($start) : false;
    $endTs   = $end   !== '' ? strtotime($end)   : false;

    if ($startTs === false || $endTs === false) {
        $errors['period'] = 'Enter both a start and an end date.';
    } elseif ($endTs < $startTs) {
        $errors['period'] = 'The period ends before it starts.';
    } elseif ($startTs > strtotime('today')) {
        $errors['period'] = 'A reporting period cannot begin in the future.';
    } elseif (($endTs - $startTs) / 86400 > 92) {
        $errors['period'] = 'A single report covers at most one quarter. Please split a longer period.';
    }

    if ($errors === []) {
        $clash = Reports::overlapping($destinationId, $start, $end, $id);

        if ($clash !== []) {
            $first = $clash[0];

            $errors['period'] = 'Those dates are already covered by another report ('
                . format_date($first['period_start'], 'M j') . ' to ' . format_date($first['period_end'], 'M j, Y')
                . ', ' . Reports::STATUSES[$first['status']] . '). Adjust the period, or correct that report instead.';
        }
    }

    /* Either method satisfies this. A manager who photographed a completed page
       has submitted their arrivals just as surely as one who typed them, and
       demanding both would put the travel back in a different form. */
    if ($errors === [] && $action === 'submit' && $id > 0
        && Entries::countFor($id) === 0 && Documents::countFor($id) === 0) {
        $errors['entries'] = 'There is nothing to submit yet. Either copy in a logbook page, import a '
            . 'spreadsheet, or attach a photo of the paper page.';
    }

    if ($errors === []) {
        if ($report === null) {
            $id = Reports::createDraft($destinationId, $start, $end, $notes);

            ActivityLog::record(
                'report.created', 'arrival_report', $id,
                'Draft for ' . ManagerAuth::destinationName() . ' (' . $start . ' to ' . $end . ')'
            );
        } else {
            Reports::updateDraft($id, $start, $end, $notes);
        }

        /* A narrowed period drops the pages that fall outside it. Said out loud
           rather than done quietly — those are lines somebody typed. */
        $dropped = Entries::trimToPeriod($id, $start, $end);

        if ($action === 'submit') {
            Reports::submit($id, (int) ManagerAuth::id());

            ActivityLog::record(
                'report.submitted', 'arrival_report', $id,
                'Submitted by ' . ManagerAuth::name() . ' for ' . ManagerAuth::destinationName()
                . ' — ' . Entries::countFor($id) . ' logbook entries'
            );

            Session::flash('success', 'Report submitted. The Municipal Tourism Office can see it now — there is nothing to deliver.');
            redirect(base_url('/manager/reports.php'));
        }

        Session::flash('success', $dropped > 0
            ? 'Draft saved. ' . $dropped . ' line(s) fell outside the new dates and were removed.'
            : 'Draft saved. It stays private to you until you submit it.');

        redirect(base_url('/manager/report-form.php?id=' . $id));
    }

    /* Fell through with errors: nothing was written, and the form below redraws
       from $_POST so the manager does not lose what they changed. */
    $report = $id > 0 ? Reports::find($id) : null;
}

// -----------------------------------------------------------------------------
// The cover of the stack
// -----------------------------------------------------------------------------

$periodStart = (string) ($_POST['period_start'] ?? $report['period_start'] ?? date('Y-m-01'));
$periodEnd   = (string) ($_POST['period_end']   ?? $report['period_end']   ?? date('Y-m-t'));

$documents = $id > 0 ? Documents::forReport($id) : [];
$pages     = $id > 0 ? Entries::pages($id) : [];
$byDate   = [];
$unsure   = 0;
/* The office's three residence columns, plus the lines that fall into none of
   them. Same names the monthly Tourism Attraction Visitor Record uses, so the
   manager is checking their figures in the vocabulary the sheet is filed in. */
$totalRow = [
    'entries'        => 0,
    'this_province'  => 0,
    'other_province' => 0,
    'foreign_total'  => 0,
    'unplaced'       => 0,
];

foreach ($pages as $page) {
    $byDate[$page['visit_date']] = $page;
    $unsure += (int) $page['unsure'];

    foreach ($totalRow as $field => $_) {
        $totalRow[$field] += (int) $page[$field];
    }
}

/** Every date in the period, capped at a quarter. */
$dates = [];

for ($t = (int) strtotime($periodStart); $t !== false && $t <= (int) strtotime($periodEnd); $t = (int) strtotime('+1 day', $t)) {
    $dates[] = date('Y-m-d', $t);

    if (count($dates) >= 92) {
        break;
    }
}

$pageTitle    = $report === null ? 'New Arrival Report' : 'Arrival Report';
$pageIcon     = 'fa-file-pen';
$pageSubtitle = ManagerAuth::destinationName();

require __DIR__ . '/_partials/head.php';
?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <i class="fa-solid fa-circle-exclamation"></i>
        <strong>Nothing was saved.</strong>
        <?php foreach ($errors as $message): ?>
            <div class="small mt-1"><?= e($message) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($report !== null && $report['status'] === 'rejected'): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-rotate-left"></i>
        <strong>Sent back by the Municipal Tourism Office.</strong>
        <div class="small mt-1"><?= e((string) $report['rejection_reason']) ?></div>
        <div class="small mt-1">Correct the pages below and submit again.</div>
    </div>
<?php endif; ?>

<?php if ($report !== null && !$editable): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-lock"></i>
        This report is <strong><?= e(Reports::STATUSES[$report['status']]) ?></strong> and is read-only.
        <?php if ($report['status'] === 'approved'): ?>
            Its figures are now part of the municipality's tourism records.
        <?php else: ?>
            The Office is reviewing it. If something in it is wrong, tell them &mdash; they can send it
            back to you for correction.
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($unsure > 0 && $editable): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-circle-question"></i>
        <strong><?= n($unsure) ?> line(s) need a second look.</strong>
        The address written on those lines was not recognised, so the type is a guess. Open the page
        and set it &mdash; a guess that reaches the Office unchallenged becomes one of the
        municipality's statistics.
    </div>
<?php endif; ?>

<?php if ($totalRow['unplaced'] > 0): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-map-location-dot"></i>
        <strong><?= n($totalRow['unplaced']) ?> visitor(s) have no recognised place of residence.</strong>
        They are counted in the total, but the Office's monthly form has three residence columns
        &mdash; This province, Other Province, Foreign Country &mdash; and these fall into none of
        them. Correcting the address on those lines puts them in the right column.
        <?php if ($editable): ?>
            Look for the <span class="cell-sub text-danger">unplaced</span> note in the Other Province
            column below.
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="post">
    <?= csrf_field() ?>

    <!-- ===================== THE PERIOD ===================== -->
    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-solid fa-calendar-days"></i> Reporting period</h2>
        </header>

        <div class="panel__body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="period_start" class="form-label">From</label>
                    <input type="date" id="period_start" name="period_start" class="form-control"
                           value="<?= e($periodStart) ?>" required <?= $editable ? '' : 'disabled' ?>>
                </div>

                <div class="col-md-4">
                    <label for="period_end" class="form-label">To</label>
                    <input type="date" id="period_end" name="period_end" class="form-control"
                           value="<?= e($periodEnd) ?>" required <?= $editable ? '' : 'disabled' ?>>
                </div>

                <div class="col-md-4">
                    <label for="notes" class="form-label">
                        Notes <span class="text-muted small">(optional)</span>
                    </label>
                    <input type="text" id="notes" name="notes" class="form-control" maxlength="500"
                           value="<?= e((string) ($_POST['notes'] ?? $report['notes'] ?? '')) ?>"
                           placeholder="e.g. closed Aug 12 for trail repairs" <?= $editable ? '' : 'disabled' ?>>
                </div>
            </div>

            <div class="mt-3 d-flex gap-2 flex-wrap">
                <?php if ($editable): ?>
                    <button type="submit" name="action" value="save" class="btn btn-sm btn-outline-secondary">
                        <i class="fa-solid fa-floppy-disk"></i>
                        <?= $report === null ? 'Create draft' : 'Save period' ?>
                    </button>
                <?php endif; ?>

                <a href="reports.php" class="btn btn-sm btn-outline-secondary">Back to reports</a>
            </div>

            <?php if ($editable && $report !== null): ?>
                <p class="text-muted small mt-2 mb-0">
                    Narrowing the dates removes any pages that fall outside them.
                </p>
            <?php endif; ?>
        </div>
    </section>
</form>

<?php if ($report === null): ?>

        <!-- Nothing to list until the draft exists and has a period to hang pages on. -->
        <section class="panel">
            <div class="panel__body">
                <div class="empty-public">
                    <i class="fa-regular fa-calendar"></i>
                    <h3>Set the dates first</h3>
                    <p>
                        Choose the period this report covers and create the draft. You can then copy in the
                        logbook pages, attach a photo of the paper page, or import a spreadsheet.
                    </p>
                </div>
            </div>
        </section>

    <?php else: ?>

        <!-- =============== METHOD 2 — THE PAPER PAGE ITSELF ===============
             A separate form because it carries a file. Placed before the typed
             records deliberately: a manager who already has a completed page
             can photograph it, attach it, and submit without typing a name. -->
        <section class="panel" id="documents">
            <header class="panel__head">
                <h2><i class="fa-solid fa-paperclip"></i> Photo or PDF of the paper logbook</h2>
                <span class="text-muted small"><?= n(count($documents)) ?> file(s)</span>
            </header>

            <div class="panel__body">
                <p class="text-muted small">
                    If the page is already filled in on paper, photograph it and attach it here &mdash; you do
                    not have to type every name. The Municipal Tourism Office opens the original during review.
                    JPG, PNG or PDF, up to 8&nbsp;MB.
                </p>

                <?php if ($documents === []): ?>
                    <div class="empty-public">
                        <i class="fa-regular fa-image"></i>
                        <h3>No logbook photo attached</h3>
                        <p>Attach one if you have the paper page to hand.</p>
                    </div>
                <?php else: ?>
                    <div class="doc-list">
                        <?php foreach ($documents as $doc): ?>
                            <article class="doc-card">
                                <div class="doc-card__icon">
                                    <i class="fa-solid <?= $doc['mime_type'] === 'application/pdf' ? 'fa-file-pdf' : 'fa-file-image' ?>"></i>
                                </div>

                                <div class="doc-card__body">
                                    <strong><?= e((string) $doc['original_name']) ?></strong>
                                    <span class="cell-sub">
                                        <?= e(Documents::humanSize((int) $doc['byte_size'])) ?>
                                        <?php if ($doc['covers_date']): ?>
                                            &middot; page for <?= e(format_date((string) $doc['covers_date'], 'M j')) ?>
                                        <?php endif; ?>
                                        &middot; <?= e(format_date((string) $doc['created_at'], 'M j, g:i A')) ?>
                                    </span>
                                    <?php if ($doc['caption']): ?>
                                        <span class="cell-sub"><?= e((string) $doc['caption']) ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="doc-card__actions">
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="<?= e(base_url('/api/reports/document.php?id=' . (int) $doc['id'] . '&report=' . $id)) ?>"
                                       target="_blank" rel="noopener">
                                        <i class="fa-solid fa-eye"></i> View
                                    </a>

                                    <?php if ($editable): ?>
                                        <button type="submit" name="action" value="delete-document"
                                                form="documentForm"
                                                class="btn btn-sm btn-outline-danger"
                                                onclick="document.getElementById('deleteDocId').value='<?= (int) $doc['id'] ?>'; return confirm('Remove this document from the report?');">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($editable): ?>
                    <form method="post" action="report-form.php?id=<?= $id ?>"
                          enctype="multipart/form-data" id="documentForm" class="mt-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="document_id" id="deleteDocId" value="">

                        <div class="row g-3 align-items-end">
                            <div class="col-md-5">
                                <label for="document" class="form-label">Choose a file</label>
                                <input type="file" id="document" name="document" class="form-control"
                                       accept="image/jpeg,image/png,application/pdf,.jpg,.jpeg,.png,.pdf"
                                       capture="environment">
                            </div>

                            <div class="col-md-3">
                                <label for="covers_date" class="form-label">
                                    Page date <span class="text-muted small">(optional)</span>
                                </label>
                                <input type="date" id="covers_date" name="covers_date" class="form-control"
                                       min="<?= e((string) $report['period_start']) ?>"
                                       max="<?= e((string) $report['period_end']) ?>">
                            </div>

                            <div class="col-md-4">
                                <label for="caption" class="form-label">
                                    Note <span class="text-muted small">(optional)</span>
                                </label>
                                <input type="text" id="caption" name="caption" class="form-control" maxlength="200"
                                       placeholder="e.g. page 3 of 4">
                            </div>
                        </div>

                        <div class="mt-3">
                            <button type="submit" name="action" value="upload" class="btn btn-brand btn-sm">
                                <i class="fa-solid fa-cloud-arrow-up"></i> Attach document
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <!-- ===================== THE PAGES ===================== -->
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="period_start" value="<?= e($periodStart) ?>">
            <input type="hidden" name="period_end" value="<?= e($periodEnd) ?>">
            <input type="hidden" name="notes" value="<?= e((string) ($report['notes'] ?? '')) ?>">
        <section class="panel">
            <header class="panel__head">
                <h2><i class="fa-solid fa-book-open"></i> Logbook pages</h2>
                <span class="text-muted small"><?= n($totalRow['entries']) ?> visitor(s) typed in</span>
            </header>

            <div class="panel__body">
                <p class="text-muted small">
                    One line per date in the period. Open a date and copy that day's page from the paper
                    logbook &mdash; Name, Address, Contact no. The four figures below are worked out from
                    the addresses; they are not typed, so they cannot disagree with the lines behind them.
                </p>

                <?php if ($editable): ?>
                    <p class="mb-3">
                        <a href="import.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fa-solid fa-file-import"></i> Import from Excel or CSV
                        </a>
                        <span class="text-muted small ms-2">
                            Already have the list in a spreadsheet? You will see a preview before anything is saved.
                        </span>
                    </p>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <!-- The office's own column names, not this system's.
                                     A manager checking their figures against the monthly
                                     Tourism Attraction Visitor Record has to be reading the
                                     same three headings the sheet uses — "Domestic" would
                                     scatter Polomolok and Koronadal into a column the office
                                     does not have. -->
                                <th>Date</th>
                                <th class="text-end">Visitors</th>
                                <th class="text-end">This province</th>
                                <th class="text-end">Other Province</th>
                                <th class="text-end">Foreign</th>
                                <th></th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($dates as $date):
                                $page    = $byDate[$date] ?? null;
                                $weekend = in_array(date('N', (int) strtotime($date)), ['6', '7'], true);
                                ?>
                                <tr class="<?= $page === null ? 'text-muted' : '' ?>">
                                    <td>
                                        <span class="cell-strong"><?= e(format_date($date, 'M j')) ?></span>
                                        <span class="cell-sub"><?= e(date('D', (int) strtotime($date))) ?><?= $weekend ? ' &middot; weekend' : '' ?></span>
                                    </td>

                                    <?php if ($page === null): ?>
                                        <td class="text-end num">&mdash;</td>
                                        <td class="text-end num">&mdash;</td>
                                        <td class="text-end num">&mdash;</td>
                                        <td class="text-end num">&mdash;</td>
                                    <?php else: ?>
                                        <td class="text-end num"><strong><?= n((int) $page['entries']) ?></strong></td>
                                        <td class="text-end num"><?= n((int) $page['this_province']) ?></td>
                                        <td class="text-end num">
                                            <?= n((int) $page['other_province']) ?>
                                            <?php if ((int) $page['unplaced'] > 0): ?>
                                                <span class="cell-sub text-danger">
                                                    +<?= n((int) $page['unplaced']) ?> unplaced
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end num"><?= n((int) $page['foreign_total']) ?></td>
                                    <?php endif; ?>

                                    <td class="text-end">
                                        <?php if ($page !== null && (int) $page['unsure'] > 0): ?>
                                            <span class="pill pill--flag"><?= n((int) $page['unsure']) ?> to check</span>
                                        <?php endif; ?>

                                        <a href="logbook.php?id=<?= $id ?>&amp;date=<?= e($date) ?>"
                                           class="btn btn-sm btn-outline-secondary">
                                            <?php if ($page === null): ?>
                                                <?= $editable ? 'Add page' : 'View' ?>
                                            <?php else: ?>
                                                <?= $editable ? 'Edit page' : 'View page' ?>
                                            <?php endif; ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>

                        <tfoot>
                            <tr>
                                <th class="text-end">Total</th>
                                <th class="text-end num"><?= n($totalRow['entries']) ?></th>
                                <th class="text-end num"><?= n($totalRow['this_province']) ?></th>
                                <th class="text-end num"><?= n($totalRow['other_province']) ?></th>
                                <th class="text-end num"><?= n($totalRow['foreign_total']) ?></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <?php if ($editable): ?>
                    <div class="mt-3 d-flex gap-2 flex-wrap">
                        <?php
                        $hasSomething = $totalRow['entries'] > 0 || $documents !== [];

                        $summary = $totalRow['entries'] > 0
                            ? n($totalRow['entries']) . ' visitor(s) across ' . n(count($pages)) . ' page(s)'
                            : n(count($documents)) . ' logbook document(s)';
                        ?>
                        <button type="submit" name="action" value="submit" class="btn btn-brand btn-sm"
                                <?= $hasSomething ? '' : 'disabled' ?>
                                onclick="return confirm('Submit this report to the Municipal Tourism Office?\n\n<?= e($summary) ?>. You will not be able to edit it while they review it.');">
                            <i class="fa-solid fa-paper-plane"></i> Submit Report
                        </button>
                    </div>

                    <?php if (!$hasSomething): ?>
                        <p class="text-muted small mt-2 mb-0">
                            Before submitting: copy in a logbook page above, import a spreadsheet, or attach a
                            photo of the paper page.
                        </p>
                    <?php elseif ($totalRow['entries'] === 0): ?>
                        <p class="text-muted small mt-2 mb-0">
                            No records were typed in, so this will be submitted as the logbook photo alone.
                            The Office will read the arrivals off the page.
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
        </form>

    <?php endif; ?>

<?php require __DIR__ . '/_partials/foot.php'; ?>
