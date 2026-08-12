<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Visitor ratings and comments.
 *
 * Every review here can be tied to a specific logged visit, which makes it
 * unusual: the reviewer demonstrably stood at the destination. That is worth
 * protecting, so a review submitted without a matching arrival is accepted but
 * marked as unverified rather than silently treated the same.
 *
 * Moderation policy, stated once and enforced everywhere below:
 *   Hide abuse and spam. Never hide a review merely for being negative.
 * A government office suppressing criticism is a legitimacy problem, not a
 * moderation one — and a five-star-only page tells a visitor nothing.
 */
final class FeedbackRepository
{
    public static function create(array $data): int
    {
        return Database::insert(
            'INSERT INTO feedback
                (destination_id, arrival_id, visitor_name, rating, comment, status, device_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $data['destination_id'],
                $data['arrival_id'] ?: null,
                $data['visitor_name'] ?: null,
                $data['rating'],
                $data['comment'] ?: null,
                // Everything waits for a human. An unmoderated comment box on a
                // municipal website is a liability, not a feature.
                'pending',
                $data['device_hash'] ?? null,
            ]
        );
    }

    /** Has this visit already been rated? Stops a refresh creating a second review. */
    public static function existsForArrival(int $arrivalId): bool
    {
        return Database::scalar('SELECT 1 FROM feedback WHERE arrival_id = ?', [$arrivalId]) !== null;
    }

    /**
     * Has this device already rated this destination recently?
     *
     * The database backstop for a scan-backed review. Since Feature 1 removed
     * the digital logbook there is no arrival row to key on, and a session flag
     * alone is not enough — clearing cookies, or opening the sign in a private
     * tab, makes the next submission look new.
     *
     * device_hash is already stored on every review, so this checks something
     * the system holds rather than collecting anything further. The window is
     * days rather than forever: a visitor who genuinely returns next season is
     * entitled to say what they thought the second time.
     */
    public static function existsForDevice(int $destinationId, string $deviceHash, int $days = 30): bool
    {
        if ($deviceHash === '') {
            return false;
        }

        return Database::scalar(
            'SELECT 1 FROM feedback
              WHERE destination_id = ?
                AND device_hash = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              LIMIT 1',
            [$destinationId, $deviceHash, max(1, min($days, 365))]
        ) !== null;
    }

    public static function find(int $id): ?array
    {
        return Database::first(
            'SELECT f.*, d.name AS destination_name, d.slug AS destination_slug,
                    a.visit_date, a.tourist_type
               FROM feedback f
               JOIN destinations d ON d.id = f.destination_id
               LEFT JOIN tourist_arrivals a ON a.id = f.arrival_id
              WHERE f.id = ?',
            [$id]
        );
    }

    /** Published reviews for one destination. */
    public static function publishedFor(int $destinationId, int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));

        return Database::all(
            "SELECT f.*, a.visit_date, a.origin_city, a.origin_province, a.origin_country
               FROM feedback f
               LEFT JOIN tourist_arrivals a ON a.id = f.arrival_id
              WHERE f.destination_id = ? AND f.status = 'published'
              ORDER BY f.created_at DESC
              LIMIT {$limit}",
            [$destinationId]
        );
    }

    /** Best recent reviews across all destinations, for the homepage. */
    public static function featured(int $limit = 6): array
    {
        $limit = max(1, min($limit, 20));

        return Database::all(
            "SELECT f.*, d.name AS destination_name, d.slug AS destination_slug,
                    a.origin_city, a.origin_province, a.origin_country
               FROM feedback f
               JOIN destinations d ON d.id = f.destination_id
               LEFT JOIN tourist_arrivals a ON a.id = f.arrival_id
              WHERE f.status = 'published'
                AND f.comment IS NOT NULL AND f.comment <> ''
                AND f.rating >= 4
              ORDER BY f.created_at DESC
              LIMIT {$limit}"
        );
    }

    /** Average rating and count for one destination. */
    public static function summaryFor(int $destinationId): array
    {
        $row = Database::first(
            "SELECT ROUND(AVG(rating), 1) AS average, COUNT(*) AS total
               FROM feedback WHERE destination_id = ? AND status = 'published'",
            [$destinationId]
        );

        return [
            'average' => (float) ($row['average'] ?? 0),
            'total'   => (int) ($row['total'] ?? 0),
        ];
    }

    /** Star distribution, so the admin can see shape rather than just a mean. */
    public static function distribution(?int $destinationId = null): array
    {
        $sql = "SELECT rating, COUNT(*) AS total FROM feedback WHERE status = 'published'";
        $params = [];

        if ($destinationId !== null) {
            $sql .= ' AND destination_id = ?';
            $params[] = $destinationId;
        }

        $sql .= ' GROUP BY rating';

        $out = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        foreach (Database::all($sql, $params) as $row) {
            $out[(int) $row['rating']] = (int) $row['total'];
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Admin
    // -------------------------------------------------------------------------

    public static function paginate(array $filters, int $page = 1, int $perPage = 20): array
    {
        $clauses = [];
        $params  = [];

        if (!empty($filters['status']) && in_array($filters['status'], ['pending', 'published', 'hidden'], true)) {
            $clauses[] = 'f.status = ?';
            $params[]  = $filters['status'];
        }

        if (!empty($filters['destination_id'])) {
            $clauses[] = 'f.destination_id = ?';
            $params[]  = (int) $filters['destination_id'];
        }

        if (!empty($filters['rating'])) {
            $clauses[] = 'f.rating = ?';
            $params[]  = (int) $filters['rating'];
        }

        // "Needs attention" — low ratings the office should read first.
        if (!empty($filters['low_only'])) {
            $clauses[] = 'f.rating <= 2';
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

        $total   = (int) Database::scalar("SELECT COUNT(*) FROM feedback f {$where}", $params);
        $perPage = max(1, min($perPage, 100));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));
        $offset  = ($page - 1) * $perPage;

        $rows = Database::all(
            "SELECT f.*, d.name AS destination_name,
                    a.visit_date, a.origin_city, a.origin_province, a.origin_country,
                    m.full_name AS moderator_name
               FROM feedback f
               JOIN destinations d ON d.id = f.destination_id
               LEFT JOIN tourist_arrivals a ON a.id = f.arrival_id
               LEFT JOIN admins m ON m.id = f.moderated_by
               {$where}
              ORDER BY f.created_at DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return compact('rows', 'total', 'page', 'pages', 'perPage');
    }

    public static function moderate(int $id, string $status, int $adminId): void
    {
        Database::run(
            'UPDATE feedback SET status = ?, moderated_by = ?, moderated_at = NOW() WHERE id = ?',
            [$status, $adminId, $id]
        );
    }

    public static function countPending(): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM feedback WHERE status = 'pending'");
    }

    /** Counts per status, for the moderation queue tabs. */
    public static function statusCounts(): array
    {
        $out = ['pending' => 0, 'published' => 0, 'hidden' => 0];

        foreach (Database::all('SELECT status, COUNT(*) AS total FROM feedback GROUP BY status') as $row) {
            $out[$row['status']] = (int) $row['total'];
        }

        return $out;
    }
}
