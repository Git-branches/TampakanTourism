<?php
declare(strict_types=1);

/**
 * TourSync — reviewing one establishment's compliance evidence.
 *
 * Each standard is decided on its own, because approving four of five is the
 * normal outcome. A single verdict on the whole report cannot say "the
 * extinguisher is fine, the signage photo is too dark to read" — and that
 * sentence is the entire point of reviewing from photographs.
 *
 * THREE ANSWERS, AND THEY MEAN DIFFERENT THINGS
 *
 *   Approved        the standard is met
 *   Not met         the standard is not met — something has to change on site
 *   Needs clearer   the office cannot tell from what was sent — re-photograph
 *
 * The third is not a polite rejection. A manager acts differently on each: fix
 * the site, or point the camera better. Collapsing them would send someone to
 * buy a fire extinguisher they already own.
 *
 * And when a photograph genuinely cannot settle it — a smell, a structural
 * doubt, a gauge that is present but unreadable — the office schedules a visit.
 * This feature removes the trips that were never needed, not inspection itself.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;
use App\Repositories\InspectionRepository as Inspections;
use App\Repositories\ManagerNotificationRepository as Bell;

Auth::require();

$id     = (int) ($_GET['id'] ?? 0);
$report = $id > 0 ? Inspections::find($id) : null;

/* Opened inside the queue's dialog rather than as a page of its own. The
   difference is only what gets drawn: every guard, every POST handler and every
   query below is the same code either way. */
$modal = is_modal_request();

/* A REDIRECT IS THE WRONG ANSWER TO A FETCH.
   The dialog fetches this address and puts the response in its body — so a
   redirect to index.php would pour the whole queue page, sidebar and topbar
   included, inside the dialog. Refused in one line instead. */
$refuse = static function (string $why) use ($modal): never {
    if ($modal) {
        http_response_code(404);
        echo '<p class="text-muted">' . e($why) . '</p>';
        exit;
    }

    Session::flash('danger', $why);
    redirect(base_url('/admin/inspections/index.php'));
};

if ($report === null) {
    $refuse('That report could not be found.');
}

/* A draft belongs to the manager still photographing it. Not in the queue, and
   not reachable by typing its id either. */
if ($report['status'] === 'draft') {
    $refuse('That report is still a draft. It has not been submitted yet.');
}

// -----------------------------------------------------------------------------
// Decisions
// -----------------------------------------------------------------------------

