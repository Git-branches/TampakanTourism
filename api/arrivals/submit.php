<?php
declare(strict_types=1);

/**
 * =============================================================================
 *  TourSync — Arrival submission endpoint
 * -----------------------------------------------------------------------------
 *  The single write that Feature 1 exists to perform, and the point where
 *  Feature 2 takes over.
 *
 *  This endpoint is public and unauthenticated by design: a tourist standing
 *  in the sun will not complete anything slower. The consequence is that rows
 *  written here become the Municipality's official tourism statistics, so the
 *  checks below are not ceremony — they are what keeps a government figure
 *  defensible.
 *
 *  Order matters. Cheap rejections come first so an abusive client is turned
 *  away before it costs a database round trip.
 *
 *    1. Method            — anything but POST is not a submission
 *    2. Honeypot          — silently discarded, never told why
 *    3. Dwell time        — a form filled in under two seconds was not typed
 *    4. CSRF token        — the request came from our own form
 *    5. Rate limit        — per address and destination
 *    6. QR token          — resolves the destination; nothing else can
 *    7. Field validation  — server side, always
 *    8. Duplicate check   — FLAGS, never blocks
 *
 *  TWO CALLERS, ONE PIPELINE.                                        Feature 2
 *
 *  A browser posts this form and expects a redirect. A phone replaying a visit
 *  it captured with no signal posts the same fields with mode=sync and expects
 *  JSON. Every check above runs identically for both — the sync path is not a
 *  side door, it is the same door answered in a different language. What the
 *  sync caller additionally sends is a client_uuid, so a retry cannot be
 *  counted twice, and a captured_at, so the visit is dated to when the visitor
 *  stood there rather than to when the signal came back.
 * =============================================================================
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Csrf;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Core\Validator;
use App\Repositories\ArrivalRepository;
use App\Repositories\DestinationRepository;

/* ---- 1. Method --------------------------------------------------------- */
if (!is_post()) {
    redirect(destinations_url());
}

$token      = (string) ($_POST['token'] ?? '');
$backToForm = base_url('/logbook.php?token=' . urlencode($token));

/* Is this a phone draining its offline queue, or a browser posting a form? */
$isSync = ($_POST['mode'] ?? '') === 'sync';

/**
 * Ends the request the way this caller expects.
 *
 * The browser path is untouched: flash the errors, redirect back to the form,
 * repopulate. The sync path cannot use a session flash — nobody is going to
 * look at that page — so it answers with the errors in the body and a status
 * the device can act on. 422 means "this record is wrong, stop retrying it";
 * anything the device should retry is signalled with 503 instead.
 */
$fail = static function (array $errors, string $redirectTo, int $status = 422) use ($isSync, $backToForm): void {
    if ($isSync) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'        => false,
            'retryable' => $status >= 500,
            'errors'    => $errors,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    flash_back($errors, $_POST, $redirectTo ?: $backToForm);
};

/* ---- 2. Honeypot -------------------------------------------------------
   A real visitor never sees this field. Anything that fills it is automated.
   The response is a normal-looking success page: telling a bot why it was
   rejected only teaches it to try again correctly. Nothing is written. */
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    if ($isSync) {
        /* Reported as accepted so the device stops retrying and drops it from
           the queue. Nothing is written, and a bot learns nothing. */
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'stored' => false]);
        exit;
    }
    redirect(base_url('/logbook-success.php?ok=1'));
}

/* ---- 3. Dwell time -----------------------------------------------------
   The form stamps its render time. A submission arriving within two seconds
   was not filled in by a person reading a privacy notice. */
$renderedAt = (int) ($_POST['rendered_at'] ?? 0);
if ($renderedAt > 0 && (time() - $renderedAt) < 2) {
    redirect(base_url('/logbook-success.php?ok=1'));
}

/* ---- 4. CSRF ------------------------------------------------------------
   A replaying device gets a fresh token from api/arrivals/token.php first,
   because the one baked into the form it filled offline may be hours old and
   belong to a session that has since expired. The guard is not relaxed for
   sync; the caller is simply expected to hold a current token, same as a
   browser with the page open. */
Csrf::verify();

