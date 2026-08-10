<?php
declare(strict_types=1);

/**
 * TourSync — page not found.
 *
 * A visitor who mistypes a URL, or scans a sign whose destination has since
 * been archived, should land somewhere that helps rather than on an Apache
 * default page that names the server software.
 */

require_once __DIR__ . '/bootstrap.php';

http_response_code(404);

use App\Repositories\DestinationRepository;

$suggestions = DestinationRepository::published([], 3);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Page Not Found — Tampakan Tourism</title>
<link rel="icon" href="<?= e(base_url('assets/img/tampakan_logo.jpg')) ?>" sizes="any">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>">
</head>
<body id="top">

<?php require __DIR__ . '/app/views/partials/public-nav.php'; ?>

<main>
<section class="section section--light">
    <div class="container">
        <div class="empty-public">
            <i class="fa-solid fa-compass"></i>
            <h1>That page could not be found</h1>
            <p>
                The link may be out of date, or the page may have been withdrawn by the
                Municipal Tourism Office.
            </p>
            <p class="mt-3">
                <a href="<?= e(base_url('/')) ?>" class="btn btn-primary-grad">
                    <i class="fa-solid fa-house"></i> Return home
                </a>
                <a href="<?= e(destinations_url()) ?>" class="btn btn-outline-brand">
                    <i class="fa-solid fa-map-location-dot"></i> Browse destinations
                </a>
            </p>
        </div>

        <?php if ($suggestions !== []): ?>
            <h2 class="dest-h2 text-center mt-5">While you are here</h2>
            <div class="row g-4 justify-content-center">
                <?php foreach ($suggestions as $d): ?>
                    <div class="col-lg-4 col-md-6">
                        <article class="dest-card">
                            <div class="dest-card__media">
                                <img src="<?= e($d['cover_photo'] ? base_url($d['cover_photo']) : img('1464822759023-fed622ff2c3b')) ?>"
                                     alt="<?= e($d['name']) ?>" loading="lazy" width="1200" height="800">
                            </div>
                            <div class="dest-card__body">
                                <h3 class="dest-card__title"><?= e($d['name']) ?></h3>
                                <p class="dest-card__text"><?= e((string) $d['short_description']) ?></p>
                                <a href="<?= e(base_url('/destination.php?slug=' . $d['slug'])) ?>" class="link-more">
                                    View Details <i class="fa-solid fa-arrow-right-long"></i>
                                </a>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
</main>

<?php require __DIR__ . '/app/views/partials/public-footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
