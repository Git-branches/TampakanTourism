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
use App\Repositories\DataRequestRepository;

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
 |
 | Three was the number that fitted one row of the old grid. The section is a
 | strip now — five on screen, the rest behind the arrows — so the limit is what
 | the office might reasonably have on at once rather than what fitted a row.
 | Anything past this is genuinely more than a season's programme.
 * -------------------------------------------------------------------------- */
$events = [];

/* WHICH KIND OF EVENT IS BEING SHOWN.
 *
 * Its own parameter, not shared with the news filter: both sections are on one
 * page, and a single ?type= would have narrowed them together — choosing
 * Festival would have emptied Latest News, which holds no festivals by design.
 *
 * A value from the other section's vocabulary is dropped rather than obeyed. */
$eventType = (string) ($_GET['event'] ?? '');

if ($eventType !== '' && !isset(AnnouncementRepository::EVENT_TYPES[$eventType])) {
    $eventType = '';
}

/** The mirror of the script at the foot of this page. Both must agree. */
$eventShows = static fn (string $type, string $filter): bool => $filter === '' || $type === $filter;

foreach (AnnouncementRepository::upcomingEvents(15) as $row) {
    $when = $row['event_date'] ? strtotime($row['event_date']) : strtotime($row['created_at']);

    $events[] = [
        'slug'     => $row['slug'],
        'type'     => $row['type'],
        'kind'     => AnnouncementRepository::EVENT_TYPES[$row['type']] ?? 'Event',
        'title'    => $row['title'],
        // Machine-readable date for the <time datetime> attribute. The day,
        // month, and year below are for people; this one is for search
        // engines and assistive technology.
        'iso'      => date('Y-m-d', $when),
        /* NULL RATHER THAN A STOCK PHOTOGRAPH.
         *
         * This used to fall back to an Unsplash picture of a concert crowd, and
         * three of the five events on file have no banner — so the section
         * showed the same foreign concert three times over and read as
         * placeholder data. It is also not Tampakan, which anybody who lives
         * there notices first.
         *
         * The card draws its own plate when this is null: the date on a green
         * ground with the event's own icon. A card that plainly has no
         * photograph looks better than one pretending to have a relevant one,
         * and it gives the office a reason to upload the real poster. */
        'image'    => $row['banner_path'] ? base_url($row['banner_path']) : null,
        'icon'     => AnnouncementRepository::TYPE_STYLE[$row['type']]['icon'] ?? 'fa-calendar-day',
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
/* The preview carries the slug as well as the label: the colour of a pin is
   chosen from the slug on both maps, so a category added later is the same
   colour here as it is on map.php without either page being edited. */
$markerRows = DestinationRepository::mapMarkers();   // read once; used twice below

$mapMarkers = array_map(static fn(array $row): array => [
    'name'     => $row['name'],
    'lat'      => (float) $row['latitude'],
    'lng'      => (float) $row['longitude'],
    'type'     => $row['category_name'] ?: 'Destination',
    'category' => $row['category_slug'] ?: 'other',
], $markerRows);

/* The office marker is always shown, so an empty map still orients the visitor. */
if ($mapMarkers === []) {
    $mapMarkers[] = [
        'name'     => 'Municipal Tourism Office',
        'lat'      => $site['lat'],
        'lng'      => $site['lng'],
        'type'     => 'Municipal Tourism Office',
        'category' => 'other',
    ];
}

/* THE LEGEND IS BUILT FROM WHAT IS ON THE MAP, not from a hand-written list.
   The list it replaces named "Government Offices", which is not a category the
   system has, and omitted four that it does — including the two added when the
   Office's own destination file was loaded. A legend that disagrees with the
   pins beside it is worse than no legend. */
$mapLegend = [];

foreach ($markerRows as $row) {
    $slug = $row['category_slug'] ?: 'other';
    $mapLegend[$slug] = $row['category_name'] ?: 'Other';
}

asort($mapLegend);

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
if ($newsType !== '' && !isset(AnnouncementRepository::NEWS_TYPES[$newsType])) {
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
/* NOT publicFeed(): that returns everything published, events included, and
 this section is the one that must not repeat what Upcoming Events already
 shows. latestNews() excludes every event type. */
$newsRows = AnnouncementRepository::latestNews(60);

$news = [];

foreach ($newsRows as $row) {
    $news[] = [
        'slug'  => $row['slug'],
        'type'  => $row['type'],
        'tag'   => AnnouncementRepository::TYPES[$row['type']] ?? 'Announcement',
        'date'  => format_date($row['publish_at'] ?: $row['created_at']),
        'title' => $row['title'],
        'text'  => $row['summary'] ?: mb_substr(strip_tags($row['body']), 0, 165),
        /* NULL, NOT A STOCK PHOTOGRAPH — AND HERE IT WAS WORSE THAN GENERIC.
         *
         * All nine notices on file share one Unsplash sunset, so a closure, an
         * advisory and a reminder wore the same picture. "Buto Falls closed for
         * footpath repair" was illustrated with a mountain at golden hour, which
         * reads as an invitation: the image contradicted the words it sat above.
         *
         * The card draws its own plate instead, in the colour the type already
         * owns — red for a closure, amber for an advisory. The tag chip carries
         * the words, so the plate carries only the colour and the icon rather
         * than saying it twice. */
        'image' => $row['banner_path'] ? base_url($row['banner_path']) : null,
        'icon'  => AnnouncementRepository::TYPE_STYLE[$row['type']]['icon'] ?? 'fa-bullhorn',
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
/* The exception is gone, and so is the reason for it. This read
   `$type !== 'event'` from when an event was one type among the notices and
   had to be hidden from the "All" view by hand. Events are their own section
   with their own five kinds now, and latestNews() excludes every one of them
   before the page sees a row — so there is nothing here to make an exception
   for, and leaving the old rule in would name a type this list can no longer
   contain. Its mirror in the script at the foot says the same. */
$newsShows = static fn(string $type, string $filter): bool
    => $filter === '' || $type === $filter;

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
    ['icon' => 'fa-calendar-day',    'value' => 16,                'suffix' => '', 'label' => 'Tourism Events'],
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

/* -----------------------------------------------------------------------------
 | The About section's people and photographs.
 |                      Presentation feedback 15 Sep, revised by the office 17 Sep
 |
 | TWO NAMED PROFILES, NOT A ROSTER. The first cut of this showed the Tourism
 | Office as an organisational chart — a head above a row of staff. The office
 | came back and asked for the opposite: the Mayor beside the Tampakan text, the
 | Tourism Coordinator beside the Office text, and nobody else. So both are
 | single fixed profiles held as settings rows, the same call as the mission and
 | vision beside them. There is only ever one Mayor and one Coordinator; neither
 | is a list, so neither earns a table.
 |
 | EVERY NAME, POSITION AND PHOTOGRAPH IS READ FROM THE DATABASE. Not one is a
 | literal in this file, which is the point of the whole exercise: these are real
 | officials holding real positions, and when one of them is reassigned the
 | office must be able to put it right that afternoon from Settings › About
 | without anybody touching PHP.
 * -------------------------------------------------------------------------- */

/**
 * One profile, read from three settings keys.
 *
 * A NAME IS THE MINIMUM. A card reading "—" with a blank circle where the
 * official portrait belongs is not a placeholder on a municipal website, it is
 * an error nobody reported — so a profile with no name does not render at all.
 * A photograph alone identifies nobody, and a position alone is a job with no
 * holder.
 *
 * @return array{name:string, position:string, photo:string|null}|null
 */
$officialProfile = static function (string $prefix): ?array {
    $name = trim((string) (setting('about_' . $prefix . '_name', '') ?? ''));

    if ($name === '') {
        return null;
    }

    return [
        'name'     => $name,
        'position' => trim((string) (setting('about_' . $prefix . '_position', '') ?? '')),
        /* uploaded_url() returns null for a row pointing at a file that is no
           longer on disk, so a photograph deleted from the server degrades to
           the initials fallback rather than to a broken image. */
        'photo'    => uploaded_url((string) (setting('about_' . $prefix . '_photo', '') ?? '')),
    ];
};

$mayor       = $officialProfile('mayor');
$coordinator = $officialProfile('coordinator');

/* EVERY ABOUT BLOCK HOLDS A GALLERY, not a slot.
 *
 * Each of the four started as one settings row holding one file. The office
 * asked for several everywhere, so the photographs live in about_photos keyed on
 * the block, and the old single rows were moved into it by the migration.
 *
 * WHAT DIFFERS BETWEEN THEM IS ONLY HOW THEY ARE DRAWN. Cultural Heritage gets
 * the 2×2 grid; the other three keep a single image with "View all photos" over
 * its corner however many they hold. That was the office's instruction and it is
 * expressed by the 'grid' flag on each block below, not by the storage.
 *
 * A row whose file has gone is dropped by published(), so a picture deleted from
 * the server leaves a shorter gallery rather than a hole in the layout.
 */
$aboutGallery = static function (string $section, array $fallbacks = []): array {
    $urls = array_column(
        App\Repositories\AboutPhotoRepository::published($section),
        'url'
    );

    /* The fallbacks are the pre-block legacy slots and the stock picture. They
       stand in only when the office has uploaded nothing for that block at all
       — one real photograph beats every fallback. */
    return $urls !== [] ? $urls : array_values(array_filter($fallbacks));
};

$officeBlurb = trim((string) (setting('about_office_text', '') ?? ''));

/* The two paragraphs of the About Tampakan column.
 *
 * TWO FIELDS, ONE COLUMN. They are edited separately — an office may want to
 * change how it introduces the municipality without touching the historical
 * record, and vice versa — but they render as consecutive paragraphs with no
 * heading between them, because to a reader they are one piece of prose about
 * the same subject.
 *
 * The history is transcribed from the office's own printed trifold brochure.
 * Held in settings like every other word in this section: the dates, the
 * Republic Act number and the meaning of "tamfaken" are the municipality's own
 * record, and the office must be able to correct them without a developer. */
$aboutHistory = trim((string) (setting('about_history', '') ?? ''));

/* The cultural heritage block, also from the brochure. Its own field because
   the office edits it separately, and its own block on the page because it is
   about the municipality's traditions rather than about its founding. */
$aboutHeritage = trim((string) (setting('about_heritage', '') ?? ''));

/* -----------------------------------------------------------------------------
 | The roster behind the Contact Us modal's Tour Guide rating.
 |
 | Active accreditations only. A suspended or revoked guide is not somebody the
 | office is inviting the public to rate, and the endpoint enforces the same
 | condition — a list here that disagreed with the check there would offer a
 | name and then refuse the submission naming it.
 |
 | Two columns, no more. This select needs a label; the roster row also carries
 | an address, a mobile number and an email, and there is no reason for any of
 | those to reach the public page.
 * -------------------------------------------------------------------------- */
$guideRoster = Database::all(
    "SELECT id, full_name, guide_code
       FROM tour_guides
      WHERE status = 'active'
      ORDER BY full_name"
);

$currentYear = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="theme-color" content="#2E7D32">

    <?php /* THE STILL LOADER, FOR VISITORS WHO ASKED FOR NO MOTION.
             Everyone else gets the full journey on every opening of Home — the
             office wants the aeroplane seen each time, so an earlier "first
             visit of the session only" rule was taken back out.
             This has to run HERE, before the stylesheet and the body: decided
             any later — in script.js, at DOMContentLoaded — the animation has
             already begun painting, and the visitor sees it start and then
             jump to its finished state. */ ?>
    <script>
        if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) {
            document.documentElement.classList.add('tt-still');
        }
    </script>

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

    <link rel="icon" href="<?= e(asset('img/tourism-logo-mark.png')) ?>" type="image/png">

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
      "logo": "<?= e($site['url']) ?>/assets/img/tourism-logo-mark.png",
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
<?php /* JOURNEY -> DESTINATION -> DISCOVERY, in about four seconds from click to
         homepage, on every opening.
         The seal holds still and everything moves around it: the rings turn
         slowly, a dashed route runs three-quarters of the way round, a small
         aeroplane travels it once and arrives at a pin, and the ridgeline
         beneath rises into view. Nothing bounces and nothing glows.

         The route, the aeroplane and the pin are one inline SVG so the arc the
         plane flies and the arc drawn on screen are the same numbers — a path
         described twice is a path that drifts apart. */ ?>
<div id="preloader" class="preloader" aria-hidden="true">

    <!-- Behind everything: the ridgeline, revealed upward. -->
    <svg class="preloader__ridge" viewBox="0 0 1440 220" preserveAspectRatio="none" aria-hidden="true">
        <path class="preloader__ridge-far"
              d="M0 220 L0 150 L180 96 L330 148 L470 84 L620 142 L780 70 L940 138 L1090 92 L1250 146 L1440 104 L1440 220 Z"/>
        <path class="preloader__ridge-near"
              d="M0 220 L0 182 L150 140 L300 186 L450 132 L600 178 L760 124 L920 176 L1080 138 L1260 184 L1440 148 L1440 220 Z"/>
    </svg>

    <div class="preloader__inner">
        <div class="preloader__stage">
            <?php /* THREE RINGS, ONE COLOUR EACH.
                     Two of these used to be pseudo-elements carrying two border
                     colours apiece, which read as one bi-coloured spinner. Real
                     elements instead: each is a single colour, its own size, its
                     own speed and its own direction, so they drift past one
                     another rather than turning as a set. */ ?>
            <div class="preloader__ring">
                <i class="preloader__arc preloader__arc--blue"></i>
                <i class="preloader__arc preloader__arc--green"></i>
                <i class="preloader__arc preloader__arc--gold"></i>
                <img src="<?= e(asset('img/tourism-logo-mark.png')) ?>" alt="" class="preloader__logo">
            </div>

            <svg class="preloader__route" viewBox="0 0 200 200" aria-hidden="true">
                <?php /* Half a circle, over the top: away at the lower left and
                         arriving at the upper right. Centred on the seal at
                         100,100 with radius 72, the two ends sit at 45 degrees
                         either side — 49.1,150.9 and 150.9,49.1 — which are ends
                         of a diameter, so the sweep is exactly 180 degrees and
                         the renderer has no radius to correct.
                         The gap matters: a closed loop is an orbit, an open one
                         is a trip. */ ?>
                <?php /* pathLength normalises the arc to 100 units so the dash
                         numbers in the CSS are readable ones rather than
                         whatever 226.19 happens to be. It has to live here:
                         it is an SVG attribute, not a CSS property. */ ?>
                <path class="preloader__path"
                      d="M 49.1 150.9 A 72 72 0 1 1 150.9 49.1"
                      pathLength="100"
                      fill="none" stroke-linecap="round"/>
            </svg>

            <?php /* The pin sits exactly where the arc ends: 150.9, 49.1. */ ?>
            <span class="preloader__pin" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11Z"
                          stroke="currentColor" stroke-width="2"
                          stroke-linejoin="round" fill="rgba(255,255,255,.10)"/>
                    <circle cx="12" cy="10" r="2.4" fill="currentColor"/>
                </svg>
            </span>

            <?php /* Travels the same arc through CSS offset-path, so it banks
                     into the turn on its own — offset-rotate does the pointing. */ ?>
            <span class="preloader__plane" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="currentColor">
                    <path d="M21.6 11.1 14 9.3 9.9 2.6a.9.9 0 0 0-1.6.1l-1 2.2a.9.9 0 0 0 .1.9l2.9 4.1-3.7-.9-1.5-1.9a.7.7 0 0 0-.7-.2l-1.1.3a.7.7 0 0 0-.4 1l1.2 2.4-1.2 2.4a.7.7 0 0 0 .4 1l1.1.3a.7.7 0 0 0 .7-.2l1.5-1.9 3.7-.9-2.9 4.1a.9.9 0 0 0-.1.9l1 2.2a.9.9 0 0 0 1.6.1L14 14.7l7.6-1.8a.9.9 0 0 0 0-1.8Z"/>
                </svg>
            </span>
        </div>

        <p class="preloader__text">Tampakan Tourism</p>

        <?php /* The three words the whole animation is acting out, said plainly
                 underneath it. The arrows are real characters rather than
                 images so they inherit the colour and scale with the type. */ ?>
        <p class="preloader__tagline">Journey <span>&rarr;</span> Destination <span>&rarr;</span> Discovery</p>

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
                    <div><strong>14 Barangays</strong><span>Across the municipality</span></div>
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

        <?php
        /* The same filter the news section has, over the event vocabulary.
           Real links to ?event=…#events, so it works with no JavaScript at all
           and a filtered view has an address that can be shared. The script at
           the foot upgrades them: it shows and hides the cards already on the
           page and rewrites the URL without navigating — so the video, the
           strips and the map are never rebuilt. */
        $eventCounts = [];

        foreach ($events as $ev) {
            $eventCounts[$ev['type']] = ($eventCounts[$ev['type']] ?? 0) + 1;
        }

        $eventShown = count(array_filter($events,
            static fn (array $ev): bool => $eventShows($ev['type'], $eventType)));
        ?>
        <?php if (count($events) > 1): ?>
            <div class="chip-row chip-row--center" id="eventChips">
                <a href="<?= e(events_url()) ?>" data-event-filter=""
                   class="chip <?= $eventType === '' ? 'is-active' : '' ?>">All</a>

                <?php foreach (AnnouncementRepository::EVENT_TYPES as $value => $label): ?>
                    <?php /* Only the kinds actually on the page. A chip that can
                             only ever say "nothing here" is a dead end. */ ?>
                    <?php if (!isset($eventCounts[$value])) { continue; } ?>
                    <a href="<?= e(events_url(['event' => $value])) ?>"
                       data-event-filter="<?= e($value) ?>"
                       class="chip <?= $eventType === $value ? 'is-active' : '' ?>">
                        <i class="fa-solid <?= e(AnnouncementRepository::TYPE_STYLE[$value]['icon']) ?>"></i>
                        <?= e($label) ?>
                        <em><?= n($eventCounts[$value]) ?></em>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <p class="explore-count" id="eventCount" <?= $eventType === '' ? 'hidden' : '' ?>>
            <span id="eventCountText"><?php if ($eventType !== ''): ?><?=
                n($eventShown) ?> <?= $eventShown === 1 ? 'event' : 'events'
                ?> under <?= e(AnnouncementRepository::EVENT_TYPES[$eventType]) ?>.<?php endif; ?></span>
            <a href="<?= e(events_url()) ?>" data-event-filter="">Clear filter</a>
        </p>

        <div class="empty-public" id="eventEmpty" <?= $eventShown > 0 ? 'hidden' : '' ?>>
            <i class="fa-solid fa-calendar-day"></i>
            <h3 id="eventEmptyTitle"><?= $eventType !== ''
                ? 'Nothing under ' . e(AnnouncementRepository::EVENT_TYPES[$eventType])
                : 'No events scheduled at the moment' ?></h3>
            <p>
                <span id="eventEmptyText"><?= $eventType !== ''
                    ? 'No event of this kind is coming up.'
                    : 'When the Tourism Office schedules a festival, a fair or a community activity, it appears here.' ?></span>
                <a href="<?= e(events_url()) ?>" data-event-filter=""
                   id="eventEmptyClear" <?= $eventType === '' ? 'hidden' : '' ?>>See everything</a>
            </p>
        </div>

        <?php /* Same strip as Destinations and Announcements: five across, the
                 rest behind the arrows, and a section that stays one row tall
                 however many events the office publishes. */ ?>
        <div class="rail-wrap">
            <button type="button" class="rail-nav rail-nav--prev" data-rail-prev="eventGrid"
                    aria-label="Previous events" hidden>
                <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
            </button>

            <div class="rail" id="eventGrid" data-rail data-rail-dots="eventRailDots"
                 tabindex="0" role="group" aria-label="Upcoming events">
            <?php foreach ($events as $i => $ev): ?>
            <div class="event-item" data-event-type="<?= e($ev['type']) ?>"
                 <?= $eventShows($ev['type'], $eventType) ? '' : 'hidden' ?>>
                <article class="event-card">
                    <div class="event-card__media<?= $ev['image'] === null ? ' is-plate' : '' ?>">
                        <?php if ($ev['image'] !== null): ?>
                            <img src="<?= e($ev['image']) ?>" alt="<?= e(strip_tags($ev['title'])) ?> event banner"
                                 loading="lazy" width="1200" height="800">
                        <?php else: ?>
                            <?php /* No banner on file. The kind of event, drawn
                                     rather than borrowed from a stock library.
                                     aria-hidden: the kind is already in the card's
                                     own text, and a screen reader does not need
                                     to hear a decorative plate say it twice. */ ?>
                            <span class="event-card__plate" aria-hidden="true">
                                <i class="fa-solid <?= e($ev['icon']) ?>"></i>
                                <em><?= e($ev['kind']) ?></em>
                            </span>
                        <?php endif; ?>
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
                        <a href="<?= e(base_url('/events.php?slug=' . $ev['slug'])) ?>" class="btn btn-soft w-100">Learn More <i class="fa-solid fa-arrow-right-long"></i></a>
                    </div>
                </article>
            </div>
            <?php endforeach; ?>
            </div>

            <button type="button" class="rail-nav rail-nav--next" data-rail-next="eventGrid"
                    aria-label="More events" hidden>
                <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
            </button>
        </div>

        <div class="rail-dots" id="eventRailDots" role="tablist"
             aria-label="Event pages" hidden></div>
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
                <?php /* A PREVIEW, AND IT SAYS SO. The full map — photographs on
                         every marker, category filters and "Where am I?" — lives on
                         one page rather than being half-built on two. */ ?>
                <p class="section-sub section-sub--light">
                    <?= n(count($mapMarkers)) ?> destination<?= count($mapMarkers) === 1 ? '' : 's' ?>
                    pinned across the municipality. This is a quick look at where they are;
                    the full map has photographs, category filters, and directions from where you stand.
                </p>

                <?php /* Built from the pins actually on the map — see $mapLegend. */ ?>
                <ul class="map-legend">
                    <?php foreach ($mapLegend as $slug => $label): ?>
                        <li>
                            <span class="dot" style="background: <?= e(map_category_colour($slug)) ?>"></span>
                            <?= e($label) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <a href="<?= e(base_url('/map.php')) ?>" class="btn btn-primary-grad btn-lg mt-2">
                    <i class="fa-solid fa-expand"></i> Explore Full Map
                </a>
            </div>

            <div class="col-lg-7">
                <div class="map-frame">
                    <!-- Leaflet renders here; markers arrive as JSON on the data attribute -->
                    <div id="touristMap"
                         data-center-lat="<?= $site['lat'] ?>"
                         data-center-lng="<?= $site['lng'] ?>"
                         data-markers='<?= e(json_encode($mapMarkers, JSON_UNESCAPED_UNICODE)) ?>'
                         data-colours='<?= e(json_encode(map_category_colours(), JSON_UNESCAPED_SLASHES)) ?>'
                         aria-label="Map preview of Tampakan tourist destinations"></div>
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

            <?php foreach (AnnouncementRepository::NEWS_TYPES as $value => $label): ?>
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
                ?> filed under <?= e(AnnouncementRepository::NEWS_TYPES[$newsType]) ?>.<?php endif; ?></span>
            <a href="<?= e(announcements_url()) ?>" data-news-filter="">Clear filter</a>
        </p>

        <div class="empty-public" id="newsEmpty" <?= $newsCount > 0 ? 'hidden' : '' ?>>
            <i class="fa-solid fa-bullhorn"></i>
            <h3 id="newsEmptyTitle"><?= $newsType !== ''
                ? 'Nothing filed under ' . e(AnnouncementRepository::NEWS_TYPES[$newsType])
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
                    <div class="news-card__media<?= $n['image'] === null ? ' is-plate news-card__media--' . e($n['type']) : '' ?>">
                        <?php if ($n['image'] !== null): ?>
                            <img src="<?= e($n['image']) ?>" alt="<?= e($n['title']) ?>"
                                 loading="lazy" width="900" height="600">
                        <?php else: ?>
                            <?php /* Decorative: the chip below already names the
                                     type in words, so a screen reader would hear
                                     it twice. */ ?>
                            <i class="news-card__plate fa-solid <?= e($n['icon']) ?>" aria-hidden="true"></i>
                        <?php endif; ?>
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
<?php
/* EVERY WORD AND EVERY PHOTOGRAPH COMES FROM SETTINGS.
 *
 * This was hard-coded: the office's own mission and vision, its founding year,
 * and two stock photographs of somewhere that is not Tampakan. Those are the
 * sentences most likely to be revised by the people they belong to, and they
 * were the ones only a developer could change. Settings › Public site › About.
 *
 * The stock IDs remain as the last fallback for the two original slots, so an
 * office that has uploaded nothing still gets a finished page. The slots added
 * after the September presentation have NO stock fallback — see below. */
$ab = static fn(string $k, string $fallback = ''): string
    => trim((string) (setting($k, '') ?? '')) ?: $fallback;

/* THE FOUR GALLERIES, each falling back to the pre-block legacy slot and then to
   a stock picture — so a fresh install still gets a finished page and an office
   that filled the old slots years ago keeps what it had. One uploaded photograph
   beats every fallback. */
$historyPhotos  = $aboutGallery('history', [
    uploaded_url((string) (setting('about_image_small', '') ?? '')),
    img('1518495973542-4542c06a5843', 800, 700),
]);

$tampakanPhotos = $aboutGallery('tampakan', [
    uploaded_url((string) (setting('about_image_main', '') ?? '')),
    img('1426604966848-d7adac402bff', 900, 1100),
]);

/* No stock fallback for these two. The Tourism Office and Cultural Heritage are
   about this office and this municipality; a stock photograph of somewhere else
   under either heading is worse than the block not being drawn. */
$officePhotos   = $aboutGallery('office');
$heritagePhotos = $aboutGallery('heritage');

$badgeValue = $ab('about_badge_value');
$badgeLabel = $ab('about_badge_label');
$titleEm    = $ab('about_title_em');

/* Is there a Tourism Office area to draw at all? A heading with nothing under
   it is worse than no heading — it reads as a page that failed to load.
 *
 * MISSION AND VISION COUNT, and leaving them out of this test was a real bug
 * the suite caught. They moved into this area during the September rework —
 * they are the OFFICE's mission and vision, and beside a description of the
 * municipality they read as the municipality's. But an office that wrote them
 * months ago and has not yet filled in any of the new fields would then have
 * had both statements silently vanish from the homepage: the words still in
 * Settings, still saving, rendered nowhere. Exactly the failure the hero had.
 */
$hasMissionOrVision = false;

foreach (['mission', 'vision'] as $part) {
    if ($ab('about_' . $part . '_title') !== '' || $ab('about_' . $part . '_text') !== '') {
        $hasMissionOrVision = true;
        break;
    }
}

/* Mission and vision are NOT in this test any more.
 *
 * They were, back when they lived inside the Tourism Office column and would
 * have vanished with it. They now sit at the foot of the section under their own
 * condition, so counting them here would draw an empty "About the Tourism
 * Office" heading for an office that has written a mission and nothing else. */
$hasOfficeArea = $officeBlurb !== ''
    || trim((string) (setting('about_office_text2', '') ?? '')) !== ''
    || $officePhotos !== []
    || $coordinator !== null;

/**
 * One official's profile card — the Mayor, or the Tourism Coordinator.
 *
 * ONE PARTIAL FOR BOTH, because they differ in the words and in nothing else.
 * Two copies of this markup would be two places to correct the next time an alt
 * text or a heading level is wrong, and they would drift.
 *
 * Compact and inline, NOT an organisational chart. The office looked at the
 * chart version and asked for this instead: a person named beside the text they
 * are responsible for, at the size of a caption rather than a feature.
 *
 * @param array{name:string, position:string, photo:string|null} $person
 */
$profileCard = static function (array $person, string $fallbackRole = ''): void { ?>
    <figure class="official">
        <?php if ($person['photo'] !== null): ?>
            <img src="<?= e($person['photo']) ?>"
                 alt="<?= e($person['name']) ?>"
                 class="official__photo" loading="lazy">
        <?php else: ?>
            <?php /* Initials rather than a stock silhouette. It is honest about
                     holding a place, and it cannot be mistaken for a photograph
                     of the person — see initials() in app/helpers.php. It fills
                     the same frame the portrait will, so uploading one later
                     does not move anything on the page. */ ?>
            <span class="official__photo official__photo--blank" aria-hidden="true">
                <?= e(initials($person['name'])) ?>
            </span>
        <?php endif; ?>

        <figcaption class="official__body">
            <strong class="official__name"><?= e($person['name']) ?></strong>
            <?php
            /* The stored position, or the block's own label when the office has
               named somebody but not yet said what they are. A card carrying a
               name and nothing else leaves a reader wondering why that person is
               on the page at all. */
            $role = $person['position'] !== '' ? $person['position'] : $fallbackRole;
            ?>
            <?php if ($role !== ''): ?>
                <span class="official__role"><?= e($role) ?></span>
            <?php endif; ?>
        </figcaption>
    </figure>
<?php };
?>
<section id="about" class="section section--tint">
    <div class="container">

        <?php
        /* -------------------------------------------------------------------
         * ONE PARTIAL, CALLED FOUR TIMES.
         *
         * Every block is the same editorial shape — photographs left, prose
         * centre, and where there is one, the responsible official's portrait
         * right. What differs is only which of the three are present:
         *
         *   Brief History      [ image ] [ content ]
         *   About Tampakan     [ image ] [ content ] [ Mayor ]
         *   Tourism Office     [ 2 imgs ] [ content ] [ Coordinator ]
         *   Cultural Heritage  [ image ] [ content ]
         *
         * Writing that four times would mean four places to fix the next time an
         * alt text or a heading level is wrong, and the copies always drift.
         *
         * THE PORTRAIT IS A COMPACT CARD, NOT AN ORGANISATIONAL CHART. A chart
         * was built and withdrawn; the office asked for one named person beside
         * the text they are responsible for.
         * ---------------------------------------------------------------- */
        $aboutBlock = static function (array $b) use ($profileCard): void {
            /* THE TEXT TAKES BACK WHATEVER THE SIDE COLUMNS DO NOT USE.
             *
             * Both are optional — the office may not have uploaded a photograph,
             * and a section may have no official — and a fixed split would leave
             * a third of the row as empty tint, which reads as a picture that
             * failed to load rather than as a column that was never there. */
            $photos    = array_values(array_filter($b['photos'] ?? []));
            $hasPhoto  = $photos !== [];
            $hasPerson = ($b['person'] ?? null) !== null;

            [$photoCol, $textCol] = match (true) {
                $hasPhoto && $hasPerson => ['col-lg-4', 'col-lg-5'],
                /* Wider image and a comfortable measure beside it when there is
                   no third column to make room for. */
                $hasPhoto               => ['col-lg-5', 'col-lg-7'],
                $hasPerson              => ['',          'col-lg-9'],
                default                 => ['',          'col-12'],
            };
            ?>
            <div class="about-block" id="about-<?= e($b['id']) ?>">

                <?php /* The centred header. .section-head is the component every
                         other section on this page opens with, so these read as
                         parts of one website rather than as blocks somebody
                         styled separately. */ ?>
                <div class="section-head">
                    <span class="eyebrow">
                        <i class="fa-solid <?= e($b['icon']) ?>"></i> <?= e($b['eyebrow']) ?>
                    </span>

                    <?php /* Two fields joined here rather than one holding a
                             <span>. An officer should not have to type markup to
                             colour half a heading, and a field that accepts
                             markup is a field that can break the page from the
                             settings screen. */ ?>
                    <h2 class="section-title">
                        <?= e($b['title']) ?><?php if ($b['titleEm'] !== ''): ?>
                            <span class="text-grad"><?= e($b['titleEm']) ?></span>
                        <?php endif; ?>
                    </h2>

                    <?php if (($b['subtitle'] ?? '') !== ''): ?>
                        <p class="section-sub"><?= e($b['subtitle']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="row g-4 g-lg-5 align-items-start">

                    <?php if ($hasPhoto): ?>
                        <div class="<?= e($photoCol) ?>">
                            <?php
                            /* ONE PHOTOGRAPH ON THE PAGE, THE REST BEHIND A DOOR.
                             *
                             * The Tourism Office briefly showed two stacked, and
                             * the office asked for the destination pages' pattern
                             * instead: a single image, and "View all photos" once
                             * there is more than one. It keeps every block the
                             * same height whether the office has uploaded one
                             * picture or six, and it is the behaviour a visitor
                             * has already met elsewhere on this site.
                             *
                             * The extras are still in the markup as [data-lightbox]
                             * links — hidden, but present — because that is how the
                             * viewer knows what to page through. Each block names
                             * its OWN group, so the Office photographs and the
                             * gallery further up the page are separate sets.
                             */
                            $lead  = $photos[0];
                            $extra = array_slice($photos, 1);
                            $group = 'about-' . $b['id'];

                            /* THE GRID IS CULTURAL HERITAGE'S ALONE.
                             *
                             * Every block holds a gallery now, but the office was
                             * explicit that only Cultural Heritage should look like
                             * one — the other three keep a single image with "View
                             * all photos" over its corner however many they hold.
                             * Heritage is a set of festivals, which is a gallery;
                             * the Municipal Building is one subject photographed
                             * more than once, which is a picture with alternates.
                             *
                             * Still needs four to make a 2×2: below that it falls
                             * back to the single image, because a grid with an
                             * empty cell is worse than either. */
                            $grid = ($b['grid'] ?? false) && count($photos) >= 4;
                            ?>
                            <?php if ($grid): ?>
                                <?php
                                /* Four tiles. The fourth is a real photograph with
                                   the door over it, so the grid is never a hole —
                                   the same thing every gallery of this shape does,
                                   and what the office drew. */
                                $tiles  = array_slice($photos, 0, 4);
                                $behind = count($photos) - count($tiles);
                                ?>
                                <div class="about-grid">
                                    <?php foreach ($tiles as $i => $src): ?>
                                        <?php $last = $i === count($tiles) - 1 && $behind > 0; ?>
                                        <a href="<?= e($src) ?>" data-lightbox="<?= e($group) ?>"
                                           data-caption="<?= e($b['alt']) ?>"
                                           class="about-grid__cell<?= $last ? ' is-more' : '' ?>"
                                           <?= $last
                                               ? 'aria-label="View all ' . n(count($photos)) . ' photos"'
                                               : 'aria-label="' . e($b['alt']) . '"' ?>>
                                            <img src="<?= e($src) ?>"
                                                 alt="<?= $i === 0 ? e($b['alt']) : '' ?>"
                                                 <?= $i === 0 ? '' : 'aria-hidden="true"' ?>
                                                 class="about-grid__img" loading="lazy">

                                            <?php if ($last): ?>
                                                <span class="about-grid__veil">
                                                    <strong>+<?= n($behind) ?></strong>
                                                    <small>View all photos</small>
                                                </span>
                                            <?php endif; ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>

                                <?php /* Everything past the four tiles: in the
                                         document for the viewer to page through,
                                         out of the layout entirely. */ ?>
                                <?php foreach (array_slice($photos, 4) as $src): ?>
                                    <a href="<?= e($src) ?>" data-lightbox="<?= e($group) ?>"
                                       data-caption="<?= e($b['alt']) ?>"
                                       class="about-block__hidden" tabindex="-1" aria-hidden="true"></a>
                                <?php endforeach; ?>

                            <?php else: ?>
                                <figure class="about-block__frame">
                                    <?php /* THE LABEL IS INSIDE THE LINK, not a second
                                             one beside it. Two anchors pointing at the
                                             same photograph would both join the
                                             viewer's group, and it would count two
                                             pictures where there is one — "1 / 3" for
                                             a pair. One trigger, one entry. */ ?>
                                    <a href="<?= e($lead) ?>" data-lightbox="<?= e($group) ?>"
                                       data-caption="<?= e($b['alt']) ?>"
                                       class="about-block__link"
                                       aria-label="<?= $extra === []
                                           ? 'View ' . e($b['alt']) . ' larger'
                                           : 'View all ' . n(count($photos)) . ' photos' ?>">
                                        <img src="<?= e($lead) ?>" alt="<?= e($b['alt']) ?>"
                                             class="about-block__img" loading="lazy">

                                        <?php if ($extra !== []): ?>
                                            <?php /* Over the corner of the photograph,
                                                     the way the destination galleries
                                                     do it — a door on the image rather
                                                     than a button under it. */ ?>
                                            <span class="about-block__more">
                                                <i class="fa-regular fa-images" aria-hidden="true"></i>
                                                View all <?= n(count($photos)) ?> photos
                                            </span>
                                        <?php endif; ?>
                                    </a>

                                    <?php /* Both badge fields blank means the office
                                             does not want the card, rather than an
                                             empty white box over the photograph. */ ?>
                                    <?php if (($b['badge'] ?? null) !== null): ?>
                                        <figcaption class="about__badge">
                                            <i class="fa-solid fa-award"></i>
                                            <strong><?= e($b['badge'][0]) ?></strong>
                                            <span><?= e($b['badge'][1]) ?></span>
                                        </figcaption>
                                    <?php endif; ?>
                                </figure>

                                <?php /* The extras: reachable by the viewer, never
                                         drawn. */ ?>
                                <?php foreach ($extra as $src): ?>
                                    <a href="<?= e($src) ?>" data-lightbox="<?= e($group) ?>"
                                       data-caption="<?= e($b['alt']) ?>"
                                       class="about-block__hidden" tabindex="-1" aria-hidden="true"></a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="<?= e($textCol) ?>">
                        <?php /* LABELLED PARTS. Each is a settings field of its
                                 own because they are edited separately, and each
                                 carries its own small heading so a reader can
                                 tell where one ends.

                                 A part with no text is not drawn, heading and
                                 all: an office that has not written its second
                                 paragraph should not get an empty label sitting
                                 over nothing. */ ?>
                        <?php foreach ($b['parts'] as [$label, $text]): ?>
                            <?php if (trim((string) $text) !== ''): ?>
                                <?php if (trim((string) $label) !== ''): ?>
                                    <h3 class="about-block__label"><?= e($label) ?></h3>
                                <?php endif; ?>
                                <div class="about-block__text"><?= nl2br(e($text)) ?></div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($hasPerson): ?>
                        <div class="col-lg-3">
                            <?php $profileCard($b['person'], $b['role']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php };
        ?>

        <!-- ---------------------------------------------------------------
             01 · A BRIEF HISTORY
             -------------------------------------------------------------
             ITS OWN SECTION, AND FIRST. It used to run on as a second
             paragraph inside About Tampakan, under a small label. The office
             asked for it separated and moved to the front: the page now opens
             on where the municipality came from, then says what it is today.

             Every word is transcribed from the office's own printed trifold
             brochure, through Settings. The dates, the Republic Act number and
             the etymology of "tamfaken" are the municipality's own record —
             theirs to state and correct, and nothing here is generated.
             ------------------------------------------------------------ -->
        <?php if ($aboutHistory !== ''): ?>
            <?php $aboutBlock([
                'id'       => 'history',
                'eyebrow'  => $ab('about_history_eyebrow', 'Our Roots'),
                'icon'     => 'fa-clock-rotate-left',
                'title'    => $ab('about_history_title', 'A Brief'),
                'titleEm'  => $ab('about_history_title_em', 'History'),
                'subtitle' => $ab('about_history_subtitle', 'How Tampakan came to be'),
                'parts'    => [['', $aboutHistory]],
                'photos'   => $historyPhotos,
                'badge'    => null,
                'alt'      => 'Tampakan through its history',
                'person'   => null,
                'role'     => '',
            ]); ?>
        <?php endif; ?>

        <!-- ---------------------------------------------------------------
             02 · ABOUT TAMPAKAN
             -------------------------------------------------------------
             ONE PHOTOGRAPH, the municipal building, at the office's explicit
             instruction. This block carried an overlapping pair until they
             asked for a single image.
             ------------------------------------------------------------ -->
        <?php $aboutBlock([
            'id'       => 'tampakan',
            'eyebrow'  => $ab('about_eyebrow', 'About Tampakan'),
            'icon'     => 'fa-landmark',
            'title'    => $ab('about_title', 'About'),
            'titleEm'  => $ab('about_title_em', 'Tampakan'),
            'subtitle' => $ab('about_subtitle', 'Discover the place we call home'),
            'parts'    => [[$ab('about_lead_label'), $ab('about_lead')]],
            'photos'   => $tampakanPhotos,
            'badge'    => ($badgeValue !== '' || $badgeLabel !== '')
                              ? [$badgeValue, $badgeLabel] : null,
            'alt'      => 'Tampakan Municipal Building',
            'person'   => $mayor,
            'role'     => 'Municipal Mayor',
        ]); ?>

        <!-- ---------------------------------------------------------------
             03 · ABOUT THE TOURISM OFFICE
             ------------------------------------------------------------ -->
        <?php if ($hasOfficeArea): ?>
            <?php $aboutBlock([
                'id'       => 'office',
                'eyebrow'  => $ab('about_office_eyebrow', 'About the Tourism Office'),
                'icon'     => 'fa-people-roof',
                'title'    => 'About the',
                'titleEm'  => 'Tourism Office',
                'subtitle' => $ab('about_office_subtitle',
                                  'Supporting tourism and local destinations'),
                'parts'    => [
                    [$ab('about_office_label'), $officeBlurb],
                    ['', $ab('about_office_text2')],
                ],
                'photos'   => $officePhotos,
                'badge'    => null,
                'alt'      => 'The Municipal Tourism Office of Tampakan',
                'person'   => $coordinator,
                'role'     => 'Municipal Tourism Coordinator',
            ]); ?>
        <?php endif; ?>

        <!-- ---------------------------------------------------------------
             04 · CULTURAL HERITAGE
             -------------------------------------------------------------
             The photograph slot is deliberately here before the office has an
             image to put in it — they asked for it to be ready. With none
             uploaded the block renders as heading and prose across a
             comfortable measure; the day a photograph arrives it becomes the
             same image-and-content shape as the two above, with no further
             work.
             ------------------------------------------------------------ -->
        <?php /* Text OR photographs. An office that has uploaded a gallery and
                 not yet written the paragraph should see its pictures, not an
                 absent section — and the block already draws correctly with
                 either half missing. */ ?>
        <?php if ($aboutHeritage !== '' || $heritagePhotos !== []): ?>
            <?php $aboutBlock([
                'id'       => 'heritage',
                'eyebrow'  => $ab('about_heritage_eyebrow', 'Culture & Traditions'),
                'icon'     => 'fa-hands-holding-circle',
                'title'    => $ab('about_heritage_title', 'Cultural'),
                'titleEm'  => $ab('about_heritage_title_em', 'Heritage'),
                'subtitle' => $ab('about_heritage_subtitle',
                                  'The festivals and traditions we celebrate'),
                'parts'    => [['', $aboutHeritage]],
                /* THE ONE BLOCK WITH A GALLERY RATHER THAN A SLOT.
                   Cultural Heritage moved off the destination pages and the
                   office asked to upload as many photographs as they have. The
                   others still take a single settings row, which is right for
                   "the Municipal Building" and wrong for "our festivals". */
                'photos'   => $heritagePhotos,
                'grid'     => true,        // the only block drawn as a gallery
                'badge'    => null,
                'alt'      => 'Cultural heritage of Tampakan',
                'person'   => null,
                'role'     => '',
            ]); ?>
        <?php endif; ?>

        <?php /* -----------------------------------------------------------
                 05 / 06 · MISSION AND VISION

                 Side by side at the foot of the section, content and styling
                 untouched at the office's instruction. They were once stacked
                 inside the Tourism Office column, where a three-column row
                 squeezed two paragraphs of statement into a 30-character
                 measure. They are the office's two standing commitments and
                 they read as a pair.
              -------------------------------------------------------------- */ ?>
        <?php if ($hasMissionOrVision): ?>
            <div class="row g-4 mv-row">
                <?php foreach ([
                    ['mission', 'fa-solid fa-bullseye'],
                    ['vision',  'fa-regular fa-eye'],
                ] as [$part, $icon]): ?>
                    <?php
                    $mvTitle = $ab('about_' . $part . '_title');
                    $mvText  = $ab('about_' . $part . '_text');
                    ?>
                    <?php if ($mvTitle !== '' || $mvText !== ''): ?>
                        <div class="col-lg-6">
                            <div class="mv-card mv-card--<?= $part ?>">
                                <span class="mv-card__icon"><i class="<?= e($icon) ?>"></i></span>
                                <div>
                                    <h3><?= e($mvTitle) ?></h3>
                                    <p><?= nl2br(e($mvText)) ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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

                <?php /* LEAFLET AND OPENSTREETMAP, NOT A GOOGLE MAPS IFRAME.
                         ---------------------------------------------------------
                         This box showed "This content is blocked. Contact the site
                         owner to fix the issue." — the Content-Security-Policy in
                         app/bootstrap.php allows frames from 'self' and YouTube
                         and nothing else, so the embed never rendered. It has
                         never rendered.

                         The fix is not to add Google to frame-src. A Maps embed
                         sets third-party cookies, and the cookie notice on this
                         same page tells every visitor "No advertising, analytics
                         or tracking cookies are used". Opening the header would
                         have made a municipal privacy statement untrue in order
                         to show a map.

                         The site already had the answer: map.php and the tourist
                         map above both use Leaflet with OpenStreetMap tiles, the
                         script is already loaded on this page, and img-src
                         already allows the tiles. No header changes, no API key,
                         no tracking — and the same map everywhere. */ ?>
                <div class="contact-map">
                    <div id="officeMap"
                         data-lat="<?= e((string) $site['lat']) ?>"
                         data-lng="<?= e((string) $site['lng']) ?>"
                         data-label="<?= e((string) setting('office_name', 'Municipal Tourism Office')) ?>"
                         data-address="<?= e((string) $contact['address']) ?>"
                         role="img"
                         aria-label="Map showing the Municipal Tourism Office in Tampakan, South Cotabato"></div>
                </div>
            </div>

            <?php /* THE FORM MOVED INTO A MODAL.        15 Sep 2026
                     -------------------------------------------------------
                     What was here: one form of six fields, always open, always
                     the same shape, taking up more of the homepage than any
                     other single thing on it.

                     The office asked for three kinds of enquiry that are
                     genuinely different — a rating of a named guide, a formal
                     data request of fourteen fields, and a general message.
                     Rendering all three inline would have made the longest
                     section on the page longer still, and shown every visitor
                     two forms they did not want.

                     So the page keeps a chooser and the forms live in a modal.
                     The section is shorter than it was before, not longer. */ ?>
            <div class="col-lg-7">
                <div class="contact-form-card contact-start">
                    <h3 class="contact-form-card__title">Send Us a Message</h3>
                    <p class="contact-form-card__sub">
                        Tell us what your enquiry is about and we will open the right form.
                    </p>

                    <div class="contact-start__pick">
                        <label for="cfCategory" class="form-label">
                            What is this about? <span>*</span>
                        </label>

                        <?php /* A real <select>, not three buttons dressed as one.
                                 The office asked for a dropdown; it is also the
                                 control a phone renders as a native picker, which
                                 is a better target than three cards squeezed side
                                 by side on a 360px screen. */ ?>
                        <select class="form-select form-select-lg" id="cfCategory"
                                aria-describedby="cfCategoryHelp">
                            <option value="">Choose a category&hellip;</option>
                            <option value="tour-guide">Tour Guide</option>
                            <option value="data-request">Data Request / Inquiries</option>
                            <option value="other">Other Concerns / Suggestions</option>
                        </select>

                        <p class="contact-start__help" id="cfCategoryHelp">
                            Rate a guide you travelled with, request tourism data, or write to us
                            about anything else.
                        </p>

                        <?php /* NO data-bs-toggle, and that is the point.
                                 The category can no longer be changed once the
                                 dialog is open, so opening it without one chosen
                                 would drop the visitor into whichever form came
                                 first with no way to say that was not the one
                                 they wanted. script.js opens it, and only once a
                                 category has actually been picked.

                                 Nothing is lost by taking the attribute off: the
                                 dialog is a Bootstrap modal, so a browser that
                                 could not run the handler could not have opened
                                 it declaratively either. */ ?>
                        <button type="button" class="btn btn-green btn-lg w-100" id="cfOpen">
                            <i class="fa-regular fa-pen-to-square"></i> Continue
                        </button>
                    </div>

                    <?php /* THE SERVER'S ANSWER STILL LANDS ON THE PAGE, not in
                             the modal. A visitor with no JavaScript posts the
                             form normally and is redirected back to #contact with
                             the modal closed; if this lived inside the modal they
                             would be told nothing at all.

                             script.js moves a live answer up into the modal while
                             it is open, so nobody has to close it to find out
                             whether their message was sent. */ ?>
                    <div id="formAlert"
                         class="form-alert <?= $contactFlashes !== [] ? 'form-alert--' . e($contactFlashes[0]['type'] === 'success' ? 'success' : 'error') . ' is-visible' : '' ?>"
                         role="status" aria-live="polite">
                        <?php if ($contactFlashes !== []): ?>
                            <i class="fa-solid <?= $contactFlashes[0]['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                            <span><?= e((string) $contactFlashes[0]['message']) ?></span>
                        <?php endif; ?>
                    </div>

                    <ul class="contact-start__notes">
                        <li><i class="fa-solid fa-shield-halved"></i>
                            Your details go to the Municipal Tourism Office and nowhere else.</li>
                        <li><i class="fa-regular fa-clock"></i>
                            We usually respond within one working day.</li>
                    </ul>
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
                    <?php /* Both marks — see the same pair in
                             app/views/partials/public-footer.php, which every
                             page but this one uses. The landing page carries its
                             own copy of the footer, so a change to one has to be
                             made to the other. */ ?>
                    <div class="footer__brand">
                        <img src="assets/img/tampakan_logo.png" alt="Official Seal of the Municipality of Tampakan, Province of South Cotabato" width="70" height="70">
                        <img src="assets/img/tourism-logo-mark.png" alt="Logo of the Tampakan Municipal Tourism Office" width="70" height="70">
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
                    <?php /* "Staff", not "Administrator". The office asked for it
                             and they are right: the people who sign in here are
                             the tourism staff and the destination managers, and
                             only one of them is an administrator of anything.
                             The destination is unchanged. */ ?>
                    <a href="<?= e($site['admin_url']) ?>" class="btn btn-soft-light btn-sm mt-2">
                        <i class="fa-solid fa-lock"></i> Staff Login
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="footer__bottom">
        <div class="container">
            <?php /* Copyright left, links right — unchanged. What changed is in
                     .footer__bottom-row: the row now stops short of the chat
                     launcher's corner instead of running underneath it. */ ?>
            <div class="footer__bottom-row">
                <p class="mb-0">
                    &copy; <?= e((string) $currentYear) ?> <?= e($site['municipality']) ?>, <?= e($site['province']) ?>.
                    All rights reserved.
                </p>
                <ul class="footer__legal">
                    <li><a href="#privacy" data-bs-toggle="modal" data-bs-target="#privacyModal">Privacy Policy</a></li>
                    <li><a href="#terms" data-bs-toggle="modal" data-bs-target="#termsModal">Terms &amp; Conditions</a></li>
                    <li><a href="#" data-cookie-open>Cookies</a></li>
                    <li><a href="#contact">Sitemap</a></li>
                </ul>
            </div>
        </div>
    </div>
</footer>

<?php require __DIR__ . '/app/views/partials/cookie-notice.php'; ?>

<!-- Back-to-top button -->
<a href="#top" id="backToTop" class="back-to-top" aria-label="Back to top">
    <i class="fa-solid fa-chevron-up"></i>
</a>

<!-- =========================================================================
     LIGHTBOX — driven by script.js
     ====================================================================== -->
<?php require __DIR__ . '/app/views/partials/lightbox.php'; ?>

<!-- =========================================================================
     CONTACT US — THE CATEGORISED ENQUIRY MODAL             15 Sep 2026
     -------------------------------------------------------------------------
     THREE SEPARATE <form> ELEMENTS, NOT ONE THAT SWAPS ITS ACTION.

     One form would have to carry every field of all three, post fields the
     endpoint it reached has no use for, and — the part that actually breaks —
     hold `required` on inputs that are display:none. A hidden required control
     fails checkValidity() and the browser refuses to submit while reporting
     "an invalid form control is not focusable", which the visitor sees as a
     Submit button that does nothing at all.

     Three forms, each validating only itself, each posting to its own endpoint.
     Only the visible one is ever submitted.
     ====================================================================== -->
<div class="modal fade" id="inquiryModal" tabindex="-1"
     aria-labelledby="inquiryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"
         id="inquiryDialog">
        <div class="modal-content inquiry-modal">

            <?php /* THE TITLE IS THE CATEGORY, and it is the only place the
                     category is named inside the dialog.
                     The chooser on the page behind has already been answered by
                     the time this opens; asking the same question again at the
                     top of the form is a second control that can disagree with
                     the first, and one more thing between the visitor and the
                     fields they came to fill in. script.js writes both the label
                     and the icon from whichever category was chosen. */ ?>
            <div class="modal-header">
                <h5 class="modal-title" id="inquiryModalLabel">
                    <i class="fa-regular fa-envelope" data-inquiry-icon aria-hidden="true"></i>
                    <span data-inquiry-title>Contact the Tourism Office</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">

                <?php /* Where an answer appears while the modal is open. The copy
                         on the page behind stays authoritative for the no-script
                         path; script.js writes into whichever of the two the
                         visitor can actually see. */ ?>
                <div id="inquiryAlert" class="form-alert" role="status" aria-live="polite"></div>

                <!-- ===========================================================
                     A · TOUR GUIDE — rate a guide you travelled with
                     ======================================================== -->
                <div class="inquiry__panel" data-panel="tour-guide" hidden>

                    <?php if ($guideRoster === []): ?>
                        <?php /* No accredited guide on the roster, so nothing
                                 honest to put in the select. An empty dropdown
                                 above a Submit button is a form that can only
                                 fail; this says why instead. */ ?>
                        <div class="inquiry__empty">
                            <i class="fa-regular fa-address-card"></i>
                            <p>
                                There are no accredited guides listed just now. Please use
                                <strong>Other Concerns / Suggestions</strong> to tell us about
                                your guide, and the Office will follow it up.
                            </p>
                        </div>
                    <?php else: ?>
                        <p class="inquiry__lead">
                            Travelled with one of our accredited guides? Tell the Office how it went.
                            Ratings are read by an officer before they appear anywhere.
                        </p>

                        <form id="guideReviewForm" class="row g-3" novalidate data-no-busy
                              data-inquiry-form
                              method="post" action="<?= e(base_url('/api/contact/guide-review.php')) ?>">
                            <?= csrf_field() ?>

                            <?php /* Honeypot and dwell, the same pair guarding every
                                     other public form here. */ ?>
                            <div class="visually-hidden" aria-hidden="true">
                                <label for="grWebsite">Leave this blank</label>
                                <input type="text" id="grWebsite" name="website" tabindex="-1" autocomplete="off">
                            </div>
                            <input type="hidden" name="rendered_at" value="<?= time() ?>">

                            <div class="col-12">
                                <label for="grGuide" class="form-label">Tour Guide <span>*</span></label>
                                <select class="form-select" id="grGuide" name="guide_id" required>
                                    <option value="">Choose the guide you travelled with&hellip;</option>
                                    <?php foreach ($guideRoster as $g): ?>
                                        <option value="<?= (int) $g['id'] ?>">
                                            <?= e((string) $g['full_name']) ?>
                                            (<?= e((string) $g['guide_code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Please choose a tour guide.</div>
                            </div>

                            <div class="col-12">
                                <?php /* RADIO BUTTONS UNDER THE STARS, not a widget
                                         driven by click handlers. A rating control
                                         built from divs is invisible to a keyboard
                                         and to a screen reader; this one is a real
                                         radio group that arrow keys already work in,
                                         with the stars drawn over it in CSS.
                                         Rendered high to low so that the visual
                                         order left-to-right is 1..5 after the CSS
                                         reverses the row — which is also what makes
                                         the "highlight every star up to this one"
                                         hover work with a sibling selector alone. */ ?>
                                <span class="form-label d-block" id="grRatingLabel">
                                    Rating <span>*</span>
                                </span>
                                <div class="star-rate" role="radiogroup" aria-labelledby="grRatingLabel">
                                    <?php foreach ([5, 4, 3, 2, 1] as $stars): ?>
                                        <input type="radio" class="star-rate__input" name="rating"
                                               id="grStar<?= $stars ?>" value="<?= $stars ?>" required>
                                        <label class="star-rate__star" for="grStar<?= $stars ?>"
                                               title="<?= $stars ?> out of 5">
                                            <i class="fa-solid fa-star" aria-hidden="true"></i>
                                            <span class="visually-hidden"><?= $stars ?> out of 5</span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <?php /* A CLASS OF ITS OWN, NOT .invalid-feedback.d-block.
                                         That pairing showed the error on a form
                                         nobody had touched yet: .d-block is
                                         display:block !important, [hidden] is
                                         display:none !important, they tie on
                                         specificity, and Bootstrap's utilities are
                                         loaded last — so the attribute lost and the
                                         message was on screen from the moment the
                                         modal opened.

                                         .invalid-feedback would not have worked
                                         here anyway: Bootstrap reveals it through
                                         `:invalid ~ .invalid-feedback`, and this sits
                                         beside the star group rather than beside an
                                         input, so the rule never matches it. This
                                         message is driven by script and by the
                                         attribute alone. */ ?>
                                <div class="rating-error" data-rating-error hidden>
                                    Please choose a rating.
                                </div>
                            </div>

                            <div class="col-12">
                                <label for="grComment" class="form-label">Comment</label>
                                <textarea class="form-control" id="grComment" name="comment" rows="4"
                                          maxlength="1000"
                                          placeholder="What was the guide like? Anything the Office should know?"></textarea>
                                <div class="form-text">Optional. Up to 1,000 characters.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="grName" class="form-label">Your Name</label>
                                <input type="text" class="form-control" id="grName" name="visitor_name"
                                       maxlength="120" autocomplete="name" placeholder="Optional">
                            </div>
                            <div class="col-md-6">
                                <label for="grEmail" class="form-label">Your Email</label>
                                <input type="email" class="form-control" id="grEmail" name="visitor_email"
                                       maxlength="190" autocomplete="email" placeholder="Optional">
                                <div class="invalid-feedback">Please enter a valid email address.</div>
                            </div>

                            <div class="col-12">
                                <div class="inquiry__actions">
                                    <button type="button" class="btn btn-quiet" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-green">
                                        <i class="fa-regular fa-star"></i> Submit Rating
                                    </button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- ===========================================================
                     B · DATA REQUEST — the official form, field for field
                     ======================================================== -->
                <div class="inquiry__panel" data-panel="data-request" hidden>
                    <p class="inquiry__lead">
                        This is the Office's official Data Request form. Please complete it as fully
                        as you can — an incomplete request takes longer to act on.
                    </p>

                    <form id="dataRequestForm" class="row g-3" novalidate data-no-busy
                          data-inquiry-form
                          method="post" action="<?= e(base_url('/api/contact/data-request.php')) ?>">
                        <?= csrf_field() ?>

                        <div class="visually-hidden" aria-hidden="true">
                            <label for="drWebsite">Leave this blank</label>
                            <input type="text" id="drWebsite" name="website" tabindex="-1" autocomplete="off">
                        </div>
                        <input type="hidden" name="rendered_at" value="<?= time() ?>">

                        <?php /* FIELDSETS, NOT STYLED DIVS WITH A HEADING.
                                 The office asked for three logical sections. A
                                 <fieldset> with a <legend> is the element that
                                 actually groups controls for assistive technology
                                 — a screen reader announces "Requester Information"
                                 as it enters the group. A div with an <h4> in it
                                 looks identical and announces nothing. */ ?>

                        <!-- 1 · Requester Information -->
                        <fieldset class="col-12 form-section">
                            <legend class="form-section__title">
                                <span class="form-section__step">1</span> Requester Information
                            </legend>

                            <div class="row g-3">
                                <div class="col-12">
                                    <label for="drOrg" class="form-label">Department / Organization</label>
                                    <input type="text" class="form-control" id="drOrg" name="organisation"
                                           maxlength="190" autocomplete="organization"
                                           placeholder="e.g. Provincial Tourism Office, or leave blank if personal">
                                </div>

                                <div class="col-md-4">
                                    <label for="drFirst" class="form-label">First Name <span>*</span></label>
                                    <input type="text" class="form-control" id="drFirst" name="first_name"
                                           required maxlength="80" autocomplete="given-name">
                                    <div class="invalid-feedback">Please enter your first name.</div>
                                </div>
                                <div class="col-md-4">
                                    <label for="drMiddle" class="form-label">Middle Name</label>
                                    <input type="text" class="form-control" id="drMiddle" name="middle_name"
                                           maxlength="80" autocomplete="additional-name">
                                </div>
                                <div class="col-md-4">
                                    <label for="drLast" class="form-label">Last Name <span>*</span></label>
                                    <input type="text" class="form-control" id="drLast" name="last_name"
                                           required maxlength="80" autocomplete="family-name">
                                    <div class="invalid-feedback">Please enter your last name.</div>
                                </div>

                                <div class="col-12">
                                    <label for="drAddress" class="form-label">Home Address</label>
                                    <input type="text" class="form-control" id="drAddress" name="home_address"
                                           maxlength="255" autocomplete="street-address"
                                           placeholder="House/Purok, Barangay, Municipality, Province">
                                </div>

                                <div class="col-md-5">
                                    <label for="drBirth" class="form-label">Birthdate</label>
                                    <?php /* max=today, enforced again on the server.
                                             The attribute stops the obvious slip in
                                             the picker; the server stops the post
                                             that never went near the picker. */ ?>
                                    <input type="date" class="form-control" id="drBirth" name="birthdate"
                                           max="<?= e(date('Y-m-d')) ?>" autocomplete="bday">
                                    <div class="invalid-feedback">Please check the birthdate.</div>
                                </div>

                                <div class="col-md-3">
                                    <label for="drAge" class="form-label">Age</label>
                                    <?php /* CALCULATED, AND NOT SUBMITTED — it has no
                                             name attribute, so it never reaches the
                                             server. An age is a fact with a shelf
                                             life of a year; the birthdate beside it
                                             is the durable one, and every screen
                                             derives the age from that. This box is
                                             here so the requester can see that what
                                             they entered means what they meant. */ ?>
                                    <input type="text" class="form-control" id="drAge"
                                           readonly tabindex="-1" placeholder="—"
                                           aria-describedby="drAgeHelp">
                                    <div class="form-text" id="drAgeHelp">From birthdate</div>
                                </div>

                                <div class="col-md-4">
                                    <label for="drCivil" class="form-label">Civil Status</label>
                                    <select class="form-select" id="drCivil" name="civil_status">
                                        <option value="">Choose&hellip;</option>
                                        <?php foreach (DataRequestRepository::CIVIL_STATUSES as $cs): ?>
                                            <option value="<?= e($cs) ?>"><?= e($cs) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-12">
                                    <label for="drTitle" class="form-label">Title / Job / Designation</label>
                                    <input type="text" class="form-control" id="drTitle" name="designation"
                                           maxlength="160" autocomplete="organization-title"
                                           placeholder="e.g. Research Assistant, Student, Journalist">
                                </div>
                            </div>
                        </fieldset>

                        <!-- 2 · Contact Information -->
                        <fieldset class="col-12 form-section">
                            <legend class="form-section__title">
                                <span class="form-section__step">2</span> Contact Information
                            </legend>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="drEmail" class="form-label">Email Address <span>*</span></label>
                                    <input type="email" class="form-control" id="drEmail" name="email"
                                           required maxlength="190" autocomplete="email"
                                           placeholder="you@example.com">
                                    <div class="invalid-feedback">Please enter a valid email address.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="drPhone" class="form-label">Contact Number</label>
                                    <input type="tel" class="form-control" id="drPhone" name="contact_number"
                                           maxlength="40" autocomplete="tel" placeholder="+63 9XX XXX XXXX">
                                </div>
                            </div>
                        </fieldset>

                        <!-- 3 · Request Details -->
                        <fieldset class="col-12 form-section">
                            <legend class="form-section__title">
                                <span class="form-section__step">3</span> Request Details
                            </legend>

                            <div class="row g-3">
                                <div class="col-12">
                                    <label for="drPurpose" class="form-label">Purpose of Request <span>*</span></label>
                                    <textarea class="form-control" id="drPurpose" name="purpose" rows="3"
                                              required minlength="10" maxlength="1000"
                                              placeholder="What will the data be used for?"></textarea>
                                    <div class="invalid-feedback">Please tell us what the data is for.</div>
                                </div>

                                <div class="col-12">
                                    <label for="drData" class="form-label">Requested Data / Content <span>*</span></label>
                                    <textarea class="form-control" id="drData" name="requested_data" rows="4"
                                              required minlength="10" maxlength="2000"
                                              placeholder="Which figures, for which destinations, and over what period?"></textarea>
                                    <div class="invalid-feedback">Please describe the data you need.</div>
                                </div>

                                <div class="col-md-6">
                                    <label for="drNeeded" class="form-label">Preferred Completion Date</label>
                                    <input type="date" class="form-control" id="drNeeded" name="needed_by"
                                           min="<?= e(date('Y-m-d')) ?>">
                                    <div class="form-text">The Office will tell you if this is not possible.</div>
                                    <div class="invalid-feedback">Please choose a date that has not passed.</div>
                                </div>
                            </div>
                        </fieldset>

                        <div class="col-12">
                            <div class="form-check inquiry__consent">
                                <input class="form-check-input" type="checkbox" id="drConsent" required>
                                <label class="form-check-label" for="drConsent">
                                    I consent to the Municipal Tourism Office processing the personal details
                                    above for the purpose of this request, in line with the
                                    <a href="#privacy" data-bs-toggle="modal" data-bs-target="#privacyModal">Privacy Policy</a>.
                                </label>
                                <div class="invalid-feedback">Your consent is required.</div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="inquiry__actions">
                                <button type="button" class="btn btn-quiet" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-green">
                                    <i class="fa-regular fa-paper-plane"></i> Submit Request
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- ===========================================================
                     C · OTHER CONCERNS / SUGGESTIONS
                     ======================================================== -->
                <div class="inquiry__panel" data-panel="other" hidden>
                    <p class="inquiry__lead">
                        Anything else — a suggestion, a concern, or a question about visiting Tampakan.
                    </p>

                    <?php /* KEEPS THE ID #contactForm AND THE SAME ENDPOINT.
                             This is the form that was on the page, moved. The
                             office's inbox filters messages by subject, and that
                             filter is built from what this form has been sending
                             since it shipped; a version that stopped sending a
                             subject would leave the filter offering topics no new
                             message ever carries.

                             The topic select is therefore kept, even though the
                             brief lists only name/email/message for this
                             category. It is one field, it preserves a screen the
                             office already uses, and "Other Concerns" is broad
                             enough that knowing which kind genuinely helps. */ ?>
                    <form id="contactForm" class="row g-3" novalidate data-no-busy
                          data-inquiry-form
                          method="post" action="<?= e(base_url('/api/contact/submit.php')) ?>">
                        <?= csrf_field() ?>

                        <input type="hidden" name="category" value="other">

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
                            <div class="form-check inquiry__consent">
                                <input class="form-check-input" type="checkbox" id="cfConsent" required>
                                <label class="form-check-label" for="cfConsent">
                                    I consent to the Municipal Tourism Office processing my details in line with the
                                    <a href="#privacy" data-bs-toggle="modal" data-bs-target="#privacyModal">Privacy Policy</a>.
                                </label>
                                <div class="invalid-feedback">Your consent is required.</div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="inquiry__actions">
                                <button type="button" class="btn btn-quiet" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-green">
                                    <i class="fa-regular fa-paper-plane"></i> Send Message
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

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


<!-- =========================================================================
     UPCOMING EVENTS — filter by kind of event
     -------------------------------------------------------------------------
     The twin of the news filter above, over the event vocabulary, and separate
     from it on purpose: both sections are on this one page, so a shared ?type=
     would narrow them together — choosing Festival would empty Latest News,
     which holds no festivals by design.

     The chips are real links to ?event=…#events, so the filter works with no
     JavaScript at all and every filtered view has an address that can be shared
     or bookmarked. Here the click is intercepted, the cards already in the DOM
     are shown or hidden, and history.replaceState rewrites the address. Nothing
     navigates, so the video, the strips and the map are never rebuilt.

     THE STRIP HAS TO BE TOLD. The rail watches for `hidden` changing on its own
     children and redraws its arrows and dots from that, so filtering to two
     events puts them away by itself — the two features needed no knowledge of
     each other.
     ====================================================================== -->
<script>
(function () {
    const chips = document.getElementById('eventChips');
    const grid  = document.getElementById('eventGrid');

    if (!grid) return;

    const items      = Array.from(grid.querySelectorAll('.event-item'));
    const countBox   = document.getElementById('eventCount');
    const countText  = document.getElementById('eventCountText');
    const empty      = document.getElementById('eventEmpty');
    const emptyTitle = document.getElementById('eventEmptyTitle');
    const emptyText  = document.getElementById('eventEmptyText');
    const emptyClear = document.getElementById('eventEmptyClear');

    /* Labels live in PHP; this is the only copy that crosses over, and it is
       generated from the same constant rather than typed out again. */
    const KINDS = <?= json_encode(AnnouncementRepository::EVENT_TYPES, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    /* The mirror of $eventShows in the PHP above. If one changes, change both. */
    const shows = (type, filter) => filter === '' || type === filter;

    function apply(filter, push) {
        let shown = 0;

        items.forEach(item => {
            const visible = shows(item.dataset.eventType, filter);
            item.hidden = !visible;
            if (visible) shown++;
        });

        if (chips) {
            chips.querySelectorAll('[data-event-filter]').forEach(chip => {
                chip.classList.toggle('is-active', chip.dataset.eventFilter === filter);
            });
        }

        if (countBox) {
            countBox.hidden = (filter === '' || shown === 0);

            if (!countBox.hidden) {
                countText.textContent = shown + (shown === 1 ? ' event' : ' events')
                    + ' under ' + KINDS[filter] + '.';
            }
        }

        if (empty) {
            empty.hidden = shown > 0;

            if (!empty.hidden) {
                emptyTitle.textContent = filter === ''
                    ? 'No events scheduled at the moment'
                    : 'Nothing under ' + KINDS[filter];
                emptyText.textContent = filter === ''
                    ? 'When the Tourism Office schedules a festival, a fair or a community activity, it appears here.'
                    : 'No event of this kind is coming up.';
                emptyClear.hidden = filter === '';
            }
        }

        /* The address stays shareable without a navigation. replaceState rather
           than pushState: a filter is not a place, and filling the Back button
           with six of them is how people end up unable to leave the page. */
        if (push) {
            const url = new URL(window.location.href);

            if (filter === '') { url.searchParams.delete('event'); }
            else               { url.searchParams.set('event', filter); }

            url.hash = 'events';
            window.history.replaceState({}, '', url);
        }
    }

    document.addEventListener('click', function (event) {
        const link = event.target.closest('[data-event-filter]');

        if (!link) return;

        event.preventDefault();
        apply(link.dataset.eventFilter, true);
        reveal();
    });

    /* SCROLL TO THE RESULT, NOT TO THE HEADING.
     *
     * This used to be scrollIntoView({block:'start'}) on #events, which put the
     * top of the SECTION at the top of the screen. The section opens with a
     * pill, a heading, a subtitle, the chips and the count — about 550px before
     * the first card begins — so the cards landed below the fold and were cut:
     * 360px off the bottom on a 1366x600 laptop, 129px on a phone. The visitor
     * filtered to three events and had to scroll to see any of them.
     *
     * What the click means is "show me these events", so the card is what gets
     * put on screen. The gap above it keeps the chips and the count in view, so
     * the control that was just used does not vanish.
     */
    /* HOW MUCH OF THE TOP IS NOT ACTUALLY VISIBLE.
     *
     * The main nav is position:fixed and 85px tall on a laptop, less once it
     * collapses on a phone. Measured rather than written down, because a
     * hardcoded 85 becomes wrong the first time somebody adds a line to the
     * bar and nothing points back here.
     *
     * The first fix put the card 96px from the top of the WINDOW, which is
     * 11px below an 85px bar — so the card was whole but the chips that had
     * just been clicked were hidden behind the nav. The screenshot showed it;
     * my own check had called them visible, because "inside the viewport" and
     * "not covered by something fixed on top of it" are different questions.
     */
    function coveredTop() {
        let covered = 0;

        document.querySelectorAll('nav, header, .navbar').forEach(el => {
            const cs = getComputedStyle(el);

            if (cs.position !== 'fixed' && cs.position !== 'sticky') { return; }

            const r = el.getBoundingClientRect();

            /* ITS HEIGHT, NOT ITS CURRENT BOTTOM, and no test on where it is
               sitting right now.
                 At the top of the page this bar floats 40px down and carries no
               .is-scrolled class; once the page moves it snaps to top:0 and
               shrinks — 96px to 85px on a laptop, 85 to 74 on a desktop. An
               earlier version of this required r.top <= 1, which is false while
               the bar is floating, so at scrollY 0 it concluded nothing covered
               the top and gave the card a 96px gap that the bar then ate. The
               chips clicked a moment earlier ended up behind it.
                 The un-scrolled height is the larger of the two, which errs
               towards a slightly bigger gap. That is the safe direction. */
            if (r.height >= 20 && r.width >= window.innerWidth * 0.5) {
                covered = Math.max(covered, r.height);
            }
        });

        return covered;
    }

    function reveal() {
        const card = grid.querySelector('.event-item:not([hidden])') || grid;
        const box  = card.getBoundingClientRect();
        const vh   = window.innerHeight;
        const top  = coveredTop();

        /* Already whole and clear of the bar: leave the page exactly where the
           visitor put it. Scrolling a card that is already readable is the
           jump this was supposed to fix. */
        if (box.top >= top && box.bottom <= vh) { return; }

        /* Room to leave between the bar and the card, so the chips and the
           count stay in sight. Capped, and given up entirely on a screen too
           short to afford it — the card itself comes first. */
        const room = vh - top - box.height;
        const gap  = Math.max(0, Math.min(96, room - 16));

        window.scrollTo({ top: window.scrollY + box.top - top - gap, behavior: 'smooth' });
    }
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

    /* The mirror of $newsShows in the PHP above. If one changes, change both.
       This used to read `type !== 'event'` for the All case, from when an
       event was one type among the notices. There are five event kinds now and
       latestNews() excludes every one of them server-side, so no event ever
       reaches this grid — the exception had nothing left to except. */
    const shows = (type, filter) => filter === '' || type === filter;

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
