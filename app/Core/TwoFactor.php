<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Two-step verification for Tourism Office accounts.
 *
 * Totp does the arithmetic; this decides what the application does with it —
 * where the secret lives, how a code is spent, and what happens when the phone
 * is lost.
 *
 * THE SECRET IS ENCRYPTED AT REST, and that is not ceremony here. A dump of this
 * database has already been committed to a public repository once. A password
 * column survives that because it holds argon2id hashes; a TOTP secret does not
 * — it is the key itself, and anyone holding it can generate codes forever. So
 * it is sealed with AES-256-GCM under a key derived from the installation's own
 * salt, which lives in app/config/config.php and has never been committed. A
 * stolen dump then yields ciphertext, and the office rotates one file instead of
 * re-enrolling every officer.
 *
 * RECOVERY CODES ARE HASHED like passwords, for the same reason and with the
 * same algorithm. They are shown once, at enrolment, and never again.
 */
final class TwoFactor
{
    private const ISSUER = 'TourSync — Tampakan Tourism Office';
    private const RECOVERY_COUNT = 10;

    /** Minutes a half-finished sign-in may sit at the code prompt. */
    public const PENDING_MINUTES = 5;

    /** Wrong codes allowed at the prompt before the attempt is abandoned. */
    public const MAX_ATTEMPTS = 5;

    // -------------------------------------------------------------------------
    //  State
    // -------------------------------------------------------------------------

    public static function isEnabled(int $adminId): bool
    {
        return Database::scalar(
            'SELECT totp_confirmed_at FROM admins WHERE id = ?',
            [$adminId]
        ) !== null;
    }

    /** What the account page shows: on or off, since when, and codes left. */
    public static function status(int $adminId): array
    {
        $row = Database::first(
            'SELECT totp_confirmed_at FROM admins WHERE id = ?',
            [$adminId]
        );

        return [
            'enabled'      => ($row['totp_confirmed_at'] ?? null) !== null,
            'confirmed_at' => $row['totp_confirmed_at'] ?? null,
            'codes_left'   => (int) Database::scalar(
                'SELECT COUNT(*) FROM admin_recovery_codes WHERE admin_id = ? AND used_at IS NULL',
                [$adminId]
            ),
        ];
    }

    // -------------------------------------------------------------------------
    //  Enrolment
    // -------------------------------------------------------------------------

    /**
     * A secret to show as a QR code. NOT saved yet — an account is only put
     * behind a second step once the officer has proved the phone can produce a
     * code from it, otherwise a mistyped scan locks them out of their own
     * account at the next sign-in.
     */
    public static function beginSetup(): string
    {
        return Totp::secret();
    }

    public static function uriFor(string $secret, string $username): string
    {
        return Totp::uri($secret, $username, self::ISSUER);
    }

    /**
     * Turns it on, and returns the recovery codes to show once.
     *
     * @return array<int, string>
     */
    public static function enable(int $adminId, string $secret): array
    {
        Database::run(
            'UPDATE admins SET totp_secret = ?, totp_confirmed_at = NOW(), totp_last_step = NULL WHERE id = ?',
            [self::seal($secret), $adminId]
        );

        ActivityLog::record('auth.2fa.enabled', 'admin', $adminId,
            'Two-step verification switched on', $adminId);

        return self::issueRecoveryCodes($adminId);
    }

    public static function disable(int $adminId): void
    {
        Database::run(
            'UPDATE admins SET totp_secret = NULL, totp_confirmed_at = NULL, totp_last_step = NULL WHERE id = ?',
            [$adminId]
        );

        Database::run('DELETE FROM admin_recovery_codes WHERE admin_id = ?', [$adminId]);

        ActivityLog::record('auth.2fa.disabled', 'admin', $adminId,
            'Two-step verification switched off', $adminId);
    }

    /**
     * Ten fresh codes, replacing whatever was there.
     *
     * @return array<int, string>
     */
    public static function issueRecoveryCodes(int $adminId): array
    {
        Database::run('DELETE FROM admin_recovery_codes WHERE admin_id = ?', [$adminId]);

        $codes = [];

        for ($i = 0; $i < self::RECOVERY_COUNT; $i++) {
            /* Two groups of five from an alphabet with no 0/O or 1/I/L in it:
               these get written on paper and read back under pressure. */
            $code = self::readableCode();
            $codes[] = $code;

            Database::run(
                'INSERT INTO admin_recovery_codes (admin_id, code_hash) VALUES (?, ?)',
                [$adminId, password_hash(self::normalise($code), PASSWORD_ARGON2ID)]
            );
        }

        ActivityLog::record('auth.2fa.codes', 'admin', $adminId,
            'New recovery codes issued', $adminId);

        return $codes;
    }

