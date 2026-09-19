<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * TourSync — ratings left for an accredited tour guide.
 *
 * The office accredits guides, issues each an ID, and until now had no way to
 * hear how a visitor found one. This is that way: the Tour Guide category of
 * the homepage Contact Us modal.
 *
 * SEPARATE FROM FeedbackRepository, which is about a place. A destination
 * review is tied to an arrival or a QR scan — proof the reviewer stood there.
 * A guide review is about a named person and has no location to prove.
 *
 * MODERATION POLICY, the same sentence as feedback because it is the same
 * principle:
 *     Hide abuse and spam. Never hide a review merely for being negative.
 * With one addition that feedback does not need. These reviews name a private
 * individual who holds a municipal accreditation, so nothing here is published
 * by submitting it — an officer publishes it or it stays unseen.
 */
final class GuideReviewRepository
{
    public const STATUSES = [
        'pending'   => 'Awaiting review',
        'published' => 'Published',
        'hidden'    => 'Hidden',
    ];

    /** @param array<string, mixed> $data */
    public static function create(array $data): int
    {
        return Database::insert(
            'INSERT INTO guide_reviews
                (guide_id, visitor_name, visitor_email, rating, comment, status, device_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $data['guide_id'],
                self::clip($data['visitor_name'] ?? '', 120),
                self::clip($data['visitor_email'] ?? '', 190),
                max(1, min(5, (int) $data['rating'])),
                self::clip($data['comment'] ?? '', 1000),
                /* Never anything but pending. Passing a status in would make the
                   one guarantee this table offers an argument the caller
                   chooses, and the caller here is a public endpoint. */
                'pending',
                $data['device_hash'] ?? null,
            ]
        );
    }

    /**
     * Has this device rated this guide lately?
     *
     * The backstop behind the rate limiter. There is no arrival row to key on
     * the way a destination review has, and without this one person can leave
     * the same guide five reviews from five tabs.
     *
     * Days rather than forever: somebody who genuinely books the same guide
     * again next season is entitled to say what they thought the second time.
     */
    public static function existsForDevice(int $guideId, string $deviceHash, int $days = 30): bool
    {
        if ($deviceHash === '') {
            return false;
        }

        return Database::scalar(
            'SELECT 1 FROM guide_reviews
              WHERE guide_id = ?
                AND device_hash = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              LIMIT 1',
            [$guideId, $deviceHash, max(1, min($days, 365))]
        ) !== null;
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::first(
            'SELECT r.*, g.full_name AS guide_name, g.guide_code,
                    a.full_name AS moderated_by_name
               FROM guide_reviews r
               JOIN tour_guides g ON g.id = r.guide_id
               LEFT JOIN admins a ON a.id = r.moderated_by
              WHERE r.id = ?',
            [$id]
        );
    }

    /**
     * The officer's list.
     *
     * Pending first, then newest — an officer opens this screen to deal with
     * what is waiting, not to browse what they have already handled.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function inbox(array $filters = [], int $limit = 100): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['status']) && isset(self::STATUSES[$filters['status']])) {
            $where[]  = 'r.status = ?';
            $params[] = $filters['status'];
        }

        if ((int) ($filters['guide_id'] ?? 0) > 0) {
            $where[]  = 'r.guide_id = ?';
            $params[] = (int) $filters['guide_id'];
        }

        if (trim((string) ($filters['search'] ?? '')) !== '') {
            $term     = '%' . trim((string) $filters['search']) . '%';
            $where[]  = '(g.full_name LIKE ? OR r.visitor_name LIKE ?)';
            $params[] = $term;
            $params[] = $term;
        }

        $sql = 'SELECT r.*, g.full_name AS guide_name, g.guide_code,
                       a.full_name AS moderated_by_name
                  FROM guide_reviews r
                  JOIN tour_guides g ON g.id = r.guide_id
                  LEFT JOIN admins a ON a.id = r.moderated_by';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= " ORDER BY FIELD(r.status, 'pending', 'published', 'hidden'), r.created_at DESC
                  LIMIT " . max(1, min(500, $limit));

        return Database::all($sql, $params);
    }

    /** @return array<string, int> */
    public static function statusCounts(): array
    {
        $out = array_fill_keys(array_keys(self::STATUSES), 0);

        foreach (Database::all('SELECT status, COUNT(*) c FROM guide_reviews GROUP BY status') as $row) {
            $out[(string) $row['status']] = (int) $row['c'];
        }

        return $out;
    }

    public static function countPending(): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM guide_reviews WHERE status = 'pending'");
    }

    public static function moderate(int $id, string $status, int $adminId): bool
    {
        if (!isset(self::STATUSES[$status])) {
            return false;
        }

        Database::run(
            'UPDATE guide_reviews
                SET status = ?, moderated_by = ?, moderated_at = NOW()
              WHERE id = ?',
            [$status, $adminId, $id]
        );

        return true;
    }

    /**
     * Published reviews for one guide, and the average across them.
     *
     * Nothing public reads this yet — the About and guide pages do not show
     * ratings, and that is the office's call to make rather than a developer's.
     * It is here because the admin screen shows an officer what a guide's
     * published record looks like before they publish one more.
     *
     * @return array{count:int, average:float}
     */
    public static function summaryFor(int $guideId): array
    {
        $row = Database::first(
            "SELECT COUNT(*) c, AVG(rating) a
               FROM guide_reviews
              WHERE guide_id = ? AND status = 'published'",
            [$guideId]
        );

        return [
            'count'   => (int) ($row['c'] ?? 0),
            'average' => round((float) ($row['a'] ?? 0), 2),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function publishedFor(int $guideId, int $limit = 20): array
    {
        return Database::all(
            "SELECT visitor_name, rating, comment, created_at
               FROM guide_reviews
              WHERE guide_id = ? AND status = 'published'
              ORDER BY created_at DESC
              LIMIT " . max(1, min(100, $limit)),
            [$guideId]
        );
    }

    /**
     * Trim to the column's width, or NULL when there is nothing left.
     *
     * mb_substr rather than substr: these carry ñ and the odd accented name,
     * and cutting a multi-byte character in half stores a broken sequence that
     * MySQL rejects — a save that fails for a reason nobody can see.
     */
    private static function clip(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
