<?php
/**
 * =============================================================================
 *  TAMPAKAN TOURISM PORTAL  Public Landing Page
 *  Municipality of Tampakan, South Cotabato, Philippines
 * -----------------------------------------------------------------------------
 *  This is the PUBLIC-FACING tourism website only. No admin dashboard and no
 *  backend logic live here. Content is held in plain PHP arrays below so that a
 *  future release can swap each array for a database query without touching the
 *  markup underneath.
 *
 *  Stack : HTML5 Â· Bootstrap 5 Â· CSS3 Â· Vanilla JS Â· Font Awesome Â· AOS Â· Leaflet
 *  Assets: assets/css/style.css Â· assets/js/script.js
 * =============================================================================
 */

declare(strict_types=1);

/* Phase 1: the page now reads live data. bootstrap.php supplies the
   configuration, the database connection, and the e() helper. */
require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Core\Weather;
use App\Repositories\AnnouncementRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\DestinationRepository;
use App\Repositories\FeedbackRepository;
use App\Repositories\HeroSlideRepository as HeroSlides;

/* Contact form state, carried across the redirect from api/contact/submit.php.
 *
 * The form used to be handled entirely in the browser and threw every message
 * away. It now posts to a real endpoint, which means it can also come BACK with
 * errors — and a visitor who has to retype a five-line message because their
 * email had a typo in it will not retype it. */
$contactFlashes = App\Core\Session::takeFlash();

$contactOld = App\Core\Session::get('_contact_old', []);
App\Core\Session::forget('_contact_old');
$contactOld = is_array($contactOld) ? $contactOld : [];

$contactErrors = App\Core\Session::get('_contact_errors', []);
App\Core\Session::forget('_contact_errors');
$contactErrors = is_array($contactErrors) ? $contactErrors : [];

$cfOld = static fn(string $key): string => (string) ($contactOld[$key] ?? '');

/* -----------------------------------------------------------------------------
 | Site-wide configuration
 * -------------------------------------------------------------------------- */
$site = [
    'name'        => 'Tampakan Tourism',
    'municipality'=> 'Municipality of Tampakan',
    'province'    => 'South Cotabato, Philippines',
    'tagline'     => 'Discover the Beauty of Tampakan',
    'description' => 'Official tourism portal of the Municipality of Tampakan, South Cotabato — explore highland destinations, festivals, eco-tourism trails, and travel guides.',
    'url'         => 'https://tourism.tampakan.gov.ph',
    'admin_url'   => 'admin/login.php',   // Handled by a separate module.
    'lat'         => 6.4333,              // Tampakan municipal hall (approx.)
    'lng'         => 124.9167,
];

/* e() now comes from app/helpers.php so the public site and the admin
   area escape output through exactly the same function. */

/* img() now lives in app/helpers.php so every public page shares the same
   photograph fallback. */

/* Navigation now lives in public_nav() (app/helpers.php) so the navbar,
   the footer, and every other page share one definition. */


/* -----------------------------------------------------------------------------
 | Hero video  —  optional, drop-in.
 |
 | If assets/video/hero.mp4 exists the hero plays it behind the rotating
 | headlines; if not, the photo slider below carries the section on its own.
 | Nothing needs editing either way.
 |
 | The <source> URLs are held in data attributes rather than the src, so the
 | browser downloads nothing until script.js decides the visitor should have
 | it. A tourist on mobile data in an upland barangay pays for every megabyte,
 | and they get the poster image instead.
 * -------------------------------------------------------------------------- */
$heroVideo = [];

/* NO VIDEO BEHIND THE HOMEPAGE, deliberately.
 *
 * This read a hero clip from promo_videos until the office decided a video
 * belongs on the page of the place it is about and nowhere else. A muted film
 * of Jadas Falls playing behind the homepage is exactly the "video appearing
 * somewhere other than its destination" that rule exists to prevent.
 *
 * The array is kept rather than the branches below it being torn out: the hero
 * markup already handles an empty one by falling back to the photograph slider,
 * which is now the only thing it does. If a municipal-level background is ever
 * wanted again, this is the one place that has to change.
 */

/* Poster: a real destination photo if one has been uploaded, so the still
   frame shown before playback is of Tampakan rather than stock imagery.
 *
 * Several rows are read rather than one, because the newest row is not
 * necessarily the one whose file still exists on disk. uploaded_url() returns
 * null for the ones that have gone missing and the loop moves on. */
$heroPoster = null;

foreach (Database::all(
    "SELECT p.file_path FROM destination_photos p
       JOIN destinations d ON d.id = p.destination_id
      WHERE d.status = 'active'
      ORDER BY p.is_cover DESC, p.id ASC LIMIT 10"
) as $row) {
    if ($heroPoster = uploaded_url($row['file_path'])) {
        break;
    }
}

/* ------------------------ 2-----------------------------------------------------
 | Hero slider
 * -------------------------------------------------------------------------- */
/* THE HERO COMES FROM THE hero_slides TABLE, WHICH THE OFFICE OWNS.
 *
 * It was three hard-coded entries here, illustrated with stock photographs
 * fetched from images.unsplash.com on every page load — a mountain that is not
 * Tampakan, on Tampakan's own front page, and blank whenever that CDN was slow.
 * The office can now add, reorder, draft and photograph its own slides in
 * Settings › Public site.
 *
 * published() returns only the live slides that have some words on them: a
 * published slide with an empty caption is reachable (upload the picture, save,
 * go to lunch) and a blank pane sliding across the front page reads as a broken
 * site rather than an unfinished one.
 *
 * The stock IDs survive as the LAST fallback, used by position, so a slide whose
 * photograph has not been uploaded yet still shows something rather than a grey
 * rectangle. Modulo, so a fourth slide wraps instead of reading past the end. */
$heroFallbacks = [
    '1501785888041-af3ef285b470',
    '1441974231531-c6227db76b6e',
    '1533105079780-92b9be482077',
];

$heroSlides = [];

foreach (HeroSlides::published() as $i => $heroRow) {
    $heroImage = trim((string) $heroRow['image_path']);

    /* uploaded_url() returns null for a row whose file has gone missing from
       disk, so a deleted photograph falls back rather than printing a broken
       image on the front page. */
    $heroSrc = $heroImage !== '' ? uploaded_url($heroImage) : null;

    $heroSlides[] = [
        'image'   => $heroSrc ?? img($heroFallbacks[$i % count($heroFallbacks)], 1920, 1080),
        'eyebrow' => (string) $heroRow['eyebrow'],
        /* The title may carry one <span> for the emphasised word, so it is not
           escaped here. It is officer-entered and officer-only — the same trust
           the announcement body and the heritage text already have. */
        'title'   => (string) $heroRow['title'],
        'text'    => (string) $heroRow['body'],
    ];
}

/* AN EMPTY ROTATION IS STILL A HOMEPAGE.
 *
 * Every slide deleted, or every one moved to draft, must not leave the front
 * page with a carousel of nothing — the markup below indexes $heroSlides[0] for
 * the reduced-motion poster and for og:image. One stock slide carrying the words
 * this site shipped with, so the page stays whole while the office decides what
 * to put there. */
if ($heroSlides === []) {
    $heroSlides[] = [
        'image'   => img($heroFallbacks[0], 1920, 1080),
        'eyebrow' => 'Welcome to South Cotabato&rsquo;s Highland Heart',
        'title'   => 'Discover the Beauty of <span class="hero__title-em">Tampakan</span>',
        'text'    => 'Where cool mountain air, rolling highlands, and the living traditions '
                   . 'of the B&rsquo;laan people meet a warm municipal welcome.',
    ];
}

/* A poster is not decoration here: a visitor who has asked for reduced motion
   never sees the video play, so the poster IS their hero. If no destination
   photo has been uploaded yet, fall back to the first slide image.
   Placed after $heroSlides is defined — referencing it earlier resolved to
   null silently, because ?? suppresses the undefined-variable warning. */
if ($heroVideo !== [] && $heroPoster === null) {
    $heroPoster = $heroSlides[0]['image'] ?? null;
}


/* -----------------------------------------------------------------------------
 | Destination catalogue  —  LIVE, searchable, filterable.
 |
 | This section used to show six featured teasers and send everybody to
 | destinations.php for the real list. That page is gone: a visitor who wants
 | to find a place should not have to notice a button, load a second page, and
 | arrive somewhere that looks almost the same. The search box and the category
 | filter now sit directly above the cards on the homepage.
 |
 | Both controls remain plain GET parameters, so ?category=waterfalls is a URL
 | the Tourism Office can put on a poster and the catalogue still works with
 | scripting unavailable. What changed is that the query no longer does the
 | filtering for the visible page: every published destination is fetched and
 | rendered once, and the filter decides which cards are shown.
 |
 | The reason is the same one that drove the announcement chips. Filtering
 | server-side meant every chip click and every search reloaded the homepage —
 | tearing down a background video, a carousel and a Leaflet map to change a
 | grid two sections above them. The page flashed on every click.
 * -------------------------------------------------------------------------- */
$search       = trim((string) ($_GET['q'] ?? ''));
$categorySlug = trim((string) ($_GET['category'] ?? ''));

$activeCategory = $categorySlug !== '' ? CategoryRepository::findBySlug($categorySlug) : null;

/* A slug nobody recognises would otherwise filter silently to nothing and
   leave the chip row showing "All" as active, which reads as an empty
   database rather than a bad link. */
if ($activeCategory === null) {
    $categorySlug = '';
}

$isFiltered = $search !== '' || $activeCategory !== null;

$destinationRows = DestinationRepository::published();

$categories = CategoryRepository::withDestinations();

$destinations = array_map(static function (array $row): array {
    $meta = array_filter([
        $row['barangay'] ? 'Barangay ' . $row['barangay'] : null,
        $row['operating_hours'] ?: null,
    ]);

    return [
        'slug'     => $row['slug'],
        'name'     => $row['name'],
        'category' => $row['category_name'] ?: 'Destination',
        'categorySlug' => (string) ($row['category_slug'] ?? ''),
        'image'    => uploaded_url($row['cover_photo'])
                        ?? img('1464822759023-fed622ff2c3b'),
        'excerpt'  => $row['short_description'] ?: 'Details are being prepared by the Tourism Office.',
        'meta'     => $meta ? implode(' · ', $meta) : 'Tampakan, South Cotabato',
        'rating'   => (float) $row['avg_rating'],
        'reviews'  => (int) $row['review_count'],

        /* The three columns the SQL LIKE used to search, joined and folded to
           lower case so the client matches on exactly the same text the server
           did. Searching what is merely printed on the card would quietly
           change the results — the excerpt is truncated and the barangay is
           formatted, so neither is the raw column. */
        'haystack' => mb_strtolower(trim(implode(' ', array_filter([
            $row['name'],
            $row['barangay'],
            $row['short_description'],
        ])))),
    ];
}, $destinationRows);

