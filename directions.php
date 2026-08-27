<?php
declare(strict_types=1);

/**
 * TourSync — the directions sheet.                                   Feature 5
 *
 * WHAT THIS IS FOR
 *
 * The last kilometre into most of these destinations has no mobile signal. A
 * map that needs to fetch tiles is a blank grey square at exactly the junction
 * where the visitor needed it.
 *
 * So this page is deliberately plain: text directions, coordinates, hotlines
 * and the office's uploaded sketch map, laid out to be printed or saved as a
 * PDF from the browser and read on a phone in aeroplane mode. No tiles, no
 * scripts, no fonts to fetch. Everything on it works with the radio off.
 *
 * WHY THE HOTLINES ARE ON IT
 *
 * Somebody reading this sheet is, by definition, out of signal range and
 * heading somewhere remote. The one moment they most need a number is the one
 * moment they cannot load a page to look it up.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Repositories\DestinationRepository;
use App\Repositories\RouteRepository;

$slug = trim((string) ($_GET['slug'] ?? ''));
$d    = $slug !== '' ? DestinationRepository::findBySlug($slug) : null;

/* findBySlug() already refuses anything not active, so a null here covers a
   mistyped slug and an archived destination alike — both of which should look
   the same to a stranger. */
if ($d === null) {
    require __DIR__ . '/404.php';
    exit;
}

$routes = RouteRepository::forDestination((int) $d['id']);
$nearby = RouteRepository::nearby((int) $d['id'], 3);

/* Same keys and the same order of urgency as the QR page. Two lists that
   disagree about which number is the fire brigade is worse than one list. */
$hotlines = [];

foreach ([
    'hotline_emergency' => 'Emergency (911)',
    'hotline_police'    => 'Police',
    'hotline_medical'   => 'Medical / Health Unit',
    'hotline_rescue'    => 'Rescue / MDRRMO',
    'hotline_fire'      => 'Fire',
    'hotline_tourism'   => 'Tourism Office',
] as $key => $label) {
    $number = trim((string) (setting($key, '') ?? ''));

    if ($number !== '') {
        $hotlines[$label] = $number;
    }
}

/* The destination's own number last: it is the most specific and the least
   likely to be answered at 2am. */
if (trim((string) ($d['local_hotline'] ?? '')) !== '') {
    $hotlines['At ' . $d['name']] = trim((string) $d['local_hotline']);
}

$hasCoords = $d['latitude'] !== null && $d['longitude'] !== null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Directions to <?= e((string) $d['name']) ?> — Tampakan Tourism</title>
<meta name="description" content="Printable, offline directions to <?= e((string) $d['name']) ?> in Tampakan, South Cotabato.">
<link rel="icon" href="<?= e(asset('img/tampakan_logo.png')) ?>" sizes="any">

<?php /* EVERY STYLE IS INLINE AND EVERY FONT IS A SYSTEM FONT. A stylesheet
         fetched from a CDN is one more thing that is not there when this page
         is opened from a phone's cache on a mountain. */ ?>
