<?php
declare(strict_types=1);

/**
 * TourSync — the manager's compliance inspection report.
 *
 * The screen that replaces an officer's trip. Each tourism standard is a card:
 * what the office wants to see, what to photograph, the photos already sent,
 * and — once the office has looked — their answer on that one requirement.
 *
 * WHY EACH REQUIREMENT CARRIES ITS OWN STATUS
 *
 * Approving four of five standards is the normal outcome, not the exception. A
 * single status on the whole report cannot say "the extinguisher is fine, come
 * back with a clearer photo of the signage", and that sentence is the entire
 * value of doing this remotely.
 *
 * Photos upload one at a time, per requirement, and each upload is its own POST
 * that redirects. A manager doing this on one bar of signal at a waterfall
 * loses one photograph when the connection drops, not the morning's work.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Csrf;
use App\Core\DocumentUploader;
use App\Core\ManagerAuth;
use App\Core\Session;
use App\Repositories\InspectionRepository as Inspections;

ManagerAuth::require();

$destinationId = (int) ManagerAuth::destinationId();

/* A manager opens this page because they have a photograph to add, not to
   "start an inspection". One open report per destination, created on demand. */
$report   = Inspections::openFor($destinationId);
$reportId = (int) $report['id'];
$editable = Inspections::isEditable($report);

// -----------------------------------------------------------------------------
// Actions
// -----------------------------------------------------------------------------

if (is_post()) {
    Csrf::verify();

    $action = (string) ($_POST['action'] ?? '');

    if (!$editable) {
        Session::flash('danger', 'This report has been submitted and can no longer be changed.');
        redirect(base_url('/manager/inspection.php'));
    }

    /* ---- a photograph for one requirement ---- */
    if ($action === 'upload') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $item   = Inspections::findItem($itemId, $reportId);

        if ($item === null) {
            Session::flash('danger', 'That requirement could not be found.');
            redirect(base_url('/manager/inspection.php'));
        }

        $uploader = new DocumentUploader();
        $stored   = $uploader->store($_FILES['photo'] ?? [], 'inspections');

        if ($stored === null) {
            Session::flash('danger', $uploader->firstError() ?? 'That photo could not be uploaded.');
            redirect(base_url('/manager/inspection.php#item' . $itemId));
        }

        /* PDF passes DocumentUploader — it is allowed for logbook pages — but a
           compliance standard is a thing you photograph. Rejected here rather
           than by widening the shared uploader's rules. */
        if ($stored['mime_type'] === 'application/pdf') {
            DocumentUploader::delete($stored['stored_name'], 'inspections');
            Session::flash('danger', 'Please upload a photo (JPG or PNG), not a PDF — the office needs to see the item itself.');
            redirect(base_url('/manager/inspection.php#item' . $itemId));
        }

        Inspections::addPhoto($itemId, $stored, (int) ManagerAuth::id(), trim((string) ($_POST['caption'] ?? '')));

        ActivityLog::record(
            'inspection.photo_uploaded', 'inspection_report', $reportId,
            'Photo for "' . $item['title'] . '" at ' . ManagerAuth::destinationName()
        );

        Session::flash('success', 'Photo added to ' . $item['title'] . '.');
        redirect(base_url('/manager/inspection.php#item' . $itemId));
    }

    /* ---- replacing evidence: remove one photo ---- */
    if ($action === 'remove-photo') {
        $photoId = (int) ($_POST['photo_id'] ?? 0);
        $itemId  = (int) ($_POST['item_id'] ?? 0);

        if (Inspections::removePhoto($photoId, $reportId)) {
            Session::flash('success', 'Photo removed.');
        }

        redirect(base_url('/manager/inspection.php#item' . $itemId));
    }

    /* ---- the manager's own note about a requirement ---- */
    if ($action === 'remarks') {
        $itemId = (int) ($_POST['item_id'] ?? 0);

        if (Inspections::findItem($itemId, $reportId) !== null) {
            Inspections::saveItemRemarks($itemId, $reportId, trim((string) ($_POST['remarks'] ?? '')));
            Session::flash('success', 'Remarks saved.');
        }

        redirect(base_url('/manager/inspection.php#item' . $itemId));
    }

    /* ---- hand the whole thing to the office ---- */
    if ($action === 'submit') {
        $missing = Inspections::missingRequired($reportId);

        if ($missing !== []) {
            /* Named, not counted. "Incomplete" makes a manager hunt; a list of
               titles lets them pick up the phone and walk to each one. */
            Session::flash('danger', 'Still needed before this can be submitted: ' . implode(', ', $missing) . '.');
            redirect(base_url('/manager/inspection.php'));
        }

        if (Inspections::submit($reportId, (int) ManagerAuth::id())) {
            ActivityLog::record(
                'inspection.submitted', 'inspection_report', $reportId,
                'Compliance report submitted for ' . ManagerAuth::destinationName()
            );

            Session::flash('success', 'Inspection report submitted. The Municipal Tourism Office will review the photos — you do not need to travel there.');
        }

        redirect(base_url('/manager/inspections.php'));
    }

    redirect(base_url('/manager/inspection.php'));
}

