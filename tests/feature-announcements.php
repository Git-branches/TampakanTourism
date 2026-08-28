<?php
declare(strict_types=1);

/**
 * FEATURE 3 — centralised announcements and automated communication.
 *
 * NOTHING IN HERE SENDS AN SMS, and that is deliberate rather than incidental.
 *
 * The configured driver is philsms with a live API key, and the driver is read
 * from config.php inside the Apache process — so SmsGateway::useDriver(), which
 * pins a driver for the current process, has no reach over HTTP. A test that
 * triggered the dispatch path would text real numbers and spend real credit.
 * It has happened on this project before.
 *
 * So the send path is exercised in-process, where the log driver CAN be pinned,
 * and the HTTP half tests everything up to but not including the send: the
 * announcement is written, it publishes, the public page shows it, the contact
 * form reaches the office inbox.
 *
 * Everything it creates, it deletes.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Core\Sms\LogDriver;
use App\Core\SmsGateway;

echo "\n=== feature 3: announcements and communication ===\n\n";

if (!test_server_up()) {
    echo "  SKIP — no web server answering at " . test_base_url() . "\n";
    exit(0);
}

[$sid, $csrf] = test_sign_in_officer();

$before = (int) Database::scalar('SELECT COUNT(*) FROM announcements');

register_shutdown_function(static function (): void {
    Database::run("DELETE FROM announcements WHERE title LIKE 'ZZ %'");
    Database::run("DELETE FROM contact_messages WHERE name LIKE 'ZZ %'");
    echo "  (probe announcement and message removed)\n";
});

echo "--- the office writes an announcement ---\n";

/* type and audience are required and validated against the repository's own
   lists — my first attempt posted only title/body/status, the form refused it,
   and the redirect that came back looked exactly like a success. A 302 from a
   flash_back is indistinguishable from a 302 after a save; the row is the only
   honest evidence, which is why the check below reads the table. */
$r = test_post('admin/announcements/create.php', $sid, [
    '_token'   => $csrf,
    'title'    => 'ZZ Trail advisory',
    'body'     => 'ZZ the upper circuit is closed after heavy rain.',
    'type'     => 'advisory',
    'audience' => 'public',
    'status'   => 'published',
]);

check('the form was accepted (302)', $r['code'], 302);

$made = Database::first("SELECT * FROM announcements WHERE title = 'ZZ Trail advisory'");

check('it was written', $made !== null, true);

if ($made === null) {
    test_finish();
}

$aid = (int) $made['id'];

check('it is published', (string) $made['status'], 'published');
check('it has a slug', trim((string) $made['slug']) !== '', true);

echo "\n--- and a visitor can read it ---\n";

$public = test_get('announcement.php?slug=' . urlencode((string) $made['slug']));

check('the public page renders without diagnostics',
    (bool) preg_match('/Warning:|Fatal error:/', $public), false);
check('the title is on it', str_contains($public, 'ZZ Trail advisory'), true);
check('the body is on it',
    str_contains($public, 'the upper circuit is closed after heavy rain'), true);

echo "\n--- a draft is not readable by the public ---\n";

Database::run("UPDATE announcements SET status = 'draft' WHERE id = ?", [$aid]);

$ch = curl_init(test_base_url() . '/announcement.php?slug=' . urlencode((string) $made['slug']));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
curl_exec($ch);
$draftCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

check('a draft 404s for a visitor', $draftCode, 404);

Database::run("UPDATE announcements SET status = 'published' WHERE id = ?", [$aid]);

echo "\n--- the SMS gateway, driven in-process where the driver can be pinned ---\n";

/* useDriver() only reaches THIS process. That is exactly why the dispatch
   button is never pressed over HTTP anywhere in this suite. */
SmsGateway::useDriver(new LogDriver());

register_shutdown_function(static function (): void {
    SmsGateway::useDriver(null);   /* back to whatever config says */
});

check('the gateway is not live while pinned', SmsGateway::isLive(), false);

$composed = SmsGateway::compose('ZZ Trail advisory',
    'ZZ the upper circuit is closed after heavy rain.', 'Tampakan Tourism Office');

check('a message composes', trim($composed) !== '', true);
check('it carries the title', str_contains($composed, 'ZZ Trail advisory'), true);
check('segments are counted', SmsGateway::segments($composed) >= 1, true);

$sent = SmsGateway::send('09171234567', 'ZZ probe message');

check('the log driver reports success', (bool) ($sent['ok'] ?? false), true);
check('and it really is the log driver', SmsGateway::driver()->name(), 'log');

echo "\n--- number normalisation, so one phone is not texted twice ---\n";

$forms = ['09171234567', '+639171234567', '639171234567', '0917 123 4567'];
$norm  = array_unique(array_map(
    static fn(string $n): ?string => SmsGateway::normalise($n), $forms
));

printf("    %d spellings collapse to %d number(s)\n", count($forms), count($norm));

check('every spelling of one number normalises the same', count($norm), 1);

echo "\n--- the inbound webhook fails closed when unconfigured ---\n";

$secret = setting_fresh('sms_inbound_secret');

$ch = curl_init(test_base_url() . '/api/alerts/inbound.php');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => ['from' => '09171234567', 'message' => 'ZZ probe'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
]);
curl_exec($ch);
$inbound = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($secret === '') {
    check('unconfigured, it answers 503 rather than accepting', $inbound, 503);
} else {
    check('configured, an unsigned request is refused 401', $inbound, 401);
}

echo "\n--- the public contact form reaches the office inbox ---\n";

/* Walked the way a visitor walks it: load the homepage, keep the session
   cookie, read the token off the form. A post assembled from nothing is
   refused with a 403 — correctly, and my first version of this test read that
   refusal as a broken contact form. */
$sent = test_public_form('index.php', 'api/contact/submit.php', [
    'name'    => 'ZZ Visitor',
    'email'   => 'zz.visitor@example.test',
    'subject' => 'ZZ a question about the falls',
    'message' => 'ZZ is the trail open on Sundays?',
]);

printf("    token read from the page: %s   endpoint answered %d\n",
    $sent['token'] === '' ? 'NONE' : 'yes', $sent['code']);

check('a token was on the public form', $sent['token'] !== '', true);

$message = Database::first("SELECT * FROM contact_messages WHERE name = 'ZZ Visitor'");

check('the message was stored', $message !== null, true);

if ($message !== null) {
    check('with its subject', (string) $message['subject'], 'ZZ a question about the falls');

    $inbox = test_get_as($sid, 'admin/messages/index.php');

    check('and it is in the office inbox', str_contains($inbox, 'ZZ Visitor'), true);
}

echo "\n--- clean up ---\n";

Database::run("DELETE FROM announcements WHERE title LIKE 'ZZ %'");
Database::run("DELETE FROM contact_messages WHERE name LIKE 'ZZ %'");

check('announcements are back to where they started',
    (int) Database::scalar('SELECT COUNT(*) FROM announcements'), $before);
check('no probe message survives',
    (int) Database::scalar("SELECT COUNT(*) FROM contact_messages WHERE name LIKE 'ZZ %'"), 0);

test_finish();
