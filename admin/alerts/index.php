<?php
declare(strict_types=1);

/**
 * TourSync — the Municipal Tourism Office's alert inbox.             Feature 3
 *
 * Everything the destinations have reported, urgent first, newest within that.
 * Portal and SMS land in the same list because an officer should not have to
 * check two places to learn the same fact.
 *
 * Acting on an alert happens here rather than on a separate page: an urgent
 * report is read and answered in the same breath, and a click to a detail
 * screen is a click somebody skips at the wrong moment.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Session;
use App\Core\SmsGateway;
use App\Repositories\AlertRepository as Alerts;

Auth::require();

if (is_post()) {
    Csrf::verify();

    $action  = (string) ($_POST['action'] ?? '');
    $id      = (int) ($_POST['id'] ?? 0);
    $adminId = (int) Auth::id();
    $alert   = $id > 0 ? Alerts::find($id) : null;

    if ($alert === null) {
        Session::flash('danger', 'That alert could not be found.');
        redirect(base_url('/admin/alerts/index.php'));
    }

    $where = (string) ($alert['destination_name'] ?: 'an unverified number');

    if ($action === 'acknowledge') {
        Alerts::acknowledge($id, $adminId);
        ActivityLog::record('alert.acknowledged', 'destination_alert', $id, 'Acknowledged: ' . $where);
        Session::flash('success', 'Acknowledged. The manager can see it has been picked up.');
    }

    if ($action === 'resolve' || $action === 'dismiss') {
        $note = trim((string) ($_POST['resolution_note'] ?? ''));

        /* Dismissing without a reason leaves the manager watching an alert go
           quiet with no idea whether anyone read it. */
        if ($action === 'dismiss' && $note === '') {
            Session::flash('danger', 'Please say why this is being dismissed — the manager sees this.');
            redirect(base_url('/admin/alerts/index.php#alert' . $id));
        }

        if ($action === 'resolve') {
            Alerts::resolve($id, $adminId, $note);
            ActivityLog::record('alert.resolved', 'destination_alert', $id, 'Resolved: ' . $where);
            Session::flash('success', 'Marked resolved.');
        } else {
            Alerts::dismiss($id, $adminId, $note);
            ActivityLog::record('alert.dismissed', 'destination_alert', $id, 'Dismissed: ' . $where);
            Session::flash('success', 'Dismissed with your note.');
        }
    }

    if ($action === 'reclassify') {
        Alerts::reclassify($id, (string) ($_POST['category'] ?? ''), (string) ($_POST['severity'] ?? ''));
        Session::flash('success', 'Reclassified.');
    }

    /* The half that makes this two-way. A manager who reports a landslide and
       hears nothing will drive to town to find out whether it arrived. */
    if ($action === 'reply') {
        $body = trim((string) ($_POST['reply'] ?? ''));

        if ($body === '') {
            Session::flash('danger', 'Write the message first.');
            redirect(base_url('/admin/alerts/index.php#alert' . $id));
        }

        $result = Alerts::replyBySms($id, $body);

        if ($result['ok']) {
            ActivityLog::record('alert.replied', 'destination_alert', $id, 'Texted back: ' . mb_substr($body, 0, 100));
            Session::flash('success', SmsGateway::isLive()
                ? 'Reply sent by SMS.'
                : 'Reply written to the SMS log — this system is in test mode, so nothing was actually sent.');
        } else {
            Session::flash('danger', $result['error']);
        }
    }

    redirect(base_url('/admin/alerts/index.php#alert' . $id));
}

$status   = (string) ($_GET['status'] ?? '');
$severity = (string) ($_GET['severity'] ?? '');

if ($status !== '' && !isset(Alerts::STATUSES[$status]))     { $status = ''; }
if ($severity !== '' && !isset(Alerts::SEVERITIES[$severity])) { $severity = ''; }

$alerts = Alerts::inbox(['status' => $status, 'severity' => $severity]);
$counts = Alerts::counts();