// -----------------------------------------------------------------------------
// The page
// -----------------------------------------------------------------------------

$items     = Inspections::items($reportId);
$missing   = Inspections::missingRequired($reportId);
$readiness = Inspections::readiness($reportId);

$photoTotal = 0;
foreach ($items as $item) {
    $photoTotal += count($item['photos']);
}

$pageTitle    = 'Compliance Inspection';
$pageIcon     = 'fa-clipboard-check';
$pageSubtitle = ManagerAuth::destinationName();

require __DIR__ . '/_partials/head.php';

$itemTone = static fn (string $s): string => match ($s) {
    'approved'       => 'ok',
    'rejected'       => 'flag',
    'needs_revision' => 'qr',
    'submitted'      => 'qr',
    default          => 'void',
};
?>

<?php if ($report['status'] === 'rejected'): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-rotate-left"></i>
        <strong>Sent back by the Municipal Tourism Office.</strong>
        <?php if ($report['office_remarks']): ?>
            <div class="small mt-1"><?= e((string) $report['office_remarks']) ?></div>
        <?php endif; ?>
        <div class="small mt-1">Look for the requirements marked below, fix or re-photograph them, and submit again.</div>
    </div>
<?php elseif ($report['status'] === 'approved'): ?>
    <div class="alert alert-success">
        <i class="fa-solid fa-circle-check"></i>
        <strong>Compliant.</strong>
        <?= $report['valid_until'] ? 'Valid until ' . e(format_date((string) $report['valid_until'], 'F j, Y')) . '.' : '' ?>
        <?php if ($report['office_remarks']): ?>
            <div class="small mt-1"><?= e((string) $report['office_remarks']) ?></div>
        <?php endif; ?>
    </div>
<?php elseif (!$editable): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-lock"></i>
        This report is <strong><?= e(Inspections::STATUSES[$report['status']]) ?></strong> and is read-only while the Office looks at it.
    </div>
<?php endif; ?>

<?php if ((int) $report['site_visit_required'] === 1): ?>
    <!-- The honest middle. Said plainly, because the manager needs to be there. -->
    <div class="alert alert-warning">
        <i class="fa-solid fa-user-check"></i>
        <strong>The Office has asked for a site visit.</strong>
        <?php if ($report['site_visit_at']): ?>
            Scheduled for <strong><?= e(format_date((string) $report['site_visit_at'], 'F j, Y \a\t g:i A')) ?></strong>.
        <?php else: ?>
            They will contact you to arrange a date.
        <?php endif; ?>
        <?php if ($report['site_visit_note']): ?>
            <div class="small mt-1"><?= e((string) $report['site_visit_note']) ?></div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- ===================== PROGRESS ===================== -->
