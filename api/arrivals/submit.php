<?php
declare(strict_types=1);

/**
 * =============================================================================
 *  TourSync — retired: the QR arrival submission endpoint
 * -----------------------------------------------------------------------------
 *  This used to accept a tourist's own logbook entry from the QR page. It no
 *  longer accepts anything.
 *
 *  WHY IT IS CLOSED RATHER THAN DELETED
 *
 *  The monthly Tourism Attraction Visitor Record filed with the DOT is built
 *  from ONE source: an arrival report a destination manager submitted and the
 *  Municipal Tourism Office approved. Every figure on that sheet has to answer
 *  "where did this come from" with a named manager, a date, and a logbook page.
 *
 *  This endpoint wrote arrivals with no report behind them. It is unreachable —
 *  logbook.php has been a 301 since Feature 1 moved the QR code to destination
 *  information — but an unreachable route that still writes to the official
 *  statistics is a route somebody finds. Left as a file so an old service
 *  worker cache or a bookmarked POST gets a clear refusal instead of a 404
 *  that reads like a bug, and so the reason is written down where the endpoint
 *  used to be.
 *
 *  Delete this file, sw.js, assets/js/arrival-queue.js, manifest.json and
 *  offline.html together once the office confirms no printed sign points at the
 *  old logbook URL.
 * =============================================================================
 */

require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

http_response_code(410);   // Gone: it existed, it is not coming back

echo json_encode([
    'ok'      => false,
    'error'   => 'The digital logbook has been retired.',
    'message' => 'Visitors now sign the paper logbook at the destination, and the '
               . 'destination manager submits the figures to the Municipal Tourism Office.',
]);
