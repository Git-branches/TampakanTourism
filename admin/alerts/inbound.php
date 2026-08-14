<?php
declare(strict_types=1);

/**
 * TourSync — every inbound text, accepted or not.                    Feature 3
 *
 * The page an officer opens when a manager says "I texted you". Most of what
 * arrives at the webhook is not an alert — provider retries, wrong numbers, and
 * attempts to post to the URL by someone who found it — and none of that
 * belongs in the alert inbox. It belongs here, where somebody can look.
 *
 * Officer-only: it shows the shared secret's status and the addresses that have
 * been probing the endpoint.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Session;
use App\Repositories\AlertRepository as Alerts;

Auth::require('officer');

if (is_post()) {
    Csrf::verify();

    if (($_POST['action'] ?? '') === 'rotate-secret') {
        /* 32 bytes of randomness, hex. Long enough that guessing is not a
           strategy, and printable so it can be pasted into a provider's
           dashboard without encoding surprises. */
        $secret = bin2hex(random_bytes(32));

        Database::run(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            ['sms_inbound_secret', $secret]
        );

        ActivityLog::record('alert.secret_rotated', 'settings', null,
            'Inbound SMS secret rotated — the provider must be updated');

        /* Shown once, on this response, and never flashed through the session —
           a secret does not belong in a session file on disk. */
        Session::flash('warning', 'New secret generated. Paste it into the SMS provider now — '
            . 'inbound texts will be refused until you do.');

        $justRotated = $secret;
    }

    if (($_POST['action'] ?? '') === 'disable') {
        Database::run(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, "")
             ON DUPLICATE KEY UPDATE setting_value = ""',
            ['sms_inbound_secret']
        );

        ActivityLog::record('alert.inbound_disabled', 'settings', null, 'Inbound SMS switched off');
        Session::flash('info', 'Inbound SMS is off. The endpoint now refuses everything.');
        redirect(base_url('/admin/alerts/inbound.php'));
    }
}

$secret    = (string) (Database::scalar('SELECT setting_value FROM settings WHERE setting_key = ?', ['sms_inbound_secret']) ?? '');
$log       = Alerts::inboundLog(80);
$webhook   = rtrim((string) (setting('public_url', '') ?: base_url('/')), '/') . '/api/alerts/inbound.php';

$pageTitle    = 'Inbound SMS';
$pageIcon     = 'fa-message';
$pageSubtitle = 'Every text the system received';

require __DIR__ . '/../_partials/head.php';
?>

<p class="mb-3">
    <a href="index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa-solid fa-arrow-left"></i> Back to alerts
    </a>
</p>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-plug"></i> Provider setup</h2>
        <span class="pill pill--<?= $secret === '' ? 'flag' : 'ok' ?>">
            <?= $secret === '' ? 'Switched off' : 'Active' ?>
        </span>
    </header>

    <div class="panel__body">
        <?php if (!empty($justRotated)): ?>
            <div class="alert alert-warning">
                <strong>Your new secret — copy it now.</strong>
                <div class="mt-2"><code style="word-break:break-all"><?= e($justRotated) ?></code></div>
                <div class="small mt-2">
                    This is the only time it is shown. It is stored as the value the endpoint compares
                    against, and there is no screen that will print it again.
                </div>
            </div>
        <?php endif; ?>

        <dl class="detail-grid">
            <div>
                <dt>Webhook URL</dt>
                <dd><code style="word-break:break-all"><?= e($webhook) ?></code></dd>
            </div>
            <div>
                <dt>Secret</dt>
                <dd><?= $secret === '' ? 'not set' : 'set (' . strlen($secret) . ' characters)' ?></dd>
            </div>
        </dl>

        <p class="text-muted small">
            Give the URL to the SMS provider and have them send the secret either as the header
            <code>X-TourSync-Secret</code> or as a form field named <code>secret</code>. The endpoint
            accepts the usual field names for the sender and the body, so most providers work without
            any per-vendor code.
        </p>

        <?php if ($secret === ''): ?>
            <div class="alert alert-info">
                <i class="fa-solid fa-shield-halved"></i>
                Inbound SMS is off, and the endpoint refuses everything while it is.
                An inbound webhook with no secret is an open write endpoint on the internet.
            </div>
        <?php endif; ?>

        <div class="d-flex gap-2 flex-wrap">
            <form method="post">
                <?= csrf_field() ?>
                <button type="submit" name="action" value="rotate-secret" class="btn btn-brand btn-sm"
                        onclick="return confirm('Generate a new secret?\n\nInbound texts will be refused until the provider is updated with it.');">
                    <i class="fa-solid fa-key"></i> <?= $secret === '' ? 'Switch on and generate a secret' : 'Rotate the secret' ?>
                </button>
            </form>

            <?php if ($secret !== ''): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <button type="submit" name="action" value="disable" class="btn btn-sm btn-outline-secondary"
                            onclick="return confirm('Switch inbound SMS off?');">
                        Switch off
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-list"></i> Received</h2>
        <span class="text-muted small"><?= n(count($log)) ?> most recent</span>
    </header>

    <div class="panel__body">
        <?php if ($log === []): ?>
            <div class="empty-public">
                <i class="fa-regular fa-message"></i>
                <h3>Nothing received</h3>
                <p>Texts sent to the office number appear here, including any that were refused.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>From</th>
                            <th>Message</th>
                            <th>Outcome</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($log as $row): ?>
                            <?php
                            $tone = match ($row['outcome']) {
                                'alert_created'  => 'ok',
                                'unknown_sender' => 'qr',
                                'rejected'       => 'flag',
                                default          => 'void',
                            };
                            ?>
                            <tr>
                                <td>
                                    <span class="cell-strong"><?= e(format_date((string) $row['created_at'], 'M j')) ?></span>
                                    <span class="cell-sub"><?= e(format_date((string) $row['created_at'], 'g:i A')) ?></span>
                                </td>
                                <td class="small"><?= e((string) ($row['from_number'] ?: '—')) ?></td>
                                <td class="small"><?= e(mb_substr((string) ($row['body'] ?? ''), 0, 70)) ?></td>
                                <td><span class="pill pill--<?= $tone ?>"><?= e(str_replace('_', ' ', (string) $row['outcome'])) ?></span></td>
                                <td class="small text-muted">
                                    <?= e(mb_substr((string) ($row['note'] ?? ''), 0, 60)) ?>
                                    <?php if ($row['ip_address']): ?>
                                        <span class="cell-sub"><?= e(\App\Core\ActivityLog::readableIp((string) $row['ip_address'])) ?></span>
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

<?php require __DIR__ . '/../_partials/foot.php'; ?>
