<?php
declare(strict_types=1);

namespace App\Core;

use App\Repositories\ManagerRepository;

/**
 * Queues and sends announcement notifications.
 *
 * Why a queue rather than sending inside the publish request: a blast to forty
 * managers over a provider that answers in a second each would hold the page
 * open for the better part of a minute, and a timeout halfway through would
 * leave the officer unable to tell who had been reached. Rows are written
 * first, then sent, so the record of intent survives whatever happens next.
 *
 * On delivery status, stated plainly because the brief asks for read receipts:
 * SMS can report 'sent', and sometimes 'delivered' if the provider supports
 * callbacks. It can never report 'read'. Any system claiming otherwise for
 * plain SMS is guessing.
 */
final class Notifier
{
    private const MAX_ATTEMPTS = 3;

    /**
     * Creates one queued notification per opted-in recipient.
     *
     * @return int Number of recipients queued
     */
    public static function queue(int $announcementId, ?int $destinationId = null): int
    {
        $recipients = ManagerRepository::smsRecipients($destinationId);

        if ($recipients === []) {
            return 0;
        }

        $queued = 0;

        foreach ($recipients as $manager) {
            // Idempotent: re-dispatching an announcement must not text anyone
            // a second time. An officer clicking Send twice is normal.
            $exists = Database::scalar(
                'SELECT 1 FROM notifications WHERE announcement_id = ? AND manager_id = ?',
                [$announcementId, $manager['id']]
            );

            if ($exists !== null) {
                continue;
            }

            Database::run(
                "INSERT INTO notifications (announcement_id, manager_id, channel, status)
                 VALUES (?, ?, 'sms', 'queued')",
                [$announcementId, $manager['id']]
            );

            $queued++;
        }

        return $queued;
    }

    /**
     * Sends everything queued for one announcement.
     *
     * @return array{sent:int, failed:int, skipped:int}
     */
    public static function dispatch(int $announcementId, string $title, string $body): array
    {
        $message = SmsGateway::compose($title, $body, (string) setting('office_name', 'Tampakan Tourism Office'));

        $pending = Database::all(
            "SELECT n.id, n.attempts, m.mobile_number, m.full_name
               FROM notifications n
               JOIN destination_managers m ON m.id = n.manager_id
              WHERE n.announcement_id = ?
                AND n.status IN ('queued', 'failed')
                AND n.attempts < ?",
            [$announcementId, self::MAX_ATTEMPTS]
        );

        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($pending as $row) {
            $number = SmsGateway::normalise((string) $row['mobile_number']);

            // A malformed number is not worth three retry attempts.
            if ($number === null) {
                Database::run(
                    "UPDATE notifications
                        SET status = 'failed', attempts = ?, error_message = ?
                      WHERE id = ?",
                    [self::MAX_ATTEMPTS, 'Invalid mobile number on record', $row['id']]
                );
                $result['skipped']++;
                continue;
            }

            $response = SmsGateway::send($number, $message);

            if ($response['ok']) {
                Database::run(
                    "UPDATE notifications
                        SET status = 'sent', attempts = attempts + 1,
                            provider_ref = ?, error_message = NULL, sent_at = NOW()
                      WHERE id = ?",
                    [$response['reference'], $row['id']]
                );
                $result['sent']++;
            } else {
                Database::run(
                    "UPDATE notifications
                        SET status = 'failed', attempts = attempts + 1, error_message = ?
                      WHERE id = ?",
                    [mb_substr((string) $response['error'], 0, 255), $row['id']]
                );
                $result['failed']++;
            }
        }

        return $result;
    }

    /** Per-recipient delivery board for one announcement. */
    public static function deliveryBoard(int $announcementId): array
    {
        return Database::all(
            "SELECT n.*, m.full_name, m.mobile_number, m.position, d.name AS destination_name
               FROM notifications n
               JOIN destination_managers m ON m.id = n.manager_id
               JOIN destinations d ON d.id = m.destination_id
              WHERE n.announcement_id = ?
              ORDER BY n.status, d.name, m.full_name",
            [$announcementId]
        );
    }

    public static function summary(int $announcementId): array
    {
        $out = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'delivered' => 0, 'total' => 0];

        foreach (Database::all(
            'SELECT status, COUNT(*) AS total FROM notifications WHERE announcement_id = ? GROUP BY status',
            [$announcementId]
        ) as $row) {
            $out[$row['status']] = (int) $row['total'];
            $out['total'] += (int) $row['total'];
        }

        return $out;
    }

    /** Notifications still worth retrying, across every announcement. */
    public static function retryableCount(): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM notifications WHERE status = 'failed' AND attempts < ?",
            [self::MAX_ATTEMPTS]
        );
    }

    public static function maxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }
}
