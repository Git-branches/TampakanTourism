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

    /* Printed on the back of the tour guide ID. A page NAME rather than a URL:
       it is read off a laminated card by somebody who will then type it into
       the Facebook search box, and a full https:// address is longer, harder to
       read at 2 mm, and no easier to act on. */
    'office_facebook'     => ['label' => 'Facebook page',          'type' => 'text', 'max' => 160],

    /* Printed on the tour guide receipt so a visitor knows when they can turn
       up. It had no field on this page at all until now: the code asked for it,
       found nothing, and quietly said nothing. */
    'office_hours'        => ['label' => 'Opening hours',          'type' => 'text', 'max' => 120],

    /* The one setting that gets printed onto physical objects.
       Everything else here can be corrected with a page refresh; this ends up
       laminated on a post at a waterfall, so it is stated deliberately once
       rather than inferred from whichever hostname an officer happened to be
       working on. See QrService::url(). */
    'public_url'          => ['label' => 'Public website address (used by printed QR codes)', 'type' => 'url', 'max' => 200],

    /* Municipal emergency numbers, shown on every destination's QR page.
       Settings rather than columns on destinations: the police station has one
       number for the whole municipality, and holding it in twenty destination
       records is twenty places to correct when it changes — and nineteen
       chances to miss one. */
    'hotline_emergency'   => ['label' => 'Emergency (911 / national)',  'type' => 'text', 'max' => 60],
    'hotline_police'      => ['label' => 'Police station',             'type' => 'text', 'max' => 60],
    'hotline_medical'     => ['label' => 'Rural Health Unit / hospital','type' => 'text', 'max' => 60],
    'hotline_rescue'      => ['label' => 'MDRRMO / rescue',            'type' => 'text', 'max' => 60],
    'hotline_fire'        => ['label' => 'Fire station',               'type' => 'text', 'max' => 60],
    'hotline_tourism'     => ['label' => 'Municipal Tourism Office',   'type' => 'text', 'max' => 60],

    /* WHO IS TEXTED WHEN SOMETHING NEEDS THE OFFICE.
       Urgent destination alerts and every tour guide request go to the officers
       who have opted in on My Account, PLUS whatever is listed here — numbers
       with no account behind them: the office landline, a barangay captain,
       the MDRRMO duty phone.

       It had no field anywhere in the system, so the only way to reach it was
       to edit the database by hand. */
    'alert_sms_recipients' => ['label' => 'Extra numbers to text', 'type' => 'text', 'max' => 400],

    /* Shown on EVERY destination's QR page, unlike destinations.cultural_heritage
       which describes one place. Same words at every sign, because it is the
       same municipality. 'text' with a large max — the validator only measures
       length, and the textarea is chosen at render time. */
    'municipal_heritage_title' => ['label' => 'Heading', 'type' => 'text', 'max' => 120],
    'municipal_heritage'       => ['label' => 'The text itself', 'type' => 'text', 'max' => 4000],

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

        if ($rules['type'] === 'url' && $value !== '') {
            $value = rtrim($value, '/');

            if (!filter_var($value, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $value)) {
                $v->addError($key, 'Enter the full address including http:// or https://.');
                continue;
            }

            /* Rejected at the point of entry rather than at the point of
               printing. By the time a poster is on a wall it is too late to
               discover the address only worked on the office computer. */
            $host = strtolower((string) parse_url($value, PHP_URL_HOST));

            if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
                $v->addError($key, 'This address only works on this computer. Printed QR codes carrying it would open nothing on a visitor\'s phone.');
                continue;
            }
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
                        <?php foreach (['office_name', 'office_municipality', 'office_province', 'office_address', 'office_phone', 'office_email', 'office_hours', 'office_facebook'] as $key):
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
                <header class="panel__head">
                    <h2><i class="fa-solid fa-phone-volume"></i> Emergency Hotlines</h2>
                </header>
                <div class="panel__body">
                    <p class="text-muted small mb-3">
                        Shown at the top of every destination's QR page, as tap-to-call numbers. A visitor
                        reading them is standing at the site, so these must be numbers that are answered.
                        <strong>Leave a line blank if you are not sure of it</strong> &mdash; an unanswered
                        number printed on a sign at a waterfall is worse than no number at all, because
                        somebody will dial it in an emergency and wait.
                    </p>

                    <div class="row g-3">
                        <?php foreach ([
                            'hotline_emergency', 'hotline_police', 'hotline_medical',
                            'hotline_rescue', 'hotline_fire', 'hotline_tourism',
                        ] as $key):
                            $rules = $editable[$key]; ?>
                            <div class="col-md-6">
                                <label for="<?= e($key) ?>" class="form-label"><?= e($rules['label']) ?></label>
                                <input type="text" id="<?= e($key) ?>" name="<?= e($key) ?>"
                                       maxlength="<?= (int) $rules['max'] ?>"
                                       placeholder="0917 123 4567"
                                       class="form-control <?= has_error($key) ? 'is-invalid' : '' ?>"
                                       value="<?= old($key, (string) setting($key, '')) ?>">
                                <?php if (has_error($key)): ?><div class="field-error"><?= e(error_for($key)) ?></div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php
                    /* WHO ACTUALLY GETS TEXTED — counted, not described.
                       "Nobody" is the state this installation was in, and no
                       screen anywhere said so. */
                    $reachable = [];

                    try {
                        $reachable = \App\Repositories\AlertRepository::officeRecipients();
                    } catch (\Throwable $e) {
                        $reachable = [];
                    }
                    ?>

                    <hr class="my-4">

                    <h3 class="h6 mb-2"><i class="fa-solid fa-comment-sms"></i> Who the system texts</h3>

                    <?php if ($reachable === []): ?>
                        <div class="alert alert-danger">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <div>
                                <strong>Nobody is reachable by SMS.</strong>
                                <p class="mb-0">
                                    Urgent destination alerts and tour guide requests are texted to the
                                    office &mdash; and right now that message goes to no one. An officer
                                    is reached only if they have a mobile number on
                                    <a href="<?= e(base_url('/admin/account/index.php')) ?>">My Account</a>
                                    <em>and</em> have kept the alert tick-box on. Add a number there, or
                                    list one below.
                                </p>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="text-muted small mb-3">
                            <?= n(count($reachable)) ?> number<?= count($reachable) === 1 ? '' : 's' ?>
                            will be texted: <?= e(implode(', ', $reachable)) ?>.
                        </p>
                    <?php endif; ?>

                    <p class="text-muted small mb-3">
                        Officers are reached through their own account. Use the box below only for
                        numbers with no account behind them &mdash; the office landline, a barangay
                        captain, the MDRRMO duty phone. Separate several with commas.
                    </p>

                    <?php $extraRules = $editable['alert_sms_recipients']; ?>
                    <label for="alert_sms_recipients" class="form-label"><?= e($extraRules['label']) ?></label>
                    <input type="text" id="alert_sms_recipients" name="alert_sms_recipients"
                           maxlength="<?= (int) $extraRules['max'] ?>"
                           placeholder="0917 123 4567, 0918 765 4321"
                           class="form-control <?= has_error('alert_sms_recipients') ? 'is-invalid' : '' ?>"
                           value="<?= old('alert_sms_recipients', (string) setting('alert_sms_recipients', '')) ?>">
                    <p class="field-hint">
                        Anything that is not a Philippine mobile number is ignored rather than refused,
                        so a landline left here simply never receives one.
                    </p>
                    <?php if (has_error('alert_sms_recipients')): ?>
                        <div class="field-error"><?= e(error_for('alert_sms_recipients')) ?></div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="panel">
                <header class="panel__head">
                    <h2><i class="fa-solid fa-people-group"></i> Local Culture &amp; Heritage</h2>
                </header>
                <div class="panel__body">
                    <p class="text-muted small mb-3">
                        Shown on <strong>every destination's QR page</strong>, below that destination's own
                        heritage section. This is about Tampakan itself &mdash; the people, the customs, the
                        history a visitor should know wherever in the municipality they happen to be standing.
                        Written once here rather than copied into each destination, so correcting it corrects
                        it everywhere.
                    </p>

                    <div class="mb-3">
                        <label for="municipal_heritage_title" class="form-label">
                            <?= e($editable['municipal_heritage_title']['label']) ?>
                        </label>
                        <input type="text" id="municipal_heritage_title" name="municipal_heritage_title"
                               maxlength="120"
                               class="form-control <?= has_error('municipal_heritage_title') ? 'is-invalid' : '' ?>"
                               value="<?= old('municipal_heritage_title', (string) setting('municipal_heritage_title', '')) ?>">
                        <?php if (has_error('municipal_heritage_title')): ?>
                            <div class="field-error"><?= e(error_for('municipal_heritage_title')) ?></div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label for="municipal_heritage" class="form-label">
                            <?= e($editable['municipal_heritage']['label']) ?>
                        </label>
                        <?php /* A textarea, not an input. This is paragraphs — the
                                 B'laan and T'boli communities of the area, the
                                 harvest customs, why the mountain matters. */ ?>
                        <textarea id="municipal_heritage" name="municipal_heritage" rows="8" maxlength="4000"
                                  class="form-control <?= has_error('municipal_heritage') ? 'is-invalid' : '' ?>"
                                  placeholder="Tampakan sits at the foot of Mt. Matutum, on land the B'laan people have lived on for generations&hellip;"><?= old('municipal_heritage', (string) setting('municipal_heritage', '')) ?></textarea>
                        <p class="form-text">
                            Leave blank and the section simply does not appear on any QR page.
                        </p>
                        <?php if (has_error('municipal_heritage')): ?>
                            <div class="field-error"><?= e(error_for('municipal_heritage')) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="panel">
                <header class="panel__head"><h2><i class="fa-solid fa-qrcode"></i> Printed Signage Address</h2></header>
                <div class="panel__body">
                    <p class="text-muted small mb-3">
                        The address a scanned QR code opens. Every other link on this system follows
                        whatever address you are browsing on; this one must not, because it is printed
                        onto signs that stay in the field for years. Set it to the address a tourist on
                        mobile data can reach.
                    </p>

                    <label for="public_url" class="form-label"><?= e($editable['public_url']['label']) ?></label>
                    <input type="url" id="public_url" name="public_url"
                           maxlength="<?= (int) $editable['public_url']['max'] ?>"
                           placeholder="https://tourism.tampakan.gov.ph"
                           class="form-control <?= has_error('public_url') ? 'is-invalid' : '' ?>"
                           value="<?= old('public_url', (string) setting('public_url', '')) ?>">
                    <?php if (has_error('public_url')): ?>
                        <div class="field-error"><?= e(error_for('public_url')) ?></div>
                    <?php endif; ?>

                    <p class="text-muted small mt-2 mb-0">
                        Codes currently point at
                        <code><?= e(App\Core\QrService::publicBase()) ?>/d/&hellip;</code>
                        <?php if (!App\Core\QrService::isPublishable()): ?>
                            &mdash; <strong class="text-danger">not usable on a printed sign.</strong>
                            <?= e(App\Core\QrService::unpublishableReason()) ?>
                        <?php endif; ?>
                    </p>
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
