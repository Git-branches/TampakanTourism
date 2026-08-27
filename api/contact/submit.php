<?php
declare(strict_types=1);

/**
 * TourSync — the homepage contact form.                              Feature 7
 *
 * WHAT THIS FIXES
 *
 * The form on the homepage has existed since the first commit and has never
 * sent anything anywhere. It validated in the browser, printed a success
 * message, and discarded the message. Every enquiry made through it since the
 * site went up is gone, and each of those people believes the office read it
 * and chose not to reply.
 *
 * STORED, NOT EMAILED. There is no mail sender configured on this host, and
 * mail() on shared cPanel hosting fails silently often enough that a message
 * handed to it is barely safer than the old behaviour. A row in a table the
 * officer opens is a message that survives.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Csrf;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Core\Validator;
use App\Repositories\ContactRepository;
use App\Repositories\NotificationRepository as Notifications;

if (!is_post()) {
    redirect(base_url('/#contact'));
}

Csrf::verify();

/** Sends them back to the form with what they typed still in it. */
$bounce = static function (array $errors, string $message): never {
    Session::put('_contact_old', [
        'name'    => $_POST['name']    ?? '',
        'email'   => $_POST['email']   ?? '',
        'phone'   => $_POST['phone']   ?? '',
        'subject' => $_POST['subject'] ?? '',
        'message' => $_POST['message'] ?? '',
    ]);
    Session::put('_contact_errors', $errors);
    Session::flash('danger', $message);
    redirect(base_url('/#contact'));
    exit;
};

/* Honeypot and dwell, same two as every other public form here. */
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    redirect(base_url('/#contact'));
}

$renderedAt = (int) ($_POST['rendered_at'] ?? 0);
if ($renderedAt > 0 && (time() - $renderedAt) < 3) {
    redirect(base_url('/#contact'));
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

/* Two buckets, and the strict one is charged after validation — the same
   lesson as the guide form. A visitor who mistypes their email twice must not
   be locked out of contacting their municipal office. */
if (!RateLimiter::allow('contact-try:' . $ip, 20, 3600)) {
    Session::flash('danger', 'Too many attempts from this connection. Please try again later.');
    redirect(base_url('/#contact'));
}

$v = new Validator($_POST);
$v->require('name', 'email', 'subject', 'message')
  ->length('name', 2, 120)
  ->email('email')
  ->length('email', 0, 190)
  ->length('subject', 2, 120)
  ->length('message', 10, 2000);

if (trim((string) ($_POST['phone'] ?? '')) !== '') {
    $v->length('phone', 0, 40);
}

if ($v->fails()) {
    $bounce($v->errors(), $v->firstError() ?? 'Please check the form and try again.');
}

if (!RateLimiter::allow('contact:' . $ip, 4, 3600)) {
    Session::flash('danger', 'You have sent several messages already. Please wait for the Office to reply.');
    redirect(base_url('/#contact'));
}

try {
    ContactRepository::create([
        'name'        => (string) $v->value('name'),
        'email'       => (string) $v->value('email'),
        'phone'       => (string) $v->value('phone', ''),
        'subject'     => (string) $v->value('subject'),
        'message'     => (string) $v->value('message'),
        'device_hash' => RateLimiter::deviceHash(),
    ]);
} catch (Throwable $e) {
    error_log('Contact message failed: ' . $e->getMessage());
    $bounce([], 'Your message could not be sent. Please try again, or call the Office directly.');
}

/* On the bell. There is no SMS for an enquiry — until this, a message from the
   public website waited for somebody to open the Messages screen. */
Notifications::record(
    'contact_message',
    'New message: ' . $v->value('subject'),
    [
        'body'        => 'From ' . $v->value('name') . ' (' . $v->value('email') . ')',
        'link'        => base_url('/admin/messages/index.php'),
        'entity_type' => 'contact_message',
    ]
);

/* Says what actually happens next, and does not promise a timeframe the office
   has not agreed to. */
Session::flash(
    'success',
    'Thank you — your message has reached the Municipal Tourism Office. They will reply to the '
    . 'email address you gave.'
);

redirect(base_url('/#contact'));
