<?php
declare(strict_types=1);

/**
 * TourSync — the tour guide booking receipt.                         Feature 4
 *
 * What the tourist walks into the Municipal Tourism Office holding. Printable,
 * savable as a PDF, and readable from a phone with the radio off once saved —
 * the same reasoning as directions.php, and for the same visitors.
 *
 * WHY IT SAYS "REQUEST RECEIVED" AND NOT "BOOKING CONFIRMED"
 *
 * Nothing is confirmed at submission. The Office has to find somebody free,
 * which is a phone call to a person who may be up a mountain. A receipt that
 * says "confirmed" would send a tourist to a trailhead expecting a guide who
 * was never asked. It says what is true at each stage and changes wording when
 * the status changes.
 *
 * WHAT PROTECTS IT
 *
 * The reference code, and nothing else — there is no account to sign in to and
 * a tourist will not create one. So the codes are drawn from a 31-character
 * alphabet, five places, non-sequential: about 28.6 million per prefix. Guessing
 * one is not the risk; the risk is somebody looking over a shoulder, which is
 * also true of a paper receipt.
 *
 * Even so, this page shows the visitor's OWN details back to them and nothing
 * else — no other request, no internal note, no officer's name.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Session;

$reference = strtoupper(trim((string) ($_GET['ref'] ?? '')));

/* Shape-checked before it reaches the database, so a malformed code costs a
   regex rather than a query. */
$valid = (bool) preg_match('/^TG-[A-Z0-9]{5,8}$/', $reference);

$booking = null;
$allowed = true;

if ($valid) {
    $booking = Database::first(
        'SELECT g.*, d.name AS destination_name, d.slug AS destination_slug, d.barangay
           FROM tour_guide_requests g
           LEFT JOIN destinations d ON d.id = g.destination_id
          WHERE g.reference_code = ?',
        [$reference]
    );
}

/* EVERY DESTINATION ON THE REQUEST, not only the primary one.
 *
 * The query above joins destinations through g.destination_id, which holds the
 * first place the visitor chose. A request can carry several, and a receipt
 * that lists one of the three they asked for is a receipt they will bring to
 * the counter to argue with.
 *
 * No cap here. The SMS is charged by the segment and spells out two; this sheet
 * costs nothing to be long, so it names them all. */
$stops = $booking !== null
    ? App\Repositories\TourGuideRepository::destinationsFor((int) $booking['id'])
    : [];

/* THE LIMIT COUNTS MISSES, NOT VISITS.
 *
 * An earlier version charged every request, which is the wrong thing to
 * measure: a family of four each opening their own receipt from one hotel's
 * WiFi share a public address, and so does a whole barangay hall. They were
 * being rate-limited for reading a page addressed to them.
 *
 * What is worth limiting is guessing, and a guess is a MISS. Somebody working
 * through the code space accrues nothing but misses; a real visitor reloading
 * their own receipt accrues none at all.
 *
 * This is a nuisance guard rather than the protection. The protection is the
 * code space — five places from a 31-character alphabet is 28.6 million per
 * prefix, and at even a thousand tries an hour that is three thousand years. */