/* The filter rule, stated once. Mirrored in JavaScript at the foot of the file;
   if one changes the other has to change with it. Both conditions must hold,
   which is what makes searching inside a chosen category work. */
$destShows = static fn(array $d, string $cat, string $q): bool
    => ($cat === '' || $d['categorySlug'] === $cat)
    && ($q === '' || mb_strpos($d['haystack'], mb_strtolower($q)) !== false);

$destCount = count(array_filter(
    $destinations,
    static fn(array $d): bool => $destShows($d, $categorySlug, $search)
));

/* -----------------------------------------------------------------------------
 | Why visit — value propositions
 * -------------------------------------------------------------------------- */
$reasons = [
    ['icon' => 'fa-leaf',              'title' => 'Nature',        'text' => 'Highland forests, cloud-wrapped ridges, and cold mountain springs kept green year-round.'],
    ['icon' => 'fa-person-hiking',     'title' => 'Adventure',     'text' => 'Trekking circuits, waterfall trails, camping decks, and ridge rides for every skill level.'],
    ['icon' => 'fa-drum',              'title' => 'Culture',       'text' => 'Living B&rsquo;laan traditions — weaving, beadwork, music, and festivals held all year.'],
    ['icon' => 'fa-utensils',          'title' => 'Local Cuisine', 'text' => 'Farm-fresh highland produce, native delicacies, and celebrated single-origin coffee.'],
    ['icon' => 'fa-hand-holding-heart','title' => 'Hospitality',   'text' => 'A community that welcomes every visitor as a guest of the whole municipality.'],
    ['icon' => 'fa-seedling',          'title' => 'Eco Tourism',   'text' => 'Community-managed sites, reforestation programs, and low-impact visitor practices.'],
];

/* -----------------------------------------------------------------------------
 | Upcoming events  —  LIVE from published announcements of type "event".
 |
 | Past events drop off on their own; nobody has to remember to remove them.
 * -------------------------------------------------------------------------- */
$events = [];

foreach (AnnouncementRepository::upcomingEvents(3) as $row) {
    $when = $row['event_date'] ? strtotime($row['event_date']) : strtotime($row['created_at']);

    $events[] = [
        'slug'     => $row['slug'],
        'title'    => $row['title'],
        // Machine-readable date for the <time datetime> attribute. The day,
        // month, and year below are for people; this one is for search
        // engines and assistive technology.
        'iso'      => date('Y-m-d', $when),
        'image'    => $row['banner_path'] ? base_url($row['banner_path']) : img('1533174072545-7a4b6ad7a6c3'),
        'day'      => date('d', $when),
        'month'    => date('M', $when),
        'year'     => date('Y', $when),
        'location' => $row['event_location'] ?: ($row['destination_name'] ?: 'Tampakan, South Cotabato'),
        'excerpt'  => $row['summary'] ?: mb_substr(strip_tags($row['body']), 0, 150),
    ];
}

/* -----------------------------------------------------------------------------
 | Map markers  —  LIVE. Only destinations with coordinates appear.
 * -------------------------------------------------------------------------- */
$mapMarkers = array_map(static fn(array $row): array => [
    'name' => $row['name'],
    'lat'  => (float) $row['latitude'],
    'lng'  => (float) $row['longitude'],
    'type' => $row['category_name'] ?: 'Destination',
], DestinationRepository::mapMarkers());

/* The office marker is always shown, so an empty map still orients the visitor. */
if ($mapMarkers === []) {
    $mapMarkers[] = [
        'name' => 'Municipal Tourism Office',
        'lat'  => $site['lat'],
        'lng'  => $site['lng'],
        'type' => 'Office',
    ];
}

/* -----------------------------------------------------------------------------
 | News and advisories  —  LIVE, and the full feed rather than a teaser of three.
 |
 | This section absorbed announcements.php, so it answers the whole question:
 | every published notice, filterable by type, with the chips that used to sit
 | on that page. The type comes off the query string the same way the category
 | filter for the catalogue does, and announcements_url() builds the links.
 * -------------------------------------------------------------------------- */
$newsType = (string) ($_GET['type'] ?? '');

/* An unrecognised ?type= is treated as no filter rather than as a filter that
   matches nothing — a stale or hand-edited link should show the feed, not an
   empty section with a "no results" panel. */
if ($newsType !== '' && !isset(AnnouncementRepository::TYPES[$newsType])) {
    $newsType = '';
}

/* Every type is fetched and every card is rendered, whatever the filter says.
 *
 * The chips used to reload the homepage to change six cards, which meant the
 * hero video, the carousel and the Leaflet map all tore down and rebuilt — the
 * flash the whole page made on every click. The cards are all in the DOM now
 * and the filter only decides which are shown, so switching type costs nothing
 * but a class change.
 *
 * The limit rises with the scope: 30 was per type, this is across all six. */
$newsRows = AnnouncementRepository::publicFeed(null, 60);

$news = [];

foreach ($newsRows as $row) {
    $news[] = [
        'slug'  => $row['slug'],
        'type'  => $row['type'],
        'tag'   => AnnouncementRepository::TYPES[$row['type']] ?? 'Announcement',
        'date'  => format_date($row['publish_at'] ?: $row['created_at']),
        'title' => $row['title'],
        'text'  => $row['summary'] ?: mb_substr(strip_tags($row['body']), 0, 165),
        'image' => $row['banner_path'] ? base_url($row['banner_path']) : img('1490682143684-14369e18dce8', 900, 600),
    ];
}

/* The visibility rule, written once.
 *
 * PHP applies it for the first paint so the page is correct before any script
 * runs — and correct with no script at all, since the chips remain real links.
 * The same two lines are mirrored in JavaScript at the foot of this file; if
 * one changes the other has to change with it.
 *
 * "All" hides events because they already have their own dated section above,
 * and a notice appearing twice on one page reads as two notices. Choosing the
 * Tourism Event chip is an explicit request for them, so there they stay. */
$newsShows = static fn(string $type, string $filter): bool
    => $filter === '' ? $type !== 'event' : $type === $filter;

$newsCount = count(array_filter(
    $news,
    static fn(array $n): bool => $newsShows($n['type'], $newsType)
));

/* -----------------------------------------------------------------------------
 | Photo gallery  —  LIVE destination photographs when any exist.
 |
 | Falls back to placeholders while the Tourism Office is still uploading, so
 | the section never renders empty during the transition.
 * -------------------------------------------------------------------------- */
$galleryRows = Database::all(
    "SELECT p.file_path, p.caption, d.name
       FROM destination_photos p
       JOIN destinations d ON d.id = p.destination_id
      WHERE d.status = 'active'
      ORDER BY p.is_cover DESC, p.id DESC
      LIMIT 12"
);

$gallery = [];
foreach ($galleryRows as $row) {
    /* A row whose file has gone missing is skipped rather than rendered as a
       broken tile — and if that empties the gallery, the placeholders below
       take over exactly as they do before the first upload. */
    if (($url = uploaded_url($row['file_path'])) === null) {
        continue;
    }

    $gallery[] = [
        'src'     => $url,
        'full'    => $url,
        'caption' => $row['caption'] ?: $row['name'],
    ];
}

/* Placeholders only while no photographs have been uploaded. */
if ($gallery === []) {
    $placeholders = [
        ['1501785888041-af3ef285b470', 'Sunrise over the highland ridge'],
        ['1433086966358-54859d0ed716', 'Waterfalls of the municipality'],
        ['1475924156734-496f6cac6ec1', 'Highland arabica harvest'],
        ['1464822759023-fed622ff2c3b', 'Sea of clouds at daybreak'],
        ['1470071459604-3b5ec3a7fe05', 'Forest paths of the reserve'],
        ['1523805009345-7448845a9e53', 'Trekking the Matutum approach'],
        ['1502082553048-f009c37129b9', 'Pine stands above the valley'],
        ['1426604966848-d7adac402bff', 'The valley seen from Liberty'],
        ['1465146344425-f00d5f5c8f07', 'Highland blooms in season'],
        ['1441974231531-c6227db76b6e', 'Afternoon light through the canopy'],
        ['1469474968028-56623f02e42e', 'Golden hour on the summit trail'],
        ['1470770841072-f978cf4d019e', 'Camp night beside the water'],
    ];
    foreach ($placeholders as $i => $item) {
        $gallery[] = [
            'src'     => img($item[0], 800, $i % 3 === 0 ? 1000 : 640),
            'full'    => img($item[0], 1600, 1100),
            'caption' => $item[1],
        ];
    }
}

/* -----------------------------------------------------------------------------
 | Visitor testimonials  —  LIVE, and only what a real visitor wrote.
 |
 | Drawn from moderated reviews submitted after a logged visit, so every quote
 | on the homepage belongs to somebody who demonstrably stood at the site.
 | When none exist yet the section is hidden entirely rather than filled with
 | invented praise — a fabricated review on a municipal page is a real problem,
 | not a placeholder.
 * -------------------------------------------------------------------------- */
$testimonials = [];

foreach (FeedbackRepository::featured(6) as $row) {
    $origin = array_filter([$row['origin_city'], $row['origin_province'], $row['origin_country']]);

    $testimonials[] = [
        'name'   => $row['visitor_name'] ?: 'Verified visitor',
        'origin' => $origin ? implode(', ', array_slice($origin, 0, 2)) : $row['destination_name'],
        'rating' => (int) $row['rating'],
        'quote'  => (string) $row['comment'],
        'photo'  => null,
    ];
}

/* -----------------------------------------------------------------------------
 | Animated statistics  —  destination count and arrivals are LIVE.
 |
 | Events and years remain fixed values: announcements arrive in Phase 4, and
 | the founding year is a fact the Tourism Office must supply.
 * -------------------------------------------------------------------------- */
