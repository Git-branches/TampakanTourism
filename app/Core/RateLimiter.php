<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Rate limiting for the public endpoints.
 *
 * The digital logbook is deliberately open — no login, no captcha — because a
 * tourist standing in the sun will not complete anything slower. The cost of
 * that decision is that the endpoint writes rows which become the
 * Municipality's official tourism statistics, so it needs a floor.
 *
 * Storage is a file per bucket under storage/ratelimit. That is chosen over a
 * database table on purpose: the limiter must still work when the database is
 * the thing under strain, and it must not add a write to every request it is
 * trying to protect.
 */
final class RateLimiter
{
    private static function directory(): string
    {
        $dir = dirname(APP_PATH) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ratelimit';

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }

    private static function file(string $key): string
    {
        // The key is hashed so a caller cannot shape it into a path.
        return self::directory() . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }

    /**
     * Records one hit and reports whether the caller is now over the limit.
     *
     * @param string $key         Bucket identifier, e.g. "arrival:<ip>:<token>"
     * @param int    $maxHits     Hits allowed inside the window
     * @param int    $windowSecs  Length of the window
     * @return bool  true when the request should be allowed
     */
    public static function allow(string $key, int $maxHits, int $windowSecs): bool
    {
        $file = self::file($key);
        $now  = time();

        $hits = [];
        if (is_file($file)) {
            $decoded = json_decode((string) @file_get_contents($file), true);
            if (is_array($decoded)) {
                $hits = $decoded;
            }
        }

        // Drop everything that has aged out of the window.
        $hits = array_values(array_filter($hits, static fn($t): bool => ($now - (int) $t) < $windowSecs));

        if (count($hits) >= $maxHits) {
            return false;
        }

        $hits[] = $now;
        @file_put_contents($file, json_encode($hits), LOCK_EX);

        self::sweep();

        return true;
    }

    /** Seconds until the oldest hit in the bucket expires. */
    public static function retryAfter(string $key, int $windowSecs): int
    {
        $file = self::file($key);
        if (!is_file($file)) {
            return 0;
        }

        $hits = json_decode((string) @file_get_contents($file), true);
        if (!is_array($hits) || $hits === []) {
            return 0;
        }

        return max(0, $windowSecs - (time() - (int) min($hits)));
    }

    /**
     * Removes bucket files nobody has touched for a day.
     *
     * Runs on roughly one call in fifty rather than on a schedule: shared
     * hosting cannot be relied on for cron, and without this the directory
     * grows one file per visitor forever.
     */
    private static function sweep(): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }

        $cutoff = time() - 86400;

        foreach (glob(self::directory() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (@filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    /**
     * Stable pseudonymous identifier for a visitor's device.
     *
     * A salted hash of address and user agent — the raw IP is never stored,
     * which keeps duplicate detection working without turning the arrivals
     * table into a log of who was where.
     */
    public static function deviceHash(): string
    {
        $salt = (string) config('security.device_salt', 'toursync');

        return hash('sha256', $salt . '|'
            . ($_SERVER['REMOTE_ADDR'] ?? '')
            . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    public static function ipKey(string $prefix): string
    {
        return $prefix . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
}
