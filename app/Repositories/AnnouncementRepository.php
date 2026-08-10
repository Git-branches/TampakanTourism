<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Announcements, advisories, schedules, events, and closures.
 *
 * One table and one composer serve both the public website and the SMS blast
 * to destination managers; the `audience` column decides which. Splitting them
 * into separate features would mean an officer writing the same closure notice
 * twice and the two copies drifting apart — which is the communication problem
 * the brief describes, reproduced inside the system meant to solve it.
 */
final class AnnouncementRepository
{
    public const TYPES = [
        'announcement' => 'General Announcement',
        'advisory'     => 'Tourism Advisory',
        'schedule'     => 'Report Submission Schedule',
        'event'        => 'Tourism Event',
        'closure'      => 'Destination Closure',
        'reminder'     => 'Reminder',
    ];

    /** Icon and tone per type, so an advisory never looks like an invitation. */
    public const TYPE_STYLE = [
        'announcement' => ['icon' => 'fa-bullhorn',              'tone' => 'blue'],
        'advisory'     => ['icon' => 'fa-triangle-exclamation',  'tone' => 'amber'],
        'schedule'     => ['icon' => 'fa-calendar-check',        'tone' => 'teal'],
        'event'        => ['icon' => 'fa-calendar-star',         'tone' => 'green'],
        'closure'      => ['icon' => 'fa-circle-xmark',          'tone' => 'red'],
        'reminder'     => ['icon' => 'fa-bell',                  'tone' => 'blue'],
    ];

    public const AUDIENCES = [
        'public'   => 'Public website only',
        'managers' => 'Destination managers only (SMS)',
        'both'     => 'Public website and destination managers',
    ];

    // -------------------------------------------------------------------------
    // Public reads
    // -------------------------------------------------------------------------

    /**
     * What the website shows.
     *
     * Published, past its publish time, not expired, and addressed to an
     * audience that includes the public. A scheduled post must not appear
     * early, and an expired advisory must stop appearing without anyone
     * remembering to take it down.
     */
    public static function publicFeed(?string $type = null, int $limit = 50): array
    {
        $sql = "SELECT a.*, d.name AS destination_name, d.slug AS destination_slug
                  FROM announcements a
                  LEFT JOIN destinations d ON d.id = a.destination_id
                 WHERE a.status = 'published'
                   AND a.audience IN ('public', 'both')
                   AND (a.publish_at IS NULL OR a.publish_at <= NOW())
                   AND (a.expires_at IS NULL OR a.expires_at >= NOW())";
        $params = [];

        if ($type !== null && isset(self::TYPES[$type])) {
            $sql .= ' AND a.type = ?';
            $params[] = $type;
        }

        $limit = max(1, min($limit, 100));
        $sql .= " ORDER BY COALESCE(a.publish_at, a.created_at) DESC LIMIT {$limit}";

        return Database::all($sql, $params);
    }

    /** Upcoming events for the homepage — future dates only, soonest first. */
    public static function upcomingEvents(int $limit = 3): array
    {
        $limit = max(1, min($limit, 20));

        return Database::all(
            "SELECT a.*, d.name AS destination_name
               FROM announcements a
               LEFT JOIN destinations d ON d.id = a.destination_id
              WHERE a.status = 'published'
                AND a.audience IN ('public', 'both')
                AND a.type = 'event'
                AND a.event_date IS NOT NULL
                AND a.event_date >= CURDATE()
              ORDER BY a.event_date ASC
              LIMIT {$limit}"
        );
    }

    /** Latest news and advisories for the homepage, excluding events. */
    public static function latestNews(int $limit = 3): array
    {
        $limit = max(1, min($limit, 20));

        return Database::all(
            "SELECT a.*, d.name AS destination_name
               FROM announcements a
               LEFT JOIN destinations d ON d.id = a.destination_id
              WHERE a.status = 'published'
                AND a.audience IN ('public', 'both')
                AND a.type <> 'event'
                AND (a.publish_at IS NULL OR a.publish_at <= NOW())
                AND (a.expires_at IS NULL OR a.expires_at >= NOW())
              ORDER BY COALESCE(a.publish_at, a.created_at) DESC
              LIMIT {$limit}"
        );
    }

