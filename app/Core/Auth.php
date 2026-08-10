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

        self::establish($admin);
        return null;
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
