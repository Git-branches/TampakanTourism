<?php
declare(strict_types=1);

/**
 * TourSync — reviewing one submitted arrival report.                 Feature 2
 *
 * The point at which a manager's figures become the municipality's figures.
 * Approving writes every day of the report into arrival_daily_summary, which is
 * what analytics, the DOT-format reports and the decision support all read, so
 * this screen shows the whole table before asking for a decision rather than a
 * total to rubber-stamp.
 *
 * Rejection requires a reason in writing. "Sent back" with no explanation means
 * a phone call, and the phone call is the thing this feature exists to remove.
 *
 * A rejected report can still be approved later, and an approved one can still
 * be sent back — a figure discovered wrong next month has to be correctable, or
 * the record stays wrong forever. Both transitions are logged.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;
use App\Repositories\ArrivalReportRepository as Reports;
use App\Repositories\ManagerNotificationRepository as Bell;
use App\Repositories\LogbookEntryRepository as Entries;
use App\Repositories\ReportDocumentRepository as Documents;

Auth::require();

$id     = (int) ($_GET['id'] ?? 0);
$report = $id > 0 ? Reports::find($id) : null;

if ($report === null) {
    Session::flash('danger', 'That report could not be found.');
    redirect(base_url('/admin/arrival-reports/index.php'));
}

/* A draft belongs to the manager who is still writing it. The office does not
   see one in the queue, and should not see one by typing its id either. */
if ($report['status'] === 'draft') {
    Session::flash('warning', 'That report is still a draft. It has not been submitted yet.');
    redirect(base_url('/admin/arrival-reports/index.php'));
}

// -----------------------------------------------------------------------------
// The decision
// -----------------------------------------------------------------------------

if (is_post()) {
    Csrf::verify();

    $action  = (string) ($_POST['action'] ?? '');
    $adminId = (int) Auth::id();

    /* Approving publishes figures into the municipality's official record, and
       rejecting withdraws them again. Staff may open a report and mark it as
       being looked at; the decision itself is the Tourism Officer's. Checked
       here rather than only hidden in the markup, because a hidden button is
       still a form anyone can post. */
    if (in_array($action, ['approve', 'reject'], true) && !Auth::isOfficer()) {
        Session::flash('danger', 'Only the Tourism Officer can approve or send back a report.');
        redirect(base_url('/admin/arrival-reports/review.php?id=' . $id));
    }

    if ($action === 'reviewing') {
        Reports::markReviewing($id, $adminId);

        ActivityLog::record(
            'report.reviewing', 'arrival_report', $id,
            'Opened for review: ' . $report['destination_name']
        );

        Session::flash('info', 'Marked as under review. The manager can see that you have picked it up.');
        redirect(base_url('/admin/arrival-reports/review.php?id=' . $id));
    }

    if ($action === 'approve') {
        /* A submission needs SOMETHING in it — but a photograph of the page is
           something. Approving a photo-only submission records that the office
           has accepted the manager's return; it publishes no figures, because
           there are none to publish, and nobody typed a number nobody read. */
        $hasDocuments = Documents::countFor($id) > 0;

        if ((int) $report['day_count'] === 0 && !$hasDocuments) {
            Session::flash('danger', 'This report is empty — no records and no logbook document. Send it back instead.');
            redirect(base_url('/admin/arrival-reports/review.php?id=' . $id));
        }

        Reports::approve($id, $adminId);

        /* approve() is a no-op unless the report was submitted or under review,
           so the outcome is read back rather than assumed. Telling an officer a
           report was approved when the transition was refused is worse than
           telling them nothing. */
        $after = Reports::find($id);

        if ($after !== null && $after['status'] === 'approved') {
            ActivityLog::record(
                'report.approved', 'arrival_report', $id,
                'Approved ' . $report['destination_name'] . ' (' . $report['period_start'] . ' to '
                . $report['period_end'] . '), ' . (int) $report['visitors'] . ' visitors'
            );

            Bell::record((int) $report['destination_id'], 'report_approved',
                'Your arrival report was approved', [
                    'body'        => 'The figures are now part of the municipality\'s tourism records.',
                    'link'        => base_url('/manager/reports.php'),
                    'entity_type' => 'arrival_report',
                    'entity_id'   => $id,
                ]);

            Session::flash('success', 'Approved. The figures are now part of the municipality\'s tourism records.');
        } else {
            Session::flash('warning', 'That report could not be approved from its current status. Reload and try again.');
        }

        redirect(base_url('/admin/arrival-reports/index.php'));
    }

    if ($action === 'reject') {
        $reason = trim((string) ($_POST['rejection_reason'] ?? ''));

        /* Enforced, not merely asked for. A manager reading "Rejected" with an
           empty reason learns nothing except that they have to ring the office
           — which is the trip this feature replaced. */
        if (mb_strlen($reason) < 10) {
            Session::flash('danger', 'Please give the manager a reason of at least 10 characters — they have to know what to correct.');
            redirect(base_url('/admin/arrival-reports/review.php?id=' . $id));
        }

        Reports::reject($id, $adminId, mb_substr($reason, 0, 500));

        ActivityLog::record(
            'report.rejected', 'arrival_report', $id,
            'Sent back to ' . $report['destination_name'] . ': ' . mb_substr($reason, 0, 120)
        );

        Bell::record((int) $report['destination_id'], 'report_returned',
            'Your arrival report was sent back', [
                'body'        => $reason,
                'link'        => base_url('/manager/reports.php'),
                'entity_type' => 'arrival_report',
                'entity_id'   => $id,
            ]);

        Session::flash('success', 'Sent back with your reason. The manager can correct it and resubmit.');
        redirect(base_url('/admin/arrival-reports/index.php'));
    }

    redirect(base_url('/admin/arrival-reports/review.php?id=' . $id));
}

