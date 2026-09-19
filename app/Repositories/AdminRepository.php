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

    /**
     * A new account's password was typed by the officer who created it, so it
     * is temporary by definition: must_change_password is set and the owner
     * replaces it before reaching anything else.
     */
    public static function create(array $data): int
    {
        return Database::insert(
            'INSERT INTO admins (full_name, username, email, password_hash, role, is_active, must_change_password)
             VALUES (?, ?, ?, ?, ?, 1, 1)',
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
                    must_change_password = 0, failed_attempts = 0, locked_until = NULL
              WHERE id = ?',
            [Auth::hash($password), $id]
        );
    }

    /**
     * Another officer set this password, so somebody other than the owner knows
     * it. Stored the same way as any password — hashed — and flagged so the
     * owner has to replace it at their next sign-in.
     */
    public static function resetPassword(int $id, string $password): void
    {
        Database::run(
            'UPDATE admins SET password_hash = ?, password_changed_at = NOW(), must_change_password = 1,
                    failed_attempts = 0, locked_until = NULL
              WHERE id = ?',
            [Auth::hash($password), $id]
        );
    }

    /**
     * The characters a username may hold, and why.
     *
     * Lowercase only, because Auth::attempt() matches with `username = ?` and
     * MySQL's collation would let "Officer" sign in as "officer" here while a
     * case-sensitive column elsewhere would not. One spelling, decided at the
     * point of entry, rather than a rule that depends on a collation setting.
     */
    public const USERNAME_PATTERN = '/^[a-z0-9._-]+$/';

    /**
     * Anything wrong with a proposed username, as sentences.
     *
     * Shared by the create form and by an officer renaming their own account,
     * so the two cannot drift into different rules for the same column.
     *
     * @return array<int, string>
     */
    public static function usernameProblems(string $username, ?int $ignoreId = null): array
    {
        $problems = [];
        $length   = mb_strlen($username);

        if ($length < 3) {
            $problems[] = 'must be at least 3 characters';
        } elseif ($length > 60) {
            $problems[] = 'must be 60 characters or fewer';
        }

        if ($username !== '' && !preg_match(self::USERNAME_PATTERN, $username)) {
            $problems[] = 'may use only lowercase letters, numbers, and . _ -';
        }

        if ($username !== '' && self::usernameTaken($username, $ignoreId)) {
            $problems[] = 'is already taken by another account';
        }

        return $problems;
    }

    /**
     * Renames the account somebody signs in with.
     *
     * Nothing else moves: the id is what every other table references, so a
     * rename cannot orphan a report, an audit entry or an uploaded file. The
     * caller is responsible for proving the person knows the current password
     * and for refreshing the session copy.
     */
    public static function changeUsername(int $id, string $username): void
    {
        Database::run('UPDATE admins SET username = ? WHERE id = ?', [$username, $id]);
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
     * Moved to \App\Core\Password::problems() so the managers are held to the
     * same rule; kept under this name because the officer screens call it.
     */
    public static function passwordProblems(string $password): array
    {
        return \App\Core\Password::problems($password);
    }
}