$pageTitle    = 'Destination Alerts';
$pageIcon     = 'fa-tower-broadcast';
$pageSubtitle = 'What the destinations are reporting';

require __DIR__ . '/../_partials/head.php';
?>

<?php if ($counts['urgent_new'] > 0): ?>
    <div class="alert alert-danger">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <strong><?= n($counts['urgent_new']) ?> urgent alert(s) have not been picked up.</strong>
        Someone at a destination is waiting to hear that this was read.
    </div>
<?php endif; ?>

<div class="stat-grid">
    <?php
    $cards = [
        ['icon' => 'fa-bell',          'tone' => 'amber', 'value' => $counts['new'],          'label' => 'New',          'q' => 'status=new'],
        ['icon' => 'fa-triangle-exclamation', 'tone' => 'teal', 'value' => $counts['urgent_new'], 'label' => 'Urgent, unread', 'q' => 'status=new&severity=urgent'],
        ['icon' => 'fa-eye',           'tone' => 'blue',  'value' => $counts['acknowledged'], 'label' => 'Acknowledged', 'q' => 'status=acknowledged'],
        ['icon' => 'fa-circle-check',  'tone' => 'green', 'value' => $counts['resolved'],     'label' => 'Resolved',     'q' => 'status=resolved'],
    ];

    foreach ($cards as $card): ?>
        <a class="stat-card stat-card--<?= e($card['tone']) ?>" href="index.php?<?= e($card['q']) ?>">
            <div class="stat-card__icon"><i class="fa-solid <?= e($card['icon']) ?>"></i></div>
            <div class="stat-card__body">
                <p class="stat-card__value"><?= n((int) $card['value']) ?></p>
                <p class="stat-card__label"><?= e($card['label']) ?></p>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-inbox"></i> Alerts</h2>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <a href="inbound.php" class="btn btn-sm btn-outline-secondary">
                <i class="fa-solid fa-message"></i> SMS log
            </a>
            <?php if ($status !== '' || $severity !== ''): ?>
                <a href="index.php" class="btn btn-sm btn-outline-secondary">Clear filter</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="panel__body">
        <?php if ($alerts === []): ?>
            <div class="empty-public">
                <i class="fa-regular fa-bell"></i>
                <h3><?= $status !== '' || $severity !== '' ? 'Nothing matches that filter' : 'Nothing reported' ?></h3>
                <p>
                    When a destination manager reports a closure, a hazard or an injury &mdash; from the
                    portal or by text &mdash; it appears here.
                </p>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php foreach ($alerts as $a): ?>
    <?php
    $sevTone = match ($a['severity']) {
        'urgent'  => 'flag',
        'warning' => 'qr',
        default   => 'void',
    };
    $open       = in_array($a['status'], ['new', 'acknowledged'], true);
    $unverified = $a['destination_id'] === null;
    ?>
    <section class="panel" id="alert<?= (int) $a['id'] ?>">
        <header class="panel__head">
            <h2>
                <i class="fa-solid fa-<?= $a['channel'] === 'sms' ? 'message' : 'desktop' ?>"></i>
                <?= e((string) ($a['destination_name'] ?: 'Unverified sender')) ?>
                <span class="text-muted small">&middot; <?= e(Alerts::CATEGORIES[$a['category']]) ?></span>
            </h2>
            <span>
                <span class="pill pill--<?= $sevTone ?>"><?= e(Alerts::SEVERITIES[$a['severity']]) ?></span>
                <span class="pill pill--<?= $a['status'] === 'resolved' ? 'ok' : ($a['status'] === 'new' ? 'flag' : 'void') ?>">
                    <?= e(Alerts::STATUSES[$a['status']]) ?>
                </span>
            </span>
        </header>

        <div class="panel__body">
            <?php if ($unverified): ?>
                <!-- Kept rather than dropped: the one time it matters it may be
                     a bystander at an emergency. Described honestly. -->
                <div class="alert alert-warning py-2">
                    <i class="fa-solid fa-circle-question"></i>
                    <strong>This came from a number that does not match any active manager</strong>
                    (<?= e((string) ($a['from_number'] ?: 'no number')) ?>). Treat it as unverified &mdash;
                    it could be a visitor, or a manager texting from a different phone.
                </div>
            <?php endif; ?>

            <p class="mb-2"><?= nl2br(e((string) $a['message'])) ?></p>

            <p class="text-muted small">
                <?= e(format_date((string) $a['created_at'], 'F j, Y \a\t g:i A')) ?>
                <?php if ($a['raised_by_name']): ?>&middot; <?= e((string) $a['raised_by_name']) ?><?php endif; ?>
                <?php if ($a['channel'] === 'sms'): ?>
                    &middot; by text from <?= e((string) ($a['from_number'] ?: 'unknown')) ?>
                <?php endif; ?>
                <?php if ($a['reply_sent_at']): ?>
                    &middot; replied <?= e(format_date((string) $a['reply_sent_at'], 'M j, g:i A')) ?>
                <?php endif; ?>
            </p>

            <?php if ($a['resolution_note']): ?>
                <div class="alert alert-<?= $a['status'] === 'resolved' ? 'success' : 'info' ?> py-2">
                    <strong><?= $a['status'] === 'resolved' ? 'Resolved' : 'Note' ?>:</strong>
                    <?= e((string) $a['resolution_note']) ?>
                    <?php if ($a['acknowledged_by_name']): ?>
                        <span class="cell-sub">&mdash; <?= e((string) $a['acknowledged_by_name']) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($open): ?>
                <div class="d-flex gap-2 flex-wrap mb-3">
                    <?php if ($a['status'] === 'new'): ?>
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <button type="submit" name="action" value="acknowledge" class="btn btn-brand btn-sm">
                                <i class="fa-solid fa-eye"></i> Acknowledge
                            </button>
                        </form>
                    <?php endif; ?>

                    <!-- Reclassifying, because the parser guessed. It is a
                         suggestion and the officer has the whole message. -->
                    <form method="post" class="d-flex gap-2 flex-wrap align-items-center">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                        <select name="category" class="form-select form-select-sm" style="width:auto">
                            <?php foreach (Alerts::CATEGORIES as $k => $label): ?>
                                <option value="<?= e($k) ?>" <?= $a['category'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="severity" class="form-select form-select-sm" style="width:auto">
                            <?php foreach (Alerts::SEVERITIES as $k => $label): ?>
                                <option value="<?= e($k) ?>" <?= $a['severity'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="action" value="reclassify" class="btn btn-sm btn-outline-secondary">
                            Reclassify
                        </button>
                    </form>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-lg-6">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">

                            <label class="form-label" for="note<?= (int) $a['id'] ?>">What was done</label>
                            <input type="text" id="note<?= (int) $a['id'] ?>" name="resolution_note"
                                   class="form-control form-control-sm" maxlength="600"
                                   placeholder="e.g. Barangay cleared the trail; reopened 2pm">

                            <div class="mt-2 d-flex gap-2">
                                <button type="submit" name="action" value="resolve" class="btn btn-sm btn-outline-secondary">
                                    <i class="fa-solid fa-circle-check"></i> Resolve
                                </button>
                                <button type="submit" name="action" value="dismiss" class="btn btn-sm btn-outline-secondary">
                                    Dismiss
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="col-12 col-lg-6">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">

                            <label class="form-label" for="reply<?= (int) $a['id'] ?>">
                                Text them back
                                <?php if (!SmsGateway::isLive()): ?>
                                    <span class="text-muted small">(test mode — written to the log)</span>
                                <?php endif; ?>
                            </label>
                            <input type="text" id="reply<?= (int) $a['id'] ?>" name="reply"
                                   class="form-control form-control-sm" maxlength="300"
                                   placeholder="e.g. Received. Barangay rescue is on the way.">

                            <div class="mt-2">
                                <button type="submit" name="action" value="reply" class="btn btn-sm btn-outline-secondary">
                                    <i class="fa-solid fa-reply"></i> Send SMS reply
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php endforeach; ?>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