if (is_post()) {
    Csrf::verify();

    $action  = (string) ($_POST['action'] ?? '');
    $adminId = (int) Auth::id();

    /* Granting or withdrawing compliance is the Tourism Officer's call. Staff
       may work through the individual standards and book a site visit — those
       are the reviewing, not the certifying. Checked here and not only hidden
       in the markup, because a hidden button is still a form anyone can post. */
    if (in_array($action, ['approve', 'reject'], true) && !Auth::isOfficer()) {
        Session::flash('danger', 'Only the Tourism Officer can grant or withdraw compliance.');
        redirect(base_url('/admin/inspections/review.php?id=' . $id));
    }

    if ($action === 'reviewing') {
        Inspections::markReviewing($id, $adminId);
        ActivityLog::record('inspection.reviewing', 'inspection_report', $id,
            'Opened for review: ' . $report['destination_name']);

        /* Somebody has it. Worth saying, because the alternative is a
           manager refreshing a page that has not changed for a week. */
        Bell::record((int) $report['destination_id'], 'inspection_reviewing',
            'Your compliance inspection is being reviewed', [
                'body'        => 'The Municipal Tourism Office has opened it. Nothing is needed from you yet.',
                'link'        => base_url('/manager/inspection.php'),
                'entity_type' => 'inspection_report',
                'entity_id'   => $id,
            ]);

        Session::flash('info', 'Marked as under review. The manager can see it has been picked up.');
        redirect(base_url('/admin/inspections/review.php?id=' . $id));
    }

    /* ---- one standard ---- */
    if ($action === 'decide') {
        $itemId  = (int) ($_POST['item_id'] ?? 0);
        $status  = (string) ($_POST['item_status'] ?? '');
        $comment = trim((string) ($_POST['comment'] ?? ''));

        $item = Inspections::findItem($itemId, $id);

        if ($item === null) {
            redirect(base_url('/admin/inspections/review.php?id=' . $id));
        }

        /* Read BEFORE the decision, so the message is sent by whichever
           decision actually moved the report — not by every one after it.
           An officer settling five standards in a row should cost the
           manager one text, not five. */
        $wasOpen = in_array($report["status"], ["submitted", "reviewing", "approved"], true);

        if (!Inspections::decideItem($itemId, $id, $status, $comment, $adminId)) {
            Session::flash('danger', $status === 'approved'
                ? 'That decision could not be recorded.'
                : 'Please write what is wrong or what you need to see. "' . $item['title']
                  . '" sent back without a reason tells the manager only to try again.');

            redirect(base_url('/admin/inspections/review.php?id=' . $id . '#item' . $itemId));
        }

        ActivityLog::record('inspection.item_decided', 'inspection_report', $id,
            $item['title'] . ' -> ' . Inspections::ITEM_STATUSES[$status] . ' (' . $report['destination_name'] . ')');

        /* decideItem() sends the whole report back when a standard is
           refused, so the manager can act on it. Tell them it happened. */
        $sentBack = $status !== 'approved' && $wasOpen;
        $texted   = $sentBack ? Inspections::notifyManager($id, 'rejected') : false;

        if ($sentBack) {
            /* The REASON travels with it. "Needs revision" on its own is
               the phone call this feature exists to remove. */
            Bell::record((int) $report['destination_id'], 'inspection_revision',
                $item['title'] . ' needs a clearer photo', [
                    'body'        => $comment,
                    'link'        => base_url('/manager/inspection.php'),
                    'entity_type' => 'inspection_report',
                    'entity_id'   => $id,
                ]);
        }

        Session::flash('success', $item['title'] . ': ' . Inspections::ITEM_STATUSES[$status] . '.'
            . ($sentBack ? ' The report has gone back to the manager so they can correct it'
                . ($texted ? ', and they have been texted.' : '.') : ''));
        redirect(base_url('/admin/inspections/review.php?id=' . $id . '#item' . $itemId));
    }

    /* ---- a physical visit ---- */
    if ($action === 'site-visit') {
        $when = trim((string) ($_POST['site_visit_at'] ?? ''));
        $note = trim((string) ($_POST['site_visit_note'] ?? ''));

        Inspections::scheduleSiteVisit($id, $adminId, $when !== '' ? $when : null, $note);

        ActivityLog::record('inspection.site_visit', 'inspection_report', $id,
            'Site visit requested for ' . $report['destination_name'] . ($when !== '' ? ' on ' . $when : ''));

        Session::flash('success', 'Site visit recorded. The manager can see it on their side.');
        redirect(base_url('/admin/inspections/review.php?id=' . $id));
    }

    if ($action === 'cancel-site-visit') {
        Inspections::cancelSiteVisit($id);
        Session::flash('info', 'Site visit cancelled.');
        redirect(base_url('/admin/inspections/review.php?id=' . $id));
    }

    /* ---- the whole report ---- */
    if ($action === 'approve') {
        if (!Inspections::approve($id, $adminId, trim((string) ($_POST['office_remarks'] ?? '')))) {
            /* TWO REASONS IT CAN REFUSE, AND THEY NEED DIFFERENT WORDS.
               Either a standard is still unsettled, or this report was already
               approved — a stale tab, a back button, a second press. Saying
               "not every standard is settled" to somebody looking at five green
               ticks is the kind of message that makes people distrust the
               screen. The button is hidden once approved; this is the path a
               page that predates the approval still takes. */
            $outstanding = Inspections::readiness($id)['outstanding'];

            if ($outstanding === []) {
                Session::flash('info', $report['destination_name']
                    . ' is already recorded as compliant. Nothing was changed.');
            } else {
                Session::flash('danger', 'Not every standard is settled yet: ' . implode('; ', $outstanding)
                    . '. Decide each one before approving compliance.');
            }

            redirect(base_url('/admin/inspections/review.php?id=' . $id));
        }

        ActivityLog::record('inspection.approved', 'inspection_report', $id,
            'Compliance granted to ' . $report['destination_name']);

        $texted = Inspections::notifyManager($id, 'approved');

        Bell::record((int) $report['destination_id'], 'inspection_approved',
            'Your compliance inspection was approved', [
                'body'        => trim((string) ($_POST['office_remarks'] ?? '')) ?: 'Every standard met.',
                'link'        => base_url('/manager/inspection.php'),
                'entity_type' => 'inspection_report',
                'entity_id'   => $id,
            ]);

        Session::flash('success', $report['destination_name'] . ' is now recorded as compliant.'
            . ($texted ? ' The manager has been texted.' : ''));
        redirect(base_url('/admin/inspections/index.php'));
    }

    if ($action === 'reject') {
        $remarks = trim((string) ($_POST['office_remarks'] ?? ''));

        if (!Inspections::reject($id, $adminId, $remarks)) {
            Session::flash('danger', 'Please give the manager a reason — they have to know what to correct.');
            redirect(base_url('/admin/inspections/review.php?id=' . $id));
        }

        ActivityLog::record('inspection.rejected', 'inspection_report', $id,
            'Sent back to ' . $report['destination_name'] . ': ' . mb_substr($remarks, 0, 120));

        $texted = Inspections::notifyManager($id, 'rejected');

        Bell::record((int) $report['destination_id'], 'inspection_revision',
            'Your compliance inspection was sent back', [
                'body'        => $remarks,
                'link'        => base_url('/manager/inspection.php'),
                'entity_type' => 'inspection_report',
                'entity_id'   => $id,
            ]);

        Session::flash('success', 'Sent back with your remarks. The manager can correct it and resubmit.'
            . ($texted ? ' They have been texted.' : ''));
        redirect(base_url('/admin/inspections/index.php'));
    }

    redirect(base_url('/admin/inspections/review.php?id=' . $id));
}