// -----------------------------------------------------------------------------
// The figures
// -----------------------------------------------------------------------------

$days    = Reports::days($id);
$pending = in_array($report['status'], ['submitted', 'reviewing'], true);

/* The transcription behind the figures. The officer is checking typed lines
   against a paper page, so the lines have to be visible — a review that only
   shows totals is a review of the manager's arithmetic, and the arithmetic is
   the one part of this the system already did. */
$pages       = Entries::pages($id);
$entryCount  = Entries::countFor($id);
$unsureCount = Entries::unsure($id);

$pageByDate = [];
foreach ($pages as $page) {
    $pageByDate[$page['visit_date']] = $page;
}

$documents = Documents::forReport($id);
$method    = Documents::methodLabel($entryCount, count($documents));

$showEntries = (string) ($_GET['entries'] ?? '') === '1';

$totals = ['local_count' => 0, 'domestic_count' => 0, 'foreign_count' => 0, 'ofw_count' => 0, 'total_visitors' => 0];

foreach ($days as $day) {
    foreach ($totals as $field => $_) {
        $totals[$field] += (int) $day[$field];
    }
}

$pageTitle    = 'Review Arrival Report';
$pageIcon     = 'fa-file-circle-check';
$pageSubtitle = $report['destination_name'] . ' · ' . format_date($report['period_start'], 'M j')
    . ' – ' . format_date($report['period_end'], 'M j, Y');

require __DIR__ . '/../_partials/head.php';
?>

<p class="mb-3">
    <a href="index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa-solid fa-arrow-left"></i> Back to the queue
    </a>
</p>

