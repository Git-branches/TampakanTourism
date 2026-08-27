<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * TourSync — the bell in the officer's topbar.
 *
 * WHAT THIS IS NOT
 *
 * Not App\Core\Notifier, and not the `notifications` table. Those record
 * whether an announcement's SMS reached a destination manager — a delivery
 * receipt for a message that left the building. This is the other direction:
 * something happened in the municipality and the office has not looked at it
 * yet.
 *
 * WHY "READ" LIVES IN A SEPARATE TABLE
 *
 * Six officers share this system. Read is not a property of the event; it is a
 * property of one person's relationship to it. One row per event, one small row
 * per person who has read it — so marking a guide request read does not hide it
 * from the colleague who has not seen it, and an officer added next year does
 * not inherit somebody else's history.
 *
 * WHY THE BELL AND NOT THE SIDEBAR
 *
 * The sidebar already carries counts on the menus that own the work — three
 * requests waiting sits beside Tour Guide Requests, where somebody about to
 * open that screen will see it. Those answer "where is work waiting". The bell
 * answers a different question: "what has happened since I last looked", in
 * time order, across every module at once.
 */
final class NotificationRepository
{
    /**
     * How many the dropdown shows.
     *
     * Five, because a dropdown that scrolls is a page pretending to be a menu —
     * and the sixth thing is what "View all" is for.
     */
    public const DROPDOWN = 5;

    /**
     * The kinds of thing worth interrupting somebody about, and how each looks.
     *
     * An icon and a tone per type rather than one bell for everything: an
     * officer glancing at five rows should be able to tell an injury report
     * from a new video without reading a word.
     */
    public const TYPES = [
        'guide_request'  => ['icon' => 'fa-person-hiking',        'tone' => 'blue',  'label' => 'Tour guide request'],
        'contact_message'=> ['icon' => 'fa-envelope',             'tone' => 'blue',  'label' => 'Message'],
        'arrival_report' => ['icon' => 'fa-inbox',                'tone' => 'amber', 'label' => 'Arrival report'],
        'change_request' => ['icon' => 'fa-pen-to-square',        'tone' => 'amber', 'label' => 'Change request'],
        'alert'          => ['icon' => 'fa-triangle-exclamation', 'tone' => 'red',   'label' => 'Destination alert'],
        'inspection'     => ['icon' => 'fa-clipboard-check',      'tone' => 'amber', 'label' => 'Compliance report'],
        'feedback'       => ['icon' => 'fa-comment-dots',         'tone' => 'green', 'label' => 'Visitor feedback'],
    ];

    /**
     * Records something the office should know about.
     *
     * NEVER THROWS. This is called from inside the paths that accept a guide
     * request, a message, a report — and a bell that cannot be written must not
     * take down the thing it was announcing. A visitor's request is worth more
     * than the notification about it.
     *
     * @param array{link?: string, body?: string, entity_type?: string, entity_id?: int} $extra
     */
    public static function record(string $type, string $title, array $extra = []): ?int
    {
        if (!isset(self::TYPES[$type])) {
            $type = 'alert';
        }

        try {
            return Database::insert(
                'INSERT INTO admin_notifications (type, title, body, link, entity_type, entity_id)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $type,
                    mb_substr(trim($title), 0, 160),
                    ($extra['body'] ?? '') !== '' ? mb_substr((string) $extra['body'], 0, 400) : null,
                    ($extra['link'] ?? '') !== '' ? mb_substr((string) $extra['link'], 0, 255) : null,
                    $extra['entity_type'] ?? null,
                    isset($extra['entity_id']) ? (int) $extra['entity_id'] : null,
                ]
            );
        } catch (\Throwable $e) {
            error_log('Notification not recorded: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * The newest notifications, each marked read or unread for this officer.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function latestFor(int $adminId, int $limit = self::DROPDOWN, int $offset = 0): array
    {
        return Database::all(
            'SELECT n.*, r.read_at
               FROM admin_notifications n
               LEFT JOIN admin_notification_reads r
                      ON r.notification_id = n.id AND r.admin_id = ?
              ORDER BY n.created_at DESC, n.id DESC
              LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset),
            [$adminId]
        );
    }

    /** Everything, for the full page. Paged by the caller. */
    public static function countAll(): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM admin_notifications');
    }

