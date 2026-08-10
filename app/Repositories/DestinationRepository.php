<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Uploader;

/**
 * All destination queries live here. No page file writes SQL.
 *
 * This table is the single source of truth the brief's Problem 4 calls for:
 * the public listing, the detail page, the map marker, and the QR target all
 * read the same row.
 */
final class DestinationRepository
{
    /** Columns a caller may sort by. Anything else is ignored — never interpolated. */
    private const SORTABLE = [
        'name'       => 'd.name',
        'created_at' => 'd.created_at',
        'category'   => 'c.name',
        'visitors'   => 'visitors',
    ];

    // -------------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------------

    /**
     * Admin listing with search, filters, and arrival counts.
     */
    public static function paginate(array $filters = [], int $page = 1, int $perPage = 12): array
    {
        [$where, $params] = self::buildWhere($filters);

        $sortKey   = $filters['sort'] ?? 'created_at';
        $sortCol   = self::SORTABLE[$sortKey] ?? self::SORTABLE['created_at'];
        $direction = strtolower((string) ($filters['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM destinations d
             LEFT JOIN categories c ON c.id = d.category_id
             {$where}",
            $params
        );

        $perPage = max(1, min($perPage, 100));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));
        $offset  = ($page - 1) * $perPage;

        $rows = Database::all(
            "SELECT d.*,
                    c.name AS category_name,
                    c.icon AS category_icon,
                    (SELECT file_path FROM destination_photos p
                      WHERE p.destination_id = d.id
                      ORDER BY p.is_cover DESC, p.sort_order ASC, p.id ASC LIMIT 1) AS cover_photo,
                    (SELECT COUNT(*) FROM destination_photos p WHERE p.destination_id = d.id) AS photo_count,
                    COALESCE((SELECT SUM(a.total_visitors) FROM tourist_arrivals a
                               WHERE a.destination_id = d.id AND a.status = 'valid'), 0) AS visitors
               FROM destinations d
               LEFT JOIN categories c ON c.id = d.category_id
               {$where}
              ORDER BY {$sortCol} {$direction}
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'rows'    => $rows,
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'perPage' => $perPage,
        ];
    }

    private static function buildWhere(array $filters): array
    {
        $clauses = [];
        $params  = [];

        // Default to active only; the admin list passes status explicitly.
        $status = $filters['status'] ?? 'active';
        if ($status !== 'all') {
            $clauses[] = 'd.status = ?';
            $params[]  = $status;
        }

        if (!empty($filters['search'])) {
            $clauses[] = '(d.name LIKE ? OR d.barangay LIKE ? OR d.short_description LIKE ?)';
            $term = '%' . $filters['search'] . '%';
            array_push($params, $term, $term, $term);
        }

        if (!empty($filters['category_id'])) {
            $clauses[] = 'd.category_id = ?';
            $params[]  = (int) $filters['category_id'];
        }

        if (!empty($filters['featured'])) {
            $clauses[] = 'd.is_featured = 1';
        }

        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }

    public static function find(int $id): ?array
    {
        return Database::first(
            "SELECT d.*, c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon
               FROM destinations d
               LEFT JOIN categories c ON c.id = d.category_id
              WHERE d.id = ?",
            [$id]
        );
    }

    public static function findBySlug(string $slug, bool $activeOnly = true): ?array
    {
        $sql = "SELECT d.*, c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon
                  FROM destinations d
                  LEFT JOIN categories c ON c.id = d.category_id
                 WHERE d.slug = ?";
        if ($activeOnly) {
            $sql .= " AND d.status = 'active'";
        }
        return Database::first($sql . ' LIMIT 1', [$slug]);
    }

