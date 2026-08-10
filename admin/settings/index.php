<?php
declare(strict_types=1);

/**
 * TourSync — system settings.       Officer only.
 *
 * These are the values the Office should be able to change without a
 * developer: the letterhead on its reports, how long personal data is kept,
 * and the thresholds that decide when an arrival is flagged. Anything that
 * belongs to deployment — database credentials, file paths — stays in
 * config.php where a web form cannot reach it.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Session;
use App\Core\SmsGateway;
use App\Core\Validator;

Auth::require('officer');

$pageTitle    = 'Settings';
$pageIcon     = 'fa-gear';
$pageSubtitle = 'Office profile, data retention, and record thresholds';

/** The full set of editable settings, with the rules that guard each. */
$editable = [
    'office_name'         => ['label' => 'Office name',            'type' => 'text', 'max' => 120],
    'office_municipality' => ['label' => 'Municipality',           'type' => 'text', 'max' => 120],
    'office_province'     => ['label' => 'Province',               'type' => 'text', 'max' => 120],
    'office_address'      => ['label' => 'Office address',         'type' => 'text', 'max' => 255],
    'office_phone'        => ['label' => 'Telephone',              'type' => 'text', 'max' => 60],
    'office_email'        => ['label' => 'Email address',          'type' => 'email','max' => 160],
    'retention_months'    => ['label' => 'Retain personal data for (months)', 'type' => 'int', 'min' => 6,  'max' => 120],
    'dedupe_window_hours' => ['label' => 'Duplicate detection window (hours)','type' => 'int', 'min' => 1,  'max' => 72],
    'rate_limit_per_15m'  => ['label' => 'Logbook submissions allowed per 15 minutes', 'type' => 'int', 'min' => 3, 'max' => 100],
    'proximity_metres'    => ['label' => 'Flag arrivals beyond this distance (metres)','type' => 'int', 'min' => 100, 'max' => 5000],
];

if (is_post()) {
    Csrf::verify();

    $v = new Validator($_POST);
    $changes = [];

    foreach ($editable as $key => $rules) {
        $value = trim((string) ($_POST[$key] ?? ''));

        if ($rules['type'] === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $v->addError($key, 'Enter a valid email address.');
            continue;
        }

        if ($rules['type'] === 'int') {
            $n = filter_var($value, FILTER_VALIDATE_INT);
            if ($n === false || $n < $rules['min'] || $n > $rules['max']) {
                $v->addError($key, sprintf('Enter a whole number between %d and %d.', $rules['min'], $rules['max']));
                continue;
            }
            $value = (string) $n;
        }

        if ($rules['type'] === 'text' && mb_strlen($value) > $rules['max']) {
            $v->addError($key, 'That is longer than ' . $rules['max'] . ' characters.');
            continue;
        }

        if ((string) setting($key, '') !== $value) {
            $changes[$key] = $value;
        }
    }

    if ($v->fails()) {
        flash_back($v->errors(), $_POST, 'index.php');
    }

    foreach ($changes as $key => $value) {
        Database::run(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$key, $value]
        );
    }

    if ($changes !== []) {
        // The keys are logged, never the values — a setting could hold an
        // address or a phone number, and the audit log is read by more people
        // than the settings screen is.
        ActivityLog::record('settings.update', 'settings', null,
            'Updated: ' . implode(', ', array_keys($changes)));
        Session::flash('success', count($changes) . ' setting(s) saved.');
    } else {
        Session::flash('info', 'Nothing changed.');
    }

    redirect(base_url('/admin/settings/index.php'));
}

$dbVersion = Database::scalar('SELECT VERSION()');

require __DIR__ . '/../_partials/head.php';
?>