/* ---- 4b. Already stored? -----------------------------------------------
   The device generates client_uuid before the record leaves it. If a previous
   attempt inserted the row and the acknowledgement never arrived, the retry
   lands here and is answered with the same success as the first attempt —
   which is what lets the device delete it from the queue with confidence.

   Without this, the honest failure mode of a bad signal is a tourism figure
   that counts one visitor twice. */
$clientUuid = trim((string) ($_POST['client_uuid'] ?? ''));

if ($clientUuid !== '' && preg_match('/^[0-9a-f-]{36}$/i', $clientUuid) === 1) {
    $existing = ArrivalRepository::findByClientUuid($clientUuid);

    if ($existing !== null) {
        if ($isSync) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'        => true,
                'stored'    => true,
                'duplicate' => true,
                'arrival_id' => (int) $existing['id'],
            ]);
            exit;
        }

        Session::put('_last_arrival', [
            'id'             => (int) $existing['id'],
            'destination_id' => (int) $existing['destination_id'],
            'destination'    => $existing['destination_name'],
            'slug'           => $existing['destination_slug'],
            'token'          => $existing['qr_token'],
            'visitors'       => 1 + (int) $existing['companions_count'],
        ]);
        redirect(base_url('/logbook-success.php'));
    }
} else {
    $clientUuid = '';
}

/* ---- 5. Rate limit -----------------------------------------------------
   Ten submissions per address per destination in fifteen minutes. Generous
   enough for a tour guide filing for several guests from one phone, tight
   enough that a script cannot inflate a destination's annual figures. */
$limitKey   = 'arrival:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ':' . $token;
$maxPer15   = (int) (setting('rate_limit_per_15m') ?? 10);

if (!RateLimiter::allow($limitKey, $maxPer15, 900)) {
    $wait = (int) ceil(RateLimiter::retryAfter($limitKey, 900) / 60);

    /* 503 for a syncing device, not 422: the record is fine, the moment is
       wrong. A phone draining a queue of six visits after a day with no signal
       will hit this legitimately, and it must keep them and try later rather
       than throw them away as invalid. */
    $fail(
        ['form' => "Too many submissions from this connection. Please try again in about {$wait} minute(s), or ask the site attendant for help."],
        $backToForm,
        503
    );
}

/* ---- 6. Resolve the destination ---------------------------------------
   The token is the ONLY way to identify a destination here. The tourist never
   selects one, and no numeric id is accepted, so a record cannot be filed
   against a site the visitor did not scan. */
$destination = DestinationRepository::findByQrToken($token);

if ($destination === null) {
    $fail(
        ['form' => 'This QR code is no longer active. Please scan the code again, or report the sign to the Tourism Office.'],
        destinations_url()
    );
}

/* ---- 7. Validation ------------------------------------------------------ */
$v = new Validator($_POST);

$v->require('tourist_type')
  ->in('tourist_type', array_keys(ArrivalRepository::TYPES))
  ->integer('companions_count', 0, 200)
  ->in('age_bracket', array_keys(ArrivalRepository::AGE_BRACKETS))
  ->in('sex', ['male', 'female', 'prefer_not_to_say'])
  ->in('purpose', array_keys(ArrivalRepository::PURPOSES))
  ->in('stay_type', ['day_trip', 'overnight'])
  ->length('full_name', 0, 160)
  ->email('email');

if (trim((string) ($_POST['contact_number'] ?? '')) !== '') {
    $v->mobile('contact_number');
}

/* Consent is a legal requirement under RA 10173, not a formality. */
if (empty($_POST['consent'])) {
    $v->addError('consent', 'We cannot record your visit without your consent.');
}

if ($v->fails()) {
    $fail($v->errors(), $backToForm);
}

/* ---- 8. Duplicate detection --------------------------------------------
   FLAGGED, never blocked. A family sharing one phone hotspot produces several
   genuine submissions from one device, and refusing them would lose real
   arrivals — a worse failure than counting a duplicate the officer can review.
   Reports count only 'valid' rows, so a flagged record sits outside the
   published figure until somebody looks at it. */
/* ---- 8a. When did this visit actually happen? --------------------------
   Two clocks, and confusing them corrupts the report.

   arrived_at is when the visitor stood at the destination. For an online
   submission that is now. For a record captured at a waterfall with no signal
   and synced from the town centre three hours later, "now" is a lie that would
   file a Tuesday morning arrival against Tuesday afternoon — and, across a
   date boundary, against the wrong day entirely.

   So the device sends the moment it captured the visit, and it is trusted
   within bounds it cannot abuse: never in the future beyond a little clock
   skew, and never older than the retention window for a queued record. A value
   outside those bounds is not an argument to reject the visit — it is a reason
   to fall back to server time and keep the arrival. */
