<?php
declare(strict_types=1);

/**
 * TourSync — request a tour guide.                                   Feature 4
 *
 * ONE FORM, REACHED FROM THREE PLACES. The QR sign at a destination, the public
 * page for that destination, and the main navigation all arrive here. An
 * earlier sketch put a copy of the form on each of those pages; three copies of
 * a form is three places to fix a validation rule and two of them get missed.
 * The destination travels in the query string instead.
 *
 * WHAT ARRIVING FROM A QR CODE CHANGES
 *
 * Somebody who scanned the sign at Jadas Falls has already told us where they
 * are. Asking them again in a dropdown is a question with an obvious answer,
 * and a form that opens with a redundant question reads as a form that was not
 * written for you. So the destination is confirmed as a fact they can change,
 * not asked as a choice they must make.
 *
 * WHAT IS NOT ASKED FOR
 *
 * No account, no email verification, no captcha. A visitor standing at a
 * waterfall with one bar of signal will not complete any of those, and the
 * request is worth more than the small amount of spam a honeypot and a rate
 * limit do not already catch.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Repositories\TourGuideRepository as Guides;
use App\Core\Session;

/* Which destination, if any. Accepts a slug so the QR sign and the public page
   can link here without knowing an id, and it degrades to a general request
   rather than an error when the slug is stale. */
$slug        = trim((string) ($_GET['d'] ?? ''));
$fromQr      = ($_GET['src'] ?? '') === 'qr';
$destination = null;

if ($slug !== '') {
    $destination = Database::first(
        "SELECT id, name, slug, barangay FROM destinations WHERE slug = ? AND status = 'active'",
        [$slug]
    );
}

/* Everywhere a guide could be requested for, so somebody arriving from the
   navigation can pick one. Only active destinations — offering a guide to a
   site the office has archived wastes both their time. */
$destinations = Database::all(
    "SELECT id, name FROM destinations WHERE status = 'active' ORDER BY name"
);

/* Whatever they typed last time, if the endpoint bounced them back. Retyping a
   whole form because one field was wrong is the reason people abandon it. */
$old = Session::get('_guide_old', []);
Session::forget('_guide_old');
$old = is_array($old) ? $old : [];

$errors = Session::get('_guide_errors', []);
Session::forget('_guide_errors');
$errors = is_array($errors) ? $errors : [];

$val = static fn(string $key, string $fallback = ''): string => (string) ($old[$key] ?? $fallback);

$flashes     = Session::takeFlash();
$officePhone = trim((string) (setting('office_phone', '') ?? ''));
$officeHours = trim((string) (setting('office_hours', '') ?? ''));

/* WHICH DESTINATIONS ARE ALREADY SETTLED.
 *
 * A list now rather than one id, because a request can carry several. It comes
 * from a bounced submission if there was one, otherwise from the QR sign or the
 * link that brought them here, otherwise it is empty.
 *
 * Filtered against the active list rather than trusted: a stale slug or an id
 * typed into the query string must not put a destination on the form that the
 * endpoint will then refuse. */
$chosenIds = $old['destination_ids'] ?? null;

if (!is_array($chosenIds)) {
    $chosenIds = isset($destination['id']) ? [(int) $destination['id']] : [];
}

$active    = array_column($destinations, 'id');
$chosenIds = array_values(array_unique(array_filter(
    array_map('intval', $chosenIds),
    static fn(int $id): bool => $id > 0 && in_array($id, array_map('intval', $active), true)
)));

/* The picker collapses into a confirmation only when the visitor arrived with
   exactly one destination settled and has not since asked for more. Two or more
   is a list they built themselves, and hiding it behind "Jadas Falls — Change"
   would be the form forgetting what they just told it. */
$chosenId = $chosenIds[0] ?? 0;
$chosen   = null;

if (count($chosenIds) === 1) {
    foreach ($destinations as $row) {
        if ((int) $row['id'] === $chosenId) {
            $chosen = $row;
            break;
        }
    }
}