if ($booking === null) {
    $allowed = RateLimiter::allow('booking-miss:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 20, 900);
}

if ($booking === null) {
    http_response_code(404);
}

$officePhone   = trim((string) (setting('office_phone', '') ?? ''));
$officeAddress = trim((string) (setting('office_address', '') ?? ''));
$officeName    = trim((string) (setting('office_name', '') ?? '')) ?: 'Municipal Tourism Office';

/* What the visitor is told at each stage, in their words rather than the
   database's. 'new' deliberately does not promise a time. */
$state = match ((string) ($booking['status'] ?? '')) {
    'new'          => ['label' => 'Request received',  'tone' => 'wait', 'note' => 'The Tourism Office has your request and is arranging a guide.'],
    'acknowledged' => ['label' => 'Being arranged',    'tone' => 'wait', 'note' => 'The Office has seen your request and is finding a guide for you.'],
    'assigned'     => ['label' => 'Guide assigned',    'tone' => 'ok',   'note' => 'A guide has been assigned. Bring this receipt to the Tourism Office.'],
    'completed'    => ['label' => 'Completed',         'tone' => 'ok',   'note' => 'This visit is recorded as completed. Thank you for coming to Tampakan.'],
    'declined'     => ['label' => 'Not arranged',      'tone' => 'bad',  'note' => 'The Office could not arrange a guide for this request.'],
    'cancelled'    => ['label' => 'Cancelled',         'tone' => 'bad',  'note' => 'This request was cancelled.'],
    default        => ['label' => 'Unknown',           'tone' => 'wait', 'note' => ''],
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $booking === null ? 'Receipt not found' : 'Booking ' . e($reference) ?> — Tampakan Tourism</title>
<link rel="icon" href="<?= e(asset('img/tampakan_logo.png')) ?>" sizes="any">

<?php /* Self-contained, like directions.php. A receipt is saved and reopened
         later, often somewhere without a connection, and a stylesheet fetched
         from a CDN is one more thing that is not there when it is. */ ?>
<style>
    :root { --ink:#16211A; --muted:#5A6B60; --line:#D8E2DB; --forest:#123A1B; --gold:#B8801F; }
    * { box-sizing: border-box; }

    body {
        margin: 0 auto;
        padding: 1.5rem 1rem 3rem;
        max-width: 40rem;
        font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
        font-size: 15px;
        line-height: 1.6;
        color: var(--ink);
        background: #F4F6F5;
    }

    .sheet { background:#fff; border:1px solid var(--line); border-radius:10px; padding:1.75rem 1.5rem 2rem; }

    header { display:flex; align-items:center; gap:1rem; border-bottom:3px double var(--forest); padding-bottom:1rem; }
    .seal  { width:56px; height:56px; object-fit:contain; flex-shrink:0; }
    .office { margin:0; font-size:.7rem; letter-spacing:.12em; text-transform:uppercase; color:var(--forest); font-weight:700; }
    h1 { margin:.15rem 0 0; font-size:1.35rem; color:var(--forest); line-height:1.2; }

    .ref { margin:1.25rem 0 0; text-align:center; }
    .ref__label { margin:0; font-size:.72rem; letter-spacing:.14em; text-transform:uppercase; color:var(--muted); }
    .ref__code {
        margin:.2rem 0 0; font-size:2.1rem; font-weight:800; letter-spacing:.06em;
        font-family: ui-monospace, 'Courier New', monospace; color:var(--forest);
    }

    .state { display:inline-block; margin-top:.6rem; padding:.3rem .8rem; border-radius:999px; font-size:.8rem; font-weight:700; }
    .state--ok   { background:#E8F3E9; color:#1B5E20; }
    .state--wait { background:#FFF6E5; color:#8A5A00; }
    .state--bad  { background:#FDECEA; color:#8E1F1B; }

    .note { margin:.85rem 0 0; font-size:.92rem; color:var(--muted); text-align:center; }

    h2 { font-size:.72rem; letter-spacing:.11em; text-transform:uppercase; color:var(--forest);
         border-bottom:1px solid var(--line); padding-bottom:.35rem; margin:1.75rem 0 .6rem; }

    table { border-collapse:collapse; width:100%; font-size:.93rem; }
    th, td { text-align:left; padding:.45rem .1rem; border-bottom:1px solid var(--line); vertical-align:top; }
    th { width:42%; font-weight:500; color:var(--muted); }

    .guide { background:#E8F3E9; border-left:4px solid #2E7D32; padding:.9rem 1rem; border-radius:4px; margin-top:.6rem; }
    /* Blue rather than the green used for the guide, so the two blocks are not
       mistaken for each other at a glance on a printed sheet. */
    /* Shown once, at the top, and gone on its own after a few seconds — the
       request succeeded, so there is nothing here for the visitor to act on. */
    .flash { display:flex; align-items:flex-start; gap:.6rem; padding:.85rem 1rem;
             border-radius:6px; margin-bottom:1rem; font-size:.94rem; line-height:1.5;
             border-left:4px solid; transition:opacity .3s ease, transform .3s ease; }
    .flash--ok  { background:#E8F5E9; border-color:#2E7D32; color:#1B5E20; }
    .flash--bad { background:#FDECEA; border-color:#C62828; color:#8E1F1B; }
    .flash button { margin-left:auto; border:0; background:none; font-size:1.3rem;
                    line-height:1; color:inherit; opacity:.55; cursor:pointer; padding:0 .2rem; }
    .flash button:hover { opacity:1; }
    .flash.is-going { opacity:0; transform:translateY(-6px); }
    @media (prefers-reduced-motion: reduce) { .flash { transition:none; } }

    .contact { background:#E1F1FA; border-left:4px solid #0288D1; padding:.9rem 1rem; border-radius:4px; margin-top:.6rem; font-size:.92rem; }
    .guide strong { display:block; font-size:1.05rem; color:#1B5E20; }

    .next { background:#FDF8EE; border-left:4px solid var(--gold); padding:.9rem 1rem; border-radius:4px; margin-top:.6rem; font-size:.9rem; }
    .next ol { margin:.5rem 0 0; padding-left:1.2rem; }
    .next li { margin-bottom:.3rem; }

    footer { margin-top:1.75rem; padding-top:.9rem; border-top:1px solid var(--line); font-size:.76rem; color:var(--muted); }

    .actions { margin-bottom:1rem; display:flex; gap:.5rem; flex-wrap:wrap; }
    .actions button, .actions a {
        padding:.6rem 1.1rem; border:1px solid var(--forest); border-radius:8px;
        background:#fff; color:var(--forest); font:inherit; font-size:.88rem;
        text-decoration:none; cursor:pointer;
    }
    .actions .primary { background:var(--forest); color:#fff; }

    .missing { text-align:center; padding:2rem 1rem; }
    .missing h1 { margin-bottom:.5rem; }

    @media print {
        body { background:#fff; padding:0; max-width:none; font-size:11pt; }
        .sheet { border:0; padding:0; border-radius:0; }
        .actions, .no-print { display:none !important; }
    }
</style>
</head>
<body>

<?php if ($booking === null): ?>

    <div class="sheet missing">
        <img class="seal" src="<?= e(asset('img/tampakan_logo.png')) ?>" alt="" width="56" height="56">
        <h1>That receipt could not be found</h1>
        <p>
            <?= $allowed
                ? 'Check the reference on your confirmation — it looks like <strong>TG-4B7KP</strong>.'
                : 'Too many attempts from this connection. Please wait a few minutes and try again.' ?>
        </p>
        <?php if ($officePhone !== ''): ?>
            <p>Or call the <?= e($officeName) ?> on <strong><?= e($officePhone) ?></strong>.</p>
        <?php endif; ?>
        <p class="no-print" style="margin-top:1.25rem">
            <a href="<?= e(base_url('/tour-guide.php')) ?>">Request a tour guide</a>
        </p>
    </div>

<?php else: ?>

    <?php
    /* THE MESSAGE THE VISITOR WAS PROMISED.
     *
     * api/guides/request.php flashes "Request sent. Keep this receipt..." and
     * redirects here — and this page never read it. The flash sat in the
     * session until some other page with flash handling loaded, which meant it
     * appeared on the blank request form days later, congratulating somebody on
     * a request they had long since made.
     *
     * Read here, where the person actually is, about the receipt in front of
     * them. no-print because a printed sheet does not need it. */
    $receiptFlashes = Session::takeFlash();
    ?>

    <?php foreach ($receiptFlashes as $note): ?>
        <?php $good = ($note['type'] ?? '') === 'success'; ?>
        <div class="flash flash--<?= $good ? 'ok' : 'bad' ?> no-print"
             role="status" data-flash<?= $good ? ' data-flash-fades' : '' ?>>
            <span><?= e((string) ($note['message'] ?? '')) ?></span>
            <button type="button" data-flash-close aria-label="Dismiss">&times;</button>
        </div>
    <?php endforeach; ?>

    <div class="actions no-print">
        <button class="primary" onclick="window.print()">Print or save as PDF</button>
        <a href="<?= e(base_url('/tour-guide.php')) ?>">Request another guide</a>
    </div>

    <div class="sheet">
        <header>
            <img class="seal" src="<?= e(asset('img/tampakan_logo.png')) ?>"
                 alt="Official Seal of the Municipality of Tampakan" width="56" height="56">
            <div>
                <p class="office">Municipality of Tampakan &middot; South Cotabato</p>
                <h1>Tour Guide Request</h1>
            </div>
        </header>

        <div class="ref">
            <p class="ref__label">Reference</p>
            <p class="ref__code"><?= e($reference) ?></p>
            <span class="state state--<?= e($state['tone']) ?>"><?= e($state['label']) ?></span>
            <?php if ($state['note'] !== ''): ?>
                <p class="note"><?= e($state['note']) ?></p>
            <?php endif; ?>
        </div>

        <h2>Your visit</h2>
        <table>
            <tr>
                <?php /* Singular while there is one, plural once there are more —
                         a heading that says "Destinations" above a single line
                         reads as a list with something missing from it. */ ?>
                <th><?= count($stops) > 1 ? 'Destinations' : 'Destination' ?></th>
                <td>
                    <?php if (count($stops) > 1): ?>
                        <?php /* Numbered, because the order is the order they
                                 intend to walk it, and the guide reads this. */ ?>
                        <ol style="margin:0; padding-left:1.1rem">
                            <?php foreach ($stops as $stop): ?>
                                <li><?= e((string) $stop['name']) ?></li>
                            <?php endforeach; ?>
                        </ol>
                    <?php elseif (count($stops) === 1): ?>
                        <?= e((string) $stops[0]['name']) ?>
                        <?php if ($booking['barangay']): ?><br><small>Brgy. <?= e((string) $booking['barangay']) ?></small><?php endif; ?>
                    <?php else: ?>
                        <?php /* Two different silences. Somebody who asked to be
                                 advised is owed a sentence saying the Office will
                                 answer; somebody who simply skipped the field is
                                 not promised anything nobody agreed to. */ ?>
                        <?= !empty($booking['needs_advice'])
                            ? 'To be advised by the Office'
                            : 'Not specified' ?>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Date</th>
                <td><?= $booking['preferred_date']
                        ? e(format_date((string) $booking['preferred_date'], 'l, F j, Y'))
                        : 'Not specified' ?></td>
            </tr>
            <?php if ($booking['preferred_time']): ?>
                <tr><th>Time</th><td><?= e(App\Repositories\TourGuideRepository::formatTime(
                    (string) $booking['preferred_time'])) ?></td></tr>
            <?php endif; ?>
            <tr><th>Number of visitors</th><td><?= n((int) $booking['party_size']) ?></td></tr>
        </table>

        <h2>Your details</h2>
        <table>
            <tr><th>Name</th><td><?= e((string) $booking['visitor_name']) ?></td></tr>
            <tr><th>Contact number</th><td><?= e((string) $booking['contact_number']) ?></td></tr>
            <?php if ($booking['contact_email']): ?>
                <tr><th>Email</th><td><?= e((string) $booking['contact_email']) ?></td></tr>
            <?php endif; ?>
            <?php if ($booking['notes']): ?>
                <tr><th>Your notes</th><td><?= nl2br(e((string) $booking['notes'])) ?></td></tr>
            <?php endif; ?>
            <tr><th>Requested</th><td><?= e(format_date((string) ($booking['issued_at'] ?: $booking['created_at']), 'F j, Y \a\t g:i A')) ?></td></tr>
        </table>

        <?php if ($booking['status'] === 'assigned' && $booking['guide_name']): ?>
            <h2>Your guide</h2>
            <div class="guide">
                <strong><?= e((string) $booking['guide_name']) ?></strong>
                <?php if ($booking['guide_contact']): ?>
                    <?= e((string) $booking['guide_contact']) ?>
                <?php endif; ?>
            </div>

            <h2>Where to meet</h2>
            <?php /* Recorded on the request when it was assigned, not looked up
                     now — an office that moves next year must not silently
                     rewrite the address on every arrangement it ever made. */ ?>
            <p><?= nl2br(e((string) ($booking['meeting_point']
                ?: App\Repositories\TourGuideRepository::officeMeetingPoint()))) ?></p>
        <?php endif; ?>

        <?php if ($booking['office_note']): ?>
            <h2>From the Tourism Office</h2>
            <p><?= nl2br(e((string) $booking['office_note'])) ?></p>
        <?php endif; ?>

        <?php if (!in_array($booking['status'], ['declined', 'cancelled', 'completed'], true)): ?>
            <?php
            /* THIS USED TO NAME A PLACE.
             *
             * "Come to the Municipal Tourism Office and meet your guide there" —
             * on every receipt, whatever had been arranged. The form promised,
             * three steps earlier, to text the visitor where to meet, and the
             * time picker accepts 5:00 AM, which no municipal office is open
             * for. A visitor who read this and turned up at the hall at dawn
             * would have been following the instructions.
             *
             * The office says it varies. So the receipt says what is true at
             * each stage: before a guide is assigned, that the Office will
             * confirm; afterwards, the place they actually chose, printed above. */
            $arranged = $booking['status'] === 'assigned' && $booking['meeting_point'];
            ?>
            <h2>What happens next</h2>
            <div class="next">
                <ol>
                    <li>Keep this receipt &mdash; print it, or save it to your phone.</li>

                    <?php if ($arranged): ?>
                        <li>Come to the <?= e($officeName) ?> at the time you asked for.</li>
                        <li>Show this reference. Your guide meets you there.</li>
                    <?php else: ?>
                        <li>
                            The <?= e($officeName) ?> finds a guide and texts you their
                            name and number.
                        </li>
                        <li>
                            Come to the office at the time you asked for and show this
                            reference. This page updates as soon as a guide is assigned
                            &mdash; the same link.
                        </li>
                    <?php endif; ?>
                </ol>
            </div>
        <?php endif; ?>

        <?php
        /* QUESTIONS, INQUIRIES, CONCERNS.
         *
         * The number was already on this page, inside an if that dropped the
         * whole line when the setting was blank — and it is blank. A visitor
         * with a question was shown nothing at all and had no way to ask.
         *
         * Its own block now, and when no number has been set the receipt says
         * where to go rather than pretending there is nothing to say. */
        ?>
        <h2>Questions?</h2>
        <div class="contact">
            <p style="margin:0 0 .35rem"><strong><?= e($officeName) ?></strong></p>

            <?php if ($officeAddress !== ''): ?>
                <p style="margin:0 0 .35rem"><?= e($officeAddress) ?></p>
            <?php endif; ?>

            <?php if ($officePhone !== ''): ?>
                <p style="margin:0">
                    Telephone <strong><?= e($officePhone) ?></strong> &mdash; call us about
                    anything to do with this request.
                </p>
            <?php else: ?>
                <p style="margin:0">
                    Call in at the office above with your reference for any question
                    about this request.
                </p>
            <?php endif; ?>
        </div>

        <footer>
            <?= e($officeName) ?>, Tampakan, South Cotabato.
            This is a request receipt, not a payment receipt &mdash; no fee has been charged.
            Shown at <?= e(base_url('/booking.php?ref=' . $reference)) ?>
        </footer>
    </div>

<?php endif; ?>

<script>
(function () {
    /* A SUCCESS MESSAGE GOES ON ITS OWN; A FAILURE WAITS TO BE READ.
     *
     * The request went through — there is nothing on this page for the visitor
     * to correct, so the message has done its job in a few seconds. Anything
     * that is not a success stays until it is dismissed, because a reason that
     * vanishes on a count of five is a reason nobody read. */
    document.querySelectorAll('[data-flash]').forEach(function (note) {
        var go = function () {
            note.classList.add('is-going');
            window.setTimeout(function () {
                if (note.parentNode) { note.parentNode.removeChild(note); }
            }, 320);
        };

        var closer = note.querySelector('[data-flash-close]');
        if (closer) { closer.addEventListener('click', go); }

        if (note.hasAttribute('data-flash-fades')) {
            var timer = window.setTimeout(go, 5000);

            /* Hovering pauses it. Somebody still reading should not have it
               taken away mid-sentence. */
            note.addEventListener('mouseenter', function () { window.clearTimeout(timer); });
            note.addEventListener('mouseleave', function () { timer = window.setTimeout(go, 2500); });
        }
    });
})();
</script>
</body>
</html>
