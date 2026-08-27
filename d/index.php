<?php
declare(strict_types=1);

/**
 * =============================================================================
 *  TourSync — what the QR sign opens                    Feature 1 / Problem 1
 * -----------------------------------------------------------------------------
 *  Reached by scanning the sign at a destination: /d/{token}
 *
 *  THIS IS NOT A LOGBOOK. The tourist writes their name in the paper book at
 *  the fill-up station, the way they always have. The code carries the three
 *  things they cannot get from the paper:
 *
 *      emergency hotlines   who to ring, from a waterfall, on one bar
 *      spot information     hours, fee, facilities, how to get out
 *      cultural heritage    what the place is, which is why they came
 *
 *  ORDER IS A SAFETY DECISION. Emergency comes first and is one tap from the
 *  top of the page. A visitor reading this is standing at the site; on the day
 *  it matters they are holding a phone with one hand and someone's arm with the
 *  other, and a hotline three scrolls down is a hotline they do not find.
 *  Everything else is below it.
 *
 *  Numbers are tel: links. Reading a number off a screen and typing it is two
 *  chances to get it wrong; tapping it is none.
 *
 *  The tourist never selects a destination. The token does it.
 * =============================================================================
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Session;
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

/* Scanning the sign is evidence of being at the site. Recorded in the session
   so the rating form below can accept a review from someone who was actually
   here — it is what the logbook submission used to prove, and the only thing
   the rating needed it for. No personal data, and it expires with the session.
 *
 * THE 'rated' FLAG SURVIVES A RE-SCAN. Writing a fresh array here would wipe it,
 * and a visitor who rates the place and then scans the sign again — which they
 * will, because the page is useful — would be handed a second blank rating form.
 * That is the same bug the arrival-backed path already carried a warning about:
 * clearing the proof makes the next request look new. */
$previous = Session::get('_scanned');

$alreadyRatedHere = is_array($previous)
    && (int) ($previous['destination_id'] ?? 0) === (int) $d['id']
    && !empty($previous['rated']);

Session::put('_scanned', [
    'destination_id' => (int) $d['id'],
    'name'           => (string) $d['name'],
    'at'             => time(),
    'rated'          => $alreadyRatedHere,
]);

$photos     = DestinationRepository::photos((int) $d['id']);
$facilities = DestinationRepository::decodeFacilities($d['facilities']);
$cover      = $photos[0]['file_path'] ?? null;

/**
 * The hotlines, municipal and local together, in the order someone in trouble
 * needs them. Blank ones are dropped rather than printed empty — a number that
 * is not there must not look like a number that is.
 */
$hotlines = [];

foreach ([
    ['key' => 'hotline_emergency', 'label' => 'Emergency (911)',      'icon' => 'fa-tower-broadcast'],
    ['key' => 'hotline_police',    'label' => 'Police',               'icon' => 'fa-shield-halved'],
    ['key' => 'hotline_medical',   'label' => 'Medical / Health Unit','icon' => 'fa-truck-medical'],
    ['key' => 'hotline_rescue',    'label' => 'Rescue / MDRRMO',      'icon' => 'fa-helmet-safety'],
    ['key' => 'hotline_fire',      'label' => 'Fire',                 'icon' => 'fa-fire-extinguisher'],
    ['key' => 'hotline_tourism',   'label' => 'Tourism Office',       'icon' => 'fa-circle-info'],
] as $line) {
    $number = trim((string) setting($line['key'], ''));

    if ($number !== '') {
        $hotlines[] = ['label' => $line['label'], 'number' => $number, 'icon' => $line['icon'], 'scope' => 'municipal'];
    }
}

/* The destination's own numbers come first in the list — the caretaker standing
   two hundred metres away is more use than a municipal line, and they can call
   the municipal line themselves. */
$local = [];

if (trim((string) ($d['local_hotline'] ?? '')) !== '') {
    $local[] = ['label' => 'On-site assistance', 'number' => trim((string) $d['local_hotline']),
                'icon' => 'fa-location-dot', 'scope' => 'local'];
}

