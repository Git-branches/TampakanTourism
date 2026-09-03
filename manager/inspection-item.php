<?php
declare(strict_types=1);

/**
 * TourSync — one compliance standard, for the evidence dialog.
 *
 * WHY THIS PAGE EXISTS
 *
 * inspection.php used to render every standard's full upload form at once:
 * five panels, forty-six form fields, 4.3 screens of scrolling on a phone. A
 * manager standing at a waterfall looking for the one requirement they came to
 * photograph had to scroll past four they did not.
 *
 * The standards are a compact card grid now, and this page is what one card
 * opens — the evidence already sent, the upload form, and the manager's note.
 *
 * WHAT THIS PAGE DOES NOT DO
 *
 * It does not handle a single POST. Upload, remove-photo and remarks are all
 * still handled by inspection.php exactly as they were, and the forms below
 * carry an explicit action pointing back there. That is deliberate: the upload
 * path validates a MIME type, rejects PDFs, writes an activity log and moves a
 * file on disk, and a second copy of that in a new file is a second copy to get
 * wrong. This page is a view. The logic did not move.
 *
 * PERMISSIONS ARE NOT RE-INVENTED EITHER. The item is looked up with
 * findItem($id, $reportId) where $reportId is this manager's own open report,
 * so a manager cannot open another destination's standard by editing the
 * address — the query simply returns nothing.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\ManagerAuth;
use App\Repositories\InspectionRepository as Inspections;

ManagerAuth::require();

$destinationId = (int) ManagerAuth::destinationId();
$report        = Inspections::openFor($destinationId);
$reportId      = (int) $report['id'];
$editable      = Inspections::isEditable($report);

$itemId = (int) ($_GET['id'] ?? 0);
$item   = $itemId > 0 ? Inspections::findItem($itemId, $reportId) : null;

if ($item === null) {
    /* Not "forbidden": from here the two are the same thing, and saying which
       would tell someone probing addresses that the id exists. */
    http_response_code(404);
    echo '<p class="text-muted">That requirement could not be found.</p>';
    exit;
}

/* findItem() returns the row without its photographs — the card grid needs a
   count, this dialog needs the pictures themselves. */
$photos = Inspections::photos($itemId);

/* How many the office asked for. Defaulted rather than assumed present, so a
   requirement added before the columns existed still renders. */
$needMin = max(1, (int) ($item['min_photos'] ?? 1));
$needMax = max($needMin, (int) ($item['max_photos'] ?? $needMin));

/* Where every form on this page posts. The handlers live there. */
$postTo = base_url('/manager/inspection.php');

$modal = is_modal_request();

if (!$modal) {
    /* Reachable on its own, so a browser with no JavaScript still gets to the
       upload form rather than a card that does nothing. */
    $pageTitle    = (string) $item['title'];
    $pageIcon     = 'fa-shield-halved';
    $pageSubtitle = ManagerAuth::destinationName();

    require __DIR__ . '/_partials/head.php';

    echo '<p class="mb-3"><a class="text-muted small" href="' . e(base_url('/manager/inspection.php')) . '">'
       . '<i class="fa-solid fa-arrow-left"></i> All standards</a></p>';
}
?>