/* Weather for the municipality. Cached server-side, so a slow forecast
   service can never hold up the homepage. */
$weather = Weather::forecast();
$weatherPlace = 'Tampakan, South Cotabato';

$liveDestinations = DestinationRepository::countActive();
$liveArrivals     = (int) Database::scalar(
    "SELECT COALESCE(SUM(total_visitors), 0) FROM tourist_arrivals WHERE status = 'valid'"
);

$stats = [
    ['icon' => 'fa-map-location-dot', 'value' => $liveDestinations, 'suffix' => '', 'label' => 'Tourist Destinations'],
    ['icon' => 'fa-users',            'value' => $liveArrivals,     'suffix' => '', 'label' => 'Recorded Arrivals'],
    ['icon' => 'fa-calendar-star',    'value' => 16,                'suffix' => '', 'label' => 'Tourism Events'],
    ['icon' => 'fa-award',            'value' => 25,                'suffix' => '', 'label' => 'Years Promoting Tourism'],
];

/* -----------------------------------------------------------------------------
 | Travel guide cards
 * -------------------------------------------------------------------------- */
$travelGuide = [
    [
        'icon'  => 'fa-plane-departure', 'title' => 'How to Get Here',
        'text'  => 'Fly into General Santos (GES) or Koronadal, then travel overland to Tampakan.',
        'items' => ['GenSan to Tampakan: approx. 1 hr 30 min', 'Koronadal to Tampakan: approx. 40 min', 'Daily vans and buses via the Marbel route'],
    ],
    [
        'icon'  => 'fa-van-shuttle', 'title' => 'Transportation',
        'text'  => 'Getting around the municipality is straightforward and inexpensive.',
        'items' => ['Tricycles within Poblacion', 'Habal-habal for upland barangays', 'Van rentals for group day tours'],
    ],
    [
        'icon'  => 'fa-bed', 'title' => 'Accommodation',
        'text'  => 'Choose between town lodging and immersive highland stays.',
        'items' => ['Inns and pension houses in Poblacion', 'Community-run homestays', 'Designated eco-camping grounds'],
    ],
    [
        'icon'  => 'fa-shield-heart', 'title' => 'Safety Tips',
        'text'  => 'A few simple habits keep every highland trip trouble-free.',
        'items' => ['Register at the visitor desk before trekking', 'Hire accredited local guides', 'Pack layers — nights drop below 18Â°C'],
    ],
    [
        'icon'  => 'fa-cloud-sun', 'title' => 'Best Time to Visit',
        'text'  => 'Tampakan is pleasant year-round, with two standout windows.',
        'items' => ['November to April: driest, clearest views', 'September: founding anniversary week', 'Sunrise treks: arrive on-site by 4:30 AM'],
    ],
];

/* -----------------------------------------------------------------------------
 | Contact details
 * -------------------------------------------------------------------------- */
$contact = [
    'address'    => 'Municipal Tourism Office, Municipal Hall Compound, Poblacion, Tampakan, South Cotabato 9507',
    'phone'      => '(083) 228-1234',
    'mobile'     => '+63 917 123 4567',
    'email'      => 'tourism@tampakan.gov.ph',
    'facebook'   => 'https://www.facebook.com/',
    'fb_label'   => 'facebook.com/TampakanTourism',
    'hours'      => 'Monday to Friday, 8:00 AM – 5:00 PM',
    'hours_note' => 'Closed on weekends and national holidays',
];

$currentYear = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="theme-color" content="#2E7D32">

    <!-- ===================== SEO metadata ===================== -->
    <title><?= e($site['tagline']) ?> | <?= e($site['municipality']) ?>, South Cotabato</title>
    <meta name="description" content="<?= e($site['description']) ?>">
    <meta name="keywords" content="Tampakan tourism, South Cotabato, Mindanao travel, Mt. Matutum, B'laan culture, eco-tourism Philippines, Tampakan destinations">
    <meta name="author" content="Municipal Tourism Office of Tampakan">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?= e($site['url']) ?>/">

    <!-- Open Graph / social sharing -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= e($site['name']) ?>">
    <meta property="og:title" content="<?= e($site['tagline']) ?> | <?= e($site['municipality']) ?>">
    <meta property="og:description" content="<?= e($site['description']) ?>">
    <meta property="og:image" content="<?= e($heroSlides[0]['image']) ?>">
    <meta property="og:url" content="<?= e($site['url']) ?>/">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="icon" href="assets/img/tampakan_logo.png" sizes="any">

    <!-- ===================== Fonts ===================== -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Dancing+Script:wght@600;700&display=swap" rel="stylesheet">

    <!-- ===================== Third-party stylesheets ===================== -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.4/dist/aos.css" rel="stylesheet">
    <link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">

    <!-- ===================== Project stylesheet ===================== -->
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">

    <!-- ===================== Structured data (JSON-LD) ===================== -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "GovernmentOrganization",
      "name": "Municipal Tourism Office of Tampakan",
      "url": "<?= e($site['url']) ?>",
      "logo": "<?= e($site['url']) ?>/assets/img/tampakan_logo.png",
      "email": "<?= e($contact['email']) ?>",
      "telephone": "<?= e($contact['phone']) ?>",
      "address": {
        "@type": "PostalAddress",
        "streetAddress": "Municipal Hall Compound, Poblacion",
        "addressLocality": "Tampakan",
        "addressRegion": "South Cotabato",
        "postalCode": "9507",
        "addressCountry": "PH"
      },
      "geo": { "@type": "GeoCoordinates", "latitude": <?= $site['lat'] ?>, "longitude": <?= $site['lng'] ?> }
    }
    </script>
</head>
<body id="top">

<!-- =========================================================================
     PRELOADER — removed by script.js once the window has loaded
     ====================================================================== -->
<div id="preloader" class="preloader" aria-hidden="true">
    <div class="preloader__inner">
        <div class="preloader__ring">
            <img src="assets/img/tampakan_logo.png" alt="" class="preloader__logo">
        </div>
        <p class="preloader__text">Tampakan Tourism</p>
        <span class="preloader__bar"><i></i></span>
    </div>
</div>

<!-- Reading-progress indicator -->

<!-- Accessibility: skip straight to the main content -->
<a href="#destinations" class="skip-link">Skip to main content</a>

<!-- =========================================================================
     NAVIGATION — transparent over the hero, solid once scrolled (script.js)
     ====================================================================== -->
<?php
/* The homepage uses the same navigation as every other page, in its
   transparent-over-hero state. Keeping a separate copy here is what let the
   two drift apart: the homepage had an Explore dropdown and a Gallery link no
   other page carried, and its "Destinations" scrolled to a section while the
   same word elsewhere opened the full listing. */
$navTransparent = true;
require __DIR__ . '/app/views/partials/public-nav.php';
?>

<main>

<!-- =========================================================================
     1 Â· HERO SLIDER
     ====================================================================== -->
<section id="home" class="hero <?= $heroVideo !== [] ? 'hero--video' : '' ?>" aria-label="Welcome">

    <?php if ($heroVideo !== []): ?>
        <?php $heroSources = e(json_encode($heroVideo)); ?>

        <!-- Decorative background. aria-hidden and muted: it carries no
             information and must never surprise anyone with sound.

             Two layers, one file. Footage shot on a phone is taller than it is
             wide, and a landscape hero cannot show it without either upscaling
             a 540-wide frame across 1920 pixels or cropping away most of the
             picture. Neither is acceptable, so the clip is shown at its own
             proportions and a heavily blurred copy of itself fills the space on
             either side — the treatment every vertical video gets on a wide
             screen, and it reads as deliberate rather than broken.

             The blurred layer is not free markup: script.js attaches its source
             only after the main layer reports that the footage really is
             portrait, and by then the file is in the browser cache, so the
             second copy costs no extra download. Landscape footage skips the
             whole arrangement and simply covers the hero as before. -->
        <video class="hero__video hero__video--fill" muted loop playsinline preload="none"
               aria-hidden="true" tabindex="-1"
               data-sources='<?= $heroSources ?>'></video>

        <video id="heroVideo" class="hero__video hero__video--main" muted loop playsinline preload="none"
               <?= $heroPoster ? 'poster="' . e($heroPoster) . '"' : '' ?>
               aria-hidden="true" tabindex="-1"
               data-sources='<?= $heroSources ?>'></video>
    <?php endif; ?>

    <div id="heroCarousel" class="carousel slide carousel-fade hero__carousel" data-bs-ride="carousel" data-bs-interval="6500">

        <div class="carousel-inner h-100">
            <?php foreach ($heroSlides as $i => $slide): ?>
            <div class="carousel-item h-100 <?= $i === 0 ? 'active' : '' ?>">
                <!-- Background layer carries the Ken Burns animation -->
                <?php if ($heroVideo === []): ?>
                    <div class="hero__bg" style="background-image:url('<?= e($slide['image']) ?>')" role="img"
                         aria-label="<?= e(strip_tags($slide['title'])) ?>"></div>
                <?php endif; ?>
                <div class="hero__overlay"></div>

                <div class="container hero__container">
                    <div class="hero__content">
                        <span class="hero__eyebrow"><i class="fa-solid fa-location-dot"></i> <?= $slide['eyebrow'] ?></span>
                        <h1 class="hero__title"><?= $slide['title'] ?></h1>
                        <p class="hero__text"><?= $slide['text'] ?></p>
                        <div class="hero__actions">
                            <a href="#destinations" class="btn btn-primary-grad btn-lg">
                                <i class="fa-solid fa-compass"></i> Explore Destinations
                            </a>
                            <a href="#travel-guide" class="btn btn-glass btn-lg">
                                <i class="fa-regular fa-calendar-check"></i> Plan Your Visit
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- The prev/next chevrons are gone. The slide is a backdrop, not a
             gallery: nobody arrives wanting to page through photographs of the
             municipality, and the arrows sat on top of the hero text and the
             CTA buttons on a phone for a control almost nobody used.

             Navigation is not lost. The carousel still advances on its own, and
             the indicators below remain real buttons — so a keyboard or screen
             reader user can still reach any slide directly, which the arrows
             only ever offered one step at a time. -->
        <div class="carousel-indicators hero__indicators">
            <?php foreach ($heroSlides as $i => $slide): ?>
            <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="<?= $i ?>"
                    class="<?= $i === 0 ? 'active' : '' ?>" aria-label="Slide <?= $i + 1 ?>"
                    <?= $i === 0 ? 'aria-current="true"' : '' ?>></button>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Glassmorphic quick-facts strip anchored to the base of the hero -->
    <div class="hero__facts">
        <div class="container">
            <div class="glass-strip">
                <div class="glass-strip__item">
                    <i class="fa-solid fa-mountain"></i>
                    <div><strong>500&ndash;1,200 m</strong><span>Elevation range</span></div>
                </div>
                <!-- The live reading, not a hardcoded range.
                     This slot used to claim "21°C – 28°C, year-round climate"
                     while the #weather section further down the same page
                     reported the actual temperature — so on a hot afternoon the
                     homepage contradicted itself, and the invented figure was
                     the one a visitor saw first.

                     It answers only the three-second question: is it nice out
                     right now. The five-day outlook is a different question and
                     stays in its own section, one tap away. -->
                <?php if ($weather !== null): ?>
                    <a class="glass-strip__item glass-strip__item--live" href="#weather">
                        <i class="fa-solid <?= e($weather['icon']) ?>"></i>
                        <div>
                            <strong><?= (int) $weather['temperature'] ?>&deg;C</strong>
                            <span>
                                <?= e($weather['label']) ?> now
                                <i class="fa-solid fa-angle-right"></i>
                            </span>
                        </div>
                    </a>
                <?php else: ?>
                    <!-- Forecast service unreachable and nothing cached. The
                         static range is wrong in the specifics but right in the
                         general, which beats an empty cell in the strip. -->
                    <div class="glass-strip__item">
                        <i class="fa-solid fa-temperature-half"></i>
                        <div><strong>21&deg;C &ndash; 28&deg;C</strong><span>Year-round climate</span></div>
                    </div>
                <?php endif; ?>
                <div class="glass-strip__item">
                    <i class="fa-solid fa-map-pin"></i>
                    <div><strong>13 Barangays</strong><span>Across the municipality</span></div>
                </div>
                <div class="glass-strip__item">
                    <i class="fa-solid fa-road"></i>
                    <div><strong>40 minutes</strong><span>From Koronadal City</span></div>
                </div>
            </div>
        </div>
    </div>

    <a href="#destinations" class="hero__scroll" aria-label="Scroll to content">
        <span class="hero__mouse"><span></span></span>
    </a>
