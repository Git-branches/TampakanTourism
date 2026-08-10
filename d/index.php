<?php
declare(strict_types=1);

/**
 * =============================================================================
 *  TourSync — QR code resolver          Feature 1 / Problem 1
 * -----------------------------------------------------------------------------
 *  Reached by scanning the sign at a destination: /d/{token}
 *
 *  This is the same information a browser visitor sees at destination.php, but
 *  it means something different. Arriving here implies physical presence, so
 *  the digital logbook is the primary action. On destination.php it is absent
 *  entirely — offering it there would invite arrival records from people
 *  sitting at home, corrupting the very statistic the system exists to
 *  produce.
 *
 *  The tourist never selects a destination. The token does it.
 * =============================================================================
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Repositories\DestinationRepository;

$token = (string) ($_GET['token'] ?? '');
$d     = DestinationRepository::findByQrToken($token);

/* An unknown token means a retired code, a damaged scan, or a fabricated URL.
   All three get the same honest page rather than a raw 404. */
if ($d === null) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>Code Not Recognised — Tampakan Tourism</title>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
        <link rel="stylesheet" href="<?= e(asset('css/logbook.css')) ?>">
    </head>
    <body class="lb-body">
        <main class="lb-shell">
            <div class="lb-card lb-card--center">
                <div class="lb-icon lb-icon--warn"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <h1>This code is not recognised</h1>
                <p>
                    It may have been replaced with a newer sign, or the destination may have
                    been temporarily closed by the Tourism Office.
                </p>
                <p class="lb-muted">
                    If the sign looks damaged or tampered with, please report it to the
                    Municipal Tourism Office.
                </p>
                <a href="<?= e(destinations_url()) ?>" class="lb-btn lb-btn--primary">
                    <i class="fa-solid fa-compass"></i> Browse all destinations
                </a>
            </div>
        </main>
    </body>
    </html>
    <?php
    exit;
}