$now          = time();
$capturedAt   = (int) ($_POST['captured_at'] ?? 0);
$maxQueueDays = 30;

$capturedIsSane = $capturedAt > 0
    && $capturedAt <= $now + 300
    && $capturedAt >= $now - ($maxQueueDays * 86400);

$arrivedAt = $capturedIsSane ? $capturedAt : $now;

/* Only a record that genuinely waited is marked as synced. A submission that
   travelled straight through leaves synced_at null, so the column means
   exactly one thing: this arrival spent time on a device before it reached us. */
$syncedAt = ($capturedIsSane && ($now - $capturedAt) > 60)
    ? date('Y-m-d H:i:s', $now)
    : null;

$deviceHash  = RateLimiter::deviceHash();
$dedupeHours = (int) (setting('dedupe_window_hours') ?? 6);
$recent      = ArrivalRepository::recentFromDevice($deviceHash, (int) $destination['id'], $dedupeHours);

$status     = 'valid';
$flagReason = null;

if ($recent > 0) {
    $status     = 'flagged';
    $flagReason = "Device already logged this destination {$recent} time(s) within {$dedupeHours} hours";
}

/* ---- Write -------------------------------------------------------------- */
try {
    $arrivalId = ArrivalRepository::record([
        'destination_id'   => (int) $destination['id'],
        'visit_date'       => date('Y-m-d', $arrivedAt),
        'arrived_at'       => date('Y-m-d H:i:s', $arrivedAt),
        'full_name'        => (string) $v->value('full_name', ''),
        'age_bracket'      => (string) $v->value('age_bracket', ''),
        'sex'              => (string) $v->value('sex', ''),
        'contact_number'   => (string) $v->value('contact_number', ''),
        'email'            => (string) $v->value('email', ''),
        'tourist_type'     => (string) $v->value('tourist_type'),
        'stay_type'        => (string) $v->value('stay_type', ''),
        'nationality'      => (string) $v->value('nationality', ''),
        'origin_country'   => (string) $v->value('origin_country', ''),
        'origin_province'  => (string) $v->value('origin_province', ''),
        'origin_city'      => (string) $v->value('origin_city', ''),
        'purpose'          => (string) $v->value('purpose', ''),
        'companions_count' => (int) $v->value('companions_count', 0),
        'consent_given'    => 1,
        'source'           => 'qr',
        'qr_version_used'  => (int) $destination['qr_version'],
        'device_hash'      => $deviceHash,
        'status'           => $status,
        'flag_reason'      => $flagReason,
        'client_uuid'      => $clientUuid,
        'synced_at'        => $syncedAt,
    ]);
} catch (Throwable $e) {
    error_log('Arrival submission failed: ' . $e->getMessage());

    /* 503, so a syncing device keeps the record and tries again. A database
       that was briefly unreachable must not cost the municipality an arrival
       that a phone was faithfully holding on to. */
    $fail(
        ['form' => 'Your visit could not be saved just now. Please check your connection and try again.'],
        $backToForm,
        503
    );
}

if ($status === 'flagged') {
    ActivityLog::record('arrival.flagged', 'arrival', $arrivalId, $flagReason);
}

/* A device draining its queue has no session worth writing to and no page to
   be redirected to. It gets the answer and goes back to the next record. */
if ($isSync) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'         => true,
        'stored'     => true,
        'duplicate'  => false,
        'arrival_id' => $arrivalId,
        'flagged'    => $status === 'flagged',
    ]);
    exit;
}

/* The arrival id is carried in the session, not the URL: it lets the
   thank-you page attach a rating to this exact visit without exposing a
   record identifier that could be guessed or shared. */
Session::put('_last_arrival', [
    'id'             => $arrivalId,
    'destination_id' => (int) $destination['id'],
    'destination'    => $destination['name'],
    'slug'           => $destination['slug'],
    'token'          => $destination['qr_token'],
    'visitors'       => 1 + (int) $v->value('companions_count', 0),
]);

redirect(base_url('/logbook-success.php'));