</section>

<!-- =========================================================================
     2 Â· DESTINATIONS  —  the full catalogue, searchable and filterable
     ====================================================================== -->
<section id="destinations" class="section section--light">
    <div class="container">

        <div class="section-head">
            <span class="eyebrow"><i class="fa-solid fa-compass"></i> Where to Go</span>
            <h2 class="section-title">Explore <span class="text-grad">Destinations</span></h2>
            <p class="section-sub">From cloud-covered peaks to hidden waterfalls and living cultural villages &mdash;
               every place in Tampakan that is open to visitors, in one list.</p>
        </div>

        <!-- Search and category filter.
             The form still submits: with no JavaScript it reloads the homepage
             with ?q= and the fragment returns the visitor to this section
             rather than the hero. The script at the foot of the page takes over
             when it can, filtering the cards as the visitor types and never
             navigating — so the video, the carousel and the map stay up. -->
        <form class="explore-filters" method="get" action="<?= e(base_url('/')) ?>#destinations"
              id="destForm">
            <div class="explore-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" name="q" value="<?= e($search) ?>" id="destSearch"
                       placeholder="Search destinations, barangays, or activities"
                       aria-label="Search destinations" autocomplete="off">
            </div>

            <?php /* Keeps the chosen category while searching within it. The
                     script keeps this in step as the chips are clicked, so a
                     no-JS submit after a category choice still narrows. */ ?>
            <input type="hidden" name="category" value="<?= e($categorySlug) ?>" id="destCategory"
                   <?= $categorySlug === '' ? 'disabled' : '' ?>>

            <button type="submit" class="btn btn-primary-grad">Search</button>
        </form>

        <div class="chip-row" id="destChips">
            <a href="<?= e(destinations_url(['q' => $search])) ?>" data-dest-filter=""
               class="chip <?= $activeCategory === null ? 'is-active' : '' ?>">All</a>

            <?php foreach ($categories as $c): ?>
                <a href="<?= e(destinations_url(['category' => $c['slug'], 'q' => $search])) ?>"
                   data-dest-filter="<?= e($c['slug']) ?>"
                   class="chip <?= ($activeCategory['id'] ?? null) === $c['id'] ? 'is-active' : '' ?>">
                    <?php if ($c['icon']): ?><i class="fa-solid <?= e($c['icon']) ?>"></i><?php endif; ?>
                    <?= e($c['name']) ?>
                    <em><?= n($c['destination_count']) ?></em>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Rendered once and then addressed by the script rather than rebuilt.
             Two empty states, because they mean opposite things: nothing
             matched a filter, versus nothing has been published at all. Only
             the first is the visitor's doing, and only the first offers a way
             out of it. -->
        <p class="explore-count" id="destCount" <?= $isFiltered && $destCount > 0 ? '' : 'hidden' ?>>
            <span id="destCountText"><?php if ($isFiltered && $destCount > 0): ?><?=
                n($destCount) ?> <?= $destCount === 1 ? 'destination' : 'destinations' ?> found<?php
                ?><?= $activeCategory !== null ? ' in ' . e($activeCategory['name']) : '' ?><?php
                ?><?= $search !== '' ? ' for &ldquo;' . e($search) . '&rdquo;' : '' ?>.<?php endif; ?></span>
            <a href="<?= e(destinations_url()) ?>" data-dest-filter="" data-dest-clear>Clear filters</a>
        </p>

        <div class="empty-public" id="destEmpty" <?= $destCount === 0 ? '' : 'hidden' ?>>
            <i class="fa-solid fa-mountain-sun"></i>
            <h3 id="destEmptyTitle"><?= $destinations === [] && !$isFiltered
                ? 'Destinations are being prepared'
                : 'No destinations match that search' ?></h3>
            <p>
                <span id="destEmptyText"><?= $destinations === [] && !$isFiltered
                    ? 'The Municipal Tourism Office is currently registering the municipality&rsquo;s destinations. Please check back shortly.'
                    : 'Try a different term, or' ?></span>
                <a href="<?= e(destinations_url()) ?>" data-dest-filter="" data-dest-clear
                   id="destEmptyClear" <?= $destinations === [] && !$isFiltered ? 'hidden' : '' ?>>browse everything</a>
            </p>
        </div>

        <?php /* Was `row g-4`: three across, and a new row for every three more
                 destinations. The section is a fixed height now however many
                 there are. The col-* classes are gone with it — inside the
                 strip they would set a width that fights its own track.

                 The arrows sit on the edges of the strip rather than in a bar
                 above it, and the dots below say which page this is. Both are
                 hidden until the script has counted the cards: with less than
                 a page of them there is nowhere to go, and with JavaScript off
                 the strip is swiped or scrolled instead. */ ?>
        <div class="rail-wrap">
            <button type="button" class="rail-nav rail-nav--prev" data-rail-prev="destGrid"
                    aria-label="Previous destinations" hidden>
                <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
            </button>

            <div class="rail" id="destGrid" data-rail data-rail-dots="destRailDots"
                 tabindex="0" role="group" aria-label="Destinations">
            <?php foreach ($destinations as $d): ?>
                <div class="dest-item"
                     data-dest-category="<?= e($d['categorySlug']) ?>"
                     data-dest-haystack="<?= e($d['haystack']) ?>"
                     <?= $destShows($d, $categorySlug, $search) ? '' : 'hidden' ?>>
                    <article class="dest-card">
                        <div class="dest-card__media">
                            <img src="<?= e($d['image']) ?>" alt="<?= e(strip_tags($d['name'])) ?>, Tampakan"
                                 loading="lazy" width="1200" height="800">
                            <span class="dest-card__badge"><?= e($d['category']) ?></span>
                        </div>
                        <div class="dest-card__body">
                            <h3 class="dest-card__title"><?= e($d['name']) ?></h3>
                            <p class="dest-card__meta"><i class="fa-solid fa-location-dot"></i> <?= e($d['meta']) ?></p>

                            <?php if ($d['rating'] > 0): ?>
                                <p class="dest-card__rating">
                                    <?php for ($s = 1; $s <= 5; $s++): ?>
                                        <i class="fa-<?= $s <= round($d['rating']) ? 'solid' : 'regular' ?> fa-star"></i>
                                    <?php endfor; ?>
                                    <span><?= e((string) $d['rating']) ?> (<?= n($d['reviews']) ?>)</span>
                                </p>
                            <?php endif; ?>

                            <p class="dest-card__text"><?= e($d['excerpt']) ?></p>
                            <a href="<?= e(base_url('/destination.php?slug=' . $d['slug'])) ?>" class="link-more">View Details <i class="fa-solid fa-arrow-right-long"></i></a>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
            </div>

            <button type="button" class="rail-nav rail-nav--next" data-rail-next="destGrid"
                    aria-label="More destinations" hidden>
                <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
            </button>
        </div>

        <?php /* Filled by the script: one dot per page, not per card. */ ?>
        <div class="rail-dots" id="destRailDots" role="tablist"
             aria-label="Destination pages" hidden></div>
    </div>
</section>

<!-- =========================================================================
     3 Â· WHY VISIT TAMPAKAN
     ====================================================================== -->
<section id="why-visit" class="section section--tint">
    <div class="container">

        <div class="section-head">
            <span class="eyebrow"><i class="fa-solid fa-heart"></i> Reasons to Come</span>
            <h2 class="section-title">Why Visit <span class="text-grad">Tampakan</span></h2>
            <p class="section-sub">Six good reasons the highlands of South Cotabato belong on your itinerary.</p>
        </div>

        <div class="row g-4">
            <?php foreach ($reasons as $i => $r): ?>
            <div class="col-lg-4 col-md-6">
                <div class="reason-card">
                    <div class="reason-card__icon"><i class="fa-solid <?= e($r['icon']) ?>"></i></div>
                    <h3 class="reason-card__title"><?= $r['title'] ?></h3>
                    <p class="reason-card__text"><?= $r['text'] ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- =========================================================================
     4 Â· UPCOMING EVENTS
     ====================================================================== -->
