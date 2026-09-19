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
        } catch (\Throwable) {
            /* Housekeeping is never worth an error page. */
        }
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