if (trim((string) ($d['contact_phone'] ?? '')) !== '') {
    $local[] = ['label' => trim((string) ($d['contact_person'] ?? '')) !== ''
                    ? trim((string) $d['contact_person']) . ' (caretaker)'
                    : 'Destination caretaker',
                'number' => trim((string) $d['contact_phone']),
                'icon' => 'fa-user-shield', 'scope' => 'local'];
}

$hotlines = array_merge($local, $hotlines);

/** Strips a number down to what a phone can dial. */
$dialable = static fn (string $n): string => (string) preg_replace('/[^0-9+]/', '', $n);

$hasDirections = $d['latitude'] !== null && $d['longitude'] !== null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($d['name']) ?> — Tampakan Tourism</title>
<meta name="theme-color" content="#2E7D32">
<link rel="icon" href="<?= e(asset('img/tampakan_logo.png')) ?>" sizes="any">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/logbook.css')) ?>">
</head>
<body class="lb-body">

<!-- Official identification: a visitor should be able to confirm at a glance
     that they landed on the municipality's own page and not a copy. -->
<header class="lb-gov">
    <img src="<?= e(asset('img/tampakan_logo.png')) ?>" alt="Seal of the Municipality of Tampakan" width="34" height="34">
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

    <!-- ===================== 1. EMERGENCY ===================== -->
    <?php if ($hotlines !== []): ?>
        <section class="lb-card lb-emergency" id="emergency">
            <h2 class="lb-h2 lb-h2--urgent">
                <i class="fa-solid fa-phone-volume"></i> Emergency Call
            </h2>

            <p class="lb-muted lb-emergency__intro">
                Tap a number to call. Tell them you are at <strong><?= e($d['name']) ?></strong><?php
                    if ($d['barangay']): ?>, Barangay <?= e((string) $d['barangay']) ?><?php endif; ?>.
            </p>

            <ul class="lb-hotlines">
                <?php foreach ($hotlines as $line): ?>
                    <li class="lb-hotline <?= $line['scope'] === 'local' ? 'lb-hotline--local' : '' ?>">
                        <a href="tel:<?= e($dialable($line['number'])) ?>">
                            <span class="lb-hotline__icon"><i class="fa-solid <?= e($line['icon']) ?>"></i></span>
                            <span class="lb-hotline__text">
                                <strong><?= e($line['label']) ?></strong>
                                <span><?= e($line['number']) ?></span>
                            </span>
                            <span class="lb-hotline__call"><i class="fa-solid fa-phone"></i></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($hasDirections): ?>
                <!-- Coordinates, plainly readable. Rescue asks "where exactly are
                     you", and a visitor who cannot describe a trail can read
                     these two numbers down a phone. -->
                <p class="lb-coords">
                    <i class="fa-solid fa-location-crosshairs"></i>
                    Your location: <strong><?= e(number_format((float) $d['latitude'], 5)) ?>,
                    <?= e(number_format((float) $d['longitude'], 5)) ?></strong>
                </p>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="lb-card">
            <h2 class="lb-h2"><i class="fa-solid fa-phone-volume"></i> Emergency Call</h2>
            <p class="lb-muted">
                No hotline numbers have been published for this destination yet. In an emergency,
                dial <strong>911</strong>.
            </p>
        </section>
    <?php endif; ?>

    <?php if (trim((string) ($d['safety_notes'] ?? '')) !== ''): ?>
        <section class="lb-card lb-card--warn">
            <h2 class="lb-h2"><i class="fa-solid fa-triangle-exclamation"></i> Safety at this site</h2>
            <p><?= nl2br(e((string) $d['safety_notes'])) ?></p>
        </section>
    <?php endif; ?>

    <!-- ===================== 2. SPOT INFORMATION ===================== -->
    <section class="lb-card">
        <h2 class="lb-h2"><i class="fa-solid fa-circle-info"></i> Spot Information</h2>

        <?php if ($d['short_description']): ?>
            <p><?= e((string) $d['short_description']) ?></p>
        <?php endif; ?>

        <?php
        $details = array_filter([
            'Barangay'       => (string) ($d['barangay'] ?? ''),
            'Address'        => (string) ($d['address'] ?? ''),
            'Opening hours'  => (string) ($d['operating_hours'] ?? ''),
            'Entrance fee'   => (string) ($d['entrance_fee'] ?? ''),
        ], static fn (string $v): bool => trim($v) !== '');
        ?>

        <?php if ($details !== []): ?>
            <dl class="lb-facts">
                <?php foreach ($details as $label => $value): ?>
                    <div>
                        <dt><?= e($label) ?></dt>
                        <dd><?= e($value) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>

        <?php if ($facilities !== []): ?>
            <h3 class="lb-h3">Facilities</h3>
            <ul class="lb-tags">
                <?php foreach ($facilities as $facility): ?>
                    <li><?= e((string) $facility) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if (trim((string) ($d['reminders'] ?? '')) !== ''): ?>
            <h3 class="lb-h3">Please remember</h3>
            <p><?= nl2br(e((string) $d['reminders'])) ?></p>
        <?php endif; ?>

        <?php if ($details === [] && !$d['short_description'] && $facilities === []): ?>
            <p class="lb-muted">
                Details for this destination have not been published yet. Ask the caretaker on site,
                or contact the Municipal Tourism Office.
            </p>
        <?php endif; ?>
    </section>

    <!-- ===================== 3. CULTURAL HERITAGE ===================== -->
    <?php
    $heritage = trim((string) ($d['cultural_heritage'] ?? ''));
    $history  = trim((string) ($d['history'] ?? ''));
    ?>
    <?php if ($heritage !== '' || $history !== ''): ?>
        <section class="lb-card lb-card--heritage">
            <h2 class="lb-h2"><i class="fa-solid fa-landmark-dome"></i> Cultural Heritage</h2>

            <?php if ($heritage !== ''): ?>
                <p><?= nl2br(e($heritage)) ?></p>
            <?php endif; ?>

            <?php if ($history !== ''): ?>
                <h3 class="lb-h3">History</h3>
                <p><?= nl2br(e($history)) ?></p>
            <?php endif; ?>

            <p class="lb-muted lb-heritage__note">
                <i class="fa-solid fa-hands-holding-circle"></i>
                This site belongs to the people of Tampakan. Please treat it, and the community
                around it, with respect.
            </p>
        </section>
    <?php endif; ?>

    <!-- ===================== 3a. THIS PLACE, FILMED =====================
         Only this destination's videos. Somebody standing at Jadas Falls with
         the sign in front of them gets the Jadas clip and nothing else — a
         gallery of the whole municipality is a different errand.

         preload="none" is not a nicety here. This page is opened on mobile data
         at a trailhead, and a video that starts downloading itself has spent
         somebody's load before they chose to watch anything. -->
    <?php $qrVideos = App\Repositories\VideoRepository::published((int) $d['id'], 3); ?>
    <?php if ($qrVideos !== []): ?>
        <section class="lb-card">
            <h2 class="lb-h2"><i class="fa-solid fa-film"></i> Watch</h2>

            <?php foreach ($qrVideos as $qrVideo): ?>
                <?php $qrEmbed = $qrVideo['source'] === 'external'
                    ? App\Repositories\VideoRepository::embedUrl((string) $qrVideo['external_url'])
                    : null; ?>
                <div class="lb-video">
                    <?php if ($qrVideo['source'] === 'upload' && $qrVideo['file_path']): ?>
                        <video controls preload="none" playsinline
                               <?= $qrVideo['poster_path']
                                    ? 'poster="' . e(base_url('/' . $qrVideo['poster_path'])) . '"'
                                    : '' ?>>
                            <source src="<?= e(base_url('/' . $qrVideo['file_path'])) ?>"
                                    type="<?= e((string) ($qrVideo['mime_type'] ?: 'video/mp4')) ?>">
                        </video>
                    <?php elseif ($qrEmbed !== null): ?>
                        <iframe src="<?= e($qrEmbed) ?>" title="<?= e((string) $qrVideo['title']) ?>"
                                loading="lazy" allowfullscreen
                                referrerpolicy="strict-origin-when-cross-origin"></iframe>
                    <?php endif; ?>
                </div>
                <?php if ($qrVideo['caption']): ?>
                    <p class="lb-muted"><?= e((string) $qrVideo['caption']) ?></p>
                <?php endif; ?>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <!-- =============== 3b. THE MUNICIPALITY'S OWN HERITAGE ===============
         SHARED BY EVERY QR CODE, and separate from the block above on purpose.
         The section above is about this one place; this one is about Tampakan,
         and it reads identically at Bulol Falls and at Kolon Ridge because it
         is the same municipality either way.

         Two sections rather than one merged paragraph: a visitor should be able
         to tell which sentences are about the ground under their feet and which
         are about the town they drove through to get here. -->
    <?php
    $municipalHeritage = trim((string) (setting('municipal_heritage', '') ?? ''));
    $municipalTitle    = trim((string) (setting('municipal_heritage_title', '') ?? ''))
        ?: 'Local Culture & Heritage of Tampakan';
    ?>
    <?php if ($municipalHeritage !== ''): ?>
        <section class="lb-card lb-card--heritage">
            <h2 class="lb-h2"><i class="fa-solid fa-people-group"></i> <?= e($municipalTitle) ?></h2>
            <p><?= nl2br(e($municipalHeritage)) ?></p>
        </section>
    <?php endif; ?>

    <!-- ===================== GETTING AROUND =====================
         Somebody scanning this sign has already arrived, so directions here are
         for the journey OUT — back to town, or on to the next place. The
         printable sheet matters more than the maps-app link: they are standing
         at the point where signal usually fails, which is the last moment they
         can save anything at all. -->
    <?php $qrRoutes = App\Repositories\RouteRepository::forDestination((int) $d['id']); ?>
    <?php if ($hasDirections || $qrRoutes !== [] || $d['offline_map_image']): ?>
        <section class="lb-card">
            <h2 class="lb-h2"><i class="fa-solid fa-diamond-turn-right"></i> Directions</h2>
            <p class="lb-muted">Signal is weak here — save these before you move on.</p>

            <?php if ($qrRoutes !== []): ?>
                <a class="lb-btn lb-btn--primary"
                   href="<?= e(base_url('/directions.php?slug=' . urlencode((string) $d['slug']))) ?>">
                    <i class="fa-solid fa-file-arrow-down"></i> Printable directions sheet
                </a>
            <?php endif; ?>

            <?php if ($d['offline_map_image']): ?>
                <a class="lb-btn"
                   href="<?= e(base_url('/' . $d['offline_map_image'])) ?>"
                   download="<?= e((string) $d['slug']) ?>-map.jpg">
                    <i class="fa-solid fa-map"></i> Download the map
                </a>
            <?php endif; ?>

            <?php if ($hasDirections): ?>
                <a class="lb-btn"
                   href="https://www.google.com/maps/dir/?api=1&destination=<?= e((string) $d['latitude']) ?>,<?= e((string) $d['longitude']) ?>"
                   target="_blank" rel="noopener">
                    <i class="fa-solid fa-route"></i> Open in maps
                </a>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- ===================== ALSO NEARBY ===================== -->
    <?php $qrNearby = App\Repositories\RouteRepository::nearby((int) $d['id'], 3); ?>
    <?php if ($qrNearby !== []): ?>
        <section class="lb-card">
            <h2 class="lb-h2"><i class="fa-solid fa-location-crosshairs"></i> Also nearby</h2>
            <p class="lb-muted">Other places in Tampakan within reach of here.</p>
            <ul class="lb-nearby">
                <?php foreach ($qrNearby as $n): ?>
                    <li>
                        <a href="<?= e(base_url('/destination.php?slug=' . urlencode((string) $n['slug']))) ?>">
                            <strong><?= e((string) $n['name']) ?></strong>
                            <span><?= e(number_format((float) $n['distance_km'], 1)) ?> km away</span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <!-- ===================== A LOCAL GUIDE ===================== -->
    <!-- Placed on the QR page and not only on the website because this is the
         moment it is asked: somebody is standing at the trailhead deciding
         whether to go up alone. src=qr tells the Office they are already here,
         which is a different urgency from a request made a week out. -->
    <section class="lb-card">
        <h2 class="lb-h2"><i class="fa-solid fa-person-hiking"></i> Want a local guide?</h2>
        <p>
            The Municipal Tourism Office can arrange someone who knows
            <?= e((string) $d['name']) ?> &mdash; the trail, the history, and the safe way round.
        </p>
        <a class="lb-btn lb-btn--primary"
           href="<?= e(base_url('/tour-guide.php?src=qr&d=' . urlencode((string) $d['slug']))) ?>">
            <i class="fa-solid fa-paper-plane"></i> Request a tour guide
        </a>
        <p class="lb-muted">They text you the guide's name and number, usually the same day.</p>
    </section>

    <!-- ===================== THE PAPER LOGBOOK ===================== -->
    <!-- Said plainly, because the sign no longer asks them to type anything and
         a visitor who expected a form should know where to go instead. -->
    <section class="lb-card lb-card--note">
        <h2 class="lb-h2"><i class="fa-solid fa-book"></i> Please sign the logbook</h2>
        <p>
            There is a logbook at the entrance of this destination. Writing your name in it is what
            tells the Municipal Tourism Office how many people visit &mdash; and that keeps this place
            maintained and funded.
        </p>
        <p class="lb-muted">Nothing needs to be typed here.</p>
    </section>

    <!-- ===================== RATE THE VISIT ===================== -->
    <section class="lb-card">
        <h2 class="lb-h2"><i class="fa-regular fa-star"></i> How was your visit?</h2>
        <p class="lb-muted">Optional, and it goes straight to the Municipal Tourism Office.</p>

        <form method="post" action="<?= e(base_url('/api/feedback/submit.php')) ?>" id="rateForm">
            <?= csrf_field() ?>
            <input type="hidden" name="destination_id" value="<?= (int) $d['id'] ?>">
            <input type="hidden" name="rendered_at" value="<?= time() ?>">

            <!-- Honeypot: a real visitor never sees this, a bot fills it in. -->
            <div class="lb-hp" aria-hidden="true">
                <label for="website">Website</label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <?php
            /* Rendered 5 down to 1 so the CSS sibling selector can light the
               lower stars too; the row is reversed visually so it reads 1..5. */
            $starWords = [
                1 => 'Poor', 2 => 'Fair', 3 => 'Good', 4 => 'Very good', 5 => 'Excellent',
            ];
            ?>
            <div class="lb-stars" role="radiogroup" aria-label="Rating out of five">
                <?php for ($s = 5; $s >= 1; $s--): ?>
                    <input type="radio" id="star<?= $s ?>" name="rating" value="<?= $s ?>"
                           data-word="<?= e($starWords[$s]) ?>" required>
                    <label for="star<?= $s ?>">
                        <i class="fa-solid fa-star" aria-hidden="true"></i>
                        <span class="visually-hidden"><?= $s ?> out of 5 &mdash; <?= e($starWords[$s]) ?></span>
                    </label>
                <?php endfor; ?>
            </div>

            <!-- Says the choice back in words. Filled stars are ambiguous at a
                 glance, and this is the line that confirms what was tapped. -->
            <span class="lb-stars-readout" id="ratingReadout" aria-live="polite"></span>

            <div class="lb-field">
                <label for="comment">Anything you would like the Office to know? <span class="lb-optional">optional</span></label>
                <textarea id="comment" name="comment" rows="4" maxlength="800"
                          placeholder="e.g. the trail signage near the second bend has fallen over"></textarea>
                <p class="lb-hint">Up to 800 characters. Reviewed by the Office before it appears publicly.</p>
            </div>

            <button type="submit" class="lb-btn lb-btn--primary">
                <i class="fa-regular fa-paper-plane"></i> Send
            </button>
        </form>

        <script>
        /* Progressive enhancement only. The rating submits correctly with this
           script blocked — it is a radio group — so this adds the wording and
           nothing the form depends on. */
        (function () {
            var group    = document.querySelector('.lb-stars');
            var readout  = document.getElementById('ratingReadout');
            if (!group || !readout) { return; }

            group.addEventListener('change', function (e) {
                if (e.target.name !== 'rating') { return; }
                readout.textContent = e.target.value + ' out of 5 — ' + e.target.dataset.word;
            });
        })();
        </script>
    </section>

    <footer class="lb-foot">
        <a href="<?= e(destinations_url()) ?>">
            <i class="fa-solid fa-compass"></i> Browse other destinations in Tampakan
        </a>
        <p class="lb-muted">
            Municipal Tourism Office &middot; Municipality of Tampakan, South Cotabato
        </p>
    </footer>

</main>

</body>
</html>
