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

        <form method="post">
            <?= csrf_field() ?>

            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label for="category" class="form-label">What kind of thing is it?</label>
                    <select id="category" name="category" class="form-select">
                        <?php foreach (Alerts::CATEGORIES as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= ($_POST['category'] ?? '') === $key ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-6">
                    <label for="severity" class="form-label">How urgent?</label>
                    <select id="severity" name="severity" class="form-select">
                        <?php foreach (Alerts::SEVERITIES as $key => $label): ?>
                            <option value="<?= e($key) ?>"
                                <?= ($_POST['severity'] ?? 'warning') === $key ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <label for="message" class="form-label">What has happened?</label>
                    <textarea id="message" name="message" class="form-control" rows="4" maxlength="1000"
                              required placeholder="e.g. Landslide across the trail about 200m from the entrance. No one hurt. Trail closed until it is cleared."><?= e((string) ($_POST['message'] ?? '')) ?></textarea>
                    <p class="text-muted small mt-1 mb-0">
                        Say where, and whether anyone is hurt. The Office cannot see what you are looking at.
                    </p>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-brand btn-sm">
                        <i class="fa-solid fa-paper-plane"></i> Send Alert
                    </button>
                </div>
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
                                    <?= $a['resolution_note']
                                        ? e(mb_substr((string) $a['resolution_note'], 0, 80))
                                        : '<span class="text-muted">&mdash;</span>' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/_partials/foot.php'; ?>
