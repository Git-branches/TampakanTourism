<?php
declare(strict_types=1);

/**
 * TourSync — tour guide request submission.                          Feature 4
 *
 * The public writes here. Everything arriving is untrusted, so the order below
 * is deliberate: cheap rejections first (method, CSRF, honeypot, rate limit),
 * then validation, then the write, then the notification. A request is saved
 * before anybody is texted — the record is the point, the SMS is the delivery.
 *
 * NOTHING FROM THE PROVIDER OR THE DATABASE IS SHOWN TO THE VISITOR. A failure
 * here says the request could not be sent and nothing else; the detail goes to
 * the error log where the office's developer can read it and a stranger cannot.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Csrf;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Core\Validator;
use App\Repositories\TourGuideRepository as Guides;
use App\Repositories\NotificationRepository as Notifications;

if (!is_post()) {
    redirect(base_url('/tour-guide.php'));
}

Csrf::verify();

/** Puts the visitor back on the form with what they typed still in it. */
$bounce = static function (array $errors, string $message): never {
    /* The number is kept but the rest is not filtered — this is the visitor's
       own input coming back to their own screen, escaped on output. */
    Session::put('_guide_old', [
        'destination_ids' => is_array($_POST['destination_ids'] ?? null) ? $_POST['destination_ids'] : [],
        'needs_advice'    => ($_POST['needs_advice'] ?? '0') === '1',
        'visitor_name'   => $_POST['visitor_name'] ?? '',
        'contact_number' => $_POST['contact_number'] ?? '',
        'contact_email'  => $_POST['contact_email'] ?? '',
        'party_size'     => $_POST['party_size'] ?? '',
        'preferred_date' => $_POST['preferred_date'] ?? '',
        'preferred_time' => $_POST['preferred_time'] ?? '',
        'notes'          => $_POST['notes'] ?? '',
    ]);
    Session::put('_guide_errors', $errors);
    Session::flash('danger', $message);
    redirect(base_url('/tour-guide.php'));

    /* redirect() is declared void even though it exits, so this is what tells a
       reader — and a static analyser — that nothing after a bounce runs. */
    exit;
};

/* Silently accepted and discarded, both of them. A bot told it failed tries
   again with the field filled in; a bot told it succeeded goes away. */
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    redirect(base_url('/tour-guide.php?sent=1'));
}

