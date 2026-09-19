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
    /**
     * TWO VOCABULARIES, NOT ONE LIST WITH AN ODD MEMBER IN IT.
     *
     * "Tourism Event" used to sit among the notice types, so a festival was
     * both a notice and an event and the public homepage showed it twice —
     * once under Latest News and again under Upcoming Events. The visitor read
     * the same thing in two places and neither section answered its own
     * question.
     *
     * NEWS answers "what do I need to know": advisories, closures, schedules,
     * reminders. EVENTS answers "what can I go to": something with a date you
     * can turn up for.
     *
     * The split is by these two lists and nothing else — one table, one status
     * workflow, one SMS path, one public detail page. A type belongs to exactly
     * one of them, which is what stops a record appearing in both sections.
     */
    public const NEWS_TYPES = [
        'announcement' => 'General Announcement',
        'advisory'     => 'Tourism Advisory',
        'schedule'     => 'Report Submission Schedule',
        'closure'      => 'Destination Closure',
        'reminder'     => 'Reminder',
    ];

    public const EVENT_TYPES = [
        'event'     => 'Tourism Event',
        'festival'  => 'Festival',
        'community' => 'Community Event',
        'municipal' => 'Municipal Activity',
        'activity'  => 'Other Upcoming Activity',
    ];

    /**
     * Both, for the places that legitimately need every label: the filter on
     * the admin list, the label beside a row, the public detail page. Kept as
     * TYPES so nothing that already reads it had to change.
     */
    public const TYPES = self::NEWS_TYPES + self::EVENT_TYPES;

    /** Is this type something a visitor can attend? */
    public static function isEventType(string $type): bool
    {
        return isset(self::EVENT_TYPES[$type]);
    }

    /** The SQL fragment for "an event", used by both public queries. */
    private static function eventTypeList(): string
    {
        return "'" . implode("','", array_keys(self::EVENT_TYPES)) . "'";
    }

    /** Icon and tone per type, so an advisory never looks like an invitation. */
    public const TYPE_STYLE = [
        'announcement' => ['icon' => 'fa-bullhorn',              'tone' => 'blue'],
        'advisory'     => ['icon' => 'fa-triangle-exclamation',  'tone' => 'amber'],
        'schedule'     => ['icon' => 'fa-calendar-check',        'tone' => 'teal'],
        'closure'      => ['icon' => 'fa-circle-xmark',          'tone' => 'red'],
        'reminder'     => ['icon' => 'fa-bell',                  'tone' => 'blue'],

        'event'        => ['icon' => 'fa-calendar-day',          'tone' => 'green'],
        'festival'     => ['icon' => 'fa-masks-theater',         'tone' => 'green'],
        'community'    => ['icon' => 'fa-people-group',          'tone' => 'green'],
        'municipal'    => ['icon' => 'fa-landmark',              'tone' => 'green'],
        'activity'     => ['icon' => 'fa-flag',                  'tone' => 'green'],
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

    /**
     * Upcoming events for the homepage — future dates only, soonest first.
     *
     * Two dates are at work here and they are not the same date. event_date is
     * when the festival happens; publish_at is when the municipality is willing
     * to say so. This query filtered on the first and ignored the second, so an
     * event written up in advance and scheduled for release appeared on the
     * homepage the moment it was saved — while publicFeed(), which does check,
     * correctly kept it hidden. The same record was public in one section and
     * embargoed in another, which made publish_at meaningless for events.
     *
     * The expiry check comes along for the ride, for the same reason: a notice
     * the office has dated out of circulation should leave every section at
     * once, not just the ones that remembered to ask.
     */
    public static function upcomingEvents(int $limit = 3): array
    {
        $limit = max(1, min($limit, 20));

        return Database::all(
            "SELECT a.*, d.name AS destination_name
               FROM announcements a
               LEFT JOIN destinations d ON d.id = a.destination_id
              WHERE a.status = 'published'
                AND a.audience IN ('public', 'both')
                AND a.type IN (" . self::eventTypeList() . ")
                AND a.event_date IS NOT NULL
                AND a.event_date >= CURDATE()
                AND (a.publish_at IS NULL OR a.publish_at <= NOW())
                AND (a.expires_at IS NULL OR a.expires_at >= NOW())
              ORDER BY a.event_date ASC
              LIMIT {$limit}"
        );
    }

    /**
     * Latest news and advisories for the homepage — never an event.
     *
     * The homepage news section used to call publicFeed(), which returns
     * everything published, so every festival appeared under Latest News AND
     * under Upcoming Events. A visitor scrolling the page read the same fiesta
     * twice and neither section answered its own question.
     *
     * The cap is 100 because that section is a filterable catalogue, not a
     * teaser of three.
     */
    public static function latestNews(int $limit = 3): array
    {
        $limit = max(1, min($limit, 100));

        return Database::all(
            "SELECT a.*, d.name AS destination_name
               FROM announcements a
               LEFT JOIN destinations d ON d.id = a.destination_id
              WHERE a.status = 'published'
                AND a.audience IN ('public', 'both')
                AND a.type NOT IN (" . self::eventTypeList() . ")
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

        /* WHICH SECTION THIS LIST IS. News and Events are the same table read
           through two doors, so each door narrows to its own vocabulary before
           any type filter inside it applies. Without this the News list would
           show festivals and the split would exist only on the public site. */
        if (!empty($filters['types']) && is_array($filters['types'])) {
            $clauses[] = 'a.type IN (' . implode(',', array_fill(0, count($filters['types']), '?')) . ')';
            array_push($params, ...array_values($filters['types']));
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

    /**
     * A copy, as a draft, for the office to edit into next year's notice.
     *
     * The festival happens every year and the closure notice is the same words
     * with a different date; retyping either is how two versions of the same
     * announcement end up disagreeing. Everything is carried over EXCEPT the
     * things that must not be:
     *
     *   status      — always draft. A copy that published itself the moment it
     *                 was made would put last year's date on the website.
     *   publish_at  — a scheduled time that has already passed would publish it
     *                 instantly, which is the same fault by another route.
     *   slug        — its own, because the public URL identifies one notice.
     *   notifications — not copied. The delivery board belongs to the message
     *                 that was actually sent, and cascade removes it anyway.
     *
     * The banner_path IS shared, deliberately: it is the same picture, and
     * bannerInUse() already stops either copy deleting the other's file.
     */
    public static function duplicate(int $id, ?int $adminId): ?int
    {
        $a = Database::first('SELECT * FROM announcements WHERE id = ?', [$id]);

        if ($a === null) {
            return null;
        }

        $title = mb_substr($a['title'] . ' (copy)', 0, 200);

        $copy = Database::insert(
            'INSERT INTO announcements
                (title, slug, body, summary, type, audience, status, destination_id,
                 event_date, event_location, banner_path, expires_at, created_by)
             VALUES (?,?,?,?,?,?,"draft",?,?,?,?,?,?)',
            [
                $title,
                self::uniqueSlug($title),
                $a['body'],
                $a['summary'],
                $a['type'],
                $a['audience'],
                $a['destination_id'],
                $a['event_date'],
                $a['event_location'],
                $a['banner_path'],
                $a['expires_at'],
                $adminId,
            ]
        );

        /* The gallery comes with it, sharing paths the way the banner does —
           bannerInUse() looks in the gallery table too, so neither copy can
           delete a file the other still shows. */
        foreach (self::photos($id) as $photo) {
            Database::run(
                'INSERT INTO announcement_photos (announcement_id, file_path, sort_order)
                 SELECT ?, file_path, sort_order FROM announcement_photos WHERE id = ?',
                [$copy, $photo['id']]
            );
        }

        return $copy;
    }

    /**
     * Gone for good.
     *
     * `notifications` cascades, so the delivery board goes with it — the record
     * of who was texted about this notice and whether it arrived. That is not a
     * side effect worth discovering afterwards, so the screen that offers this
     * says so before asking.
     *
     * The picture is removed only if nothing else is using it: the banners
     * directory is shared with the homepage hero slides and with any copy made
     * by duplicate().
     */
    public static function delete(int $id): void
    {
        $banner = trim((string) (Database::scalar(
            'SELECT banner_path FROM announcements WHERE id = ?', [$id]) ?? ''));

        /* Read before the row goes: the gallery rows cascade with it, and after
           that there is nothing left that says which files were this event's. */
        $gallery = array_column(self::photos($id), 'file_path');

        Database::run('DELETE FROM announcements WHERE id = ?', [$id]);

        foreach (array_unique(array_merge($banner !== '' ? [$banner] : [], $gallery)) as $path) {
            if (!self::bannerInUse((string) $path)) {
                \App\Core\Uploader::delete((string) $path);
            }
        }
    }

    /**
     * The picture behind the card, replacing whatever was there.
     *
     * A method of its own rather than another column on create() and update():
     * an upload can fail on its own — a file too large, a format GD will not
     * decode — and it must not take a perfectly good edit to the words down
     * with it. The announcement is saved first, then the picture is attached.
     * That is how the heritage items work, for the same reason.
     *
     * The file it replaces is deleted, unless something else is using it. The
     * banners directory is shared with the homepage hero slides, so an image
     * an officer used for both would otherwise disappear from one of them.
     */
    public static function setBanner(int $id, string $path): void
    {
        $previous = trim((string) (Database::scalar(
            'SELECT banner_path FROM announcements WHERE id = ?', [$id]) ?? ''));

        Database::run('UPDATE announcements SET banner_path = ? WHERE id = ?', [$path, $id]);

        if ($previous !== '' && $previous !== $path && !self::bannerInUse($previous)) {
            \App\Core\Uploader::delete($previous);
        }
    }

    /** Back to the stock photograph the public pages fall back to. */
    public static function clearBanner(int $id): void
    {
        $previous = trim((string) (Database::scalar(
            'SELECT banner_path FROM announcements WHERE id = ?', [$id]) ?? ''));

        Database::run('UPDATE announcements SET banner_path = NULL WHERE id = ?', [$id]);

        if ($previous !== '' && !self::bannerInUse($previous)) {
            \App\Core\Uploader::delete($previous);
        }
    }

    /**
     * Is this file still referenced by an announcement, an event's gallery, or
     * a hero slide? A duplicated event shares its photographs' paths with the
     * original, so a file is only removed when the last reference to it goes.
     */
    private static function bannerInUse(string $path): bool
    {
        if (Database::scalar('SELECT 1 FROM announcements WHERE banner_path = ? LIMIT 1', [$path]) !== null) {
            return true;
        }

        if (Database::scalar('SELECT 1 FROM announcement_photos WHERE file_path = ? LIMIT 1', [$path]) !== null) {
            return true;
        }

        return Database::scalar('SELECT 1 FROM hero_slides WHERE image_path = ? LIMIT 1', [$path]) !== null;
    }

    // -------------------------------------------------------------------------
    // An event's photographs
    // -------------------------------------------------------------------------

    /**
     * How many photographs one event may hold, the featured one included.
     * Generous for a festival, and a ceiling so a runaway upload cannot fill
     * the disk one event at a time.
     */
    public const MAX_PHOTOS = 30;

    /** The photographs after the featured one, in the order they were added. */
    public static function photos(int $id): array
    {
        return Database::all(
            'SELECT id, file_path FROM announcement_photos
              WHERE announcement_id = ?
              ORDER BY sort_order, id',
            [$id]
        );
    }

    /**
     * Every photograph of an event, featured first — what the public gallery
     * draws. An event with only banner_path (every event made before galleries
     * existed) returns that one picture, so it renders exactly as it did.
     *
     * @return string[] web-relative paths
     */
    public static function gallery(array $a): array
    {
        $paths  = [];
        $banner = trim((string) ($a['banner_path'] ?? ''));

        if ($banner !== '') {
            $paths[] = $banner;
        }

        foreach (self::photos((int) $a['id']) as $photo) {
            $paths[] = (string) $photo['file_path'];
        }

        return $paths;
    }

    public static function photoCount(int $id): int
    {
        $banner = trim((string) (Database::scalar('SELECT banner_path FROM announcements WHERE id = ?', [$id]) ?? ''));

        return ($banner !== '' ? 1 : 0)
            + (int) Database::scalar('SELECT COUNT(*) FROM announcement_photos WHERE announcement_id = ?', [$id]);
    }

    /**
     * Adds stored photographs to an event. The first becomes the featured
     * picture when the event has none, so an event created with six photos has
     * a card picture without anyone having to choose one.
     */
    public static function addPhotos(int $id, array $paths): void
    {
        $paths = array_values(array_filter($paths, static fn ($p): bool => is_string($p) && $p !== ''));

        if ($paths === []) {
            return;
        }

        $banner = trim((string) (Database::scalar('SELECT banner_path FROM announcements WHERE id = ?', [$id]) ?? ''));

        if ($banner === '') {
            Database::run('UPDATE announcements SET banner_path = ? WHERE id = ?', [array_shift($paths), $id]);
        }

        $next = (int) Database::scalar(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM announcement_photos WHERE announcement_id = ?', [$id]
        );

        foreach ($paths as $path) {
            Database::run(
                'INSERT INTO announcement_photos (announcement_id, file_path, sort_order) VALUES (?, ?, ?)',
                [$id, $path, $next++]
            );
        }
    }

    /**
     * Removes some of an event's gallery photographs, by id. Scoped to the
     * event in the WHERE clause, so an id belonging to another event — from an
     * edited form — removes nothing.
     */
    public static function removePhotos(int $id, array $photoIds): int
    {
        $photoIds = array_values(array_unique(array_filter(array_map('intval', $photoIds))));

        if ($photoIds === []) {
            return 0;
        }

        $marks = implode(',', array_fill(0, count($photoIds), '?'));
        $rows  = Database::all(
            "SELECT id, file_path FROM announcement_photos WHERE announcement_id = ? AND id IN ({$marks})",
            array_merge([$id], $photoIds)
        );

        foreach ($rows as $row) {
            Database::run('DELETE FROM announcement_photos WHERE id = ?', [$row['id']]);

            if (!self::bannerInUse((string) $row['file_path'])) {
                \App\Core\Uploader::delete((string) $row['file_path']);
            }
        }

        return count($rows);
    }

    /**
     * Makes one of the gallery photographs the featured one. The two swap
     * places: the chosen picture moves to banner_path, and the previous featured
     * picture joins the gallery where the chosen one was — nothing is lost by
     * changing which picture leads.
     */
    public static function makeFeatured(int $id, int $photoId): bool
    {
        $photo = Database::first(
            'SELECT id, file_path FROM announcement_photos WHERE id = ? AND announcement_id = ?',
            [$photoId, $id]
        );

        if ($photo === null) {
            return false;
        }

        $banner = trim((string) (Database::scalar('SELECT banner_path FROM announcements WHERE id = ?', [$id]) ?? ''));

        Database::transaction(static function () use ($id, $photo, $banner): void {
            Database::run('UPDATE announcements SET banner_path = ? WHERE id = ?', [$photo['file_path'], $id]);

            if ($banner !== '') {
                Database::run('UPDATE announcement_photos SET file_path = ? WHERE id = ?', [$banner, $photo['id']]);
            } else {
                Database::run('DELETE FROM announcement_photos WHERE id = ?', [$photo['id']]);
            }
        });

        return true;
    }

    /**
     * Removes the featured picture of an event and promotes the next one in
     * the gallery, so an event with photographs never falls back to the stock
     * picture just because its lead photo was taken down.
     */
    public static function removeFeatured(int $id): void
    {
        self::clearBanner($id);

        $next = Database::first(
            'SELECT id, file_path FROM announcement_photos WHERE announcement_id = ? ORDER BY sort_order, id LIMIT 1',
            [$id]
        );

        if ($next !== null) {
            Database::transaction(static function () use ($id, $next): void {
                Database::run('UPDATE announcements SET banner_path = ? WHERE id = ?', [$next['file_path'], $id]);
                Database::run('DELETE FROM announcement_photos WHERE id = ?', [$next['id']]);
            });
        }
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