<div class="stat-grid">
    <?php
    $cards = [
        ['icon' => 'fa-list-check',   'tone' => 'blue',  'value' => count($items),                'label' => 'Standards'],
        ['icon' => 'fa-circle-check', 'tone' => 'green', 'value' => $readiness['approved'],       'label' => 'Approved'],
        ['icon' => 'fa-camera',       'tone' => 'teal',  'value' => $photoTotal,                  'label' => 'Photos sent'],
        ['icon' => 'fa-triangle-exclamation', 'tone' => 'amber', 'value' => count($missing),      'label' => 'Still to photograph'],
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

<?php if ($editable && $missing !== []): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-camera"></i>
        <strong>Still needed:</strong> <?= e(implode(', ', $missing)) ?>.
        Photograph each one and it can go to the Office &mdash; no trip required.
    </div>
<?php endif; ?>

<!-- ===================== THE STANDARDS ===================== -->
<?php foreach ($items as $item): ?>
    <section class="panel" id="item<?= (int) $item['id'] ?>">
        <header class="panel__head">
            <h2>
                <i class="fa-solid fa-shield-halved"></i>
                <?= e((string) $item['title']) ?>
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

            <?php if ($item['office_comment']): ?>
                <div class="alert alert-<?= $item['status'] === 'approved' ? 'success' : 'warning' ?> py-2">
                    <strong>Office:</strong> <?= e((string) $item['office_comment']) ?>
                    <?php if ($item['reviewed_by_name']): ?>
                        <span class="cell-sub">&mdash; <?= e((string) $item['reviewed_by_name']) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($item['photos'] === []): ?>
                <p class="text-muted small mb-3"><em>No photo sent for this standard yet.</em></p>
            <?php else: ?>
                <div class="evidence-grid mb-3">
                    <?php foreach ($item['photos'] as $photo): ?>
                        <figure class="evidence">
                            <a href="<?= e(base_url('/api/inspections/photo.php?id=' . (int) $photo['id'] . '&report=' . $reportId)) ?>"
                               target="_blank" rel="noopener">
                                <img src="<?= e(base_url('/api/inspections/photo.php?id=' . (int) $photo['id'] . '&report=' . $reportId)) ?>"
                                     alt="<?= e((string) ($photo['caption'] ?: $item['title'])) ?>" loading="lazy">
                            </a>
                            <figcaption>
                                <?php if ($photo['caption']): ?>
                                    <span class="evidence__caption"><?= e((string) $photo['caption']) ?></span>
                                <?php endif; ?>
                                <span class="cell-sub"><?= e(Inspections::humanSize((int) $photo['byte_size'])) ?></span>

                                <?php if ($editable): ?>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="remove-photo">
                                        <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
                                        <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Remove this photo?"
                                                aria-label="Remove this photo">
                                            <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($editable): ?>
                <!-- One upload, one POST. A dropped connection costs this photo
                     and nothing else. -->
                <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload">
                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">

                    <div class="col-12 col-md-5">
                        <label class="form-label" for="photo<?= (int) $item['id'] ?>">Add a photo</label>
                        <input type="file" id="photo<?= (int) $item['id'] ?>" name="photo" required
                               class="form-control form-control-sm"
                               accept="image/jpeg,image/png,.jpg,.jpeg,.png" capture="environment"
                               data-max-mb="<?= n(upload_limit_mb()) ?>">
                    </div>

                    <div class="col-12 col-md-5">
                        <label class="form-label" for="caption<?= (int) $item['id'] ?>">
                            What it shows <span class="text-muted small">(optional)</span>
                        </label>
                        <input type="text" id="caption<?= (int) $item['id'] ?>" name="caption"
                               class="form-control form-control-sm" maxlength="300"
                               placeholder="e.g. by the entrance, tag dated Jan 2026">
                    </div>

                    <div class="col-12 col-md-2">
                        <button type="submit" class="btn btn-brand btn-sm w-100">
                            <i class="fa-solid fa-camera"></i> Upload
                        </button>
                    </div>
                </form>

                <form method="post" class="mt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="remarks">
                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">

                    <label class="form-label" for="remarks<?= (int) $item['id'] ?>">
                        Your remarks <span class="text-muted small">(optional)</span>
                    </label>
                    <div class="d-flex gap-2">
                        <input type="text" id="remarks<?= (int) $item['id'] ?>" name="remarks"
                               class="form-control form-control-sm" maxlength="600"
                               value="<?= e((string) ($item['remarks'] ?? '')) ?>"
                               placeholder="Anything the Office should know about this standard">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Save</button>
                    </div>
                </form>
            <?php elseif ($item['remarks']): ?>
                <p class="text-muted small mb-0"><strong>Your remarks:</strong> <?= e((string) $item['remarks']) ?></p>
            <?php endif; ?>
        </div>
    </section>
<?php endforeach; ?>

<?php if ($editable): ?>
    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-solid fa-paper-plane"></i> Send to the Municipal Tourism Office</h2>
        </header>

        <div class="panel__body">
            <?php if ($missing !== []): ?>
                <p class="text-muted small mb-0">
                    <?= n(count($missing)) ?> required standard(s) still have no photo:
                    <strong><?= e(implode(', ', $missing)) ?></strong>.
                </p>
            <?php else: ?>
                <p class="text-muted small">
                    Every required standard has a photo. Submitting hands the whole report to the Office
                    for review &mdash; you will not be able to change it while they look at it, and they
                    will send it back with comments if anything needs a clearer picture.
                </p>

                <form method="post">
                    <?= csrf_field() ?>
                    <button type="submit" name="action" value="submit" class="btn btn-brand btn-sm" data-confirm="Submit this inspection report to the Municipal Tourism Office?">
                        <i class="fa-solid fa-paper-plane"></i> Submit Inspection Report
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/_partials/foot.php'; ?>
