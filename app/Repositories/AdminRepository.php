<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Auth;
use App\Core\Database;
use App\Core\SmsGateway;

/**
 * Administrative accounts.
 *
 * There is no public registration anywhere in this system. Accounts exist only
 * because the installer created the first one or a Tourism Officer created the
 * rest — which is what "no public admin registration" in the brief requires in
 * practice rather than merely on paper.
 */
final class AdminRepository
{
    public static function all(): array
    {
        return Database::all(
            'SELECT id, full_name, username, email, role, is_active,
                    last_login_at, password_changed_at, locked_until, created_at
               FROM admins
              ORDER BY role, full_name'
        );
    }

    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM admins WHERE id = ?', [$id]);
    }

    public static function usernameTaken(string $username, ?int $ignoreId = null): bool
    {
        $sql = 'SELECT 1 FROM admins WHERE username = ?';
        $params = [$username];

        if ($ignoreId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $ignoreId;
        }

        return Database::scalar($sql, $params) !== null;
    }

    public static function emailTaken(string $email, ?int $ignoreId = null): bool
    {
        $sql = 'SELECT 1 FROM admins WHERE email = ?';
        $params = [$email];

        if ($ignoreId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $ignoreId;
        }

        return Database::scalar($sql, $params) !== null;
    }

    public static function create(array $data): int
    {
        return Database::insert(
            'INSERT INTO admins (full_name, username, email, password_hash, role, is_active)
             VALUES (?, ?, ?, ?, ?, 1)',
            [
                $data['full_name'],
                $data['username'],
                $data['email'],
                Auth::hash($data['password']),
                $data['role'],
            ]
        );
    }

    public static function updateProfile(int $id, array $data): void
    {
        /* The mobile number is what an urgent destination alert is texted to.
           Stored normalised so a number typed as "0917 123 4567" and the same
           number typed as "+639171234567" are one phone — the alert sender
           compares them, and two spellings would text somebody twice. */
        $mobile = isset($data['mobile_number']) ? trim((string) $data['mobile_number']) : '';
        $mobile = $mobile !== '' ? (SmsGateway::normalise($mobile) ?? $mobile) : null;

        Database::run(
            'UPDATE admins SET full_name = ?, email = ?, mobile_number = ?, alert_sms_opt_in = ? WHERE id = ?',
            [
                $data['full_name'],
                $data['email'],
                $mobile,
                !empty($data['alert_sms_opt_in']) ? 1 : 0,
                $id,
            ]
        );
    }

    /**
     * Sets a new password and stamps the change.
     *
     * The stamp is what lets the system tell an officer their account is still
     * using the password the installer printed to a terminal.
     */
    public static function changePassword(int $id, string $password): void
    {
        Database::run(
            'UPDATE admins SET password_hash = ?, password_changed_at = NOW(),
                    failed_attempts = 0, locked_until = NULL
              WHERE id = ?',
            [Auth::hash($password), $id]
        );
    }

    public static function setRole(int $id, string $role): void
    {
        Database::run('UPDATE admins SET role = ? WHERE id = ?', [$role, $id]);
    }

    public static function setActive(int $id, bool $active): void
    {
        Database::run('UPDATE admins SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
    }

    public static function unlock(int $id): void
    {
        Database::run('UPDATE admins SET failed_attempts = 0, locked_until = NULL WHERE id = ?', [$id]);
    }

    /** Officers still able to sign in — used to prevent locking everyone out. */
    public static function activeOfficerCount(): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM admins WHERE role = 'officer' AND is_active = 1"
        );
    }

    /** Accounts still using the password the installer generated. */
    public static function usingInstallerPassword(): array
    {
        return Database::all(
            'SELECT id, full_name, username FROM admins
              WHERE password_changed_at IS NULL AND is_active = 1'
        );
    }

    /**
     * Password strength rules.
     *
     * Length first, because it does more than character classes do, and the
     * requirement is stated to the user rather than hidden behind a rejection.
     */
    public static function passwordProblems(string $password): array
    {
        $problems = [];

        if (mb_strlen($password) < 10) {
            $problems[] = 'must be at least 10 characters';
        }
        if (!preg_match('/[A-Za-z]/', $password)) {
            $problems[] = 'must contain at least one letter';
        }
        if (!preg_match('/\d/', $password)) {
            $problems[] = 'must contain at least one number';
        }

        // A handful of passwords are guessed first in every attack.
        $obvious = ['password', '12345678', 'qwerty', 'admin', 'tampakan', 'toursync', 'letmein'];
        foreach ($obvious as $bad) {
            if (stripos($password, $bad) !== false && mb_strlen($password) < 16) {
                $problems[] = 'must not be built around an obvious word such as "' . $bad . '"';
                break;
            }
        }

        return $problems;
    }
}