    /**
     * How many this officer has not read.
     *
     * A LEFT JOIN rather than NOT IN: the read table is keyed on
     * (notification_id, admin_id), so the join is an index lookup and the
     * subquery would not be.
     */
    public static function unreadCountFor(int $adminId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*)
               FROM admin_notifications n
               LEFT JOIN admin_notification_reads r
                      ON r.notification_id = n.id AND r.admin_id = ?
              WHERE r.notification_id IS NULL',
            [$adminId]
        );
    }

    /**
     * Marks one read for one officer.
     *
     * INSERT IGNORE rather than a check-then-write: two tabs open on the same
     * bell would otherwise race, and the second would raise a duplicate key on
     * something that is already true.
     */
    public static function markRead(int $id, int $adminId): bool
    {
        Database::run(
            'INSERT IGNORE INTO admin_notification_reads (notification_id, admin_id, read_at)
             VALUES (?, ?, NOW())',
            [$id, $adminId]
        );

        return true;
    }

    /** Puts one back to unread — the row simply goes. */
    public static function markUnread(int $id, int $adminId): bool
    {
        Database::run(
            'DELETE FROM admin_notification_reads WHERE notification_id = ? AND admin_id = ?',
            [$id, $adminId]
        );

        return true;
    }

    /**
     * Marks everything this officer has not read.
     *
     * One statement rather than a loop: the office may have a hundred of these
     * after a quiet week, and a hundred round trips to make a badge say zero is
     * a hundred round trips.
     */
    public static function markAllRead(int $adminId): int
    {
        Database::run(
            'INSERT IGNORE INTO admin_notification_reads (notification_id, admin_id, read_at)
             SELECT n.id, ?, NOW()
               FROM admin_notifications n
               LEFT JOIN admin_notification_reads r
                      ON r.notification_id = n.id AND r.admin_id = ?
              WHERE r.notification_id IS NULL',
            [$adminId, $adminId]
        );

        return 0;
    }

    /**
     * One notification as the bell shows it.
     *
     * The relative time is worked out here rather than in the browser, so the
     * dropdown and the full page cannot disagree about what "2 hours ago"
     * means, and so a page with the script blocked still reads properly.
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        $type = (string) ($row['type'] ?? 'alert');
        $meta = self::TYPES[$type] ?? self::TYPES['alert'];

        return [
            'id'      => (int) $row['id'],
            'type'    => $type,
            'icon'    => $meta['icon'],
            'tone'    => $meta['tone'],
            'label'   => $meta['label'],
            'title'   => (string) $row['title'],
            'body'    => (string) ($row['body'] ?? ''),
            'link'    => (string) ($row['link'] ?? ''),
            'unread'  => empty($row['read_at']),
            'when'    => self::relative((string) $row['created_at']),
            'exact'   => format_date((string) $row['created_at'], 'F j, Y \a\t g:i A'),
        ];
    }

    /**
     * "4 minutes ago", in the words a person uses.
     *
     * Stops at a week and gives the date instead: "23 days ago" is arithmetic
     * somebody then has to do in their head to work out whether they were in
     * the office that day.
     */
    public static function relative(string $timestamp): string
    {
        $then = strtotime($timestamp);

        if ($then === false) {
            return '';
        }

        $seconds = time() - $then;

        if ($seconds < 60)    { return 'just now'; }
        if ($seconds < 3600)  { $n = (int) floor($seconds / 60);   return $n . ' minute' . ($n === 1 ? '' : 's') . ' ago'; }
        if ($seconds < 86400) { $n = (int) floor($seconds / 3600); return $n . ' hour'   . ($n === 1 ? '' : 's') . ' ago'; }
        if ($seconds < 604800){ $n = (int) floor($seconds / 86400);return $n . ' day'    . ($n === 1 ? '' : 's') . ' ago'; }

        return format_date($timestamp, 'M j, Y');
    }
}
