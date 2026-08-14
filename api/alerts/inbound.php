<?php
declare(strict_types=1);

/**
 * =============================================================================
 *  TourSync — inbound SMS: a manager reporting from the destination  Feature 3
 * -----------------------------------------------------------------------------
 *  The endpoint an SMS provider posts to when a manager texts the office
 *  number. A landslide at Jadas Falls reaches the officer's screen in the time
 *  it takes the provider to make one HTTP call.
 *
 *  THIS IS A PUBLIC URL THAT WRITES TO A MUNICIPAL SYSTEM. Everything below
 *  exists because of that sentence.
 *
 *    shared secret     the office sets it; the endpoint refuses EVERYTHING
 *                      until it is set, because an inbound webhook with no
 *                      secret is an open write endpoint on the internet
 *
 *    constant time     the secret is compared with hash_equals, so the
 *                      comparison cannot be timed character by character
 *
 *    rate limit        by IP, so a leaked secret is a nuisance and not a way to
 *                      fill the office's inbox with ten thousand alerts
 *
 *    sender matching   the number is matched against active managers. It is
 *                      identification, NOT authentication — numbers can be
 *                      spoofed — so the raw number is kept on every alert and
 *                      an unrecognised sender is quarantined rather than
 *                      trusted
 *
 *    idempotency       providers retry after a timeout. The provider's message
 *                      id is stored under a unique key, so the same text cannot
 *                      raise two alerts
 *
 *  WHY AN UNKNOWN NUMBER IS KEPT AND NOT DROPPED
 *
 *  The one time it matters, it may be a bystander reporting a drowning from
 *  their own phone. It is recorded with no destination attached and shown to
 *  the office as unverified, which is the honest description of what it is.
 * =============================================================================
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Repositories\AlertRepository as Alerts;

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

/** One shape of reply, so a provider never has to guess. */
$respond = static function (int $code, bool $ok, string $message, array $extra = []): never {
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'message' => $message] + $extra);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $respond(405, false, 'Send this as a POST request.');
}

// -----------------------------------------------------------------------------
// 1. The secret
// -----------------------------------------------------------------------------

$expected = (string) (setting('sms_inbound_secret', '') ?? '');

if ($expected === '') {
    /* Not "unauthorised" — the feature is switched off. Said differently so an
       officer reading the provider's error log can tell the two apart. */
    Alerts::logInbound([
        'from_number' => (string) ($_POST['from'] ?? ''),
        'body'        => (string) ($_POST['message'] ?? ''),
        'outcome'     => 'rejected',
        'note'        => 'inbound secret not configured',
    ]);

    $respond(503, false, 'Inbound SMS is not configured on this system.');
}

/* Accepted from a header or the body: providers differ, and the ones that only
   post form fields cannot set a header. */
$supplied = (string) ($_SERVER['HTTP_X_TOURSYNC_SECRET'] ?? $_POST['secret'] ?? '');

