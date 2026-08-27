<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * TourSync — how to get there.                                       Feature 5
 *
 * Text directions written from landmarks a person can actually find, plus the
 * nearby-attraction lookup that turns one destination into an afternoon.
 *
 * WHY THIS IS NOT A ROUTING ENGINE
 *
 * The last kilometre of most of these routes is a barangay road that no map
 * provider has surveyed. A routing API confidently draws a line down a track
 * that washes out in the wet season, and a visitor who follows it ends up
 * somewhere a rescue would struggle to reach. The office knows the real route;
 * this stores what they know, in the words they use.
 */
final class RouteRepository
{
    /**
     * Every route into a destination, in the order the office arranged them.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forDestination(int $destinationId): array
    {
        return Database::all(
            'SELECT * FROM destination_routes
              WHERE destination_id = ?
              ORDER BY sort_order, id',
            [$destinationId]
        );
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM destination_routes WHERE id = ?', [$id]);
    }

    /** @param array<string, mixed> $data */
    public static function create(int $destinationId, array $data): int
    {
        return Database::insert(
            'INSERT INTO destination_routes
                (destination_id, from_landmark, directions, travel_time, distance, transport, fare_note, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            self::bind($destinationId, $data)
        );
    }

    /** @param array<string, mixed> $data */
    public static function update(int $id, int $destinationId, array $data): void
    {
        /* bind() leads with destination_id for the INSERT; an UPDATE does not
           set that column, so it is dropped from the front and reappears in the
           WHERE instead. Keeping it in the WHERE is what stops a route id
           belonging to another destination from being edited by posting it. */
        $fields = array_slice(self::bind($destinationId, $data), 1);

        Database::run(
            'UPDATE destination_routes
                SET from_landmark = ?, directions = ?, travel_time = ?,
                    distance = ?, transport = ?, fare_note = ?, sort_order = ?
              WHERE id = ? AND destination_id = ?',
            [...$fields, $id, $destinationId]
        );
    }

    /**
     * Trims and caps everything to the column widths.
     *
     * @param array<string, mixed> $data
     * @return array<int, mixed>
     */
    private static function bind(int $destinationId, array $data): array
    {
        return [
            $destinationId,
            mb_substr(trim((string) ($data['from_landmark'] ?? '')), 0, 160),
            mb_substr(trim((string) ($data['directions'] ?? '')), 0, 5000),
            self::nullable($data['travel_time'] ?? '', 60),
            self::nullable($data['distance'] ?? '', 60),
            self::nullable($data['transport'] ?? '', 160),
            self::nullable($data['fare_note'] ?? '', 160),
            max(0, min(999, (int) ($data['sort_order'] ?? 0))),
        ];
    }

    private static function nullable(mixed $value, int $max): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $max);
    }

    public static function delete(int $id, int $destinationId): void
    {
        Database::run(
            'DELETE FROM destination_routes WHERE id = ? AND destination_id = ?',
            [$id, $destinationId]
        );
    }

    /**
     * Other destinations worth the trip while you are out here.
     *
     * Distance is computed in SQL with the haversine formula rather than by
     * loading every destination and sorting in PHP. There are three
     * destinations today and there will be thirty eventually; neither is a lot,
     * but the query is the same length either way.
     *
     * RETURNS AN EMPTY LIST WHEN THE ORIGIN HAS NO COORDINATES. A "nearby"
     * section computed from a missing latitude would list places in an
     * arbitrary order and present it as proximity.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function nearby(int $destinationId, int $limit = 4, float $withinKm = 60.0): array
    {
        $origin = Database::first(
            'SELECT latitude, longitude FROM destinations WHERE id = ?',
            [$destinationId]
        );

        if ($origin === null || $origin['latitude'] === null || $origin['longitude'] === null) {
            return [];
        }

        return Database::all(
            "SELECT d.id, d.name, d.slug, d.short_description, d.barangay,
                    d.latitude, d.longitude, c.name AS category_name, c.icon AS category_icon,

                    /* The same subquery published() uses, so a nearby row and a
                       same-category row are the same shape. They are merged into
                       one list on the destination page, and a caller should not
                       have to know which query a row came from to read it. */
                    (SELECT file_path FROM destination_photos p
                      WHERE p.destination_id = d.id
                      ORDER BY p.is_cover DESC, p.sort_order ASC, p.id ASC LIMIT 1) AS cover_photo,

                    (6371 * ACOS(
                        LEAST(1.0,
                            COS(RADIANS(?)) * COS(RADIANS(d.latitude))
                          * COS(RADIANS(d.longitude) - RADIANS(?))
                          + SIN(RADIANS(?)) * SIN(RADIANS(d.latitude))
                        )
                    )) AS distance_km
               FROM destinations d
               LEFT JOIN categories c ON c.id = d.category_id
              WHERE d.id <> ?
                AND d.status = 'active'
                AND d.latitude IS NOT NULL
                AND d.longitude IS NOT NULL
             HAVING distance_km <= ?
              ORDER BY distance_km
              LIMIT " . max(1, min(20, $limit)),
            [
                $origin['latitude'],
                $origin['longitude'],
                $origin['latitude'],
                $destinationId,
                $withinKm,
            ]
        );
    }

    /**
     * How many destinations still have no directions written for them.
     *
     * Surfaced to the officer because an empty directions section is invisible
     * from the public page — it simply does not render, and nobody notices the
     * gap until a visitor phones to ask where the turning is.
     */
    public static function destinationsWithoutRoutes(): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM destinations d
              WHERE d.status = 'active'
                AND NOT EXISTS (SELECT 1 FROM destination_routes r WHERE r.destination_id = d.id)"
        );
    }
}
