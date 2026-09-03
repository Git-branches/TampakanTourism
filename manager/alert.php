<?php
declare(strict_types=1);

/**
 * TourSync — the manager raising an alert.                           Feature 3
 *
 * The portal half of a two-channel report. A manager with a data signal uses
 * this; a manager with only GSM texts the office number and it lands in the
 * same inbox. Neither is a fallback for the other — at a waterfall, the text is
 * usually the one that works.
 *
 * Kept to four fields and one button. Someone filling this in is standing next
 * to whatever went wrong, and a form that asks for a reference number and a
 * category taxonomy is a form they abandon and phone instead.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Csrf;
use App\Core\ManagerAuth;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Core\SmsGateway;
use App\Repositories\AlertRepository as Alerts;
use App\Repositories\NotificationRepository as Notifications;

ManagerAuth::require();

$destinationId = (int) ManagerAuth::destinationId();
$errors        = [];

if (is_post()) {
    Csrf::verify();

    $message  = trim((string) ($_POST['message'] ?? ''));
    $category = (string) ($_POST['category'] ?? 'other');
    $severity = (string) ($_POST['severity'] ?? 'warning');

    if (mb_strlen($message) < 10) {
        $errors['message'] = 'Please describe what has happened — at least a sentence, so the Office knows what they are responding to.';
    }

    if (!isset(Alerts::CATEGORIES[$category])) {
        $category = 'other';
    }

    if (!isset(Alerts::SEVERITIES[$severity])) {
        $severity = 'warning';
    }

    /* A limit, but a generous one. Somebody in the middle of an incident may
       legitimately send three messages in five minutes as it develops; the
       limit is here to stop a stuck form posting in a loop, not to ration a
       manager reporting an emergency. */
    if ($errors === [] && !RateLimiter::allow('alert:' . ManagerAuth::id(), 10, 600)) {
        $errors['message'] = 'That is several alerts in a short time. If this is an emergency, call the Office directly.';
    }

    if ($errors === []) {
        $id = Alerts::create([
            'destination_id' => $destinationId,
            'raised_by'      => (int) ManagerAuth::id(),
            'channel'        => 'portal',
            'category'       => $category,
            'severity'       => $severity,
            'message'        => $message,
        ]);

        ActivityLog::record(
            'alert.raised', 'destination_alert', $id,
            strtoupper($severity) . ' ' . $category . ' at ' . ManagerAuth::destinationName()
            . ': ' . mb_substr($message, 0, 100)
        );

        /* Urgent alerts text the officer. Without this the alert is a row in a
           table waiting for somebody to open a page. */
        $texted = Alerts::notifyOffice($id);

        /* And on the bell, urgent or not. A "crowding" alert does not warrant a
           text at eleven at night; it does warrant being on the screen when
           somebody next signs in. */
        Notifications::record(
            'alert',
            strtoupper($severity) . ' — ' . $category . ' at ' . ManagerAuth::destinationName(),
            [
                'body'        => mb_substr($message, 0, 200),
                'link'        => base_url('/admin/alerts/index.php'),
                'entity_type' => 'destination_alert',
                'entity_id'   => $id,
            ]
        );

        if ($severity === 'urgent') {
            Session::flash('success', $texted > 0
                ? 'Urgent alert sent, and the Tourism Office has been texted. If anyone is in danger, call the emergency hotline as well — this does not replace that call.'
                : 'Urgent alert sent. It is at the top of the Office\'s inbox. If anyone is in danger, call the emergency hotline as well — this does not replace that call.');
        } else {
            Session::flash('success', 'Alert sent to the Municipal Tourism Office.');
        }

        redirect(base_url('/manager/alert.php'));
    }
}

$alerts = Alerts::forDestination($destinationId);

/* The number a text should go to, and whether texting is live at all. Shown so
   a manager knows the fallback exists BEFORE the day they need it. */
$officeNumber = (string) (setting('office_phone', '') ?? '');
$smsLive      = SmsGateway::isLive();

$pageTitle    = 'Report an Alert';
$pageIcon     = 'fa-triangle-exclamation';
$pageSubtitle = ManagerAuth::destinationName();

