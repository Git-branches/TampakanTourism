<?php
declare(strict_types=1);

/**
 * TourSync — destinations as GeoJSON for the public map.
 *
 * Public, so it returns only what a map needs. Contact person, phone, and
 * email are deliberately absent: those belong to named site staff, and a
 * public JSON feed is exactly how such details end up scraped.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Repositories\DestinationRepository;
use App\Repositories\FeedbackRepository;

$features = [];

foreach (DestinationRepository::mapMarkers() as $d) {
    $summary = FeedbackRepository::summaryFor((int) $d['id']);

    $features[] = [
        'type' => 'Feature',
        'geometry' => [
            'type'        => 'Point',
            // GeoJSON orders coordinates longitude first. Reversing them is
            // the classic mistake — it silently places Tampakan in Somalia.
            'coordinates' => [(float) $d['longitude'], (float) $d['latitude']],
        ],
        'properties' => [
            'id'       => (int) $d['id'],
            'name'     => $d['name'],
            'slug'     => $d['slug'],
            'category' => $d['category_name'] ?: 'Destination',
            'category_slug' => $d['category_slug'] ?: 'other',
            'url'      => base_url('/destination.php?slug=' . $d['slug']),
            'rating'   => $summary['average'] > 0 ? $summary['average'] : null,
            'reviews'  => $summary['total'],

            /* The picture the marker wears, and the two lines its popup shows.
               null rather than a placeholder path: the map decides what to draw
               when a destination has no photograph, and a made-up URL here
               would be a broken image on every one of them. */
            'photo'    => $d['cover_photo'] ? base_url((string) $d['cover_photo']) : null,
            'barangay' => (string) ($d['barangay'] ?? ''),
            'excerpt'  => mb_strimwidth((string) ($d['short_description'] ?? ''), 0, 120, '…'),
        ],
    ];
}

// Cached briefly: destinations change a few times a year, but the map is
// loaded by every visitor who opens it.
header('Cache-Control: public, max-age=300');

json_response([
    'type'     => 'FeatureCollection',
    'features' => $features,
]);
