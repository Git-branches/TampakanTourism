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
use App\Repositories\RouteRepository;
use App\Repositories\VideoRepository;

/* The office's own contact details, read once. The sidebar shows these rather
   than the destination's caretaker: somebody still deciding whether to travel
   wants the municipal number that is answered on a working day. */
$officeName    = trim((string) (setting('office_name', '') ?? '')) ?: 'Tampakan Municipal Tourism Office';
$officePhone   = trim((string) (setting('office_phone', '') ?? ''));
$officeEmail   = trim((string) (setting('office_email', '') ?? ''));
$officeAddress = trim((string) (setting('office_address', '') ?? ''));

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

    /* The score, not the words. Comments go to the Municipal Tourism Office and
       are not published here — so the query that fetched twenty review bodies on
       every page load is gone with them. */
    $ratingSummary = FeedbackRepository::summaryFor((int) $d['id']);

    /* THE SCORES, NEVER THE WORDS.
     *
     * The office's rule stands: a rating is public, a comment goes privately to
     * the Municipal Tourism Office. So this list carries the star count and the
     * date and nothing else — it exists to show that ratings are recent and
     * real, not to publish what anyone wrote.
     *
     * Three at a time, because that is what fits beside the sidebar without the
     * section becoming the page. */
    $recentRatings = FeedbackRepository::publishedFor((int) $d['id'], 12);
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

    /* How to get here, in words, from landmarks a person can find.
     *
     * Distinct from $related above: that is "places like this one", this is
     * "places you could reach on the same trip", measured in kilometres rather
     * than category. A visitor already out on the mountain road is choosing
     * between the second and the third, not between categories. */
    $routes = RouteRepository::forDestination((int) $d['id']);
    $nearby = RouteRepository::nearby((int) $d['id'], 3);

    /* THIS DESTINATION'S VIDEOS AND NO OTHER.
     *
     * A clip the office filmed at Jadas Falls belongs on the Jadas Falls page.
     * published() filters on destination_id, so a video attached to another
     * place — or to the municipality generally — never appears here. */
    $videoPage = VideoRepository::forDestinationPage((int) $d['id'], 12);
    $videos    = $videoPage['featured'] !== null ? [$videoPage['featured']] : [];


    /* "BEFORE YOU VISIT" — the practical card row.
     *
     * The hours and the fee lead because they decide whether the trip happens at
     * all. Everything after them is the office's own reminders, one card per
     * line: managers already write these as a list, and running them together as
     * a paragraph is what made them read as small print nobody finishes.
     *
     * The icon is chosen from words in the line. Not clever, deliberately — a
     * keyword match either hits or falls back to a tick, and a wrong-but-neutral
     * icon costs nothing while a missing one leaves a ragged row. */
    $beforeYouVisit = [];

    if ($d['operating_hours']) {
        $beforeYouVisit[] = [
            'icon'  => 'fa-regular fa-clock',
            'title' => 'Opening Hours',
            'text'  => (string) $d['operating_hours'],
        ];
    }

    if ($d['entrance_fee']) {
        $beforeYouVisit[] = [
            'icon'  => 'fa-solid fa-ticket',
            'title' => 'Entrance Fee',
            'text'  => (string) $d['entrance_fee'],
        ];
    }

    $reminderIcons = [
        'footwear'  => 'fa-solid fa-shoe-prints',
        'shoes'     => 'fa-solid fa-shoe-prints',
        'sandal'    => 'fa-solid fa-shoe-prints',
        'water'     => 'fa-solid fa-bottle-water',
        'drink'     => 'fa-solid fa-bottle-water',
        'hydrat'    => 'fa-solid fa-bottle-water',
        'weather'   => 'fa-solid fa-cloud-sun',
        'rain'      => 'fa-solid fa-cloud-showers-heavy',
        'clean'     => 'fa-solid fa-trash-can',
        'litter'    => 'fa-solid fa-trash-can',
        'trash'     => 'fa-solid fa-trash-can',
        'garbage'   => 'fa-solid fa-trash-can',
        'guide'     => 'fa-solid fa-person-hiking',
        'swim'      => 'fa-solid fa-person-swimming',
        'photo'     => 'fa-solid fa-camera',
        'camera'    => 'fa-solid fa-camera',
        'slippery'  => 'fa-solid fa-triangle-exclamation',
        'careful'   => 'fa-solid fa-triangle-exclamation',
        'safety'    => 'fa-solid fa-shield-halved',
        'respect'   => 'fa-solid fa-hands-praying',
        'noise'     => 'fa-solid fa-volume-xmark',
        'logbook'   => 'fa-solid fa-clipboard-check',
        'register'  => 'fa-solid fa-clipboard-check',
        'fire'      => 'fa-solid fa-fire',
        'pet'       => 'fa-solid fa-paw',
        'child'     => 'fa-solid fa-children',
    ];

    foreach (preg_split('/\r\n|\r|\n/', (string) ($d['reminders'] ?? '')) ?: [] as $line) {
        $line = trim(ltrim($line, "-*• \t"));

        if ($line === '') {
            continue;
        }

        $icon  = 'fa-solid fa-circle-check';
        $lower = mb_strtolower($line);

        foreach ($reminderIcons as $cue => $candidate) {
            if (str_contains($lower, $cue)) {
                $icon = $candidate;
                break;
            }
        }

        /* A long reminder is a sentence, not a card title. Split on the first
           colon or dash so "Footwear: the last stretch is loose rock" becomes a
           heading and a line under it; otherwise the whole thing is the heading
           and the card carries no body. */
        $title = $line;
        $body  = '';

        if (preg_match('/^(.{3,34}?)\s*[:–—-]\s+(.+)$/u', $line, $parts)) {
            $title = $parts[1];
            $body  = $parts[2];
        }

        $beforeYouVisit[] = ['icon' => $icon, 'title' => $title, 'text' => $body];
    }

    foreach ($facilities as $facility) {
        $beforeYouVisit[] = [
            'icon'  => 'fa-solid fa-circle-check',
            'title' => (string) $facility,
            'text'  => '',
        ];
    }

    /* "YOU MAY ALSO LIKE" — one list, not two.
     *
     * The page carried a "More <category> destinations" row and an "Also nearby"
     * row, three cards each, frequently showing the same place twice under two
     * headings. Nearby leads because somebody already out on the mountain road
     * is choosing what else fits in the afternoon; same-category fills the rest. */
    $alsoLike = [];
    $seen     = [(int) $d['id'] => true];

    foreach (array_merge($nearby, $related) as $candidate) {
        $cid = (int) $candidate['id'];

        if (isset($seen[$cid])) {
            continue;
        }

        $seen[$cid]  = true;
        $alsoLike[]  = $candidate;

        /* Three, not four: the column is narrower now that this sits beside
           the sidebar rather than under the whole page. */
        if (count($alsoLike) === 3) {
            break;
        }
    }

    /* Each of these guards a whole region of the page, and each region is built
       entirely from optional columns — so any of them can turn out to have
       nothing to show for a thinly-filled destination. */
    $hasFacts = $ratingSummary['total'] > 0
        || $d['operating_hours']
        || $d['entrance_fee']
        || $weather !== null
        || ($d['latitude'] !== null && $d['longitude'] !== null);

    $hasStory = $videos !== []
        || $d['short_description']
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
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body id="top">