// -----------------------------------------------------------------------------
// The page
// -----------------------------------------------------------------------------

$items     = Inspections::items($id);
$readiness = Inspections::readiness($id);
$pending   = in_array($report['status'], ['submitted', 'reviewing'], true);

$photoTotal = 0;
foreach ($items as $item) {
    $photoTotal += count($item['photos']);
}

$pageTitle    = 'Compliance Review';
$pageIcon     = 'fa-clipboard-check';
$pageSubtitle = $report['destination_name'] . ' · '
    . ($report['submitted_at'] ? format_date((string) $report['submitted_at'], 'F j, Y') : 'not submitted');

if (!$modal) {
    require __DIR__ . '/../_partials/head.php';
}

$itemTone = static fn (string $s): string => match ($s) {
    'approved'       => 'ok',
    'rejected'       => 'flag',
    'needs_revision' => 'qr',
    'submitted'      => 'qr',
    default          => 'void',
};

$reportTone = match ($report['status']) {
    'approved'  => 'ok',
    'rejected'  => 'flag',
    'reviewing' => 'qr',
    default     => 'void',
};

/* Which standard opens first: the first one still needing a decision, so the
   reviewer lands on the work rather than on whatever happens to be first. */
$openIndex = 0;

foreach ($items as $i => $it) {
    if (in_array($it['status'], ['submitted', 'pending'], true)) {
        $openIndex = $i;
        break;
    }
}
?>

<?php if (!$modal): ?>
    <p class="mb-3">
        <a href="index.php" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-arrow-left"></i> Back to the queue
        </a>
    </p>
<?php endif; ?>

