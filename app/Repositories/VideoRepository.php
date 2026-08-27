<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * TourSync — promotional videos.                                     Feature 8
 *
 * The office uploads a clip about Jadas Falls, or pastes a YouTube link for
 * something longer, and it appears on that destination's page.
 *
 * ONE HOME PER VIDEO. A clip belongs to the place it is about: its destination
 * page and that destination's QR page, and nowhere else. There is no site-wide
 * gallery and nothing plays behind the homepage — both existed briefly and both
 * put a video somewhere other than the place it was filmed.
 *
 * The columns is_hero and is_featured are left in the table from those two
 * removed features. NOTHING READS THEM. They are not dropped because dropping a
 * column cannot be undone, but a future reader should not wire them back up
 * expecting them to mean anything.
 */
final class VideoRepository
{
    public const STATUSES = [
        'draft'     => 'Draft',
        'published' => 'Published',
    ];

    /**
     * What kind of clip this is, in the words a reader needs.
     *
     * The labels are headings on the public page, not internal jargon — a
     * visitor scanning a destination page should be able to tell the office's
     * own film from something a tourist sent in without being told how the
     * database is arranged.
     */
    public const CATEGORIES = [
        'promo'   => 'Promotional video',
        'event'   => 'Events & festivals',
        'archive' => 'Previous tourism videos',
        'visitor' => 'Visitor experiences',
    ];

    /** Plural headings for the grouped lists under the featured clip. */
    public const CATEGORY_HEADINGS = [
        'promo'   => 'More about this destination',
        'event'   => 'Events & festivals',
        'archive' => 'From previous years',
        'visitor' => 'Visitor experiences',
    ];

    /**
     * Turns a pasted page URL into something an iframe can load.
     *
     * Returns null for anything it does not recognise, and the caller treats
     * that as "not a video link" rather than embedding an unknown host — an
     * iframe pointing wherever a form field said is a hole, not a feature.
     *
     * YouTube and Facebook only. They are what a municipal office actually
     * uses, and each additional provider is another URL shape to keep working.
     */
    public static function embedUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        /* youtu.be/ID, youtube.com/watch?v=ID, /embed/ID, /shorts/ID */
        if (str_contains($host, 'youtube.com') || str_contains($host, 'youtu.be')) {
            $id = null;

            if (str_contains($host, 'youtu.be')) {
                $id = trim((string) parse_url($url, PHP_URL_PATH), '/');
            } else {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $id = (string) ($query['v'] ?? '');

                if ($id === '' && preg_match('#/(embed|shorts|v)/([A-Za-z0-9_-]{6,})#', $url, $m)) {
                    $id = $m[2];
                }
            }

            /* A YouTube id is 11 characters of a known alphabet. Anything else
               is not an id, and building an embed URL out of it would put
               whatever it is into the src of an iframe. */
            return (is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/', $id))
                ? 'https://www.youtube-nocookie.com/embed/' . $id
                : null;
        }

        if (str_contains($host, 'facebook.com') || str_contains($host, 'fb.watch')) {
            return 'https://www.facebook.com/plugins/video.php?href=' . rawurlencode($url) . '&show_text=false';
        }

        return null;
    }

    /** True when the office pasted something this system can actually show. */
    public static function isEmbeddable(?string $url): bool
    {
        return self::embedUrl($url) !== null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        return Database::insert(
            'INSERT INTO promo_videos
                (title, caption, destination_id, category, source, file_path, mime_type, file_size,
                 external_url, poster_path, status, sort_order, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                mb_substr(trim((string) $data['title']), 0, 160),
                self::nullable($data['caption'] ?? '', 600),
                !empty($data['destination_id']) ? (int) $data['destination_id'] : null,
                isset(self::CATEGORIES[$data['category'] ?? '']) ? $data['category'] : 'promo',
                ($data['source'] ?? 'upload') === 'external' ? 'external' : 'upload',
                self::nullable($data['file_path'] ?? '', 255),
                self::nullable($data['mime_type'] ?? '', 60),
                !empty($data['file_size']) ? (int) $data['file_size'] : null,
                self::nullable($data['external_url'] ?? '', 500),
                self::nullable($data['poster_path'] ?? '', 255),
                isset(self::STATUSES[$data['status'] ?? '']) ? $data['status'] : 'draft',
                max(0, min(999, (int) ($data['sort_order'] ?? 0))),
                !empty($data['created_by']) ? (int) $data['created_by'] : null,
            ]
        );
    }

