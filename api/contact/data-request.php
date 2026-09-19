<?php
declare(strict_types=1);

/**
 * TourSync — the official Data Request form.
 *      Contact Us › Data Request.          Presentation feedback, 15 Sep 2026
 *
 * Mirrors the paper form the office hands across the counter, so a request made
 * on the website can be filed and answered exactly like one made in person.
 *
 * THIS IS THE ONLY PUBLIC ENDPOINT HERE THAT COLLECTS REAL PERSONAL DATA —
 * home address, birthdate, contact number. Everything below treats that as the
 * governing fact:
 *
 *   · Nothing submitted is echoed back into a public page. The answer names the
 *     reference code and nothing else the requester typed.
 *   · Nothing reaches the notification bell but the reference and the
 *     organisation. An officer's notification list is read over shoulders in an
 *     open-plan office; somebody's home address does not belong in it.
 *   · The failure path does NOT repopulate the form from the session the way
 *     the general contact form does. Holding a birthdate and an address in
 *     session storage to save a retype is not a trade worth making, and the
 *     modal keeps what was typed client-side anyway.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Csrf;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Core\Validator;
use App\Repositories\DataRequestRepository;
use App\Repositories\NotificationRepository as Notifications;

if (!is_post()) {
    redirect(base_url('/#contact'));
}

Csrf::verify();

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

/* Honeypot and dwell, both answering as though nothing happened. */
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    if ($wantsJson) { $json(true, null); }
    redirect(base_url('/#contact'));
}

/* Five seconds rather than the three the other forms use. This form is fourteen
   fields long; nobody fills it honestly in less time, and a bot that does has
   told us what it is. */
$renderedAt = (int) ($_POST['rendered_at'] ?? 0);
if ($renderedAt > 0 && (time() - $renderedAt) < 5) {
    if ($wantsJson) { $json(true, null); }
    redirect(base_url('/#contact'));
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

/* Attempts bucket, charged before validation and deliberately generous. This is
   the longest form on the site and a requester will bounce off it more than
   once; being locked out of asking a government office for data because the
   date format was wrong twice is not a defence, it is a fault. */
if (!RateLimiter::allow('data-request-try:' . $ip, 30, 3600)) {
    $tooMany = 'Too many attempts from this connection. Please try again later.';
    if ($wantsJson) { $json(false, $tooMany); }
    $bounce($tooMany);
}

$v = new Validator($_POST);
$v->require('first_name', 'last_name', 'email', 'purpose', 'requested_data')
  ->length('first_name', 2, 80)
  ->length('middle_name', 0, 80)
  ->length('last_name', 2, 80)
  ->length('organisation', 0, 190)
  ->length('home_address', 0, 255)
  ->length('designation', 0, 160)
  ->email('email')
  ->length('email', 0, 190)
  ->length('contact_number', 0, 40)
  ->length('purpose', 10, 1000)
  ->length('requested_data', 10, 2000);

if (trim((string) ($_POST['civil_status'] ?? '')) !== '') {
    $v->in('civil_status', DataRequestRepository::CIVIL_STATUSES);
}

/* THE TWO DATES, CHECKED FOR SENSE AND NOT ONLY FOR SHAPE.
 *
 * date() answers whether a string parses. It says nothing about whether the
 * value is possible, and both of these have an obvious wrong direction:
 * a birthdate cannot be in the future, and a completion date the office is
 * being asked to meet cannot be in the past. Either one accepted silently
 * produces a row an officer has to query by hand. */
$today = new DateTimeImmutable('today');

$birthdate = trim((string) ($_POST['birthdate'] ?? ''));
if ($birthdate !== '') {
    $v->date('birthdate');

    if (!isset($v->errors()['birthdate'])) {
        try {
            $born = new DateTimeImmutable($birthdate);

            if ($born > $today) {
                $v->addError('birthdate', 'A birthdate cannot be in the future.');
            } elseif ((int) $born->diff($today)->y > 130) {
                $v->addError('birthdate', 'Please check the birthdate.');
            }
        } catch (Throwable) {
            $v->addError('birthdate', 'Please give the birthdate as a date.');
        }
    }
}

$neededBy = trim((string) ($_POST['needed_by'] ?? ''));
if ($neededBy !== '') {
    $v->date('needed_by');

    if (!isset($v->errors()['needed_by'])) {
        try {
            if (new DateTimeImmutable($neededBy) < $today) {
                $v->addError('needed_by', 'Please choose a completion date that has not passed.');
            }
        } catch (Throwable) {
            $v->addError('needed_by', 'Please give the completion date as a date.');
        }
    }
}

if ($v->fails()) {
    $message = $v->firstError() ?? 'Please check the form and try again.';
    if ($wantsJson) { $json(false, $message, $v->errors()); }
    $bounce($message);
}

/* The real bucket, charged only now that the request is known to be well
   formed. Three an hour: a data request is a considered act, and somebody
   filing a fourth in an hour is either testing the form or flooding it. */
if (!RateLimiter::allow('data-request:' . $ip, 3, 3600)) {
    $enough = 'You have already sent several requests. Please wait for the Office to reply.';
    if ($wantsJson) { $json(false, $enough); }
    $bounce($enough);
}

try {
    $created = DataRequestRepository::create([
        'organisation'   => (string) $v->value('organisation', ''),
        'first_name'     => (string) $v->value('first_name'),
        'middle_name'    => (string) $v->value('middle_name', ''),
        'last_name'      => (string) $v->value('last_name'),
        'home_address'   => (string) $v->value('home_address', ''),
        'birthdate'      => $birthdate,
        'civil_status'   => (string) $v->value('civil_status', ''),
        'designation'    => (string) $v->value('designation', ''),
        'email'          => (string) $v->value('email'),
        'contact_number' => (string) $v->value('contact_number', ''),
        'purpose'        => (string) $v->value('purpose'),
        'requested_data' => (string) $v->value('requested_data'),
        'needed_by'      => $neededBy,
        'device_hash'    => RateLimiter::deviceHash(),
    ]);
} catch (Throwable $e) {
    error_log('Data request failed: ' . $e->getMessage());
    $failed = 'Your request could not be sent. Please try again, or call the Office directly.';
    if ($wantsJson) { $json(false, $failed); }
    $bounce($failed);
}

/* THE REFERENCE AND THE ORGANISATION, AND NOTHING ELSE.
   A notification is a line in a list an officer reads with other people in the
   room. The requester's name, address and birthdate are on the screen this
   links to, behind a sign-in, which is where they belong. */
Notifications::record(
    'data_request',
    'New data request: ' . $created['reference'],
    [
        'body' => trim((string) $v->value('organisation', '')) !== ''
            ? 'From ' . mb_substr((string) $v->value('organisation'), 0, 120)
            : 'From an individual requester.',
        'link'        => base_url('/admin/messages/data-requests.php'),
        'entity_type' => 'data_request',
    ]
);

/* The reference is the useful half of this sentence: it is how the requester
   asks after their request without either side repeating personal details over
   email. Nothing else they typed is read back to them. */
$thanks = 'Thank you — your data request has reached the Municipal Tourism Office. '
    . 'Your reference is ' . $created['reference'] . '. Please quote it when following up.';

if ($wantsJson) { $json(true, $thanks); }

Session::flash('success', $thanks);
redirect(base_url('/#contact'));