<div class="rev">

    <!-- ===================== SUMMARY =====================
         Badges, not a panel of rows. Six facts the reviewer needs before
         looking at a single photograph, on one line where there is room. -->
    <div class="rev__summary">
        <span class="pill pill--<?= $reportTone ?> rev__status">
            <?= e(Inspections::STATUSES[$report['status']]) ?>
        </span>

        <span class="rev__fact">
            <i class="fa-solid fa-mountain-sun" aria-hidden="true"></i>
            <?= e((string) $report['destination_name']) ?>
        </span>

        <span class="rev__fact">
            <i class="fa-solid fa-user" aria-hidden="true"></i>
            <?= e((string) ($report['submitted_by_name'] ?: '&mdash;')) ?>
        </span>

        <span class="rev__fact">
            <i class="fa-solid fa-calendar-day" aria-hidden="true"></i>
            <?= $report['submitted_at']
                ? e(format_date((string) $report['submitted_at'], 'M j, Y g:i A'))
                : '&mdash;' ?>
        </span>

        <span class="rev__fact rev__fact--count">
            <i class="fa-solid fa-list-check" aria-hidden="true"></i>
            <strong><?= n($readiness['approved']) ?></strong> of <?= n(count($items)) ?> approved
        </span>

        <span class="rev__fact rev__fact--count">
            <i class="fa-solid fa-camera" aria-hidden="true"></i>
            <strong><?= n($photoTotal) ?></strong> photo<?= $photoTotal === 1 ? '' : 's' ?>
        </span>

        <?php if ($report['valid_until']): ?>
            <span class="rev__fact">
                <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
                Valid until <?= e(format_date((string) $report['valid_until'], 'M j, Y')) ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if ($readiness['outstanding'] !== []): ?>
        <div class="alert alert-info py-2 rev__outstanding">
            <i class="fa-solid fa-list-check"></i>
            <strong>Still to settle:</strong> <?= e(implode('; ', $readiness['outstanding'])) ?>
        </div>
    <?php endif; ?>

    <?php if ($report['status'] === 'submitted'): ?>
        <form method="post" class="rev__pickup">
            <?= csrf_field() ?>
            <button type="submit" name="action" value="reviewing" class="btn btn-sm btn-outline-secondary">
                <i class="fa-solid fa-eye"></i> Mark as under review
            </button>
            <span class="text-muted small">Lets the manager see someone has picked it up.</span>
        </form>
    <?php endif; ?>

    <!-- ===================== ONE STANDARD AT A TIME =====================
         Every standard used to be its own full panel, stacked: guidance,
         photographs, a decision form and a comment box, five times over. The
         page was long enough that deciding the last one meant scrolling past
         four already settled.

         All of them are still in the document — the list on the left is built
         from them and the switch is instant, with no request — but only the
         chosen one is shown. Written against however many standards exist:
         five today, and nothing here counts on that.
         ============================================================== -->
    <div class="rev__work" id="revWork">

        <nav class="rev__nav" aria-label="Standards">
            <?php foreach ($items as $i => $item): ?>
                <button type="button" class="rev__tab<?= $i === $openIndex ? ' is-active' : '' ?>"
                        data-rev-tab="<?= $i ?>"
                        aria-pressed="<?= $i === $openIndex ? 'true' : 'false' ?>">
                    <span class="rev__dot rev__dot--<?= $itemTone((string) $item['status']) ?>"
                          aria-hidden="true"></span>
                    <span class="rev__tabName"><?= e((string) $item['title']) ?></span>
                    <span class="rev__tabState"><?= e(Inspections::ITEM_STATUSES[$item['status']]) ?></span>
                </button>
            <?php endforeach; ?>
        </nav>

        <div class="rev__panes">
            <?php foreach ($items as $i => $item): ?>
                <section class="rev__pane" data-rev-pane="<?= $i ?>"
                         id="item<?= (int) $item['id'] ?>"
                         <?= $i === $openIndex ? '' : 'hidden' ?>>

                    <header class="rev__paneHead">
                        <h3>
                            <?= e((string) $item['title']) ?>
                            <?php if ((int) $item['is_required'] !== 1): ?>
                                <span class="text-muted small">(optional)</span>
                            <?php endif; ?>
                        </h3>
                        <span class="pill pill--<?= $itemTone((string) $item['status']) ?>">
                            <?= e(Inspections::ITEM_STATUSES[$item['status']]) ?>
                        </span>
                    </header>

                    <?php if ($item['guidance']): ?>
                        <p class="text-muted small"><?= e((string) $item['guidance']) ?></p>
                    <?php endif; ?>

                    <?php if ($item['remarks']): ?>
                        <p class="small rev__note">
                            <strong>Manager's remarks:</strong> <?= e((string) $item['remarks']) ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($item['photos'] === []): ?>
                        <div class="alert alert-warning py-2">
                            <i class="fa-solid fa-camera"></i> No photo was sent for this standard.
                        </div>
                    <?php else: ?>
                        <div class="evidence-grid mb-3">
                            <?php foreach ($item['photos'] as $photo): ?>
                                <?php $src = base_url('/api/inspections/photo.php?id=' . (int) $photo['id'] . '&report=' . $id); ?>
                                <figure class="evidence">
                                    <?php /* The viewer, not a new tab. The href stays so
                                             the image is still reachable with no
                                             JavaScript, and target="_blank" is gone
                                             because that is what left the system. */ ?>
                                    <a href="<?= e($src) ?>" data-lightbox
                                       data-caption="<?= e((string) ($photo['caption'] ?: $item['title'])) ?>"
                                       title="Open full size">
                                        <img src="<?= e($src) ?>"
                                             alt="<?= e((string) ($photo['caption'] ?: $item['title'])) ?>"
                                             loading="lazy">
                                    </a>
                                    <figcaption>
                                        <?php if ($photo['caption']): ?>
                                            <span class="evidence__caption"><?= e((string) $photo['caption']) ?></span>
                                        <?php endif; ?>
                                        <span class="cell-sub">
                                            <?= e(format_date((string) $photo['created_at'], 'M j, g:i A')) ?>
                                            &middot; <?= e(Inspections::humanSize((int) $photo['byte_size'])) ?>
                                        </span>
                                    </figcaption>
                                </figure>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($item['office_comment']): ?>
                        <p class="small rev__note">
                            <strong>Your comment:</strong> <?= e((string) $item['office_comment']) ?>
                            <?php if ($item['reviewed_by_name']): ?>
                                <span class="cell-sub">&mdash; <?= e((string) $item['reviewed_by_name']) ?></span>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($pending): ?>
                        <form method="post" class="rev__decide row g-2 align-items-end">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="decide">
                            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">

                            <div class="col-12 col-md-4">
                                <label class="form-label" for="st<?= (int) $item['id'] ?>">Decision</label>
                                <select id="st<?= (int) $item['id'] ?>" name="item_status" class="form-select form-select-sm">
                                    <option value="approved"       <?= $item['status'] === 'approved' ? 'selected' : '' ?>>Approved</option>
                                    <option value="needs_revision" <?= $item['status'] === 'needs_revision' ? 'selected' : '' ?>>Needs clearer evidence</option>
                                    <option value="rejected"       <?= $item['status'] === 'rejected' ? 'selected' : '' ?>>Not met</option>
                                </select>
                            </div>

                            <div class="col-12 col-md-6">
                                <label class="form-label" for="cm<?= (int) $item['id'] ?>">
                                    Comment <span class="text-muted small">(required unless approving)</span>
                                </label>
                                <input type="text" id="cm<?= (int) $item['id'] ?>" name="comment"
                                       class="form-control form-control-sm" maxlength="600"
                                       value="<?= e((string) ($item['office_comment'] ?? '')) ?>"
                                       placeholder="e.g. the pressure gauge is not readable — please re-shoot closer">
                            </div>

                            <div class="col-12 col-md-2">
                                <button type="submit" class="btn btn-brand btn-sm w-100">Record</button>
                            </div>
                        </form>
                    <?php endif; ?>

                    <div class="rev__step">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-rev-prev
                                <?= $i === 0 ? 'disabled' : '' ?>>
                            <i class="fa-solid fa-arrow-left"></i> Previous Standard
                        </button>
                        <span class="rev__of"><?= $i + 1 ?> of <?= count($items) ?></span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-rev-next
                                <?= $i === count($items) - 1 ? 'disabled' : '' ?>>
                            Next Standard <i class="fa-solid fa-arrow-right"></i>
                        </button>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ===================== FACE-TO-FACE =====================
         Folded away by default. It is the exception, not the step: most
         reports are settled from the photographs, and an open form for the
         rare case was taking a screen's worth of room on every one. -->
    <details class="rev__visit" <?= (int) $report['site_visit_required'] === 1 ? 'open' : '' ?>>
        <summary>
            <i class="fa-solid fa-user-check"></i>
            Face-to-face inspection
            <?php if ((int) $report['site_visit_required'] === 1): ?>
                <span class="pill pill--qr">Requested</span>
            <?php endif; ?>
        </summary>

        <?php if ((int) $report['site_visit_required'] === 1): ?>
            <div class="alert alert-warning py-2">
                <strong>Site visit requested.</strong>
                <?= $report['site_visit_at']
                    ? e(format_date((string) $report['site_visit_at'], 'F j, Y \a\t g:i A'))
                    : 'Date not set.' ?>
                <?php if ($report['site_visit_note']): ?>
                    <div class="small mt-1"><?= e((string) $report['site_visit_note']) ?></div>
                <?php endif; ?>
            </div>

            <form method="post">
                <?= csrf_field() ?>
                <button type="submit" name="action" value="cancel-site-visit" class="btn btn-sm btn-outline-secondary">
                    Cancel the site visit
                </button>
            </form>
        <?php else: ?>
            <p class="text-muted small">
                Use this when a photograph cannot settle it &mdash; a structural doubt, a smell, an
                extinguisher that is present but whose tag cannot be read. Recorded here rather than
                agreed on the phone, so the manager sees it is coming and why.
            </p>

            <form method="post" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="site-visit">

                <div class="col-12 col-md-3">
                    <label class="form-label" for="svat">Date and time <span class="text-muted small">(optional)</span></label>
                    <input type="datetime-local" id="svat" name="site_visit_at" class="form-control form-control-sm">
                </div>

                <div class="col-12 col-md-7">
                    <label class="form-label" for="svnote">What needs checking in person</label>
                    <input type="text" id="svnote" name="site_visit_note" class="form-control form-control-sm"
                           maxlength="500" placeholder="e.g. the handrail on the upper viewing deck">
                </div>

                <div class="col-12 col-md-2">
                    <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Request visit</button>
                </div>
            </form>
        <?php endif; ?>
    </details>

    <!-- ===================== THE DECISION ===================== -->
    <?php
    /* Already approved is a finished decision. The button used to be gated on
       readiness alone, so a compliant report still offered an enabled Approve —
       and pressing it changed nothing while telling the manager, by SMS and by
       bell, that it had been approved a second time. */
    $alreadyApproved = $report['status'] === 'approved';
    ?>

    <div class="rev__decision">
        <?php if (!Auth::isOfficer()): ?>
            <p class="text-muted small mb-0">
                <i class="fa-solid fa-circle-info"></i>
                You can review the standards and request a site visit, but approving or withdrawing
                compliance is the Tourism Officer's decision.
            </p>
        <?php else: ?>
            <?php /* data-modal-reload: approving or returning a report changes the
                     row in the queue behind this dialog, so the dialog closes and
                     the page comes back with the new status rather than leaving a
                     stale line on screen. The flash is carried across. */ ?>
            <form method="post" id="revDecide" data-modal-reload>
                <?= csrf_field() ?>

                <label class="form-label" for="remarks">Remarks to the establishment</label>
                <textarea id="remarks" name="office_remarks" class="form-control" rows="2" maxlength="1000"
                          placeholder="e.g. All standards verified from the photos. Please keep the extinguisher tag visible for the next check."><?= e((string) ($report['office_remarks'] ?? '')) ?></textarea>

                <p class="text-muted small mt-2 mb-2">
                    <?php if ($alreadyApproved): ?>
                        <?= e((string) $report['destination_name']) ?> is already recorded as compliant<?=
                            $report['valid_until']
                                ? ', valid until ' . e(format_date((string) $report['valid_until'], 'F j, Y'))
                                : '' ?>.
                        There is nothing further to approve. If something is found to be wrong, withdraw
                        the compliance and it goes back to the manager with your reason.
                    <?php elseif ($readiness['ready']): ?>
                        Every standard is settled. Approval is valid for 12 months &mdash; compliance has a
                        shelf life, and an approval from two years ago is not evidence that the
                        extinguisher is still charged.
                    <?php else: ?>
                        Approval stays disabled until every standard is decided. A destination cannot be
                        recorded as compliant while one of its standards sits unresolved &mdash; that is a
                        certificate that contradicts its own evidence.
                    <?php endif; ?>
                </p>

                <div class="rev__actions">
                    <?php if ($modal): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close>Close</button>
                    <?php endif; ?>

                    <button type="submit" name="action" value="reject"
                            class="btn btn-sm btn-outline-danger" data-rev-return>
                        <i class="fa-solid fa-rotate-left"></i>
                        <?= $alreadyApproved ? 'Withdraw Compliance' : 'Return for Correction' ?>
                    </button>

                    <?php if (!$alreadyApproved): ?>
                        <button type="submit" name="action" value="approve" class="btn btn-brand btn-sm"
                                <?= $readiness['ready'] ? '' : 'disabled' ?>
                                data-confirm="Record <?= e((string) $report['destination_name']) ?> as compliant?"
                                data-confirm-tone="normal">
                            <i class="fa-solid fa-circle-check"></i> Approve Compliance
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php /* THE EVIDENCE VIEWER, CARRIED BY THIS SCREEN RATHER THAN THE SHELL.
         The officer's layout is finished and this is the only page in it that
         shows photographs, so the dialog and its script travel with the review
         instead of being added to every admin page. Injected into #pageModal's
         body when opened there — showModal() puts it in the top layer above the
         dialog it came from, which is the only arrangement that works. */ ?>
