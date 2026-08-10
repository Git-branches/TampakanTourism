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
