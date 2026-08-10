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
    /** The URL a scanned code opens. */
    public static function url(string $token): string
    {
        return base_url('/d/' . $token);
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
