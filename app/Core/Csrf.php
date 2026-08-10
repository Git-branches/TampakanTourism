<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Synchroniser-token CSRF protection.
 *
 * One token per session rather than per form: a tourist filling the logbook
 * may have several tabs open, and rotating per form would invalidate the
 * others and produce a confusing failure for an ordinary visitor.
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    /** Ready-made hidden input for forms. */
    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * hash_equals compares in constant time, so a token cannot be guessed
     * byte by byte from response timing.
     */
    public static function check(?string $supplied): bool
    {
        $expected = $_SESSION[self::KEY] ?? '';
        return $expected !== '' && is_string($supplied) && hash_equals($expected, $supplied);
    }

    /**
     * Guard for any mutating request. Ends the request on failure rather than
     * returning, so a caller cannot forget to act on the result.
     */
    public static function verify(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (!self::check($_POST['_token'] ?? null)) {
            // 403, not Laravel's 419: that code is not registered with IANA,
            // and PHP/Apache surface it as a 500 — making a correctly rejected
            // request look like a server fault in the logs.
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Your session expired or the form was submitted from an untrusted page. Please reload and try again.');
        }
    }

    /** Called after login, since the session ID changes. */
    public static function rotate(): void
    {
        $_SESSION[self::KEY] = bin2hex(random_bytes(32));
    }
}
