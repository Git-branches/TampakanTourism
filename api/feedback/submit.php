<?php
declare(strict_types=1);

/**
 * TourSync — visitor rating submission.
 *
 * Reached from the thank-you page, so the session still holds the arrival that
 * was just recorded. Linking the two is what makes these reviews unusual: the
 * reviewer demonstrably stood at the destination, unlike an anonymous comment
 * left from anywhere.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Csrf;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Core\Validator;
use App\Repositories\FeedbackRepository;

if (!is_post()) {
    redirect(destinations_url());
}

Csrf::verify();

/* Honeypot and dwell time, same reasoning as the arrivals endpoint: a comment
   box on a government website attracts automated spam within days of going
   live, and neither check costs a real visitor anything. */
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    redirect(base_url('/logbook-success.php?rated=1'));
}

$renderedAt = (int) ($_POST['rendered_at'] ?? 0);
if ($renderedAt > 0 && (time() - $renderedAt) < 2) {
    redirect(base_url('/logbook-success.php?rated=1'));
}

$limitKey = 'feedback:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!RateLimiter::allow($limitKey, 5, 900)) {
    Session::flash('danger', 'Too many reviews from this connection. Please try again later.');
    redirect(destinations_url());
}

$v = new Validator($_POST);
$v->require('destination_id', 'rating')
  ->integer('rating', 1, 5)
  ->length('comment', 0, 1000)
  ->length('visitor_name', 0, 120);

$destinationId = (int) $v->value('destination_id');

$destination = Database::first(
    "SELECT id, name, slug FROM destinations WHERE id = ? AND status = 'active'",
    [$destinationId]
);

if ($destination === null) {
    $v->addError('destination_id', 'That destination is not available.');
}

if ($v->fails()) {
    Session::flash('danger', $v->firstError() ?? 'Please check your rating and try again.');
    redirect(base_url('/destination.php?slug=' . ($destination['slug'] ?? '')));
}

/* PROOF OF PRESENCE, read from the session and never from the form. A record id
 * in a hidden field could be edited to attach a review to somebody else's visit.
 *
 * A review still requires having been at the destination — that is the whole
 * proposition, these come from people who demonstrably stood there, and it
 * closes the obvious spam route of posting to this endpoint directly.
 *
 * WHAT COUNTS AS PROOF CHANGED WITH FEATURE 1. The QR sign no longer opens a
 * logbook, so there is no arrival record to point at; the tourist writes in the
 * paper book at the fill-up station instead. Scanning the sign is now the
 * evidence, and d/index.php records it. The older arrival-backed path is kept
 * for any session still carrying one — dropping it would silently refuse a
 * review from someone mid-visit on the day this shipped. */
$arrival = Session::get('_last_arrival');
$scanned = Session::get('_scanned');

$hasArrival = is_array($arrival)
    && isset($arrival['id'])
    && (int) ($arrival['destination_id'] ?? 0) === $destinationId;

$hasScan = is_array($scanned)
    && (int) ($scanned['destination_id'] ?? 0) === $destinationId;

if (!$hasArrival && !$hasScan) {
    Session::flash(
        'info',
        'Reviews are open to visitors who have been to the destination. Scan the QR code on the sign to leave one.'
    );
    redirect(base_url('/destination.php?slug=' . $destination['slug'] . '#reviews'));
}

$arrivalId = $hasArrival ? (int) $arrival['id'] : null;

/* One review per visit, checked two ways where there is an arrival to check
 * against.
 *
 * The session flag catches the common case — a visitor tapping submit twice on
 * a slow connection, or using the back button. The database check catches the
 * rest, including a resubmission after the session was cleared. An earlier
 * version relied on the session alone and a double submit produced two
 * reviews, because clearing the arrival made the second request look new.
 *
 * A scan-backed review has no arrival row, so its backstop is the device hash
 * already stored on every review — same idea, different key. Without it the
 * session flag is the only guard, and clearing cookies or reopening the sign in
 * a private tab makes the next submission look new. */
$alreadyRated = $hasArrival
    ? (!empty($arrival['rated']) || FeedbackRepository::existsForArrival($arrivalId))
    : (!empty($scanned['rated'])
        || FeedbackRepository::existsForDevice($destinationId, RateLimiter::deviceHash()));

if ($alreadyRated) {
    Session::flash('info', 'You have already rated this visit. Thank you!');
    redirect(base_url('/destination.php?slug=' . $destination['slug'] . '#reviews'));
}

try {
    FeedbackRepository::create([
        'destination_id' => $destinationId,
        'arrival_id'     => $arrivalId,
        'visitor_name'   => (string) $v->value('visitor_name', ''),
        'rating'         => (int) $v->value('rating'),
        'comment'        => (string) $v->value('comment', ''),
        'device_hash'    => RateLimiter::deviceHash(),
    ]);
} catch (Throwable $e) {
    error_log('Feedback submission failed: ' . $e->getMessage());
    Session::flash('danger', 'Your review could not be saved. Please try again.');
    redirect(base_url('/destination.php?slug=' . $destination['slug']));
}

/* Mark the visit rated rather than discarding it. Discarding made a repeat
   submission look like a brand-new one, which is exactly how the duplicate
   slipped through before. Whichever kind of proof was used gets the flag. */
if ($hasArrival) {
    $arrival['rated'] = true;
    Session::put('_last_arrival', $arrival);
} else {
    $scanned['rated'] = true;
    Session::put('_scanned', $scanned);
}

/* Said plainly rather than "thank you, your review is live" — it is not live,
   and a visitor who goes looking for it should know why it is not there. */
Session::flash(
    'success',
    'Thank you. Your review has been sent to the Municipal Tourism Office and will appear once reviewed.'
);

redirect(base_url('/destination.php?slug=' . $destination['slug'] . '#reviews'));