require __DIR__ . '/_partials/head.php';
?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <i class="fa-solid fa-circle-exclamation"></i>
        <?php foreach ($errors as $message): ?><div><?= e($message) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-tower-broadcast"></i> Tell the Office what has happened</h2>
    </header>

    <div class="panel__body">
        <p class="text-muted small">
            This reaches the Municipal Tourism Office immediately. Use it for anything that changes
            whether visitors should come today &mdash; a closure, a hazard, an injury, flooding, a
            facility that has failed.
        </p>

        <?php
        /* CHIPS, NOT DROPDOWNS.
         *
         * Seven categories and three urgencies were two <select> elements, which
         * on a phone means two full-screen native pickers and four taps to
         * choose two things. As chips they are all visible at once and cost one
         * tap each — and the person using this is standing in front of the
         * landslide, not sitting down.
         *
         * They are radio inputs underneath. Same names, same values, same POST:
         * the handler above did not change and neither did the validation.
         */
        $alertIcons = [
            'closure'  => 'fa-ban',
            'hazard'   => 'fa-triangle-exclamation',
            'accident' => 'fa-kit-medical',
            'weather'  => 'fa-cloud-showers-heavy',
            'utility'  => 'fa-plug-circle-exclamation',
            'crowding' => 'fa-people-group',
            'other'    => 'fa-circle-question',
        ];
        $chosenCat = (string) ($_POST['category'] ?? 'closure');
        $chosenSev = (string) ($_POST['severity'] ?? 'warning');
        ?>

        <form method="post" id="alertForm">
            <?= csrf_field() ?>

            <div class="mgr-steps-row">
            <div class="mgr-step">
                <p class="mgr-step__label"><span>1</span> What kind of thing is it?</p>
                <div class="mgr-chips" role="radiogroup" aria-label="Alert type">
                    <?php foreach (Alerts::CATEGORIES as $key => $label): ?>
                        <label class="mgr-chip">
                            <input type="radio" name="category" value="<?= e($key) ?>"
                                   <?= $chosenCat === $key ? 'checked' : '' ?>>
                            <span>
                                <i class="fa-solid <?= e($alertIcons[$key] ?? 'fa-circle-question') ?>"
                                   aria-hidden="true"></i>
                                <?= e($label) ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="mgr-step">
                <p class="mgr-step__label"><span>2</span> How urgent?</p>
                <div class="mgr-chips mgr-chips--sev" role="radiogroup" aria-label="Urgency">
                    <?php foreach (Alerts::SEVERITIES as $key => $label): ?>
                        <label class="mgr-chip mgr-chip--<?= e($key) ?>">
                            <input type="radio" name="severity" value="<?= e($key) ?>"
                                   <?= $chosenSev === $key ? 'checked' : '' ?>>
                            <span><?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            </div><!-- /.mgr-steps-row -->

            <div class="mgr-step">
                <label class="mgr-step__label" for="message"><span>3</span> What has happened?</label>
                <textarea id="message" name="message" class="form-control" rows="4" maxlength="1000"
                          required placeholder="e.g. Landslide across the trail about 200m from the entrance. No one hurt. Trail closed until it is cleared."><?= e((string) ($_POST['message'] ?? '')) ?></textarea>
                <p class="text-muted small mt-1 mb-0">
                    Say where, and whether anyone is hurt. The Office cannot see what you are looking at.
                </p>
            </div>

            <?php /* Step 4 is not a field. The alert can only ever be about this
                     manager's own destination — the server takes it from the
                     session and never from the form — so it is shown as a fact
                     rather than asked as a question. */ ?>
            <div class="mgr-send">
                <div class="mgr-step">
                    <p class="mgr-step__label"><span>4</span> Where</p>
                    <p class="mgr-step__fixed" id="alertPlace">
                        <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                        <?= e(ManagerAuth::destinationName()) ?>
                    </p>
                </div>

                <button type="submit" class="btn btn-brand btn-sm">
                    <i class="fa-solid fa-paper-plane"></i> Send Alert
                </button>
            </div>
        </form>

        <!-- Said here rather than in a manual nobody reads. The day the data
             signal is gone is the day this matters, and that is not the day to
             be looking for the number. -->
        <hr class="my-4">

        <h3 class="h6"><i class="fa-solid fa-mobile-screen"></i> No internet at the site?</h3>
        <p class="text-muted small mb-0">
            <?php if ($officeNumber !== ''): ?>
                Text the Office at <strong><?= e($officeNumber) ?></strong>. Write it in plain words &mdash;
                English or Tagalog, whichever is faster. It arrives in the same inbox as this form, and the
                Office can text you back.
            <?php else: ?>
                Texting the Office is available once they publish their number in Settings. Ask the
                Municipal Tourism Office for the alert number and keep it in your phone.
            <?php endif; ?>
            <?php if (!$smsLive): ?>
                <br><em>SMS is in test mode on this system &mdash; messages are written to a log rather than sent.</em>
            <?php endif; ?>
        </p>
    </div>