<section id="events" class="section section--light">
    <div class="container">

        <div class="section-head">
            <span class="eyebrow"><i class="fa-regular fa-calendar"></i> What&rsquo;s On</span>
            <h2 class="section-title">Upcoming <span class="text-grad">Events</span></h2>
            <p class="section-sub">Festivals, fairs, and cultural celebrations hosted across the municipality.</p>
        </div>

        <div class="row g-4">
            <?php foreach ($events as $i => $ev): ?>
            <div class="col-lg-4 col-md-6">
                <article class="event-card">
                    <div class="event-card__media">
                        <img src="<?= e($ev['image']) ?>" alt="<?= e(strip_tags($ev['title'])) ?> event banner"
                             loading="lazy" width="1200" height="800">
                        <time class="event-card__date" datetime="<?= e($ev['iso']) ?>">
                            <strong><?= e($ev['day']) ?></strong>
                            <span><?= e($ev['month']) ?></span>
                            <small><?= e($ev['year']) ?></small>
                        </time>
                    </div>
                    <div class="event-card__body">
                        <h3 class="event-card__title"><?= $ev['title'] ?></h3>
                        <p class="event-card__meta"><i class="fa-solid fa-location-dot"></i> <?= e($ev['location']) ?></p>
                        <p class="event-card__text"><?= $ev['excerpt'] ?></p>
                        <a href="<?= e(base_url('/announcement.php?slug=' . $ev['slug'])) ?>" class="btn btn-soft w-100">Learn More <i class="fa-solid fa-arrow-right-long"></i></a>
                    </div>
                </article>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- =========================================================================
     5 Â· INTERACTIVE TOURIST MAP PREVIEW (Leaflet — no API key required)
     ====================================================================== -->
<?php if ($weather !== null): ?>
<!-- =========================================================================
     WEATHER — live conditions for trip planning
     ====================================================================== -->
<section id="weather" class="section section--tint">
    <div class="container">

        <div class="section-head">
            <span class="eyebrow"><i class="fa-solid fa-cloud-sun"></i> Before You Set Out</span>
            <h2 class="section-title">Weather in <span class="text-grad">Tampakan</span></h2>
            <p class="section-sub">
                Live conditions and a five-day outlook for the municipality, so you can decide
                what to pack &mdash; and whether the highland trails are a good idea today.
            </p>
        </div>

        <?php require __DIR__ . '/app/views/partials/weather.php'; ?>
    </div>
</section>
<?php endif; ?>

<section id="map" class="section section--dark">
    <div class="container">
        <div class="row align-items-center g-5">

            <div class="col-lg-5">
                <span class="eyebrow eyebrow--light"><i class="fa-solid fa-map-location-dot"></i> Find Your Way</span>
                <h2 class="section-title section-title--light">Interactive <span class="text-grad-light">Tourist Map</span></h2>
                <p class="section-sub section-sub--light">
                    Every accredited destination, viewpoint, homestay, and cultural site in Tampakan, pinned and
                    ready to navigate. Select a marker for directions, opening hours, and guide contacts.
                </p>

                <ul class="map-legend">
                    <li><span class="dot dot--green"></span> Nature &amp; Eco-Tourism</li>
                    <li><span class="dot dot--blue"></span> Waterfalls &amp; Rivers</li>
                    <li><span class="dot dot--amber"></span> Culture &amp; Heritage</li>
                    <li><span class="dot dot--red"></span> Government Offices</li>
                </ul>

                <a href="<?= e(base_url('/map.php')) ?>" class="btn btn-primary-grad btn-lg mt-2">
                    <i class="fa-solid fa-expand"></i> Open Full Tourist Map
                </a>
            </div>

            <div class="col-lg-7">
                <div class="map-frame">
                    <!-- Leaflet renders here; markers arrive as JSON on the data attribute -->
                    <div id="touristMap"
                         data-center-lat="<?= $site['lat'] ?>"
                         data-center-lng="<?= $site['lng'] ?>"
                         data-markers='<?= e(json_encode($mapMarkers, JSON_UNESCAPED_UNICODE)) ?>'
                         aria-label="Interactive map of Tampakan tourist destinations"></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- =========================================================================
     6 Â· LATEST NEWS & ANNOUNCEMENTS
     ====================================================================== -->
<section id="news" class="section section--light">
    <div class="container">

        <div class="section-head">
            <span class="eyebrow"><i class="fa-regular fa-newspaper"></i> Stay Informed</span>
            <h2 class="section-title">Latest News &amp; <span class="text-grad">Announcements</span></h2>
            <p class="section-sub">Every notice published by the Municipal Tourism Office &mdash;
               advisories, closures, schedules, and reminders.</p>
        </div>

        <!-- The chips stay real links to ?type=…#news, so the filter works with
             no JavaScript at all and every filtered view has an address that
             can be shared or bookmarked. The script at the foot of the page
             upgrades them: it intercepts the click, shows and hides the cards
             already on the page, and rewrites the URL without navigating — so
             the hero, the carousel and the map are never rebuilt.

             Labels and icons come from the AnnouncementRepository constants, so
             a type added there appears here without anyone remembering to. -->
        <div class="chip-row chip-row--center" id="newsChips">
            <a href="<?= e(announcements_url()) ?>" data-news-filter=""
               class="chip <?= $newsType === '' ? 'is-active' : '' ?>">All</a>

            <?php foreach (AnnouncementRepository::TYPES as $value => $label): ?>
                <a href="<?= e(announcements_url(['type' => $value])) ?>"
                   data-news-filter="<?= e($value) ?>"
                   class="chip <?= $newsType === $value ? 'is-active' : '' ?>">
                    <i class="fa-solid <?= e(AnnouncementRepository::TYPE_STYLE[$value]['icon']) ?>"></i>
                    <?= e($label) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Rendered once and then addressed by the script rather than rebuilt:
             the count line, the empty panel and the grid are all permanent, and
             only their contents and hidden state change. -->
        <p class="explore-count" id="newsCount" <?= $newsType === '' || $newsCount === 0 ? 'hidden' : '' ?>>
            <span id="newsCountText"><?php if ($newsType !== '' && $newsCount > 0): ?><?=
                n($newsCount) ?> <?= $newsCount === 1 ? 'notice' : 'notices'
                ?> filed under <?= e(AnnouncementRepository::TYPES[$newsType]) ?>.<?php endif; ?></span>
            <a href="<?= e(announcements_url()) ?>" data-news-filter="">Clear filter</a>
        </p>

        <div class="empty-public" id="newsEmpty" <?= $newsCount > 0 ? 'hidden' : '' ?>>
            <i class="fa-solid fa-bullhorn"></i>
            <h3 id="newsEmptyTitle"><?= $newsType !== ''
                ? 'Nothing filed under ' . e(AnnouncementRepository::TYPES[$newsType])
                : 'No announcements at the moment' ?></h3>
            <p>
                <span id="newsEmptyText"><?= $newsType !== ''
                    ? 'No notice of this kind is currently in force.'
                    : 'When the Tourism Office publishes an advisory, a closure, or a schedule, it appears here.' ?></span>
                <a href="<?= e(announcements_url()) ?>" data-news-filter=""
                   id="newsEmptyClear" <?= $newsType === '' ? 'hidden' : '' ?>>See everything</a>
            </p>
        </div>

        <div class="rail-wrap">
            <button type="button" class="rail-nav rail-nav--prev" data-rail-prev="newsGrid"
                    aria-label="Previous announcements" hidden>
                <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
            </button>

            <div class="rail" id="newsGrid" data-rail data-rail-dots="newsRailDots"
                 tabindex="0" role="group" aria-label="Announcements">
            <?php foreach ($news as $n): ?>
            <div class="news-item" data-news-type="<?= e($n['type']) ?>"
                 <?= $newsShows($n['type'], $newsType) ? '' : 'hidden' ?>>
                <article class="news-card">
                    <div class="news-card__media">
                        <img src="<?= e($n['image']) ?>" alt="<?= e($n['title']) ?>"
                             loading="lazy" width="900" height="600">
                        <?php /* The TYPE, not the label. This was
                                 strtolower($n['tag']) — the human label — so
                                 "Tourism Advisory" became
                                 class="news-card__tag--tourism advisory", which
                                 the browser reads as TWO class names and matches
                                 neither. Every tag on this page has been an
                                 unbacked white word on a photograph. */ ?>
                        <span class="news-card__tag news-card__tag--<?= e($n['type']) ?>"><?= e($n['tag']) ?></span>
                    </div>
                    <div class="news-card__body">
                        <p class="news-card__date"><i class="fa-regular fa-calendar"></i> <?= e($n['date']) ?></p>
                        <h3 class="news-card__title"><?= e($n['title']) ?></h3>
                        <p class="news-card__text"><?= e($n['text']) ?></p>
                        <a href="<?= e(base_url('/announcement.php?slug=' . $n['slug'])) ?>" class="link-more">Read Full Advisory <i class="fa-solid fa-arrow-right-long"></i></a>
                    </div>
                </article>
            </div>
            <?php endforeach; ?>
            </div>

            <button type="button" class="rail-nav rail-nav--next" data-rail-next="newsGrid"
                    aria-label="More announcements" hidden>
                <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
            </button>
        </div>

        <div class="rail-dots" id="newsRailDots" role="tablist"
             aria-label="Announcement pages" hidden></div>
    </div>
</section>

<!-- =========================================================================
     7 · PHOTO GALLERY (CSS masonry + custom lightbox)
     ====================================================================== -->
<section id="gallery" class="section section--tint">
    <div class="container">

        <div class="section-head">
            <span class="eyebrow"><i class="fa-regular fa-images"></i> Through the Lens</span>
            <h2 class="section-title">Photo <span class="text-grad">Gallery</span></h2>
            <p class="section-sub">Scenes from across the municipality, captured by our visitors and tourism team.</p>
        </div>

        <div class="masonry">
            <?php foreach ($gallery as $i => $g): ?>
            <figure class="masonry__item">
                <a href="<?= e($g['full']) ?>" class="masonry__link"
                   data-lightbox data-caption="<?= e($g['caption']) ?>">
                    <img src="<?= e($g['src']) ?>"
                         alt="<?= e($g['caption']) ?>" loading="lazy">
                    <span class="masonry__overlay">
                        <span class="masonry__icon"><i class="fa-solid fa-magnifying-glass-plus"></i></span>
                        <figcaption class="masonry__caption"><?= e($g['caption']) ?></figcaption>
                    </span>
                </a>
            </figure>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- =========================================================================
     8 · VISITOR TESTIMONIALS
     ====================================================================== -->