<?php
/* NAVBAR ON, like every other interior page.
 *
 * It used to be off here, and the reason given was that a solid white bar
 * "cropped the top off" the cover photograph. That reason does not survive
 * looking at .dest-hero__overlay: the scrim is rgba(8,26,12,.92) at the top of
 * the hero, fading to .20 at the bottom. The strip a navbar occupies is already
 * 92% near-black — there is no photograph up there to crop.
 *
 * A transparent overlay navbar was considered next and rejected on the same
 * grounds it was proposed: it would have made three different navbar treatments
 * across one site — transparent here, solid on the map and the guide form, none
 * on the QR page. Three treatments is not a design, it is three decisions nobody
 * reconciled. One solid bar on every interior page is the rule; the homepage
 * keeps its transparent overlay because a landing hero is the one exception
 * every visitor already understands.
 *
 * What this restores is every route out of the page. A visitor who arrives here
 * from a search engine wanting one destination is exactly the visitor who then
 * wants the second one, and the breadcrumb offered them only "Home". */
$showNavbar = true;
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
                    <?php /* VIDEO AND PHOTOGRAPHS SIDE BY SIDE.
                             They answer the same question — what does this place
                             look like — and stacking them meant scrolling past a
                             full-width video to reach the pictures. The video
                             takes the wider column because it is the thing the
                             office actually went out and made. */ ?>
                    <?php if ($videoPage['featured'] !== null || $galleryPhotos !== []): ?>
                        <div class="row g-4 dest-media">

                            <?php if ($videoPage['featured'] !== null): ?>
                                <?php $lead = $videoPage['featured']; ?>
                                <div class="col-lg-<?= $galleryPhotos !== [] ? '7' : '12' ?>" id="video">
                                    <header class="dest-block__head">
                                        <h2 class="dest-block__title">
                                            <i class="fa-solid fa-video"></i>
                                            <?= $lead['category'] === 'promo' ? 'Promotional Video' : 'Video' ?>
                                        </h2>
                                        <p class="dest-block__sub">
                                            <?= $lead['caption']
                                                ? e((string) $lead['caption'])
                                                : 'Watch and experience the beauty of ' . e((string) $d['name']) . '.' ?>
                                        </p>
                                    </header>

                                    <?php $leadEmbed = $lead['source'] === 'external'
                                        ? VideoRepository::embedUrl((string) $lead['external_url'])
                                        : null; ?>

                                    <figure class="dest-video dest-video--lead">
                                        <div class="dest-video__frame" id="vplayFrame">
                                            <?php if ($lead['source'] === 'upload' && $lead['file_path']): ?>
                                                <video controls preload="metadata" playsinline
                                                       <?= $lead['poster_path']
                                                            ? 'poster="' . e(base_url('/' . $lead['poster_path'])) . '"'
                                                            : '' ?>>
                                                    <source src="<?= e(base_url('/' . $lead['file_path'])) ?>"
                                                            type="<?= e((string) ($lead['mime_type'] ?: 'video/mp4')) ?>">
                                                    Your browser cannot play this video.
                                                </video>
                                            <?php elseif ($leadEmbed !== null): ?>
                                                <iframe src="<?= e($leadEmbed) ?>"
                                                        title="<?= e((string) $lead['title']) ?>"
                                                        loading="lazy" allowfullscreen
                                                        referrerpolicy="strict-origin-when-cross-origin"></iframe>
                                            <?php endif; ?>
                                        </div>
                                    </figure>

                                    <?php
                                    /* THE OFFICE UPLOADS A FILM A YEAR, so this
                                       list only grows. One player and a way to
                                       step through it, rather than a column of
                                       players the reader has to scroll past.
                                       Rendered only when there is somewhere to
                                       step to — a Next button on a single video
                                       is a control that never does anything. */
                                    $playlist = $videoPage['playlist'];
                                    ?>
                                    <?php if (count($playlist) > 1): ?>
                                        <div class="vplay" id="vplay"
                                             data-clips="<?= e(json_encode($playlist, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">

                                            <div class="vplay__bar">
                                                <button type="button" class="vplay__step" data-vplay="prev"
                                                        aria-label="Previous video" disabled>
                                                    <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                                                </button>

                                                <p class="vplay__now" aria-live="polite">
                                                    <strong data-vplay="title"><?= e((string) $playlist[0]['title']) ?></strong>
                                                    <span data-vplay="meta">
                                                        <?= $playlist[0]['year'] !== '' ? e($playlist[0]['year']) . ' &middot; ' : '' ?>
                                                        <?= e((string) $playlist[0]['label']) ?>
                                                    </span>
                                                </p>

                                                <span class="vplay__count">
                                                    <span data-vplay="index">1</span> of <?= n(count($playlist)) ?>
                                                </span>

                                                <button type="button" class="vplay__step" data-vplay="next"
                                                        aria-label="Next video">
                                                    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                                                </button>
                                            </div>

                                            <?php
                                            /* A strip of year tiles sat here as a shortcut past
                                               Next-Next-Next. Removed: the office titles its
                                               uploads plainly and files several in a year, so the
                                               tiles read as a row of near-identical labels — the
                                               bar above already names what is playing, and it
                                               says it once. */
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($galleryPhotos !== []): ?>
                                <div class="col-lg-<?= $videoPage['featured'] !== null ? '5' : '12' ?>">
                                    <header class="dest-block__head dest-block__head--split">
                                        <h2 class="dest-block__title">
                                            <i class="fa-solid fa-camera"></i> Photo Gallery
                                        </h2>
                                        <?php /* Only when there are more than the six on
                                                 screen — a "view all" that shows the same
                                                 six is a link that teaches people not to
                                                 press the next one. */ ?>
                                        <?php if (count($galleryPhotos) > 9): ?>
                                            <a class="dest-block__more" href="#gallery-all" id="showAllPhotos">
                                                View all <?= n(count($galleryPhotos)) ?> photos
                                                <i class="fa-solid fa-arrow-right"></i>
                                            </a>
                                        <?php endif; ?>
                                    </header>

                                    <?php
                                    /* NINE TILES, three by three.
                                     *
                                     * Nine because the column beside a 16:9 video ends
                                     * level at three rows of three — six left a gap
                                     * under the gallery, twelve pushed past the video.
                                     * The count has to be a multiple of three or the
                                     * block finishes on a ragged row next to something
                                     * with a straight bottom edge.
                                     *
                                     * When there are more than nine, the ninth tile
                                     * becomes the way in to the rest: the photograph
                                     * stays, dimmed, with the remaining count over it.
                                     * A tenth thumbnail shrunk to a "more" button would
                                     * read as a picture nobody can open. */
                                    $shown  = array_slice($galleryPhotos, 0, 9);
                                    $spare  = count($galleryPhotos) - count($shown);
                                    $lastIx = count($shown) - 1;
                                    ?>
                                    <div class="dest-gallery" id="gallery-all">
                                        <?php foreach ($shown as $index => $photo): ?>
                                            <?php $isDoor = $spare > 0 && $index === $lastIx; ?>
                                            <a href="<?= e($photo['url']) ?>" data-lightbox
                                               class="<?= $isDoor ? 'is-door' : '' ?>"
                                               data-caption="<?= e($photo['caption'] ?: $d['name']) ?>">
                                                <img src="<?= e($photo['url']) ?>"
                                                     alt="<?= e($photo['caption'] ?: $d['name']) ?>" loading="lazy">

                                                <?php if ($isDoor): ?>
                                                    <span class="dest-gallery__door">
                                                        <strong>+<?= n($spare + 1) ?></strong>
                                                        <small>View all photos</small>
                                                    </span>
                                                <?php endif; ?>
                                            </a>
                                        <?php endforeach; ?>

                                        <?php /* The ones past the ninth. Rendered but
                                                 hidden: they are never shown as tiles,
                                                 they exist so the viewer has the whole
                                                 set to walk through once the door is
                                                 opened. */ ?>
                                        <?php foreach (array_slice($galleryPhotos, 9) as $photo): ?>
                                            <a href="<?= e($photo['url']) ?>" data-lightbox class="is-extra"
                                               data-caption="<?= e($photo['caption'] ?: $d['name']) ?>">
                                                <img src="<?= e($photo['url']) ?>"
                                                     alt="<?= e($photo['caption'] ?: $d['name']) ?>" loading="lazy">
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php
                    /* THE GRID OF EXTRA PLAYERS THAT USED TO SIT HERE IS GONE.
                     *
                     * It rendered every remaining clip as its own 16:9 player
                     * under category headings. With two or three videos that
                     * read as an archive; with one a year it becomes a column
                     * the reader scrolls past, and every one of those players
                     * fetched its own poster on page load.
                     *
                     * All of them are in the playlist beside the lead player
                     * now — same videos, one frame, reachable by Next or by
                     * pressing the year. Nothing was dropped from the page;
                     * $videoPage['groups'] is still returned for anything else
                     * that wants the category split. */
                    ?>

                    <?php if ($d['history']): ?>
                        <h2 class="dest-h2">Historical background</h2>
                        <div class="dest-prose"><?= nl2br(e($d['history'])) ?></div>
                    <?php endif; ?>

                    <?php /* BEFORE YOU VISIT — hours, fee, and the office's own
                             reminders as one row of cards. The reminders used to
                             sit in an amber warning box below the facilities
                             list, which read as small print; the practical
                             things a visitor needs are not a warning. */ ?>
                    <?php if ($beforeYouVisit !== []): ?>
                        <section class="dest-prep">
                            <h2 class="dest-block__title">
                                <i class="fa-solid fa-clipboard-list"></i> Before You Visit
                            </h2>

                            <div class="prep-grid">
                                <?php foreach ($beforeYouVisit as $item): ?>
                                    <div class="prep-card">
                                        <i class="<?= e($item['icon']) ?>"></i>
                                        <strong><?= e($item['title']) ?></strong>
                                        <?php if ($item['text'] !== ''): ?>
                                            <span><?= e($item['text']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>

                    <?php /* HOW TO GET HERE, in words.
                             The sidebar map answers "where is it"; a pin is no
                             use at the junction where the concrete ends and
                             there are three gravel tracks. These are the
                             office's own directions, from landmarks a tricycle
                             driver knows by name. */ ?>
                    <?php if ($routes !== []): ?>
                        <section class="dest-routes" id="directions">
                            <h2 class="dest-h2">
                                <i class="fa-solid fa-diamond-turn-right"></i> How to get here
                            </h2>

                            <?php foreach ($routes as $route): ?>
                                <article class="route-card">
                                    <h3 class="route-card__from">
                                        <i class="fa-solid fa-location-dot"></i>
                                        From <?= e((string) $route['from_landmark']) ?>
                                    </h3>

                                    <?php
                                    $meta = array_filter([
                                        (string) ($route['travel_time'] ?? ''),
                                        (string) ($route['distance'] ?? ''),
                                        (string) ($route['transport'] ?? ''),
                                    ], static fn(string $v): bool => trim($v) !== '');
                                    ?>
                                    <?php if ($meta !== []): ?>
                                        <p class="route-card__meta"><?= e(implode(' · ', $meta)) ?></p>
                                    <?php endif; ?>

                                    <p class="route-card__body"><?= nl2br(e((string) $route['directions'])) ?></p>

                                    <?php if ($route['fare_note']): ?>
                                        <p class="route-card__fare">
                                            <i class="fa-solid fa-coins"></i> <?= e((string) $route['fare_note']) ?>
                                        </p>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>

                            <?php /* The offline half. Signal fails on the last
                                     stretch of most of these routes, so the
                                     directions have to be takeable with you. */ ?>
                            <div class="route-offline">
                                <p class="route-offline__lede">
                                    <i class="fa-solid fa-signal"></i>
                                    <strong>There is little or no signal on the last stretch.</strong>
                                    Save the directions to your phone before you set off.
                                </p>
                                <div class="route-offline__actions">
                                    <a class="btn btn-primary-grad"
                                       href="<?= e(base_url('/directions.php?slug=' . urlencode((string) $d['slug']))) ?>">
                                        <i class="fa-solid fa-file-arrow-down"></i> Printable directions sheet
                                    </a>
                                    <?php if ($d['offline_map_image']): ?>
                                        <a class="btn btn-outline-brand"
                                           href="<?= e(base_url('/' . $d['offline_map_image'])) ?>"
                                           download="<?= e($d['slug']) ?>-map.jpg">
                                            <i class="fa-solid fa-map"></i> Download the map
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </section>
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

                    <?php /* REVIEWS AND "ALSO LIKE" LIVE IN THIS COLUMN.
                             They used to sit below the whole two-column row, full
                             width — which left a tall blank space beside the sidebar
                             on any destination whose description is short, and put
                             the reviews further from the page than the map beside
                             them. Inside the column they fill that space and read in
                             the order somebody actually asks: what is it, how do I
                             prepare, what did others think, where else could I go. */ ?>

            <section id="reviews" class="reviews-section">
                <?php /* THE SCORE IS STATED ONCE.
                         A second copy sat here, in the heading: the same average,
                         the same five stars and the same visitor count, a few
                         centimetres above the block that carries them beside the
                         distribution bars — and formatted differently, "4" here
                         against "4.0" there, so the page appeared to disagree
                         with itself about its own rating. The block below keeps
                         it because that is where the bars give it context. */ ?>
                <h2 class="dest-block__title">
                    <i class="fa-regular fa-star"></i> Visitor Reviews
                </h2>

                <?php foreach ($flashes as $flash): ?>
                    <div class="review-flash review-flash--<?= e($flash['type']) ?>">
                        <i class="fa-solid fa-circle-info"></i> <?= e($flash['message']) ?>
                    </div>
                <?php endforeach; ?>

                <?php
                /* RATINGS ONLY ON THE PUBLIC SITE.
                 *
                 * What a visitor writes goes to the Municipal Tourism Office and
                 * stops there. Two reasons, and the second is the one that
                 * decided it:
                 *
                 *   a comment published on a municipal website is a publication
                 *   the municipality answers for — a complaint about a named
                 *   caretaker, or a sentence about another visitor, is not
                 *   something an LGU should host by default
                 *
                 *   and the office wants the complaint in order to ACT on it,
                 *   which is a different job from displaying it
                 *
                 * The score still comes through in full, so a tourist deciding
                 * where to go on Saturday gets the honest signal.
                 *
                 * The per-review cards that used to sit here also carried a
                 * "Verified visit" badge, a visitor name and a home town — all
                 * read from the arrival the review was attached to. Feature 1
                 * removed the digital logbook, so there is no arrival any more
                 * and every one of those fields is now NULL. They rendered as
                 * blank furniture on a card with nothing in it. */
                ?>

                <?php if ($ratingSummary['total'] === 0): ?>
                    <div class="reviews-empty">
                        <i class="fa-regular fa-star"></i>
                        <p><strong>No ratings yet.</strong></p>
                        <p>
                            Scan the QR code at the destination and you can rate your visit from
                            there. Ratings appear here; anything you write goes privately to the
                            Municipal Tourism Office.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="rating-summary">
                        <div class="rating-summary__score">
                            <strong><?= e(number_format($ratingSummary['average'], 1)) ?></strong>
                            <div class="rating-summary__stars"
                                 aria-label="<?= e(number_format($ratingSummary['average'], 1)) ?> out of 5">
                                <?php for ($s = 1; $s <= 5; $s++): ?>
                                    <i class="fa-<?= $s <= round($ratingSummary['average']) ? 'solid' : 'regular' ?> fa-star"></i>
                                <?php endfor; ?>
                            </div>
                            <span><?= n($ratingSummary['total']) ?> visitor<?= $ratingSummary['total'] === 1 ? '' : 's' ?></span>
                        </div>

                        <div class="rating-summary__bars">
                            <?php
                            $spread = FeedbackRepository::distribution((int) $d['id']);
                            $peak   = max(1, max($spread ?: [1]));

                            for ($s = 5; $s >= 1; $s--):
                                $count = (int) ($spread[$s] ?? 0);
                                ?>
                                <div class="rating-bar">
                                    <span class="rating-bar__label"><?= $s ?><i class="fa-solid fa-star"></i></span>
                                    <span class="rating-bar__track">
                                        <span class="rating-bar__fill" style="width: <?= round($count / $peak * 100) ?>%"></span>
                                    </span>
                                    <span class="rating-bar__count"><?= n($count) ?></span>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <?php if ($recentRatings !== []): ?>
                        <?php /* Stars and a date. No name, no town, no sentence —
                                 every one of those either belongs to the office
                                 alone or, since the digital logbook was removed,
                                 is now NULL and would render as blank furniture. */ ?>
                        <ul class="rating-feed" id="ratingFeed">
                            <?php foreach ($recentRatings as $index => $rating): ?>
                                <li class="<?= $index >= 3 ? 'is-extra' : '' ?>">
                                    <span class="rating-feed__stars" aria-label="<?= (int) $rating['rating'] ?> out of 5">
                                        <?php for ($s = 1; $s <= 5; $s++): ?>
                                            <i class="fa-<?= $s <= (int) $rating['rating'] ? 'solid' : 'regular' ?> fa-star"></i>
                                        <?php endfor; ?>
                                    </span>
                                    <span class="rating-feed__when">
                                        <?= e(format_date((string) $rating['created_at'], 'j F Y')) ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <?php if (count($recentRatings) > 3): ?>
                            <?php /* A TOGGLE, not a one-way door. It used to remove
                                     itself on the way open, which left somebody who
                                     had expanded seven ratings scrolling past all of
                                     them with no way back — the only escape was
                                     reloading the page.

                                     Both labels ship in the markup so the button never
                                     flashes the wrong one while a script decides, and
                                     aria-expanded carries the state for a screen
                                     reader rather than the caret alone. */ ?>
                            <button type="button" class="rating-feed__more" id="showAllRatings"
                                    aria-expanded="false" aria-controls="ratingFeed"
                                    data-more="View all <?= n(count($recentRatings)) ?> ratings"
                                    data-less="Show fewer">
                                <span class="rating-feed__more-label">View all <?= n(count($recentRatings)) ?> ratings</span>
                                <i class="fa-solid fa-chevron-down"></i>
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>

                    <p class="reviews-note">
                        Ratings are shown here. Written comments go to the Municipal Tourism Office so
                        they can act on them.
                    </p>
                <?php endif; ?>
            </section>

            <?php /* ONE "you may also like", not two rows.
                     The page carried "More <category> destinations" and "Also
                     nearby" as separate three-card rows, and with three active
                     destinations they routinely showed the same place twice
                     under two different headings. Nearby leads the merged list:
                     somebody already out on the mountain road is choosing what
                     else fits in the afternoon. */ ?>
            <?php if ($alsoLike !== []): ?>
                <section class="dest-also">
                    <header class="dest-block__head dest-block__head--split">
                        <h2 class="dest-block__title">
                            <i class="fa-solid fa-location-dot"></i> You May Also Like
                        </h2>
                        <a class="dest-block__more" href="<?= e(destinations_url()) ?>">
                            View all destinations <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </header>

                    <div class="also-grid">
                        <?php foreach ($alsoLike as $item): ?>
                            <a class="also-card"
                               href="<?= e(base_url('/destination.php?slug=' . urlencode((string) $item['slug']))) ?>">
                                <span class="also-card__media">
                                    <?php
                                    /* ?? null, not $item['cover_photo'] directly.
                                     *
                                     * The two lists merged here came from different
                                     * queries and nearby() did not select this column.
                                     * PHP raised "Undefined array key" and wrote the
                                     * warning — file path, line number and all —
                                     * straight into the src attribute, which is what
                                     * put a stack trace inside the card on screen.
                                     *
                                     * The query is fixed above; this is the guard that
                                     * stops the next mismatched key doing it again. */
                                    $cover = uploaded_url($item['cover_photo'] ?? null);
                                    ?>
                                    <img src="<?= e($cover ?? img('1464822759023-fed622ff2c3b', 600, 400)) ?>"
                                         alt="<?= e((string) ($item['name'] ?? 'Destination')) ?>" loading="lazy">

                                    <?php /* Distance only where it was measured. The
                                             same-category entries have none, and an
                                             invented one would be a number the page
                                             cannot stand behind. */ ?>
                                    <?php if (isset($item['distance_km'])): ?>
                                        <span class="also-card__km">
                                            <?= e(number_format((float) $item['distance_km'], 1)) ?> km
                                        </span>
                                    <?php endif; ?>
                                </span>

                                <span class="also-card__body">
                                    <strong><?= e((string) $item['name']) ?></strong>
                                    <span class="also-card__where">
                                        <i class="fa-solid fa-location-dot"></i>
                                        <?= e(trim((string) ($item['barangay'] ?? '')) !== ''
                                            ? $item['barangay'] . ', Tampakan'
                                            : 'Tampakan, South Cotabato') ?>
                                    </span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
                </div>

                <!-- ===================== SIDEBAR ===================== -->
                <aside class="col-lg-4 dest-aside">

                    <?php /* THE OFFICE, not the destination's caretaker.
                             A visitor deciding whether to go wants the municipal
                             number that is answered on a working day. The
                             caretaker's own line stays on the QR page, where the
                             person reading it is already standing at the site. */ ?>
                    <div class="side-card">
                        <h3 class="side-card__title"><?= e($officeName) ?></h3>

                        <ul class="side-contact">
                            <?php if ($officePhone !== ''): ?>
                                <li>
                                    <i class="fa-solid fa-phone"></i>
                                    <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $officePhone) ?? '') ?>"><?= e($officePhone) ?></a>
                                </li>
                            <?php endif; ?>

                            <?php if ($officeEmail !== ''): ?>
                                <li>
                                    <i class="fa-regular fa-envelope"></i>
                                    <a href="mailto:<?= e($officeEmail) ?>"><?= e($officeEmail) ?></a>
                                </li>
                            <?php endif; ?>

                            <?php if ($officeAddress !== ''): ?>
                                <li>
                                    <i class="fa-solid fa-location-dot"></i>
                                    <span><?= e($officeAddress) ?></span>
                                </li>
                            <?php endif; ?>

                            <?php if ($d['contact_person']): ?>
                                <li>
                                    <i class="fa-solid fa-user"></i>
                                    <span><?= e((string) $d['contact_person']) ?> &mdash; caretaker at this destination</span>
                                </li>
                            <?php endif; ?>
                        </ul>

                        <?php /* Only when there is somewhere for it to go. A
                                 "Contact" button that opens a blank mail client
                                 because no address was ever set is worse than no
                                 button. */ ?>
                        <?php if ($officeEmail !== ''): ?>
                            <a class="btn btn-side" href="mailto:<?= e($officeEmail) ?>?subject=<?= e(rawurlencode('Enquiry about ' . $d['name'])) ?>">
                                <i class="fa-regular fa-envelope"></i> Contact Tourism Office
                            </a>
                        <?php elseif ($officePhone !== ''): ?>
                            <a class="btn btn-side" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $officePhone) ?? '') ?>">
                                <i class="fa-solid fa-phone"></i> Call the Tourism Office
                            </a>
                        <?php endif; ?>
                    </div>

                    <?php /* THE FIVE STEPS, stated before the button rather than
                             discovered afterwards.
                             Step four is the one that matters: this is not an
                             instant booking, and a visitor who learns that only
                             after submitting has been misled by the button. */ ?>
                    <div class="side-card">
                        <h3 class="side-card__title">
                            <i class="fa-solid fa-person-hiking"></i> Request a Tour Guide
                        </h3>
                        <p class="side-card__lede">
                            Request an accredited local tour guide for a safe and memorable experience.
                        </p>

                        <ol class="side-steps">
                            <li><span>1</span> Fill up your information</li>
                            <li><span>2</span> Submit your request</li>
                            <li><span>3</span> Receive digital receipt</li>
                            <li><span>4</span> Visit Municipal Tourism Office</li>
                            <li><span>5</span> Meet your tour guide</li>
                        </ol>

                        <a class="btn btn-side btn-side--solid"
                           href="<?= e(base_url('/tour-guide.php?d=' . urlencode((string) $d['slug']))) ?>">
                            <i class="fa-solid fa-paper-plane"></i> Request a Tour Guide
                        </a>
                    </div>

                    <?php if ($weather !== null): ?>
                        <div class="side-card">
                            <h3 class="side-card__title">
                                <i class="fa-solid fa-cloud-sun"></i> Weather &amp; Conditions
                            </h3>
                            <?php require __DIR__ . '/app/views/partials/weather.php'; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($d['latitude'] !== null && $d['longitude'] !== null): ?>
                        <div class="side-card">
                            <h3 class="side-card__title"><i class="fa-solid fa-map-location-dot"></i> Location</h3>
                            <div id="destMap" class="dest-map"
                                 data-lat="<?= e((string) $d['latitude']) ?>"
                                 data-lng="<?= e((string) $d['longitude']) ?>"
                                 data-name="<?= e($d['name']) ?>"></div>
                            <a class="btn btn-side btn-side--solid mt-3"
                               href="https://www.google.com/maps/dir/?api=1&destination=<?= e((string) $d['latitude']) ?>,<?= e((string) $d['longitude']) ?>"
                               target="_blank" rel="noopener">
                                <i class="fa-solid fa-diamond-turn-right"></i> Get Directions
                            </a>
                        </div>
                    <?php endif; ?>
                </aside>

            </div>

            <!-- ===================== REVIEWS ===================== -->
        </div>
    </section>