<!-- ===================== THE SUBMISSION ===================== -->
<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-circle-info"></i> Submission</h2>
        <?php
        $tone = match ($report['status']) {
            'approved'  => 'ok',
            'rejected'  => 'flag',
            'reviewing' => 'qr',
            default     => 'void',
        };
        ?>
        <span class="pill pill--<?= $tone ?>"><?= e(Reports::STATUSES[$report['status']]) ?></span>
    </header>

    <div class="panel__body">
        <dl class="detail-grid">
            <div>
                <dt>Destination</dt>
                <dd><?= e((string) $report['destination_name']) ?></dd>
            </div>
            <div>
                <dt>Period</dt>
                <dd><?= e(format_date($report['period_start'], 'M j, Y')) ?>
                    &ndash; <?= e(format_date($report['period_end'], 'M j, Y')) ?></dd>
            </div>
            <div>
                <dt>Submitted by</dt>
                <dd><?= e((string) ($report['submitted_by_name'] ?: '—')) ?></dd>
            </div>
            <div>
                <dt>Submitted</dt>
                <dd><?= $report['submitted_at'] ? e(format_date((string) $report['submitted_at'], 'M j, Y g:i A')) : '—' ?></dd>
            </div>
            <div>
                <dt>Days reported</dt>
                <dd><?= n(count($days)) ?></dd>
            </div>
            <div>
                <dt>Total visitors</dt>
                <dd><strong><?= n($totals['total_visitors']) ?></strong></dd>
            </div>
            <div>
                <dt>Submission method</dt>
                <dd><?= e($method) ?></dd>
            </div>
        </dl>

        <?php if ($report['notes']): ?>
            <p class="mt-3 mb-0">
                <strong>Manager's note:</strong> <?= e((string) $report['notes']) ?>
            </p>
        <?php endif; ?>

        <?php if ($report['status'] === 'rejected' && $report['rejection_reason']): ?>
            <div class="alert alert-warning mt-3 mb-0">
                <strong>Sent back<?= $report['reviewed_by_name'] ? ' by ' . e((string) $report['reviewed_by_name']) : '' ?>:</strong>
                <?= e((string) $report['rejection_reason']) ?>
            </div>
        <?php endif; ?>

        <?php if ($report['status'] === 'approved'): ?>
            <div class="alert alert-success mt-3 mb-0">
                <i class="fa-solid fa-circle-check"></i>
                Approved<?= $report['reviewed_by_name'] ? ' by ' . e((string) $report['reviewed_by_name']) : '' ?>
                <?= $report['reviewed_at'] ? 'on ' . e(format_date((string) $report['reviewed_at'], 'M j, Y')) : '' ?>.
                These figures are in the daily summary and appear in analytics and reports.
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ===================== THE DAILY FIGURES ===================== -->
<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-table-list"></i> Daily figures</h2>
    </header>

    <div class="panel__body">
        <?php if ($days === []): ?>

            <div class="empty-public">
                <i class="fa-regular fa-calendar"></i>
                <h3>No structured records</h3>
                <?php if ($documents !== []): ?>
                    <p>
                        This was submitted as the paper logbook alone &mdash; see the supporting documents above.
                        Approving it records that the Office has received the manager's return; it publishes no
                        figures, because none were typed in.
                    </p>
                <?php else: ?>
                    <p>This report has no records and no logbook document. Send it back for correction.</p>
                <?php endif; ?>
            </div>

        <?php else: ?>

            <p class="text-muted small">
                Days the destination was closed or had no visitors are not listed &mdash; only days with
                arrivals were submitted. Approving writes each of these dates into the municipality's
                daily summary, replacing anything already recorded for that date.
            </p>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th class="text-end">Local</th>
                            <th class="text-end">Domestic</th>
                            <th class="text-end">Foreign</th>
                            <th class="text-end">OFW</th>
                            <th class="text-end">Total</th>
                            <th>Breakdown</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($days as $day): ?>
                            <tr>
                                <td>
                                    <span class="cell-strong"><?= e(format_date($day['visit_date'], 'M j')) ?></span>
                                    <span class="cell-sub"><?= e(date('D', (int) strtotime((string) $day['visit_date']))) ?></span>
                                </td>
                                <td class="text-end num"><?= n((int) $day['local_count']) ?></td>
                                <td class="text-end num"><?= n((int) $day['domestic_count']) ?></td>
                                <td class="text-end num"><?= n((int) $day['foreign_count']) ?></td>
                                <td class="text-end num"><?= n((int) $day['ofw_count']) ?></td>
                                <td class="text-end num"><strong><?= n((int) $day['total_visitors']) ?></strong></td>
                                <td class="small text-muted">
                                    <?php
                                    /* Where the day's figures came from. The
                                       paper form has no sex or age column, so
                                       those stay empty and only a CSV import
                                       (which can carry them) ever fills them in.
                                       Printing "0 male" where nothing was given
                                       would read as a count of zero men. */
                                    $parts = [];

                                    if ($day['male_count'] !== null || $day['female_count'] !== null) {
                                        $parts[] = n((int) $day['male_count']) . ' M / ' . n((int) $day['female_count']) . ' F';
                                    }

                                    if ($day['children_count'] !== null || $day['adults_count'] !== null || $day['seniors_count'] !== null) {
                                        $parts[] = n((int) $day['children_count']) . ' child / '
                                            . n((int) $day['adults_count']) . ' adult / '
                                            . n((int) $day['seniors_count']) . ' senior';
                                    }

                                    if ($parts !== []) {
                                        echo e(implode(' · ', $parts));
                                    } elseif (isset($pageByDate[$day['visit_date']])) {
                                        echo 'from ' . n((int) $pageByDate[$day['visit_date']]['entries']) . ' logbook line(s)';
                                    } else {
                                        echo '&mdash;';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>

                    <tfoot>
                        <tr>
                            <th class="text-end">Total</th>
                            <th class="text-end num"><?= n($totals['local_count']) ?></th>
                            <th class="text-end num"><?= n($totals['domestic_count']) ?></th>
                            <th class="text-end num"><?= n($totals['foreign_count']) ?></th>
                            <th class="text-end num"><?= n($totals['ofw_count']) ?></th>
                            <th class="text-end num"><?= n($totals['total_visitors']) ?></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>

        <?php endif; ?>
    </div>
</section>

<!-- ===================== THE PAPER PAGE ITSELF ===================== -->
<?php if ($documents !== []): ?>
<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-paperclip"></i> Supporting documents</h2>
        <span class="text-muted small"><?= n(count($documents)) ?> file(s)</span>
    </header>

    <div class="panel__body">
        <p class="text-muted small">
            Photographs or scans of the paper logbook, attached by the destination manager. Open one to
            check the typed records against the original page &mdash; this is what replaces the manager
            carrying the book to the office.
        </p>

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
                                &middot; page for <?= e(format_date((string) $doc['covers_date'], 'M j, Y')) ?>
                            <?php endif; ?>
                            <?php if ($doc['uploaded_by_name']): ?>
                                &middot; <?= e((string) $doc['uploaded_by_name']) ?>
                            <?php endif; ?>
                            &middot; <?= e(format_date((string) $doc['created_at'], 'M j, Y g:i A')) ?>
                        </span>
                        <?php if ($doc['caption']): ?>
                            <span class="cell-sub"><?= e((string) $doc['caption']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="doc-card__actions">
                        <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
                           href="<?= e(base_url('/api/reports/document.php?id=' . (int) $doc['id'] . '&report=' . $id)) ?>">
                            <i class="fa-solid fa-eye"></i> View
                        </a>
                        <a class="btn btn-sm btn-outline-secondary"
                           href="<?= e(base_url('/api/reports/document.php?id=' . (int) $doc['id'] . '&report=' . $id . '&download=1')) ?>">
                            <i class="fa-solid fa-download"></i>
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <p class="text-muted small mt-3 mb-0">
            <i class="fa-solid fa-user-shield"></i>
            These pages carry names, addresses and contact numbers of private individuals. They are served
            only to signed-in staff and to the manager who submitted them &mdash; the link will not work for
            anyone else.
        </p>
    </div>
</section>
<?php endif; ?>

<!-- ===================== THE TRANSCRIBED PAGES ===================== -->
<?php if ($entryCount > 0): ?>
<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-book-open"></i> Logbook transcription</h2>
        <a href="review.php?id=<?= $id ?><?= $showEntries ? '' : '&amp;entries=1' ?>"
           class="btn btn-sm btn-outline-secondary">
            <?= $showEntries ? 'Hide the lines' : 'Show all ' . n($entryCount) . ' lines' ?>
        </a>
    </header>

    <div class="panel__body">
        <p class="text-muted small">
            <?= n($entryCount) ?> line(s) copied from the paper logbook. The local / domestic / foreign
            split above was worked out from the Address column, not tallied by hand.
        </p>

        <?php if ($unsureCount > 0): ?>
            <div class="alert alert-warning">
                <i class="fa-solid fa-circle-question"></i>
                <strong><?= n($unsureCount) ?> line(s) carry an address the system did not recognise.</strong>
                Those were classified by guess and the manager did not settle them. Worth checking before
                these figures become the municipality's.
            </div>
        <?php endif; ?>

        <?php if (!$showEntries): ?>

            <div class="table-responsive">
                <!-- Named for the monthly Tourism Attraction Visitor Record, not
                     for how the system stores it. This is the officer deciding
                     whether to publish these figures onto that sheet, so the
                     columns are the sheet's. -->
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Page</th>
                            <th class="text-end">Lines</th>
                            <th class="text-end">This province</th>
                            <th class="text-end">Other Province</th>
                            <th class="text-end">Foreign</th>
                            <th class="text-end">To check</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $unplacedTotal = 0; foreach ($pages as $page): $unplacedTotal += (int) $page['unplaced']; ?>
                            <tr>
                                <td><?= e(format_date($page['visit_date'], 'M j, Y')) ?></td>
                                <td class="text-end num"><?= n((int) $page['entries']) ?></td>
                                <td class="text-end num"><?= n((int) $page['this_province']) ?></td>
                                <td class="text-end num">
                                    <?= n((int) $page['other_province']) ?>
                                    <?php if ((int) $page['unplaced'] > 0): ?>
                                        <span class="cell-sub text-danger">+<?= n((int) $page['unplaced']) ?> unplaced</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end num"><?= n((int) $page['foreign_total']) ?></td>
                                <td class="text-end num">
                                    <?= (int) $page['unsure'] > 0
                                        ? '<span class="pill pill--flag">' . n((int) $page['unsure']) . '</span>'
                                        : '&mdash;' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($unplacedTotal > 0): ?>
                    <p class="text-muted small mt-2 mb-0">
                        <i class="fa-solid fa-map-location-dot text-danger"></i>
                        <strong><?= n($unplacedTotal) ?> visitor(s) have no recognised place of residence.</strong>
                        They will be counted in the Grand Total of the monthly form and in none of its three
                        residence columns. Send the report back if the addresses can be corrected.
                    </p>
                <?php endif; ?>
            </div>

        <?php else: ?>

            <!-- Personal data: names, addresses and contact numbers of private
                 individuals. Behind a click rather than on by default, so it is
                 opened deliberately and not left on a screen in an open office. -->
            <p class="text-muted small">
                <i class="fa-solid fa-user-shield"></i>
                Personal information collected under RA 10173. Use it to verify these figures against the
                paper page, and do not copy it elsewhere.
            </p>

            <?php foreach ($pages as $page): ?>
                <h3 class="h6 mt-4"><?= e(format_date($page['visit_date'], 'l, F j, Y')) ?>
                    <span class="text-muted small">— <?= n((int) $page['entries']) ?> line(s)</span></h3>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width:3rem">#</th>
                                <th>Name</th>
                                <th>Address (as written)</th>
                                <th>Contact no.</th>
                                <th>Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (Entries::forDate($id, (string) $page['visit_date']) as $line): ?>
                                <tr>
                                    <td class="text-muted num"><?= n((int) $line['row_no']) ?></td>
                                    <td><?= e((string) $line['full_name']) ?></td>
                                    <td>
                                        <?= $line['address_text'] !== null ? e((string) $line['address_text']) : '<span class="text-muted">&mdash;</span>' ?>
                                        <?php if ($line['origin_city'] && $line['origin_city'] !== $line['address_text']): ?>
                                            <span class="cell-sub"><?= e((string) $line['origin_city']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $line['contact_number'] !== null ? e((string) $line['contact_number']) : '<span class="text-muted">&mdash;</span>' ?></td>
                                    <td>
                                        <?php
                                        $labels = [
                                            'local'             => 'Local',
                                            'domestic'          => 'Domestic',
                                            'foreign'           => 'Foreign',
                                            'overseas_filipino' => 'OFW',
                                        ];
                                        ?>
                                        <?= e($labels[$line['tourist_type']] ?? $line['tourist_type']) ?>
                                        <?php if ($line['confidence'] === 'low'): ?>
                                            <span class="cell-sub text-danger">guessed</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>

        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- ===================== THE DECISION ===================== -->
<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-gavel"></i> Decision</h2>
    </header>

    <div class="panel__body">

        <?php if (!Auth::isOfficer()): ?>
            <div class="alert alert-info">
                <i class="fa-solid fa-circle-info"></i>
                You can review these figures, but approving a report or sending it back is the
                Tourism Officer's decision.
            </div>
        <?php endif; ?>

        <?php if ($report['status'] === 'submitted'): ?>
            <form method="post" class="mb-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reviewing">
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                    <i class="fa-solid fa-eye"></i> Mark as under review
                </button>
                <span class="text-muted small ms-2">Lets the manager see that someone has picked it up.</span>
            </form>
        <?php endif; ?>

        <div class="row g-4" <?= Auth::isOfficer() ? '' : 'hidden' ?>>
            <div class="col-lg-5">
                <?php
                /* The PHP tags in this attribute were HTML-escaped, so the
                   officer approving a report — the act that writes figures into
                   the municipality's official tourism records — was shown the
                   source of the count instead of the count. Built above the tag
                   and echoed in as one string. */
                $approveAsk = sprintf(
                    "Approve this report?\n\n%s visitors across %s day(s) will be written "
                    . "into the municipality's tourism records.",
                    n($totals['total_visitors']),
                    n(count($days))
                );
                ?>
                <form method="post" data-confirm="<?= e($approveAsk) ?>" data-confirm-tone="normal">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="approve">

                    <button type="submit" class="btn btn-brand btn-sm" <?= $days === [] ? 'disabled' : '' ?>>
                        <i class="fa-solid fa-circle-check"></i>
                        <?= $pending ? 'Approve Report' : 'Approve' ?>
                    </button>

                    <p class="text-muted small mt-2 mb-0">
                        <?php if ($pending): ?>
                            Writes these <?= n(count($days)) ?> day(s) into the daily summary. Re-approving a
                            corrected report replaces those dates rather than adding to them, so a figure
                            cannot be double-counted.
                        <?php else: ?>
                            This report is <?= e(mb_strtolower(Reports::STATUSES[$report['status']])) ?>.
                            Approval only applies to a report that is submitted or under review.
                        <?php endif; ?>
                    </p>
                </form>
            </div>

            <div class="col-lg-7">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reject">

                    <label for="rejection_reason" class="form-label">
                        Reason for sending it back
                    </label>

                    <textarea id="rejection_reason" name="rejection_reason" class="form-control" rows="3"
                              maxlength="500" minlength="10" required
                              placeholder="e.g. Aug 14 shows 320 visitors — please check against the logbook, it looks like a typing slip."></textarea>

                    <button type="submit" class="btn btn-sm btn-outline-danger mt-2">
                        <i class="fa-solid fa-rotate-left"></i> Send Back for Correction
                    </button>

                    <p class="text-muted small mt-2 mb-0">
                        The manager sees this text on their own screen and can correct the figures and
                        resubmit without coming to the office.
                    </p>
                </form>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
