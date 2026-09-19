<?php
declare(strict_types=1);

/**
 * The categorised Contact Us modal and the reworked About section, end to end
 * through Apache.                      Presentation feedback, 15 September 2026
 *
 * WHAT THIS IS GUARDING.
 *
 * Three public forms now post to three endpoints and write to three different
 * tables, and one of those tables holds real personal data. Nothing between the
 * modal and the officer's screen would notice if a link in that chain broke —
 * the page would render, the button would spin, and the visitor would be told
 * their request had arrived. That is precisely how the original contact form
 * spent the project's whole life discarding every message sent through it.
 *
 * It also guards the two promises the About section makes:
 *   · no invented officials — an empty roster renders no team block at all;
 *   · what an officer types into Settings is what the homepage shows.
 *
 * EVERY ROW THIS SUITE CREATES IS DELETED BY ITS OWN ID, on the way out and
 * whatever the outcome. Nothing here restores a table wholesale, and nothing
 * here writes over a row it did not create.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;

echo "\n=== contact us: categories, modal, about section ===\n\n";

if (!test_server_up()) {
    echo "  SKIP — no web server answering at " . test_base_url() . "\n";
    exit(0);
}

/* ---------------------------------------------------------------------------
 | THE SUITE'S OWN RATE-LIMIT BUCKETS, CLEARED FIRST.
 |
 | Every submission below comes from 127.0.0.1, and the endpoints cap a data
 | request at three an hour and a guide rating at five. Those ceilings are
 | correct — a data request is a considered act and somebody filing a fourth in
 | an hour is flooding the form — but they mean this suite fails on its second
 | run of the hour, reporting a bug in code that is working exactly as designed.
 | A red suite that is wrong is worse than no suite.
 |
 | ONLY THE FIVE BUCKETS THIS FILE FILLS, addressed by the same hash the limiter
 | uses. Not a wipe of storage/ratelimit — that directory also holds the buckets
 | protecting the live arrival and contact endpoints, and clearing those to make
 | a test pass would take the brakes off the real forms.
 * ------------------------------------------------------------------------ */
$limitDir = __DIR__ . '/../storage/ratelimit';

/* Both spellings of localhost. Apache reports ::1 for a request that reached it
   over IPv6 and 127.0.0.1 over IPv4, and which one curl picks depends on how the
   host resolves "localhost" — so clearing only one leaves the suite failing on
   exactly the machines where the other is chosen. */
foreach (['127.0.0.1', '::1'] as $ip) {
    foreach ([
        'data-request', 'data-request-try',
        'guide-review', 'guide-review-try',
        'contact',      'contact-try',
    ] as $prefix) {
        $f = $limitDir . '/' . hash('sha256', $prefix . ':' . $ip) . '.json';

        if (is_file($f)) {
            @unlink($f);
        }
    }
}

/* ---------------------------------------------------------------------------
 | Cleanup, registered BEFORE anything is created.
 |
 | Registered first so an exception halfway through still takes its rows with
 | it. Every id is one this suite inserted; the lists start empty and only this
 | file ever appends to them.
 * ------------------------------------------------------------------------ */
$made = ['guide_reviews' => [], 'data_requests' => [], 'contact_messages' => []];

register_shutdown_function(static function () use (&$made): void {
    $removed = 0;

    foreach ($made as $table => $ids) {
        foreach ($ids as $id) {
            /* The table name is a key of the literal array above, never input.
               The id is bound. */
            Database::run("DELETE FROM {$table} WHERE id = ?", [(int) $id]);
            $removed++;
        }
    }

    echo "  ({$removed} test row(s) removed)\n";
});

/**
 * Posts a public form the way the modal does — as an XHR, so the endpoint
 * answers JSON instead of redirecting — and gives back the decoded answer.
 *
 * The suite asserts on the database rather than on this JSON wherever it can:
 * an endpoint that says "ok" and writes nothing is the exact failure being
 * guarded against, and its own answer is no evidence either way.
 *
 * @param array<string, mixed> $fields
 * @return array{code:int, json:array<string,mixed>|null, raw:string}
 */