<?php if ($testimonials !== []): ?>
<section id="testimonials" class="section section--light">
    <div class="container">

        <div class="section-head">
            <span class="eyebrow"><i class="fa-solid fa-quote-left"></i> Visitor Voices</span>
            <h2 class="section-title">What Our <span class="text-grad">Guests Say</span></h2>
            <p class="section-sub">Reflections from travellers who have walked our trails and shared our table.</p>
        </div>

        <div id="testimonialCarousel" class="carousel slide" data-bs-ride="carousel"
             data-bs-interval="7000">
            <div class="carousel-inner">
                <?php foreach ($testimonials as $i => $t): ?>
                <div class="carousel-item <?= $i === 0 ? 'active' : '' ?>">
                    <blockquote class="tst-card">
                        <i class="fa-solid fa-quote-right tst-card__mark" aria-hidden="true"></i>
                        <div class="tst-card__stars" aria-label="<?= (int) $t['rating'] ?> out of 5 stars">
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                                <i class="fa-<?= $s <= $t['rating'] ? 'solid' : 'regular' ?> fa-star"></i>
                            <?php endfor; ?>
                        </div>
                        <p class="tst-card__quote">&ldquo;<?= e($t['quote']) ?>&rdquo;</p>
                        <footer class="tst-card__author">
                            <!-- Initial instead of a photograph: real reviewers do not upload one,
                                 and borrowing a stock portrait would attach a stranger's face to
                                 somebody's words. -->
                            <span class="tst-card__initial" aria-hidden="true"><?= e(mb_substr($t['name'], 0, 1)) ?></span>
                            <span>
                                <strong><?= e($t['name']) ?></strong>
                                <small><i class="fa-solid fa-location-dot"></i> <?= e($t['origin']) ?></small>
                            </span>
                        </footer>
                    </blockquote>
                </div>
                <?php endforeach; ?>
            </div>

            <button class="carousel-control-prev tst-control" type="button" data-bs-target="#testimonialCarousel" data-bs-slide="prev">
                <i class="fa-solid fa-chevron-left"></i><span class="visually-hidden">Previous review</span>
            </button>
            <button class="carousel-control-next tst-control" type="button" data-bs-target="#testimonialCarousel" data-bs-slide="next">
                <i class="fa-solid fa-chevron-right"></i><span class="visually-hidden">Next review</span>
            </button>

            <div class="carousel-indicators tst-indicators">
                <?php foreach ($testimonials as $i => $t): ?>
                <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="<?= $i ?>"
                        class="<?= $i === 0 ? 'active' : '' ?>" aria-label="Review <?= $i + 1 ?>"></button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- =========================================================================
     9 · TOURISM HIGHLIGHTS — animated counters
     ====================================================================== -->
<section id="highlights" class="stats-section" aria-label="Tourism highlights">
    <div class="stats-section__overlay"></div>
    <div class="container position-relative">

        <div class="section-head">
            <span class="eyebrow eyebrow--light"><i class="fa-solid fa-chart-simple"></i> By the Numbers</span>
            <h2 class="section-title section-title--light">Tourism <span class="text-grad-light">Highlights</span></h2>
        </div>

        <div class="row g-4">
            <?php foreach ($stats as $i => $s): ?>
            <div class="col-lg-3 col-6">
                <div class="stat-card">
                    <div class="stat-card__icon"><i class="fa-solid <?= e($s['icon']) ?>"></i></div>
                    <div class="stat-card__value">
                        <span class="counter" data-target="<?= (int) $s['value'] ?>">0</span><?= e($s['suffix']) ?>
                    </div>
                    <p class="stat-card__label"><?= e($s['label']) ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- =========================================================================
     10 · TRAVEL GUIDE
     ====================================================================== -->
<section id="travel-guide" class="section section--light">
    <div class="container">

        <div class="section-head">
            <span class="eyebrow"><i class="fa-solid fa-suitcase-rolling"></i> Before You Go</span>
            <h2 class="section-title">Travel <span class="text-grad">Guide</span></h2>
            <p class="section-sub">Everything you need to plan a smooth, safe, and memorable trip to Tampakan.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <?php foreach ($travelGuide as $i => $g): ?>
            <div class="col-lg-4 col-md-6">
                <div class="guide-card h-100">
                    <div class="guide-card__head">
                        <span class="guide-card__icon"><i class="fa-solid <?= e($g['icon']) ?>"></i></span>
                        <h3 class="guide-card__title"><?= e($g['title']) ?></h3>
                    </div>
                    <p class="guide-card__text"><?= e($g['text']) ?></p>
                    <ul class="guide-card__list">
                        <?php foreach ($g['items'] as $item): ?>
                        <li><i class="fa-solid fa-circle-check"></i><span><?= e($item) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- =========================================================================
     11 · ABOUT THE MUNICIPAL TOURISM OFFICE
     ====================================================================== -->
<section id="about" class="section section--tint">
    <div class="container">
        <div class="row align-items-center g-5">

            <?php
            /* EVERY WORD AND BOTH PHOTOGRAPHS COME FROM SETTINGS.
             *
             * This was hard-coded: the office's own mission and vision, its
             * founding year, and two stock photographs of somewhere that is not
             * Tampakan. Those are the sentences most likely to be revised by the
             * people they belong to, and they were the ones only a developer
             * could change. Settings › Public site › About the Office.
             *
             * The stock IDs remain as the last fallback, so an office that has
             * uploaded nothing still gets a finished page. */
            $ab = static fn(string $k, string $fallback = ''): string
                => trim((string) (setting($k, '') ?? '')) ?: $fallback;

            $aboutMain  = uploaded_url((string) (setting('about_image_main', '') ?? ''))
                       ?? img('1426604966848-d7adac402bff', 900, 1100);
            $aboutSmall = uploaded_url((string) (setting('about_image_small', '') ?? ''))
                       ?? img('1518495973542-4542c06a5843', 800, 700);

            $badgeValue = $ab('about_badge_value');
            $badgeLabel = $ab('about_badge_label');
            $titleEm    = $ab('about_title_em');
            ?>

            <div class="col-lg-6">
                <div class="about__gallery">
                    <img src="<?= e($aboutMain) ?>" alt="The municipality of Tampakan"
                         class="about__img about__img--tall" loading="lazy">
                    <img src="<?= e($aboutSmall) ?>" alt=""
                         class="about__img about__img--small" loading="lazy">

                    <?php /* Both fields blank means the office does not want the card,
                             rather than an empty white box floating over the photo. */ ?>
                    <?php if ($badgeValue !== '' || $badgeLabel !== ''): ?>
                        <div class="about__badge">
                            <i class="fa-solid fa-award"></i>
                            <strong><?= e($badgeValue) ?></strong>
                            <span><?= e($badgeLabel) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-6">
                <?php if ($ab('about_eyebrow') !== ''): ?>
                    <span class="eyebrow">
                        <i class="fa-solid fa-building-columns"></i> <?= e($ab('about_eyebrow')) ?>
                    </span>
                <?php endif; ?>

                <?php /* Two fields joined here rather than one field holding a <span>.
                         An officer should not have to type markup to colour half a
                         heading, and a field that accepts markup is a field that can
                         break the page from the settings screen. */ ?>
                <h2 class="section-title">
                    <?= e($ab('about_title')) ?><?php if ($titleEm !== ''): ?>
                        <span class="text-grad"><?= e($titleEm) ?></span>
                    <?php endif; ?>
                </h2>

                <?php if ($ab('about_lead') !== ''): ?>
                    <p class="about__lead"><?= nl2br(e($ab('about_lead'))) ?></p>
                <?php endif; ?>

                <?php foreach ([
                    ['mission', 'fa-solid fa-bullseye'],
                    ['vision',  'fa-regular fa-eye'],
                ] as [$part, $icon]): ?>
                    <?php
                    $mvTitle = $ab('about_' . $part . '_title');
                    $mvText  = $ab('about_' . $part . '_text');
                    ?>
                    <?php if ($mvTitle !== '' || $mvText !== ''): ?>
                        <div class="mv-card mv-card--<?= $part ?>">
                            <span class="mv-card__icon"><i class="<?= e($icon) ?>"></i></span>
                            <div>
                                <h3><?= e($mvTitle) ?></h3>
                                <p><?= nl2br(e($mvText)) ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<!-- =========================================================================
     12 · CONTACT
     ====================================================================== -->
