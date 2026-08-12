<?php
declare(strict_types=1);

/**
 * TourSync — importing a tourist list from a spreadsheet.            Feature 2
 *
 * Method 3. For the manager who already keeps the list in Excel and should not
 * have to retype it into a browser.
 *
 * THE FILE IS NEVER IMPORTED ON UPLOAD. Upload parses and validates; the
 * manager reads what would happen; a second, separate confirmation writes it.
 * A one-click import of a file with a shifted date column is a month of wrong
 * arrivals in the municipality's records, discovered later by someone who
 * cannot tell which rows were wrong.
 *
 * Between the two steps the file sits in storage/imports under a random name,
 * and the confirmation re-reads and re-validates it. The alternative — posting
 * the parsed rows back through a hidden field — would mean the rows finally
 * written are rows the browser sent, not rows the file contained, and the
 * preview would stop being evidence of anything.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Csrf;
use App\Core\LogbookImport;
use App\Core\ManagerAuth;
use App\Core\Session;
use App\Core\SpreadsheetReader;
use App\Repositories\ArrivalReportRepository as Reports;
use App\Repositories\LogbookEntryRepository as Entries;

ManagerAuth::require();

/** 5 MB is generous for a list of names; anything larger is not a tourist list. */
const IMPORT_MAX_BYTES = 5 * 1024 * 1024;

$destinationId = (int) ManagerAuth::destinationId();
$reportId      = (int) ($_GET['id'] ?? $_POST['report_id'] ?? 0);
$report        = $reportId > 0 ? Reports::find($reportId) : null;

if ($report === null || (int) $report['destination_id'] !== $destinationId) {
    Session::flash('danger', 'That report could not be found.');
    redirect(base_url('/manager/reports.php'));
}

if (!in_array($report['status'], ['draft', 'rejected'], true)) {
    Session::flash('danger', 'This report has been submitted and can no longer be changed.');
    redirect(base_url('/manager/report-form.php?id=' . $reportId));
}

/** storage/imports — outside the served tree, same as the logbook documents. */
$importDirectory = static function (): ?string {
    $directory = dirname(APP_PATH) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'imports';

    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        return null;
    }

    return is_writable($directory) ? $directory : null;
};

/**
 * Resolves the staged file for this report.
 *
 * The name comes from the session, never the request, and is re-checked against
 * the shape this page generates. A staged filename accepted from a form field
 * is a path the browser chose.
 */
$stagedPath = static function (int $reportId) use ($importDirectory): ?array {
    $staged = $_SESSION['_import'][$reportId] ?? null;

    if (!is_array($staged)) {
        return null;
    }

    $name = (string) ($staged['file'] ?? '');

    if (preg_match('/^[a-f0-9]{32}\.(csv|xlsx)$/', $name) !== 1) {
        return null;
    }

    $directory = $importDirectory();

    if ($directory === null) {
        return null;
    }

    $absolute = $directory . DIRECTORY_SEPARATOR . $name;

    return is_file($absolute) ? ['path' => $absolute, 'meta' => $staged] : null;
};

$discard = static function (int $reportId) use ($stagedPath): void {
    $staged = $stagedPath($reportId);

    if ($staged !== null) {
        @unlink($staged['path']);
    }

    unset($_SESSION['_import'][$reportId]);
};

$errors  = [];
$preview = null;

// -----------------------------------------------------------------------------
// Step 1 — upload, parse, validate
// -----------------------------------------------------------------------------

if (is_post() && ($_POST['action'] ?? '') === 'upload') {
    Csrf::verify();

    $discard($reportId);

    $file = $_FILES['sheet'] ?? null;
    $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($code !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
        $errors[] = match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is larger than the server allows.',
            UPLOAD_ERR_NO_FILE                        => 'No file was selected.',
            UPLOAD_ERR_PARTIAL                        => 'The upload was interrupted. Please try again.',
            default                                   => 'The upload failed.',
        };
    } elseif ((int) $file['size'] > IMPORT_MAX_BYTES) {
        $errors[] = 'Spreadsheets must be 5 MB or smaller.';
    } else {
        /* By its own bytes. A .csv extension on an old binary .xls would
           otherwise be read as text and produce nonsense rows. */
        $kind = SpreadsheetReader::detect((string) $file['tmp_name']);

        if ($kind === null) {
            $errors[] = 'That file is not a CSV or XLSX spreadsheet. If you exported from Excel, choose '
                . '"CSV UTF-8" or "Excel Workbook (.xlsx)".';
        } else {
            $reader = new SpreadsheetReader();
            $rows   = $reader->read((string) $file['tmp_name'], $kind);

            if ($rows === null) {
                $errors = array_merge($errors, $reader->errors());
            } else {
                $import = new LogbookImport($rows, (string) $report['period_start'], (string) $report['period_end']);
                $import->run();

                if ($import->fatalErrors() !== []) {
                    $errors = array_merge($errors, $import->fatalErrors());
                } else {
                    $directory = $importDirectory();

                    if ($directory === null) {
                        $errors[] = 'The server could not stage the file for review.';
                    } else {
                        $stored = bin2hex(random_bytes(16)) . '.' . $kind;

                        if (!move_uploaded_file((string) $file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $stored)) {
                            $errors[] = 'The server could not stage the file for review.';
                        } else {
                            $_SESSION['_import'][$reportId] = [
                                'file'     => $stored,
                                'kind'     => $kind,
                                'original' => mb_substr(basename((string) $file['name']), 0, 200),
                                'at'       => time(),
                            ];

                            $preview = $import;
                        }
                    }
                }
            }
        }
    }
}

