<?php
declare(strict_types=1);

/**
 * =============================================================================
 *  TourSync — Public destination detail page
 * -----------------------------------------------------------------------------
 *  Reached by browsing the website. In Phase 2 the QR resolver at /d/{token}
 *  renders the same content through a shared partial but promotes the digital
 *  logbook to the primary action.
 *
 *  The two stay separate on purpose: arriving here means the visitor is
 *  browsing, and offering a logbook would invite arrival records from people
 *  sitting at home — corrupting the statistic the system exists to produce.
 * =============================================================================
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Session;
use App\Core\Weather;
use App\Repositories\DestinationRepository;
use App\Repositories\FeedbackRepository;

$slug = trim((string) ($_GET['slug'] ?? ''));
$d    = $slug !== '' ? DestinationRepository::findBySlug($slug) : null;

if ($d === null) {
    http_response_code(404);
    $notFound = true;
} else {
    $notFound = false;
    $photos     = DestinationRepository::photos((int) $d['id']);
    $facilities = DestinationRepository::decodeFacilities($d['facilities']);
    /* Resolved through uploaded_url() so a photo row whose file has gone
       missing leaves the hero on its solid green fallback instead of a broken
       background and an og:image nobody can load. */
    $cover      = uploaded_url($photos[0]['file_path'] ?? null);

    /* One municipal forecast, shared by every destination.
     *
     * Per-destination coordinates were tried first and were a mistake: each
     * destination became its own cache entry and its own cold API call, adding
     * four seconds to the page. It also implied a precision that does not
     * exist — Open-Meteo's grid is around 11 km, so destinations a few
     * kilometres apart resolve to the same cell and return identical readings.
     * One call per hour now serves the whole site. */
    $weather = Weather::forecast();
    $weatherPlace = 'Tampakan, South Cotabato';

    $reviews       = FeedbackRepository::publishedFor((int) $d['id'], 20);
    $ratingSummary = FeedbackRepository::summaryFor((int) $d['id']);
    $flashes       = Session::takeFlash();

    /* The gallery starts after the cover.
     *
     * The hero is already showing $photos[0] full-bleed, so repeating it as the
     * first thumbnail shows the visitor the picture they are looking at. It
     * only stays in when the cover resolved to null — a missing file leaves the
     * hero on solid green, and then photo zero has never been seen.
     *
     * Every path goes through uploaded_url() for the same reason the cover
     * does: a row whose file has been deleted from disk would otherwise render
     * as a broken-image icon in the middle of the gallery. */
    $galleryPhotos = [];
    foreach ($photos as $i => $photo) {
        if ($i === 0 && $cover !== null) {
            continue;
        }
        $url = uploaded_url($photo['file_path'] ?? null);
        if ($url !== null) {
            $galleryPhotos[] = ['url' => $url, 'caption' => (string) ($photo['caption'] ?? '')];
        }
    }

    /* Guarded on the category.
     *
     * buildWhere() drops a falsy category_id rather than matching on NULL, so
     * an uncategorised destination asked for "related" spots and got the first
     * three active destinations in the database — arbitrary, and under a
     * heading with a hole where the category name should be. No category means
     * no relation to draw, so the section does not run. */
    $related = [];
    if (!empty($d['category_id'])) {
        $related = array_slice(array_filter(
            DestinationRepository::published(['category_id' => $d['category_id']], 4),
            static fn(array $r): bool => (int) $r['id'] !== (int) $d['id']
        ), 0, 3);
    }

    /* Each of these guards a whole region of the page, and each region is built
       entirely from optional columns — so any of them can turn out to have
       nothing to show for a thinly-filled destination. */
    $hasFacts = $ratingSummary['total'] > 0
        || $d['operating_hours']
        || $d['entrance_fee']
        || $weather !== null
        || ($d['latitude'] !== null && $d['longitude'] !== null);

    $hasStory = $d['short_description']
        || $d['description']
        || $d['history']
        || $facilities !== []
        || $d['reminders']
        || $galleryPhotos !== [];

    $hasContacts = $d['contact_person'] || $d['contact_phone'] || $d['contact_email'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $notFound ? 'Destination Not Found' : e($d['name']) ?> — Tampakan Tourism</title>
<?php if (!$notFound): ?>
    <meta name="description" content="<?= e($d['short_description'] ?: $d['name'] . ', a tourist destination in Tampakan, South Cotabato.') ?>">
    <meta property="og:title" content="<?= e($d['name']) ?> — Tampakan Tourism">
    <meta property="og:description" content="<?= e((string) $d['short_description']) ?>">
    <?php if ($cover): ?><meta property="og:image" content="<?= e($cover) ?>"><?php endif; ?>
<?php else: ?>
    <meta name="robots" content="noindex">
<?php endif; ?>
<link rel="icon" href="assets/img/tampakan_logo.png" sizes="any">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body id="top">

<?php
/* No navbar on a spot page.
 *
 * The cover photograph is the whole point of this page, and a solid white bar
 * sitting above it cropped the top off every one of them. Without it the
 * photograph runs to the top of the viewport and the destination gets the
 * full-bleed treatment it deserves.
 *
 * Wayfinding moves to the breadcrumb, which is why it is styled up rather than
 * left as faint grey text — on this page it is the only way back into the site
 * until the visitor reaches the footer.
 *
 * The not-found branch keeps its navbar. That justification is entirely about
 * the photograph, and there is no photograph there — dropping the header as
 * well would leave somebody who followed a stale link on a bare white page
 * with one button on it. */
$showNavbar = $notFound;
require __DIR__ . '/app/views/partials/public-nav.php';
?>

<main>
<?php if ($notFound): ?>

    <section class="section section--light">
        <div class="container">
            <div class="empty-public">
                <i class="fa-solid fa-map-location-dot"></i>
                <h1>That destination could not be found</h1>
                <p>It may have been archived by the Tourism Office, or the link may be out of date.</p>
                <p class="mt-3">
                    <a href="<?= e(destinations_url()) ?>" class="btn btn-primary-grad">
                        <i class="fa-solid fa-compass"></i> Browse all destinations
                    </a>
                </p>
            </div>
        </div>
    </section>

<?php else: ?>

    <!-- ===================== HERO ===================== -->
    <header class="dest-hero" <?= $cover ? 'style="background-image:url(\'' . e($cover) . '\')"' : '' ?>>
        <div class="dest-hero__overlay"></div>
        <div class="container dest-hero__inner">
            <nav aria-label="Breadcrumb" class="crumbs crumbs--light">
                <a href="<?= e(base_url('/')) ?>">Home</a>
                <i class="fa-solid fa-angle-right"></i>
                <a href="<?= e(destinations_url()) ?>">Destinations</a>
                <i class="fa-solid fa-angle-right"></i>
                <span><?= e($d['name']) ?></span>
            </nav>

            <?php if ($d['category_name']): ?>
                <span class="dest-hero__badge">
                    <?php if ($d['category_icon']): ?><i class="fa-solid <?= e($d['category_icon']) ?>"></i><?php endif; ?>
                    <?= e($d['category_name']) ?>
                </span>
            <?php endif; ?>

            <h1><?= e($d['name']) ?></h1>

            <?php if ($d['barangay'] || $d['address']): ?>
                <p class="dest-hero__place">
                    <i class="fa-solid fa-location-dot"></i>
                    <?= e(implode(', ', array_filter([
                        $d['barangay'] ? 'Barangay ' . $d['barangay'] : null,
                        $d['address'] ?: null,
                        'Tampakan, South Cotabato',
                    ]))) ?>
                </p>
            <?php endif; ?>
        </div>
    </header>

    <!-- Everything a visitor checks before deciding to go, above the fold and
         readable at a glance. This strip is the only place the hours, the fee
         and the weather are stated — the sidebar used to repeat all three
         within the same screen, so it now carries contacts alone. -->
    <?php if ($hasFacts): ?>
    <div class="dest-facts">
        <div class="container">
            <div class="dest-facts__row">
                <?php if ($ratingSummary['total'] > 0): ?>
                    <div class="dest-fact">
                        <i class="fa-solid fa-star"></i>
                        <div>
                            <strong><?= e((string) $ratingSummary['average']) ?> / 5</strong>
                            <span><?= n($ratingSummary['total']) ?> visitor review<?= $ratingSummary['total'] === 1 ? '' : 's' ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($d['operating_hours']): ?>
                    <div class="dest-fact">
                        <i class="fa-regular fa-clock"></i>
                        <div><strong><?= e($d['operating_hours']) ?></strong><span>Operating hours</span></div>
                    </div>
                <?php endif; ?>

                <?php if ($d['entrance_fee']): ?>
                    <div class="dest-fact">
                        <i class="fa-solid fa-ticket"></i>
                        <div><strong><?= e($d['entrance_fee']) ?></strong><span>Entrance</span></div>
                    </div>
                <?php endif; ?>

                <?php if ($weather !== null): ?>
                    <div class="dest-fact">
                        <i class="fa-solid <?= e($weather['icon']) ?>"></i>
                        <div>
                            <strong><?= (int) $weather['temperature'] ?>&deg;C</strong>
                            <span><?= e($weather['label']) ?> now</span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($d['latitude'] !== null && $d['longitude'] !== null): ?>
                    <a class="dest-fact dest-fact--action"
                       href="https://www.google.com/maps/dir/?api=1&destination=<?= e((string) $d['latitude']) ?>,<?= e((string) $d['longitude']) ?>"
                       target="_blank" rel="noopener">
                        <i class="fa-solid fa-diamond-turn-right"></i>
                        <div><strong>Get Directions</strong><span>Open in Maps</span></div>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <section class="section section--light">
        <div class="container">
            <div class="row g-5">

                <!-- ===================== MAIN COLUMN ===================== -->
                <div class="col-lg-8">

                    <?php if ($d['short_description']): ?>
                        <p class="dest-lead"><?= e($d['short_description']) ?></p>
                    <?php endif; ?>

                    <?php if ($d['description']): ?>
                        <h2 class="dest-h2">About this destination</h2>
                        <div class="dest-prose"><?= nl2br(e($d['description'])) ?></div>
                    <?php endif; ?>

                    <!-- Directly under the description, not at the foot of the
                         column. The photographs are the strongest argument for
                         going, and behind the history and the reminders they
                         were three screens down — past the point where most
                         readers stop. -->
                    <?php if ($galleryPhotos !== []): ?>
                        <h2 class="dest-h2">Photo gallery</h2>
                        <div class="dest-gallery">
                            <?php foreach ($galleryPhotos as $photo): ?>
                                <a href="<?= e($photo['url']) ?>" data-lightbox
                                   data-caption="<?= e($photo['caption'] ?: $d['name']) ?>">
                                    <img src="<?= e($photo['url']) ?>"
                                         alt="<?= e($photo['caption'] ?: $d['name']) ?>" loading="lazy">
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($d['history']): ?>
                        <h2 class="dest-h2">Historical background</h2>
                        <div class="dest-prose"><?= nl2br(e($d['history'])) ?></div>
                    <?php endif; ?>

                    <?php if ($facilities !== []): ?>
                        <h2 class="dest-h2">Facilities &amp; amenities</h2>
                        <ul class="facility-list">
                            <?php foreach ($facilities as $f): ?>
                                <li><i class="fa-solid fa-circle-check"></i><?= e((string) $f) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($d['reminders']): ?>
                        <div class="reminder-box">
                            <h3><i class="fa-solid fa-triangle-exclamation"></i> Important reminders</h3>
                            <div><?= nl2br(e($d['reminders'])) ?></div>
                        </div>
                    <?php endif; ?>

                    <!-- Every block above is optional, so a destination the
                         Tourism Office has only just created renders two thirds
                         of the page as blank white beside a filled sidebar.
                         Saying so is better than looking broken. -->
                    <?php if (!$hasStory): ?>
                        <div class="empty-public">
                            <i class="fa-regular fa-file-lines"></i>
                            <h2>Details are on the way</h2>
                            <p>
                                The Tourism Office has not published a description for this
                                destination yet. The visitor information beside this and the
                                map below are accurate — check back for the full story.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ===================== SIDEBAR ===================== -->
                <aside class="col-lg-4">
                    <!-- Contacts only.
                         The hours and the fee moved out of this card: they are
                         already stated in the facts strip above, and printing
                         them twice within one screen made the page read as
                         though it had more to say than it does. -->
                    <div class="info-card">
                        <h3><i class="fa-solid fa-circle-info"></i> Who to contact</h3>
                        <dl>
                            <?php if ($d['contact_person']): ?>
                                <dt><i class="fa-solid fa-user"></i> Contact person</dt>
                                <dd><?= e($d['contact_person']) ?></dd>
                            <?php endif; ?>

                            <?php if ($d['contact_phone']): ?>
                                <dt><i class="fa-solid fa-phone"></i> Phone</dt>
                                <dd><a href="tel:<?= e(preg_replace('/\D/', '', $d['contact_phone'])) ?>"><?= e($d['contact_phone']) ?></a></dd>
                            <?php endif; ?>

                            <?php if ($d['contact_email']): ?>
                                <dt><i class="fa-regular fa-envelope"></i> Email</dt>
                                <dd><a href="mailto:<?= e($d['contact_email']) ?>"><?= e($d['contact_email']) ?></a></dd>
                            <?php endif; ?>
                        </dl>

                        <?php if (!$hasContacts): ?>
                            <p class="text-muted small mb-0">
                                No contact person has been listed for this destination yet.
                                For assistance, reach the Tampakan Tourism Office directly.
                            </p>
                        <?php endif; ?>
                    </div>

                    <?php if ($weather !== null): ?>
                        <div class="info-card">
                            <h3><i class="fa-solid fa-cloud-sun"></i> Weather &amp; Conditions</h3>
                            <?php require __DIR__ . '/app/views/partials/weather.php'; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($d['latitude'] !== null && $d['longitude'] !== null): ?>
                        <div class="info-card">
                            <h3><i class="fa-solid fa-map-location-dot"></i> Location</h3>
                            <div id="destMap" class="dest-map"
                                 data-lat="<?= e((string) $d['latitude']) ?>"
                                 data-lng="<?= e((string) $d['longitude']) ?>"
                                 data-name="<?= e($d['name']) ?>"></div>
                            <a class="btn btn-primary-grad w-100 mt-3"
                               href="https://www.google.com/maps/dir/?api=1&destination=<?= e((string) $d['latitude']) ?>,<?= e((string) $d['longitude']) ?>"
                               target="_blank" rel="noopener">
                                <i class="fa-solid fa-diamond-turn-right"></i> Get Directions
                            </a>
                        </div>
                    <?php endif; ?>
                </aside>
            </div>


            <!-- ===================== REVIEWS ===================== -->
            <section id="reviews" class="reviews-section">
                <div class="reviews-head">
                    <div>
                        <h2 class="dest-h2 mb-1">Visitor Reviews</h2>
                        <?php if ($ratingSummary['total'] > 0): ?>
                            <p class="reviews-summary">
                                <span class="reviews-summary__score"><?= e((string) $ratingSummary['average']) ?></span>
                                <span class="reviews-summary__stars">
                                    <?php for ($s = 1; $s <= 5; $s++): ?>
                                        <i class="fa-<?= $s <= round($ratingSummary['average']) ? 'solid' : 'regular' ?> fa-star"></i>
                                    <?php endfor; ?>
                                </span>
                                <span class="reviews-summary__count">
                                    from <?= n($ratingSummary['total']) ?> visitor<?= $ratingSummary['total'] === 1 ? '' : 's' ?>
                                </span>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php foreach ($flashes as $flash): ?>
                    <div class="review-flash review-flash--<?= e($flash['type']) ?>">
                        <i class="fa-solid fa-circle-info"></i> <?= e($flash['message']) ?>
                    </div>
                <?php endforeach; ?>

                <?php if ($reviews === []): ?>
                    <div class="reviews-empty">
                        <i class="fa-regular fa-comment-dots"></i>
                        <p><strong>No reviews yet.</strong></p>
                        <p>
                            Reviews here come from visitors who logged their visit on site by scanning
                            the destination QR code — so every one of them was actually there.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="reviews-list">
                        <?php foreach ($reviews as $rev): ?>
                            <article class="review-pub">
                                <header>
                                    <div class="review-pub__stars" aria-label="<?= (int) $rev['rating'] ?> out of 5">
                                        <?php for ($s = 1; $s <= 5; $s++): ?>
                                            <i class="fa-<?= $s <= (int) $rev['rating'] ? 'solid' : 'regular' ?> fa-star"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <?php if ($rev['arrival_id'] !== null): ?>
                                        <span class="review-pub__verified" title="This visitor logged their visit on site">
                                            <i class="fa-solid fa-circle-check"></i> Verified visit
                                        </span>
                                    <?php endif; ?>
                                </header>

                                <?php if ($rev['comment']): ?>
                                    <p class="review-pub__text"><?= nl2br(e($rev['comment'])) ?></p>
                                <?php endif; ?>

                                <footer class="review-pub__foot">
                                    <strong><?= e($rev['visitor_name'] ?: 'Anonymous visitor') ?></strong>
                                    <?php
                                    $from = array_filter([$rev['origin_city'], $rev['origin_province'], $rev['origin_country']]);
                                    if ($from): ?>
                                        <span>· <?= e(implode(', ', array_slice($from, 0, 2))) ?></span>
                                    <?php endif; ?>
                                    <span>· <?= e(format_date($rev['created_at'], 'M Y')) ?></span>
                                </footer>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($related !== []): ?>
                <h2 class="dest-h2 mt-5">
                    <?= $d['category_name']
                        ? 'More ' . e((string) $d['category_name']) . ' destinations'
                        : 'More destinations to explore' ?>
                </h2>
                <div class="row g-4">
                    <?php foreach ($related as $r): ?>
                        <div class="col-md-4">
                            <article class="dest-card">
                                <div class="dest-card__media">
                                    <img src="<?= e($r['cover_photo'] ? base_url($r['cover_photo']) : img('1464822759023-fed622ff2c3b')) ?>"
                                         alt="<?= e($r['name']) ?>" loading="lazy" width="1200" height="800">
                                </div>
                                <div class="dest-card__body">
                                    <h3 class="dest-card__title"><?= e($r['name']) ?></h3>
                                    <p class="dest-card__text"><?= e((string) $r['short_description']) ?></p>
                                    <a href="<?= e(base_url('/destination.php?slug=' . $r['slug'])) ?>" class="link-more">
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

<?php endif; ?>
</main>

<?php require __DIR__ . '/app/views/partials/public-footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?= e(asset('js/script.js')) ?>"></script>
<script>
(function () {
    const el = document.getElementById('destMap');
    if (!el || typeof L === 'undefined') return;

    const lat = parseFloat(el.dataset.lat);
    const lng = parseFloat(el.dataset.lng);
    if (Number.isNaN(lat) || Number.isNaN(lng)) return;

    const map = L.map(el, { scrollWheelZoom: false }).setView([lat, lng], 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors', maxZoom: 19
    }).addTo(map);
    L.marker([lat, lng]).addTo(map).bindPopup(el.dataset.name).openPopup();
})();
</script>
</body>
</html>
