<?php
declare(strict_types=1);

namespace App\Core;

/**
 * =============================================================================
 *  TourSync — destination manager authentication                    Feature 2
 * -----------------------------------------------------------------------------
 *  A separate class from Auth, and deliberately so.
 *
 *  Auth guards the Municipal Tourism Office dashboard. Fourteen admin modules
 *  call Auth::require() on their first line and none of them check anything
 *  else. Teaching Auth to also accept destination managers would silently admit
 *  a manager to arrivals, reports, settings, activity logs, and every other
 *  officer screen — a role escalation introduced by a one-line change nobody
 *  would notice reviewing it.
 *
 *  So a manager session lives under its own key. Auth::check() is false for a
 *  manager; ManagerAuth::check() is false for an officer. The two cannot be
 *  confused for one another because they do not share a place to be confused.
 *
 *  A manager is scoped to exactly one destination for their entire session, and
 *  destinationId() is the value every query in the manager area must filter on.
 *  It is read from the session — set at sign-in from the database — never from
 *  a request parameter, because a destination id in a URL is a destination id
 *  someone can edit.
 * =============================================================================
 */
final class ManagerAuth
{
    private const KEY = '_manager';

    private const MAX_ATTEMPTS    = 5;
    private const LOCKOUT_MINUTES = 15;

    // -------------------------------------------------------------------------
    // Signing in
    // -------------------------------------------------------------------------

    /**
     * @return string|null Error message for the user, or null on success.
     */
    public static function attempt(string $username, string $password): ?string
    {
        $manager = Database::first(
            'SELECT m.*, d.name AS destination_name, d.slug AS destination_slug, d.status AS destination_status
               FROM destination_managers m
               JOIN destinations d ON d.id = m.destination_id
              WHERE m.username = ?
              LIMIT 1',
            [$username]
        );

        /* One message for every failure. Distinguishing "no such account" from
           "wrong password" hands an attacker a list of valid usernames. */
        $generic = 'Incorrect username or password.';

        if ($manager === null) {
            /* Burn roughly the time a real verification costs, so response
               timing does not reveal whether the account exists. */
            password_verify($password, '$argon2id$v=19$m=65536,t=4,p=1$ZmFrZXNhbHR2YWx1ZQ$0000000000000000000000000000000000000000000');
            return $generic;
        }

        if ((int) $manager['is_active'] !== 1) {
            return 'This account has been deactivated. Contact the Municipal Tourism Office.';
        }

        /* A manager whose row exists but was never issued credentials. Reported
           as the generic failure rather than "no password set", which would
           confirm the account exists. */
        if (($manager['password_hash'] ?? '') === '' || $manager['password_hash'] === null) {
            return $generic;
        }

        if ($manager['locked_until'] !== null && strtotime((string) $manager['locked_until']) > time()) {
            $minutes = (int) ceil((strtotime((string) $manager['locked_until']) - time()) / 60);
            return "Too many failed attempts. Try again in {$minutes} minute(s).";
        }

        if (!password_verify($password, (string) $manager['password_hash'])) {
            self::recordFailure($manager);
            return $generic;
        }

        /* Re-hash if PHP's defaults have moved on since the password was set. */
        if (password_needs_rehash((string) $manager['password_hash'], PASSWORD_ARGON2ID)) {
            Database::run(
                'UPDATE destination_managers SET password_hash = ? WHERE id = ?',
                [password_hash($password, PASSWORD_ARGON2ID), $manager['id']]
            );
        }

        self::establish($manager);

        return null;
    }

    private static function recordFailure(array $manager): void
    {
        $attempts = (int) $manager['failed_attempts'] + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            Database::run(
                'UPDATE destination_managers
                    SET failed_attempts = ?, locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE)
                  WHERE id = ?',
                [$attempts, self::LOCKOUT_MINUTES, $manager['id']]
            );

            ActivityLog::record(
                'manager.locked', 'manager', (int) $manager['id'],
                'Manager account locked after ' . $attempts . ' failed attempts'
            );
        } else {
            Database::run(
                'UPDATE destination_managers SET failed_attempts = ? WHERE id = ?',
                [$attempts, $manager['id']]
            );
        }
    }

    private static function establish(array $manager): void
    {
        /* A fresh session id the moment privileges change — any id an attacker
           planted before sign-in becomes worthless. */
        Session::regenerate();
        Csrf::rotate();

        /* Both keys are cleared, not just ours. Somebody signed in as an
           officer on this browser who then signs in as a manager must not keep
           an officer session alive underneath. */
        unset($_SESSION['_admin']);

        $_SESSION[self::KEY] = [
            'id'             => (int) $manager['id'],
            'full_name'      => $manager['full_name'],
            'username'       => $manager['username'],
            'destination_id' => (int) $manager['destination_id'],
            'destination'    => $manager['destination_name'],
        ];

        Database::run(
            'UPDATE destination_managers
                SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW()
              WHERE id = ?',
            [$manager['id']]
        );

        ActivityLog::record(
            'manager.login', 'manager', (int) $manager['id'],
            'Signed in for ' . $manager['destination_name']
        );
    }

    public static function logout(): void
    {
        if (self::check()) {
            ActivityLog::record('manager.logout', 'manager', self::id(), 'Signed out');
        }

        Session::destroy();
    }

    // -------------------------------------------------------------------------
    // Reading the session
    // -------------------------------------------------------------------------

    public static function check(): bool
    {
        return isset($_SESSION[self::KEY]['id']);
    }

    public static function user(): ?array
    {
        return $_SESSION[self::KEY] ?? null;
    }

    public static function id(): ?int
    {
        return $_SESSION[self::KEY]['id'] ?? null;
    }

    public static function name(): string
    {
        return (string) ($_SESSION[self::KEY]['full_name'] ?? '');
    }

    /**
     * The scope of everything a manager may see or write.
     *
     * Every query in the manager area filters on this. It comes from the
     * session, which was set from the database at sign-in — never from a
     * request parameter, because a destination id in a URL is a destination id
     * someone can change to a neighbour's.
     */
    public static function destinationId(): ?int
    {
        return $_SESSION[self::KEY]['destination_id'] ?? null;
    }

    public static function destinationName(): string
    {
        return (string) ($_SESSION[self::KEY]['destination'] ?? '');
    }

    // -------------------------------------------------------------------------
    // The gate
    // -------------------------------------------------------------------------

    /**
     * Called on the first line of every manager page. Hiding a link is
     * presentation; this is the access control.
     */
    public static function require(): void
    {
        if (!self::check()) {
            Session::put('_manager_intended', $_SERVER['REQUEST_URI'] ?? '');
            Session::flash('warning', 'Please sign in to continue.');
            redirect(base_url('/manager/login.php'));
        }
    }

    /**
     * Confirms a record belongs to the signed-in manager's destination.
     *
     * The companion to destinationId(): that one scopes a listing, this one
     * guards a single record reached by id. Without it, a manager who changes
     * ?id=12 to ?id=13 in the address bar reads another destination's report.
     */
    public static function owns(?int $destinationId): bool
    {
        return $destinationId !== null && $destinationId === self::destinationId();
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }
}
