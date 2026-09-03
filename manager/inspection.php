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

        /* SEVERAL AT ONCE NOW, AND THE REMARKS IN THE SAME BREATH.
         *
         * A first aid kit needs two photographs — the kit open and its contents
         * — and taking them was one submit each, with the remarks a third. The
         * office asked for one action, so the field is photos[] and the note
         * travels with it.
         *
         * STILL ONE FILE AT A TIME UNDERNEATH. Each is stored and recorded on
         * its own, so a connection that drops halfway keeps the photographs that
         * already landed instead of failing the batch. Whoever is doing this is
         * standing at a waterfall on one bar.
         *
         * The single-file name is still read, so an older cached page — or a
         * browser that ignores `multiple` — keeps working. */
        $files = $_FILES['photos'] ?? $_FILES['photo'] ?? null;

        /* PHP gives a multi-file input as parallel arrays, not a list of files.
           Normalised to one row per file so the loop below reads plainly. */
        $incoming = [];

        if (is_array($files) && isset($files['name'])) {
            foreach ((array) $files['name'] as $i => $originalName) {
                if (!is_array($files['name'])) {
                    $incoming[] = $files;
                    break;
                }

                $incoming[] = [
                    'name'     => $originalName,
                    'type'     => $files['type'][$i]     ?? '',
                    'tmp_name' => $files['tmp_name'][$i] ?? '',
                    'error'    => $files['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $files['size'][$i]     ?? 0,
                ];
            }
        }

        /* An empty slot is not an error — the browser sends one for a field
           nobody filled, and the manager may have come only to write a note. */
        $incoming = array_values(array_filter(
            $incoming,
            static fn (array $f): bool => (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        ));

        $caption = trim((string) ($_POST['caption'] ?? ''));
        $saved   = 0;
        $problem = null;

        foreach ($incoming as $file) {
            $uploader = new DocumentUploader();
            $stored   = $uploader->store($file, 'inspections');

            if ($stored === null) {
                $problem = $uploader->firstError() ?? 'One photo could not be uploaded.';
                continue;
            }

            /* PDF passes DocumentUploader — it is allowed for logbook pages — but
               a compliance standard is a thing you photograph. Rejected here
               rather than by widening the shared uploader's rules. */
            if ($stored['mime_type'] === 'application/pdf') {
                DocumentUploader::delete($stored['stored_name'], 'inspections');
                $problem = 'Please upload photos (JPG or PNG), not a PDF — the office needs to see the item itself.';
                continue;
            }

            Inspections::addPhoto($itemId, $stored, (int) ManagerAuth::id(), $caption);
            $saved++;
        }

        /* The note is saved whether or not a photograph came with it, which is
           what removed the second button. */
        if (array_key_exists('remarks', $_POST)) {
            Inspections::saveItemRemarks($itemId, $reportId, trim((string) $_POST['remarks']));
        }

        if ($saved > 0) {
            ActivityLog::record(
                'inspection.photo_uploaded', 'inspection_report', $reportId,
                $saved . ' photo(s) for "' . $item['title'] . '" at ' . ManagerAuth::destinationName()
            );
        }

        /* Said exactly: "2 photos added" when two landed, and the reason when
           some did not — a batch that half-worked must not report success. */
        if ($saved > 0 && $problem === null) {
            Session::flash('success', $saved . ' photo' . ($saved === 1 ? '' : 's')
                . ' added to ' . $item['title'] . '.');
        } elseif ($saved > 0) {
            Session::flash('warning', $saved . ' photo' . ($saved === 1 ? '' : 's')
                . ' added to ' . $item['title'] . '. ' . $problem);
        } elseif ($problem !== null) {
            Session::flash('danger', $problem);
        } else {
            Session::flash('success', 'Saved.');
        }

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

<!-- ===================== THE STANDARDS =====================
     A CARD EACH, NOT A FORM EACH.

     This used to render every standard's full upload form at once: five
     panels, forty-six form fields, and 4.3 screens of scrolling on a phone.
     A manager arrives having already decided which requirement they are
     photographing, so four fifths of that was always in the way.

     Each card now carries the four things you choose between on — name, what
     it is for, where it stands, and how much evidence is already in — and the
     form itself is one tap away in inspection-item.php.

     The grid is auto-fill, so five standards land as two rows and a sixth
     costs nothing. Pagination would be the answer past roughly a dozen; at
     five it would be a control that only ever says "1". -->
<section class="panel mgr-standards">
    <header class="panel__head">
        <h2><i class="fa-solid fa-list-check"></i> Tourism Standards</h2>
        <span class="pill pill--qr"><?= n(count($items)) ?></span>
    </header>

    <div class="panel__body">
        <div class="mgr-card-grid">
            <?php foreach ($items as $item): ?>
                <?php
                $itemPhotos = (int) $item['photo_count'];
                $isOpen     = $editable;
                ?>
                <article class="mgr-card mgr-card--<?= $itemTone((string) $item['status']) ?>"
                         id="item<?= (int) $item['id'] ?>">
                    <h3 class="mgr-card__title">
                        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                        <span><?= e((string) $item['title']) ?></span>
                    </h3>

                    <?php if ($item['guidance']): ?>
                        <p class="mgr-card__hint"><?= e((string) $item['guidance']) ?></p>
                    <?php endif; ?>

                    <?php
                    $need = max(1, (int) ($item['min_photos'] ?? 1));
                    $cap  = max($need, (int) ($item['max_photos'] ?? $need));
                    $met  = $itemPhotos >= $need;
                    ?>
                    <p class="mgr-card__meta">
                        <span class="pill pill--<?= $itemTone((string) $item['status']) ?>">
                            <?= e(Inspections::ITEM_STATUSES[$item['status']]) ?>
                        </span>

                        <?php /* AGAINST WHAT IS ASKED FOR, not just how many are
                                 there. "1 photo" tells a manager nothing; "1 of
                                 2 photos" tells them to take another. */ ?>
                        <span class="mgr-card__count<?= $met ? ' is-met' : '' ?>">
                            <i class="fa-solid fa-camera" aria-hidden="true"></i>
                            <?= n($itemPhotos) ?> of <?= n($need) ?><?= $cap > $need ? '&ndash;' . n($cap) : '' ?>
                            photo<?= $need === 1 && $cap === 1 ? '' : 's' ?>
                        </span>

                        <?php if ((int) $item['is_required'] !== 1): ?>
                            <span class="mgr-card__opt">Optional</span>
                        <?php endif; ?>
                    </p>

                    <?php /* One primary action, worded for what it does. A
                             standard with no photograph needs one; a standard
                             with photographs is something you look at. Both
                             open the same dialog — the wording is the whole
                             difference, and it is the part a manager reads. */ ?>
                    <a class="btn btn-sm <?= $itemPhotos === 0 && $isOpen ? 'btn-brand' : 'btn-outline-secondary' ?> mgr-card__go"
                       href="<?= e(base_url('/manager/inspection-item.php?id=' . (int) $item['id'])) ?>"
                       data-modal-page
                       data-modal-title="<?= e((string) $item['title']) ?>">
                        <?php if (!$isOpen): ?>
                            <i class="fa-solid fa-eye"></i> View Evidence
                        <?php elseif ($itemPhotos === 0): ?>
                            <i class="fa-solid fa-camera"></i> Add Evidence
                        <?php else: ?>
                            <i class="fa-solid fa-images"></i> View Evidence
                        <?php endif; ?>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php /* The old per-standard panels rendered here — one full upload form
         per requirement, all of them open at once. Everything they did now
         lives in inspection-item.php, opened from the cards above. The POST
         handlers at the top of this file were not touched: this was a change
         of what the manager is shown, not of what the server does. */ ?>

<?php if ($editable): ?>
    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-solid fa-paper-plane"></i> Send to the Municipal Tourism Office</h2>
        </header>

        <div class="panel__body">
            <?php if ($missing !== []): ?>
                <p class="text-muted small mb-0">
                    <?php /* "still have no photo" was true while the gate only
                             asked for one. A standard that wants two and has one
                             now appears here too, and saying it has no photo is
                             simply false. */ ?>
                    <?= n(count($missing)) ?> required standard(s) still need photos:
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
