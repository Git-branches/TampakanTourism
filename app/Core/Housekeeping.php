<?php
declare(strict_types=1);

namespace App\Core;

/**
 * =============================================================================
 *  TourSync — housekeeping: old, read notifications are cleared by themselves.
 * -----------------------------------------------------------------------------
 *  Nothing ever deleted a bell entry. The storage this costs is small — a few
 *  hundred KB a year — but a bell carrying years of settled "read" items is
 *  clutter an officer scrolls past every day, and there was no way to be rid of
 *  it. So, once a day:
 *
 *    OFFICE BELL    an entry older than KEEP_DAYS goes once EVERY active
 *                   officer has read it. The office's bell is shared; one
 *                   officer having read it does not mean the office has.
 *    MANAGER BELL   the same, per destination: once every active manager of
 *                   that destination has read it.
 *
 *  AN UNREAD ENTRY IS NEVER REMOVED, however old — it is something somebody
 *  has not seen yet. The activity log is NOT touched here: it is the record of
 *  who did what, and it stays.
 *
 *  THERE IS NO CRON on the office's XAMPP machine, so this runs itself: the
 *  first signed-in page each day calls runDaily(), a stamp file makes every
 *  other call that day a no-op, and nothing about it can take a page down.
 * =============================================================================
 */
final class Housekeeping
{
    /** Six months: long enough to look something up, short enough to stay tidy. */
    public const KEEP_DAYS = 180;

    private const STAMP = 'housekeeping-notifications.stamp';

    /** Called from the admin and manager shells. Cheap on every call but one a day. */
    public static function runDaily(): void
    {
        try {
            $stamp = dirname(APP_PATH) . '/storage/cache/' . self::STAMP;

            if (is_file($stamp) && date('Y-m-d', (int) filemtime($stamp)) === date('Y-m-d')) {
                return;
            }

            /* Stamped FIRST, so two officers opening the dashboard in the same
               second do not both run it, and a failure below is not retried on
               every page load for the rest of the day. */
            @touch($stamp);

            self::pruneNotifications();
            self::pruneDanglingNotifications();
        } catch (\Throwable) {
            /* Housekeeping is never worth an error page. */
        }
    }

    /**
     * Removes bell entries whose record has gone.
     *
     * A notification carries entity_type and entity_id, which no foreign key
     * can police — the id means a different table depending on the type. So a
     * record removed anywhere (a change request cascading with its destination,
     * a draft report discarded by its manager) leaves the announcement of it
     * behind, and the officer clicking it lands on "could not be found". The
     * audit found 48 of them.
     *
     * Cleared here rather than at each of the dozen places that delete
     * something: one sweep that cannot be forgotten by the next feature.
     *
     * @return int how many were cleared
     */
    public static function pruneDanglingNotifications(): int
    {
        /* entity_type => the table that id belongs to. A type absent from this
           map is left alone: better a stale row than a guess at its table. */
        $targets = [
            'destination_alert'      => 'destination_alerts',
            'contact_message'        => 'contact_messages',
            'inspection_report'      => 'inspection_reports',
            'inspection_requirement' => 'inspection_requirements',
            'arrival_report'         => 'arrival_reports',
            'change_request'         => 'destination_change_requests',
            'data_request'           => 'data_requests',
            'guide_review'           => 'guide_reviews',
            'guide_request'          => 'tour_guide_requests',
            'announcement'           => 'announcements',
            'destination'            => 'destinations',
            'feedback'               => 'feedback',
        ];

        $cleared = 0;

        foreach (['admin_notifications', 'manager_notifications'] as $table) {
            foreach ($targets as $type => $target) {
                $cleared += Database::run(
                    "DELETE n FROM {$table} n
                      LEFT JOIN {$target} t ON t.id = n.entity_id
                      WHERE n.entity_type = ? AND n.entity_id IS NOT NULL AND t.id IS NULL",
                    [$type]
                )->rowCount();
            }
        }

        if ($cleared > 0) {
            ActivityLog::record('housekeeping.notifications', 'notification', null,
                'Cleared ' . $cleared . ' notification(s) pointing at records that no longer exist');
        }

        return $cleared;
    }

    /**
     * Deletes old notifications everyone concerned has read.
     *
     * @return array{office: int, managers: int} how many went from each bell
     */
    public static function pruneNotifications(int $keepDays = self::KEEP_DAYS): array
    {
        $keepDays = max(30, $keepDays);

        /* Ids first, then deleted by id: the read marks cascade from the rows
           being deleted, and selecting and deleting in one statement across
           that relationship is where MySQL starts refusing. */
        $office = array_column(Database::all(
            "SELECT n.id
               FROM admin_notifications n
              WHERE n.created_at < NOW() - INTERVAL {$keepDays} DAY
                AND NOT EXISTS (
                    SELECT 1 FROM admins a
                     WHERE a.is_active = 1
                       AND NOT EXISTS (SELECT 1 FROM admin_notification_reads r
                                        WHERE r.notification_id = n.id AND r.admin_id = a.id))"
        ), 'id');

        $managers = array_column(Database::all(
            "SELECT n.id
               FROM manager_notifications n
              WHERE n.created_at < NOW() - INTERVAL {$keepDays} DAY
                AND NOT EXISTS (
                    SELECT 1 FROM destination_managers m
                     WHERE m.destination_id = n.destination_id
                       AND m.is_active = 1
                       AND NOT EXISTS (SELECT 1 FROM manager_notification_reads r
                                        WHERE r.notification_id = n.id AND r.manager_id = m.id))"
        ), 'id');

        self::deleteIds('admin_notifications', $office);
        self::deleteIds('manager_notifications', $managers);

        if ($office !== [] || $managers !== []) {
            ActivityLog::record('housekeeping.notifications', 'notification', null,
                'Cleared ' . count($office) . ' office and ' . count($managers)
                . ' manager notification(s) older than ' . $keepDays . ' days, already read by everyone');
        }

        return ['office' => count($office), 'managers' => count($managers)];
    }

    private static function deleteIds(string $table, array $ids): void
    {
        foreach (array_chunk(array_map('intval', $ids), 500) as $chunk) {
            Database::run(
                "DELETE FROM {$table} WHERE id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')',
                $chunk
            );
        }
    }
}