<div class="mgr-evidence">

    <?php /* The status, restated inside the dialog. The card that opened it is
             behind the backdrop and cannot be re-read. */ ?>
    <p class="mgr-evidence__status">
        <span class="pill pill--<?= [
            'approved'       => 'ok',
            'rejected'       => 'flag',
            'needs_revision' => 'qr',
            'submitted'      => 'qr',
        ][$item['status']] ?? 'void' ?>">
            <?= e(Inspections::ITEM_STATUSES[$item['status']]) ?>
        </span>
        <?php if ((int) $item['is_required'] !== 1): ?>
            <span class="text-muted small">Optional</span>
        <?php endif; ?>
    </p>

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

    <!-- ---------------- what has already been sent ---------------- -->
    <?php if ($photos === []): ?>
        <p class="text-muted small mb-3"><em>No photo sent for this standard yet.</em></p>
    <?php else: ?>
        <div class="evidence-grid mb-3">
            <?php foreach ($photos as $photo): ?>
                <?php $src = base_url('/api/inspections/photo.php?id=' . (int) $photo['id'] . '&report=' . $reportId); ?>
                <figure class="evidence">
                    <?php /* data-lightbox opens it in the viewer instead of
                             handing the manager to a bare photo.php tab. The
                             href stays, so with JavaScript off the anchor still
                             reaches the image. target="_blank" is gone: it is
                             the thing that used to leave the system. */ ?>
                    <a href="<?= e($src) ?>" data-lightbox
                       data-caption="<?= e((string) ($photo['caption'] ?: $item['title'])) ?>">
                        <img src="<?= e($src) ?>"
                             alt="<?= e((string) ($photo['caption'] ?: $item['title'])) ?>" loading="lazy">
                    </a>
                    <figcaption>
                        <?php if ($photo['caption']): ?>
                            <span class="evidence__caption"><?= e((string) $photo['caption']) ?></span>
                        <?php endif; ?>
                        <span class="cell-sub"><?= e(Inspections::humanSize((int) $photo['byte_size'])) ?></span>

                        <?php if ($editable): ?>
                            <form method="post" action="<?= e($postTo) ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="remove-photo">
                                <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
                                <input type="hidden" name="item_id" value="<?= $itemId ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        data-confirm="Remove this photo?" data-confirm-tone="danger"
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
        <!-- ---------------- the evidence, in one action ----------------
             ONE FORM AND TWO BUTTONS, because the office asked for one action.

             It used to be three: a file, then Upload, then a separate Save for
             the remarks. A first aid kit needs two photographs, so filing one
             standard was three round trips over the signal at a trailhead.

             photos[] is multiple. The handler still stores them one at a time,
             so a connection that drops keeps whatever already landed.

             data-modal-reload asks the dialog to close and the page behind to
             reload once this succeeds, so the card's "0 of 2 photos" becomes
             "2 of 2" instead of going stale. It is opt-in and no officer form
             carries it. -->
        <form method="post" action="<?= e($postTo) ?>" enctype="multipart/form-data"
              class="mgr-evidence__form" data-modal-reload id="mgrEvidenceForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">

            <div class="mb-2">
                <label class="form-label" for="mgrPhotos">
                    Photos
                    <span class="text-muted small">
                        <?= $needMin === $needMax
                            ? n($needMin) . ' needed'
                            : n($needMin) . '&ndash;' . n($needMax) . ' needed' ?>
                    </span>
                </label>
                <input type="file" id="mgrPhotos" name="photos[]" multiple
                       class="form-control form-control-sm"
                       accept="image/jpeg,image/png,.jpg,.jpeg,.png" capture="environment"
                       data-max-mb="<?= n(upload_limit_mb()) ?>">

                <?php /* Counted against what is ALREADY on file plus what is
                         about to be sent, because a standard with one photo
                         already needs one more, not two. */ ?>
                <p class="mgr-evidence__count" id="mgrCount"
                   data-have="<?= count($photos) ?>" data-need="<?= (int) $needMin ?>">
                    <?= n(count($photos)) ?> of <?= n($needMin) ?> photos added
                </p>

                <div class="mgr-previews" id="mgrPreviews" hidden></div>
            </div>

            <div class="mb-2">
                <label class="form-label" for="mgrCaption">
                    What does it show? <span class="text-muted small">(optional)</span>
                </label>
                <input type="text" id="mgrCaption" name="caption"
                       class="form-control form-control-sm" maxlength="300"
                       placeholder="e.g. by the entrance, tag dated Jan 2026">
            </div>

            <div class="mb-2">
                <label class="form-label" for="mgrRemarks">
                    Remarks <span class="text-muted small">(optional)</span>
                </label>
                <input type="text" id="mgrRemarks" name="remarks"
                       class="form-control form-control-sm" maxlength="600"
                       value="<?= e((string) ($item['remarks'] ?? '')) ?>"
                       placeholder="Anything the Office should know about this standard">
            </div>

            <div class="mgr-evidence__actions">
                <?php /* data-dialog-close is the attribute the shell already
                         listens for — not a new one invented for this button. */ ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close>Cancel</button>
                <button type="submit" class="btn btn-brand btn-sm">
                    <i class="fa-solid fa-camera"></i> Upload Evidence
                </button>
            </div>
        </form>

        <script>
        /* Previews and the running count. Both are read off the file input, so
           they cost no request and work with the signal off.
           createObjectURL rather than FileReader: no base64 copy of a 4MB
           photograph in memory, and the URL is revoked when the row goes. */
        (function () {
            var input    = document.getElementById('mgrPhotos');
            var previews = document.getElementById('mgrPreviews');
            var count    = document.getElementById('mgrCount');

            if (!input || !previews || !count) { return; }

            var have = parseInt(count.dataset.have, 10) || 0;
            var need = parseInt(count.dataset.need, 10) || 1;
            var urls = [];

            input.addEventListener('change', function () {
                urls.forEach(URL.revokeObjectURL);
                urls = [];
                previews.innerHTML = '';

                var files = Array.prototype.slice.call(input.files || []);

                files.forEach(function (file) {
                    if (file.type.indexOf('image/') !== 0) { return; }

                    var url = URL.createObjectURL(file);
                    urls.push(url);

                    var figure = document.createElement('figure');
                    figure.className = 'mgr-preview';

                    var img = document.createElement('img');
                    img.src = url;
                    img.alt = file.name;

                    var cap = document.createElement('figcaption');
                    cap.textContent = file.name;

                    figure.appendChild(img);
                    figure.appendChild(cap);
                    previews.appendChild(figure);
                });

                previews.hidden = files.length === 0;

                var total = have + files.length;
                count.textContent = total + ' of ' + need + ' photos added';
                count.classList.toggle('is-met', total >= need);
            });
        })();
        </script>
    <?php else: ?>
        <p class="text-muted small mb-0">
            <i class="fa-solid fa-lock"></i>
            This report is with the Office and cannot be changed.
            <?php if ($item['remarks']): ?>
                <br><strong>Your remarks:</strong> <?= e((string) $item['remarks']) ?>
            <?php endif; ?>
        </p>
    <?php endif; ?>

</div>

<?php
if (!$modal) {
    require __DIR__ . '/_partials/foot.php';
}
