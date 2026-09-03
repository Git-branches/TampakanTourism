<?php
declare(strict_types=1);

/**
 * TourSync — a single public event.
 *
 * The twin of announcement.php, and deliberately its twin rather than a second
 * implementation: same layout, same header partial, same footer, same
 * assistant. A visitor moving between a closure notice and a fiesta should not
 * feel they have changed website.
 *
 * WHY IT EXISTS AT ALL. Every event's "Learn More" used to land on
 * announcement.php, so a festival opened on a page whose heading, breadcrumb
 * and back-link all said Announcements — a visitor who came for the fiesta was
 * told they were reading a notice, and the way back led to the wrong section.
 *
 * The RECORD is unchanged: one table, one status workflow, one composer. What
 * differs is which page presents it, decided by its type. Each record therefore
 * has exactly one public address, and each page redirects to the other rather
 * than showing something that is not its business — so old links, printed or
 * bookmarked, keep working.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Repositories\AnnouncementRepository;

$slug = trim((string) ($_GET['slug'] ?? ''));
$a    = $slug !== '' ? AnnouncementRepository::findBySlug($slug) : null;

/* A notice reached through an event link belongs on the other page. Redirected
   rather than rendered here, so there is one address per record and no two
   URLs showing the same thing under different headings. */
if ($a !== null && !AnnouncementRepository::isEventType((string) $a['type'])) {
    redirect(base_url('/announcement.php?slug=' . urlencode($slug)));
}

if ($a === null) {
    http_response_code(404);
}

$style = $a !== null
    ? (AnnouncementRepository::TYPE_STYLE[$a['type']] ?? ['icon' => 'fa-calendar-day', 'tone' => 'green'])
    : ['icon' => 'fa-calendar-day', 'tone' => 'green'];

/* An event whose date has gone is still worth reading — somebody may have kept
   the link — but it should say so rather than read as an invitation. */
$isPast = $a !== null && $a['event_date'] !== null && $a['event_date'] < date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $a === null ? 'Event Not Found' : e($a['title']) ?> &mdash; Tampakan Tourism</title>
<?php if ($a !== null): ?>
    <meta name="description" content="<?= e($a['summary'] ?: mb_substr(strip_tags($a['body']), 0, 155)) ?>">
<?php else: ?>
    <meta name="robots" content="noindex">
<?php endif; ?>
<link rel="icon" href="assets/img/tampakan_logo.png" sizes="any">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body id="top">

<?php
/* Navbar on, found or not. Somebody who has just read that the fiesta is next
   Saturday is precisely the person who then wants the map or a guide. */
$showNavbar = true;
require __DIR__ . '/app/views/partials/public-nav.php';
?>

<main>
<?php if ($a === null): ?>

    <section class="section section--light">
        <div class="container">
            <div class="empty-public">
                <i class="fa-solid fa-calendar-day"></i>
                <h1>That event could not be found</h1>
                <p>It may have finished, or been withdrawn by the Tourism Office.</p>
                <p class="mt-3">
                    <a href="<?= e(events_url()) ?>" class="btn btn-primary-grad">
                        <i class="fa-solid fa-calendar-days"></i> All upcoming events
                    </a>
                </p>
            </div>
        </div>
    </section>

<?php else: ?>

    <?php
    /* The shared header, same as the map, the tour guide page and an
       announcement. The breadcrumb leads back to Events, which is the section
       this record actually lives in. */
    $head = [
        'title'  => (string) $a['title'],
        'icon'   => 'fa-solid ' . $style['icon'],
        'sub'    => $a['event_date']
            ? format_date($a['event_date'], 'l, F j, Y')
            : format_date($a['publish_at'] ?: $a['created_at'], 'F j, Y'),
        'crumbs' => [
            ['label' => 'Home',   'href' => base_url('/')],
            ['label' => 'Events', 'href' => events_url()],
            ['label' => AnnouncementRepository::TYPES[$a['type']]],
        ],
    ];
    require __DIR__ . '/app/views/partials/page-head.php';
    ?>

    <section class="section section--light">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8">

                    <?php if ($isPast): ?>
                        <div class="alert alert-warning">
                            <i class="fa-regular fa-calendar-xmark"></i>
                            <strong>This event has already taken place.</strong>
                            It is kept here for reference. See
                            <a href="<?= e(events_url()) ?>" class="alert-link">what is coming up next</a>.
                        </div>
                    <?php endif; ?>

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
                        <a href="<?= e(events_url()) ?>" class="link-more">
                            <i class="fa-solid fa-arrow-left-long"></i> All upcoming events
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

<?php require __DIR__ . '/app/views/partials/chat-widget.php'; ?>

</body>
</html>