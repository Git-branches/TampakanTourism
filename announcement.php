<?php
declare(strict_types=1);

/**
 * TourSync — a single public announcement.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Repositories\AnnouncementRepository;

$slug = trim((string) ($_GET['slug'] ?? ''));
$a    = $slug !== '' ? AnnouncementRepository::findBySlug($slug) : null;

/* AN EVENT BELONGS ON events.php.
 *
 * Every event's "Learn More" used to land here, so a festival opened on a page
 * whose heading, breadcrumb and back-link all said Announcements: the visitor
 * came for the fiesta and was told they were reading a notice, with the way
 * back leading to the wrong section.
 *
 * Redirected rather than rendered, so a record has ONE public address — and so
 * every link already printed, bookmarked or sent in a message still arrives
 * where it should. */
if ($a !== null && AnnouncementRepository::isEventType((string) $a['type'])) {
    redirect(base_url('/events.php?slug=' . urlencode($slug)));
}

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
<link rel="icon" href="<?= e(asset('img/tourism-logo-mark.png')) ?>" type="image/png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body id="top">

<?php
/* NAVBAR ON, found or not.
 *
 * It was previously shown only on the not-found branch, which meant a reader
 * who reached a real announcement lost every route out of it. Somebody who has
 * just read that a trail is closed is precisely the person who then wants the
 * map or the destination list. */
$showNavbar = true;
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

    <?php
    /* The shared header, same as the map and the tour guide page. */
    $head = [
        'title'  => (string) $a['title'],
        'icon'   => 'fa-regular fa-calendar',
        'sub'    => format_date($a['publish_at'] ?: $a['created_at'], 'F j, Y'),
        'crumbs' => [
            ['label' => 'Home',          'href' => base_url('/')],
            ['label' => 'Announcements', 'href' => announcements_url()],
            ['label' => AnnouncementRepository::TYPES[$a['type']]],
        ],
    ];
    require __DIR__ . '/app/views/partials/page-head.php';
    ?>

    <section class="section section--light">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8">

                    <div class="notice-badge notice-badge--<?= e($style['tone']) ?>">
                        <i class="fa-solid <?= e($style['icon']) ?>"></i>
                        <?= e(AnnouncementRepository::TYPES[$a['type']]) ?>
                    </div>

                    <?php /* THE PICTURE THE OFFICE ATTACHED.
                             Same omission as the event page had: banner_path was
                             on the row and editable in the admin, and this page
                             printed the summary and the body and threw the
                             picture away. An officer could photograph a washed
                             out footbridge, attach it to the closure notice, and
                             the one thing that showed the reader what "closed"
                             actually meant never reached them.

                             Nothing is drawn when there is none. A notice is
                             words first; the picture is evidence when it exists
                             and an empty grey box when it does not. */ ?>
                    <?php if (!empty($a['banner_path'])): ?>
                        <figure class="event-poster">
                            <img src="<?= e(base_url($a['banner_path'])) ?>"
                                 alt="Photograph attached to <?= e($a['title']) ?>"
                                 loading="lazy">
                        </figure>
                    <?php endif; ?>

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
<script src="<?= e(asset('js/vendor/sweetalert2.all.min.js')) ?>"></script>
<script src="<?= e(asset('js/notify.js')) ?>"></script>
<script src="<?= e(asset('js/script.js')) ?>"></script>

<!-- =========================================================================
     THE TOURISM ASSISTANT
     Every public page carries it. A visitor reading an advisory, planning a
     route or filling in a guide request has the same questions as one on the
     home page, and should not have to go back to ask them.
     ====================================================================== -->
<?php require __DIR__ . '/app/views/partials/chat-widget.php'; ?>

</body>
</html>
