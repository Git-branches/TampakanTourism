<?php
declare(strict_types=1);

/**
 * TourSync — promotional videos.                                     Feature 8
 *
 * The office uploads a clip about a destination, or pastes a YouTube link for
 * anything past this server's upload limit, and it appears on the public site.
 *
 * WHAT THIS REPLACED. The homepage looked for a hero video by globbing
 * assets/video/*.mp4 — a folder nothing in this panel could write to. The
 * office had already put Tampakan.mp4 in uploads/Video/ by hand, where no page
 * ever looked for it, so their video sat on the server and off the website.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Paginator;
use App\Core\Session;
use App\Core\VideoUploader;
use App\Repositories\VideoRepository as Videos;

Auth::require();

if (is_post()) {
    Csrf::verify();

    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'create') {
        $title  = trim((string) ($_POST['title'] ?? ''));
        $source = ($_POST['source'] ?? 'upload') === 'external' ? 'external' : 'upload';
        $link   = trim((string) ($_POST['external_url'] ?? ''));

        if ($title === '') {
            Session::flash('danger', 'Give the video a title — it is what the caption reads on the website.');
            redirect(base_url('/admin/videos/index.php'));
        }

        $payload = [
            'title'          => $title,
            'caption'        => $_POST['caption'] ?? '',
            'destination_id' => $_POST['destination_id'] ?? null,
            'category'       => $_POST['category'] ?? 'promo',
            'source'         => $source,
            'status'         => 'draft',
            'sort_order'     => $_POST['sort_order'] ?? 0,
            'created_by'     => Auth::id(),
        ];

        if ($source === 'external') {
            /* Refused rather than stored. A link this system cannot turn into
               an embed is a link that renders as an empty box on the public
               page, and the office would have no way of telling why. */
            if (!Videos::isEmbeddable($link)) {
                Session::flash('danger', 'That link is not a YouTube or Facebook video address this system recognises.');
                redirect(base_url('/admin/videos/index.php'));
            }

            $payload['external_url'] = $link;
        } else {
            $uploader = new VideoUploader();
            $stored   = $uploader->store($_FILES['video'] ?? []);

            if ($stored === null) {
                Session::flash('danger', implode(' ', array_unique($uploader->errors())) ?: 'The video could not be uploaded.');
                redirect(base_url('/admin/videos/index.php'));
            }

            $payload['file_path'] = $stored['path'];
            $payload['mime_type'] = $stored['mime'];
            $payload['file_size'] = $stored['size'];
        }

        $newId = Videos::create($payload);

        ActivityLog::record('video.added', 'promo_video', $newId, 'Added "' . $title . '"');
        Session::flash('success', 'Video added as a draft. Publish it when you are ready for it to appear.');
    }

    if ($action === 'update' && $id > 0) {
        Videos::update($id, [
            'title'          => $_POST['title'] ?? '',
            'caption'        => $_POST['caption'] ?? '',
            'destination_id' => $_POST['destination_id'] ?? null,
            'category'       => $_POST['category'] ?? 'promo',
            'status'         => $_POST['status'] ?? 'draft',
            'sort_order'     => $_POST['sort_order'] ?? 0,
        ]);

        ActivityLog::record('video.updated', 'promo_video', $id, 'Updated video ' . $id);
        Session::flash('success', 'Saved.');
    }

    if ($action === 'publish' && $id > 0) {
        Videos::setStatus($id, 'published');
        ActivityLog::record('video.published', 'promo_video', $id, 'Published video ' . $id);
        Session::flash('success', 'Published. It is on the website now.');
    }

    if ($action === 'unpublish' && $id > 0) {
        Videos::setStatus($id, 'draft');
        ActivityLog::record('video.unpublished', 'promo_video', $id, 'Unpublished video ' . $id);
        Session::flash('success', 'Taken off the website.');
    }

    if ($action === 'delete' && $id > 0) {
        $video = Videos::find($id);
        Videos::delete($id);

        ActivityLog::record('video.deleted', 'promo_video', $id,
            'Deleted "' . (string) ($video['title'] ?? $id) . '"');
        Session::flash('success', 'Deleted, and the file removed from the server.');
    }

    redirect(base_url('/admin/videos/index.php'));
}

$search        = trim((string) ($_GET['q'] ?? ''));
$status        = (string) ($_GET['status'] ?? '');
$category      = (string) ($_GET['category'] ?? '');
$destinationId = (int) ($_GET['destination'] ?? 0);