$renderedAt = (int) ($_POST['rendered_at'] ?? 0);
if ($renderedAt > 0 && (time() - $renderedAt) < 3) {
    redirect(base_url('/tour-guide.php?sent=1'));
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

/* TWO BUCKETS, AND THE ORDER MATTERS.
 *
 * RateLimiter::allow() records a hit every time it is called, so a single
 * bucket checked here would charge a visitor for their own typos: mistype the
 * phone number twice and get the date wrong once, and a three-per-hour limit
 * locks them out before they have successfully sent anything. On a form filled
 * in one-handed at a trailhead that is the normal case, not the abusive one.
 *
 * So: a loose bucket here catches somebody hammering the endpoint, and the
 * strict one further down — the one that protects the office's inbox and its
 * per-segment SMS bill — is only reached once the submission is actually valid
 * and about to be written. A rejected form costs nothing. */
if (!RateLimiter::allow('guide-try:' . $ip, 20, 3600)) {
    Session::flash('danger', 'Too many attempts from this connection. Please try again later, or call the Tourism Office.');
    redirect(base_url('/tour-guide.php'));
}

$deviceHash = RateLimiter::deviceHash();

/* The backstop for a cleared cookie jar. Deliberately looser than the IP limit
   — this is a nuisance guard, not a security boundary, and a shared phone at a
   resort front desk should not be locked out for the day. */
if (Guides::recentForDevice($deviceHash, 6) >= 5) {
    Session::flash('danger', 'This device has sent several requests recently. Please wait for the Office to reply.');
    redirect(base_url('/tour-guide.php'));
}

$v = new Validator($_POST);
$v->require('visitor_name', 'contact_number', 'party_size')
  ->length('visitor_name', 2, 120)
  ->mobile('contact_number')
  ->integer('party_size', 1, 200)
  ->length('notes', 0, 600);

/* A time input can be typed into as well as spun, so the value is checked here
   and the visitor is told when it is wrong rather than having it quietly
   dropped. Silently discarding a time somebody deliberately chose would have
   them turn up expecting a guide at an hour the office never saw. */
$preferredTime = trim((string) ($_POST['preferred_time'] ?? ''));

if ($preferredTime !== '') {
    $problem = Guides::timeProblem($preferredTime);

    if ($problem !== null) {
        $v->addError('preferred_time', $problem);
    }
}

if (trim((string) ($_POST['contact_email'] ?? '')) !== '') {
    $v->email('contact_email')->length('contact_email', 0, 190);
}

$preferredDate = trim((string) ($_POST['preferred_date'] ?? ''));

if ($preferredDate !== '') {
    $v->date('preferred_date');

    /* A guide cannot be arranged for last Tuesday. Caught here rather than
       trusting the min= attribute, which a visitor never sees fail and an
       attacker simply removes.
     *
     * Gated on this field's own error and not on $v->passes(): a form that also
     * had a blank name would otherwise report the name, accept the correction,
     * and only then reveal the date problem — two round trips to learn two
     * things that were both wrong on the first submission. */
    if (!isset($v->errors()['preferred_date']) && $preferredDate < date('Y-m-d')) {
        $v->addError('preferred_date', 'Please choose today or a later date.');
    }
}

/* THE DESTINATIONS, AS A LIST.
 *
 * An unrecognised destination is dropped rather than refused, which is the rule
 * this endpoint has always followed: the office would rather hear "somebody
 * wants a guide" than nothing at all because a slug went stale between the QR
 * sign and the form. With several selectors that matters more, not less — one
 * bad id must not cost the visitor the other four.
 *
 * Deduplicated here as well as in the browser. The form disables an option once
 * it is taken, but the form is JavaScript and this is not, and the unique index
 * on the join table would refuse the whole insert over a duplicate rather than
 * the one row. */
$submitted = $_POST['destination_ids'] ?? [];

/* Tolerates the single destination_id the form used to post, so a cached page
   or a bookmarked QR link submitted mid-deploy still reaches the office. */
if (!is_array($submitted)) {
    $submitted = [$submitted];
}

if (($_POST['destination_id'] ?? '') !== '') {
    $submitted[] = $_POST['destination_id'];
}

$submitted = array_values(array_unique(array_filter(
    array_map('intval', $submitted),
    static fn(int $id): bool => $id > 0
)));

/* A ceiling the form cannot reach — it stops offering rows once every
   destination is taken. This is here for anything posting straight at the
   endpoint, so a request cannot arrive naming ten thousand places. */
$submitted = array_slice($submitted, 0, 20);

$destinationIds   = [];
$destinationNames = [];

if ($submitted !== []) {
    $marks = implode(',', array_fill(0, count($submitted), '?'));

    /* The names as well as the ids, in one query rather than one per row. The
       bell says "New tour guide request for Jadas Falls and Kolon Ridge", and an
       officer reading a list of five should not have to open one to find out
       which places it is about. */
    $known = Database::all(
        "SELECT id, name FROM destinations WHERE id IN ({$marks}) AND status = 'active'",
        $submitted
    );

    $byId = [];

    foreach ($known as $row) {
        $byId[(int) $row['id']] = (string) $row['name'];
    }

    /* Rebuilt in the order the visitor chose, not the order MySQL returned
       them. The list is an itinerary — first place first. */
    foreach ($submitted as $id) {
        if (isset($byId[$id])) {
            $destinationIds[]   = $id;
            $destinationNames[] = $byId[$id];
        }
    }
}

/* Asked to be advised, as opposed to simply not having chosen. Only true when
   they landed on no destination AND the form says the choice was deliberate. */
$needsAdvice = $destinationIds === [] && ($_POST['needs_advice'] ?? '0') === '1';

if ($v->fails()) {
    $bounce($v->errors(), $v->firstError() ?? 'Please check the form and try again.');
}

/* The strict bucket, charged only for a submission that is about to become a
   real request in the office's inbox and a real SMS on the office's bill.
   A family sorting out two separate days is a real thing; thirty is not. */
if (!RateLimiter::allow('guide:' . $ip, 3, 3600)) {
    Session::flash('danger', 'You have sent several requests already. Please give the Office a little time to answer, or call them.');
    redirect(base_url('/tour-guide.php'));
}

try {
    $created = Guides::create([
        'destination_ids' => $destinationIds,
        'needs_advice'    => $needsAdvice,
        'source'         => ($_POST['source'] ?? '') === 'qr' ? 'qr' : 'website',
        'visitor_name'   => (string) $v->value('visitor_name'),
        'contact_number' => (string) $v->value('contact_number'),
        'contact_email'  => (string) $v->value('contact_email', ''),
        'party_size'     => (int) $v->value('party_size'),
        'preferred_date' => $preferredDate,
        'preferred_time' => $preferredTime,
        'notes'          => (string) $v->value('notes', ''),
        'device_hash'    => $deviceHash,
    ]);
} catch (Throwable $e) {
    error_log('Tour guide request failed: ' . $e->getMessage());
    $bounce([], 'Your request could not be sent. Please try again in a moment.');
}

/* After the write, never before, and its failure never reaches the visitor.
   The office has the request either way; what an outage costs is the speed of
   the answer, not the answer. */
try {
    Guides::notifyOffice($created['id']);
} catch (Throwable $e) {
    error_log('Tour guide office notification failed: ' . $e->getMessage());
}

/* AND ON THE OFFICER'S SCREEN, not only on their phone.
 *
 * The text reaches whoever is carrying the office mobile — and on this
 * installation, nobody, because no officer has a number on file. The bell
 * reaches whoever is at a desk, and is still there tomorrow morning for the
 * officer who was not on shift. NotificationRepository::record() never throws,
 * so this cannot cost the visitor their request. */
Notifications::record(
    'guide_request',
    'New tour guide request'
        . ($destinationNames !== [] ? ' for ' . Guides::nameList($destinationNames) : ''),
    [
        'body'        => $v->value('visitor_name') . ' — ' . (int) $v->value('party_size') . ' pax'
                       . ($preferredDate !== '' ? ', ' . format_date($preferredDate, 'M j') : '')
                       . '. Ref ' . $created['reference'] . '.',
        'link'        => base_url('/admin/guides/index.php#req' . $created['id']),
        'entity_type' => 'guide_request',
        'entity_id'   => $created['id'],
    ]
);

/* Straight to the receipt, not back to an empty form with a flash on it.
 *
 * The client asked for a digital receipt, and the moment to hand somebody one
 * is the moment they finish filling in the form — not after they have closed
 * the tab looking for it. The receipt page carries the reference, everything
 * they typed, and what to do next; it prints and saves as a PDF. */
Session::flash(
    'success',
    'Request sent. Keep this receipt — the Tourism Office will also text you at the number you gave.'
);

redirect(base_url('/booking.php?ref=' . urlencode($created['reference'])));