// -----------------------------------------------------------------------------
// Step 2 — the manager confirms
// -----------------------------------------------------------------------------

if (is_post() && ($_POST['action'] ?? '') === 'confirm') {
    Csrf::verify();

    $staged = $stagedPath($reportId);

    if ($staged === null) {
        Session::flash('danger', 'That upload is no longer available. Please upload the file again.');
        redirect(base_url('/manager/import.php?id=' . $reportId));
    }

    $reader = new SpreadsheetReader();
    $rows   = $reader->read($staged['path'], (string) $staged['meta']['kind']);

    if ($rows === null) {
        $discard($reportId);
        Session::flash('danger', $reader->firstError() ?? 'That file could not be read.');
        redirect(base_url('/manager/import.php?id=' . $reportId));
    }

    /* Re-validated, not trusted from step 1. The period may have changed
       between the two steps, and the rows written must be rows this file
       actually contains. */
    $import = new LogbookImport($rows, (string) $report['period_start'], (string) $report['period_end']);
    $import->run();

    $byDate = $import->byDate();

    if ($byDate === []) {
        $discard($reportId);
        Session::flash('danger', 'There was nothing valid left to import.');
        redirect(base_url('/manager/import.php?id=' . $reportId));
    }

    $mode     = (string) ($_POST['mode'] ?? 'merge');
    $imported = 0;

    foreach ($byDate as $date => $rowsForDate) {
        $lines = [];

        /* Merge keeps what is already typed for that date and adds to it;
           replace throws the page away and uses the file. Said in those words
           on the form, because one of them destroys work. */
        if ($mode === 'merge') {
            foreach (Entries::forDate($reportId, (string) $date) as $existing) {
                $lines[] = [
                    'full_name'      => (string) $existing['full_name'],
                    'address_text'   => (string) ($existing['address_text'] ?? ''),
                    'contact_number' => (string) ($existing['contact_number'] ?? ''),
                    'tourist_type'   => (string) $existing['tourist_type'],
                    'suggested_type' => '',
                ];
            }
        }

        foreach ($rowsForDate as $row) {
            $lines[] = [
                'full_name'      => $row['full_name'],
                'address_text'   => $row['address_text'],
                'contact_number' => $row['contact_number'],
                /* No type in the file: the classifier reads the address, the
                   same way it does for a typed page. */
                'tourist_type'   => '',
                'suggested_type' => '',
            ];

            $imported++;
        }

        Entries::replaceForDate($reportId, (string) $date, $lines);
    }

    $discard($reportId);

    ActivityLog::record(
        'report.imported', 'arrival_report', $reportId,
        'Imported ' . $imported . ' tourist record(s) from a spreadsheet across '
        . count($byDate) . ' date(s) for ' . ManagerAuth::destinationName()
    );

    Session::flash('success', $imported . ' tourist record(s) imported across ' . count($byDate)
        . ' date(s). Check the pages before you submit.');

    redirect(base_url('/manager/report-form.php?id=' . $reportId));
}

if (is_post() && ($_POST['action'] ?? '') === 'cancel') {
    Csrf::verify();
    $discard($reportId);

    Session::flash('info', 'Import cancelled. Nothing was changed.');
    redirect(base_url('/manager/report-form.php?id=' . $reportId));
}

$pageTitle    = 'Import Tourist Records';
$pageIcon     = 'fa-file-import';
$pageSubtitle = ManagerAuth::destinationName() . ' · '
    . format_date($report['period_start'], 'M j') . ' – ' . format_date($report['period_end'], 'M j, Y');

require __DIR__ . '/_partials/head.php';
?>