    public static function findBySlug(string $slug): ?array
    {
        return Database::first(
            "SELECT a.*, d.name AS destination_name, d.slug AS destination_slug
               FROM announcements a
               LEFT JOIN destinations d ON d.id = a.destination_id
              WHERE a.slug = ?
                AND a.status = 'published'
                AND a.audience IN ('public', 'both')
                AND (a.publish_at IS NULL OR a.publish_at <= NOW())
              LIMIT 1",
            [$slug]
        );
    }

    // -------------------------------------------------------------------------
    // Admin
    // -------------------------------------------------------------------------

    public static function find(int $id): ?array
    {
        return Database::first(
            'SELECT a.*, d.name AS destination_name, ad.full_name AS author_name
               FROM announcements a
               LEFT JOIN destinations d ON d.id = a.destination_id
               LEFT JOIN admins ad ON ad.id = a.created_by
              WHERE a.id = ?',
            [$id]
        );
    }

    public static function paginate(array $filters, int $page = 1, int $perPage = 15): array
    {
        $clauses = [];
        $params  = [];

        if (!empty($filters['status'])) {
            $clauses[] = 'a.status = ?';
            $params[]  = $filters['status'];
        }
        if (!empty($filters['type'])) {
            $clauses[] = 'a.type = ?';
            $params[]  = $filters['type'];
        }
        if (!empty($filters['search'])) {
            $clauses[] = '(a.title LIKE ? OR a.body LIKE ?)';
            $term = '%' . $filters['search'] . '%';
            array_push($params, $term, $term);
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

        $total   = (int) Database::scalar("SELECT COUNT(*) FROM announcements a {$where}", $params);
        $perPage = max(1, min($perPage, 100));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));
        $offset  = ($page - 1) * $perPage;

        $rows = Database::all(
            "SELECT a.*, d.name AS destination_name, ad.full_name AS author_name,
                    (SELECT COUNT(*) FROM notifications n WHERE n.announcement_id = a.id) AS notified,
                    (SELECT COUNT(*) FROM notifications n WHERE n.announcement_id = a.id AND n.status = 'sent') AS delivered
               FROM announcements a
               LEFT JOIN destinations d ON d.id = a.destination_id
               LEFT JOIN admins ad ON ad.id = a.created_by
               {$where}
              ORDER BY a.created_at DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return compact('rows', 'total', 'page', 'pages', 'perPage');
    }

    public static function create(array $data, ?int $adminId): int
    {
        return Database::insert(
            'INSERT INTO announcements
                (title, slug, body, summary, type, audience, status, destination_id,
                 event_date, event_location, publish_at, expires_at, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $data['title'],
                self::uniqueSlug($data['title']),
                $data['body'],
                $data['summary'] ?: null,
                $data['type'],
                $data['audience'],
                $data['status'],
                $data['destination_id'] ?: null,
                $data['event_date'] ?: null,
                $data['event_location'] ?: null,
                $data['publish_at'] ?: null,
                $data['expires_at'] ?: null,
                $adminId,
            ]
        );
    }

    public static function update(int $id, array $data): void
    {
        Database::run(
            'UPDATE announcements
                SET title = ?, body = ?, summary = ?, type = ?, audience = ?, status = ?,
                    destination_id = ?, event_date = ?, event_location = ?,
                    publish_at = ?, expires_at = ?
              WHERE id = ?',
            [
                $data['title'],
                $data['body'],
                $data['summary'] ?: null,
                $data['type'],
                $data['audience'],
                $data['status'],
                $data['destination_id'] ?: null,
                $data['event_date'] ?: null,
                $data['event_location'] ?: null,
                $data['publish_at'] ?: null,
                $data['expires_at'] ?: null,
                $id,
            ]
        );
    }

    public static function setStatus(int $id, string $status): void
    {
        Database::run('UPDATE announcements SET status = ? WHERE id = ?', [$status, $id]);
    }

    public static function statusCounts(): array
    {
        $out = ['draft' => 0, 'published' => 0, 'archived' => 0];

        foreach (Database::all('SELECT status, COUNT(*) AS total FROM announcements GROUP BY status') as $row) {
            $out[$row['status']] = (int) $row['total'];
        }

        return $out;
    }

    private static function uniqueSlug(string $title): string
    {
        $base = str_slug($title);
        $slug = $base;
        $n    = 2;

        while (Database::scalar('SELECT 1 FROM announcements WHERE slug = ?', [$slug]) !== null) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }
}
