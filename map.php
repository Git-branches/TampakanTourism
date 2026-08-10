<?php
declare(strict_types=1);

/**
 * =============================================================================
 *  TourSync — Interactive Tourist Map        Feature 5
 * -----------------------------------------------------------------------------
 *  Leaflet with OpenStreetMap tiles rather than Google Maps: no API key, no
 *  billing account, no usage cap that silently breaks the page after a busy
 *  month. For a municipal site that has to keep working without anyone
 *  watching a quota, that matters more than the styling difference.
 * =============================================================================
 */

require_once __DIR__ . '/bootstrap.php';

use App\Repositories\CategoryRepository;
use App\Repositories\DestinationRepository;

$markerCount = count(DestinationRepository::mapMarkers());
$categories  = CategoryRepository::withDestinations();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tourist Map — Tampakan Tourism</title>
<meta name="description" content="Interactive map of every tourist destination in the Municipality of Tampakan, South Cotabato, with directions and visitor information.">
<link rel="icon" href="assets/img/tampakan_logo.jpg" sizes="any">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body id="top">

<?php
/* No navbar, matching the destination and announcement pages. The gradient
   banner is this page's header, and the map below it wants every pixel of
   height it can get — a navbar costs it a strip of the viewport on exactly the
   screens where the map is hardest to read. Wayfinding is the breadcrumb,
   styled up by .page-head--top because it is now the only way back. */
$showNavbar = false;
require __DIR__ . '/app/views/partials/public-nav.php';
?>

<main>
<header class="page-head page-head--top">
    <div class="container">
        <nav aria-label="Breadcrumb" class="crumbs">
            <a href="<?= e(base_url('/')) ?>">Home</a>
            <i class="fa-solid fa-angle-right"></i>
            <span>Tourist Map</span>
        </nav>
        <h1>Interactive Tourist Map</h1>
        <p>
            <?= n($markerCount) ?> destination<?= $markerCount === 1 ? '' : 's' ?> pinned across the municipality.
            Tap a marker for details and directions.
        </p>
    </div>
</header>

