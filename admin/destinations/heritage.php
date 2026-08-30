<?php
declare(strict_types=1);

/**
 * TourSync — the heritage items on a destination's QR page.
 *
 * A scanned QR code already carried `destinations.cultural_heritage`: one block
 * of prose about the place. What it could not carry was a PICTURE of any of it
 * — the weaving, the burial jar, the ancestral marker — so the section a
 * visitor reads while standing in front of the thing had nothing to look at.
 *
 * Each item is a photograph, a heading and a paragraph. They are a list the
 * office curates and reorders, which is why they are records rather than more
 * columns on `destinations`.
 *
 * Deliberately NOT part of destination_photos: three queries in
 * DestinationRepository pick a cover photograph out of that table, and sharing
 * it would let a burial jar become a waterfall's cover on the public list.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\Uploader;
use App\Repositories\DestinationRepository;
use App\Repositories\HeritageRepository as Heritage;

Auth::require();

$id = (int) ($_GET['id'] ?? $_POST['destination_id'] ?? 0);
$d  = DestinationRepository::find($id);

if ($d === null) {
    Session::flash('danger', 'That destination no longer exists.');
    redirect(base_url('/admin/destinations/index.php'));
}

$pageTitle    = 'Cultural Heritage';
$pageIcon     = 'fa-landmark-dome';
$pageSubtitle = $d['name'];

$back = base_url('/admin/destinations/heritage.php?id=' . $id);

if (is_post()) {
    Csrf::verify();

    $action = (string) ($_POST['action'] ?? '');
    $itemId = (int) ($_POST['item_id'] ?? 0);

    /* An item is only ever addressed through its destination, so a crafted id
       belonging to another destination cannot be edited from this page. */
    $item = $itemId > 0 ? Heritage::find($itemId) : null;

    if ($item !== null && (int) $item['destination_id'] !== $id) {
        $item = null;
    }

    if ($action !== 'create' && $action !== 'reorder' && $item === null) {
        Session::flash('danger', 'That heritage item no longer exists.');
        redirect($back);
    }

    /* Stored through Uploader into uploads/destinations, which re-encodes
       through GD — an image with something smuggled into its metadata does not
       survive — and names the file randomly rather than trusting the browser. */
    $storeImage = static function () use ($back): ?string {
        if (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $uploader = new Uploader();
        $stored   = $uploader->store($_FILES['image'], 'destinations');

        if ($stored === null) {
            Session::flash('danger', $uploader->firstError() ?? 'That image could not be saved.');
            redirect($back);
        }

        return $stored;
    };

    $words = [
        'title' => trim((string) ($_POST['title'] ?? '')),
        'body'  => trim((string) ($_POST['body']  ?? '')),
    ];

    switch ($action) {
        case 'create':
            if ($words['title'] === '' && $words['body'] === '') {
                Session::flash('danger', 'Give the item a heading or a description. '
                    . 'A photograph with nothing said about it is not heritage.');
                redirect($back);
            }

            $newId = Heritage::create($id, $words);
            $image = $storeImage();

            if ($image !== null) {
                Heritage::replaceImage($newId, $image);
            }

            ActivityLog::record('heritage.create', 'destination', $id,
                'Added heritage item "' . $words['title'] . '" to ' . $d['name']);
            Session::flash('success', 'Heritage item added.');
            break;

        case 'update':
            if ($words['title'] === '' && $words['body'] === '') {
                Session::flash('danger', 'Give the item a heading or a description.');
                redirect($back);
            }

            /* The words are saved BEFORE the photograph is touched, so a
               rejected upload does not throw away an edited paragraph. */
            Heritage::update($itemId, $words);

            $image = $storeImage();

            if ($image !== null) {
                Heritage::replaceImage($itemId, $image);
            } elseif (!empty($_POST['remove_image'])) {
                Heritage::clearImage($itemId);
            }

            ActivityLog::record('heritage.update', 'destination', $id,
                'Edited heritage item "' . $words['title'] . '" on ' . $d['name']);
            Session::flash('success', 'Heritage item saved.');
            break;

        case 'delete':
            Heritage::delete($itemId);
            ActivityLog::record('heritage.delete', 'destination', $id,
                'Deleted heritage item "' . $item['title'] . '" from ' . $d['name']);
            Session::flash('success', 'Heritage item deleted.');
            break;

        case 'reorder':
            Heritage::reorder($id, (array) ($_POST['order'] ?? []));
            ActivityLog::record('heritage.reorder', 'destination', $id,
                'Reordered the heritage items on ' . $d['name']);
            Session::flash('success', 'New order saved.');
            break;
    }

    redirect($back);
}

