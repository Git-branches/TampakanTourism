<?php
declare(strict_types=1);

/**
 * TourSync — the signed-in user's own profile and password.
 *
 * Separate from the accounts screen on purpose: changing your own password is
 * something every user must be able to do, while creating and disabling
 * accounts belongs to the Tourism Officer alone.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\Validator;
use App\Repositories\AdminRepository;

Auth::require();

$pageTitle    = 'My Account';
$pageIcon     = 'fa-user-gear';
$pageSubtitle = 'Your profile and password';

$id = (int) Auth::id();
$me = AdminRepository::find($id);

if (is_post()) {
    Csrf::verify();
    $action = (string) ($_POST['action'] ?? '');

    // ---- Profile ----------------------------------------------------------
    if ($action === 'profile') {
        $v = new Validator($_POST);
        $v->require('full_name', 'email')->length('full_name', 2, 120)->email('email');

        if ($v->passes() && AdminRepository::emailTaken((string) $v->value('email'), $id)) {
            $v->addError('email', 'Another account already uses that email address.');
        }

        if ($v->fails()) {
            flash_back($v->errors(), $_POST, 'index.php');
        }

        AdminRepository::updateProfile($id, [
            'full_name'        => (string) $v->value('full_name'),
            'email'            => (string) $v->value('email'),
            'mobile_number'    => (string) ($_POST['mobile_number'] ?? ''),
            'alert_sms_opt_in' => !empty($_POST['alert_sms_opt_in']),
        ]);

        // The name is shown in the topbar from the session, so refresh it.
        $_SESSION['_admin']['full_name'] = (string) $v->value('full_name');

        ActivityLog::record('account.profile', 'admin', $id, 'Updated own profile');
        Session::flash('success', 'Profile updated.');
        redirect(base_url('/admin/account/index.php'));
    }

    // ---- Sign-in name -----------------------------------------------------
    if ($action === 'username') {
        $wanted  = strtolower(trim((string) ($_POST['new_username'] ?? '')));
        $current = (string) ($_POST['username_password'] ?? '');
        $errors  = [];

        /* THE CURRENT PASSWORD, for the same reason the password change asks for
           it: the session already proves identity, but an unattended terminal
           that is still signed in must not be usable to rename the account out
           from under the person who owns it. A rename is quiet — nothing on
           screen changes — so it is exactly the change worth making noisy. */
        if (!password_verify($current, $me['password_hash'])) {
            $errors['username_password'] = 'That is not your current password.';
        }

        if ($wanted === $me['username']) {
            $errors['new_username'] = 'That is already your username.';
        } else {
            $problems = AdminRepository::usernameProblems($wanted, $id);

            if ($problems !== []) {
                $errors['new_username'] = 'The username ' . implode(', and ', $problems) . '.';
            }
        }

        if ($errors !== []) {
            flash_back($errors, ['new_username' => $wanted], 'index.php');
        }

        $was = (string) $me['username'];

        AdminRepository::changeUsername($id, $wanted);

        /* The session carries its own copy, and Auth reads from there. Left
           stale, the officer would be signed in as a username that no longer
           exists until the session expired. */
        $_SESSION['_admin']['username'] = $wanted;

        ActivityLog::record('account.username', 'admin', $id,
            'Renamed own sign-in from ' . $was . ' to ' . $wanted);

        Session::flash('success', 'You now sign in as "' . $wanted . '". Your password has not changed.');
        redirect(base_url('/admin/account/index.php'));
    }

    // ---- Password ---------------------------------------------------------
    if ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $errors = [];

        // The current password is required even though the session already
        // proves identity: it stops an unattended, still-signed-in terminal
        // being used to take the account over permanently.
        if (!password_verify($current, $me['password_hash'])) {
            $errors['current_password'] = 'That is not your current password.';
        }

        $problems = AdminRepository::passwordProblems($new);
        if ($problems !== []) {
            $errors['new_password'] = 'The new password ' . implode(', and ', $problems) . '.';
        }

        if ($new !== $confirm) {
            $errors['confirm_password'] = 'The two entries do not match.';
        }

        if ($new === $current && !isset($errors['current_password'])) {
            $errors['new_password'] = 'The new password must be different from the current one.';
        }

        if ($errors !== []) {
            flash_back($errors, [], 'index.php');
        }

        AdminRepository::changePassword($id, $new);
        ActivityLog::record('account.password', 'admin', $id, 'Changed own password');

        Session::flash('success', 'Password changed. It takes effect on your next sign-in.');
        redirect(base_url('/admin/account/index.php'));
    }
}

$neverChanged = $me['password_changed_at'] === null;

require __DIR__ . '/../_partials/section-head.php';
require __DIR__ . '/../_partials/head.php';
?>

