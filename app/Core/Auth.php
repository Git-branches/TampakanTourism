<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Administrative authentication and role gates.
 *
 * There is no registration method here, and that is deliberate: TourSync has
 * no public sign-up path. Accounts are created by database/install.php or by
 * an officer inside the admin area.
 */
final class Auth
{
    private const KEY = '_admin';

    /* A sign-in that has passed the password and is waiting at the code prompt.
       DELIBERATELY NOT self::KEY: check(), user(), id() and every Auth::require()
       in the application read that one key, so a half-finished sign-in stored
       there would be a full session. Kept apart, it can reach nothing. */
    private const PENDING = '_admin_pending_2fa';

    private static int $maxAttempts = 5;
    private static int $lockoutMinutes = 15;

    public static function configure(int $maxAttempts, int $lockoutMinutes): void
    {
        self::$maxAttempts = $maxAttempts;
        self::$lockoutMinutes = $lockoutMinutes;
    }

    /**
     * Verifies credentials and starts an administrative session.
     *
     * @return string|null Error message for the user, or null on success.
     */
    public static function attempt(string $username, string $password): ?string
    {
        $admin = Database::first(
            'SELECT * FROM admins WHERE username = ? OR email = ? LIMIT 1',
            [$username, $username]
        );

        // One generic message for every failure path. Saying "no such user"
        // would let anyone enumerate valid account names.
        $generic = 'Incorrect username or password.';

        if ($admin === null) {
            // Spend roughly the same time as a real verification would, so
            // response timing does not reveal whether the account exists.
            password_verify($password, '$argon2id$v=19$m=65536,t=4,p=1$ZmFrZXNhbHR2YWx1ZQ$0000000000000000000000000000000000000000000');
            return $generic;
        }

        if ((int) $admin['is_active'] !== 1) {
            return 'This account has been deactivated. Contact the Tourism Officer.';
        }

        if ($admin['locked_until'] !== null && strtotime($admin['locked_until']) > time()) {
            $minutes = (int) ceil((strtotime($admin['locked_until']) - time()) / 60);
            return "Too many failed attempts. Try again in {$minutes} minute(s).";
        }

        if (!password_verify($password, $admin['password_hash'])) {
            self::recordFailure($admin);
            return $generic;
        }

        // Upgrade the stored hash if PHP's default parameters have changed.
        if (password_needs_rehash($admin['password_hash'], PASSWORD_ARGON2ID)) {
            Database::run(
                'UPDATE admins SET password_hash = ? WHERE id = ?',
                [password_hash($password, PASSWORD_ARGON2ID), $admin['id']]
            );
        }

        /* THE PASSWORD WAS RIGHT, WHICH IS NOT THE SAME AS BEING SIGNED IN.
           With a second step switched on, the session that exists after this
           point can do nothing but present a code: no admin key is written, so
           Auth::check() is false and every guarded page still refuses. */
        if (TwoFactor::isEnabled((int) $admin['id'])) {
            Session::regenerate();      // the fixation defence belongs here too
            Csrf::rotate();

            $_SESSION[self::PENDING] = [
                'id'       => (int) $admin['id'],
                'since'    => time(),
                'attempts' => 0,
            ];

            Database::run('UPDATE admins SET failed_attempts = 0, locked_until = NULL WHERE id = ?', [$admin['id']]);

            return null;
        }

        self::establish($admin);
        return null;
    }

    // -------------------------------------------------------------------------
    //  The second step
    // -------------------------------------------------------------------------

    /** True when a password has been accepted and only the code is missing. */
    public static function awaitingTwoFactor(): bool
    {
        return self::pending() !== null;
    }

    /**
     * The half-finished sign-in, or null when there is none or it has gone stale.
     *
     * Expiring it matters: without a clock, a password typed on a shared machine
     * leaves a prompt sitting there that anyone who finds the browser can finish
     * as soon as they have the phone.
     */
    private static function pending(): ?array
    {
        $pending = $_SESSION[self::PENDING] ?? null;

        if (!is_array($pending) || !isset($pending['id'], $pending['since'])) {
            return null;
        }

        if ((time() - (int) $pending['since']) > TwoFactor::PENDING_MINUTES * 60) {
            unset($_SESSION[self::PENDING]);
            return null;
        }

        return $pending;
    }

    /** The name to greet at the prompt. Never the account's other details. */
    public static function pendingName(): string
    {
        $pending = self::pending();

        if ($pending === null) {
            return '';
        }

        return (string) (Database::scalar('SELECT full_name FROM admins WHERE id = ?', [$pending['id']]) ?? '');
    }

