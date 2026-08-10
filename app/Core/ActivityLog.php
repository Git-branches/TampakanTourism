<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Append-only audit trail.
 *
 * Nothing in TourSync ever updates or deletes a row in activity_logs. When
 * an officer voids an arrival record — the one action that can change an
 * official statistic — this is the evidence of who did it and why.
 */
final class ActivityLog
{
    /**
     * @param string   $action  Dotted verb, e.g. 'destination.update'
     * @param int|null $actorId Overrides the logged-in admin, used during login
     *                          when the session does not exist yet.
     */
    public static function record(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $description = null,
        ?int $actorId = null
    ): void {
        try {
            Database::run(
                'INSERT INTO activity_logs
                    (admin_id, action, entity_type, entity_id, description, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $actorId ?? Auth::id(),
                    $action,
                    $entityType,
                    $entityId,
                    $description !== null ? mb_substr($description, 0, 400) : null,
                    self::packedIp(),
                    mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]
            );
        } catch (\Throwable $e) {
            // Logging must never break the action it is recording. A failed
            // audit write is itself worth knowing about, so it goes to the
            // PHP error log rather than disappearing.
            error_log('TourSync activity log failed: ' . $e->getMessage());
        }
    }

    /**
     * IPv4 and IPv6 both fit in VARBINARY(16) once packed, and packing keeps
     * the column a fixed width for indexing.
     */
    private static function packedIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $packed = $ip !== '' ? @inet_pton($ip) : false;
        return $packed === false ? null : $packed;
    }

    /** Turns a stored value back into a readable address for the log screen. */
    public static function readableIp(?string $packed): string
    {
        if ($packed === null || $packed === '') {
            return '—';
        }
        $ip = @inet_ntop($packed);
        return $ip === false ? '—' : $ip;
    }
}