if (!hash_equals($expected, $supplied)) {
    Alerts::logInbound([
        'from_number' => (string) ($_POST['from'] ?? ''),
        'body'        => (string) ($_POST['message'] ?? ''),
        'outcome'     => 'rejected',
        'note'        => 'bad or missing secret',
    ]);

    ActivityLog::record('alert.inbound_rejected', 'sms_inbox', null,
        'Inbound SMS rejected: bad secret from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

    $respond(401, false, 'Unauthorised.');
}

// -----------------------------------------------------------------------------
// 2. Rate limit
// -----------------------------------------------------------------------------

$limitKey = 'sms-inbound:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

if (!RateLimiter::allow($limitKey, 60, 60)) {
    Alerts::logInbound([
        'from_number' => (string) ($_POST['from'] ?? ''),
        'body'        => (string) ($_POST['message'] ?? ''),
        'outcome'     => 'rejected',
        'note'        => 'rate limited',
    ]);

    $respond(429, false, 'Too many messages. Slow down.');
}

// -----------------------------------------------------------------------------
// 3. The message
// -----------------------------------------------------------------------------

/* Field names vary by provider. These cover Semaphore, Twilio and the common
   Android gateway apps without needing a driver per vendor. */
$from = trim((string) ($_POST['from'] ?? $_POST['From'] ?? $_POST['sender'] ?? $_POST['msisdn'] ?? ''));
$body = trim((string) ($_POST['message'] ?? $_POST['Body'] ?? $_POST['text'] ?? $_POST['content'] ?? ''));
$ref  = trim((string) ($_POST['message_id'] ?? $_POST['MessageSid'] ?? $_POST['id'] ?? ''));

if ($body === '') {
    Alerts::logInbound([
        'from_number' => $from,
        'body'        => '',
        'provider_ref' => $ref !== '' ? $ref : null,
        'outcome'     => 'empty',
        'note'        => 'no message body',
    ]);

    $respond(200, true, 'Empty message ignored.');
}

/* Idempotency. A provider that times out and retries must not raise the alert
   twice — the office would dispatch to the same landslide two people. */
if ($ref !== '' && Alerts::seenProviderRef($ref)) {
    $respond(200, true, 'Already received.');
}

// -----------------------------------------------------------------------------
// 4. Who sent it
// -----------------------------------------------------------------------------

$manager = $from !== '' ? Alerts::managerForNumber($from) : null;

$parsed = Alerts::classify($body);

if ($manager === null) {
    /* Unrecognised sender. Recorded, flagged, and given to the office to judge
       — because it might be a bystander at an emergency, and because silently
       dropping it means nobody ever learns it arrived. */
    $alertId = Alerts::create([
        'destination_id' => null,
        'raised_by'      => null,
        'channel'        => 'sms',
        'category'       => $parsed['category'],
        'severity'       => $parsed['severity'],
        'message'        => $body,
        'raw_text'       => $body,
        'from_number'    => $from,
        'provider_ref'   => $ref !== '' ? $ref : null,
    ]);

    Alerts::logInbound([
        'from_number'  => $from,
        'body'         => $body,
        'provider_ref' => $ref !== '' ? $ref : null,
        'outcome'      => 'unknown_sender',
        'alert_id'     => $alertId,
        'note'         => 'number does not match any active manager',
    ]);

    ActivityLog::record('alert.inbound_unknown', 'destination_alert', $alertId,
        'SMS from an unrecognised number: ' . mb_substr($body, 0, 100));

    $respond(200, true, 'Received. The number is not recognised, so the Tourism Office will verify it.',
        ['alert_id' => $alertId, 'verified_sender' => false]);
}

// -----------------------------------------------------------------------------
// 5. A known manager
// -----------------------------------------------------------------------------

$alertId = Alerts::create([
    'destination_id' => (int) $manager['destination_id'],
    'raised_by'      => (int) $manager['id'],
    'channel'        => 'sms',
    'category'       => $parsed['category'],
    'severity'       => $parsed['severity'],
    'message'        => $body,
    'raw_text'       => $body,
    'from_number'    => $from,
    'provider_ref'   => $ref !== '' ? $ref : null,
]);

Alerts::logInbound([
    'from_number'  => $from,
    'body'         => $body,
    'provider_ref' => $ref !== '' ? $ref : null,
    'outcome'      => 'alert_created',
    'alert_id'     => $alertId,
    'note'         => $manager['full_name'] . ' / ' . $manager['destination_name'],
]);

ActivityLog::record(
    'alert.received', 'destination_alert', $alertId,
    strtoupper($parsed['severity']) . ' ' . $parsed['category'] . ' from '
    . $manager['destination_name'] . ': ' . mb_substr($body, 0, 100),
    null,
    (int) $manager['id']
);

/* Urgent texts reach the officer's phone. An alert nobody is told about
   is a row in a table, and the manager who sent it is standing at the
   incident assuming somebody knows. */
$texted = Alerts::notifyOffice($alertId);

$respond(200, true, 'Alert received. The Municipal Tourism Office has it.', [
    'alert_id'        => $alertId,
    'destination'     => $manager['destination_name'],
    'category'        => $parsed['category'],
    'severity'        => $parsed['severity'],
    'verified_sender' => true,
]);
