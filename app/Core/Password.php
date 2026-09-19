<?php
declare(strict_types=1);

namespace App\Core;

/**
 * =============================================================================
 *  TourSync — the one password policy, for officers and managers alike.
 * -----------------------------------------------------------------------------
 *  There were two. The officer's lived in AdminRepository::passwordProblems()
 *  and refused "tampakan2026"; the manager's was written inline in
 *  manager/account.php and accepted it. Same system, same attacker, two rules —
 *  and the weaker one guarded the account that files official arrival figures.
 *
 *  Everything a password needs is here: the rule, the generator for a
 *  temporary one, and the fingerprint that tells a session its password has
 *  changed underneath it.
 * =============================================================================
 */
final class Password
{
    /**
     * What is wrong with a proposed password, as phrases that complete
     * "The new password …". Empty means acceptable.
     */
    public static function problems(string $password): array
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

    /**
     * A temporary password, different for every account.
     *
     * 12 characters from a 31-symbol alphabet — roughly 59 bits. The alphabet
     * omits the pairs that get misread off a screen and mistyped on a phone:
     * 0/O, 1/l/I. At least one digit is guaranteed, so the temporary password
     * passes the same rule the owner's replacement has to.
     */
    public static function temporary(): string
    {
        $alphabet = '23456789abcdefghjkmnpqrstuvwxyz';

        do {
            $password = '';
            for ($i = 0; $i < 12; $i++) {
                $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (self::problems($password) !== []);

        return $password;
    }

    /**
     * A short fingerprint of the stored hash, kept in the session at sign-in.
     *
     * When the password changes, or is revoked, the hash changes and every
     * session carrying the old fingerprint stops being honoured on its next
     * request. That is what "changing your password signs out the laptop you
     * left at the office" means in practice.
     *
     * A fingerprint of the HASH, never of the password — the session store is a
     * file on disk, and nothing that could help recover a password belongs in
     * it. Truncated, because it only has to tell two hashes apart.
     */
    public static function fingerprint(?string $hash): string
    {
        return substr(hash('sha256', 'toursync-session|' . (string) $hash), 0, 24);
    }
}