<style>
    :root { --ink:#16211A; --muted:#5A6B60; --line:#D8E2DB; --forest:#123A1B; --gold:#B8801F; }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        padding: 1.5rem 1rem 3rem;
        font-family: Georgia, 'Times New Roman', serif;
        font-size: 16px;
        line-height: 1.65;
        color: var(--ink);
        background: #fff;
        max-width: 46rem;
        margin-inline: auto;
    }

    header { border-bottom: 3px double var(--forest); padding-bottom: 1rem; margin-bottom: 1.5rem; }
    .seal  { width: 62px; height: 62px; object-fit: contain; float: right; margin-left: 1rem; }
    .office { margin: 0; font-size: .78rem; letter-spacing: .12em; text-transform: uppercase; color: var(--forest); font-family: system-ui, sans-serif; }
    h1 { margin: .25rem 0 .3rem; font-size: 1.9rem; line-height: 1.15; color: var(--forest); }
    .where { margin: 0; color: var(--muted); font-size: .95rem; }

    h2 {
        font-size: 1.05rem; text-transform: uppercase; letter-spacing: .09em;
        color: var(--forest); border-bottom: 1px solid var(--line);
        padding-bottom: .35rem; margin: 2rem 0 .9rem;
        font-family: system-ui, sans-serif;
    }

    .route { margin-bottom: 1.6rem; page-break-inside: avoid; }
    .route__from { font-weight: 700; font-size: 1.05rem; margin: 0 0 .2rem; }
    .route__meta { margin: 0 0 .55rem; font-size: .85rem; color: var(--muted); font-family: system-ui, sans-serif; }
    .route__body { margin: 0; white-space: pre-line; }
    .route__fare { margin: .5rem 0 0; font-size: .85rem; color: var(--muted); font-family: system-ui, sans-serif; }

    table { border-collapse: collapse; width: 100%; font-family: system-ui, sans-serif; font-size: .92rem; }
    th, td { text-align: left; padding: .45rem .6rem; border-bottom: 1px solid var(--line); }
    th { width: 40%; font-weight: 600; color: var(--muted); }

    .hotlines td:last-child { font-weight: 700; font-size: 1.05rem; }

    .map { max-width: 100%; height: auto; border: 1px solid var(--line); border-radius: 4px; }

    .note {
        border-left: 4px solid var(--gold); background: #FDF8EE;
        padding: .8rem 1rem; margin: 1rem 0; font-size: .92rem;
        font-family: system-ui, sans-serif;
    }

    .empty { color: var(--muted); font-style: italic; }

    footer {
        margin-top: 2.5rem; padding-top: 1rem; border-top: 1px solid var(--line);
        font-size: .8rem; color: var(--muted); font-family: system-ui, sans-serif;
    }

    .actions { margin-bottom: 1.5rem; font-family: system-ui, sans-serif; }
    .actions a, .actions button {
        display: inline-block; padding: .55rem 1rem; margin: 0 .4rem .4rem 0;
        border: 1px solid var(--forest); border-radius: 8px;
        background: #fff; color: var(--forest); font: inherit; font-size: .9rem;
        text-decoration: none; cursor: pointer;
    }
    .actions a.primary, .actions button.primary { background: var(--forest); color: #fff; }

    /* On paper: drop the buttons and the back link, tighten the margins, and
       let the browser put the URL in the footer instead of a live link. */
    @media print {
        body { padding: 0; font-size: 12pt; max-width: none; }
        .actions, .no-print { display: none !important; }
        h2 { margin-top: 1.2rem; }
        a { text-decoration: none; color: inherit; }
    }
</style>
</head>
<body>

<div class="actions no-print">
    <button class="primary" onclick="window.print()">Print or save as PDF</button>
    <a href="<?= e(base_url('/destination.php?slug=' . urlencode((string) $d['slug']))) ?>">Back to <?= e((string) $d['name']) ?></a>
</div>

<header>
    <img class="seal" src="<?= e(asset('img/tampakan_logo.png')) ?>"
         alt="Official Seal of the Municipality of Tampakan" width="62" height="62">
    <p class="office">Municipal Tourism Office &middot; Tampakan, South Cotabato</p>
    <h1>How to get to <?= e((string) $d['name']) ?></h1>
    <p class="where">
        <?= e(trim((string) ($d['barangay'] ?? '')) !== '' ? 'Barangay ' . $d['barangay'] . ', ' : '') ?>Tampakan, South Cotabato
    </p>
</header>

<div class="note">
    <strong>Save this page before you set off.</strong>
    There is little or no mobile signal on the last stretch of most routes in Tampakan.
    Print it, or use your browser's <em>Print &rarr; Save as PDF</em> so it opens without a connection.
</div>

<h2>Directions</h2>

<?php if ($routes === []): ?>
    <?php /* Said plainly. A visitor who finds this page empty should know to
             phone rather than assume the road is obvious. */ ?>
    <p class="empty">
        Written directions for this destination have not been published yet.
        Please call the Municipal Tourism Office before travelling.
    </p>
<?php else: ?>
    <?php foreach ($routes as $r): ?>
        <div class="route">
            <p class="route__from">From <?= e((string) $r['from_landmark']) ?></p>
            <p class="route__meta">
                <?php
                $meta = array_filter([
                    (string) ($r['travel_time'] ?? ''),
                    (string) ($r['distance'] ?? ''),
                    (string) ($r['transport'] ?? ''),
                ], static fn(string $v): bool => trim($v) !== '');
                echo e(implode(' · ', $meta));
                ?>
            </p>
            <p class="route__body"><?= e((string) $r['directions']) ?></p>
            <?php if ($r['fare_note']): ?>
                <p class="route__fare">Fare: <?= e((string) $r['fare_note']) ?></p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($d['offline_map_image']): ?>
    <h2>Map</h2>
    <img class="map" src="<?= e(base_url('/' . $d['offline_map_image'])) ?>"
         alt="Map showing the route to <?= e((string) $d['name']) ?>">
<?php endif; ?>

<h2>The destination</h2>
<table>
    <?php if ($d['operating_hours']): ?>
        <tr><th>Open</th><td><?= e((string) $d['operating_hours']) ?></td></tr>
    <?php endif; ?>
    <?php if ($d['entrance_fee']): ?>
        <tr><th>Entrance</th><td><?= e((string) $d['entrance_fee']) ?></td></tr>
    <?php endif; ?>
    <?php if ($d['address']): ?>
        <tr><th>Address</th><td><?= e((string) $d['address']) ?></td></tr>
    <?php endif; ?>
    <?php if ($hasCoords): ?>
        <?php /* Written out as plain numbers so they can be typed into any
                 offline GPS app, or read aloud over a radio. */ ?>
        <tr>
            <th>Coordinates</th>
            <td><?= e((string) $d['latitude']) ?>, <?= e((string) $d['longitude']) ?></td>
        </tr>
    <?php endif; ?>
    <?php if ($d['contact_person']): ?>
        <tr><th>Contact</th><td><?= e((string) $d['contact_person']) ?></td></tr>
    <?php endif; ?>
</table>

<?php if ($d['safety_notes'] || $d['reminders']): ?>
    <h2>Before you go</h2>
    <?php if ($d['reminders']): ?><p><?= nl2br(e((string) $d['reminders'])) ?></p><?php endif; ?>
    <?php if ($d['safety_notes']): ?><p><?= nl2br(e((string) $d['safety_notes'])) ?></p><?php endif; ?>
<?php endif; ?>

<?php if ($hotlines !== []): ?>
    <h2>Emergency numbers</h2>
    <table class="hotlines">
        <?php foreach ($hotlines as $label => $number): ?>
            <tr><th><?= e((string) $label) ?></th><td><?= e($number) ?></td></tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<?php if ($nearby !== []): ?>
    <h2>Also nearby</h2>
    <table>
        <?php foreach ($nearby as $n): ?>
            <tr>
                <th><?= e((string) $n['name']) ?></th>
                <td><?= e(number_format((float) $n['distance_km'], 1)) ?> km away<?php
                    if ($n['barangay']): ?> &middot; Brgy. <?= e((string) $n['barangay']) ?><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<footer>
    Municipal Tourism Office of Tampakan, South Cotabato.
    Printed from <?= e(base_url('/directions.php?slug=' . $d['slug'])) ?> on <?= e(date('j F Y')) ?>.
    Road conditions change &mdash; check with the Office before travelling in the rainy season.
</footer>

</body>
</html>
