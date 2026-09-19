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
            self::refuse();
        }
    }

    /**
     * The reply to a refused request.
     *
     * THE REFUSAL IS UNCHANGED. The request is still rejected, still 403, and
     * nothing is written. Only what the person sees is different.
     *
     * It used to be one line of plain text — black serif on white, no heading,
     * no seal, no way forward. The ordinary way to meet it is to leave the
     * sign-in page open over a break and then type a password, and what came
     * back read like a page that had escaped from a developer's machine rather
     * than part of a municipal system.
     *
     * The view is self-contained for the same reason 500.php is: the session is
     * the thing that has just gone, so a reply that needs one cannot report
     * that it went. If the view is missing for any reason, the plain sentence
     * is still sent — a guard that cannot answer is worse than an ugly answer.
     */
    private static function refuse(): never
    {
        /* WHERE "SIGN IN AGAIN" SHOULD GO, from the path of the request that was
           refused. An officer sent to the public homepage from the admin login
           has to find their way back; a destination manager sent to the
           officers' door cannot get in at all. */
        $path = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $base = function_exists('base_url') ? base_url('/') : '/';

        $root = rtrim($base, '/');

        /* Both ways out are plain GET links — see the note in the view. */
        if (str_contains($path, '/manager/')) {
            $csrfReturnUrl   = $root . '/manager/login.php';
            $csrfReturnLabel = 'Sign in again';
            $csrfAltUrl      = $base;
            $csrfAltLabel    = 'Back to the public site';
        } elseif (str_contains($path, '/admin/')) {
            $csrfReturnUrl   = $root . '/admin/login.php';
            $csrfReturnLabel = 'Sign in again';
            $csrfAltUrl      = $base;
            $csrfAltLabel    = 'Back to the public site';
        } else {
            /* A visitor, not staff — the contact form or the logbook. They have
               no sign-in to return to, and a second button to the same place is
               a choice that is not one. */
            $csrfReturnUrl   = $base;
            $csrfReturnLabel = 'Back to the site';
            $csrfAltUrl      = '';
            $csrfAltLabel    = '';
        }

        $csrfLogo = function_exists('asset') ? asset('img/tourism-logo-mark.png') : '';
        $view     = APP_PATH . '/views/errors/session-expired.php';

        if (is_file($view)) {
            header('Content-Type: text/html; charset=utf-8');
            require $view;
            exit;
        }

        header('Content-Type: text/plain; charset=utf-8');
        exit('Your session expired or the form was submitted from an untrusted page. Please reload and try again.');
    }

    /** Called after login, since the session ID changes. */
    public static function rotate(): void
    {
        $_SESSION[self::KEY] = bin2hex(random_bytes(32));
    }
}