<section id="contact" class="section section--light">
    <div class="container">

        <div class="section-head">
            <span class="eyebrow"><i class="fa-regular fa-envelope"></i> Get in Touch</span>
            <h2 class="section-title">Contact <span class="text-grad">Us</span></h2>
            <p class="section-sub">Planning a trip, arranging a guide, or requesting a media visit? We are happy to help.</p>
        </div>

        <div class="row g-4 g-lg-5">

            <!-- Office details -->
            <div class="col-lg-5">
                <ul class="contact-list">
                    <li>
                        <span class="contact-list__icon"><i class="fa-solid fa-location-dot"></i></span>
                        <div><strong>Office Address</strong><p><?= e($contact['address']) ?></p></div>
                    </li>
                    <li>
                        <span class="contact-list__icon"><i class="fa-solid fa-phone"></i></span>
                        <div><strong>Phone</strong>
                            <p><a href="tel:<?= e(preg_replace('/\D/', '', $contact['phone'])) ?>"><?= e($contact['phone']) ?></a><br>
                               <a href="tel:<?= e(preg_replace('/\D/', '', $contact['mobile'])) ?>"><?= e($contact['mobile']) ?></a></p>
                        </div>
                    </li>
                    <li>
                        <span class="contact-list__icon"><i class="fa-regular fa-envelope"></i></span>
                        <div><strong>Email</strong><p><a href="mailto:<?= e($contact['email']) ?>"><?= e($contact['email']) ?></a></p></div>
                    </li>
                    <li>
                        <span class="contact-list__icon"><i class="fa-brands fa-facebook-f"></i></span>
                        <div><strong>Facebook</strong>
                            <p><a href="<?= e($contact['facebook']) ?>" target="_blank" rel="noopener"><?= e($contact['fb_label']) ?></a></p></div>
                    </li>
                    <li>
                        <span class="contact-list__icon"><i class="fa-regular fa-clock"></i></span>
                        <div><strong>Office Hours</strong>
                            <p><?= e($contact['hours']) ?><br><small><?= e($contact['hours_note']) ?></small></p></div>
                    </li>
                </ul>

                <!-- Embedded Google Map (keyless embed — no API key required) -->
                <div class="contact-map">
                    <iframe
                        src="https://www.google.com/maps?q=Tampakan,%20South%20Cotabato,%20Philippines&z=13&output=embed"
                        title="Google Map of Tampakan, South Cotabato"
                        loading="lazy" referrerpolicy="no-referrer-when-downgrade"
                        allowfullscreen></iframe>
                </div>
            </div>

            <!-- Contact form — client-side only; wire to a mailer/controller later -->
            <div class="col-lg-7">
                <div class="contact-form-card">
                    <h3 class="contact-form-card__title">Send Us a Message</h3>
                    <p class="contact-form-card__sub">We usually respond within one working day.</p>

                    <?php /* A REAL POST to a real endpoint. This form spent the
                             project's whole life discarding what people wrote
                             into it. */ ?>
                    <form id="contactForm" class="row g-3" novalidate
                          method="post" action="<?= e(base_url('/api/contact/submit.php')) ?>">
                        <?= csrf_field() ?>

                        <?php /* Honeypot and dwell time, the same pair guarding
                                 every other public form here. */ ?>
                        <div class="visually-hidden" aria-hidden="true">
                            <label for="cfWebsite">Leave this blank</label>
                            <input type="text" id="cfWebsite" name="website" tabindex="-1" autocomplete="off">
                        </div>
                        <input type="hidden" name="rendered_at" value="<?= time() ?>">

                        <div class="col-md-6">
                            <label for="cfName" class="form-label">Full Name <span>*</span></label>
                            <input type="text" class="form-control <?= isset($contactErrors['name']) ? 'is-invalid' : '' ?>"
                                   id="cfName" name="name" required maxlength="120" autocomplete="name"
                                   placeholder="Juan Dela Cruz" value="<?= e($cfOld('name')) ?>">
                            <div class="invalid-feedback">
                                <?= isset($contactErrors['name']) ? e((string) $contactErrors['name']) : 'Please enter your name.' ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="cfEmail" class="form-label">Email Address <span>*</span></label>
                            <input type="email" class="form-control <?= isset($contactErrors['email']) ? 'is-invalid' : '' ?>"
                                   id="cfEmail" name="email" required maxlength="190" autocomplete="email"
                                   placeholder="you@example.com" value="<?= e($cfOld('email')) ?>">
                            <div class="invalid-feedback">
                                <?= isset($contactErrors['email']) ? e((string) $contactErrors['email']) : 'Please enter a valid email address.' ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="cfPhone" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="cfPhone" name="phone" maxlength="40"
                                   autocomplete="tel" placeholder="+63 9XX XXX XXXX" value="<?= e($cfOld('phone')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="cfSubject" class="form-label">Subject <span>*</span></label>
                            <select class="form-select <?= isset($contactErrors['subject']) ? 'is-invalid' : '' ?>"
                                    id="cfSubject" name="subject" required>
                                <option value="">Choose a topic&hellip;</option>
                                <?php foreach ([
                                    'Trip Planning & Itineraries',
                                    'Tour Guide Booking',
                                    'Accommodation Assistance',
                                    'Events & Festivals',
                                    'Media & Partnerships',
                                    'Feedback',
                                ] as $topic): ?>
                                    <option <?= $cfOld('subject') === $topic ? 'selected' : '' ?>><?= e($topic) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a subject.</div>
                        </div>
                        <div class="col-12">
                            <label for="cfMessage" class="form-label">Message <span>*</span></label>
                            <textarea class="form-control <?= isset($contactErrors['message']) ? 'is-invalid' : '' ?>"
                                      id="cfMessage" name="message" rows="5" required minlength="10" maxlength="2000"
                                      placeholder="Tell us how we can help with your visit&hellip;"><?= e($cfOld('message')) ?></textarea>
                            <div class="invalid-feedback">
                                <?= isset($contactErrors['message']) ? e((string) $contactErrors['message']) : 'Please write a short message.' ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="cfConsent" required>
                                <label class="form-check-label" for="cfConsent">
                                    I consent to the Municipal Tourism Office processing my details in line with the
                                    <a href="#privacy" data-bs-toggle="modal" data-bs-target="#privacyModal">Privacy Policy</a>.
                                </label>
                                <div class="invalid-feedback">Your consent is required.</div>
                            </div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary-grad btn-lg w-100">
                                <i class="fa-regular fa-paper-plane"></i> Send Message
                            </button>
                        </div>
                        <div class="col-12">
                            <?php /* Two sources fill this. The server's answer,
                                     rendered below after a redirect, is the one
                                     that means the message was actually stored.
                                     script.js only ever writes the client-side
                                     "you missed a field" case into it. */ ?>
                            <div id="formAlert"
                                 class="form-alert <?= $contactFlashes !== [] ? 'form-alert--' . e($contactFlashes[0]['type'] === 'success' ? 'success' : 'error') . ' is-visible' : '' ?>"
                                 role="status" aria-live="polite">
                                <?php if ($contactFlashes !== []): ?>
                                    <i class="fa-solid <?= $contactFlashes[0]['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                                    <span><?= e((string) $contactFlashes[0]['message']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

</main>

<!-- =========================================================================
     13 · FOOTER
     ====================================================================== -->