if ($status !== '' && !isset(Videos::STATUSES[$status])) {
    $status = '';
}

if ($category !== '' && !isset(Videos::CATEGORIES[$category])) {
    $category = '';
}

$pager = Paginator::slice(
    Videos::all([
        'status'         => $status,
        'category'       => $category,
        'destination_id' => $destinationId,
        'search'         => $search,
    ], 500),
    $_GET['page'] ?? null
);

$videos       = $pager['rows'];
$counts       = Videos::counts();
$destinations = Database::all("SELECT id, name FROM destinations WHERE status = 'active' ORDER BY name");

$edit = null;

if (($eid = (int) ($_GET['edit'] ?? 0)) > 0) {
    $edit = Videos::find($eid);
}

$pageTitle    = 'Promotional Videos';
$pageIcon     = 'fa-film';
$pageSubtitle = 'Clips shown on the public website';

require __DIR__ . '/../_partials/head.php';
?>
<?php
/* THE STAT CARDS THAT SAT HERE ARE GONE.
 *
 * Two of them, counting published and draft, each a shortcut to a filter. The
 * filter bar below now says the same thing in a control that also does the
 * other three jobs, and the footer counts the whole set. Two ways to set one
 * filter is one way too many on a screen this small. */
?>

<div class="page-actions">
    <button type="button" class="btn btn-brand" data-dialog="addVideo">
        <i class="fa-solid fa-plus" aria-hidden="true"></i> Add Video
    </button>
</div>

