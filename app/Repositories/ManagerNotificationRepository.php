<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * TourSync — the destination manager's bell.
 *
 * WHY THIS IS NOT NotificationRepository WITH A FILTER
 *
 * That one carries the office's workload: "a manager submitted a report", "a
 * visitor left a review", "an alert was raised at Kolon Ridge". Almost none of
 * it is a manager's business, and most rows in it name a destination that is
 * not theirs. Filtering a stream that was never designed to be filtered puts
 * the whole guarantee on one WHERE clause, and the day somebody adds a
 * notification type and forgets it, a manager reads another site's business.
 *
 * Two tables, two audiences, and no query that can accidentally cross them.
 *
 * WHAT GOES IN HERE
 *
 * Only things the office DID that this destination now has to answer for or
 * act on: their report picked up, approved, sent back with a reason; a response
 * to an alert they raised. Not the office's own queue, not other destinations,
 * and nothing they already know because they just did it themselves.
 *
 * KEYED TO THE DESTINATION, READ PER MANAGER
 *
 * Jadas Falls has two managers. Both need to see that their report came back;
 * one of them marking it read must not hide it from the other. So the
 * notification belongs to the destination and the read mark belongs to the
 * person — the same shape the officer's bell has, for the same reason.
 */
final class ManagerNotificationRepository
{
    /** How many the dropdown shows before "view all". */
    public const DROPDOWN = 5;

    /**
     * The kinds of thing worth interrupting a manager about.
     *
     * Deliberately fewer than the officer's list. A manager glancing at the
     * panel should be able to tell "your report came back" from "the office
     * answered your alert" without reading a word.
     */
    public const TYPES = [
        'inspection_reviewing' => ['icon' => 'fa-eye',                 'tone' => 'blue',  'label' => 'Inspection'],
        'inspection_approved'  => ['icon' => 'fa-circle-check',        'tone' => 'green', 'label' => 'Inspection approved'],
        'inspection_revision'  => ['icon' => 'fa-rotate-left',         'tone' => 'amber', 'label' => 'Needs revision'],
        'report_approved'      => ['icon' => 'fa-circle-check',        'tone' => 'green', 'label' => 'Arrival report'],
        'report_returned'      => ['icon' => 'fa-rotate-left',         'tone' => 'amber', 'label' => 'Arrival report'],
        'alert_response'       => ['icon' => 'fa-triangle-exclamation','tone' => 'red',   'label' => 'Alert'],
        'office'               => ['icon' => 'fa-building-columns',    'tone' => 'blue',  'label' => 'Tourism Office'],
    ];