    // -------------------------------------------------------------------------
    //  Spending a code
    // -------------------------------------------------------------------------

    /**
     * Accepts a six-digit code or a recovery code, and spends it.
     *
     * @return bool true when the officer may be let in.
     */
    public static function verify(int $adminId, string $supplied): bool
    {
        $supplied = trim($supplied);

        if ($supplied === '') {
            return false;
        }

        $row = Database::first(
            'SELECT totp_secret, totp_last_step FROM admins WHERE id = ?',
            [$adminId]
        );

        if ($row === null || ($row['totp_secret'] ?? null) === null) {
            return false;
        }

        $secret = self::open((string) $row['totp_secret']);

        if ($secret !== '') {
            $step = Totp::match($secret, $supplied);

            if ($step !== null) {
                /* ONE CODE, ONE USE. Without this the same six digits work for
                   the rest of their thirty-second window — long enough for a
                   code read over a shoulder, or lifted from a screen share, to
                   be typed somewhere else. */
                if ($row['totp_last_step'] !== null && $step <= (int) $row['totp_last_step']) {
                    ActivityLog::record('auth.2fa.replay', 'admin', $adminId,
                        'A one-time code was presented twice', $adminId);

                    return false;
                }

                Database::run('UPDATE admins SET totp_last_step = ? WHERE id = ?', [$step, $adminId]);

                return true;
            }
        }

        return self::spendRecoveryCode($adminId, $supplied);
    }

    private static function spendRecoveryCode(int $adminId, string $supplied): bool
    {
        $candidate = self::normalise($supplied);

        if (strlen($candidate) < 8) {
            return false;
        }

        foreach (Database::all(
            'SELECT id, code_hash FROM admin_recovery_codes WHERE admin_id = ? AND used_at IS NULL',
            [$adminId]
        ) as $row) {
            if (password_verify($candidate, (string) $row['code_hash'])) {
                Database::run('UPDATE admin_recovery_codes SET used_at = NOW() WHERE id = ?', [$row['id']]);

                $left = (int) Database::scalar(
                    'SELECT COUNT(*) FROM admin_recovery_codes WHERE admin_id = ? AND used_at IS NULL',
                    [$adminId]
                );

                ActivityLog::record('auth.2fa.recovery', 'admin', $adminId,
                    "Signed in with a recovery code ({$left} left)", $adminId);

                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    //  Sealing the secret
    // -------------------------------------------------------------------------

    private static function key(): string
    {
        /* A dedicated key if the installation sets one, otherwise derived from
           the salt it already has. HKDF with its own info string, so the two
           uses of that salt cannot produce the same bytes. */
        $material = (string) config('security.totp_key', '');

        if ($material === '') {
            $material = (string) config('security.device_salt', '');
        }

        return hash_hkdf('sha256', $material === '' ? 'toursync-fallback' : $material, 32, 'toursync-totp-v1');
    }

    private static function seal(string $secret): string
    {
        $iv  = random_bytes(12);
        $tag = '';

        $cipher = openssl_encrypt($secret, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipher === false) {
            /* Refusing is the only safe answer: storing it in the clear because
               the cipher was unavailable is the failure this exists to avoid. */
            throw new \RuntimeException('Could not seal the two-step secret.');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    private static function open(string $stored): string
    {
        $raw = base64_decode($stored, true);

        if ($raw === false || strlen($raw) < 29) {
            return '';
        }

        $plain = openssl_decrypt(
            substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA,
            substr($raw, 0, 12), substr($raw, 12, 16)
        );

        return $plain === false ? '' : $plain;
    }

    // -------------------------------------------------------------------------

    private static function readableCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out = '';

        for ($i = 0; $i < 10; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];

            if ($i === 4) {
                $out .= '-';
            }
        }

        return $out;
    }

    /** So "abcde-fghij", "ABCDE FGHIJ" and "ABCDEFGHIJ" are the same code. */
    private static function normalise(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }
}
