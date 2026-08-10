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

/* ---- 2. Honeypot -------------------------------------------------------
   A real visitor never sees this field. Anything that fills it is automated.
   The response is a normal-looking success page: telling a bot why it was
   rejected only teaches it to try again correctly. Nothing is written. */
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    redirect(base_url('/logbook-success.php?ok=1'));
}

/* ---- 3. Dwell time -----------------------------------------------------
   The form stamps its render time. A submission arriving within two seconds
   was not filled in by a person reading a privacy notice. */
$renderedAt = (int) ($_POST['rendered_at'] ?? 0);
if ($renderedAt > 0 && (time() - $renderedAt) < 2) {
    redirect(base_url('/logbook-success.php?ok=1'));
}

/* ---- 4. CSRF ------------------------------------------------------------ */
Csrf::verify();

/* ---- 5. Rate limit -----------------------------------------------------
   Ten submissions per address per destination in fifteen minutes. Generous
   enough for a tour guide filing for several guests from one phone, tight
   enough that a script cannot inflate a destination's annual figures. */
$limitKey   = 'arrival:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ':' . $token;
$maxPer15   = (int) (setting('rate_limit_per_15m') ?? 10);

if (!RateLimiter::allow($limitKey, $maxPer15, 900)) {
    $wait = (int) ceil(RateLimiter::retryAfter($limitKey, 900) / 60);
    flash_back(
        ['form' => "Too many submissions from this connection. Please try again in about {$wait} minute(s), or ask the site attendant for help."],
        $_POST,
        $backToForm
    );
}

/* ---- 6. Resolve the destination ---------------------------------------
   The token is the ONLY way to identify a destination here. The tourist never
   selects one, and no numeric id is accepted, so a record cannot be filed
   against a site the visitor did not scan. */
$destination = DestinationRepository::findByQrToken($token);

if ($destination === null) {
    flash_back(
        ['form' => 'This QR code is no longer active. Please scan the code again, or report the sign to the Tourism Office.'],
        $_POST,
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
    flash_back($v->errors(), $_POST, $backToForm);
}

/* ---- 8. Duplicate detection --------------------------------------------
   FLAGGED, never blocked. A family sharing one phone hotspot produces several
   genuine submissions from one device, and refusing them would lose real
   arrivals — a worse failure than counting a duplicate the officer can review.
   Reports count only 'valid' rows, so a flagged record sits outside the
   published figure until somebody looks at it. */
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
        'visit_date'       => date('Y-m-d'),
        'arrived_at'       => date('Y-m-d H:i:s'),
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
    ]);
} catch (Throwable $e) {
    error_log('Arrival submission failed: ' . $e->getMessage());
    flash_back(
        ['form' => 'Your visit could not be saved just now. Please check your connection and try again.'],
        $_POST,
        $backToForm
    );
}

if ($status === 'flagged') {
    ActivityLog::record('arrival.flagged', 'arrival', $arrivalId, $flagReason);
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
