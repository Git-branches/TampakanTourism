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

if ($report === null) {
    Session::flash('danger', 'That report could not be found.');
    redirect(base_url('/admin/inspections/index.php'));
}

/* A draft belongs to the manager still photographing it. Not in the queue, and
   not reachable by typing its id either. */
if ($report['status'] === 'draft') {
    Session::flash('warning', 'That report is still a draft. It has not been submitted yet.');
    redirect(base_url('/admin/inspections/index.php'));
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
            $outstanding = Inspections::readiness($id)['outstanding'];

            Session::flash('danger', 'Not every standard is settled yet: ' . implode('; ', $outstanding)
                . '. Decide each one before granting compliance.');

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

require __DIR__ . '/../_partials/head.php';

$itemTone = static fn (string $s): string => match ($s) {
    'approved'       => 'ok',
    'rejected'       => 'flag',
    'needs_revision' => 'qr',
    'submitted'      => 'qr',
    default          => 'void',
};
?>

<p class="mb-3">
    <a href="index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa-solid fa-arrow-left"></i> Back to the queue
    </a>
</p>

<!-- ===================== SUMMARY ===================== -->
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
        <span class="pill pill--<?= $tone ?>"><?= e(Inspections::STATUSES[$report['status']]) ?></span>
    </header>

    <div class="panel__body">
        <dl class="detail-grid">
            <div><dt>Destination</dt><dd><?= e((string) $report['destination_name']) ?></dd></div>
            <div><dt>Submitted by</dt><dd><?= e((string) ($report['submitted_by_name'] ?: '—')) ?></dd></div>
            <div><dt>Submitted</dt>
                 <dd><?= $report['submitted_at'] ? e(format_date((string) $report['submitted_at'], 'M j, Y g:i A')) : '—' ?></dd></div>
            <div><dt>Standards approved</dt>
                 <dd><strong><?= n($readiness['approved']) ?></strong> of <?= n(count($items)) ?></dd></div>
            <div><dt>Photos</dt><dd><?= n($photoTotal) ?></dd></div>
            <div><dt>Valid until</dt>
                 <dd><?= $report['valid_until'] ? e(format_date((string) $report['valid_until'], 'M j, Y')) : '—' ?></dd></div>
        </dl>

        <?php if ($readiness['outstanding'] !== []): ?>
            <div class="alert alert-info mt-3 mb-0">
                <i class="fa-solid fa-list-check"></i>
                <strong>Still to settle:</strong> <?= e(implode('; ', $readiness['outstanding'])) ?>
            </div>
        <?php endif; ?>

        <?php if ($report['status'] === 'submitted'): ?>
            <form method="post" class="mt-3">
                <?= csrf_field() ?>
                <button type="submit" name="action" value="reviewing" class="btn btn-sm btn-outline-secondary">
                    <i class="fa-solid fa-eye"></i> Mark as under review
                </button>
                <span class="text-muted small ms-2">Lets the manager see someone has picked it up.</span>
            </form>
        <?php endif; ?>
    </div>
</section>

<!-- ===================== EACH STANDARD ===================== -->
<?php foreach ($items as $item): ?>
    <section class="panel" id="item<?= (int) $item['id'] ?>">
        <header class="panel__head">
            <h2>
                <i class="fa-solid fa-shield-halved"></i> <?= e((string) $item['title']) ?>
                <?php if ((int) $item['is_required'] !== 1): ?>
                    <span class="text-muted small">(optional)</span>
                <?php endif; ?>
            </h2>
            <span class="pill pill--<?= $itemTone((string) $item['status']) ?>">
                <?= e(Inspections::ITEM_STATUSES[$item['status']]) ?>
            </span>
        </header>

        <div class="panel__body">
            <?php if ($item['guidance']): ?>
                <p class="text-muted small"><?= e((string) $item['guidance']) ?></p>
            <?php endif; ?>

            <?php if ($item['remarks']): ?>
                <p class="small"><strong>Manager's remarks:</strong> <?= e((string) $item['remarks']) ?></p>
            <?php endif; ?>

            <?php if ($item['photos'] === []): ?>
                <div class="alert alert-warning py-2">
                    <i class="fa-solid fa-camera"></i> No photo was sent for this standard.
                </div>
            <?php else: ?>
                <div class="evidence-grid mb-3">
                    <?php foreach ($item['photos'] as $photo): ?>
                        <figure class="evidence">
                            <a href="<?= e(base_url('/api/inspections/photo.php?id=' . (int) $photo['id'] . '&report=' . $id)) ?>"
                               target="_blank" rel="noopener" title="Open full size">
                                <img src="<?= e(base_url('/api/inspections/photo.php?id=' . (int) $photo['id'] . '&report=' . $id)) ?>"
                                     alt="<?= e((string) ($photo['caption'] ?: $item['title'])) ?>" loading="lazy">
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
                <p class="small"><strong>Your comment:</strong> <?= e((string) $item['office_comment']) ?>
                    <?php if ($item['reviewed_by_name']): ?>
                        <span class="cell-sub">&mdash; <?= e((string) $item['reviewed_by_name']) ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if ($pending): ?>
                <form method="post" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="decide">
                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">

                    <div class="col-12 col-md-3">
                        <label class="form-label" for="st<?= (int) $item['id'] ?>">Decision</label>
                        <select id="st<?= (int) $item['id'] ?>" name="item_status" class="form-select form-select-sm">
                            <option value="approved"       <?= $item['status'] === 'approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="needs_revision" <?= $item['status'] === 'needs_revision' ? 'selected' : '' ?>>Needs clearer evidence</option>
                            <option value="rejected"       <?= $item['status'] === 'rejected' ? 'selected' : '' ?>>Not met</option>
                        </select>
                    </div>

                    <div class="col-12 col-md-7">
                        <label class="form-label" for="cm<?= (int) $item['id'] ?>">
                            Comment
                            <span class="text-muted small">(required unless approving)</span>
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
        </div>
    </section>
<?php endforeach; ?>

<!-- ===================== SITE VISIT ===================== -->
<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-user-check"></i> Face-to-face inspection</h2>
    </header>

    <div class="panel__body">
        <?php if ((int) $report['site_visit_required'] === 1): ?>
            <div class="alert alert-warning">
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
    </div>
</section>

<!-- ===================== THE DECISION ===================== -->
<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-gavel"></i> Compliance decision</h2>
    </header>

    <div class="panel__body">
        <?php if (!Auth::isOfficer()): ?>
            <div class="alert alert-info">
                <i class="fa-solid fa-circle-info"></i>
                You can review the standards and request a site visit, but granting or withdrawing
                compliance is the Tourism Officer's decision.
            </div>
        <?php else: ?>
            <form method="post">
                <?= csrf_field() ?>

                <label class="form-label" for="remarks">Remarks to the establishment</label>
                <textarea id="remarks" name="office_remarks" class="form-control" rows="3" maxlength="1000"
                          placeholder="e.g. All standards verified from the photos. Please keep the extinguisher tag visible for the next check."><?= e((string) ($report['office_remarks'] ?? '')) ?></textarea>

                <div class="mt-3 d-flex gap-2 flex-wrap">
                    <button type="submit" name="action" value="approve" class="btn btn-brand btn-sm"
                            <?= $readiness['ready'] ? '' : 'disabled' ?> data-confirm="Record <?= e((string) $report['destination_name']) ?> as compliant?" data-confirm-tone="normal">
                        <i class="fa-solid fa-circle-check"></i> Grant Compliance
                    </button>

                    <button type="submit" name="action" value="reject" class="btn btn-sm btn-outline-danger">
                        <i class="fa-solid fa-rotate-left"></i> Send Back for Correction
                    </button>
                </div>

                <p class="text-muted small mt-2 mb-0">
                    <?php if ($readiness['ready']): ?>
                        Every standard is settled. Approval is valid for 12 months &mdash; compliance has a
                        shelf life, and an approval from two years ago is not evidence that the
                        extinguisher is still charged.
                    <?php else: ?>
                        Approval stays disabled until every standard is decided. A destination cannot be
                        recorded as compliant while one of its standards sits unresolved &mdash; that is a
                        certificate that contradicts its own evidence.
                    <?php endif; ?>
                </p>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