$items = Heritage::forDestination($id);

require __DIR__ . '/../_partials/section-head.php';
/* Skips the shell when this page was asked for as a dialog fragment.
   Additive: without ?modal=1 nothing here changes at all. */
if (!is_modal_request()) { require __DIR__ . '/../_partials/head.php'; }
?>

<div class="record-bar">
    <a class="btn btn-sm btn-outline-secondary" href="index.php">
        <i class="fa-solid fa-arrow-left"></i> All destinations
    </a>
    <span class="record-bar__title"><?= e($d['name']) ?></span>
    <div class="record-bar__spacer"></div>
    <a class="btn btn-sm btn-outline-secondary" href="photos.php?id=<?= $id ?>">
        <i class="fa-solid fa-images"></i> Photos
    </a>
    <a class="btn btn-sm btn-outline-secondary" href="edit.php?id=<?= $id ?>">
        <i class="fa-solid fa-pen"></i> Edit details
    </a>
</div>

<section class="panel">
    <?php section_head('fa-landmark-dome', 'Cultural Heritage',
        'Shown on this destination&rsquo;s QR page, under its heritage text.',
        count($items) === 1 ? '1 item' : count($items) . ' items') ?>

    <div class="panel__body">
        <div class="hero-bar">
            <p class="hero-bar__note">
                <?php if ($items === []): ?>
                    Nothing here yet. A visitor scanning the sign at
                    <?= e($d['name']) ?> reads the heritage text with nothing to look at.
                <?php else: ?>
                    <?= n(count($items)) ?> item<?= count($items) === 1 ? '' : 's' ?>,
                    shown in this order on the QR page.
                <?php endif; ?>
            </p>

            <div class="filter-bar__actions">
                <button type="button" class="btn btn-brand btn-sm" data-dialog="heritageAdd">
                    <i class="fa-solid fa-plus"></i> Add heritage item
                </button>
            </div>
        </div>

        <?php if ($items === []): ?>
            <p class="hero-empty">
                <i class="fa-solid fa-landmark-dome"></i>
                Add the weaving, the burial jar, the ancestral marker &mdash; whatever a
                visitor standing here should be told about.
            </p>
        <?php else: ?>
            <?php /* Dragging rewrites the hidden inputs and submits. Without
                     JavaScript the list does not drag and the arrows under each
                     item still work. */ ?>
            <form method="post" id="heritageOrderForm">
                <?= csrf_field() ?>
                <input type="hidden" name="destination_id" value="<?= $id ?>">
                <input type="hidden" name="action" value="reorder">

                <ul class="hero-list" id="heritageList">
                    <?php foreach ($items as $i => $it): ?>
                        <?php
                        $iid   = (int) $it['id'];
                        $thumb = uploaded_url((string) $it['image_path']);
                        ?>
                        <li class="hero-row" data-hero-id="<?= $iid ?>">
                            <input type="hidden" name="order[]" value="<?= $iid ?>">

                            <span class="hero-row__grip" aria-hidden="true" title="Drag to reorder">
                                <i class="fa-solid fa-grip-vertical"></i>
                            </span>

                            <span class="hero-row__num"><?= $i + 1 ?></span>

                            <span class="hero-row__thumb<?= $thumb === null ? ' is-empty' : '' ?>"
                                  <?= $thumb === null ? 'title="No photograph yet"' : '' ?>>
                                <?php if ($thumb !== null): ?>
                                    <img src="<?= e($thumb) ?>" alt="">
                                <?php else: ?>
                                    <i class="fa-solid fa-image" aria-hidden="true"></i>
                                    <span class="visually-hidden">No photograph yet</span>
                                <?php endif; ?>
                            </span>

                            <div class="hero-row__main">
                                <p class="hero-row__title">
                                    <?= e(trim((string) $it['title']) !== ''
                                        ? (string) $it['title'] : 'Untitled item') ?>
                                </p>
                                <p class="hero-row__sub">
                                    <?= e(trim((string) $it['body']) !== ''
                                        ? mb_substr((string) $it['body'], 0, 90) : 'No description') ?>
                                </p>
                            </div>

                            <div class="hero-row__actions">
                                <button type="button" class="icon-btn" data-dialog="heritageEdit<?= $iid ?>"
                                        title="Edit this item" aria-label="Edit this item">
                                    <i class="fa-solid fa-pen"></i>
                                </button>

                                <button type="submit" class="icon-btn icon-btn--danger"
                                        form="heritageDel<?= $iid ?>"
                                        data-confirm="Delete &quot;<?= e((string) $it['title']) ?>&quot;? The photograph goes with it."
                                        data-confirm-tone="danger"
                                        title="Delete this item" aria-label="Delete this item">
                                    <i class="fa-regular fa-trash-can"></i>
                                </button>

                                <button type="button" class="icon-btn" data-hero-expand
                                        aria-expanded="false" title="Show the description"
                                        aria-label="Show the description">
                                    <i class="fa-solid fa-chevron-down"></i>
                                </button>
                            </div>

                            <div class="hero-row__more" hidden>
                                <p class="hero-row__body">
                                    <?= e(trim((string) $it['body']) !== ''
                                        ? (string) $it['body'] : 'This item has no description.') ?>
                                </p>
                                <div class="hero-row__more-actions">
                                    <span class="hero-row__moves">
                                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                                data-hero-move="up" <?= $i === 0 ? 'disabled' : '' ?>
                                                aria-label="Move up">
                                            <i class="fa-solid fa-arrow-up"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                                data-hero-move="down"
                                                <?= $i === count($items) - 1 ? 'disabled' : '' ?>
                                                aria-label="Move down">
                                            <i class="fa-solid fa-arrow-down"></i>
                                        </button>
                                    </span>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </form>

            <p class="hero-hint" id="heritageHint" hidden>
                <i class="fa-solid fa-arrows-up-down"></i>
                <span class="hero-hint__drag">Drag an item by its handle to reorder, or use</span>
                <span class="hero-hint__tap">Use</span>
                the arrows under an item to move it.
            </p>
        <?php endif; ?>
    </div>
