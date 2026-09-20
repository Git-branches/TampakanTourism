<?php
declare(strict_types=1);

/**
 * TourSync — a rating left for an accredited tour guide.
 *          Contact Us › Tour Guide.        Presentation feedback, 15 Sep 2026
 *
 * The office accredits guides, issues each an ID, and had no way to hear how a
 * visitor found one. This is that way.
 *
 * NOTHING SUBMITTED HERE IS EVER PUBLISHED BY SUBMITTING IT. These reviews name
 * a private individual who holds a municipal accreditation. An officer reads it
 * and decides; until then it is visible to the office and to nobody else. The
 * repository hard-codes 'pending' so this endpoint cannot get that wrong even
 * by accident.
 *
 * WHY THERE IS NO PROOF-OF-PRESENCE CHECK, unlike a destination review. A
 * destination review is gated on an arrival record or a QR scan, because the
 * whole proposition there is that the reviewer demonstrably stood at the place.
 * A guide is a person, not a location: somebody who was guided around Tampakan
 * last month and is writing from home has exactly the experience the office
 * wants to hear about, and there is no session left to prove it with. The
 * defences here are therefore the other ones — honeypot, dwell, two rate-limit
 * buckets, a per-device duplicate check, and a human before anything is seen.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Csrf;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Core\Validator;
use App\Repositories\GuideReviewRepository;
use App\Repositories\NotificationRepository as Notifications;

if (!is_post()) {
    redirect(base_url('/#contact'));
}

Csrf::verify();

/* Same dual path as api/contact/submit.php. The modal posts by fetch and stays
   open; a browser without JavaScript posts normally and is redirected back to
   the section with the answer rendered into the page. */
$wantsJson = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

/** @param array<string, string> $errors */
$json = static function (bool $ok, ?string $message, array $errors = []): never {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(
        ['ok' => $ok, 'message' => $message, 'errors' => $errors],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
};

$bounce = static function (string $message, string $type = 'danger'): never {
    Session::flash($type, $message);
    redirect(base_url('/#contact'));
};

/* Honeypot and dwell. Both answer as though the submission succeeded — telling
   a bot which trap it fell into is how it learns to step over the trap. */
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    if ($wantsJson) { $json(true, null); }
    redirect(base_url('/#contact'));
}

$renderedAt = (int) ($_POST['rendered_at'] ?? 0);
if ($renderedAt > 0 && (time() - $renderedAt) < 3) {
    if ($wantsJson) { $json(true, null); }
    redirect(base_url('/#contact'));
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

/* TWO BUCKETS, AND THE STRICT ONE IS CHARGED AFTER VALIDATION.
   RateLimiter::allow() records every call it is given, so a single bucket
   placed before validation charges a visitor for their own typos — three
   corrected mistakes and they are locked out of rating anybody. The loose
   bucket here counts attempts; the strict one below counts reviews. */
if (!RateLimiter::allow('guide-review-try:' . $ip, 20, 3600)) {
    $tooMany = 'Too many attempts from this connection. Please try again later.';
    if ($wantsJson) { $json(false, $tooMany); }
    $bounce($tooMany);
}

$v = new Validator($_POST);
$v->require('guide_id', 'rating')
  ->integer('guide_id', 1)
  ->integer('rating', 1, 5)
  ->length('comment', 0, 1000)
  ->length('visitor_name', 0, 120);

if (trim((string) ($_POST['visitor_email'] ?? '')) !== '') {
    $v->email('visitor_email')->length('visitor_email', 0, 190);
}

$guideId = (int) $v->value('guide_id');

/* The guide must exist AND be someone the office currently stands behind.
   A suspended or revoked accreditation is not a guide the office is inviting
   the public to review — and offering one would mean the select on the page
   and the check here disagree about who is on the roster. */
$guide = $guideId > 0
    ? Database::first(
        "SELECT id, full_name FROM tour_guides WHERE id = ? AND status = 'active'",
        [$guideId]
    )
    : null;

if ($guide === null) {
    $v->addError('guide_id', 'Please choose a tour guide from the list.');
}

if ($v->fails() || $guide === null) {
    $message = $v->firstError() ?? 'Please check the form and try again.';
    if ($wantsJson) { $json(false, $message, $v->errors()); }
    $bounce($message);
}

/* Read once, here, where $guide is known to be a row. $bounce() never returns,
   but a static checker cannot see through a closure, and $guide['full_name']
   further down read as an offset on something that might be null. */
$guideName = (string) ($guide['full_name'] ?? '');

$deviceHash = RateLimiter::deviceHash();

/* One review per guide per device, for a month. The rate limiter counts across
   all guides; this stops the same person rating one guide five times from five
   tabs, which the limiter's own ceiling would otherwise permit. */
if (GuideReviewRepository::existsForDevice($guideId, $deviceHash)) {
    $already = 'You have already left a rating for this guide recently. Thank you!';
    if ($wantsJson) { $json(false, $already); }
    $bounce($already, 'info');
}

if (!RateLimiter::allow('guide-review:' . $ip, 5, 3600)) {
    $enough = 'You have sent several ratings already. Please try again later.';
    if ($wantsJson) { $json(false, $enough); }
    $bounce($enough);
}

try {
    GuideReviewRepository::create([
        'guide_id'      => $guideId,
        'visitor_name'  => (string) $v->value('visitor_name', ''),
        'visitor_email' => (string) $v->value('visitor_email', ''),
        'rating'        => (int) $v->value('rating'),
        'comment'       => (string) $v->value('comment', ''),
        'device_hash'   => $deviceHash,
    ]);
} catch (Throwable $e) {
    error_log('Guide review failed: ' . $e->getMessage());
    $failed = 'Your rating could not be saved. Please try again, or call the Office directly.';
    if ($wantsJson) { $json(false, $failed); }
    $bounce($failed);
}

/* On the bell. A rating nobody is told about waits for somebody to open the
   Feedback screen and notice a tab they were not looking for.

   The rating is in the title and the comment is NOT in the body. An officer
   glancing at a notification should see that somebody rated a named guide two
   stars; what they wrote about that person belongs on the screen where it can
   be read in full, moderated, and acted on — not in a notification list. */
Notifications::record(
    'guide_review',
    'New guide rating: ' . $guideName . ' (' . (int) $v->value('rating') . '/5)',
    [
        'body'        => 'Awaiting moderation before it can be published.',
        'link'        => base_url('/admin/feedback/guides.php'),
        'entity_type' => 'guide_review',
    ]
);

/* Says what actually happens next. It does not say the rating is live, because
   it is not, and somebody who goes looking for it should know why it is not
   there. The same sentence on both paths — a visitor with JavaScript and one
   without must be told the same thing. */
$thanks = 'Thank you. Your rating has been sent to the Municipal Tourism Office and will be '
    . 'reviewed before it appears anywhere.';

if ($wantsJson) { $json(true, $thanks); }

Session::flash('success', $thanks);
redirect(base_url('/#contact'));
