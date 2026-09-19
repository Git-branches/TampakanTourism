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

            /* Written down for the same reason as the officer's: a run of these
               is the only early sign of guessing. Never the password. */
            ActivityLog::record(
                'manager.failed', 'manager', (int) $manager['id'],
                'Failed sign-in (' . $attempts . ' in a row)'
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

        /* Read back rather than taken from $manager: attempt() may have just
           re-hashed the password, and a fingerprint of the old hash would sign
           this session out on its very next request. */
        $stored = Database::first(
            'SELECT password_hash, must_change_password FROM destination_managers WHERE id = ?',
            [(int) $manager['id']]
        );

        $_SESSION[self::KEY] = [
            'id'             => (int) $manager['id'],
            'full_name'      => $manager['full_name'],
            'username'       => $manager['username'],
            'destination_id' => (int) $manager['destination_id'],
            'destination'    => $manager['destination_name'],
            'pw'             => Password::fingerprint($stored['password_hash'] ?? null),
            'must_change'    => (int) ($stored['must_change_password'] ?? 0) === 1,
        ];
        self::$verified = true;

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

    /**
     * Signed in — and still allowed to be.
     *
     * Deactivating a manager, or revoking their sign-in, used to stop only the
     * NEXT sign-in: a manager already in the portal kept filing figures until
     * they closed the tab. The account is now re-read once per request, and a
     * session whose account was deactivated, revoked, or given a new password
     * since it opened ends on its next click.
     */
    public static function check(): bool
    {
        if (!isset($_SESSION[self::KEY]['id'])) {
            return false;
        }

        return self::$verified ??= self::stillValid();
    }

    /** Once per request; every check() after the first reads this. */
    private static ?bool $verified = null;

    /** Why a session was just ended, for the sign-in page to say. */
    private static ?string $ended = null;

    private static function stillValid(): bool
    {
        $row = Database::first(
            'SELECT is_active, password_hash, must_change_password FROM destination_managers WHERE id = ?',
            [(int) $_SESSION[self::KEY]['id']]
        );

        $held = $_SESSION[self::KEY]['pw'] ?? null;
        $now  = $row !== null ? Password::fingerprint($row['password_hash']) : '';

        if ($row === null || (int) $row['is_active'] !== 1) {
            self::$ended = 'This account has been deactivated. Contact the Municipal Tourism Office.';
        } elseif ($row['password_hash'] === null || $row['password_hash'] === '') {
            self::$ended = 'Your sign-in was withdrawn by the Municipal Tourism Office.';
        } elseif ($held !== null && !hash_equals((string) $held, $now)) {
            self::$ended = 'The password for this account was changed, so you were signed out here. Sign in with the new one.';
        }

        if (self::$ended !== null) {
            /* The key only: the flash saying why lives in the session too. */
            unset($_SESSION[self::KEY]);
            Session::regenerate();

            return false;
        }

        /* A session opened before this check existed adopts the current
           fingerprint instead of being thrown out mid-task. */
        if ($held === null) {
            $_SESSION[self::KEY]['pw'] = $now;
        }

        $_SESSION[self::KEY]['must_change'] = (int) $row['must_change_password'] === 1;

        return true;
    }

    /**
     * After the manager changes their own password: this session continues on
     * the new fingerprint, under a new id, while every other one ends.
     */
    public static function refreshCredentials(): void
    {
        if (!isset($_SESSION[self::KEY]['id'])) {
            return;
        }

        $row = Database::first(
            'SELECT password_hash, must_change_password FROM destination_managers WHERE id = ?',
            [(int) $_SESSION[self::KEY]['id']]
        );

        if ($row === null) {
            return;
        }

        Session::regenerate();
        Csrf::rotate();

        $_SESSION[self::KEY]['pw']          = Password::fingerprint($row['password_hash']);
        $_SESSION[self::KEY]['must_change'] = (int) $row['must_change_password'] === 1;
        self::$verified = true;
    }

    /** True while the manager is still on the password the office issued. */
    public static function mustChangePassword(): bool
    {
        return self::check() && !empty($_SESSION[self::KEY]['must_change']);
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
    public static function require(bool $passwordPage = false): void
    {
        if (!self::check()) {
            Session::put('_manager_intended', $_SERVER['REQUEST_URI'] ?? '');
            Session::flash('warning', self::$ended ?? 'Please sign in to continue.');
            redirect(base_url('/manager/login.php'));
        }

        /* THE TEMPORARY PASSWORD OPENS ONE DOOR. Until the manager replaces
           it, every manager page sends them to the page that does — the
           dashboard, the logbook and the reports are not reachable on a
           password the office also knows. */
        if (!$passwordPage && self::mustChangePassword()) {
            redirect(base_url('/manager/set-password.php'));
        }
    }

    /**
     * Gives a manager a sign-in with a new temporary password, and returns it.
     *
     * The only way a manager password is ever set by somebody other than the
     * manager, whether at account creation or a reset. Every call generates a
     * different password; it is stored only as an Argon2id hash; and the account
     * is flagged so the manager must replace it at first sign-in. The plain
     * password exists only in the return value — the caller shows it once and
     * lets it go.
     */
    public static function issueTemporaryPassword(int $managerId, string $username): string
    {
        $password = Password::temporary();

        Database::run(
            'UPDATE destination_managers
                SET username = ?, password_hash = ?, must_change_password = 1,
                    password_changed_at = NOW(), failed_attempts = 0, locked_until = NULL
              WHERE id = ?',
            [$username, self::hash($password), $managerId]
        );

        return $password;
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
