<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Time-based one-time passwords — RFC 6238, and nothing more.
 *
 * WHY THIS IS HAND-WRITTEN. The project carries no Composer autoloader, by a
 * deployment decision that predates this file: it is uploaded to shared hosting
 * as plain PHP. TOTP is HMAC-SHA1 over a counter and a base32 alphabet — about
 * eighty lines — so writing it costs less than the dependency would, and it is
 * checked here against the published test vectors rather than trusted.
 *
 * This class knows the algorithm and nothing about the application: no database,
 * no session, no accounts. TwoFactor holds that. Splitting them is what lets the
 * arithmetic be tested against RFC 6238 without a database in the room.
 */
final class Totp
{
    /** The step every authenticator app assumes unless told otherwise. */
    public const PERIOD = 30;
    public const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A fresh shared secret, base32 as the apps expect it.
     *
     * 20 bytes because that is SHA1's block-matched key length and what RFC 4226
     * recommends; longer buys nothing here and makes the manual-entry string
     * harder to type on a phone.
     */
    public static function secret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * The otpauth:// URI an authenticator reads out of a QR code.
     *
     * The issuer appears twice on purpose — once as the label prefix and once as
     * a parameter. Older apps read one, newer ones read the other, and an
     * account that shows up as a bare username in a list of thirty is a support
     * call the office should not have to take.
     */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);

        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** The code for one moment in time. */
    public static function at(string $secret, ?int $timestamp = null): string
    {
        return self::forStep($secret, intdiv($timestamp ?? time(), self::PERIOD));
    }

    /**
     * Which time step a code belongs to, or null when it belongs to none.
     *
     * RETURNING THE STEP RATHER THAN true IS THE POINT. A correct code stays
     * correct for its whole window, so a code read over somebody's shoulder can
     * be replayed within the same half-minute. The caller records the step it
     * accepted and refuses anything at or below it — which it cannot do if all
     * it is told is "yes".
     *
     * @param int $window Steps either side to accept, for clock drift.
     */
    public static function match(string $secret, string $code, int $window = 1): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $now = intdiv(time(), self::PERIOD);

        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $now + $offset;

            /* hash_equals, so a wrong code cannot be narrowed down digit by
               digit from how long the comparison took. */
            if (hash_equals(self::forStep($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /** The current step number, for a caller recording what it accepted. */
    public static function step(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? time(), self::PERIOD);
    }

    private static function forStep(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);

        if ($key === '') {
            return str_repeat('0', self::DIGITS);
        }

        /* The counter as 8 bytes, big-endian. pack('J') needs 64-bit PHP, which
           every supported version on Windows and Linux now is. */
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);

        /* Dynamic truncation, RFC 4226 §5.3: the low nibble of the last byte
           picks where to read four bytes from, and the top bit is masked off so
           the result is positive on every platform. */
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $number = ((ord($hash[$offset]) & 0x7F) << 24)
                | ((ord($hash[$offset + 1]) & 0xFF) << 16)
                | ((ord($hash[$offset + 2]) & 0xFF) << 8)
                | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($number % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    public static function base32Decode(string $secret): string
    {
        /* Spaces are how a manual key is printed for typing, and lower case is
           how a phone keyboard offers it. Both are accepted; padding is not
           part of what these apps exchange. */
        $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret) ?? '');

        if ($secret === '') {
            return '';
        }

        $bits = '';

        foreach (str_split($secret) as $char) {
            $index = strpos(self::ALPHABET, $char);

            if ($index === false) {
                return '';
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
