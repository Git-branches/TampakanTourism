<?php
declare(strict_types=1);

/**
 * TourSync — the manager proposing a change to their destination.    Feature 6
 *
 * What this replaces is a phone call to the Municipal Tourism Office, or a trip
 * into town, every time an entrance fee changes or a comfort room goes out of
 * service. The public page then stayed wrong for as long as that took.
 *
 * The manager fills in the page as they believe it should read; the system
 * works out what actually changed and sends only that to the office. Nobody has
 * to describe a change in prose, and the officer reviewing it sees the old and
 * new text side by side rather than a paragraph explaining them.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Csrf;
use App\Core\ManagerAuth;
use App\Core\Session;
use App\Repositories\ChangeRequestRepository as Changes;
use App\Repositories\DestinationRepository;
use App\Repositories\NotificationRepository as Notifications;

ManagerAuth::require();

$destinationId = (int) ManagerAuth::destinationId();
$managerId     = (int) ManagerAuth::id();
$destination   = DestinationRepository::find($destinationId);

if ($destination === null) {
    Session::flash('danger', 'Your destination could not be loaded. Please contact the Tourism Office.');
    redirect(base_url('/manager/index.php'));
}

$errors = [];

if (is_post()) {
    Csrf::verify();

    $action = (string) ($_POST['action'] ?? 'propose');

    if ($action === 'withdraw') {
        /* Scoped to this manager inside the repository — an id in a form is
           not proof of who raised it. */
        $ok = Changes::withdraw((int) ($_POST['request_id'] ?? 0), $managerId);

        Session::flash(
            $ok ? 'success' : 'warning',
            $ok ? 'Request withdrawn.' : 'That request could no longer be withdrawn — the Office may have decided on it.'
        );
        redirect(base_url('/manager/update-info.php'));
    }

    $reason = trim((string) ($_POST['reason'] ?? ''));

    /* Only what actually differs from the live record. A manager who opens the
       page, corrects one line and submits should not file a proposal listing
       eleven unchanged fields for the officer to read through. */
    $changes = Changes::diff($_POST, $destination);

    /* ONE PENDING REQUEST PER FIELD, ENFORCED HERE AND NOT ONLY HOPED FOR.
     *
     * Nothing stopped a manager filing a second request over the top of a
     * waiting one — three landed during testing, each proposing a different
     * entrance fee, and the officer would have had to work out which was meant.
     * Whichever they approved, the others stayed pending and contradicted the
     * published page.
     *
     * Blocked on the FIELD, not the whole request: correcting the hours while
     * the fee is still under review is a reasonable thing to do, and refusing
     * it would push the manager back to the phone call this feature replaces. */
    $alreadyWaiting = [];

    foreach (Changes::all(['destination_id' => $destinationId, 'status' => 'pending'], 30) as $open) {
        foreach (array_keys($open['changes']) as $field) {
            if (isset($changes[$field])) {
                $alreadyWaiting[$field] = Changes::FIELDS[$field]['label'] ?? $field;
            }
        }
    }

    if ($changes === []) {
        $errors['form'] = 'Nothing on this form is different from what is published. Change something first.';
    } elseif ($alreadyWaiting !== []) {
        $errors['form'] = 'You already have a request waiting for '
            . implode(', ', $alreadyWaiting)
            . '. Withdraw it above before sending another, or the Office is given two answers for the same field.';
    } elseif ($reason === '') {
        $errors['reason'] = 'Please say why. The Office is being asked to change a public page and this is what they read first.';
    } else {
        $id = Changes::create($destinationId, $managerId, $changes, $reason);

        ActivityLog::record(
            'destination.change_requested',
            'destination',
            $destinationId,
            count($changes) . ' field(s) proposed for ' . $destination['name']
        );

        /* While this sits undecided the public page shows details a manager has
           said are wrong, so it is worth the officer's attention rather than
           only a number beside a menu. */
        Notifications::record(
            'change_request',
            'Change requested for ' . $destination['name'],
            [
                'body'        => count($changes) . ' field(s) — ' . mb_substr($reason, 0, 160),
                'link'        => base_url('/admin/change-requests/index.php#req' . $id),
                'entity_type' => 'change_request',
                'entity_id'   => $id,
            ]
        );

        Session::flash(
            'success',
            'Sent to the Municipal Tourism Office. ' . count($changes) . ' change(s) are waiting for their review.'
        );
        redirect(base_url('/manager/update-info.php'));
    }
}

$mine = Changes::all(['destination_id' => $destinationId], 30);

/* Waiting proposals are shown above the form because they answer the question a
   manager arrives with — "did my last one go through?" — and because a second
   proposal for the same field, filed because the first looked lost, is a
   contradiction the office then has to resolve. */
$pending = array_values(array_filter($mine, static fn(array $r): bool => $r['status'] === 'pending'));