    /**
     * Finishes a sign-in with a one-time or recovery code.
     *
     * @return string|null Error message for the user, or null once signed in.
     */
    public static function completeTwoFactor(string $code): ?string
    {
        $pending = self::pending();

        if ($pending === null) {
            return 'That took too long. Please sign in again.';
        }

        $adminId = (int) $pending['id'];

        if (TwoFactor::verify($adminId, $code)) {
            unset($_SESSION[self::PENDING]);

            $admin = Database::first('SELECT * FROM admins WHERE id = ? AND is_active = 1', [$adminId]);

            if ($admin === null) {
                return 'That account is no longer active.';
            }

            self::establish($admin);
            return null;
        }

        /* A run of wrong codes ends the attempt rather than allowing an endless
           guess at six digits — the password is already spent by this point. */
        $attempts = (int) ($pending['attempts'] ?? 0) + 1;
        $_SESSION[self::PENDING]['attempts'] = $attempts;

        ActivityLog::record('auth.2fa.failed', 'admin', $adminId,
            "Wrong two-step code ({$attempts} of " . TwoFactor::MAX_ATTEMPTS . ')', $adminId);

        if ($attempts >= TwoFactor::MAX_ATTEMPTS) {
            unset($_SESSION[self::PENDING]);

            return 'Too many wrong codes. Please sign in again.';
        }

        return 'That code is not right. Check the app and try the current six digits.';
    }

    public static function abandonTwoFactor(): void
    {
        unset($_SESSION[self::PENDING]);
    }

    private static function recordFailure(array $admin): void
    {
        $attempts = (int) $admin['failed_attempts'] + 1;

        if ($attempts >= self::$maxAttempts) {
            Database::run(
                'UPDATE admins SET failed_attempts = ?, locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?',
                [$attempts, self::$lockoutMinutes, $admin['id']]
            );
            ActivityLog::record('auth.locked', 'admin', (int) $admin['id'],
                "Account locked after {$attempts} failed attempts", (int) $admin['id']);
        } else {
            Database::run('UPDATE admins SET failed_attempts = ? WHERE id = ?', [$attempts, $admin['id']]);

            /* EVERY FAILURE, not only the one that trips the lock. Five spread
               over a week is somebody mistyping; five in a minute is somebody
               guessing, and the difference is only visible if each one is
               written down. The password is never part of the record. */
            ActivityLog::record('auth.failed', 'admin', (int) $admin['id'],
                "Failed sign-in ({$attempts} in a row)", (int) $admin['id']);
        }
    }

    private static function establish(array $admin): void
    {
        // A new session ID at the moment privileges change is what defeats
        // session fixation: any ID an attacker planted becomes worthless.
        Session::regenerate();
        Csrf::rotate();

        $_SESSION[self::KEY] = [
            'id'        => (int) $admin['id'],
            'full_name' => $admin['full_name'],
            'username'  => $admin['username'],
            'role'      => $admin['role'],
        ];

        Database::run(
            'UPDATE admins SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?',
            [$admin['id']]
        );

        ActivityLog::record('auth.login', 'admin', (int) $admin['id'], 'Signed in', (int) $admin['id']);
    }

    public static function logout(): void
    {
        if (self::check()) {
            ActivityLog::record('auth.logout', 'admin', self::id(), 'Signed out');
        }

        /* Including a sign-in that never finished: leaving the pending key
           behind would let the next person at the machine walk back into the
           code prompt with the password already accepted. */
        unset($_SESSION[self::PENDING]);
        Session::destroy();
    }

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

    public static function role(): ?string
    {
        return $_SESSION[self::KEY]['role'] ?? null;
    }

    public static function isOfficer(): bool
    {
        return self::role() === 'officer';
    }

    /**
     * The actual access control. Every admin page calls this on its first
     * line. Hiding a menu item is presentation; this is the gate.
     */
    public static function require(?string $role = null): void
    {
        if (!self::check()) {
            $target = $_SERVER['REQUEST_URI'] ?? '/admin/dashboard.php';
            Session::put('_intended', $target);
            Session::flash('warning', 'Please sign in to continue.');
            redirect(base_url('/admin/login.php'));
        }

        if ($role === 'officer' && !self::isOfficer()) {
            http_response_code(403);
            Session::flash('danger', 'That area is restricted to the Tourism Officer.');
            redirect(base_url('/admin/dashboard.php'));
        }
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }
}