<form class="filter-bar" method="get">
    <div class="filter-bar__row">
        <div class="search-field">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search by title or caption…">
        </div>

        <select name="category" class="form-select form-select-sm" aria-label="Category">
            <option value="">All categories</option>
            <?php foreach (Videos::CATEGORIES as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="destination" class="form-select form-select-sm" aria-label="Destination">
            <option value="">All destinations</option>
            <?php foreach ($destinations as $d): ?>
                <option value="<?= (int) $d['id'] ?>" <?= $destinationId === (int) $d['id'] ? 'selected' : '' ?>>
                    <?= e((string) $d['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="status" class="form-select form-select-sm" aria-label="Status">
            <option value="">All statuses</option>
            <?php foreach (Videos::STATUSES as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>

        <div class="filter-bar__actions">
            <?php /* Reset is held disabled when nothing is filtered, rather than
                     hidden — a button that appears and disappears moves the
                     Apply button under the cursor about to press it. */ ?>
            <?php $filtered = $search !== '' || $status !== '' || $category !== '' || $destinationId > 0; ?>
            <?php if ($filtered): ?>
                <a href="index.php" class="btn btn-sm btn-outline-secondary">
                    <i class="fa-solid fa-arrow-rotate-left" aria-hidden="true"></i> Reset
                </a>
            <?php else: ?>
                <span class="btn btn-sm btn-outline-secondary disabled" aria-disabled="true">
                    <i class="fa-solid fa-arrow-rotate-left" aria-hidden="true"></i> Reset
                </span>
            <?php endif; ?>

            <button type="submit" class="btn btn-sm btn-brand">
                <i class="fa-solid fa-filter" aria-hidden="true"></i> Apply
            </button>
        </div>
    </div>
</form>

<?php if ($videos === []): ?>
    <section class="panel">
        <div class="panel__body">
            <div class="empty-public">
                <i class="fa-solid fa-film" aria-hidden="true"></i>
                <?php if ($filtered): ?>
                    <h3>No results found</h3>
                    <p>No video matches your search or filter.</p>
                    <a href="index.php" class="btn btn-sm btn-outline-secondary">
                        <i class="fa-solid fa-arrow-rotate-left" aria-hidden="true"></i> Reset filters
                    </a>
                <?php else: ?>
                    <h3>No promotional videos yet</h3>
                    <p>
                        Upload a clip about a destination, or paste a YouTube link. A published video
                        appears on that destination's own page and on its QR page &mdash; nowhere else.
                    </p>
                    <button type="button" class="btn btn-sm btn-brand" data-dialog="addVideo">
                        <i class="fa-solid fa-plus" aria-hidden="true"></i> Add Video
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </section>
<?php else: ?>

    <div class="video-grid">
        <?php foreach ($videos as $v): ?>
            <?php
            $embed = $v['source'] === 'external' ? Videos::embedUrl((string) $v['external_url']) : null;

            /* PUBLISHED BUT NOWHERE VISIBLE.
             *
             * A video has exactly one home: the page of the destination it is
             * about. A published clip with no destination is on the server,
             * marked live, and reachable by nobody — which looks like success
             * from this screen unless it is said out loud. */
            $orphaned = $v['status'] === 'published' && empty($v['destination_id']);
            $playable = ($v['source'] === 'upload' && $v['file_path']) || $embed !== null;
            ?>
            <article class="video-card<?= $orphaned ? ' is-orphaned' : '' ?>" id="video<?= (int) $v['id'] ?>">

                <div class="video-card__thumb">
                    <?php if ($v['source'] === 'upload' && $v['file_path']): ?>
                        <?php /* preload="metadata" is what paints the first frame as a
                                 poster and what lets the script read the duration. It
                                 fetches a few kilobytes of header, not the film. */ ?>
                        <video preload="metadata" muted playsinline data-duration
                               <?= $v['poster_path'] ? 'poster="' . e(base_url('/' . $v['poster_path'])) . '"' : '' ?>>
                            <source src="<?= e(base_url('/' . $v['file_path'])) ?>#t=0.1"
                                    type="<?= e((string) ($v['mime_type'] ?: 'video/mp4')) ?>">
                        </video>
                    <?php elseif ($embed !== null): ?>
                        <?php /* No frame is fetched for a linked clip: the thumbnail
                                 lives on YouTube's servers, and asking for it would
                                 tell them which officer opened this page. */ ?>
                        <div class="video-card__linked">
                            <i class="fa-brands fa-youtube" aria-hidden="true"></i>
                            <span>Linked video</span>
                        </div>
                    <?php else: ?>
                        <div class="video-card__linked video-card__linked--broken">
                            <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                            <span>Cannot be played</span>
                        </div>
                    <?php endif; ?>

                    <span class="video-card__time" data-duration-for="<?= (int) $v['id'] ?>" hidden>
                        <i class="fa-solid fa-play" aria-hidden="true"></i> <span></span>
                    </span>

                    <span class="video-card__state video-card__state--<?= $v['status'] === 'published' ? 'live' : 'draft' ?>">
                        <?= e(Videos::STATUSES[$v['status']]) ?>
                    </span>
                </div>

                <div class="video-card__body">
                    <h3 class="video-card__title"><?= e((string) $v['title']) ?></h3>

                    <p class="video-card__meta">
                        <span>
                            <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                            <?= $v['destination_name']
                                ? e((string) $v['destination_name'])
                                : '<em>No destination</em>' ?>
                        </span>
                        <span aria-hidden="true">&middot;</span>
                        <span>
                            <i class="fa-solid fa-tag" aria-hidden="true"></i>
                            <?= e(Videos::CATEGORIES[$v['category']] ?? 'Video') ?>
                        </span>
                    </p>

                    <?php if ($orphaned): ?>
                        <p class="video-card__warn">
                            <i class="fa-solid fa-eye-slash" aria-hidden="true"></i>
                            Published, but no visitor can reach it &mdash; it has no destination.
                        </p>
                    <?php endif; ?>
                </div>

                <footer class="video-card__foot">
                    <p class="video-card__facts">
                        <span>
                            <i class="fa-regular fa-file" aria-hidden="true"></i>
                            <?= $v['source'] === 'upload' && $v['file_size']
                                ? e(number_format(((int) $v['file_size']) / 1048576, 1)) . ' MB'
                                : 'Link' ?>
                        </span>
                        <span aria-hidden="true">&middot;</span>
                        <span>
                            <i class="fa-regular fa-calendar" aria-hidden="true"></i>
                            <?= e(format_date((string) $v['created_at'], 'M j, Y')) ?>
                        </span>
                    </p>

                    <?php /* <details> rather than a scripted dropdown: Escape, click
                             elsewhere and keyboard focus are the browser's job, and
                             the menu still opens with the script blocked. */ ?>
                    <details class="kebab">
                        <summary aria-label="Actions for <?= e((string) $v['title']) ?>">
                            <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
                        </summary>

                        <div class="kebab__menu">
                            <?php if ($playable): ?>
                                <a class="kebab__item" target="_blank" rel="noopener"
                                   href="<?= e($v['source'] === 'upload'
                                        ? base_url('/' . $v['file_path'])
                                        : (string) $embed) ?>">
                                    <i class="fa-solid fa-play" aria-hidden="true"></i> Preview
                                </a>
                            <?php endif; ?>

                            <a class="kebab__item" href="index.php?<?= e(App\Core\Paginator::query([])) ?>&amp;edit=<?= (int) $v['id'] ?>">
                                <i class="fa-solid fa-pen" aria-hidden="true"></i> Edit
                            </a>

                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                                <?php if ($v['status'] === 'published'): ?>
                                    <button class="kebab__item" name="action" value="unpublish">
                                        <i class="fa-solid fa-eye-slash" aria-hidden="true"></i> Unpublish
                                    </button>
                                <?php else: ?>
                                    <button class="kebab__item" name="action" value="publish">
                                        <i class="fa-solid fa-check" aria-hidden="true"></i> Publish
                                    </button>
                                <?php endif; ?>
                            </form>

                            <form method="post"
                                  data-confirm="Delete &quot;<?= e((string) $v['title']) ?>&quot;? The file is removed from the server as well.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                                <button class="kebab__item kebab__item--danger" name="action" value="delete">
                                    <i class="fa-solid fa-trash" aria-hidden="true"></i> Delete
                                </button>
                            </form>
                        </div>
                    </details>
                </footer>
            </article>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/../../app/views/partials/pager.php'; ?>


<?php /* ===================================================================
         Add a video
         ===================================================================
         A dialog rather than a panel above the list. The form is eight fields
         and was the first thing on the screen every time, so the list this
         page exists for started below the fold.

         Native <dialog>: focus trapping, Escape and the backdrop are the
         browser's, the same as the confirmation dialog in admin.js. */ ?>
<dialog class="sheet" id="addVideo" aria-labelledby="addVideoTitle">
    <form method="post" enctype="multipart/form-data" class="sheet__form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">

        <header class="sheet__head">
            <h2 id="addVideoTitle"><i class="fa-solid fa-film" aria-hidden="true"></i> Add a video</h2>
            <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </header>

        <div class="sheet__body">
            <?php /* Two sources, chosen with a radio rather than guessed from
                     which field was filled in — a person who fills in both
                     should be told which one is being used, not have the
                     system pick. */ ?>
            <div class="btn-row mb-3" role="radiogroup" aria-label="Where the video comes from">
                <label class="video-source is-active">
                    <input type="radio" name="source" value="upload" checked>
                    <i class="fa-solid fa-upload" aria-hidden="true"></i>
                    <span><strong>Upload a file</strong><small>MP4, WebM or MOV &middot; up to <?= n(upload_limit_mb()) ?> MB</small></span>
                </label>
                <label class="video-source">
                    <input type="radio" name="source" value="external">
                    <i class="fa-brands fa-youtube" aria-hidden="true"></i>
                    <span><strong>Link to YouTube or Facebook</strong><small>For anything longer</small></span>
                </label>
            </div>

            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="title">Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="title" name="title" maxlength="160" required
                           placeholder="Jadas Falls — the trail in the wet season">
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="destination_id">About which destination?</label>
                    <select class="form-select" id="destination_id" name="destination_id">
                        <option value="">None — choose one, or it will not appear anywhere</option>
                        <?php foreach ($destinations as $d): ?>
                            <option value="<?= (int) $d['id'] ?>"><?= e((string) $d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="category">What kind of video?</label>
                    <select class="form-select" id="category" name="category">
                        <?php foreach (Videos::CATEGORIES as $key => $label): ?>
                            <option value="<?= e($key) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-text">Decides the heading it sits under.</p>
                </div>

                <div class="col-12" data-when="upload">
                    <label class="form-label" for="video">Video file</label>
                    <?php /* data-max-mb is enforced in the browser before a byte is sent.
                             Without it a 56MB file uploaded for a minute, was thrown
                             away by PHP before any code here ran, and came back as
                             "your session expired". */ ?>
                    <input type="file" class="form-control" id="video" name="video"
                           accept="video/mp4,video/webm,video/quicktime"
                           data-max-mb="<?= n(upload_limit_mb()) ?>">
                    <p class="form-text">
                        This server accepts <strong><?= n(upload_limit_mb()) ?> MB</strong> per upload
                        &mdash; roughly a minute at 1080p. Longer than that, paste a link instead.
                    </p>
                </div>

                <div class="col-12 d-none" data-when="external">
                    <label class="form-label" for="external_url">Video address</label>
                    <input type="url" class="form-control" id="external_url" name="external_url" maxlength="500"
                           placeholder="https://www.youtube.com/watch?v=...">
                </div>

                <div class="col-12">
                    <label class="form-label" for="caption">Caption <small class="text-muted">optional</small></label>
                    <input type="text" class="form-control" id="caption" name="caption" maxlength="600"
                           placeholder="Filmed August 2026 by the Municipal Tourism Office">
                </div>
            </div>
        </div>

        <footer class="sheet__foot">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close>Cancel</button>
            <button type="submit" class="btn btn-sm btn-brand">
                <i class="fa-solid fa-plus" aria-hidden="true"></i> Add video
            </button>
        </footer>
    </form>
</dialog>


<?php if ($edit !== null): ?>
    <?php /* One edit dialog, for the row ?edit= named — not one per card. It
             opens on load, so the Edit link still works with the script
             blocked (the page simply shows the dialog inline). */ ?>
    <dialog class="sheet" id="editVideo" aria-labelledby="editVideoTitle" data-open>
        <form method="post" class="sheet__form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">

            <header class="sheet__head">
                <h2 id="editVideoTitle"><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit video</h2>
                <a class="sheet__close" href="index.php?<?= e(App\Core\Paginator::query(['edit'])) ?>" aria-label="Close">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </a>
            </header>

            <div class="sheet__body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" for="edit_title">Title</label>
                        <input type="text" class="form-control" id="edit_title" name="title"
                               maxlength="160" value="<?= e((string) $edit['title']) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="edit_caption">Caption</label>
                        <input type="text" class="form-control" id="edit_caption" name="caption"
                               maxlength="600" value="<?= e((string) ($edit['caption'] ?? '')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="edit_destination">Destination</label>
                        <select class="form-select" id="edit_destination" name="destination_id">
                            <option value="">None — choose one, or it will not appear anywhere</option>
                            <?php foreach ($destinations as $d): ?>
                                <option value="<?= (int) $d['id'] ?>"
                                    <?= (int) $edit['destination_id'] === (int) $d['id'] ? 'selected' : '' ?>>
                                    <?= e((string) $d['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="edit_category">Kind</label>
                        <select class="form-select" id="edit_category" name="category">
                            <?php foreach (Videos::CATEGORIES as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= $edit['category'] === $key ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="edit_status">Status</label>
                        <select class="form-select" id="edit_status" name="status">
                            <?php foreach (Videos::STATUSES as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= $edit['status'] === $key ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="edit_order">Order</label>
                        <input type="number" class="form-control" id="edit_order" name="sort_order"
                               min="0" max="999" value="<?= (int) $edit['sort_order'] ?>">
                        <p class="form-text">Lowest number leads the destination's page.</p>
                    </div>
                </div>
            </div>

            <footer class="sheet__foot">
                <a class="btn btn-sm btn-outline-secondary"
                   href="index.php?<?= e(App\Core\Paginator::query(['edit'])) ?>">Cancel</a>
                <button type="submit" class="btn btn-sm btn-brand">
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save changes
                </button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>

<?php
$pageScripts = <<<'HTML'
<script>
(function () {
    /* ---- Which source fields to show ----------------------------------- */
    var paint = function () {
        var chosen = document.querySelector('input[name="source"]:checked');
        var want   = chosen ? chosen.value : 'upload';

        document.querySelectorAll('[data-when]').forEach(function (block) {
            block.classList.toggle('d-none', block.dataset.when !== want);
        });

        document.querySelectorAll('.video-source').forEach(function (label) {
            var input = label.querySelector('input');
            label.classList.toggle('is-active', !!input && input.checked);
        });
    };

    document.querySelectorAll('input[name="source"]').forEach(function (r) {
        r.addEventListener('change', paint);
    });

    paint();

    /* Opening and closing a sheet is admin.js's job now that three screens
       have one. Only the parts peculiar to videos are left here. */

    /* ---- Durations ------------------------------------------------------
       Read from the file the browser already fetched the header of, rather
       than stored in the database. Nothing is asked of the server that the
       poster frame did not already ask for. */
    document.querySelectorAll('video[data-duration]').forEach(function (video) {
        video.addEventListener('loadedmetadata', function () {
            var total = video.duration;

            if (!isFinite(total) || total <= 0) { return; }

            var badge = video.closest('.video-card__thumb').querySelector('[data-duration-for]');
            if (!badge) { return; }

            var mins = Math.floor(total / 60);
            var secs = Math.floor(total % 60);

            badge.querySelector('span').textContent = mins + ':' + (secs < 10 ? '0' : '') + secs;
            badge.hidden = false;
        });
    });
})();
</script>
HTML;

require __DIR__ . '/../_partials/foot.php';
