<?php
declare(strict_types=1);

/**
 * =============================================================================
 *  TourSync — retired: the digital logbook                          Feature 1
 * -----------------------------------------------------------------------------
 *  This page used to be the form a tourist filled in after scanning the sign.
 *  It no longer exists as a form.
 *
 *  The Municipal Tourism Office keeps a PAPER logbook at each destination's
 *  fill-up station, and that is where a visitor writes their name. The QR code
 *  now opens the information they actually need while standing there —
 *  emergency hotlines, spot information, cultural heritage — and the destination
 *  manager transcribes the paper page into the system afterwards (Feature 2).
 *
 *  WHY THIS FILE STILL EXISTS RATHER THAN BEING DELETED
 *
 *  Signs printed before the change may carry a URL ending in logbook.php, and a
 *  sign is laminated to a post at a waterfall for years. A visitor who scans one
 *  should reach the new page, not a 404 — the sign cannot be corrected as
 *  quickly as the software. So this forwards, permanently, and carries the
 *  token through.
 *
 *  Everything that supported the old form — sw.js, assets/js/arrival-queue.js,
 *  manifest.json, offline.html and the mode=sync path in
 *  api/arrivals/submit.php — is now unreachable from any page. It is inert
 *  rather than removed, and should be deleted deliberately once the office
 *  confirms no printed sign points here.
 * =============================================================================
 */

require_once __DIR__ . '/bootstrap.php';

use App\Repositories\DestinationRepository;

/* Both spellings: the old page took ?token=, and some hand-typed links used ?t=. */
$token = (string) ($_GET['token'] ?? $_GET['t'] ?? '');

$destination = $token !== '' ? DestinationRepository::findByQrToken($token) : null;

/* 301, not 302. A permanent move is what this is, and it lets a browser that
   has scanned the old sign once skip the hop next time. */
if ($destination !== null) {
    redirect(base_url('/d/' . $token), 301);
}

/* No token, or one that no longer resolves. The destination list is the honest
   landing place — better than a form that no longer exists. */
redirect(destinations_url(), 301);