<?php /* The Settings tab strip, so this page sits inside the group it belongs to
         rather than being a separate destination reached only from the sidebar.

         The partial renders NOTHING for a Tourism Staff member: every other tab
         in it is Auth::require('officer'), and this page is the one place here
         they are allowed. A strip of tabs that would all refuse them is worse
         than no strip. */ ?>
<?php $settingsTab = 'me'; require __DIR__ . '/../_partials/settings-tabs.php'; ?>

<?php if ($neverChanged): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <strong>This account is still using the password generated by the installer.</strong>
        That password was printed to a terminal and may exist in a screenshot, a chat log, or a
        notebook. Change it below.
    </div>
<?php endif; ?>

<div class="panel-row">
    <div>
        <section class="panel">
            <?php section_head('fa-key', 'Change Password', 'Your own sign-in password. It takes effect on your next sign-in.') ?>
            <div class="panel__body">
                <form method="post" class="row g-3" novalidate autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="password">

                    <div class="col-12">
                        <label for="current_password" class="form-label">Current password <span class="req">*</span></label>
                        <input type="password" id="current_password" name="current_password" required
                               autocomplete="current-password"
                               class="form-control <?= has_error('current_password') ? 'is-invalid' : '' ?>">
                        <?php if (has_error('current_password')): ?>
                            <div class="field-error"><?= e(error_for('current_password')) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-6">
                        <label for="new_password" class="form-label">New password <span class="req">*</span></label>
                        <input type="password" id="new_password" name="new_password" required
                               autocomplete="new-password"
                               class="form-control <?= has_error('new_password') ? 'is-invalid' : '' ?>">
                        <p class="field-hint">At least 10 characters, with a letter and a number.</p>
                        <?php if (has_error('new_password')): ?>
                            <div class="field-error"><?= e(error_for('new_password')) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-6">
                        <label for="confirm_password" class="form-label">Confirm new password <span class="req">*</span></label>
                        <input type="password" id="confirm_password" name="confirm_password" required
                               autocomplete="new-password"
                               class="form-control <?= has_error('confirm_password') ? 'is-invalid' : '' ?>">
                        <?php if (has_error('confirm_password')): ?>
                            <div class="field-error"><?= e(error_for('confirm_password')) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-brand">
                            <i class="fa-solid fa-key"></i> Change Password
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <?php /* NEXT TO THE PASSWORD, because it asks for the same proof and
                 carries the same weight: this is the name you sign in with. */ ?>
        <section class="panel">
            <?php section_head('fa-at', 'Sign-in Name',
                'The username you type on the sign-in page.') ?>
            <div class="panel__body">
                <form method="post" class="row g-3" novalidate autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="username">

                    <div class="col-md-6">
                        <label class="form-label">Current username</label>
                        <input type="text" class="form-control mono" value="<?= e($me['username']) ?>"
                               readonly disabled>
                        <p class="field-hint">
                            <?php /* Worth saying plainly. Auth::attempt() matches on
                                     `username = ? OR email = ?`, so an officer who
                                     forgets what they renamed it to is not locked
                                     out — their email still signs them in. */ ?>
                            You can also sign in with your email address,
                            <strong><?= e($me['email']) ?></strong>, whatever this is set to.
                        </p>
                    </div>

                    <div class="col-md-6">
                        <label for="new_username" class="form-label">New username <span class="req">*</span></label>
                        <input type="text" id="new_username" name="new_username" required
                               maxlength="60" autocomplete="username" spellcheck="false"
                               class="form-control mono <?= has_error('new_username') ? 'is-invalid' : '' ?>"
                               value="<?= old('new_username') ?>">
                        <p class="field-hint">
                            3 to 60 characters, using letters, numbers, and
                            <code>.</code> <code>_</code> <code>-</code>.
                            Capitals are saved as lowercase.
                        </p>
                        <?php if (has_error('new_username')): ?>
                            <div class="field-error"><?= e(error_for('new_username')) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-6">
                        <label for="username_password" class="form-label">
                            Your password <span class="req">*</span>
                        </label>
                        <input type="password" id="username_password" name="username_password" required
                               autocomplete="current-password"
                               class="form-control <?= has_error('username_password') ? 'is-invalid' : '' ?>">
                        <p class="field-hint">
                            Asked because a signed-in machine left unattended must not be
                            enough to rename the account.
                        </p>
                        <?php if (has_error('username_password')): ?>
                            <div class="field-error"><?= e(error_for('username_password')) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-brand"
                                data-confirm="Change your sign-in name? You will type the new one next time — your password stays the same."
                                data-confirm-tone="normal">
                            <i class="fa-solid fa-at"></i> Change Sign-in Name
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <section class="panel">
            <?php section_head('fa-user', 'Profile', 'Your name, and where the office reaches you.') ?>
            <div class="panel__body">
                <form method="post" class="row g-3" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="profile">

                    <div class="col-md-6">
                        <label for="full_name" class="form-label">Full name <span class="req">*</span></label>
                        <input type="text" id="full_name" name="full_name" required maxlength="120"
                               class="form-control <?= has_error('full_name') ? 'is-invalid' : '' ?>"
                               value="<?= old('full_name', $me['full_name']) ?>">
                        <?php if (has_error('full_name')): ?>
                            <div class="field-error"><?= e(error_for('full_name')) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-6">
                        <label for="email" class="form-label">Email <span class="req">*</span></label>
                        <input type="email" id="email" name="email" required maxlength="160"
                               class="form-control <?= has_error('email') ? 'is-invalid' : '' ?>"
                               value="<?= old('email', $me['email']) ?>">
                        <?php if (has_error('email')): ?>
                            <div class="field-error"><?= e(error_for('email')) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-6">
                        <label for="mobile_number" class="form-label">Mobile number</label>
                        <input type="tel" id="mobile_number" name="mobile_number" maxlength="20"
                               class="form-control" inputmode="tel"
                               placeholder="09XX XXX XXXX"
                               value="<?= old('mobile_number', (string) ($me['mobile_number'] ?? '')) ?>">
                        <?php /* .field-hint, not Bootstrap's .text-muted.small — the password
                                 panel three inches above uses .field-hint, and one screen
                                 should not hold two ideas of what a hint under a field is. */ ?>
                        <p class="field-hint">
                            Where destination alerts are texted. You are not always in front of this
                            dashboard, and a manager reporting a closure needs somebody to know.
                        </p>
                    </div>

                    <div class="col-md-6 d-flex align-items-center">
                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" value="1"
                                   id="alert_sms_opt_in" name="alert_sms_opt_in"
                                   <?= (int) ($me['alert_sms_opt_in'] ?? 1) === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="alert_sms_opt_in">
                                Text me when a destination reports something
                                <span class="field-hint d-block">
                                    Only reports at
                                    <strong><?= e(\App\Repositories\AlertRepository::SEVERITIES[
                                        (string) (setting('alert_sms_threshold', 'warning') ?? 'warning')
                                    ] ?? 'Needs attention') ?></strong>
                                    or above. Turn this off to keep the number on file without the texts.
                                </span>
                            </label>
                        </div>
                    </div>

                    <?php /* btn-brand, matching Change Password above. Both are the primary
                             action of their own panel; the outline weight said this one was
                             secondary to something, and there is nothing for it to be
                             secondary to inside its own panel. */ ?>
                    <div class="col-12">
                        <button type="submit" class="btn btn-brand">
                            <i class="fa-solid fa-floppy-disk"></i> Save Profile
                        </button>
                    </div>
                </form>
            </div>
        </section>
    </div>

    <div class="panel-stack">
        <section class="panel">
            <?php section_head('fa-id-card', 'Account', 'What this account is, and when it was last used.') ?>
            <div class="panel__body">
                <dl class="detail-grid detail-grid--single">
                    <div><dt>Username</dt><dd class="mono"><?= e($me['username']) ?></dd></div>
                    <div><dt>Role</dt><dd><?= $me['role'] === 'officer' ? 'Tourism Officer' : 'Tourism Staff' ?></dd></div>
                    <div><dt>Last sign-in</dt><dd><?= $me['last_login_at'] ? e(format_date($me['last_login_at'], 'M j, Y g:i A')) : 'This is your first' ?></dd></div>
                    <div>
                        <dt>Password last changed</dt>
                        <dd class="<?= $neverChanged ? 'text-danger' : '' ?>">
                            <?= $neverChanged ? 'Never — still the installer password' : e(format_date($me['password_changed_at'], 'M j, Y')) ?>
                        </dd>
                    </div>
                    <div><dt>Account created</dt><dd><?= e(format_date($me['created_at'])) ?></dd></div>
                </dl>
            </div>
        </section>

        <section class="panel">
            <?php section_head('fa-shield-halved', 'Security Notes', 'How TourSync protects a sign-in.') ?>
            <div class="panel__body">
                <ul class="note-list">
                    <li><i class="fa-solid fa-lock"></i>
                        <span>Passwords are stored as Argon2id hashes and cannot be read by anyone, including this office.</span></li>
                    <li><i class="fa-solid fa-ban"></i>
                        <span>Five failed sign-ins lock the account for fifteen minutes.</span></li>
                    <li><i class="fa-solid fa-clock"></i>
                        <span>Sessions end after 30 minutes idle, or 8 hours regardless.</span></li>
                    <li><i class="fa-solid fa-clipboard-list"></i>
                        <span>Every sign-in and administrative change is recorded in the activity log.</span></li>
                </ul>
            </div>
        </section>
    </div>
</div>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
