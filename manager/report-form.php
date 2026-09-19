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
use App\Core\Paginator;
use App\Core\Session;
use App\Repositories\ArrivalReportRepository as Reports;
use App\Repositories\LogbookEntryRepository as Entries;
use App\Repositories\ReportDocumentRepository as Documents;
use App\Repositories\NotificationRepository as Notifications;

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

    /* DISCARD A DRAFT — one that was never handed to the Office. Ownership was
       settled above (a report from another destination never reaches this
       line), and deleteDraft() repeats both conditions in its WHERE clause. */
    if ($action === 'discard' && $id > 0) {
        if ($report['status'] !== 'draft') {
            Session::flash('danger', 'Only a draft that has never been submitted can be discarded.');
            redirect(base_url('/manager/report-form.php?id=' . $id));
        }

        if (Reports::deleteDraft($id, $destinationId)) {
            ActivityLog::record('report.discarded', 'arrival_report', null,
                'Discarded draft report #' . $id . ' for ' . ManagerAuth::destinationName()
                . ' (' . $report['period_start'] . ' to ' . $report['period_end'] . ')');

            Session::flash('success', 'Draft discarded.');
        }

        redirect(base_url('/manager/reports.php'));
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
    } elseif ($startTs < strtotime('-24 months', strtotime('today'))) {
        /* A FLOOR, BECAUSE THE FIELD HAD NONE.
         *
         * The past has to stay open — a manager types August's logbook in
         * September, so the period must be allowed to start before today, and
         * a "this month only" rule would mean last month's figures could never
         * be sent at all. But there was no bound in the other direction, so the
         * calendar offered 1902 and a mistyped year was accepted as a period.
         *
         * Two years is past any catch-up the office would ask for — the oldest
         * report on record starts July 2025 — and stops a slip of the year. */
        $errors['period'] = 'That start date is more than two years ago. Check the year, '
            . 'or ask the Tourism Office to record a period that old for you.';
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

            /* The report is the one thing in this system that becomes an
               official figure, and it cannot until an officer approves it.
               Until now nothing announced that one was waiting. */
            Notifications::record(
                'arrival_report',
                'Arrival report submitted — ' . ManagerAuth::destinationName(),
                [
                    'body'        => Entries::countFor($id) . ' entries for '
                                   . format_date($start, 'M j') . ' to ' . format_date($end, 'M j')
                                   . ', from ' . ManagerAuth::name() . '.',
                    'link'        => base_url('/admin/arrival-reports/review.php?id=' . $id),
                    'entity_type' => 'arrival_report',
                    'entity_id'   => $id,
                ]
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

/* WHICH PERIOD A NEW REPORT OPENS ON: this month so far.
 *
 * The first of this month to TODAY — not to the month's end, which is what it
 * used to be. On the 6th that proposed a period three-quarters in the future,
 * for a logbook page nobody had finished writing. A period that has actually
 * happened is the only one a manager can copy in.
 *
 * Nothing cleverer than that. An earlier attempt here walked back to the oldest
 * month the destination had not reported, which read well until you noticed it
 * was reasoning from seeded reports — a fact about the demonstration data, not
 * about how the office works.
 *
 * Any other period is typed into the two fields: a whole past month, a week, a
 * quarter. The defaults are a starting point, not a restriction. */
$defaultStart = date('Y-m-01');
$defaultEnd   = date('Y-m-d');

if ($report !== null) {
    $defaultStart = (string) $report['period_start'];
    $defaultEnd   = (string) $report['period_end'];
}

$periodStart = (string) ($_POST['period_start'] ?? $defaultStart);
$periodEnd   = (string) ($_POST['period_end']   ?? $defaultEnd);

/* THE TWO PERIODS ANYONE ACTUALLY ASKS FOR, AS ONE TAP EACH.
 *
 * "This month so far" and "last month, whole" are the two a destination sends,
 * and typing four date parts for either is work the form can do. Anything else
 * is still typed, and the third chip says so rather than pretending a custom
 * range is a mode you have to switch into.
 *
 * Which chip is lit is derived from the dates, not from what was clicked — so
 * a manager who edits a date by hand watches it fall to Custom, and one who
 * happens to type last month exactly sees Last month light up. A remembered
 * "mode" would sooner or later disagree with the fields under it. */
/* REPORT PERIOD: pick the stretch, and the dates fill themselves in.
 *
 * The commonest thing a manager sends is not a hand-typed range, it is "this
 * month" or "last month". Naming those turns four date parts into one choice,
 * and makes the form read as a reporting screen rather than a pair of empty
 * calendars.
 *
 * WHICH ONE IS SHOWN IS DERIVED FROM THE DATES, NOT REMEMBERED FROM A CLICK.
 * A manager who nudges a date by hand watches this fall to Custom Range,
 * because a label that keeps saying "This Month" over dates that are no longer
 * this month is worse than no label. Order matters in the match below: on the
 * first of a month, Today and This Month describe the same two days, and the
 * narrower name is the more useful one. */
$presets = [
    'today' => ['label' => 'Today',
                'start' => date('Y-m-d'),
                'end'   => date('Y-m-d')],
    'week'  => ['label' => 'This Week',
                'start' => date('Y-m-d', strtotime('monday this week')),
                'end'   => date('Y-m-d')],
    'month' => ['label' => 'This Month',
                'start' => date('Y-m-01'),
                'end'   => date('Y-m-d')],
    'last'  => ['label' => 'Last Month',
                'start' => date('Y-m-01', strtotime('first day of last month')),
                'end'   => date('Y-m-t', strtotime('first day of last month'))],
];

$activePreset = 'custom';

foreach ($presets as $key => $p) {
    if ($periodStart === $p['start'] && $periodEnd === $p['end']) {
        $activePreset = $key;
        break;
    }
}

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

/* ---------------------------------------------------------------------------
   THE TYPED LINES THEMSELVES, NOT ONLY THE DAYS THEY FALL ON
   ---------------------------------------------------------------------------
   The screen used to list dates: one row per day with four totals, and the
   names behind them only visible after opening a day. That is the right shape
   for checking coverage and the wrong one for answering "is anything in here
   yet" — the commonest question a manager has about their own draft.

   Both are kept. The names are the list; the per-day coverage and the office's
   four columns moved behind View Report Details, where the totals are checked.

   Built from Entries::forDate(), the same read logbook.php uses, over the days
   that already have a page — so no new query, no new repository method, and
   nothing here can disagree with what the logbook screen shows.
   --------------------------------------------------------------------------- */
$entries = [];

if ($id > 0) {
    foreach ($pages as $page) {
        foreach (Entries::forDate($id, (string) $page['visit_date']) as $row) {
            $entries[] = $row;
        }
    }

    /* Newest day first, and within a day the order the page was typed in —
       which is the order the paper logbook is written in. */
    usort($entries, static function (array $a, array $b): int {
        return [$b['visit_date'], $a['row_no']] <=> [$a['visit_date'], $b['row_no']];
    });
}

$entryTotal  = count($entries);
$entrySearch = trim((string) ($_GET['q'] ?? ''));

if ($entrySearch !== '') {
    $needle  = mb_strtolower($entrySearch);
    $entries = array_values(array_filter($entries, static function (array $r) use ($needle): bool {
        foreach (['full_name', 'address_text', 'contact_number', 'origin_city', 'origin_province', 'origin_country'] as $f) {
            if (mb_strpos(mb_strtolower((string) ($r[$f] ?? '')), $needle) !== false) {
                return true;
            }
        }

        return false;
    }));
}

$entryPer = (int) ($_GET['per'] ?? 10);

if (!in_array($entryPer, [10, 25, 50, 100], true)) {
    $entryPer = 10;
}

$entryPager = Paginator::slice($entries, $_GET['epage'] ?? null, $entryPer);

/** The office's own vocabulary for a line's type, not this system's. */
$typeLabels = [
    'local'             => 'Local',
    'domestic'          => 'Domestic',
    'foreign'           => 'Foreign',
    'overseas_filipino' => 'OFW',
];

/** Rebuilds this screen's query string with one value changed. */
$rfLink = static function (array $changes) use ($id, $entrySearch, $entryPer): string {
    $q = array_filter([
        'id'  => (string) $id,
        'q'   => $entrySearch,
        'per' => $entryPer !== 10 ? (string) $entryPer : '',
    ], static fn (string $v): bool => $v !== '');

    foreach ($changes as $k => $v) {
        if ($v === '' || $v === null) { unset($q[$k]); } else { $q[$k] = (string) $v; }
    }

    return 'report-form.php?' . http_build_query($q);
};

$pageTitle    = $report === null ? 'New Arrival Report' : 'Arrival Entries';
$pageIcon     = 'fa-file-pen';
$pageSubtitle = $report === null
    ? ManagerAuth::destinationName()
    : 'Add tourist arrivals for the selected reporting period.';

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

<?php /* TWO WARNINGS, NOT TWO PARAGRAPHS.
         These were full-width banners of three sentences each, and on the
         redesigned screen they took a third of the height before the manager
         reached a single arrival. Every fact is kept; what went is the room
         they took to say it. rf-alert tightens them, and the list itself now
         marks the lines they are about — the Pending pill is the same lines. */ ?>
<?php if ($unsure > 0 && $editable): ?>
    <div class="alert alert-warning rf-alert">
        <i class="fa-solid fa-circle-question"></i>
        <strong><?= n($unsure) ?> line(s) need a second look.</strong>
        The address was not recognised, so the type beside it is a guess &mdash; they are the
        <span class="pill pill--flag">Pending</span> lines below. A guess that reaches the Office
        unchallenged becomes one of the municipality's statistics.
    </div>
<?php endif; ?>

<?php if ($totalRow['unplaced'] > 0): ?>
    <div class="alert alert-warning rf-alert">
        <i class="fa-solid fa-map-location-dot"></i>
        <strong><?= n($totalRow['unplaced']) ?> visitor(s) have no recognised place of residence.</strong>
        They are counted in the total, but the Office's monthly form has three residence columns
        &mdash; This province, Other Province, Foreign Country &mdash; and these fall into none of
        them. Correcting the address puts them in the right column.
        <?php if ($editable && $report !== null): ?>
            The per-column figures are under <strong>View Report Details</strong>.
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
/* THE PERIOD EDITOR, DEFINED ONCE AND PLACED TWICE.
 *
 * A report that does not exist yet is nothing but its dates, so there the
 * editor is the page. Once the draft exists the dates are settled and the
 * screen belongs to the arrivals, so the same fields move behind View Report
 * Details — same markup, same names, same handler, one definition. */
$periodEditor = static function () use (
    $editable, $presets, $activePreset, $periodStart, $periodEnd, $report
): void {
    /* THE RULES ARE ON THE FIELDS, NOT ONLY BEHIND THEM.
             *
             * The handler below has always refused a period that ends before it
             * starts, one that begins in the future, and one longer than a
             * quarter. None of that reached the two date boxes, so a manager
             * picked whatever the calendar offered, pressed save, and was sent
             * back with a sentence explaining a rule nobody had told them.
             *
             * The same three limits are now on the inputs, so the calendar will
             * not offer a date that is going to be refused. The server still
             * checks every one of them — a min attribute is a courtesy, not a
             * guard, and the overlap check can only be made there anyway.
             *
             * The end date may sit in the future on purpose: the form opens on
             * this month, and "this month" runs past today for all but its last
             * day.
             *
             * The start stays open to the past — a manager types August's page
             * in September — but not open forever: two years back, which the
             * handler above enforces for the same reason. */
            $today    = date('Y-m-d');
            $oldest   = date('Y-m-d', strtotime('-24 months'));
            $endFloor = $periodStart !== '' ? $periodStart : $today;
            $endCap   = date('Y-m-d', strtotime($endFloor . ' +92 days'));
            ?>

            <?php if ($editable): ?>
                <?php /* Its own row, so it sits directly above From and lines up
                         with it. It carries no name attribute and never posts:
                         it fills period_start and period_end, which do. */ ?>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label for="report_period" class="form-label">Report Period</label>
                        <select id="report_period" class="form-select">
                            <?php foreach ($presets as $key => $p): ?>
                                <option value="<?= e($key) ?>"
                                        data-start="<?= e($p['start']) ?>"
                                        data-end="<?= e($p['end']) ?>"
                                        <?= $activePreset === $key ? 'selected' : '' ?>><?= e($p['label']) ?></option>
                            <?php endforeach; ?>
                            <option value="custom" <?= $activePreset === 'custom' ? 'selected' : '' ?>>Custom Range</option>
                        </select>
                    </div>
                </div>
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-md-4">
                    <label for="period_start" class="form-label">From</label>
                    <input type="date" id="period_start" name="period_start" class="form-control"
                           value="<?= e($periodStart) ?>"
                           min="<?= e($oldest) ?>" max="<?= e($today) ?>"
                           required <?= $editable ? '' : 'disabled' ?>>
                </div>

                <div class="col-md-4">
                    <label for="period_end" class="form-label">To</label>
                    <input type="date" id="period_end" name="period_end" class="form-control"
                           value="<?= e($periodEnd) ?>"
                           min="<?= e($endFloor) ?>" max="<?= e($endCap) ?>"
                           required <?= $editable ? '' : 'disabled' ?>>
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

            <?php if ($editable): ?>
                <?php /* One span, not loose text. .report-suggest is a flex row,
                         and a bare text node beside a <strong> becomes its own
                         flex item — which put the dates in a narrow column of
                         their own with the sentence split either side. */ ?>
                <p class="report-suggest mt-2 mb-0">
                    <i class="fa-solid fa-lightbulb" aria-hidden="true"></i>
                    <span>
                        A new report opens on <strong>This Month</strong> &mdash; the 1st to today.
                        Choosing another period fills both dates for you; change either one by
                        hand and the box above reads <strong>Custom Range</strong>.
                    </span>
                </p>

                <?php /* Said before it is broken. These are the same rules the
                         handler enforces, in the order somebody meets them. */ ?>
                <p class="text-muted small mt-2 mb-0">
                    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                    A period may start in the past &mdash; that is how last month's logbook is
                    sent &mdash; but not in the future, and not more than two years back. It
                    cannot end before it starts, and covers at most one quarter (about 92 days).
                    Dates already covered by another report for this destination are refused, so
                    correct that report instead.
                </p>
            <?php endif; ?>
<?php }; ?>

<?php if ($report === null): ?>

    <?php /* A report that does not exist yet is nothing but its dates, so the
             editor is the page. A "Set the dates first" panel used to sit under
             this one telling the manager to do the only thing available. */ ?>
    <form method="post">
        <?= csrf_field() ?>

        <section class="panel">
            <header class="panel__head">
                <h2><i class="fa-solid fa-calendar-days"></i> Reporting period</h2>
            </header>

            <div class="panel__body">
                <?php $periodEditor(); ?>

                <div class="mt-3 d-flex gap-2 flex-wrap">
                    <button type="submit" name="action" value="save" class="btn btn-brand btn-sm">
                        <i class="fa-solid fa-floppy-disk"></i> Create draft
                    </button>

                    <a href="reports.php" class="btn btn-sm btn-outline-secondary">Back to reports</a>
                </div>
            </div>
        </section>
    </form>

    <?php else: ?>

        <?php
        $periodName = $activePreset === 'custom'
            ? 'Custom Range'
            : $presets[$activePreset]['label'];

        $statusTone = match ($report['status']) {
            'draft'    => 'void',
            'rejected' => 'flag',
            'approved' => 'ok',
            default    => 'qr',
        };

        /* A date inside the period for the manual-entry shortcut to open on.
           Today when the period covers it — which is the usual case, since a
           new report opens on this month — and the last day it does cover
           otherwise, because that is the page most likely still being written. */
        $todayIso   = date('Y-m-d');
        $entryDate  = ($todayIso >= $report['period_start'] && $todayIso <= $report['period_end'])
            ? $todayIso
            : (string) $report['period_end'];
        ?>

        <?php /* =============== THE PERIOD, AS ONE LINE ===============
                 It was a full panel with two calendars, a preset box, a notes
                 field and two paragraphs of rules — all of it settled the moment
                 the draft exists, and all of it above the work. It reads as one
                 line now; the editor is behind View Report Details, unchanged. */ ?>
        <div class="rf-bar">
            <span class="rf-bar__icon" aria-hidden="true"><i class="fa-solid fa-calendar-days"></i></span>

            <div class="rf-bar__text">
                <span class="rf-bar__label">Reporting Period</span>
                <span class="rf-bar__dates">
                    <?= e($periodName) ?> &middot;
                    <?= e(format_date((string) $report['period_start'], 'M j, Y')) ?>
                    &ndash; <?= e(format_date((string) $report['period_end'], 'M j, Y')) ?>
                </span>
            </div>

            <span class="pill pill--<?= e($statusTone) ?>"><?= e(Reports::STATUSES[$report['status']]) ?></span>

            <button type="button" class="btn btn-sm btn-outline-secondary rf-bar__more"
                    data-rf-open="rfDetails">
                <i class="fa-solid fa-circle-info"></i> View Report Details
            </button>
        </div>

        <?php if ($editable): ?>
            <?php /* =============== THE THREE WAYS IN ===============
                     The same three routes the screen always offered — a typed
                     page, a spreadsheet, a photograph — which used to be a link
                     buried in a paragraph, a second link in another panel, and a
                     whole section further down. None of them changes what they
                     do; they are simply all visible at once, because a manager
                     choosing between them cannot choose what they cannot see. */ ?>
            <div class="rf-ways">
                <button type="button" class="rf-way" data-rf-open="rfDate">
                    <span class="rf-way__icon rf-way__icon--green"><i class="fa-solid fa-pen-to-square"></i></span>
                    <span class="rf-way__text">
                        <strong>Manual Entry</strong>
                        <span>Add entries one by one</span>
                    </span>
                    <i class="fa-solid fa-chevron-right rf-way__go" aria-hidden="true"></i>
                </button>

                <a class="rf-way" href="import.php?id=<?= $id ?>">
                    <span class="rf-way__icon rf-way__icon--blue"><i class="fa-solid fa-file-excel"></i></span>
                    <span class="rf-way__text">
                        <strong>Import from Excel/CSV</strong>
                        <span>Upload a spreadsheet</span>
                    </span>
                    <i class="fa-solid fa-chevron-right rf-way__go" aria-hidden="true"></i>
                </a>

                <a class="rf-way" href="#documents">
                    <span class="rf-way__icon rf-way__icon--amber"><i class="fa-solid fa-camera"></i></span>
                    <span class="rf-way__text">
                        <strong>Attach Logbook Photo</strong>
                        <span>Upload a picture of the page</span>
                    </span>
                    <i class="fa-solid fa-chevron-right rf-way__go" aria-hidden="true"></i>
                </a>
            </div>
        <?php endif; ?>

        <?php /* =============== THE LINES THEMSELVES =============== */ ?>
        <section class="panel">
            <header class="panel__head panel__head--controls">
                <h2><i class="fa-solid fa-list-ul"></i> Recent Entries</h2>

                <div class="rf-tools">
                    <form method="get" class="rf-search">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <?php if ($entryPer !== 10): ?>
                            <input type="hidden" name="per" value="<?= (int) $entryPer ?>">
                        <?php endif; ?>

                        <label class="visually-hidden" for="entrySearch">Search entries</label>
                        <i class="fa-solid fa-magnifying-glass rf-search__icon" aria-hidden="true"></i>
                        <input type="search" id="entrySearch" name="q" class="form-control form-control-sm"
                               value="<?= e($entrySearch) ?>" placeholder="Search name, address, etc.">
                    </form>

                    <?php if ($editable): ?>
                        <button type="button" class="btn btn-brand btn-sm" data-rf-open="rfDate">
                            <i class="fa-solid fa-plus"></i> Add Entry
                        </button>
                    <?php endif; ?>
                </div>
            </header>

            <div class="panel__body">
                <?php if ($entryPager['rows'] === []): ?>
                    <div class="empty rf-empty">
                        <i class="fa-regular fa-rectangle-list"></i>
                        <?php if ($entrySearch !== ''): ?>
                            <h3>Nothing matches that search</h3>
                            <p>
                                <?= n($entryTotal) ?> line(s) are typed into this report &mdash; none of them
                                mention &ldquo;<?= e($entrySearch) ?>&rdquo;.
                                <a href="<?= e($rfLink(['q' => null, 'epage' => null])) ?>">Clear the search</a>.
                            </p>
                        <?php elseif ($editable): ?>
                            <h3>No arrivals typed in yet</h3>
                            <p>
                                Use one of the three ways above. A photograph of the paper page counts on its
                                own &mdash; you do not have to type every name to submit.
                            </p>
                        <?php else: ?>
                            <h3>No arrivals were typed in</h3>
                            <p>This report was submitted on its logbook photo alone.</p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 rf-table">
                            <thead>
                                <tr>
                                    <th class="rf-table__n">#</th>
                                    <th>Date</th>
                                    <th>Name</th>
                                    <th>Address</th>
                                    <th>Contact No.</th>
                                    <?php /* There is no party size in a logbook line — one line is one
                                             visitor — so this column carries the thing the office's own
                                             form is filed by instead. */ ?>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($entryPager['rows'] as $i => $row): ?>
                                    <?php
                                    $low  = $row['confidence'] === 'low';
                                    $date = (string) $row['visit_date'];
                                    ?>
                                    <tr>
                                        <td class="rf-table__n"><?= n($entryPager['from'] + $i) ?></td>

                                        <td class="rf-nowrap"><?= e(format_date($date, 'm/d/Y')) ?></td>

                                        <td><span class="cell-strong"><?= e((string) $row['full_name']) ?></span></td>

                                        <td>
                                            <?= e((string) ($row['address_text'] ?: '—')) ?>
                                        </td>

                                        <td class="rf-nowrap"><?= e((string) ($row['contact_number'] ?: '—')) ?></td>

                                        <td class="rf-nowrap"><?= e($typeLabels[$row['tourist_type']] ?? '—') ?></td>

                                        <td>
                                            <?php /* Confirmed means the address was recognised and the type
                                                     beside it is a reading, not a guess. Pending means it is
                                                     a guess, and a guess that reaches the Office unchallenged
                                                     becomes one of the municipality's statistics. */ ?>
                                            <span class="pill pill--<?= $low ? 'flag' : 'ok' ?>">
                                                <?= $low ? 'Pending' : 'Confirmed' ?>
                                            </span>
                                        </td>

                                        <td class="text-end">
                                            <?php /* One action, so one button. A menu holding a single item
                                                     is a menu that charges a click for nothing. */ ?>
                                            <a class="btn btn-sm btn-outline-secondary rf-row-act"
                                               href="logbook.php?id=<?= $id ?>&amp;date=<?= e($date) ?>"
                                               title="<?= $editable ? 'Edit' : 'View' ?> the page for <?= e(format_date($date, 'M j')) ?>"
                                               aria-label="<?= $editable ? 'Edit' : 'View' ?> the page for <?= e(format_date($date, 'M j')) ?>">
                                                <i class="fa-solid fa-<?= $editable ? 'pen' : 'eye' ?>" aria-hidden="true"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php /* Hidden on a single page: a pager under ten rows is
                             furniture that says the list is longer than it is. */ ?>
                    <?php if ($entryPager['total'] > 10): ?>
                        <div class="rf-pager">
                            <p class="rf-pager__count">
                                Showing <?= n($entryPager['from']) ?>&ndash;<?= n($entryPager['to']) ?>
                                of <?= n($entryPager['total']) ?> entries
                            </p>

                            <form method="get" class="rf-pager__size">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <?php if ($entrySearch !== ''): ?>
                                    <input type="hidden" name="q" value="<?= e($entrySearch) ?>">
                                <?php endif; ?>
                                <label class="visually-hidden" for="entryPer">Entries per page</label>
                                <select id="entryPer" name="per" class="form-select form-select-sm"
                                        onchange="this.form.submit()">
                                    <?php foreach ([10, 25, 50, 100] as $n): ?>
                                        <option value="<?= $n ?>" <?= $entryPer === $n ? 'selected' : '' ?>><?= $n ?> per page</option>
                                    <?php endforeach; ?>
                                </select>
                            </form>

                            <nav class="rf-pager__pages" aria-label="Pages">
                                <a class="rf-pager__step<?= $entryPager['page'] <= 1 ? ' is-off' : '' ?>"
                                   href="<?= e($rfLink(['epage' => $entryPager['page'] - 1])) ?>"
                                   <?= $entryPager['page'] <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                                   aria-label="Previous page">&lsaquo;</a>

                                <?php for ($i = 1; $i <= $entryPager['pages']; $i++): ?>
                                    <a class="rf-pager__num<?= $i === $entryPager['page'] ? ' is-active' : '' ?>"
                                       href="<?= e($rfLink(['epage' => $i === 1 ? null : $i])) ?>"><?= $i ?></a>
                                <?php endfor; ?>

                                <a class="rf-pager__step<?= $entryPager['page'] >= $entryPager['pages'] ? ' is-off' : '' ?>"
                                   href="<?= e($rfLink(['epage' => $entryPager['page'] + 1])) ?>"
                                   <?= $entryPager['page'] >= $entryPager['pages'] ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                                   aria-label="Next page">&rsaquo;</a>
                            </nav>
                        </div>
                    <?php else: ?>
                        <p class="rf-pager__count mt-3 mb-0">
                            Showing <?= n($entryPager['from']) ?>&ndash;<?= n($entryPager['to']) ?>
                            of <?= n($entryPager['total']) ?> entries
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>

        <!-- =============== METHOD 2 — THE PAPER PAGE ITSELF ===============
             A separate form because it carries a file. A manager who already has
             a completed page can photograph it, attach it, and submit without
             typing a name. -->
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
                    <div class="empty rf-empty">
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
                                                <?php /* The hidden field is set on click and the
                                                         confirmation is the shared dialog. Both still
                                                         happen in that order: the id is written before
                                                         anybody is asked, so a confirmed delete always
                                                         carries the document it was pressed for. */ ?>
                                                onclick="document.getElementById('deleteDocId').value='<?= (int) $doc['id'] ?>';"
                                                data-confirm="Remove this document from the report?">
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
                                       capture="environment" data-max-mb="<?= n(upload_limit_mb()) ?>">
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

        <!-- ===================== SUBMIT ===================== -->
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="period_start" value="<?= e($periodStart) ?>">
            <input type="hidden" name="period_end" value="<?= e($periodEnd) ?>">
            <input type="hidden" name="notes" value="<?= e((string) ($report['notes'] ?? '')) ?>">

            <?php if ($editable): ?>
                <?php
                $hasSomething = $totalRow['entries'] > 0 || $documents !== [];

                $summary = $totalRow['entries'] > 0
                    ? n($totalRow['entries']) . ' visitor(s) across ' . n(count($pages)) . ' page(s)'
                    : n(count($documents)) . ' logbook document(s)';

                /* Escaped PHP tags: the manager submitting a month of figures was
                   shown the source of the summary instead of the summary. Built
                   above the tag as one string. */
                $submitAsk = sprintf(
                    "Submit this report to the Municipal Tourism Office?\n\n%s. "
                    . "You will not be able to edit it while they review it.",
                    $summary
                );
                ?>

                <div class="rf-foot">
                    <p class="rf-foot__count">
                        <?php if ($hasSomething): ?>
                            <strong><?= e($summary) ?></strong> ready to send.
                            <?php if ($totalRow['entries'] === 0): ?>
                                No records were typed in, so this goes as the logbook photo alone &mdash;
                                the Office reads the arrivals off the page.
                            <?php endif; ?>
                        <?php else: ?>
                            Nothing to send yet. Type a page, import a spreadsheet, or attach a photo.
                        <?php endif; ?>
                    </p>

                    <a href="reports.php" class="btn btn-sm btn-outline-secondary">Back to reports</a>

                    <?php /* Draft only — a report the Office has never seen. It posts
                             the separate form below the page (form="rfDiscard"),
                             because this footer is inside the report's own form. */ ?>
                    <?php if ($report !== null && $report['status'] === 'draft'): ?>
                        <?php
                        $discardAsk = 'Discard this draft? Everything typed into it and any document '
                            . 'attached to it is deleted. It was never sent to the Municipal Tourism '
                            . 'Office, so nothing on their side changes. This cannot be undone.';
                        ?>
                        <button type="submit" form="rfDiscard" class="btn btn-sm btn-outline-danger"
                                data-confirm="<?= e($discardAsk) ?>" data-confirm-tone="danger">
                            <i class="fa-solid fa-trash-can" aria-hidden="true"></i> Discard Draft
                        </button>
                    <?php endif; ?>

                    <button type="submit" name="action" value="submit" class="btn btn-brand btn-sm"
                            <?= $hasSomething ? '' : 'disabled' ?>
                            data-confirm="<?= e($submitAsk) ?>">
                        <i class="fa-solid fa-paper-plane"></i> Submit Report
                    </button>
                </div>
            <?php endif; ?>
        </form>

        <?php if ($report !== null && $report['status'] === 'draft'): ?>
            <?php /* The Discard Draft button above submits this. Outside the
                     report's form, because forms cannot nest. */ ?>
            <form method="post" id="rfDiscard" hidden>
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $report['id'] ?>">
                <input type="hidden" name="action" value="discard">
            </form>
        <?php endif; ?>

        <?php /* =============== REPORT DETAILS ===============
                 What the main screen no longer carries: the dates and how to
                 change them, and the day-by-day coverage with the office's four
                 columns. The list of names cannot show which day is still empty;
                 this can, and it is where the totals are checked against the
                 Tourism Attraction Visitor Record. */ ?>
        <dialog id="rfDetails" class="sheet sheet--wide" aria-labelledby="rfDetailsTitle">
            <header class="sheet__head">
                <h2 id="rfDetailsTitle"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Report Details</h2>
                <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </header>

            <div class="sheet__body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">

                    <?php $periodEditor(); ?>

                    <?php if ($editable): ?>
                        <div class="mt-3 d-flex gap-2 flex-wrap align-items-center">
                            <button type="submit" name="action" value="save" class="btn btn-sm btn-brand">
                                <i class="fa-solid fa-floppy-disk"></i> Save period
                            </button>
                            <span class="text-muted small">
                                Narrowing the dates removes any pages that fall outside them.
                            </span>
                        </div>
                    <?php endif; ?>
                </form>

                <h3 class="rf-details__head">
                    <i class="fa-solid fa-book-open" aria-hidden="true"></i> Day by day
                    <span class="text-muted small"><?= n($totalRow['entries']) ?> visitor(s) typed in</span>
                </h3>

                <p class="text-muted small">
                    One line per date in the period. The four figures are worked out from the addresses on
                    the lines &mdash; they are not typed, so they cannot disagree with the lines behind them.
                </p>

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
            </div>

            <footer class="sheet__foot">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close>Close</button>
            </footer>
        </dialog>

        <?php if ($editable): ?>
            <?php /* =============== WHICH DAY'S PAGE ===============
                     Manual entry is per date — that is how the paper logbook is
                     written and how logbook.php is addressed. Rather than send
                     the manager to a list to pick one, this asks for the date and
                     opens that page. It navigates; it saves nothing. */ ?>
            <dialog id="rfDate" class="sheet" aria-labelledby="rfDateTitle">
                <header class="sheet__head">
                    <h2 id="rfDateTitle"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> Manual Entry</h2>
                    <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="sheet__body">
                    <p class="text-muted small">
                        Which day are you copying in? The page opens with the paper logbook's own columns
                        &mdash; Name, Address, Contact no. &mdash; and takes as many lines as the page has.
                    </p>

                    <label for="rfDateInput" class="form-label">Date of visit</label>
                    <input type="date" id="rfDateInput" class="form-control"
                           value="<?= e($entryDate) ?>"
                           min="<?= e((string) $report['period_start']) ?>"
                           max="<?= e((string) $report['period_end']) ?>">

                    <p class="text-muted small mt-2 mb-0">
                        Between <?= e(format_date((string) $report['period_start'], 'M j')) ?>
                        and <?= e(format_date((string) $report['period_end'], 'M j, Y')) ?> &mdash;
                        the period this report covers. To record a day outside it, change the period first.
                    </p>
                </div>

                <footer class="sheet__foot">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close>Cancel</button>
                    <a class="btn btn-sm btn-brand" id="rfDateGo"
                       href="logbook.php?id=<?= $id ?>&amp;date=<?= e($entryDate) ?>">
                        <i class="fa-solid fa-arrow-right"></i> Open the page
                    </a>
                </footer>
            </dialog>
        <?php endif; ?>

    <?php endif; ?>

<script>
/* KEEPS "TO" IN STEP WITH "FROM".
 *
 * The min and max the server rendered describe the period as it was when the
 * page loaded. The moment a manager moves the start date, the window for the
 * end date moves with it, and an attribute written in PHP cannot know that.
 *
 * This only narrows what the calendar offers. Every one of these rules is
 * checked again on submit, where the overlap test lives too — a date input is
 * a courtesy to the person filling it in, never the thing that enforces
 * anything.
 */
(function () {
    'use strict';

    var from = document.getElementById('period_start');
    var to   = document.getElementById('period_end');

    if (!from || !to || from.disabled) { return; }

    var QUARTER = 92;

    /* Built from the local date parts, not toISOString().
     *
     * new Date('2026-02-17T00:00:00') is local midnight, and toISOString()
     * converts that to UTC — which in Manila is 16:00 the day before, so the
     * string came back one day early and the quarter was 91 days, not 92. The
     * date input speaks local dates; so does this. */
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };

    var shift = function (days) {
        var d = new Date(from.value + 'T00:00:00');
        if (isNaN(d.getTime())) { return ''; }
        d.setDate(d.getDate() + days);
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    };

    var syncWindow = function () {
        if (!from.value) { return; }

        to.min = from.value;
        to.max = shift(QUARTER);

        /* A start date dragged past the end leaves the end behind it, and one
           dragged far back leaves the period longer than a quarter. Either way
           the end is moved to the nearest date still allowed rather than left
           to be refused on save. */
        if (to.value && to.value < to.min) { to.value = to.min; }
        if (to.value && to.max && to.value > to.max) { to.value = to.max; }
    };

    from.addEventListener('change', syncWindow);

    /* ---------------------------------------------------------------
       Report Period: choose the stretch, the dates fill themselves in
       --------------------------------------------------------------- */
    var period = document.getElementById('report_period');

    if (!period) { return; }

    /* Read back from the dates, never remembered from the last choice. Nudge a
       date by hand and this falls to Custom Range, because a box still reading
       "This Month" over dates that are not this month is worse than no box. */
    var resync = function () {
        var found = 'custom';

        [].forEach.call(period.options, function (o) {
            if (found === 'custom'
                && o.dataset.start === from.value && o.dataset.end === to.value) {
                found = o.value;
            }
        });

        period.value = found;
    };

    period.addEventListener('change', function () {
        var picked = period.options[period.selectedIndex];

        /* Custom Range has no dates of its own — it is the name for whatever is
           already in the fields. It puts the cursor in the first of them, which
           is the only thing left to do. */
        if (!picked || !picked.dataset.start) {
            from.focus();
            return;
        }

        /* In the order that survives the clamp: the start, then the window it
           allows the end, then the end inside that window. */
        from.value = picked.dataset.start;
        syncWindow();
        to.value = picked.dataset.end;
    });

    [from, to].forEach(function (el) {
        el.addEventListener('change', resync);
        el.addEventListener('input', resync);
    });
})();

/* THE TWO DIALOGS THIS SCREEN OPENS.
 *
 * Report Details holds the period editor and the day-by-day coverage; Manual
 * Entry asks which day's page to open. Neither writes anything by itself — the
 * first submits the same form the period panel always did, the second is a
 * link whose date the input keeps in step.
 *
 * data-dialog-close is handled by the shared script, so closing is not here. */
(function () {
    'use strict';

    document.addEventListener('click', function (ev) {
        var opener = ev.target.closest('[data-rf-open]');
        if (!opener) { return; }

        var box = document.getElementById(opener.getAttribute('data-rf-open'));
        if (!box || typeof box.showModal !== 'function') { return; }

        ev.preventDefault();
        box.showModal();
    });

    /* The date and the link are one thing said twice, so they are kept in step
       rather than read at the moment of clicking — a link whose href is stale
       is a link that opens the wrong day. */
    var picker = document.getElementById('rfDateInput');
    var go     = document.getElementById('rfDateGo');

    if (!picker || !go) { return; }

    var base = go.getAttribute('href').split('&date=')[0];

    var sync = function () {
        if (!picker.value) { go.setAttribute('aria-disabled', 'true'); return; }
        go.removeAttribute('aria-disabled');
        go.setAttribute('href', base + '&date=' + encodeURIComponent(picker.value));
    };

    picker.addEventListener('change', sync);
    picker.addEventListener('input', sync);
})();
</script>

<?php require __DIR__ . '/_partials/foot.php'; ?>
