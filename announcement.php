<?php
declare(strict_types=1);

/**
 * TourSync — a single public announcement.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Repositories\AnnouncementRepository;

$slug = trim((string) ($_GET['slug'] ?? ''));
$a    = $slug !== '' ? AnnouncementRepository::findBySlug($slug) : null;

if ($a === null) {
    http_response_code(404);
}

$style = $a !== null
    ? (AnnouncementRepository::TYPE_STYLE[$a['type']] ?? ['icon' => 'fa-bullhorn', 'tone' => 'blue'])
    : ['icon' => 'fa-bullhorn', 'tone' => 'blue'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $a === null ? 'Announcement Not Found' : e($a['title']) ?> — Tampakan Tourism</title>
<?php if ($a !== null): ?>
    <meta name="description" content="<?= e($a['summary'] ?: mb_substr(strip_tags($a['body']), 0, 155)) ?>">
<?php else: ?>
    <meta name="robots" content="noindex">
<?php endif; ?>
<link rel="icon" href="assets/img/tampakan_logo.jpg" sizes="any">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body id="top">

<?php
/* No navbar, matching the destination and map pages — the gradient banner
   below is this page's header and does not need a second one above it.
 *
 * The not-found branch keeps its navbar, for the same reason destination.php
 * does: that branch has no banner at all, so dropping the header too would
 * leave somebody who followed an expired advisory on a bare white page with a
 * single button on it. */
$showNavbar = ($a === null);
require __DIR__ . '/app/views/partials/public-nav.php';
?>

<main>
<?php if ($a === null): ?>

    <section class="section section--light">
        <div class="container">
            <div class="empty-public">
                <i class="fa-solid fa-bullhorn"></i>
                <h1>That announcement could not be found</h1>
                <p>It may have expired or been withdrawn by the Tourism Office.</p>
                <p class="mt-3">
                    <a href="<?= e(announcements_url()) ?>" class="btn btn-primary-grad">
                        <i class="fa-solid fa-list"></i> All announcements
                    </a>
                </p>
            </div>
        </div>
    </section>

<?php else: ?>

    <header class="page-head page-head--top">
        <div class="container">
            <nav aria-label="Breadcrumb" class="crumbs">
                <a href="<?= e(base_url('/')) ?>">Home</a>
                <i class="fa-solid fa-angle-right"></i>
                <a href="<?= e(announcements_url()) ?>">Announcements</a>
                <i class="fa-solid fa-angle-right"></i>
                <span><?= e(AnnouncementRepository::TYPES[$a['type']]) ?></span>
            </nav>
            <h1><?= e($a['title']) ?></h1>
            <p>
                <i class="fa-regular fa-calendar"></i>
                <?= e(format_date($a['publish_at'] ?: $a['created_at'], 'F j, Y')) ?>
            </p>
        </div>
    </header>

    <section class="section section--light">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8">

                    <div class="notice-badge notice-badge--<?= e($style['tone']) ?>">
                        <i class="fa-solid <?= e($style['icon']) ?>"></i>
                        <?= e(AnnouncementRepository::TYPES[$a['type']]) ?>
                    </div>

                    <?php if ($a['summary']): ?>
                        <p class="dest-lead"><?= e($a['summary']) ?></p>
                    <?php endif; ?>

                    <div class="dest-prose"><?= nl2br(e($a['body'])) ?></div>

                    <?php if ($a['event_date'] || $a['event_location'] || $a['destination_name']): ?>
                        <div class="notice-details">
                            <h3><i class="fa-solid fa-circle-info"></i> Details</h3>
                            <dl>
                                <?php if ($a['event_date']): ?>
                                    <dt><i class="fa-regular fa-calendar"></i> Date</dt>
                                    <dd><?= e(format_date($a['event_date'], 'l, F j, Y')) ?></dd>
                                <?php endif; ?>
                                <?php if ($a['event_location']): ?>
                                    <dt><i class="fa-solid fa-location-dot"></i> Location</dt>
                                    <dd><?= e($a['event_location']) ?></dd>
                                <?php endif; ?>
                                <?php if ($a['destination_name']): ?>
                                    <dt><i class="fa-solid fa-mountain-sun"></i> Destination</dt>
                                    <dd>
                                        <a href="<?= e(base_url('/destination.php?slug=' . $a['destination_slug'])) ?>">
                                            <?= e($a['destination_name']) ?>
                                        </a>
                                    </dd>
                                <?php endif; ?>
                            </dl>
                        </div>
                    <?php endif; ?>

                    <p class="mt-4">
                        <a href="<?= e(announcements_url()) ?>" class="link-more">
                            <i class="fa-solid fa-arrow-left-long"></i> All announcements
                        </a>
                    </p>
                </div>
            </div>
        </div>
    </section>

<?php endif; ?>
</main>

<?php require __DIR__ . '/app/views/partials/public-footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(asset('js/script.js')) ?>"></script>
</body>
</html>
