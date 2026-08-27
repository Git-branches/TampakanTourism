<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/_helpers.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\Validator;
use App\Repositories\CategoryRepository;
use App\Repositories\DestinationRepository;

Auth::require();

$id = (int) ($_GET['id'] ?? 0);
$d  = DestinationRepository::find($id);

if ($d === null) {
    Session::flash('danger', 'That destination no longer exists.');
    redirect(base_url('/admin/destinations/index.php'));
}

$pageTitle    = 'Edit Destination';
$pageIcon     = 'fa-pen';
$pageSubtitle = $d['name'];

$categories = CategoryRepository::all();

if (is_post()) {
    Csrf::verify();

    $v = new Validator($_POST);
    $v->require('name')
      ->length('name', 3, 160)
      ->email('contact_email');

    validate_coordinates($v);

    if ($v->fails()) {
        flash_back($v->errors(), $_POST, 'edit.php?id=' . $id);
    }

    $data = collect_destination_input($v);

    try {
        DestinationRepository::update($id, $data);
    } catch (Throwable $e) {
        error_log('Destination update failed: ' . $e->getMessage());
        Session::flash('danger', 'The changes could not be saved. Please try again.');
        flash_back([], $_POST, 'edit.php?id=' . $id);
    }

    ActivityLog::record('destination.update', 'destination', $id, 'Updated "' . $data['name'] . '"');
    Session::flash('success', 'Changes saved. The public page is already showing them.');
    redirect(base_url('/admin/destinations/edit.php?id=' . $id));
}

// Redisplay rejected input rather than the stored values.
$old = old_all();
foreach (array_keys($d) as $key) {
    if (isset($old[$key])) {
        $d[$key] = $old[$key];
    }
}

$photos = DestinationRepository::photos($id);

require __DIR__ . '/../_partials/head.php';
?>

<div class="record-bar">
    <div class="record-bar__facts">
        <span class="record-bar__status record-bar__status--<?= e($d['status']) ?>">
            <i class="fa-solid fa-circle"></i> <?= e(ucfirst($d['status'])) ?>
        </span>
        <span><i class="fa-solid fa-images"></i> <?= count($photos) ?> photo<?= count($photos) === 1 ? '' : 's' ?></span>
        <span><i class="fa-solid fa-qrcode"></i> QR token issued <span class="mono">v<?= (int) $d['qr_version'] ?></span></span>
        <span><i class="fa-regular fa-clock"></i> Updated <?= e(format_date($d['updated_at'], 'M j, Y g:i A')) ?></span>
    </div>

    <div class="record-bar__actions">
        <a href="photos.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-images"></i> Manage Photos
        </a>
        <?php if ($d['status'] === 'active'): ?>
            <a href="<?= e(base_url('/destination.php?slug=' . $d['slug'])) ?>" target="_blank" rel="noopener"
               class="btn btn-sm btn-outline-secondary">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> View Public Page
            </a>
        <?php endif; ?>

        <form method="post" action="archive.php" class="d-inline" data-confirm="&lt;?= $d['status'] === 'active'
                  ? 'Archive this destination? It disappears from the public site and the map, but every recorded arrival is kept.'
                  : 'Restore this destination to the public site?' ?&gt;" data-confirm-tone="normal">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="status" value="<?= $d['status'] === 'active' ? 'archived' : 'active' ?>">
            <button type="submit" class="btn btn-sm <?= $d['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                <i class="fa-solid fa-box-archive"></i>
                <?= $d['status'] === 'active' ? 'Archive' : 'Restore' ?>
            </button>
        </form>
    </div>
</div>

<?php
require __DIR__ . '/_form.php';
require __DIR__ . '/../_partials/foot.php';