$photos     = DestinationRepository::photos((int) $d['id']);
$facilities = DestinationRepository::decodeFacilities($d['facilities']);
$cover      = $photos[0]['file_path'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($d['name']) ?> — Tampakan Tourism</title>
<meta name="theme-color" content="#2E7D32">
<link rel="icon" href="<?= e(asset('img/tampakan_logo.jpg')) ?>" sizes="any">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/logbook.css')) ?>">
</head>
<body class="lb-body">

<!-- Official identification: a visitor should be able to confirm at a glance
     that they landed on the municipality's own page and not a copy. -->
<header class="lb-gov">
    <img src="<?= e(asset('img/tampakan_logo.jpg')) ?>" alt="Seal of the Municipality of Tampakan" width="34" height="34">
    <div>
        <strong>Municipal Tourism Office</strong>
        <span>Municipality of Tampakan, South Cotabato</span>
    </div>
</header>

<main class="lb-shell">

    <?php if ($cover): ?>
        <div class="lb-hero" style="background-image:url('<?= e(base_url($cover)) ?>')">
            <div class="lb-hero__scrim"></div>
            <div class="lb-hero__text">
                <?php if ($d['category_name']): ?>
                    <span class="lb-chip"><?= e($d['category_name']) ?></span>
                <?php endif; ?>
                <h1><?= e($d['name']) ?></h1>
            </div>
        </div>
    <?php else: ?>
        <div class="lb-card lb-card--head">
            <?php if ($d['category_name']): ?>
                <span class="lb-chip lb-chip--solid"><?= e($d['category_name']) ?></span>
            <?php endif; ?>
            <h1><?= e($d['name']) ?></h1>
        </div>
    <?php endif; ?>

    <!-- The primary action. Placed above the description on purpose: the
         visitor is standing at the site and reading on a phone, so the thing
         we are asking them to do must not be below a scroll. -->
    <div class="lb-card lb-cta">
        <p class="lb-cta__lead">You are at <strong><?= e($d['name']) ?></strong>.</p>
        <p class="lb-cta__sub">Logging your visit takes under a minute and helps the Municipality plan for visitors.</p>
        <a href="<?= e(base_url('/logbook.php?token=' . $d['qr_token'])) ?>" class="lb-btn lb-btn--primary lb-btn--lg">
            <i class="fa-solid fa-pen-to-square"></i> Log My Visit
        </a>
    </div>

    <?php if ($d['barangay'] || $d['operating_hours'] || $d['entrance_fee']): ?>
        <div class="lb-card">
            <h2 class="lb-h2"><i class="fa-solid fa-circle-info"></i> Visitor Information</h2>
            <dl class="lb-facts">
                <?php if ($d['barangay']): ?>
                    <div><dt><i class="fa-solid fa-location-dot"></i> Location</dt>
                         <dd>Barangay <?= e($d['barangay']) ?>, Tampakan</dd></div>
                <?php endif; ?>
                <?php if ($d['operating_hours']): ?>
                    <div><dt><i class="fa-regular fa-clock"></i> Open</dt>
                         <dd><?= e($d['operating_hours']) ?></dd></div>
                <?php endif; ?>
                <?php if ($d['entrance_fee']): ?>
                    <div><dt><i class="fa-solid fa-ticket"></i> Entrance</dt>
                         <dd><?= e($d['entrance_fee']) ?></dd></div>
                <?php endif; ?>
                <?php if ($d['contact_person']): ?>
                    <div><dt><i class="fa-solid fa-user"></i> Contact person</dt>
                         <dd><?= e($d['contact_person']) ?></dd></div>
                <?php endif; ?>
                <?php if ($d['contact_phone']): ?>
                    <div><dt><i class="fa-solid fa-phone"></i> Contact</dt>
                         <dd><a href="tel:<?= e(preg_replace('/\D/', '', $d['contact_phone'])) ?>"><?= e($d['contact_phone']) ?></a></dd></div>
                <?php endif; ?>
            </dl>
        </div>
    <?php endif; ?>

    <?php if ($d['reminders']): ?>
        <div class="lb-card lb-card--warn">
            <h2 class="lb-h2"><i class="fa-solid fa-triangle-exclamation"></i> Please Note</h2>
            <p><?= nl2br(e($d['reminders'])) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($d['description']): ?>
        <div class="lb-card">
            <h2 class="lb-h2"><i class="fa-solid fa-mountain-sun"></i> About</h2>
            <p class="lb-prose"><?= nl2br(e($d['description'])) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($d['history']): ?>
        <div class="lb-card">
            <h2 class="lb-h2"><i class="fa-solid fa-scroll"></i> History</h2>
            <p class="lb-prose"><?= nl2br(e($d['history'])) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($facilities !== []): ?>
        <div class="lb-card">
            <h2 class="lb-h2"><i class="fa-solid fa-list-check"></i> Facilities</h2>
            <ul class="lb-facilities">
                <?php foreach ($facilities as $f): ?>
                    <li><i class="fa-solid fa-check"></i><?= e((string) $f) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (count($photos) > 1): ?>
        <div class="lb-card">
            <h2 class="lb-h2"><i class="fa-regular fa-images"></i> Photos</h2>
            <div class="lb-gallery">
                <?php foreach (array_slice($photos, 0, 6) as $photo): ?>
                    <img src="<?= e(base_url($photo['file_path'])) ?>"
                         alt="<?= e($photo['caption'] ?: $d['name']) ?>" loading="lazy">
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($d['latitude'] !== null && $d['longitude'] !== null): ?>
        <div class="lb-card">
            <h2 class="lb-h2"><i class="fa-solid fa-map-location-dot"></i> Getting Around</h2>
            <a class="lb-btn lb-btn--ghost"
               href="https://www.google.com/maps/dir/?api=1&destination=<?= e((string) $d['latitude']) ?>,<?= e((string) $d['longitude']) ?>"
               target="_blank" rel="noopener">
                <i class="fa-solid fa-diamond-turn-right"></i> Open in Maps
            </a>
        </div>
    <?php endif; ?>

    <!-- Repeated at the foot: a visitor who read to the bottom should not have
         to scroll back up to act. -->
    <div class="lb-card lb-cta">
        <a href="<?= e(base_url('/logbook.php?token=' . $d['qr_token'])) ?>" class="lb-btn lb-btn--primary lb-btn--lg">
            <i class="fa-solid fa-pen-to-square"></i> Log My Visit
        </a>
    </div>

    <footer class="lb-foot">
        <p>&copy; <?= date('Y') ?> Municipality of Tampakan, South Cotabato</p>
        <p><a href="<?= e(base_url('/')) ?>">Tampakan Tourism Portal</a></p>
    </footer>
</main>

</body>
</html>