function inquiry_post(string $postUrl, array $fields): array
{
    static $jar = null;

    if ($jar === null) {
        $jar = tempnam(sys_get_temp_dir(), 'toursync-inq');

        register_shutdown_function(static function () use ($jar): void {
            if (is_file($jar)) { @unlink($jar); }
        });
    }

    /* The homepage first, every time: it starts the session and prints the
       token. A POST assembled without one is refused with a 403, correctly —
       faking a token would be testing a hole rather than the form. */
    $ch = curl_init(test_base_url() . '/index.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $html = (string) curl_exec($ch);
    curl_close($ch);

    $token = preg_match('/name="_token"\s+value="([^"]+)"/', $html, $m) ? $m[1] : '';

    $ch = curl_init(test_base_url() . '/' . ltrim($postUrl, '/'));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => array_merge($fields, ['_token' => $token]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_HTTPHEADER     => ['X-Requested-With: XMLHttpRequest'],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'json' => json_decode($raw, true), 'raw' => $raw];
}

/* ---------------------------------------------------------------------------
 | 1 · The homepage renders the chooser and all three panels
 * ------------------------------------------------------------------------ */
echo "-- the page --\n";

$home = test_get('index.php');

check('homepage still renders',            $home !== '' && str_contains($home, '</html>'), true);
check('category chooser is on the page',   str_contains($home, 'id="cfCategory"'), true);
check('the enquiry modal is rendered',     str_contains($home, 'id="inquiryModal"'), true);
check('tour guide panel present',          str_contains($home, 'data-panel="tour-guide"'), true);
check('data request panel present',        str_contains($home, 'data-panel="data-request"'), true);
check('other concerns panel present',      str_contains($home, 'data-panel="other"'), true);

/* THE FORM IS NO LONGER SITTING ON THE PAGE. The contact section was the
   longest thing on the homepage; the point of the modal was to shorten it. If
   the inline card came back, the office gets the screen they asked to be rid
   of and nobody would call it a bug. */
check('contact section is compact, not a form', str_contains($home, 'contact-start__pick'), true);

/* All three endpoints are actually wired. A panel that renders and posts
   nowhere is the failure this whole suite exists for. */
foreach ([
    'guide review endpoint wired' => 'api/contact/guide-review.php',
    'data request endpoint wired' => 'api/contact/data-request.php',
    'contact endpoint wired'      => 'api/contact/submit.php',
] as $what => $path) {
    check($what, str_contains($home, $path), true);
}

/* ---------------------------------------------------------------------------
 | 2 · About — nothing is invented
 * ------------------------------------------------------------------------ */
echo "\n-- about: no invented officials --\n";

/* TWO NAMED PROFILES, NOT A ROSTER. The organisational chart that was here was
   withdrawn by the office on 2026-09-17 — they asked for the Mayor beside the
   Tampakan text and the Tourism Coordinator beside the Office text, and nobody
   else. The chart, its table and its admin panel are gone. */
check('the withdrawn team chart is not on the page',
    str_contains($home, 'Meet the Tourism Team') || str_contains($home, 'class="team"'), false);

/* The promise, checked in whichever direction the office's data puts it. A blank
   name must render no card at all; a filled one must render exactly what is
   stored. Both halves matter — a card that never appears is as broken as one
   carrying an invented name, and only one of the two is visible to anybody. */
foreach ([
    ['mayor',       'Mayor'],
    ['coordinator', 'Tourism Coordinator'],
] as [$key, $label]) {
    $name = trim((string) (setting_fresh('about_' . $key . '_name') ?: ''));
    $post = trim((string) (setting_fresh('about_' . $key . '_position') ?: ''));

    if ($name === '') {
        check("no {$label} set ⇒ no card for them", str_contains($home, e($label)), false);
        continue;
    }

    check("{$label}: the stored name is on the page", str_contains($home, e($name)), true);

    if ($post !== '') {
        check("{$label}: the stored position is on the page", str_contains($home, e($post)), true);
    }
}

check('an official profile card is rendered', str_contains($home, 'class="official"'), true);

/* THE BRIEF HISTORY comes from settings too, not from a literal in index.php.
   It is the municipality's own historical record — the dates, the Republic Act
   number, the meaning of "tamfaken" — and the office has to be able to correct
   it without anybody editing PHP. */
$history = trim((string) (setting_fresh('about_history') ?: ''));

/* The history runs on from the opening paragraph inside the About Tampakan
   column — no heading of its own, because to a reader the two are one piece of
   prose about the same subject. So what is asserted is the text, not a wrapper. */
if ($history !== '') {
    /* The opening 60 characters rather than the whole 1,100: nl2br() and e()
       both transform the stored text, so an exact match would be asserting on
       the escaping rather than on the content. */
    check('the brief history is on the page', str_contains($home, e(mb_substr($history, 0, 60))), true);
    check('and it sits in the About Tampakan column',
        str_contains($home, 'about-block__text'), true);
}

/* ---------------------------------------------------------------------------
 | 3 · Tour guide rating
 * ------------------------------------------------------------------------ */
echo "\n-- tour guide rating --\n";

$guide = Database::first("SELECT id, full_name FROM tour_guides WHERE status = 'active' ORDER BY id LIMIT 1");

if ($guide === null) {
    echo "  SKIP — no active guide on the roster to rate\n";
} else {
    check('the roster select offers a real guide', str_contains($home, e((string) $guide['full_name'])), true);

    /* rendered_at back-dated past the dwell timer. A genuine visitor takes
       longer than three seconds to choose a guide and type a comment; curl does
       not, and without this the endpoint correctly treats the post as a bot and
       answers with a silent success. */
    $res = inquiry_post('api/contact/guide-review.php', [
        'guide_id'      => (int) $guide['id'],
        'rating'        => 4,
        'comment'       => 'ZZ automated test rating — safe to delete.',
        'visitor_name'  => 'ZZ Test Visitor',
        'visitor_email' => 'zz-test@example.invalid',
        'rendered_at'   => time() - 30,
    ]);

    check('endpoint answers JSON', is_array($res['json']), true);
    check('endpoint reports success', $res['json']['ok'] ?? null, true);

    $row = Database::first(
        "SELECT * FROM guide_reviews WHERE comment LIKE 'ZZ automated test rating%' ORDER BY id DESC LIMIT 1"
    );

    check('a row was actually written', $row !== null, true);

    if ($row !== null) {
        $made['guide_reviews'][] = (int) $row['id'];

        check('it is attached to the right guide', (int) $row['guide_id'], (int) $guide['id']);
        check('the rating was stored',             (int) $row['rating'], 4);

        /* THE ONE GUARANTEE THIS TABLE MAKES. These name a private individual
           holding a municipal accreditation; a rating that published itself on
           submission would be the office publishing an unread comment about a
           named person. */
        check('it is PENDING, never published on submit', (string) $row['status'], 'pending');

        /* A second attempt from the same device is refused. Without this one
           person can rate the same guide from five tabs and the roster's
           average becomes whatever they wanted it to be. */
        $again = inquiry_post('api/contact/guide-review.php', [
            'guide_id'    => (int) $guide['id'],
            'rating'      => 1,
            'comment'     => 'ZZ automated duplicate — should be refused.',
            'rendered_at' => time() - 30,
        ]);

        check('a duplicate from the same device is refused', $again['json']['ok'] ?? null, false);

        $dupe = Database::first(
            "SELECT id FROM guide_reviews WHERE comment LIKE 'ZZ automated duplicate%' LIMIT 1"
        );

        /* Belt and braces: if the refusal ever stops working, the row is still
           cleaned up rather than left in the office's queue. */
        if ($dupe !== null) { $made['guide_reviews'][] = (int) $dupe['id']; }

        check('and no second row was written', $dupe === null, true);
    }

    /* A guide who is not active must be refused even when the id is valid. The
       select on the page only lists active ones; this is the check that holds
       when somebody posts the id anyway. */
    $inactive = Database::first("SELECT id FROM tour_guides WHERE status <> 'active' LIMIT 1");

    if ($inactive !== null) {
        $res = inquiry_post('api/contact/guide-review.php', [
            'guide_id'    => (int) $inactive['id'],
            'rating'      => 5,
            'comment'     => 'ZZ automated suspended-guide attempt.',
            'rendered_at' => time() - 30,
        ]);

        check('a suspended guide cannot be rated', $res['json']['ok'] ?? null, false);
    }
}

/* ---------------------------------------------------------------------------
 | 4 · Data request
 * ------------------------------------------------------------------------ */
echo "\n-- data request --\n";

$res = inquiry_post('api/contact/data-request.php', [
    'organisation'   => 'ZZ Test Organisation',
    'first_name'     => 'ZZTest',
    'middle_name'    => 'Automated',
    'last_name'      => 'Requester',
    'home_address'   => 'Prk. Test, Brgy. Poblacion, Tampakan',
    'birthdate'      => '1995-06-15',
    'civil_status'   => 'Single',
    'designation'    => 'Research Assistant',
    'email'          => 'zz-test@example.invalid',
    'contact_number' => '+63 900 000 0000',
    'purpose'        => 'An automated test of the data request form. Safe to delete.',
    'requested_data' => 'Monthly arrival totals for the automated test suite. Safe to delete.',
    'needed_by'      => date('Y-m-d', strtotime('+14 days')),
    'rendered_at'    => time() - 60,
]);

check('endpoint reports success', $res['json']['ok'] ?? null, true);

$row = Database::first("SELECT * FROM data_requests WHERE first_name = 'ZZTest' ORDER BY id DESC LIMIT 1");

check('a row was actually written', $row !== null, true);

if ($row !== null) {
    $made['data_requests'][] = (int) $row['id'];

    check('the organisation was stored', (string) $row['organisation'], 'ZZ Test Organisation');
    check('the birthdate was stored',    (string) $row['birthdate'], '1995-06-15');
    check('it arrives as new',           (string) $row['status'], 'new');

    /* THE REFERENCE IS HOW THE REQUESTER FOLLOWS UP without either side
       repeating a home address over email. If it stops being generated, or
       stops reaching them, the only way to ask after a request is to send the
       personal details again. */
    check('a reference was generated', (bool) preg_match('/^DR-[A-Z0-9]{5,}$/', (string) $row['reference']), true);
    check('the reference is in the answer', str_contains((string) ($res['json']['message'] ?? ''), (string) $row['reference']), true);

    /* AGE IS DERIVED, NEVER STORED. A stored age is wrong the day after the
       requester's birthday and nothing in the system would ever correct it. */
    check('no age column exists to go stale', array_key_exists('age', $row), false);

    $expected = (int) (new DateTimeImmutable('1995-06-15'))->diff(new DateTimeImmutable('today'))->y;
    check('age is derived from the birthdate',
        \App\Repositories\DataRequestRepository::age((string) $row['birthdate']), $expected);
}

/* The two dates that have an obvious wrong direction. Either one accepted
   silently leaves an officer a row to query by hand. */
$res = inquiry_post('api/contact/data-request.php', [
    'first_name' => 'ZZFuture', 'last_name' => 'Requester',
    'email' => 'zz-test@example.invalid',
    'purpose' => 'Automated check of birthdate validation.',
    'requested_data' => 'Automated check of birthdate validation.',
    'birthdate' => date('Y-m-d', strtotime('+2 days')),
    'rendered_at' => time() - 60,
]);

check('a birthdate in the future is refused', $res['json']['ok'] ?? null, false);

$res = inquiry_post('api/contact/data-request.php', [
    'first_name' => 'ZZPast', 'last_name' => 'Requester',
    'email' => 'zz-test@example.invalid',
    'purpose' => 'Automated check of completion-date validation.',
    'requested_data' => 'Automated check of completion-date validation.',
    'needed_by' => date('Y-m-d', strtotime('-2 days')),
    'rendered_at' => time() - 60,
]);

check('a completion date in the past is refused', $res['json']['ok'] ?? null, false);

/* Nothing was written by either refusal. A validation message shown over a row
   that was saved anyway is worse than no validation. */
$leaked = Database::all("SELECT id FROM data_requests WHERE first_name IN ('ZZFuture', 'ZZPast')");

foreach ($leaked as $l) { $made['data_requests'][] = (int) $l['id']; }

check('and neither refusal wrote a row', $leaked, []);

/* ---------------------------------------------------------------------------
 | 5 · Other concerns — the original form, moved
 * ------------------------------------------------------------------------ */
echo "\n-- other concerns --\n";

$res = inquiry_post('api/contact/submit.php', [
    'name'        => 'ZZ Test Sender',
    'email'       => 'zz-test@example.invalid',
    'phone'       => '+63 900 000 0000',
    'subject'     => 'Feedback',
    'category'    => 'other',
    'message'     => 'ZZ automated test message — safe to delete.',
    'rendered_at' => time() - 30,
]);

check('endpoint reports success', $res['json']['ok'] ?? null, true);

$row = Database::first(
    "SELECT * FROM contact_messages WHERE message LIKE 'ZZ automated test message%' ORDER BY id DESC LIMIT 1"
);

check('a row was actually written', $row !== null, true);

if ($row !== null) {
    $made['contact_messages'][] = (int) $row['id'];

    check('the category was recorded', (string) ($row['category'] ?? ''), 'other');

    /* The subject select was kept in the Other panel even though the brief
       listed only name/email/message, because the office's inbox filter is
       built from it. If the field is ever dropped, that filter starts offering
       topics no new message carries and quietly stops being useful. */
    check('the subject still reaches the inbox filter', (string) $row['subject'], 'Feedback');
}

/* ---------------------------------------------------------------------------
 | 6 · The officer can actually read all of it
 |
 | A row written into a table nobody can open is the same as no row at all.
 * ------------------------------------------------------------------------ */
echo "\n-- the officer's screens --\n";

[$sid, $token] = test_sign_in_officer();

$screen = test_get_as($sid, 'admin/messages/data-requests.php');
check('data requests screen renders', str_contains($screen, 'Data Requests'), true);

/* Signed IN, the list class is present. This is the anchor for the signed-out
   assertions further down: without it, renaming the class would make those pass
   because the string vanished rather than because the screen was closed. */
check('and it renders its list for an officer', str_contains($screen, 'class="record-list"'), true);

if (($made['data_requests'][0] ?? 0) > 0) {
    $ref = (string) Database::scalar('SELECT reference FROM data_requests WHERE id = ?',
        [$made['data_requests'][0]]);
    check('the new request is on it', str_contains($screen, $ref), true);
}

$screen = test_get_as($sid, 'admin/feedback/guides.php');
check('guide ratings screen renders', str_contains($screen, 'Tour Guide Ratings'), true);

/* The tab strip is what makes either screen findable — neither has its own
   sidebar entry. A strip that stops rendering leaves two working screens nobody
   can reach. */
check('messages carries the tab strip',
    str_contains(test_get_as($sid, 'admin/messages/index.php'), 'screen-tabs'), true);
check('feedback carries the tab strip',
    str_contains(test_get_as($sid, 'admin/feedback/index.php'), 'screen-tabs'), true);

/* ---------------------------------------------------------------------------
 | 7 · ACCESS CONTROL — the reason data_requests is its own table
 |
 | These rows hold a home address, a birthdate and a contact number. A signed-out
 | request for the screen must not return them.
 * ------------------------------------------------------------------------ */
echo "\n-- access control --\n";

$anon = test_get('admin/messages/data-requests.php');

/* The class named here must be the one the screen actually renders when it DOES
   render — otherwise this assertion passes because the string was renamed, not
   because the screen is closed. tests/contact-inquiry.php asserts the signed-in
   side above, which is what keeps the two honest. */
check('signed out, the screen does not render its list',
    str_contains($anon, 'class="record-list"'), false);

if (($made['data_requests'][0] ?? 0) > 0) {
    check('signed out, no personal data leaks',
        str_contains($anon, 'Prk. Test, Brgy. Poblacion'), false);
}

check('signed out, the guide ratings screen is closed too',
    str_contains(test_get('admin/feedback/guides.php'), 'class="record-list"'), false);

/* ---------------------------------------------------------------------------
 | 8 · ABOUT CONTENT — typed in Settings, read on the public page
 |
 | The whole point of the 17 Sep revision: the Mayor and the Tourism Coordinator
 | must be replaceable from Settings without anybody editing PHP. This walks the
 | path an officer actually walks — open Settings, change the name, save, look at
 | the website — because that is the connection nothing else would notice
 | breaking. The hero lost exactly this once, to a stale editor buffer: the admin
 | screen kept showing the uploaded pictures while the homepage quietly went back
 | to stock, and a person looking at the website found it.
 * ------------------------------------------------------------------------ */
echo "\n-- about content round trip --\n";

/* EVERY KEY THE about_save HANDLER WRITES, snapshotted before anything is
 * posted and put back at the end.
 *
 * Both halves matter. The handler writes `$_POST[$key] ?? ''` across its whole
 * list, so a post carrying only the two fields under test would blank the other
 * fifteen — the office's mission, vision and introduction gone, silently. So the
 * post below carries the CURRENT value of everything it is not testing.
 *
 * And nothing here restores a table wholesale: these are the only keys touched,
 * they are read before they are written, and they are put back by name.
 */
/* READ FROM THE DATABASE, NOT HAND-MAINTAINED.
 *
 * This was a literal list, and it cost a real field. `about_heritage` was added
 * to the save handler and not to the list; the post below then carried no value
 * for it, the handler stored '', and the restore — which only knew about the
 * keys in the list — put everything back EXCEPT that one. The office's cultural
 * heritage text was silently emptied by its own test suite.
 *
 * Every key the handler writes begins `about_`, so asking the settings table is
 * both the complete answer and one that cannot drift from the handler.
 */
/* READ OFF THE RENDERED PANEL, which is what a browser would post.
 *
 * Not from the settings table. The handler writes keys that may not have a row
 * yet, and a list built from existing rows is therefore SHORT — the handler
 * refuses a post missing any of its fields, so the save quietly does nothing and
 * every assertion below fails for a reason unrelated to what it is testing.
 * The panel's own inputs are the only list that is correct by construction. */
preg_match_all(
    '/name="(about_[a-z0-9_]+)"/',
    test_get_as($sid, 'admin/settings/index.php'),
    $panelNames
);

$aboutKeys = array_values(array_unique($panelNames[1]));
sort($aboutKeys);

$aboutBefore = [];

foreach ($aboutKeys as $k) {
    $aboutBefore[$k] = (string) (Database::scalar(
        'SELECT setting_value FROM settings WHERE setting_key = ?', [$k]
    ) ?? '');
}

/* If the table somehow holds none of them, the post below would blank the lot.
   Refuse rather than "pass" by doing nothing. */
if ($aboutKeys === []) {
    fwrite(STDERR, "  no about_* inputs found in the settings panel — cannot post the form\n");
    exit(1);
}

register_shutdown_function(static function () use ($aboutBefore): void {
    foreach ($aboutBefore as $k => $v) {
        Database::run(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$k, $v]
        );
    }
    echo "  (about settings restored)\n";
});