    /** @param array<string, mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::run(
            'UPDATE promo_videos
                SET title = ?, caption = ?, destination_id = ?, category = ?, status = ?, sort_order = ?
              WHERE id = ?',
            [
                mb_substr(trim((string) $data['title']), 0, 160),
                self::nullable($data['caption'] ?? '', 600),
                !empty($data['destination_id']) ? (int) $data['destination_id'] : null,
                isset(self::CATEGORIES[$data['category'] ?? '']) ? $data['category'] : 'promo',
                isset(self::STATUSES[$data['status'] ?? '']) ? $data['status'] : 'draft',
                max(0, min(999, (int) ($data['sort_order'] ?? 0))),
                $id,
            ]
        );
    }

    private static function nullable(mixed $value, int $max): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::first(
            'SELECT v.*, d.name AS destination_name, d.slug AS destination_slug
               FROM promo_videos v
               LEFT JOIN destinations d ON d.id = v.destination_id
              WHERE v.id = ?',
            [$id]
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function all(array $filters = [], int $limit = 200): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['status']) && isset(self::STATUSES[$filters['status']])) {
            $where[]  = 'v.status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['destination_id'])) {
            $where[]  = 'v.destination_id = ?';
            $params[] = (int) $filters['destination_id'];
        }

        if (!empty($filters['category']) && isset(self::CATEGORIES[$filters['category']])) {
            $where[]  = 'v.category = ?';
            $params[] = $filters['category'];
        }

        /* Title and caption. A video is found by what it is called. */
        if (trim((string) ($filters['search'] ?? '')) !== '') {
            $term     = '%' . trim((string) $filters['search']) . '%';
            $where[]  = '(v.title LIKE ? OR v.caption LIKE ?)';
            $params[] = $term;
            $params[] = $term;
        }

        $sql = 'SELECT v.*, d.name AS destination_name, d.slug AS destination_slug
                  FROM promo_videos v
                  LEFT JOIN destinations d ON d.id = v.destination_id';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        /* Order is the only thing that decides which clip leads a page. */
        $sql .= ' ORDER BY v.sort_order, v.id DESC LIMIT ' . max(1, min(500, $limit));