</section>

<?php /* One form per delete, parked outside every other form and reached from
         the row by form="…". Each action is its own POST, so a mis-click on
         Delete cannot pick up the reorder's fields. */ ?>
<?php foreach ($items as $it): ?>
    <form method="post" id="heritageDel<?= (int) $it['id'] ?>" class="d-none">
        <?= csrf_field() ?>
        <input type="hidden" name="destination_id" value="<?= $id ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
    </form>
<?php endforeach; ?>

<?php
/* One sheet per item plus one for a new one. Rendering them from PHP rather
   than repopulating a single sheet with JavaScript means the fields are filled
   server-side and already escaped, and a sheet opened with scripting broken is
   still the right item's form. */
$sheet = static function (?array $it) use ($id, $d): void {
    $iid   = $it !== null ? (int) $it['id'] : 0;
    $isNew = $it === null;
    $thumb = $isNew ? null : uploaded_url((string) $it['image_path']);
    ?>
    <dialog class="sheet sheet--wide" id="<?= $isNew ? 'heritageAdd' : 'heritageEdit' . $iid ?>">
        <form method="post" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="destination_id" value="<?= $id ?>">
            <input type="hidden" name="action" value="<?= $isNew ? 'create' : 'update' ?>">
            <?php if (!$isNew): ?>
                <input type="hidden" name="item_id" value="<?= $iid ?>">
            <?php endif; ?>

            <header class="sheet__head">
                <h2><i class="fa-solid fa-<?= $isNew ? 'plus' : 'pen' ?>" aria-hidden="true"></i>
                    <?= $isNew ? 'Add heritage item' : 'Edit heritage item' ?></h2>
                <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </header>

            <div class="sheet__body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" for="h_title_<?= $iid ?>">Heading</label>
                        <input type="text" class="form-control" id="h_title_<?= $iid ?>"
                               name="title" maxlength="<?= \App\Repositories\HeritageRepository::MAX_TITLE ?>"
                               placeholder="e.g. B&rsquo;laan backstrap weaving"
                               value="<?= $isNew ? '' : e((string) $it['title']) ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="h_body_<?= $iid ?>">Description</label>
                        <textarea class="form-control" id="h_body_<?= $iid ?>" name="body" rows="5"
                                  maxlength="<?= \App\Repositories\HeritageRepository::MAX_BODY ?>"
                                  placeholder="What a visitor standing here should be told about it."><?= $isNew ? '' : e((string) $it['body']) ?></textarea>
                        <p class="field-hint">
                            Shown on the QR page for <?= e($d['name']) ?>, under the heritage text.
                        </p>
                    </div>

                    <div class="col-md-7">
                        <label class="form-label" for="h_image_<?= $iid ?>">Photograph</label>
                        <input type="file" class="form-control" id="h_image_<?= $iid ?>"
                               name="image" accept="image/jpeg,image/png,image/webp">
                        <p class="field-hint">
                            JPG, PNG or WebP up to <?= n(Uploader::maxMegabytes()) ?>&nbsp;MB.
                            <?= $thumb === null
                                ? 'Optional — the item shows as words alone without one.'
                                : 'Leave empty to keep the picture already saved.' ?>
                        </p>

                        <?php if ($thumb !== null): ?>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" value="1"
                                       id="h_rm_<?= $iid ?>" name="remove_image">
                                <label class="form-check-label" for="h_rm_<?= $iid ?>">
                                    Remove the current photograph
                                </label>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($thumb !== null): ?>
                        <div class="col-md-5">
                            <img class="hero-sheet__thumb" src="<?= e($thumb) ?>" alt="Current photograph">
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <footer class="sheet__foot">
                <button type="button" class="btn btn-outline-secondary" data-dialog-close>Cancel</button>
                <button type="submit" class="btn btn-brand">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <?= $isNew ? 'Add item' : 'Save item' ?>
                </button>
            </footer>
        </form>
    </dialog>
    <?php
};