</section>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-clock-rotate-left"></i> Alerts you have sent</h2>
        <span class="text-muted small"><?= n(count($alerts)) ?></span>
    </header>

    <div class="panel__body">
        <?php if ($alerts === []): ?>

            <div class="empty-public">
                <i class="fa-regular fa-bell"></i>
                <h3>Nothing reported yet</h3>
                <p>Alerts you send appear here with the Office's response.</p>
            </div>

        <?php else: ?>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>What</th>
                            <th>Urgency</th>
                            <th>Status</th>
                            <th>Office response</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($alerts as $a): ?>
                            <?php
                            $sevTone = match ($a['severity']) {
                                'urgent'  => 'flag',
                                'warning' => 'qr',
                                default   => 'void',
                            };
                            $statTone = match ($a['status']) {
                                'resolved'     => 'ok',
                                'acknowledged' => 'qr',
                                'dismissed'    => 'void',
                                default        => 'flag',
                            };
                            ?>
                            <tr>
                                <td>
                                    <span class="cell-strong"><?= e(format_date((string) $a['created_at'], 'M j')) ?></span>
                                    <span class="cell-sub"><?= e(format_date((string) $a['created_at'], 'g:i A')) ?>
                                        <?= $a['channel'] === 'sms' ? '· by text' : '' ?></span>
                                </td>
                                <td>
                                    <span class="cell-strong"><?= e(Alerts::CATEGORIES[$a['category']]) ?></span>
                                    <span class="cell-sub"><?= e(mb_substr((string) $a['message'], 0, 70)) ?></span>
                                </td>
                                <td><span class="pill pill--<?= $sevTone ?>"><?= e(Alerts::SEVERITIES[$a['severity']]) ?></span></td>
                                <td>
                                    <span class="pill pill--<?= $statTone ?>"><?= e(Alerts::STATUSES[$a['status']]) ?></span>
                                    <?php if ($a['acknowledged_by_name']): ?>
                                        <span class="cell-sub"><?= e((string) $a['acknowledged_by_name']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="small">
                                    <?php
                                    /* The reply comes first: it is the Office
                                       speaking to this manager. The resolution
                                       note is what was done, written for the
                                       record — useful, but second. */
                                    ?>
                                    <?php if ($a['office_reply']): ?>
                                        <span class="cell-strong"><?= e((string) $a['office_reply']) ?></span>
                                        <?php if ($a['replied_at']): ?>
                                            <span class="cell-sub">
                                                <?= e(format_date((string) $a['replied_at'], 'M j, g:i A')) ?>
                                                <?= $a['reply_sent_at'] ? '· texted' : '' ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if ($a['resolution_note']): ?>
                                        <span class="<?= $a['office_reply'] ? 'cell-sub' : '' ?>">
                                            <?= e(mb_substr((string) $a['resolution_note'], 0, 80)) ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if (!$a['office_reply'] && !$a['resolution_note']): ?>
                                        <span class="text-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </div>
</section>

<script>
/* CONFIRM BEFORE IT LEAVES.
 *
 * An alert texts the Office and cannot be unsent, so the manager sees exactly
 * what they are about to say first — type, urgency, place and their own words.
 *
 * It goes through TourSync.confirmAction, which is the confirmation this system
 * already uses everywhere else. A second dialog implementation would look
 * almost right and behave slightly differently, which is worse than either.
 *
 * The description is the manager's own free text, so it is escaped by putting
 * it through a text node rather than into a template string — the one place on
 * this page where markup and typing meet.
 */
(function () {
    var form = document.getElementById('alertForm');

    if (!form) { return; }

    function esc(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

    function chosen(name) {
        var picked = form.querySelector('input[name="' + name + '"]:checked');
        if (!picked) { return ''; }
        var text = picked.parentNode.textContent || '';
        return text.replace(/\s+/g, ' ').trim();
    }

    form.addEventListener('submit', function (event) {
        if (form.dataset.confirmed === 'yes') { return; }

        /* Let the browser's own required-field message win first — confirming
           an alert with no description then failing validation behind the
           dialog would be a confusing pair of steps. */
        if (typeof form.reportValidity === 'function' && !form.reportValidity()) { return; }

        event.preventDefault();

        var place = document.getElementById('alertPlace');
        var rows  = [
            ['Alert type',  chosen('category')],
            ['Urgency',     chosen('severity')],
            ['Location',    (place ? place.textContent : '').replace(/\s+/g, ' ').trim()],
            ['Description', (form.querySelector('#message').value || '').trim()]
        ];

        var html = '<dl class="mgr-confirm">' + rows.map(function (r) {
            return '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>';
        }).join('') + '</dl>';

        var ask = window.TourSync && window.TourSync.confirmAction;

        if (typeof ask !== 'function') { return; }   /* no dialog: let it submit */

        ask({
            title: 'Submit this alert?',
            html: html,
            tone: 'normal',
            confirmText: 'Submit Alert',
            cancelText: 'Cancel',
            onConfirm: function () {
                form.dataset.confirmed = 'yes';
                /* Disabled after the decision, not before: a manager who
                   cancels must be able to change something and send it. */
                var go = form.querySelector('button[type="submit"]');
                if (go) { go.disabled = true; }
                form.submit();
            }
        });
    });
})();
</script>

<?php require __DIR__ . '/_partials/foot.php'; ?>
