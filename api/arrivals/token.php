<?php
declare(strict_types=1);

/**
 * =============================================================================
 *  TourSync — CSRF token for a synchronising device               Feature 2
 * -----------------------------------------------------------------------------
 *  A visit captured with no signal carries the CSRF token that was baked into
 *  the form when it rendered. By the time the phone finds a signal that token
 *  may be hours old and its session long gone, so replaying it would be
 *  rejected — and the arrival would be lost to a security check that was never
 *  aimed at it.
 *
 *  The alternative most systems reach for is to exempt the sync endpoint from
 *  CSRF entirely. This one does not. The device asks for a current token
 *  instead, which keeps the guard exactly as strong as it is for a browser: a
 *  request must still prove it holds a token this server issued to this
 *  session. Nothing here weakens the check; it only refreshes it.
 *
 *  Reaching this endpoint requires the network to be back, which is the same
 *  condition that makes syncing possible at all — so there is no case where a
 *  device can post but cannot first collect a token.
 * =============================================================================
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Csrf;

header('Content-Type: application/json; charset=utf-8');

/* Never cached. A stale token from a proxy is a token from another session,
   and every request holding it would be rejected. */
header('Cache-Control: no-store, max-age=0');

echo json_encode(['token' => Csrf::token()], JSON_UNESCAPED_SLASHES);
