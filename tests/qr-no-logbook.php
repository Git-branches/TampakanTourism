<?php
declare(strict_types=1);

/**
 * The QR code asks a tourist for nothing.
 *
 * WHAT THIS GUARDS
 *
 * Feature 1 moved the QR sign from a digital logbook to a destination
 * information page. A visitor standing at the sign reads hotlines, spot
 * information, heritage and directions, and writes their name in the PAPER
 * logbook at the fill-up station. They type nothing into a phone.
 *
 * That is not a preference. The monthly Tourism Attraction Visitor Record filed
 * with the DOT is built from one source — an arrival report a destination
 * manager submitted and the Office approved — and every figure on it has to
 * answer "where did this come from" with a named manager, a date and a logbook
 * page. A tourist-typed arrival has none of those, and one route that still
 * writes them would put unsourced numbers on a government return.
 *
 * So this suite checks the guarantee from the outside, on EVERY destination
 * rather than one: no field on the page asks for a name, a contact or a visitor
 * count; nothing posts to the retired arrivals endpoint; and no page registers
 * the service worker that used to cache the old form for offline submission.
 *
 * THE ARCHIVED DESTINATION IS PART OF THE TEST, NOT AN EXCEPTION. Its code must
 * answer 404 rather than a page — a retired sign that still works is a sign
 * nobody can retire. An earlier version of this check demanded the phrase
 * "Nothing needs to be typed here" from every destination and flagged the
 * archived one for not carrying it, which was the check being wrong rather than
 * the page.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;

echo "\n=== the QR code asks for nothing ===\n\n";

if (!test_server_up()) {
    echo "  Apache is not answering — skipped.\n";
    test_finish();
}

$destinations = Database::all(
    'SELECT name, status, qr_token FROM destinations WHERE qr_token IS NOT NULL ORDER BY name'
);

if ($destinations === []) {
    echo "  No destination has a QR code — nothing to check.\n";
    test_finish();
}

$active   = 0;
$archived = 0;

/* Any of these on the page would mean the visitor is being asked to identify
   themselves, which is the thing that was removed. */
$identityField = '/name=["\'](full_name|visitor_name|name|contact|contact_number|mobile|address|visitors|total_visitors|visitor_type)["\']/i';

foreach ($destinations as $d) {
    $path = '/d/index.php?token=' . $d['qr_token'];
    $html = test_get($path);
    $code = test_status($path);

    if ($d['status'] !== 'active') {
        $archived++;
        check('a retired sign (' . $d['name'] . ') is refused', $code, 404);
        check('  and it offers no page to fill in', preg_match('/<form/', $html), 0);
        continue;
    }

    $active++;

    check($d['name'] . ': the code opens', $code, 200);

    check('  no field asks who the visitor is',
        (bool) preg_match($identityField, $html), false);

    check('  nothing posts to the retired arrivals endpoint',
        str_contains($html, 'api/arrivals/submit.php'), false);

    /* The service worker existed to queue logbook submissions made with no
       signal. Registering it now would install a cache for a form that is
       gone, and an old cached copy is how a retired page comes back. */
    check('  it registers no service worker',
        (bool) preg_match('/serviceWorker|sw\.js|manifest\.json/i', $html), false);

    /* One form is allowed and only one: the optional rating, which asks for a
       star and a comment and never for a name. */
    check('  the only form on it is the rating',
        preg_match_all('/<form/', $html), 1);

    check('  and it says so in words',
        str_contains($html, 'Nothing needs to be typed here'), true);
}

echo "\n  {$active} active destination(s) checked, {$archived} retired\n\n";

/* ---- the retired routes are gone, not merely closed --------------------- */

/* They used to be a 301 and a 410: compatibility for printed signs carrying the
   old address. The office confirmed on 2026-09-03 that nothing has been printed
   yet — the system has not launched — so there was no old sign to be
   compatible with, and the files were deleted rather than kept as a shim for a
   past that never happened.

   404 is now the correct answer, and this checks for it rather than assuming:
   a file deleted from the working tree but left on a server would still answer
   200, and that is exactly the kind of thing nobody looks at again. */
check('the old logbook address is gone',
    test_status('/logbook.php?token=' . $destinations[0]['qr_token']), 404);

check('the old arrivals endpoint is gone',
    test_status('/api/arrivals/submit.php'), 404);

check('the offline shell is gone',    test_status('/offline.html'), 404);
check('the service worker is gone',   test_status('/sw.js'), 404);
check('the web app manifest is gone', test_status('/manifest.json'), 404);
check('the old confirmation page is gone', test_status('/logbook-success.php'), 404);
check('the queue script is gone',     test_status('/assets/js/arrival-queue.js'), 404);

/* WHAT MUST NOT HAVE GONE WITH THEM.
   api/arrivals/token.php lives in the same directory and looks like part of the
   same retired feature. It is not: the chat widget takes its CSRF token from
   it, and deleting it would break the chatbot on every public page with no
   error pointing here. */
check('but the token endpoint the chat widget uses is still there',
    test_status('/api/arrivals/token.php') !== 404, true);

/* And the manager's own logbook page, which shares a filename with the retired
   one and is the screen the whole paper-logbook workflow depends on. */
check('and the manager transcription page still exists',
    file_on_disk('manager/logbook.php'), true);

/* The count on the DOT return must not move because someone probed a dead
   address. */
$before = (int) Database::scalar('SELECT COUNT(*) FROM tourist_arrivals');

test_get('/api/arrivals/submit.php');
test_get('/logbook.php');

check('a request to either creates no arrival row',
    (int) Database::scalar('SELECT COUNT(*) FROM tourist_arrivals'), $before);

test_finish();
