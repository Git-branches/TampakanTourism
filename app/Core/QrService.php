<?php
declare(strict_types=1);

namespace App\Core;

use App\Repositories\DestinationRepository;

/**
 * QR code handling for destination signage.
 *
 * Two decisions worth stating, because both were choices rather than defaults:
 *
 * 1. The code encodes an opaque 32-character token, never the destination's
 *    numeric id. A printed sign is a public artefact — anyone can read it with
 *    a phone — so the identifier it carries must be non-guessable. Otherwise a
 *    person who scanned one destination could derive the URL of every other
 *    and submit arrivals for sites they never visited.
 *
 * 2. The image itself is drawn in the browser from that token, not generated
 *    server-side. That keeps TourSync free of a Composer dependency (the brief
 *    requires plain cPanel deployment) and, more importantly, means no
 *    destination token is ever sent to a third-party QR service.
 */
final class QrService
{
    /**
     * The URL a scanned code opens.
     *
     * NOT base_url(). That helper is configured 'auto', so it answers with
     * whatever hostname the current request arrived on — which is correct for
     * every link on a rendered page and catastrophic here.
     *
     * A QR code is not a link on a page. It is printed, laminated, and bolted
     * to a post at a waterfall. Whatever hostname it captured at the moment the
     * poster was generated is the hostname it will carry for years. An officer
     * who generated posters while working at http://localhost produced signage
     * that resolves to the visitor's own phone — permanently dead, and dead in
     * a way nobody discovers until a tourist is standing in front of it.
     *
     * So the address is pinned: a public_url setting the office states once,
     * falling back to base_url() only when nothing has been set. Call
     * isPublishable() before printing anything.
     */
    public static function url(string $token): string
    {
        return self::publicBase() . '/d/' . $token;
    }

    /** The address printed codes point at, with no trailing slash. */
    public static function publicBase(): string
    {
        $configured = trim((string) setting('public_url', ''));

        return $configured !== ''
            ? rtrim($configured, '/')
            : rtrim(base_url('/'), '/');
    }

    /**
     * Can a code generated right now be printed at all?
     *
     * This blocks only the addresses that are meaningless to everyone: loopback
     * resolves to whatever device is scanning, so a poster carrying it opens
     * that phone's own web server — never the tourism site, for anybody, ever.
     * There is no situation in which printing that is the right thing to do.
     *
     * A LAN address is a different case and is deliberately NOT blocked here.
     * It is wrong on a sign at a waterfall and right on a test print during a
     * rehearsal, and only the officer knows which one they are doing. They get
     * warned instead — see warning().
     */
    public static function isPublishable(): bool
    {
        $host = strtolower((string) parse_url(self::publicBase(), PHP_URL_HOST));

        return $host !== ''
            && !in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true);
    }

    /** Why printing is blocked, phrased for the officer who must fix it. */
    public static function unpublishableReason(): string
    {
        $host = (string) parse_url(self::publicBase(), PHP_URL_HOST);

        if ($host === '') {
            return 'No public website address has been set.';
        }

        return 'The address is "' . $host . '", which on a visitor\'s phone means that phone itself. '
             . 'A printed code carrying it opens nothing, for anyone.';
    }

    /**
     * A caution that does not stop the officer, or '' when there is nothing to
     * say.
     *
     * The distinction from isPublishable() is who the address fails for. A
     * loopback address fails for everybody and is blocked. A LAN address works
     * perfectly for anyone on the office WiFi and fails for a tourist on mobile
     * data — which makes it exactly right for a rehearsal and exactly wrong for
     * signage. Blocking it would stop the office testing the system at all.
     */
    public static function warning(): string
    {
        $host = strtolower((string) parse_url(self::publicBase(), PHP_URL_HOST));

        if ($host === '' || !self::isPublishable()) {
            return '';
        }

        $isPrivate = preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|169\.254\.)/', $host) === 1
            || str_ends_with($host, '.local');

        if (!$isPrivate) {
            return '';
        }

        return 'These codes point at "' . $host . '", an address on your local network. '
             . 'That is fine for testing on office WiFi, but a visitor on mobile data cannot reach it — '
             . 'do not mount these on real signage.';
    }

    /**
     * Issues a fresh token, invalidating every printed sign for this
     * destination.
     *
     * That invalidation is the point: it is the remedy when signage is
     * defaced, stolen, or replaced with a sticker pointing somewhere else.
     * The version number is stamped onto each arrival record, so the office
     * can tell afterwards which generation of signage a visitor scanned.
     */
    public static function rotate(int $destinationId): string
    {
        $token = DestinationRepository::newToken();

        Database::run(
            'UPDATE destinations
                SET qr_token = ?, qr_version = qr_version + 1, qr_rotated_at = NOW()
              WHERE id = ?',
            [$token, $destinationId]
        );

        return $token;
    }

    /**
     * Poster copy shown under the code.
     *
     * Written for someone standing at a trailhead with one bar of signal, so
     * it says what to do and what happens next, in that order.
     */
    public static function posterInstructions(): array
    {
        return [
            'Open your phone camera and point it at the code.',
            'Tap the link that appears on screen.',
            'Read about this destination, then tap "Log My Visit".',
            'Fill in the short form and submit. That is all.',
        ];
    }
}