/* The five fields under test, and the current value of everything else. */
$edits = [
    'about_mayor_name'            => 'ZZ Hon. Test Mayor',
    'about_mayor_position'        => 'ZZ Municipal Mayor (test)',
    'about_coordinator_name'      => 'ZZ Test Coordinator',
    'about_coordinator_position'  => 'ZZ Municipal Tourism Coordinator (test)',
    'about_history'               => 'ZZ automated history paragraph for the test suite.',
];

$post = ['_token' => $token, 'action' => 'about_save'];

foreach ($aboutKeys as $k) {
    $post[$k] = $edits[$k] ?? $aboutBefore[$k];
}

$res = test_post('admin/settings/index.php', $sid, $post);

/* A 302 alone proves nothing here: the handler redirects whether it saved or
   refused the post. The "stored:" checks below are what actually distinguish
   the two, and they are the reason this one is not the whole test. */
check('the About save redirects, as the panel does', $res['code'], 302);

/* Stored, first. A page that happens to contain the string proves nothing if the
   row was never written. */
foreach ($edits as $k => $want) {
    check("stored: {$k}", setting_fresh($k), $want);
}

/* Then on the public page — the half that actually broke once. */
$page = test_get('index.php');

foreach ($edits as $k => $want) {
    check("on the public page: {$k}", str_contains($page, e($want)), true);
}

/* AND THE FIFTEEN FIELDS NOT UNDER TEST SURVIVED IT. This is the settings
   screen's known trap: one request reaching that save loop without a field
   blanks it. An earlier version of this panel could wipe the office profile. */
$collateral = [];

foreach ($aboutKeys as $k) {
    if (!isset($edits[$k]) && setting_fresh($k) !== $aboutBefore[$k]) {
        $collateral[] = $k;
    }
}

check('no other About field was altered', $collateral, []);

/* Mission and vision specifically, because the office asked for them to be left
   alone and because they have already vanished from this page once. */
check('mission and vision are still on the page',
    setting_fresh('about_mission_text') === '' ? true
        : str_contains($page, e(mb_substr($aboutBefore['about_mission_text'], 0, 40))), true);

/* A NAME CLEARED MUST CLEAR THE CARD, not leave a heading over an empty frame.
   This is the direction nobody tests and the one an office hits the day a Mayor
   leaves office before the next is sworn in. */
$post['about_coordinator_name'] = '';
test_post('admin/settings/index.php', $sid, $post);

check('clearing the Coordinator name removes their card',
    str_contains(test_get('index.php'), 'ZZ Municipal Tourism Coordinator (test)'), false);

test_finish();