/* EVERY REQUEST THAT HAS AN ANSWER, IN ONE PLACE.
 *
 * There were briefly two lists of these: one added at the top of the page and
 * the original "Earlier requests" below the form, filtered differently and
 * saying the same thing twice. One list, above the form, because "what happened
 * to the one I sent" is the question a manager arrives with. */
$decided = array_slice(
    array_values(array_filter($mine, static fn (array $r): bool => $r['status'] !== 'pending')),
    0,
    6
);

/* The newest decline, if the newest answer WAS a decline. That is the only case
   where the page has something to ask of the manager rather than to tell them,
   so it drives the "Review & Resubmit" prompt. */
$lastDeclined = ($decided !== [] && $decided[0]['status'] === 'rejected') ? $decided[0] : null;

$pageTitle    = 'Update my destination';
$pageIcon     = 'fa-pen-to-square';
$pageSubtitle = (string) $destination['name'];

require __DIR__ . '/_partials/head.php';
?>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i> <?= e($errors['form']) ?></div>
<?php endif; ?>

<?php if ($pending !== []): ?>
    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-solid fa-hourglass-half"></i> Waiting for the Office</h2>
        </header>
        <div class="panel__body">
            <?php foreach ($pending as $p): ?>
                <div class="border rounded p-3 mb-2">
                    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                        <div>
                            <strong><?= n(count($p['changes'])) ?> change(s)</strong>
                            <span class="text-muted small">
                                &middot; sent <?= e(format_date((string) $p['created_at'], 'M j, Y \a\t g:i A')) ?>
                            </span>
                            <p class="mb-1 text-muted small">
                                <?= e(implode(', ', array_map(
                                    static fn(string $f): string => Changes::FIELDS[$f]['label'] ?? $f,
                                    array_keys($p['changes'])
                                ))) ?>
                            </p>
                            <?php if ($p['reason']): ?>
                                <p class="mb-0 small">&ldquo;<?= e((string) $p['reason']) ?>&rdquo;</p>
                            <?php endif; ?>
                        </div>
                        <form method="post" data-confirm="Withdraw this request?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="withdraw">
                            <input type="hidden" name="request_id" value="<?= (int) $p['id'] ?>">
                            <button class="btn btn-sm btn-outline-secondary">Withdraw</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($decided !== []): ?>
    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-solid fa-clipboard-check"></i> What the Office decided</h2>
        </header>

        <div class="panel__body">
            <?php foreach ($decided as $d): ?>
                <?php $yes = $d['status'] === 'approved'; ?>
                <div class="border rounded p-3 mb-2">
                    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                        <div>
                            <span class="pill pill--<?= $yes ? 'ok' : 'flag' ?>">
                                <?= $yes ? 'Approved' : 'Declined' ?>
                            </span>
                            <strong class="ms-1"><?= n(count($d['changes'])) ?> change(s)</strong>
                            <span class="text-muted small">
                                &middot; sent <?= e(format_date((string) $d['created_at'], 'M j')) ?>
                                <?php if ($d['reviewed_at']): ?>
                                    &middot; answered <?= e(format_date((string) $d['reviewed_at'], 'M j, Y \a\t g:i A')) ?>
                                <?php endif; ?>
                            </span>

                            <p class="mb-1 text-muted small">
                                <?= e(implode(', ', array_map(
                                    static fn (string $f): string => Changes::FIELDS[$f]['label'] ?? $f,
                                    array_keys($d['changes'])
                                ))) ?>
                            </p>

                            <?php /* The office's own words. On a decline this is the
                                     only thing that says what to do differently, so
                                     its absence is said out loud rather than left as
                                     a blank space. */ ?>
                            <?php if ($d['review_note']): ?>
                                <p class="mb-0 small">
                                    <strong>The Office wrote:</strong>
                                    &ldquo;<?= e((string) $d['review_note']) ?>&rdquo;
                                </p>
                            <?php elseif (!$yes): ?>
                                <p class="mb-0 small text-muted">
                                    No reason was given. Ask the Office before sending it again.
                                </p>
                            <?php endif; ?>
                        </div>

                        <?php /* Only on the newest, and only when it was declined:
                                 that is the one case where this list is asking
                                 something of the manager rather than telling
                                 them. The form below already holds the published
                                 values, so "review and resubmit" is a scroll and
                                 an edit — no second draft to keep in step. */ ?>
                        <?php if ($lastDeclined !== null && (int) $d['id'] === (int) $lastDeclined['id']): ?>
                            <a href="#proposeForm" class="btn btn-sm btn-brand">
                                <i class="fa-solid fa-pen" aria-hidden="true"></i> Review &amp; Resubmit
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-pen-to-square"></i> Propose changes</h2>
        <a href="<?= e(base_url('/destination.php?slug=' . urlencode((string) $destination['slug']))) ?>"
           target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> See the public page
        </a>
    </header>

    <div class="panel__body">
        <?php /* SAID AS A NOTICE, NOT AS A SENTENCE IN A PARAGRAPH.
                 The one thing a manager must not misread is that filling this in
                 publishes something. It did say so, in the third line of a grey
                 paragraph, which is where a sentence goes to be skipped. */ ?>
        <div class="rf-alert alert alert-warning">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            <strong>Changes require review.</strong>
            Your updates will be reviewed by the Municipal Tourism Office before appearing on the
            public website and on your QR page.
        </div>

        <p class="text-muted">
            Edit anything below to how it should read, then press <strong>Review Changes</strong> to see
            exactly what you are asking the Office to change.
        </p>

        <?php /* What is NOT on this form, and why: status, address, coordinates,
                 the QR token, the destination's name — and the PHOTOGRAPHS.
                 Archiving a site, moving its map pin or rotating the token
                 behind a printed sign are office decisions with effects outside
                 the destination. The gallery is the Municipal Tourism Staff's
                 alone; there is deliberately no upload, replace or remove here,
                 and the server never accepts one from this page. */ ?>
        <p class="text-muted small">
            To change the destination's <strong>name, location, photographs, or whether it is open to
            the public</strong>, contact the Office directly &mdash; those are not editable here.
        </p>

        <form method="post" id="proposeForm">
            <?= csrf_field() ?>

            <div class="row g-3">
                <?php foreach (Changes::FIELDS as $field => $rules):
                    $current = (string) ($destination[$field] ?? ''); ?>
                    <div class="col-<?= $rules['type'] === 'textarea' ? '12' : 'md-6' ?>">
                        <label class="form-label" for="<?= e($field) ?>"><?= e($rules['label']) ?></label>

                        <?php if ($rules['type'] === 'textarea'): ?>
                            <textarea class="form-control" id="<?= e($field) ?>" name="<?= e($field) ?>"
                                      rows="4" maxlength="<?= (int) $rules['max'] ?>"><?= e($current) ?></textarea>
                        <?php else: ?>
                            <input type="text" class="form-control" id="<?= e($field) ?>" name="<?= e($field) ?>"
                                   maxlength="<?= (int) $rules['max'] ?>" value="<?= e($current) ?>">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <div class="col-12">
                    <label class="form-label" for="reason">
                        Why are you changing this? <span class="text-danger">*</span>
                    </label>
                    <textarea class="form-control <?= isset($errors['reason']) ? 'is-invalid' : '' ?>"
                              id="reason" name="reason" rows="3" maxlength="600"
                              placeholder="The entrance fee went up in August, and the upper comfort room is closed for repairs until October."><?= e((string) ($_POST['reason'] ?? '')) ?></textarea>
                    <?php if (isset($errors['reason'])): ?>
                        <div class="field-error"><?= e($errors['reason']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php /* REVIEW BEFORE SEND.
                     The form used to post straight from here, so the first time
                     a manager saw what they had actually changed was on the
                     officer's screen. Review Changes builds that list in the
                     browser from the published values rendered below, and the
                     submit lives inside the dialog — you cannot send without
                     having been shown what you are sending.

                     THE SERVER STILL DECIDES WHAT CHANGED. Changes::diff() runs
                     again on the post; this list is what the manager is shown,
                     never what is stored. */ ?>
            <div class="rf-foot mt-3">
                <p class="rf-foot__count" id="proposeHint">
                    Nothing is sent until you review it.
                </p>

                <a href="index.php" class="btn btn-sm btn-outline-secondary">Cancel</a>

                <button type="button" class="btn btn-brand btn-sm" id="reviewChanges">
                    <i class="fa-solid fa-list-check" aria-hidden="true"></i> Review Changes
                </button>
            </div>

            <dialog class="sheet sheet--wide" id="reviewSheet" aria-labelledby="reviewSheetTitle">
                <header class="sheet__head">
                    <h2 id="reviewSheetTitle">
                        <i class="fa-solid fa-list-check" aria-hidden="true"></i> Review Changes
                    </h2>
                    <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="sheet__body">
                    <p class="text-muted small" id="reviewCount"></p>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 rf-diff">
                            <thead>
                                <tr>
                                    <th style="width:20%">Field</th>
                                    <th style="width:40%">Currently published</th>
                                    <th style="width:40%">Your proposed change</th>
                                </tr>
                            </thead>
                            <tbody id="reviewRows"></tbody>
                        </table>
                    </div>

                    <div class="rf-diff__why mt-3">
                        <strong>Your note to the Office</strong>
                        <p class="mb-0" id="reviewReason"></p>
                    </div>
                </div>

                <footer class="sheet__foot">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close>
                        Back to Edit
                    </button>
                    <?php /* The only submit on the page. */ ?>
                    <button type="submit" name="action" value="propose" class="btn btn-sm btn-brand">
                        <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Submit Update Request
                    </button>
                </footer>
            </dialog>
        </form>
    </div>
</section>

<?php /* The published values, for the browser to diff against. Same source the
         fields were drawn from, so the comparison cannot drift from the form. */ ?>
<?php
/* Keyed by field name — array_map over FIELDS and array_keys() would return a
   LIST, and the browser needs to look each value up by the input's name. */
$published = [];

foreach (Changes::FIELDS as $field => $rules) {
    $published[$field] = [
        'label' => $rules['label'],
        'value' => (string) ($destination[$field] ?? ''),
    ];
}
?>
<script type="application/json" id="publishedValues"><?= json_encode(
    $published,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
) ?></script>

<?php /* The second copy of the decided list used to sit here, below the form,
         under the heading "Earlier requests". It is above the form now. */ ?>

<script>
/* REVIEW BEFORE SEND — the browser half.
 *
 * Builds the same comparison the officer will see, from the published values
 * the server rendered above and whatever is in the fields right now. It decides
 * nothing: Changes::diff() runs again on the post and IT is what gets stored.
 * If this list and the server ever disagreed, the server would be right.
 */
(function () {
    'use strict';

    var form   = document.getElementById('proposeForm');
    var open   = document.getElementById('reviewChanges');
    var sheet  = document.getElementById('reviewSheet');
    var rows   = document.getElementById('reviewRows');
    var count  = document.getElementById('reviewCount');
    var why    = document.getElementById('reviewReason');
    var hint   = document.getElementById('proposeHint');
    var source = document.getElementById('publishedValues');

    if (!form || !open || !sheet || !source) { return; }

    var published = {};
    try { published = JSON.parse(source.textContent) || {}; } catch (e) { return; }

    /* The same normalising the server does before comparing, so a textarea's
       CRLF does not read as an edit here and then vanish there. */
    var tidy = function (s) { return String(s == null ? '' : s).replace(/\r\n/g, '\n').trim(); };

    var diff = function () {
        var out = [];

        Object.keys(published).forEach(function (field) {
            var el = form.elements[field];
            if (!el) { return; }

            var now = tidy(el.value);
            var was = tidy(published[field].value);

            if (now !== was) {
                out.push({ label: published[field].label, was: was, now: now });
            }
        });

        return out;
    };

    var cell = function (text, empty) {
        var td = document.createElement('td');

        if (text === '') {
            var i = document.createElement('span');
            i.className = 'text-muted fst-italic';
            i.textContent = empty;
            td.appendChild(i);
        } else {
            td.textContent = text;
        }

        return td;
    };

    open.addEventListener('click', function () {
        var reason = form.elements.reason;
        var changed = diff();

        /* Both refusals are the ones the server would give, said before the
           trip rather than after it. */
        if (changed.length === 0) {
            hint.textContent = 'Nothing on this form is different from what is published yet.';
            hint.classList.add('text-danger');
            return;
        }

        if (tidy(reason.value) === '') {
            hint.textContent = 'Please say why you are changing this — the Office reads it first.';
            hint.classList.add('text-danger');
            reason.focus();
            return;
        }

        hint.classList.remove('text-danger');
        hint.textContent = 'Nothing is sent until you review it.';

        rows.textContent = '';

        changed.forEach(function (c) {
            var tr = document.createElement('tr');
            var th = document.createElement('td');
            th.innerHTML = '<strong></strong>';
            th.firstChild.textContent = c.label;
            tr.appendChild(th);
            tr.appendChild(cell(c.was, 'empty'));

            var to = cell(c.now, 'cleared');
            to.className = 'rf-diff__new';
            tr.appendChild(to);

            rows.appendChild(tr);
        });

        count.textContent = changed.length === 1
            ? '1 field will change. Everything else stays as it is published.'
            : changed.length + ' fields will change. Everything else stays as it is published.';

        why.textContent = tidy(reason.value);

        if (typeof sheet.showModal === 'function') { sheet.showModal(); }
    });

    /* ONE SUBMISSION, NOT THREE.
     *
     * The submit lives in a dialog, and a dialog is easy to press twice — the
     * button does not move and nothing on screen changes while the post is in
     * flight. The server redirects after storing, so a refresh cannot repeat it;
     * this closes the other door. */
    form.addEventListener('submit', function (ev) {
        if (form.dataset.sent === 'yes') {
            ev.preventDefault();
            return;
        }

        form.dataset.sent = 'yes';

        var go = sheet.querySelector('button[type="submit"]');

        if (go) {
            go.disabled = true;
            go.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Sending&hellip;';
        }
    });
})();
</script>

<?php require __DIR__ . '/_partials/foot.php'; ?>