    /**
     * Records something the office did that this destination should know about.
     *
     * NEVER THROWS. It is called from inside the paths that approve a report,
     * send an inspection back, answer an alert — and a bell that cannot be
     * written must not take down the decision it was announcing. The officer's
     * approval is worth more than the notification about it.
     *
     * @param array{body?: string, link?: string, entity_type?: string, entity_id?: int} $extra
     */
    public static function record(int $destinationId, string $type, string $title, array $extra = []): ?int
    {
        if ($destinationId <= 0 || !isset(self::TYPES[$type])) {
            return null;
        }

        try {
            Database::run(
                'INSERT INTO manager_notifications
                    (destination_id, type, title, body, link, entity_type, entity_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $destinationId,
                    $type,
                    mb_substr(trim($title), 0, 160),
                    isset($extra['body']) ? mb_substr(trim((string) $extra['body']), 0, 400) : null,
                    isset($extra['link']) ? mb_substr((string) $extra['link'], 0, 255) : null,
                    $extra['entity_type'] ?? null,
                    isset($extra['entity_id']) ? (int) $extra['entity_id'] : null,
                ]
            );

            return (int) Database::pdo()->lastInsertId();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The newest for one manager, with their own read state attached.
     *
     * SCOPED BY DESTINATION IN THE QUERY ITSELF, not by anything the caller
     * passes. $destinationId comes from the session, never the request.
     */
    public static function latestFor(int $managerId, int $destinationId, int $limit = self::DROPDOWN, int $offset = 0): array
    {
        return Database::all(
            'SELECT n.*, r.read_at
               FROM manager_notifications n
               LEFT JOIN manager_notification_reads r
                      ON r.notification_id = n.id AND r.manager_id = ?
              WHERE n.destination_id = ?
              ORDER BY n.id DESC
              LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            [$managerId, $destinationId]
        );
    }

    public static function countAll(int $destinationId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM manager_notifications WHERE destination_id = ?',
            [$destinationId]
        );
    }

    public static function unreadCountFor(int $managerId, int $destinationId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*)
               FROM manager_notifications n
               LEFT JOIN manager_notification_reads r
                      ON r.notification_id = n.id AND r.manager_id = ?
              WHERE n.destination_id = ? AND r.notification_id IS NULL',
            [$managerId, $destinationId]
        );
    }

    /**
     * Marks one read.
     *
     * The destination is part of the condition, so a manager cannot mark a row
     * belonging to another site — not because they would want to, but because
     * the id is in a POST and anything in a POST is a thing somebody can change.
     */
    public static function markRead(int $id, int $managerId, int $destinationId): bool
    {
        $owns = (int) Database::scalar(
            'SELECT COUNT(*) FROM manager_notifications WHERE id = ? AND destination_id = ?',
            [$id, $destinationId]
        );

        if ($owns === 0) {
            return false;
        }

        Database::run(
            'INSERT INTO manager_notification_reads (notification_id, manager_id, read_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE read_at = read_at',
            [$id, $managerId]
        );

        return true;
    }

    public static function markUnread(int $id, int $managerId, int $destinationId): bool
    {
        $owns = (int) Database::scalar(
            'SELECT COUNT(*) FROM manager_notifications WHERE id = ? AND destination_id = ?',
            [$id, $destinationId]
        );

        if ($owns === 0) {
            return false;
        }

        Database::run(
            'DELETE FROM manager_notification_reads WHERE notification_id = ? AND manager_id = ?',
            [$id, $managerId]
        );

        return true;
    }

    /**
     * @return int How many were newly marked.
     *
     * Counted before the write rather than read off lastInsertId(), which after
     * an INSERT..SELECT is the id of the first inserted row and not a count of
     * anything — it would have reported 1 for a panel of twelve.
     */
    public static function markAllRead(int $managerId, int $destinationId): int
    {
        $was = self::unreadCountFor($managerId, $destinationId);

        if ($was === 0) {
            return 0;
        }

        Database::run(
            'INSERT INTO manager_notification_reads (notification_id, manager_id, read_at)
             SELECT n.id, ?, NOW()
               FROM manager_notifications n
               LEFT JOIN manager_notification_reads r
                      ON r.notification_id = n.id AND r.manager_id = ?
              WHERE n.destination_id = ? AND r.notification_id IS NULL',
            [$managerId, $managerId, $destinationId]
        );

        return $was;
    }

    /** One row, in the shape the bell script expects — the officer's shape. */
    public static function present(array $row): array
    {
        $type = (string) ($row['type'] ?? 'office');
        $meta = self::TYPES[$type] ?? self::TYPES['office'];

        return [
            'id'     => (int) $row['id'],
            'type'   => $type,
            'icon'   => $meta['icon'],
            'tone'   => $meta['tone'],
            'label'  => $meta['label'],
            'title'  => (string) $row['title'],
            'body'   => (string) ($row['body'] ?? ''),
            'link'   => (string) ($row['link'] ?? ''),
            'unread' => empty($row['read_at']),
            'when'   => NotificationRepository::relative((string) $row['created_at']),
            'exact'  => format_date((string) $row['created_at'], 'F j, Y \a\t g:i A'),
        ];
    }
}