<dialog class="rev-lightbox" id="revLightbox" aria-label="Evidence photo">
    <button type="button" class="rev-lightbox__close" data-revlb-close aria-label="Close">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
    </button>
    <figure class="rev-lightbox__frame">
        <img id="revLightboxImg" src="" alt="">
        <figcaption id="revLightboxCap"></figcaption>
    </figure>
</dialog>

<script>
(function () {
    var work = document.getElementById('revWork');

    if (!work) { return; }

    /* ---------------- one standard at a time ---------------- */

    var tabs  = Array.prototype.slice.call(work.querySelectorAll('[data-rev-tab]'));
    var panes = Array.prototype.slice.call(work.querySelectorAll('[data-rev-pane]'));

    function show(index) {
        if (index < 0 || index >= panes.length) { return; }

        panes.forEach(function (p) {
            p.hidden = p.getAttribute('data-rev-pane') !== String(index);
        });

        tabs.forEach(function (t) {
            var on = t.getAttribute('data-rev-tab') === String(index);
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-pressed', on ? 'true' : 'false');

            /* On a phone the list is a horizontal strip, so the chosen one has
               to be brought into view or it stays off the side. */
            if (on && t.scrollIntoView) {
                t.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
        });
    }

    function current() {
        var open = panes.filter(function (p) { return !p.hidden; })[0];
        return open ? parseInt(open.getAttribute('data-rev-pane'), 10) : 0;
    }

    work.addEventListener('click', function (event) {
        var tab = event.target.closest('[data-rev-tab]');

        if (tab) { show(parseInt(tab.getAttribute('data-rev-tab'), 10)); return; }

        if (event.target.closest('[data-rev-prev]')) { show(current() - 1); return; }
        if (event.target.closest('[data-rev-next]')) { show(current() + 1); }
    });

    /* ---------------- returning it needs a reason ---------------- */

    var decide = document.getElementById('revDecide');
    var remarks = document.getElementById('remarks');

    if (decide && remarks) {
        decide.addEventListener('click', function (event) {
            var back = event.target.closest('[data-rev-return]');

            if (!back || back.dataset.asked === 'yes') { return; }
            if (remarks.value.trim() !== '') { return; }

            /* The server refuses a return with no reason, and rightly — but
               finding that out after the dialog has closed and reloaded is a
               long way round. Asked here, in the house dialog, before anything
               is sent. */
            event.preventDefault();

            var ask = window.TourSync && window.TourSync.askFor;

            if (typeof ask !== 'function') { remarks.focus(); return; }

            ask({
                title: 'Why is it going back?',
                text: 'The manager sees this, and it is the only thing telling them what to fix.',
                input: 'textarea',
                placeholder: 'e.g. the extinguisher gauge is not readable in either photo',
                confirmText: 'Return for Correction',
                validate: function (value) {
                    return (value || '').trim() === '' ? 'Please write a reason.' : null;
                },
                onConfirm: function (value) {
                    remarks.value = value;
                    back.dataset.asked = 'yes';
                    back.click();
                }
            });
        });
    }

    /* ---------------- the evidence viewer ---------------- */

    var box = document.getElementById('revLightbox');

    if (!box || typeof box.showModal !== 'function') { return; }

    var img = document.getElementById('revLightboxImg');
    var cap = document.getElementById('revLightboxCap');

    document.addEventListener('click', function (event) {
        var link = event.target.closest && event.target.closest('a[data-lightbox]');

        if (!link || !document.body.contains(box)) { return; }

        /* A middle or modified click means "open it in a tab" — let it. */
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) { return; }

        event.preventDefault();

        img.src = link.getAttribute('href');
        img.alt = link.getAttribute('data-caption') || 'Evidence photo';
        cap.textContent = link.getAttribute('data-caption') || '';
        cap.hidden = cap.textContent === '';

        box.showModal();
    });

    box.addEventListener('click', function (event) {
        if (event.target === box || (event.target.closest && event.target.closest('[data-revlb-close]'))) {
            box.close();
        }
    });

    /* Let go of the photograph on close, so a 4MB image is not held behind a
       dialog nobody can see. */
    box.addEventListener('close', function () { img.src = ''; });
})();
</script>

<?php
if (!$modal) {
    require __DIR__ . '/../_partials/foot.php';
}