<div class="panel-row">
    <div>
        <form method="post" class="form-grid" novalidate>
            <?= csrf_field() ?>

            <section class="panel">
                <header class="panel__head"><h2><i class="fa-solid fa-building-columns"></i> Office Profile</h2></header>
                <div class="panel__body">
                    <p class="text-muted small mb-3">
                        Appears on report letterheads, the SMS signature, and the public footer.
                    </p>
                    <div class="row g-3">
                        <?php foreach (['office_name', 'office_municipality', 'office_province', 'office_address', 'office_phone', 'office_email'] as $key):
                            $rules = $editable[$key]; ?>
                            <div class="col-md-<?= $key === 'office_address' ? '12' : '6' ?>">
                                <label for="<?= e($key) ?>" class="form-label"><?= e($rules['label']) ?></label>
                                <input type="<?= $rules['type'] === 'email' ? 'email' : 'text' ?>"
                                       id="<?= e($key) ?>" name="<?= e($key) ?>" maxlength="<?= (int) $rules['max'] ?>"
                                       class="form-control <?= has_error($key) ? 'is-invalid' : '' ?>"
                                       value="<?= old($key, (string) setting($key, '')) ?>">
                                <?php if (has_error($key)): ?><div class="field-error"><?= e(error_for($key)) ?></div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="panel">
                <header class="panel__head"><h2><i class="fa-solid fa-shield-halved"></i> Record Integrity Thresholds</h2></header>
                <div class="panel__body">
                    <p class="text-muted small mb-3">
                        These decide when the logbook flags a submission for review. Loosening them
                        admits more records; tightening them risks rejecting genuine visitors.
                    </p>
                    <div class="row g-3">
                        <?php foreach (['dedupe_window_hours', 'rate_limit_per_15m', 'proximity_metres'] as $key):
                            $rules = $editable[$key]; ?>
                            <div class="col-md-4">
                                <label for="<?= e($key) ?>" class="form-label"><?= e($rules['label']) ?></label>
                                <input type="number" id="<?= e($key) ?>" name="<?= e($key) ?>"
                                       min="<?= (int) $rules['min'] ?>" max="<?= (int) $rules['max'] ?>"
                                       class="form-control <?= has_error($key) ? 'is-invalid' : '' ?>"
                                       value="<?= old($key, (string) setting($key, '')) ?>">
                                <?php if (has_error($key)): ?><div class="field-error"><?= e(error_for($key)) ?></div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="panel">
                <header class="panel__head"><h2><i class="fa-solid fa-user-shield"></i> Data Privacy</h2></header>
                <div class="panel__body">
                    <p class="text-muted small mb-3">
                        Under <strong>RA 10173</strong>, personal data should not be kept longer than the
                        purpose requires. The retention job clears names and contact details from older
                        records while leaving every count intact — the statistics survive, the personal
                        data does not.
                    </p>
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label for="retention_months" class="form-label"><?= e($editable['retention_months']['label']) ?></label>
                            <input type="number" id="retention_months" name="retention_months" min="6" max="120"
                                   class="form-control <?= has_error('retention_months') ? 'is-invalid' : '' ?>"
                                   value="<?= old('retention_months', (string) setting('retention_months', '36')) ?>">
                            <?php if (has_error('retention_months')): ?><div class="field-error"><?= e(error_for('retention_months')) ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-8">
                            <a href="retention.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fa-solid fa-eraser"></i> Review and run the retention job
                            </a>
                        </div>
                    </div>
                </div>
            </section>

            <div class="form-actions">
                <button type="submit" class="btn btn-brand"><i class="fa-solid fa-floppy-disk"></i> Save Settings</button>
            </div>
        </form>
    </div>

    <div class="panel-stack">
        <section class="panel">
            <header class="panel__head"><h2><i class="fa-solid fa-comment-sms"></i> SMS Gateway</h2></header>
            <div class="panel__body">
                <dl class="detail-grid detail-grid--single">
                    <div><dt>Driver</dt><dd><?= e(SmsGateway::driver()->name()) ?></dd></div>
                    <div><dt>Status</dt>
                        <dd><span class="pill pill--<?= SmsGateway::isLive() ? 'ok' : 'flag' ?>">
                            <?= SmsGateway::isLive() ? 'Live' : 'Test mode' ?></span></dd></div>
                </dl>
                <p class="text-muted small mb-0 mt-2"><?= e(SmsGateway::driver()->describe()) ?></p>
                <p class="report-note">
                    The SMS provider and API key live in <code>app/config/config.php</code>, not here.
                    A credential editable from a web form is a credential that can be changed by
                    anyone who reaches that form.
                </p>
            </div>
        </section>

        <section class="panel">
            <header class="panel__head"><h2><i class="fa-solid fa-server"></i> System</h2></header>
            <div class="panel__body">
                <dl class="detail-grid detail-grid--single">
                    <div><dt>PHP version</dt><dd><?= e(PHP_VERSION) ?></dd></div>
                    <div><dt>Database</dt><dd><?= e((string) $dbVersion) ?></dd></div>
                    <div><dt>Environment</dt><dd><?= e((string) config('env', 'production')) ?></dd></div>
                    <div><dt>Timezone</dt><dd><?= e(date_default_timezone_get()) ?></dd></div>
                    <div><dt>Base URL</dt><dd class="small mono"><?= e(base_url()) ?></dd></div>
                </dl>
            </div>
        </section>

        <section class="panel">
            <header class="panel__head"><h2><i class="fa-solid fa-users-gear"></i> Accounts</h2></header>
            <div class="panel__body">
                <p class="text-muted small">
                    There is no public registration. Accounts exist only because the installer or a
                    Tourism Officer created them.
                </p>
                <a href="accounts.php" class="btn btn-outline-secondary btn-sm w-100">
                    <i class="fa-solid fa-users"></i> Manage user accounts
                </a>
            </div>
        </section>
    </div>
</div>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
