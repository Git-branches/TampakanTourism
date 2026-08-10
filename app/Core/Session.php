<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Hardened session handling.
 *
 * Cookie flags are set before the session starts — after start() they are
 * ignored, which is a common and silent mistake.
 */
final class Session
{
    private const NAME = 'toursync_sid';

    private static int $idleMinutes = 30;
    private static int $absoluteMinutes = 480;

    public static function start(int $idleMinutes = 30, int $absoluteMinutes = 480): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        self::$idleMinutes = $idleMinutes;
        self::$absoluteMinutes = $absoluteMinutes;

        // Only send the cookie over HTTPS when the request itself is HTTPS,
        // otherwise local development over http:// would never receive one.
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443');

        session_name(self::NAME);
        session_set_cookie_params([
            'lifetime' => 0,            // expires when the browser closes
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,         // JavaScript cannot read it
            'samesite' => 'Lax',        // blocks cross-site POST replay
        ]);

        session_start();
        self::enforceTimeouts();
    }

    /**
     * Two clocks, because they catch different problems: idle timeout closes
     * an unattended terminal, absolute timeout limits how long a stolen
     * session stays useful no matter how active it looks.
     */
    private static function enforceTimeouts(): void
    {
        $now = time();

        if (isset($_SESSION['_started_at'])) {
            $idleExpired = ($now - ($_SESSION['_last_seen'] ?? $now)) > self::$idleMinutes * 60;
            $absExpired  = ($now - $_SESSION['_started_at']) > self::$absoluteMinutes * 60;

            if ($idleExpired || $absExpired) {
                self::destroy();
                session_start();
                $_SESSION['_expired'] = true;
            }
        }

        $_SESSION['_started_at'] = $_SESSION['_started_at'] ?? $now;
        $_SESSION['_last_seen']  = $now;
    }

    public static function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Issues a new session ID, keeping the data. Called on privilege change. */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
        $_SESSION['_started_at'] = time();
    }

    public static function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }

        session_destroy();
    }

    // ---- One-request flash messages -----------------------------------------

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function takeFlash(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $messages;
    }
}