<p class="mb-3">
    <a href="report-form.php?id=<?= $reportId ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fa-solid fa-arrow-left"></i> Back to the report
    </a>
</p>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <i class="fa-solid fa-circle-exclamation"></i>
        <strong>Nothing was imported.</strong>
        <?php foreach ($errors as $message): ?>
            <div class="small mt-1"><?= e($message) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($preview === null): ?>

    <!-- ===================== STEP 1 — CHOOSE THE FILE ===================== -->
    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-solid fa-file-arrow-up"></i> Choose a spreadsheet</h2>
        </header>

        <div class="panel__body">
            <p class="text-muted small">
                CSV or XLSX, up to 5&nbsp;MB. Nothing is saved when you upload &mdash; you will see exactly
                what would be imported, and what is wrong with it, before anything is written.
            </p>

            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="report_id" value="<?= $reportId ?>">

                <div class="row g-3 align-items-end">
                    <div class="col-md-7">
                        <label for="sheet" class="form-label">Spreadsheet file</label>
                        <input type="file" id="sheet" name="sheet" class="form-control" required
                               accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                    </div>

                    <div class="col-md-5">
                        <button type="submit" name="action" value="upload" class="btn btn-brand btn-sm">
                            <i class="fa-solid fa-magnifying-glass"></i> Check the file
                        </button>
                    </div>
                </div>
            </form>

            <hr class="my-4">

            <h3 class="h6">What the file needs</h3>

            <p class="text-muted small">
                A heading row with at least <strong>Name</strong> and <strong>Date</strong>.
                <strong>Address</strong> and <strong>Contact No.</strong> are optional but recommended &mdash;
                the address is what decides whether a visitor is counted as local or domestic.
                The headings may appear a few rows down; a title above them is fine.
            </p>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr><th>Name</th><th>Address</th><th>Contact No.</th><th>Date</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Halleah Singad</td>
                            <td>Cannery, Polomolok</td>
                            <td>09340201577</td>
                            <td>2024-12-07</td>
                        </tr>
                        <tr>
                            <td>Juan Cruz</td>
                            <td>Tamp.</td>
                            <td></td>
                            <td>2024-12-07</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-muted small mt-3 mb-0">
                Dates are safest as <strong>YYYY-MM-DD</strong>. A date written 07/12/2024 is refused rather
                than guessed &mdash; it is either 7 December or 12 July, and guessing wrong files a whole
                month of arrivals into the wrong one.
            </p>
        </div>
    </section>