<section class="section section--light pt-0">
    <div class="container">

        <?php if ($markerCount === 0): ?>

            <div class="empty-public">
                <i class="fa-solid fa-map-location-dot"></i>
                <h2>The map is being prepared</h2>
                <p>
                    Destinations appear here once the Municipal Tourism Office has recorded their
                    coordinates. In the meantime you can
                    <a href="<?= e(destinations_url()) ?>">browse the destination list</a>.
                </p>
            </div>

        <?php else: ?>

            <div class="map-toolbar">
                <div class="chip-row mb-0">
                    <button type="button" class="chip is-active" data-filter="all">All</button>
                    <?php foreach ($categories as $c): ?>
                        <button type="button" class="chip" data-filter="<?= e($c['slug']) ?>">
                            <?php if ($c['icon']): ?><i class="fa-solid <?= e($c['icon']) ?>"></i><?php endif; ?>
                            <?= e($c['name']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-outline-brand btn-sm" id="locateMe">
                    <i class="fa-solid fa-location-crosshairs"></i> Where am I?
                </button>
            </div>

            <div id="fullMap" class="full-map"
                 data-endpoint="<?= e(base_url('/api/destinations/map.php')) ?>"
                 data-center-lat="6.4333" data-center-lng="124.9167"></div>

            <p class="map-note">
                <i class="fa-solid fa-circle-info"></i>
                Map data &copy; OpenStreetMap contributors. Coordinates are maintained by the
                Municipal Tourism Office — report an inaccurate pin to
                <a href="<?= e(base_url('/#contact')) ?>">the office</a>.
            </p>

        <?php endif; ?>
    </div>
</section>
</main>

<?php require __DIR__ . '/app/views/partials/public-footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<!-- The shared behaviour script: navbar scroll state, back-to-top, reading
     progress. Its absence here was why the map page alone had no scroll
     effect on the navbar. -->
<script src="<?= e(asset('js/script.js')) ?>"></script>
<script>
(function () {
    const el = document.getElementById('fullMap');
    if (!el || typeof L === 'undefined') return;

    const map = L.map(el).setView(
        [parseFloat(el.dataset.centerLat), parseFloat(el.dataset.centerLng)], 12
    );

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 19
    }).addTo(map);

    /* One colour per category, so the map reads at a glance rather than
       needing a legend lookup for every pin. */
    const COLOURS = {
        'nature': '#2E7D32', 'waterfalls': '#0288D1', 'adventure': '#EF6C00',
        'culture': '#6A1B9A', 'eco-tourism': '#00796B', 'agri-tourism': '#827717',
        'historical': '#5D4037', 'other': '#455A64'
    };

    function pin(colour) {
        return L.divIcon({
            className: 'map-pin',
            html: '<span style="background:' + colour + '"></span>',
            iconSize: [26, 26],
            iconAnchor: [13, 26],
            popupAnchor: [0, -24]
        });
    }

    const markers = [];

    fetch(el.dataset.endpoint)
        .then((r) => r.ok ? r.json() : Promise.reject())
        .then((data) => {
            data.features.forEach((f) => {
                const p = f.properties;
                // GeoJSON is [lng, lat]; Leaflet wants [lat, lng].
                const coords = [f.geometry.coordinates[1], f.geometry.coordinates[0]];

                const stars = p.rating
                    ? '<span class="map-pop__rating">' +
                      '<i class="fa-solid fa-star"></i> ' + p.rating +
                      ' <small>(' + p.reviews + ')</small></span>'
                    : '';

                const marker = L.marker(coords, { icon: pin(COLOURS[p.category_slug] || COLOURS.other) })
                    .addTo(map)
                    .bindPopup(
                        '<div class="map-pop">' +
                          '<span class="map-pop__cat">' + p.category + '</span>' +
                          '<h3>' + p.name + '</h3>' + stars +
                          '<a class="map-pop__link" href="' + p.url + '">View details &rarr;</a>' +
                        '</div>'
                    );

                marker._category = p.category_slug;
                markers.push(marker);
            });

            // Frame every destination rather than trusting a hardcoded zoom.
            if (markers.length) {
                map.fitBounds(L.featureGroup(markers).getBounds(), { padding: [50, 50], maxZoom: 14 });
            }
        })
        .catch(() => {
            el.insertAdjacentHTML('beforeend',
                '<p class="map-error">The map data could not be loaded. Please refresh the page.</p>');
        });

    /* Category filter */
    document.querySelectorAll('[data-filter]').forEach((btn) => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('[data-filter]').forEach((b) => b.classList.remove('is-active'));
            btn.classList.add('is-active');

            const want = btn.dataset.filter;
            markers.forEach((m) => {
                const show = want === 'all' || m._category === want;
                if (show) { m.addTo(map); } else { map.removeLayer(m); }
            });
        });
    });

    /* Locate the visitor. Needs HTTPS in most browsers, so it fails politely
       rather than appearing broken during local testing over http. */
    const locate = document.getElementById('locateMe');
    if (locate) {
        locate.addEventListener('click', () => {
            if (!navigator.geolocation) {
                alert('Your browser does not support location services.');
                return;
            }
            locate.disabled = true;
            locate.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Locating…';

            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    const here = [pos.coords.latitude, pos.coords.longitude];
                    L.circleMarker(here, { radius: 8, color: '#0288D1', fillColor: '#0288D1', fillOpacity: .85 })
                        .addTo(map).bindPopup('You are here').openPopup();
                    map.setView(here, 14);
                    locate.disabled = false;
                    locate.innerHTML = '<i class="fa-solid fa-location-crosshairs"></i> Where am I?';
                },
                () => {
                    alert('Location is unavailable. Browsers only share it over a secure (https) connection.');
                    locate.disabled = false;
                    locate.innerHTML = '<i class="fa-solid fa-location-crosshairs"></i> Where am I?';
                }
            );
        });
    }
})();
</script>
</body>
</html>
