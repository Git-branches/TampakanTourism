<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\Uploader;
use App\Repositories\DestinationRepository;

Auth::require();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$d  = DestinationRepository::find($id);

if ($d === null) {
    Session::flash('danger', 'That destination no longer exists.');
    redirect(base_url('/admin/destinations/index.php'));
}

$pageTitle    = 'Photos';
$pageIcon     = 'fa-images';
$pageSubtitle = $d['name'];

if (is_post()) {
    Csrf::verify();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'upload') {
        if (empty($_FILES['photos']['name'][0])) {
            Session::flash('warning', 'No files were selected.');
        } else {
            $uploader = new Uploader();
            $stored = $uploader->storeMany($_FILES['photos'], 'destinations');

            foreach ($stored as $path) {
                DestinationRepository::addPhoto($id, $path);
            }

            $failed = $uploader->errors();

            if ($stored !== []) {
                ActivityLog::record('destination.photos', 'destination', $id,
                    count($stored) . ' photo(s) added to "' . $d['name'] . '"');
                Session::flash('success', count($stored) . ' photo(s) uploaded.');
            }
            if ($failed !== []) {
                Session::flash('warning', 'Some files were rejected: ' . implode(' ', array_unique($failed)));
            }
        }
    }

    if ($action === 'cover') {
        DestinationRepository::setCover((int) $_POST['photo_id'], $id);
        Session::flash('success', 'Cover photo updated.');
    }

    if ($action === 'delete') {
        DestinationRepository::deletePhoto((int) $_POST['photo_id'], $id);
        ActivityLog::record('destination.photo_delete', 'destination', $id, 'Photo removed from "' . $d['name'] . '"');
        Session::flash('success', 'Photo removed.');
    }

    redirect(base_url('/admin/destinations/photos.php?id=' . $id));
}

$photos = DestinationRepository::photos($id);

require __DIR__ . '/../_partials/head.php';
?>

<div class="record-bar">
    <div class="record-bar__facts">
        <span><i class="fa-solid fa-images"></i> <?= count($photos) ?> photo<?= count($photos) === 1 ? '' : 's' ?></span>
        <span class="text-muted">The cover photo is used on cards, the homepage, and the map popup.</span>
    </div>
    <div class="record-bar__actions">
        <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-arrow-left"></i> Back to Details
        </a>
    </div>
</div>

<section class="panel">
    <header class="panel__head"><h2><i class="fa-solid fa-upload"></i> Upload Photos</h2></header>
    <div class="panel__body">
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="action" value="upload">

            <div class="col-md-9">
                <label for="photos" class="form-label">Choose images</label>
                <input type="file" id="photos" name="photos[]" multiple
                       accept="image/jpeg,image/png,image/webp" class="form-control" required
                       data-max-mb="<?= n(upload_limit_mb()) ?>">
                <p class="field-hint">
                    JPG, PNG, or WebP, up to <?= n(\App\Core\Uploader::maxMegabytes()) ?> MB each. Every image is decoded and re-encoded on
                    upload — that strips anything hidden in the file and resizes it for the web.
                </p>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-brand w-100">
                    <i class="fa-solid fa-upload"></i> Upload
                </button>
            </div>
        </form>
    </div>
</section>

<section class="panel">
    <header class="panel__head"><h2><i class="fa-solid fa-images"></i> Gallery</h2></header>
    <div class="panel__body">
        <?php if ($photos === []): ?>
            <div class="empty">
                <i class="fa-solid fa-image"></i>
                <p><strong>No photos yet.</strong></p>
                <p>Destination cards fall back to a placeholder until at least one photo is uploaded.</p>
            </div>
        <?php else: ?>
            <div class="photo-grid">
                <?php foreach ($photos as $p): ?>
                    <figure class="photo-tile <?= (int) $p['is_cover'] === 1 ? 'is-cover' : '' ?>">
                        <img src="<?= e(base_url($p['file_path'])) ?>" alt="<?= e($d['name']) ?>" loading="lazy">

                        <?php if ((int) $p['is_cover'] === 1): ?>
                            <span class="photo-tile__badge"><i class="fa-solid fa-star"></i> Cover</span>
                        <?php endif; ?>

                        <figcaption class="photo-tile__actions">
                            <?php if ((int) $p['is_cover'] !== 1): ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <input type="hidden" name="action" value="cover">
                                    <input type="hidden" name="photo_id" value="<?= (int) $p['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Make cover photo">
                                        <i class="fa-regular fa-star"></i>
                                    </button>
                                </form>
                            <?php endif; ?>

                            <form method="post" data-confirm="Delete this photo permanently?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="photo_id" value="<?= (int) $p['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete photo">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </figcaption>
                    </figure>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