<footer class="footer">
    <div class="footer__top">
        <div class="container">
            <div class="row g-4 g-lg-5">

                <div class="col-lg-4 col-md-6">
                    <div class="footer__brand">
                        <img src="assets/img/tampakan_logo.png" alt="Official Seal of the Municipality of Tampakan, Province of South Cotabato" width="70" height="70">
                    </div>
                    <h4 class="footer__title"><?= e($site['municipality']) ?></h4>
                    <p class="footer__text">
                        The official tourism portal of Tampakan, South Cotabato. Promoting sustainable,
                        community-based highland tourism for every visitor and every barangay.
                    </p>
                    <ul class="footer__social">
                        <li><a href="<?= e($contact['facebook']) ?>" target="_blank" rel="noopener" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a></li>
                        <li><a href="#" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a></li>
                        <li><a href="#" aria-label="YouTube"><i class="fa-brands fa-youtube"></i></a></li>
                        <li><a href="#" aria-label="TikTok"><i class="fa-brands fa-tiktok"></i></a></li>
                        <li><a href="mailto:<?= e($contact['email']) ?>" aria-label="Email"><i class="fa-solid fa-envelope"></i></a></li>
                    </ul>
                </div>

                <div class="col-lg-2 col-md-6 col-6">
                    <h4 class="footer__title">Quick Links</h4>
                    <ul class="footer__links">
                        <?php foreach (array_slice(public_nav(), 0, 4) as $link): ?>
                        <li><a href="<?= e($link['href']) ?>"><i class="fa-solid fa-angle-right"></i><?= e($link['label']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="col-lg-2 col-md-6 col-6">
                    <h4 class="footer__title">Discover</h4>
                    <ul class="footer__links">
                        <?php foreach (array_slice(public_nav(), 4) as $link): ?>
                        <li><a href="<?= e($link['href']) ?>"><i class="fa-solid fa-angle-right"></i><?= e($link['label']) ?></a></li>
                        <?php endforeach; ?>
                        <li><a href="#why-visit"><i class="fa-solid fa-angle-right"></i>Why Visit</a></li>
                        <li><a href="#gallery"><i class="fa-solid fa-angle-right"></i>Photo Gallery</a></li>
                    </ul>
                </div>

                <div class="col-lg-4 col-md-6">
                    <h4 class="footer__title">Tourism Office</h4>
                    <ul class="footer__contact">
                        <li><i class="fa-solid fa-location-dot"></i><span><?= e($contact['address']) ?></span></li>
                        <li><i class="fa-solid fa-phone"></i><span><?= e($contact['phone']) ?> &middot; <?= e($contact['mobile']) ?></span></li>
                        <li><i class="fa-regular fa-envelope"></i><span><?= e($contact['email']) ?></span></li>
                        <li><i class="fa-regular fa-clock"></i><span><?= e($contact['hours']) ?></span></li>
                    </ul>
                    <a href="<?= e($site['admin_url']) ?>" class="btn btn-soft-light btn-sm mt-2">
                        <i class="fa-solid fa-lock"></i> Administrator Login
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="footer__bottom">
        <div class="container">
            <div class="d-md-flex justify-content-between align-items-center text-center text-md-start">
                <p class="mb-2 mb-md-0">
                    &copy; <?= e((string) $currentYear) ?> <?= e($site['municipality']) ?>, <?= e($site['province']) ?>.
                    All rights reserved.
                </p>
                <ul class="footer__legal">
                    <li><a href="#privacy" data-bs-toggle="modal" data-bs-target="#privacyModal">Privacy Policy</a></li>
                    <li><a href="#terms" data-bs-toggle="modal" data-bs-target="#termsModal">Terms &amp; Conditions</a></li>
                    <li><a href="#contact">Sitemap</a></li>
                </ul>
            </div>
        </div>
    </div>
</footer>

<!-- Back-to-top button -->
<a href="#top" id="backToTop" class="back-to-top" aria-label="Back to top">
    <i class="fa-solid fa-chevron-up"></i>
</a>

<!-- =========================================================================
     LIGHTBOX — driven by script.js
     ====================================================================== -->
<?php require __DIR__ . '/app/views/partials/lightbox.php'; ?>

<!-- =========================================================================
     LEGAL MODALS
     ====================================================================== -->
<div class="modal fade" id="privacyModal" tabindex="-1" aria-labelledby="privacyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content legal-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="privacyModalLabel"><i class="fa-solid fa-shield-halved"></i> Privacy Policy</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>The Municipal Tourism Office of Tampakan respects your privacy and processes personal data in
                   accordance with Republic Act No. 10173, the Data Privacy Act of 2012.</p>
                <h6>Information We Collect</h6>
                <p>We collect only the details you voluntarily submit through our contact form — your name, email
                   address, optional phone number, and the content of your message.</p>
                <h6>How We Use It</h6>
                <p>Your information is used solely to respond to your inquiry, coordinate tour or guide bookings,
                   and improve visitor services. We do not sell or trade personal data.</p>
                <h6>Retention &amp; Security</h6>
                <p>Records are retained only as long as necessary for the purpose collected and are protected by
                   reasonable organisational, physical, and technical safeguards.</p>
                <h6>Your Rights</h6>
                <p>You may request access to, correction of, or deletion of your personal data at any time by
                   writing to <?= e($contact['email']) ?>.</p>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content legal-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="termsModalLabel"><i class="fa-solid fa-file-contract"></i> Terms &amp; Conditions</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <h6>Use of This Website</h6>
                <p>This portal is maintained by the Municipal Tourism Office of Tampakan for public information.
                   By using it you agree to access the site lawfully and not to disrupt its operation.</p>
                <h6>Accuracy of Information</h6>
                <p>Destination details, schedules, and advisories are updated regularly but may change without
                   notice. Confirm critical details with the Tourism Office before travelling.</p>
                <h6>Visitor Responsibility</h6>
                <p>Travel to mountain and forest destinations carries inherent risk. Visitors are expected to
                   register at the visitor desk, engage accredited guides, observe barangay regulations, and
                   respect indigenous cultural protocols.</p>
                <h6>Intellectual Property</h6>
                <p>Photographs, text, and official seals on this site belong to the Municipality of Tampakan or
                   their respective owners and may not be reproduced commercially without written permission.</p>
                <h6>External Links</h6>
                <p>Links to third-party sites are provided for convenience; the Municipality is not responsible
                   for their content or practices.</p>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================================
     VISITOR ASSISTANT
     -----------------------------------------------------------------------
     Placed at the end of the document rather than inside a section, because it
     is fixed to the viewport and serves every section above it — destinations,
     events, weather, the map, the travel guide, and the office details are all
     within its knowledge. See app/Core/KnowledgeBase.php.
     ====================================================================== -->
<?php require __DIR__ . '/app/views/partials/chat-widget.php'; ?>

<!-- =========================================================================
     SCRIPTS
     ====================================================================== -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/aos@2.3.4/dist/aos.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?= e(asset('js/vendor/sweetalert2.all.min.js')) ?>"></script>
<script src="<?= e(asset('js/notify.js')) ?>"></script>
<script src="<?= e(asset('js/script.js')) ?>"></script>

<?php /* The assistant's endpoints and script now travel with the widget itself
         — see app/views/partials/chat-widget.php, included above. */ ?>

<!-- =============================================================================
     Destination catalogue filter — in place, without reloading the page.
     -----------------------------------------------------------------------------
     Same shape as the announcement filter below, with one addition: a search
     box that filters as it is typed. The haystack in each card's data
     attribute is the same three columns the SQL LIKE searched — name, barangay,
     short description — so typing here and submitting the form to the server
     produce the same set.

     The form still works with scripting off; here the submit is intercepted so
     pressing Enter does not reload the page the search already filtered.
     ========================================================================== -->
<script>
(function () {
    const chips = document.getElementById('destChips');
    const grid  = document.getElementById('destGrid');
    const form  = document.getElementById('destForm');
    if (!chips || !grid || !form) return;

    const items      = Array.from(grid.querySelectorAll('.dest-item'));
    const search     = document.getElementById('destSearch');
    const hidden     = document.getElementById('destCategory');
    const countBox   = document.getElementById('destCount');
    const countText  = document.getElementById('destCountText');
    const empty      = document.getElementById('destEmpty');
    const emptyTitle = document.getElementById('destEmptyTitle');
    const emptyText  = document.getElementById('destEmptyText');
    const emptyClear = document.getElementById('destEmptyClear');

    /* Slug to display name, for the "found in Waterfalls" line. Read off the
       chips themselves rather than printed a second time from PHP — the chip
       already carries the name, and a second copy is a second thing to keep
       in step. The count badge inside <em> is not part of the name. */
    const NAMES = {};
    chips.querySelectorAll('[data-dest-filter]').forEach(chip => {
        const clone = chip.cloneNode(true);
        clone.querySelectorAll('em').forEach(em => em.remove());
        NAMES[chip.dataset.destFilter] = clone.textContent.trim();
    });

    /* Nothing published at all is a different situation from nothing matching,
       and only this flag can tell them apart once the cards are hidden. */
    const catalogueIsEmpty = items.length === 0;

    /* The mirror of $destShows in the PHP above. Change one, change both. */
    const shows = (item, cat, q) =>
        (cat === '' || item.dataset.destCategory === cat)
        && (q === '' || item.dataset.destHaystack.indexOf(q) !== -1);

    let category = <?= json_encode($categorySlug) ?>;

    function apply(push) {
        const q = search.value.trim().toLowerCase();
        let shown = 0;

        items.forEach(item => {
            const visible = shows(item, category, q);
            item.hidden = !visible;
            if (visible) shown++;
        });

        chips.querySelectorAll('[data-dest-filter]').forEach(chip => {
            chip.classList.toggle('is-active', chip.dataset.destFilter === category);
        });

        /* Kept in step so a no-JS submit — or a submit after the script has
           been running — carries the chosen category with it. */
        hidden.value    = category;
        hidden.disabled = category === '';

        const filtered = category !== '' || q !== '';

        countBox.hidden = !(filtered && shown > 0);
        if (!countBox.hidden) {
            countText.textContent = shown + (shown === 1 ? ' destination' : ' destinations')
                + ' found'
                + (category !== '' ? ' in ' + NAMES[category] : '')
                + (q !== '' ? ' for “' + search.value.trim() + '”' : '')
                + '.';
        }

        empty.hidden = shown > 0;
        if (!empty.hidden) {
            const nothingPublished = catalogueIsEmpty && !filtered;
            emptyTitle.textContent = nothingPublished
                ? 'Destinations are being prepared'
                : 'No destinations match that search';
            emptyText.textContent = nothingPublished
                ? 'The Municipal Tourism Office is currently registering the municipality’s destinations. Please check back shortly.'
                : 'Try a different term, or';
            emptyClear.hidden = nothingPublished;
        }

        if (push) {
            const params = new URLSearchParams();
            if (q !== '')        params.set('q', search.value.trim());
            if (category !== '') params.set('category', category);

            const query = params.toString();
            history.replaceState({ category: category, q: q }, '',
                location.pathname + (query ? '?' + query : '') + '#destinations');
        }
    }

    chips.addEventListener('click', function (event) {
        const link = event.target.closest('[data-dest-filter]');
        if (!link) return;

        event.preventDefault();
        category = link.dataset.destFilter;
        apply(true);
    });

    /* The two "clear" links live outside the chip row, so they get their own
       listener — and they clear the search box as well as the category, which
       is what "Clear filters", plural, promises. */
    document.querySelectorAll('[data-dest-clear]').forEach(link => {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            category     = '';
            search.value = '';
            apply(true);
        });
    });

    search.addEventListener('input', () => apply(true));
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        apply(true);
    });
})();
</script>

<!-- =============================================================================
     Announcement filter — in place, without reloading the page.
     -----------------------------------------------------------------------------
     The chips are ordinary links and stay that way: with no JavaScript they
     reload the homepage with ?type= and the PHP above renders the right cards.
     That is the fallback, not the plan, because reloading this particular page
     tears down a background video, a Bootstrap carousel and a Leaflet map to
     change six cards — which is the flash the whole screen made on every click.

     Here the click is intercepted, the cards already in the DOM are shown or
     hidden, and history.replaceState rewrites the address so the filtered view
     is still linkable. Nothing navigates, so nothing blinks.
     ========================================================================== -->
<script>
(function () {
    const chips = document.getElementById('newsChips');
    const grid  = document.getElementById('newsGrid');
    if (!chips || !grid) return;

    const items      = Array.from(grid.querySelectorAll('.news-item'));
    const countBox   = document.getElementById('newsCount');
    const countText  = document.getElementById('newsCountText');
    const empty      = document.getElementById('newsEmpty');
    const emptyTitle = document.getElementById('newsEmptyTitle');
    const emptyText  = document.getElementById('newsEmptyText');
    const emptyClear = document.getElementById('newsEmptyClear');

    /* Labels live in PHP; this is the only copy that crosses over, and it is
       generated from the same constant rather than typed out again. */
    const LABELS = <?= json_encode(AnnouncementRepository::TYPES, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    /* The mirror of $newsShows in the PHP above. If one changes, change both:
       "All" hides events because they have their own section; a chosen type
       shows only itself. */
    const shows = (type, filter) => filter === '' ? type !== 'event' : type === filter;

    function apply(filter, push) {
        let shown = 0;

        items.forEach(item => {
            const visible = shows(item.dataset.newsType, filter);
            item.hidden = !visible;
            if (visible) shown++;
        });

        chips.querySelectorAll('[data-news-filter]').forEach(chip => {
            chip.classList.toggle('is-active', chip.dataset.newsFilter === filter);
        });

        countBox.hidden = (filter === '' || shown === 0);
        if (!countBox.hidden) {
            countText.textContent = shown + (shown === 1 ? ' notice' : ' notices')
                + ' filed under ' + LABELS[filter] + '.';
        }

        empty.hidden = shown > 0;
        if (!empty.hidden) {
            emptyTitle.textContent = filter === ''
                ? 'No announcements at the moment'
                : 'Nothing filed under ' + LABELS[filter];
            emptyText.textContent = filter === ''
                ? 'When the Tourism Office publishes an advisory, a closure, or a schedule, it appears here.'
                : 'No notice of this kind is currently in force.';
            emptyClear.hidden = filter === '';
        }

        /* replaceState, not pushState: six chips clicked in a row should not
           bury the page the visitor arrived from under six back-button steps.
           The address still updates, so the view stays shareable. */
        if (push) {
            const url = filter === ''
                ? location.pathname + '#news'
                : location.pathname + '?type=' + encodeURIComponent(filter) + '#news';
            history.replaceState({ newsType: filter }, '', url);
        }
    }

    /* Delegated, so the "Clear filter" and "See everything" links inside the
       count line and the empty panel work through the same path as the chips
       without being wired up individually. */
    document.addEventListener('click', function (event) {
        const link = event.target.closest('[data-news-filter]');
        if (!link) return;

        event.preventDefault();
        apply(link.dataset.newsFilter, true);
    });
})();
</script>
</body>
</html>