<?php endif; ?>
</main>

<?php
/* The viewer the gallery needs. Without this element on the page script.js
   returns early, and every thumbnail falls through to its own href — which
   navigated the visitor out of the site to a bare JPEG. */
require __DIR__ . '/app/views/partials/lightbox.php';
require __DIR__ . '/app/views/partials/public-footer.php';
?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?= e(asset('js/vendor/sweetalert2.all.min.js')) ?>"></script>
<script src="<?= e(asset('js/notify.js')) ?>"></script>
<script src="<?= e(asset('js/script.js')) ?>"></script>
<script>
/* Reveal the rest of the gallery. Progressive: with the script blocked the six
   thumbnails and the lightbox still work, the button simply does nothing — so
   it is removed rather than left as a control that lies. */
(function () {
    var gallery = document.getElementById('gallery-all');

    if (!gallery) { return; }

    /* THE DOOR OPENS THE VIEWER, it does not grow the grid.
     *
     * Revealing twenty-one tiles inside a column that now sits beside the
     * sidebar means a long scroll through small squares to find one photograph.
     * The viewer is where photographs belong: full width, arrow keys, a counter,
     * Escape to leave.
     *
     * Nothing is bound to the tiles here. script.js already attaches a handler
     * to every [data-lightbox] on the page — including the hidden ones past the
     * ninth — so the door opens the viewer at its own photograph and the arrows
     * walk the whole set from there. The heading link just clicks the door.
     *
     * With the script blocked the door is still an <a> to a real photograph, so
     * it opens that photograph rather than doing nothing. */
    var link = document.getElementById('showAllPhotos');
    var door = gallery.querySelector('.is-door');

    if (link && door) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            door.click();
        });
    }

    /* The same pattern for the ratings list. Both are "show the rest of a set
       already on the page", so both work with the script blocked — the extra
       rows are simply visible from the start rather than hidden. */
    var ratingsButton = document.getElementById('showAllRatings');
    var ratingsFeed   = document.getElementById('ratingFeed');

    if (ratingsButton && ratingsFeed) {
        var ratingsLabel = ratingsButton.querySelector('.rating-feed__more-label');
        var ratingsCaret = ratingsButton.querySelector('i');

        ratingsButton.addEventListener('click', function () {
            var open = ratingsFeed.classList.toggle('is-open');

            ratingsButton.setAttribute('aria-expanded', open ? 'true' : 'false');

            if (ratingsLabel) {
                ratingsLabel.textContent = ratingsButton.dataset[open ? 'less' : 'more'];
            }

            if (ratingsCaret) {
                ratingsCaret.className = open
                    ? 'fa-solid fa-chevron-up'
                    : 'fa-solid fa-chevron-down';
            }

            /* Collapsing from the bottom of a long list would otherwise leave the
               viewport below everything that is left, looking like a blank page.
               Only when collapsing, and only if the control has scrolled out of
               sight — moving the page under somebody who can already see the
               button is its own kind of rude. */
            if (!open && ratingsButton.getBoundingClientRect().top < 0) {
                ratingsButton.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        });
    }

})();

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

<!-- =========================================================================
     THE TOURISM ASSISTANT
     Every public page carries it. A visitor reading an advisory, planning a
     route or filling in a guide request has the same questions as one on the
     home page, and should not have to go back to ask them.
     ====================================================================== -->
<?php require __DIR__ . '/app/views/partials/chat-widget.php'; ?>

</body>
</html>
