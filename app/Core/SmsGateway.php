<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Sms\LogDriver;
use App\Core\Sms\PhilSmsDriver;
use App\Core\Sms\SemaphoreDriver;
use App\Core\Sms\SmsDriver;

/**
 * Chooses the SMS driver and prepares messages for it.
 *
 * SMS is not email. A message is billed in 160-character segments, and a
 * careless announcement that runs to 480 characters costs three times as much
 * per recipient — multiplied by every destination manager in the municipality.
 * The composition helpers below exist so that cost is visible before sending
 * rather than discovered on the bill.
 */
final class SmsGateway
{
    public const SEGMENT_LENGTH = 160;
    public const MAX_LENGTH     = 480;   // three segments; refuse to send more

    private static ?SmsDriver $driver = null;

    /**
     * Forces a driver for the rest of this process.
     *
     * Exists for two real situations, both of which end badly without it:
     *
     *   rehearsals   an officer wants to walk through an announcement blast
     *                before the real one, without spending credits or texting
     *                forty managers a test
     *
     *   automated    the test suites drive the alert workflow end to end. With
     *   tests        a live provider configured, a test that exercises "reply
     *                to the manager" sends a REAL text to whatever number the
     *                fixture invented — which is somebody's phone.
     *
     * Pass null to fall back to the configured driver.
     */
    public static function useDriver(?SmsDriver $driver): void
    {
        self::$driver = $driver;
    }

    public static function driver(): SmsDriver
    {
        if (self::$driver instanceof SmsDriver) {
            return self::$driver;
        }

        $configured = (string) config('sms.driver', 'log');

        // Falls back to the log driver rather than failing: an announcement
        // that cannot be texted should still publish to the website.
        self::$driver = match ($configured) {
            'philsms' => new PhilSmsDriver(
                (string) config('sms.api_key', ''),
                (string) config('sms.sender_id', '')
            ),
            'semaphore' => new SemaphoreDriver(
                (string) config('sms.api_key', ''),
                (string) config('sms.sender_id', '')
            ),
            default => new LogDriver(),
        };

        return self::$driver;
    }

    public static function isLive(): bool
    {
        return self::driver()->name() !== 'log';
    }

    public static function send(string $number, string $message): array
    {
        return self::driver()->send($number, self::prepare($message));
    }

    /**
     * Final safety pass before a message leaves the system.
     *
     * compose() has usually already run, but send() is public and a caller
     * could pass raw text. This guarantees no control characters reach the
     * provider and that the length is capped whatever the route in — an
     * over-long message is silently split and billed per extra segment.
     */
    public static function prepare(string $message): string
    {
        // Strip control characters except newline, which SMS handles fine.
        $message = preg_replace('/[^\P{C}\n]+/u', '', $message) ?? $message;
        $message = trim($message);

        if (mb_strlen($message) > self::MAX_LENGTH) {
            $message = mb_substr($message, 0, self::MAX_LENGTH - 3) . '...';
        }

        return $message;
    }

    /**
     * Formats an announcement for SMS.
     *
     * Deliberately plain: no HTML, no emoji, no smart quotes. Older handsets
     * still in use across upland barangays fall back to a 70-character
     * encoding the moment a single non-GSM character appears, which more than
     * halves what fits in a segment.
     */
    public static function compose(string $title, string $body, string $officeName = 'Tampakan Tourism Office'): string
    {
        $text = trim($title) . "\n\n" . trim(strip_tags($body));
        $text = preg_replace('/\s*\R\s*/u', "\n", $text) ?? $text;   // collapse blank lines

        // Replace characters that would silently switch the message to UCS-2.
        $text = strtr($text, [
            '—' => '-', '–' => '-', '’' => "'", '‘' => "'",
            '“' => '"', '”' => '"', '…' => '...', '₱' => 'PHP ',
            '&' => 'and',
        ]);

        $signature = "\n\n- " . $officeName;
        $room      = self::MAX_LENGTH - mb_strlen($signature);

        if (mb_strlen($text) > $room) {
            $text = mb_substr($text, 0, $room - 3) . '...';
        }

        return $text . $signature;
    }

    public static function segments(string $message): int
    {
        return max(1, (int) ceil(mb_strlen($message) / self::SEGMENT_LENGTH));
    }

    /** Rough peso cost, so the officer sees the bill before sending. */
    public static function estimateCost(string $message, int $recipients, float $perSegment = 0.50): float
    {
        return self::segments($message) * $recipients * $perSegment;
    }

    /** Validates and normalises a Philippine mobile number to E.164. */
    public static function normalise(string $number): ?string
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';

        if (preg_match('/^09\d{9}$/', $digits))  return '+63' . substr($digits, 1);
        if (preg_match('/^639\d{9}$/', $digits)) return '+' . $digits;

        return null;
    }
}