/* Whether they deliberately asked to be advised, as opposed to simply not
   having chosen. Survives a bounce so the form comes back the way they left it. */
$needsAdvice = !empty($old['needs_advice']) && $chosenIds === [];

/* One blank row when nothing is settled — the form opens with exactly one
   dropdown, the way it always has. */
$rows = $chosenIds !== [] ? $chosenIds : [0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Request a Tour Guide — Tampakan Tourism</title>
<meta name="description" content="Ask the Municipal Tourism Office of Tampakan to arrange a local guide for your visit.">
<link rel="icon" href="<?= e(asset('img/tampakan_logo.png')) ?>" sizes="any">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body id="top">

<?php
$showNavbar = true;
require __DIR__ . '/app/views/partials/public-nav.php';
?>

<main>

<?php
/* THE SAME HEADER THE MAP AND THE ANNOUNCEMENTS USE.
 *
 * This page briefly had a white masthead of its own. It sat one click from the
 * map's green banner and the two looked like different websites — which is what
 * happens when a header is copied rather than shared. */
$head = [
    'title'  => 'Request a Tour Guide',
    'sub'    => 'Tell us where you are going and when. The Municipal Tourism Office arranges a local guide and texts you their name and number.',
    'crumbs' => [
        ['label' => 'Home', 'href' => base_url('/')],
        ['label' => 'Tour Guide'],
    ],
];
require __DIR__ . '/app/views/partials/page-head.php';
?>

<section class="section section--light tgr-section">
    <div class="container">

        <?php /* THE ANSWER, when there is one. Shown above everything and wide,
                 because somebody returning from a submission is looking for
                 exactly one thing and should not have to find it inside the
                 form they already filled in. */ ?>
        <?php foreach ($flashes as $flash): ?>
            <?php $ok = $flash['type'] === 'success'; ?>
            <?php /* A message about the form, dismissed the moment somebody
                     starts working on the form. It used to sit there while the
                     visitor typed the very name it was asking for, contradicting
                     the screen underneath it. Timers were not the answer: a
                     failure should stay as long as it is still true. */ ?>
            <div class="tgr-result tgr-result--<?= $ok ? 'ok' : 'bad' ?>" role="status"
                 data-result<?= $ok ? ' data-result-fades' : '' ?>>
                <i class="fa-solid <?= $ok ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                <div>
                    <strong><?= $ok ? 'Request sent' : 'Not sent' ?></strong>
                    <p><?= e($flash['message']) ?></p>
                </div>
                <button type="button" class="tgr-result__close" data-result-close
                        aria-label="Dismiss this message">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>
        <?php endforeach; ?>

        <div class="tgr-layout">

            <!-- ===================== THE FORM ===================== -->
            <div class="tgr-main">
                <form class="tgr-card" method="post" action="<?= e(base_url('/api/guides/request.php')) ?>" novalidate>
                    <?= csrf_field() ?>

                    <?php /* Honeypot and dwell time, the same pair guarding every
                             other public form here. Neither costs a real visitor
                             anything. */ ?>
                    <div class="visually-hidden" aria-hidden="true">
                        <label for="website">Leave this blank</label>
                        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                    </div>
                    <input type="hidden" name="rendered_at" value="<?= time() ?>">
                    <input type="hidden" name="source" value="<?= $fromQr ? 'qr' : 'website' ?>">

                    <!-- ---------- where ---------- -->
                    <fieldset class="tgr-fieldset">
                        <legend class="tgr-legend"><span>1</span> Where are you going?</legend>

                        <?php if ($chosen !== null): ?>
                            <?php /* Confirmed, not asked. They scanned the sign or
                                     followed the link from the spot page — the
                                     answer is already known, and a dropdown here
                                     would be a question with one obvious answer. */ ?>
                            <div class="tgr-chosen" id="chosenBox">
                                <i class="fa-solid fa-location-dot"></i>
                                <span>
                                    <strong><?= e((string) $chosen['name']) ?></strong>
                                    <small><?= $fromQr ? 'From the QR sign at this destination' : 'Selected destination' ?></small>
                                </span>
                                <button type="button" class="tgr-chosen__change" id="changeDest">Change</button>
                            </div>
                        <?php endif; ?>

                        <?php /* ONE DROPDOWN THAT REPEATS, NOT A MULTI-SELECT.
                                 A native <select multiple> asks people to
                                 ctrl-click, which is not a gesture that exists
                                 on a phone — and this form is filled in on a
                                 phone at a trailhead more often than anywhere
                                 else. So the control stays the dropdown they
                                 already know, and there is simply another one
                                 when they want another one.

                                 Works with JavaScript off: what renders below is
                                 one ordinary select that posts one destination,
                                 which is the form exactly as it was before. The
                                 script adds the second and the third. */ ?>
                        <div class="tgr-field <?= $chosen !== null ? 'is-hidden' : '' ?>" id="destPicker">
                            <div id="destList" data-dest-list>
                                <?php foreach ($rows as $index => $rowId): ?>
                                    <div class="tgr-dest" data-dest-row>
                                        <label for="dest<?= (int) $index ?>" data-dest-label>
                                            Destination<?= count($rows) > 1 ? ' ' . ((int) $index + 1) : '' ?>
                                        </label>
                                        <div class="tgr-dest__control">
                                            <select id="dest<?= (int) $index ?>" name="destination_ids[]"
                                                    data-dest-select
                                                    class="tgr-input <?= isset($errors['destination_ids']) ? 'is-invalid' : '' ?>">
                                                <?php /* Only the first selector offers "not sure". It is a
                                                         statement about the whole request, not about one line
                                                         of a list — offering it on row 2 would invite exactly
                                                         the contradiction this rule exists to prevent. */ ?>
                                                <?php if ($index === 0): ?>
                                                    <option value="">I am not sure yet &mdash; please advise</option>
                                                <?php else: ?>
                                                    <option value="">Choose a destination</option>
                                                <?php endif; ?>
                                                <?php foreach ($destinations as $d): ?>
                                                    <option value="<?= (int) $d['id'] ?>" <?= (int) $rowId === (int) $d['id'] ? 'selected' : '' ?>>
                                                        <?= e((string) $d['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php /* Hidden on the first row by the script, which is also the
                                                     only row that can exist without it. Rendered for every row
                                                     so a bounced submission comes back removable. */ ?>
                                            <button type="button" class="tgr-dest__remove" data-dest-remove
                                                    aria-label="Remove this destination">
                                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <button type="button" class="tgr-dest-add" id="destAdd">
                                <i class="fa-solid fa-plus" aria-hidden="true"></i> Add another destination
                            </button>

                            <?php /* Says why the button is unavailable, at the moment it is
                                     unavailable. A disabled control with no explanation is a
                                     dead end somebody reloads the page over. */ ?>
                            <p class="tgr-hint" id="destAdviceHint">
                                Choose a destination to add another &mdash; or leave this as
                                <em>I am not sure yet</em> and the Office will suggest a route.
                            </p>

                            <p class="tgr-error" id="destConflict" role="alert" hidden>
                                Please choose either specific destinations or
                                &ldquo;I am not sure yet &mdash; please advise&rdquo;, but not both.
                            </p>

                            <?php if (isset($errors['destination_ids'])): ?>
                                <p class="tgr-error"><?= e($errors['destination_ids']) ?></p>
                            <?php endif; ?>
                        </div>

                        <?php /* Carries the deliberate "advise me" through a bounce. Set by the
                                 script; absent with JavaScript off, where an empty selector is
                                 read the same way it always was. */ ?>
                        <input type="hidden" name="needs_advice" id="needsAdvice"
                               value="<?= $needsAdvice ? '1' : '0' ?>">

                    <?php /* Date, time and group size on one line. They are three
                             short answers about the same thing — when — and
                             stacking them was most of why this page scrolled. */ ?>
                    <div class="tgr-row tgr-row--3">
                        <div class="tgr-field">
                            <label for="preferred_date">Date of visit</label>
                            <input type="date" id="preferred_date" name="preferred_date" class="tgr-input"
                                   min="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d', strtotime('+1 year'))) ?>"
                                   value="<?= e($val('preferred_date')) ?>">
                            <?php if (isset($errors['preferred_date'])): ?>
                                <p class="tgr-error"><?= e($errors['preferred_date']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="tgr-field">
                            <label for="preferred_time">Time <small>optional</small></label>
                            <?php
                            /* A LIST OF TWENTY-SIX BECAME A WALL.
                             *
                             * This was a text box, then a <select> of every half
                             * hour between opening and closing. The select fixed
                             * the data — comparable values the office can sort a
                             * day by — but a native dropdown with twenty-six
                             * entries opens taller than the screen and, near the
                             * bottom of a page, opens upward across everything
                             * above it. It looked broken because it was too long
                             * to be anything else.
                             *
                             * <input type="time"> is the control the browser
                             * already has for this: a spinner on a desktop, the
                             * native wheel on a phone, and one line high in both.
                             * step=1800 makes it move in half hours; min and max
                             * are hints, so the server checks the same range. */
                            ?>
                            <input type="time" id="preferred_time" name="preferred_time"
                                   class="tgr-input <?= isset($errors['preferred_time']) ? 'is-invalid' : '' ?>"
                                   step="1800"
                                   min="<?= e(Guides::TIME_OPENS) ?>"
                                   max="<?= e(Guides::TIME_CLOSES) ?>"
                                   value="<?= e($val('preferred_time')) ?>">

                            <?php /* The label already says optional, so the hint only
                                     carries what it alone knows. Three lines of it
                                     made this column taller than the two beside it. */ ?>
                            <p class="tgr-hint">
                                Sites open <?= e(Guides::formatTime(Guides::TIME_OPENS)) ?>&ndash;<?= e(Guides::formatTime(Guides::TIME_CLOSES)) ?>.
                            </p>

                            <?php if (isset($errors['preferred_time'])): ?>
                                <p class="tgr-error"><?= e($errors['preferred_time']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="tgr-field">
                            <label for="party_size">Group size</label>
                            <?php /* A stepper rather than a bare number field. On a
                                     phone the native spinners are a few pixels
                                     wide, and this is the field most people need
                                     to nudge by one. */ ?>
                            <div class="tgr-stepper">
                                <button type="button" data-step="-1" aria-label="One fewer">
                                    <i class="fa-solid fa-minus"></i>
                                </button>
                                <?php /* Blank, not 1. A prefilled figure is a figure
                                         somebody can skip past without reading, and
                                         a party of four booked as one is a guide
                                         arriving alone to meet four people. Asking
                                         costs a tap; guessing costs the visit. */ ?>
                                <input type="number" id="party_size" name="party_size" required
                                       min="1" max="200" inputmode="numeric" placeholder="0"
                                       class="<?= isset($errors['party_size']) ? 'is-invalid' : '' ?>"
                                       value="<?= e($val('party_size')) ?>">
                                <button type="button" data-step="1" aria-label="One more">
                                    <i class="fa-solid fa-plus"></i>
                                </button>
                            </div>
                            <?php if (isset($errors['party_size'])): ?>
                                <p class="tgr-error"><?= e($errors['party_size']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    </fieldset>

                    <!-- ---------- who ---------- -->
                    <fieldset class="tgr-fieldset">
                        <legend class="tgr-legend"><span>2</span> How do we reach you?</legend>

                        <div class="tgr-row">
                            <div class="tgr-field">
                                <label for="visitor_name">Your name <em>*</em></label>
                                <input type="text" id="visitor_name" name="visitor_name" required maxlength="120"
                                       autocomplete="name" placeholder="Full name"
                                       class="tgr-input <?= isset($errors['visitor_name']) ? 'is-invalid' : '' ?>"
                                       value="<?= e($val('visitor_name')) ?>">
                                <?php if (isset($errors['visitor_name'])): ?>
                                    <p class="tgr-error"><?= e($errors['visitor_name']) ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="tgr-field">
                                <label for="contact_number">Mobile number <em>*</em></label>
                                <input type="tel" id="contact_number" name="contact_number" required maxlength="20"
                                       autocomplete="tel" inputmode="tel" placeholder="09XX XXX XXXX"
                                       class="tgr-input <?= isset($errors['contact_number']) ? 'is-invalid' : '' ?>"
                                       value="<?= e($val('contact_number')) ?>">
                                <p class="tgr-hint">
                                    <i class="fa-solid fa-comment-sms"></i>
                                    We text the guide's name and number here.
                                </p>
                                <?php if (isset($errors['contact_number'])): ?>
                                    <p class="tgr-error"><?= e($errors['contact_number']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="tgr-field">
                            <label for="contact_email">Email <small>optional</small></label>
                            <input type="email" id="contact_email" name="contact_email" maxlength="190"
                                   autocomplete="email" placeholder="you@example.com"
                                   class="tgr-input <?= isset($errors['contact_email']) ? 'is-invalid' : '' ?>"
                                   value="<?= e($val('contact_email')) ?>">
                            <?php if (isset($errors['contact_email'])): ?>
                                <p class="tgr-error"><?= e($errors['contact_email']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="tgr-field tgr-field--last">
                            <label for="notes">Anything the guide should know? <small>optional</small></label>
                            <textarea id="notes" name="notes" rows="2" maxlength="600" class="tgr-input"
                                      placeholder="Languages, mobility needs, children in the group, what you hope to see."><?= e($val('notes')) ?></textarea>
                            <p class="tgr-hint" id="notesCount"></p>
                        </div>
                    </fieldset>

                    <button type="submit" class="tgr-submit">
                        <i class="fa-solid fa-paper-plane"></i> Send Request
                    </button>

                    <p class="tgr-privacy">
                        <i class="fa-solid fa-lock"></i>
                        Your name and number go only to the Municipal Tourism Office so they can arrange
                        your guide and reach you. They are never shown on this website.
                    </p>
                </form>
            </div>

            <!-- ===================== ASIDE ===================== -->
            <aside class="tgr-aside">

                <?php /* WHAT HAPPENS NEXT, and it is on the page before they
                         submit rather than only after. The commonest reason a
                         government form is abandoned halfway is not knowing
                         whether anything will come of it. */ ?>
                <div class="tgr-steps">
                    <h2>What happens next</h2>
                    <ol>
                        <li>
                            <span class="tgr-steps__num">1</span>
                            <div>
                                <strong>The Office is alerted</strong>
                                <p>Your request reaches them straight away, on screen and by text.</p>
                            </div>
                        </li>
                        <li>
                            <span class="tgr-steps__num">2</span>
                            <div>
                                <strong>They find a guide</strong>
                                <p>Someone local who knows the site, the trail and the conditions that day.</p>
                            </div>
                        </li>
                        <li>
                            <span class="tgr-steps__num">3</span>
                            <div>
                                <strong>You get a text</strong>
                                <p>With the guide's name, their number, and where to meet.</p>
                            </div>
                        </li>
                    </ol>
                </div>

                <div class="tgr-note">
                    <i class="fa-solid fa-circle-info"></i>
                    <p>
                        A guide is arranged by the Tourism Office, not booked automatically.
                        Give as much notice as you can, especially for large groups.
                    </p>
                </div>

                <?php if ($officePhone !== ''): ?>
                    <div class="tgr-call">
                        <p class="tgr-call__label">In a hurry, or travelling today?</p>
                        <a class="tgr-call__link" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $officePhone) ?? '') ?>">
                            <i class="fa-solid fa-phone"></i> <?= e($officePhone) ?>
                        </a>
                        <?php if ($officeHours !== ''): ?>
                            <p class="tgr-call__hours"><?= e($officeHours) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </aside>
        </div>
    </div>
</section>
</main>

<?php require __DIR__ . '/app/views/partials/public-footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    'use strict';

    /* The confirmed destination can be undone. Progressive enhancement: with
       JavaScript off the picker is simply visible from the start, which is the
       plain form and still works. */
    var change = document.getElementById('changeDest');

    if (change) {
        change.addEventListener('click', function () {
            document.getElementById('chosenBox').remove();
            var picker = document.getElementById('destPicker');
            picker.classList.remove('is-hidden');
            picker.querySelector('select').focus();
        });
    }

    /* ---- The repeating destination picker -------------------------------
       One dropdown to start with. "Add another destination" produces a second,
       and the two rules that keep the list coherent are enforced here as well
       as at the endpoint:

         a destination cannot be chosen twice   — already-taken options are
                                                  disabled, so the duplicate is
                                                  unreachable rather than
                                                  rejected after the fact

         "I am not sure yet" cannot sit beside a real destination — it is a
                                                  statement about the whole
                                                  request, so it exists only on
                                                  the first row and only while
                                                  that row is alone

       Everything below is enhancement. With the script off the page is one
       ordinary select posting one destination, which is the form as it was. */
    var list     = document.getElementById('destList');
    var addBtn   = document.getElementById('destAdd');
    var advice   = document.getElementById('destAdviceHint');
    var conflict = document.getElementById('destConflict');
    var advised  = document.getElementById('needsAdvice');

    if (list && addBtn) {
        var rows = function () {
            return Array.prototype.slice.call(list.querySelectorAll('[data-dest-row]'));
        };

        var selects = function () {
            return Array.prototype.slice.call(list.querySelectorAll('[data-dest-select]'));
        };

        /* How many destinations exist at all. Adding an eleventh row when the
           municipality has ten places is a row that can only stay empty. */
        var ceiling = list.querySelector('[data-dest-select]').options.length - 1;

        var refresh = function () {
            var all   = rows();
            var many  = all.length > 1;
            var taken = [];

            selects().forEach(function (select) {
                if (select.value !== '') { taken.push(select.value); }
            });

            all.forEach(function (row, index) {
                var select = row.querySelector('[data-dest-select]');
                var label  = row.querySelector('[data-dest-label]');
                var remove = row.querySelector('[data-dest-remove]');

                /* "Destination" while there is one, "Destination 1" and
                   "Destination 2" once there are more. A lone field numbered 1
                   is a form implying a second one is expected. */
                select.id = 'dest' + index;
                label.setAttribute('for', select.id);
                label.textContent = many ? 'Destination ' + (index + 1) : 'Destination';

                /* The only row cannot be removed — it is the field itself. */
                remove.hidden = !many;

                Array.prototype.forEach.call(select.options, function (option) {
                    if (option.value === '') { return; }

                    option.disabled = option.value !== select.value
                        && taken.indexOf(option.value) !== -1;
                });
            });

            /* Nothing to add when the first row is still asking to be advised,
               and nothing to add once every destination is spoken for. */
            var undecided = !many && selects()[0].value === '';

            addBtn.disabled = undecided || all.length >= ceiling;
            advice.hidden   = !undecided;

            if (advised) { advised.value = undecided ? '1' : '0'; }
        };

        addBtn.addEventListener('click', function () {
            var template = rows()[0].cloneNode(true);
            var select   = template.querySelector('[data-dest-select]');

            /* A fresh row starts empty and offers no "not sure" — see the rule
               above. Its placeholder says what to do instead. */
            select.value = '';
            select.options[0].textContent = 'Choose a destination';
            select.classList.remove('is-invalid');

            Array.prototype.forEach.call(select.options, function (option) {
                option.disabled = false;
            });

            list.appendChild(template);
            refresh();
            select.focus();
        });

        /* Delegated, because the rows these fire on do not exist yet when this
           runs. */
        list.addEventListener('click', function (event) {
            var remove = event.target.closest('[data-dest-remove]');

            if (!remove) { return; }

            var row = remove.closest('[data-dest-row]');

            if (row && rows().length > 1) {
                row.remove();
                conflict.hidden = true;
                refresh();
            }
        });

        list.addEventListener('change', function (event) {
            var select = event.target.closest('[data-dest-select]');

            if (!select) { return; }

            /* Going back to "I am not sure yet" while other destinations are
               listed is the one contradiction the form has to refuse. Refused
               rather than resolved by clearing their list: the visitor spent
               taps building it, and a form that silently deletes work to
               satisfy a rule teaches people not to trust it. */
            if (select.value === '' && rows().length > 1) {
                select.value = select.dataset.was || '';
                conflict.hidden = false;
                refresh();
                return;
            }

            conflict.hidden = true;
            select.dataset.was = select.value;
            refresh();
        });

        selects().forEach(function (select) { select.dataset.was = select.value; });
        refresh();
    }

    /* Group size stepper. Clamped to the same bounds the server enforces, so
       the two never disagree about what is valid. */
    var size = document.getElementById('party_size');

    document.querySelectorAll('.tgr-stepper button').forEach(function (button) {
        button.addEventListener('click', function () {
            /* Empty means nobody has said yet. Pressing + then lands on 1
               rather than 2, and pressing - leaves it empty rather than
               inventing a party of one. */
            var step = parseInt(button.dataset.step, 10);
            var now  = parseInt(size.value, 10);

            if (isNaN(now)) {
                if (step > 0) { size.value = 1; }
                return;
            }

            size.value = Math.max(1, Math.min(200, now + step));
        });
    });

    /* Characters left, but only once they are close to the limit — a counter
       sitting at "0 of 600" from the start reads as a demand for 600. */
    var notes = document.getElementById('notes');
    var count = document.getElementById('notesCount');

    if (notes && count) {
        notes.addEventListener('input', function () {
            var left = 600 - notes.value.length;
            count.textContent = left <= 120 ? left + ' characters left' : '';
        });
    }

    /* ---- The result banner goes when it stops being true ----------------
       "Visitor name is required" sitting on screen while somebody types their
       name is a message arguing with the form below it. So it clears on the
       first thing they do to the form, and there is a close button for anyone
       who would rather dismiss it themselves.

       Not a timer. A failure that vanished on a count of five would take the
       reason with it, and the visitor would be left looking at a form that had
       refused them for no stated reason. */
    var banner = document.querySelector('[data-result]');

    if (banner) {
        var dismiss = function () {
            if (!banner) { return; }

            banner.classList.add('is-going');

            /* Removed after the fade rather than hidden, so it cannot be
               tabbed into while invisible. */
            window.setTimeout(function () {
                if (banner && banner.parentNode) { banner.parentNode.removeChild(banner); }
                banner = null;
            }, 260);
        };

        var closer = banner.querySelector('[data-result-close]');
        if (closer) { closer.addEventListener('click', dismiss); }

        /* A SUCCESS GOES ON ITS OWN. A REFUSAL WAITS.
         *
         * "Request sent" has nothing for the visitor to do, so five seconds is
         * plenty. "Visitor name is required" has to survive until they have
         * read it — and it already clears the moment they start typing. */
        if (banner.hasAttribute('data-result-fades')) {
            var timer = window.setTimeout(dismiss, 5000);

            banner.addEventListener('mouseenter', function () { window.clearTimeout(timer); });
            banner.addEventListener('mouseleave', function () { timer = window.setTimeout(dismiss, 2500); });
        }

        var form = document.querySelector('.tgr-card form') || document.querySelector('form');

        if (form) {
            ['input', 'change'].forEach(function (event) {
                form.addEventListener(event, dismiss, { once: true });
            });
        }
    }
})();
</script>

<!-- =========================================================================
     THE TOURISM ASSISTANT
     Every public page carries it. A visitor reading an advisory, planning a
     route or filling in a guide request has the same questions as one on the
     home page, and should not have to go back to ask them.
     ====================================================================== -->
<?php require __DIR__ . '/app/views/partials/chat-widget.php'; ?>

</body>
</html>