<?php else: ?>

    <!-- ===================== STEP 2 — THE PREVIEW ===================== -->
    <?php
    $valid    = $preview->validRows();
    $issues   = $preview->issues();
    $errCount = $preview->errorCount();
    $warnings = $preview->warningCount();
    $byDate   = $preview->byDate();
    ?>

    <div class="stat-grid">
        <?php
        $cards = [
            ['icon' => 'fa-list',            'tone' => 'blue',  'value' => $preview->consideredRows(), 'label' => 'Rows read'],
            ['icon' => 'fa-circle-check',    'tone' => 'green', 'value' => count($valid),              'label' => 'Ready to import'],
            ['icon' => 'fa-circle-xmark',    'tone' => 'teal',  'value' => $errCount,                  'label' => 'Rows with errors'],
            ['icon' => 'fa-calendar-days',   'tone' => 'amber', 'value' => count($byDate),             'label' => 'Dates covered'],
        ];

        foreach ($cards as $card): ?>
            <article class="stat-card stat-card--<?= e($card['tone']) ?>">
                <div class="stat-card__icon"><i class="fa-solid <?= e($card['icon']) ?>"></i></div>
                <div class="stat-card__body">
                    <p class="stat-card__value"><?= n((int) $card['value']) ?></p>
                    <p class="stat-card__label"><?= e($card['label']) ?></p>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info"></i>
        <strong>Nothing has been saved yet.</strong>
        Read this through, then confirm at the bottom. Rows with errors are not imported; the rest are.
    </div>

    <?php if ($issues !== []): ?>
        <section class="panel">
            <header class="panel__head">
                <h2><i class="fa-solid fa-triangle-exclamation"></i> Problems found</h2>
                <span class="text-muted small">
                    <?= n($errCount) ?> error(s)<?= $warnings > 0 ? ', ' . n($warnings) . ' warning(s)' : '' ?>
                </span>
            </header>

            <div class="panel__body">
                <p class="text-muted small">
                    Row numbers match your spreadsheet. Fix them there and upload again, or import what is
                    valid now and add the rest by hand.
                </p>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr><th style="width:6rem">Row</th><th>Problem</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($issues, 0, 200) as $issue): ?>
                                <tr>
                                    <td class="cell-strong">Row <?= n($issue['row']) ?></td>
                                    <td>
                                        <?php if ($issue['level'] === 'warning'): ?>
                                            <span class="pill pill--qr">Warning</span>
                                        <?php else: ?>
                                            <span class="pill pill--flag">Error</span>
                                        <?php endif; ?>
                                        <?= e($issue['message']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (count($issues) > 200): ?>
                    <p class="text-muted small mt-2 mb-0">
                        Showing the first 200 of <?= n(count($issues)) ?>.
                    </p>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($valid !== []): ?>
        <section class="panel">
            <header class="panel__head">
                <h2><i class="fa-solid fa-eye"></i> What would be imported</h2>
                <span class="text-muted small"><?= n(count($valid)) ?> record(s)</span>
            </header>

            <div class="panel__body">
                <div class="logbook-scroll table-responsive">
                    <table class="table table-sm align-middle mb-0 logbook-table">
                        <thead>
                            <tr>
                                <th>Row</th><th>Name</th><th>Address</th><th>Contact no.</th><th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($valid, 0, 100) as $row): ?>
                                <tr>
                                    <td class="text-muted num" data-label="#"><?= e($row['row']) ?></td>
                                    <td data-label="Name"><?= e($row['full_name']) ?></td>
                                    <td data-label="Address">
                                        <?= $row['address_text'] !== '' ? e($row['address_text']) : '<span class="text-muted">—</span>' ?>
                                    </td>
                                    <td data-label="Contact no.">
                                        <?= $row['contact_number'] !== '' ? e($row['contact_number']) : '<span class="text-muted">—</span>' ?>
                                    </td>
                                    <td data-label="Date"><?= e(format_date($row['visit_date'], 'M j, Y')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (count($valid) > 100): ?>
                    <p class="text-muted small mt-2 mb-0">
                        Showing the first 100 of <?= n(count($valid)) ?>. All of them are imported on confirm.
                    </p>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <header class="panel__head">
                <h2><i class="fa-solid fa-check-double"></i> Confirm the import</h2>
            </header>

            <div class="panel__body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="report_id" value="<?= $reportId ?>">

                    <p class="text-muted small">
                        These dates already have pages in this report, and this decides what happens to them:
                        <?php
                        $touched = [];

                        foreach (array_keys($byDate) as $date) {
                            $existing = count(Entries::forDate($reportId, (string) $date));

                            if ($existing > 0) {
                                $touched[] = format_date((string) $date, 'M j') . ' (' . $existing . ' line(s))';
                            }
                        }

                        echo $touched === []
                            ? 'none — every date in the file is empty at the moment.'
                            : e(implode(', ', $touched)) . '.';
                        ?>
                    </p>

                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="mode" id="mode-merge" value="merge" checked>
                        <label class="form-check-label" for="mode-merge">
                            <strong>Add to what is already there</strong>
                            <span class="d-block text-muted small">
                                Keeps existing lines on those dates and appends the imported ones.
                            </span>
                        </label>
                    </div>

                    <div class="form-check mt-2">
                        <input class="form-check-input" type="radio" name="mode" id="mode-replace" value="replace">
                        <label class="form-check-label" for="mode-replace">
                            <strong>Replace those dates entirely</strong>
                            <span class="d-block text-muted small">
                                Deletes any lines already typed for the dates in this file and uses the file instead.
                            </span>
                        </label>
                    </div>

                    <div class="mt-3 d-flex gap-2 flex-wrap">
                        <button type="submit" name="action" value="confirm" class="btn btn-brand btn-sm"
                                onclick="return confirm('Import <?= n(count($valid)) ?> tourist record(s)?');">
                            <i class="fa-solid fa-file-import"></i> Import <?= n(count($valid)) ?> record(s)
                        </button>

                        <button type="submit" name="action" value="cancel" class="btn btn-sm btn-outline-secondary">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </section>
    <?php else: ?>
        <section class="panel">
            <div class="panel__body">
                <div class="empty-public">
                    <i class="fa-regular fa-circle-xmark"></i>
                    <h3>Nothing in that file could be imported</h3>
                    <p>Every row had a problem. Fix them in the spreadsheet and upload it again.</p>
                    <p class="mt-3">
                        <a href="import.php?id=<?= $reportId ?>" class="btn btn-sm btn-outline-secondary">
                            Try another file
                        </a>
                    </p>
                </div>
            </div>
        </section>
    <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/_partials/foot.php'; ?>