        return Database::all($sql, $params);
    }

    /**
     * What the public sees, for ONE destination.
     *
     * There is no site-wide video gallery. A clip filmed at Jadas Falls belongs
     * on the Jadas Falls page and nowhere else — a visitor reading about one
     * place is not browsing footage of the municipality. Passing null returns
     * everything and is used by the admin screen only.
     *
     * Published only: a draft is the office still deciding, and a half-edited
     * caption on a public page is worse than nothing there.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function published(?int $destinationId = null, int $limit = 24): array
    {
        return self::all(
            array_filter([
                'status'         => 'published',
                'destination_id' => $destinationId,
            ]),
            $limit
        );
    }

    /**
     * What a destination page shows, arranged for reading.
     *
     * Returns ['featured' => row|null, 'groups' => [category => rows]].
     *
     * The featured clip is pulled OUT of the groups rather than repeated in
     * them — showing the same film twice on one page is the commonest way a
     * grouped layout goes wrong, and the reader assumes it is two different
     * videos until they press play on the second.
     *
     * @return array{featured: array<string,mixed>|null, groups: array<string, array<int, array<string,mixed>>>}
     */
    public static function forDestinationPage(int $destinationId, int $limit = 12): array
    {
        $rows = self::published($destinationId, $limit);

        /* THE FIRST VIDEO LEADS, and the office decides which that is with the
           order field. There is no separate "featured" control any more: two
           ways of saying which clip comes first is one way too many, and the
           order field was already there.

           Shifted out of the list rather than left in it — showing the same
           film large at the top and again in a group below is the commonest way
           a layout like this goes wrong, and the reader assumes they are two
           different videos until they press play on the second. */
        $featured = $rows === [] ? null : array_shift($rows);
        $groups   = [];

        foreach ($rows as $row) {
            $groups[(string) $row['category']][] = $row;
        }

        /* Headings render in this order whatever the sort produced, so the page
           reads the same on every destination. */
        $ordered = [];

        foreach (array_keys(self::CATEGORY_HEADINGS) as $key) {
            if (!empty($groups[$key])) {
                $ordered[$key] = $groups[$key];
            }
        }

        return [
            'featured' => $featured,
            'groups'   => $ordered,
            'playlist' => self::playlist(self::published($destinationId, $limit)),
        ];
    }

    /**
     * The destination's videos as one ordered list the page can step through.
     *
     * THE OFFICE UPLOADS ONE FILM A YEAR. After five years a destination has
     * five, and the old layout answered that by stacking every one of them down
     * the page as a separate 16:9 player — five videos loading, five posters
     * fetched, and a reader scrolling past four to reach the one they wanted.
     *
     * A playlist is the honest shape for it: one player, and a list of what
     * else there is.
     *
     * The playable address is resolved HERE, not in the browser. embedUrl()
     * refuses anything that is not a real YouTube id, and that check is worth
     * nothing if a script can assemble the src itself afterwards — so the page
     * receives finished URLs and never builds one.
     *
     * @param  array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function playlist(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $isUpload = $row['source'] === 'upload' && !empty($row['file_path']);
            $embed    = $isUpload ? null : self::embedUrl((string) ($row['external_url'] ?? ''));

            /* A row that is neither a readable file nor a recognised host has
               nothing to play. Skipping it here keeps the counter honest —
               "3 of 7" should not include one that shows an empty box. */
            if (!$isUpload && $embed === null) {
                continue;
            }

            $out[] = [
                'id'       => (int) $row['id'],
                'title'    => (string) $row['title'],
                'caption'  => (string) ($row['caption'] ?? ''),
                'category' => (string) $row['category'],
                'label'    => self::CATEGORIES[$row['category']] ?? 'Video',
                'year'     => !empty($row['created_at']) ? date('Y', strtotime((string) $row['created_at'])) : '',
                'kind'     => $isUpload ? 'upload' : 'embed',
                'src'      => $isUpload ? base_url('/' . $row['file_path']) : $embed,
                'mime'     => (string) ($row['mime_type'] ?: 'video/mp4'),
                'poster'   => !empty($row['poster_path']) ? base_url('/' . $row['poster_path']) : '',
            ];
        }

        return $out;
    }

    public static function setStatus(int $id, string $status): void
    {
        if (!isset(self::STATUSES[$status])) {
            return;
        }

        Database::run('UPDATE promo_videos SET status = ? WHERE id = ?', [$status, $id]);


    }

    /**
     * Removes the row and, for an upload, the file with it.
     *
     * The file goes because a video is large and an orphaned 40MB clip on a
     * shared host costs the office quota for nothing. Photos are kept on delete
     * elsewhere in this codebase for the opposite reason — they are small and
     * sometimes irreplaceable.
     */
    public static function delete(int $id): void
    {
        $video = self::find($id);

        if ($video === null) {
            return;
        }

        Database::run('DELETE FROM promo_videos WHERE id = ?', [$id]);

        foreach ([$video['file_path'], $video['poster_path']] as $relative) {
            if (!$relative) {
                continue;
            }

            $absolute = dirname(__DIR__, 2) . '/' . ltrim((string) $relative, '/');

            /* Confined to uploads/ — a stored path is data, and data that has
               been tampered with must not be able to name a file outside it. */
            $real = realpath($absolute);
            $root = realpath(dirname(__DIR__, 2) . '/uploads');

            if ($real !== false && $root !== false && str_starts_with($real, $root)) {
                @unlink($real);
            }
        }
    }

    public static function counts(): array
    {
        $out = ['draft' => 0, 'published' => 0];

        foreach (Database::all('SELECT status, COUNT(*) c FROM promo_videos GROUP BY status') as $row) {
            $out[(string) $row['status']] = (int) $row['c'];
        }

        return $out;
    }
}