    /** Resolves a scanned QR code. The token is the only public identifier. */
    public static function findByQrToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;    // reject malformed tokens before touching the database
        }

        return Database::first(
            "SELECT d.*, c.name AS category_name, c.icon AS category_icon
               FROM destinations d
               LEFT JOIN categories c ON c.id = d.category_id
              WHERE d.qr_token = ? AND d.status = 'active'
              LIMIT 1",
            [$token]
        );
    }

    /** Public listing — active only, cover photo and rating included. */
    public static function published(array $filters = [], ?int $limit = null): array
    {
        $filters['status'] = 'active';
        [$where, $params] = self::buildWhere($filters);

        $sql = "SELECT d.*,
                       c.name AS category_name,
                       c.slug AS category_slug,
                       c.icon AS category_icon,
                       (SELECT file_path FROM destination_photos p
                         WHERE p.destination_id = d.id
                         ORDER BY p.is_cover DESC, p.sort_order ASC, p.id ASC LIMIT 1) AS cover_photo,
                       COALESCE((SELECT ROUND(AVG(f.rating), 1) FROM feedback f
                                  WHERE f.destination_id = d.id AND f.status = 'published'), 0) AS avg_rating,
                       COALESCE((SELECT COUNT(*) FROM feedback f
                                  WHERE f.destination_id = d.id AND f.status = 'published'), 0) AS review_count
                  FROM destinations d
                  LEFT JOIN categories c ON c.id = d.category_id
                  {$where}
                 ORDER BY d.is_featured DESC, d.name ASC";

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, min($limit, 60));
        }

        return Database::all($sql, $params);
    }

    /** Markers for the Leaflet map. Coordinates only — no contact details. */
    public static function mapMarkers(): array
    {
        return Database::all(
            "SELECT d.id, d.name, d.slug, d.latitude, d.longitude,
                    c.name AS category_name, c.slug AS category_slug
               FROM destinations d
               LEFT JOIN categories c ON c.id = d.category_id
              WHERE d.status = 'active'
                AND d.latitude IS NOT NULL
                AND d.longitude IS NOT NULL
              ORDER BY d.name"
        );
    }

    public static function photos(int $destinationId): array
    {
        return Database::all(
            'SELECT * FROM destination_photos WHERE destination_id = ?
              ORDER BY is_cover DESC, sort_order ASC, id ASC',
            [$destinationId]
        );
    }

    public static function countActive(): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM destinations WHERE status = 'active'");
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    /**
     * A destination cannot exist without a QR token — the column is NOT NULL
     * UNIQUE — so one is minted at creation even though rendering the code
     * itself is Phase 2 work.
     */
    public static function create(array $data, ?int $adminId): int
    {
        return Database::insert(
            "INSERT INTO destinations
                (category_id, name, slug, short_description, description, history,
                 operating_hours, entrance_fee, facilities, reminders, barangay, address,
                 latitude, longitude, contact_person, contact_phone, contact_email,
                 qr_token, is_featured, status, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $data['category_id'] ?: null,
                $data['name'],
                self::uniqueSlug($data['name']),
                $data['short_description'] ?: null,
                $data['description'] ?: null,
                $data['history'] ?: null,
                $data['operating_hours'] ?: null,
                $data['entrance_fee'] ?: null,
                self::encodeFacilities($data['facilities'] ?? ''),
                $data['reminders'] ?: null,
                $data['barangay'] ?: null,
                $data['address'] ?: null,
                $data['latitude'] !== '' ? $data['latitude'] : null,
                $data['longitude'] !== '' ? $data['longitude'] : null,
                $data['contact_person'] ?: null,
                $data['contact_phone'] ?: null,
                $data['contact_email'] ?: null,
                self::newToken(),
                !empty($data['is_featured']) ? 1 : 0,
                'active',
                $adminId,
            ]
        );
    }

    public static function update(int $id, array $data): void
    {
        Database::run(
            "UPDATE destinations SET
                category_id = ?, name = ?, short_description = ?, description = ?, history = ?,
                operating_hours = ?, entrance_fee = ?, facilities = ?, reminders = ?,
                barangay = ?, address = ?, latitude = ?, longitude = ?,
                contact_person = ?, contact_phone = ?, contact_email = ?, is_featured = ?
             WHERE id = ?",
            [
                $data['category_id'] ?: null,
                $data['name'],
                $data['short_description'] ?: null,
                $data['description'] ?: null,
                $data['history'] ?: null,
                $data['operating_hours'] ?: null,
                $data['entrance_fee'] ?: null,
                self::encodeFacilities($data['facilities'] ?? ''),
                $data['reminders'] ?: null,
                $data['barangay'] ?: null,
                $data['address'] ?: null,
                $data['latitude'] !== '' ? $data['latitude'] : null,
                $data['longitude'] !== '' ? $data['longitude'] : null,
                $data['contact_person'] ?: null,
                $data['contact_phone'] ?: null,
                $data['contact_email'] ?: null,
                !empty($data['is_featured']) ? 1 : 0,
                $id,
            ]
        );
    }

    /**
     * Archive, never delete. Deleting would cascade into arrival statistics
     * the Municipality is required to keep; the foreign key is RESTRICT and
     * would refuse anyway.
     */
    public static function setStatus(int $id, string $status): void
    {
        Database::run('UPDATE destinations SET status = ? WHERE id = ?', [$status, $id]);
    }

    public static function addPhoto(int $destinationId, string $path, ?string $caption = null): int
    {
        $isFirst = (int) Database::scalar(
            'SELECT COUNT(*) FROM destination_photos WHERE destination_id = ?',
            [$destinationId]
        ) === 0;

        return Database::insert(
            'INSERT INTO destination_photos (destination_id, file_path, caption, is_cover, sort_order)
             VALUES (?, ?, ?, ?, ?)',
            [$destinationId, $path, $caption, $isFirst ? 1 : 0, 0]
        );
    }

    public static function deletePhoto(int $photoId, int $destinationId): void
    {
        $photo = Database::first(
            'SELECT * FROM destination_photos WHERE id = ? AND destination_id = ?',
            [$photoId, $destinationId]
        );

        if ($photo === null) {
            return;
        }

        Database::run('DELETE FROM destination_photos WHERE id = ?', [$photoId]);
        Uploader::delete($photo['file_path']);

        // If the cover was removed, promote whatever is now first.
        if ((int) $photo['is_cover'] === 1) {
            $next = Database::first(
                'SELECT id FROM destination_photos WHERE destination_id = ? ORDER BY sort_order, id LIMIT 1',
                [$destinationId]
            );
            if ($next !== null) {
                Database::run('UPDATE destination_photos SET is_cover = 1 WHERE id = ?', [$next['id']]);
            }
        }
    }

    public static function setCover(int $photoId, int $destinationId): void
    {
        Database::run('UPDATE destination_photos SET is_cover = 0 WHERE destination_id = ?', [$destinationId]);
        Database::run('UPDATE destination_photos SET is_cover = 1 WHERE id = ? AND destination_id = ?',
            [$photoId, $destinationId]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public static function newToken(): string
    {
        do {
            $token = bin2hex(random_bytes(16));   // 32 hex characters
            $taken = Database::scalar('SELECT 1 FROM destinations WHERE qr_token = ?', [$token]);
        } while ($taken !== null);

        return $token;
    }

    /** Appends -2, -3 … until the slug is free. */
    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = str_slug($name);
        $slug = $base;
        $suffix = 2;

        while (true) {
            $sql = 'SELECT 1 FROM destinations WHERE slug = ?';
            $params = [$slug];

            if ($ignoreId !== null) {
                $sql .= ' AND id <> ?';
                $params[] = $ignoreId;
            }

            if (Database::scalar($sql, $params) === null) {
                return $slug;
            }

            $slug = $base . '-' . $suffix++;
        }
    }

    /** Facilities arrive as a comma-separated field and are stored as JSON. */
    private static function encodeFacilities($facilities): ?string
    {
        if (is_array($facilities)) {
            $list = $facilities;
        } else {
            $list = array_filter(array_map('trim', explode(',', (string) $facilities)));
        }

        $list = array_values(array_unique($list));

        return $list === [] ? null : json_encode($list, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Turns a stored facilities value back into an array.
     *
     * Accepts both forms on purpose. The database holds JSON, but when a form
     * is redisplayed after a validation failure the value is the raw comma
     * string the officer typed — JSON-decoding that returns nothing, and the
     * field would silently come back empty, losing their input.
     */
    public static function decodeFacilities(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