$sheet(null);

foreach ($items as $it) {
    $sheet($it);
}
?>

<script>
(function () {
    'use strict';

    var list = document.getElementById('heritageList');

    if (!list) { return; }

    var orderForm = document.getElementById('heritageOrderForm');
    var hint      = document.getElementById('heritageHint');

    /* Hidden in the markup and revealed here: without this script the rows do
       not drag and the arrows do nothing, so an instruction to drag them would
       be a lie told to exactly the people who cannot. */
    if (hint) { hint.hidden = false; }

    list.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-hero-expand]');

        if (!toggle) { return; }

        var row  = toggle.closest('.hero-row');
        var more = row.querySelector('.hero-row__more');
        var open = more.hidden;

        more.hidden = !open;
        row.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    function renumber() {
        var rows = list.querySelectorAll('.hero-row');

        rows.forEach(function (row, i) {
            row.querySelector('.hero-row__num').textContent = i + 1;

            var up   = row.querySelector('[data-hero-move="up"]');
            var down = row.querySelector('[data-hero-move="down"]');

            if (up)   { up.disabled   = i === 0; }
            if (down) { down.disabled = i === rows.length - 1; }
        });
    }

    /* The hidden order[] inputs live inside the rows, so moving a row moves its
       input with it and the form posts whatever is on screen. */
    function saveOrder() {
        renumber();
        orderForm.submit();
    }

    list.addEventListener('click', function (event) {
        var move = event.target.closest('[data-hero-move]');

        if (!move || move.disabled) { return; }

        var row = move.closest('.hero-row');

        if (move.getAttribute('data-hero-move') === 'up') {
            if (row.previousElementSibling) {
                list.insertBefore(row, row.previousElementSibling);
            }
        } else if (row.nextElementSibling) {
            list.insertBefore(row.nextElementSibling, row);
        }

        saveOrder();
    });

    /* Rows are not draggable until a press lands on the grip — otherwise a slow
       click on Delete becomes a drag, and selecting the heading to copy it
       picks the whole row up instead. */
    var dragging = null;

    list.addEventListener('pointerdown', function (event) {
        var grip = event.target.closest('.hero-row__grip');
        var row  = event.target.closest('.hero-row');

        if (row) { row.draggable = !!grip; }
    });

    list.addEventListener('dragstart', function (event) {
        dragging = event.target.closest('.hero-row');

        if (!dragging) { return; }

        dragging.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', '');   /* Firefox needs something set */
    });

    list.addEventListener('dragover', function (event) {
        if (!dragging) { return; }

        event.preventDefault();

        var over = event.target.closest('.hero-row');

        if (!over || over === dragging) { return; }

        /* Past the midpoint means the pointer has committed to the far side of
           that row. Comparing to the midpoint rather than the edges stops the
           list flickering while the pointer sits on a boundary. */
        var box  = over.getBoundingClientRect();
        var past = event.clientY > box.top + box.height / 2;

        list.insertBefore(dragging, past ? over.nextElementSibling : over);
    });

    list.addEventListener('dragend', function () {
        if (!dragging) { return; }

        dragging.classList.remove('is-dragging');
        dragging.draggable = false;
        dragging = null;

        saveOrder();
    });
})();
</script>

<?php if (!is_modal_request()) { require __DIR__ . '/../_partials/foot.php'; } ?>
