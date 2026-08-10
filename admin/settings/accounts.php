<?php
declare(strict_types=1);

/**
 * TourSync — user accounts.       Officer only.
 *
 * The only place an account can be created. Three protections run through
 * every action here, because the failure modes are not symmetrical: a locked
 * office is as bad as an open one.
 *
 *   1. An officer cannot remove their own last route back in.
 *   2. The last active officer cannot be demoted or deactivated.
 *   3. Every change is audit-logged with who did it.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\Validator;
use App\Repositories\AdminRepository;

Auth::require('officer');

$pageTitle    = 'User Accounts';
$pageIcon     = 'fa-users-gear';
$pageSubtitle = 'Tourism Office personnel with system access';

if (is_post()) {
    Csrf::verify();
    $action = (string) ($_POST['action'] ?? '');
    $target = (int) ($_POST['id'] ?? 0);
    $self   = (int) Auth::id();

    // ---- Create -----------------------------------------------------------
    if ($action === 'create') {
        $v = new Validator($_POST);
        $v->require('full_name', 'username', 'email', 'password')
          ->length('full_name', 2, 120)
          ->length('username', 3, 60)
          ->email('email')
          ->in('role', ['officer', 'staff']);

        $username = strtolower((string) $v->value('username'));

        if (!preg_match('/^[a-z0-9._-]+$/', $username)) {
            $v->addError('username', 'Use only letters, numbers, dots, hyphens, and underscores.');
        }
        if (AdminRepository::usernameTaken($username)) {
            $v->addError('username', 'That username is already taken.');
        }
        if (AdminRepository::emailTaken((string) $v->value('email'))) {
            $v->addError('email', 'That email address is already registered.');
        }

        $problems = AdminRepository::passwordProblems((string) ($_POST['password'] ?? ''));
        if ($problems !== []) {
            $v->addError('password', 'The password ' . implode(', and ', $problems) . '.');
        }

        if ($v->fails()) {
            flash_back($v->errors(), $_POST, 'accounts.php');
        }

        $id = AdminRepository::create([
            'full_name' => (string) $v->value('full_name'),
            'username'  => $username,
            'email'     => (string) $v->value('email'),
            'password'  => (string) $_POST['password'],
            'role'      => (string) $v->value('role', 'staff'),
        ]);

        ActivityLog::record('account.create', 'admin', $id,
            'Created ' . $v->value('role', 'staff') . ' account "' . $username . '"');
        Session::flash('success', 'Account created. Ask them to change the password at their first sign-in.');
        redirect(base_url('/admin/settings/accounts.php'));
    }

    $account = AdminRepository::find($target);

    if ($account === null) {
        Session::flash('danger', 'That account no longer exists.');
        redirect(base_url('/admin/settings/accounts.php'));
    }

    // ---- Role change ------------------------------------------------------
    if ($action === 'role') {
        $role = (string) ($_POST['role'] ?? '');

        if (!in_array($role, ['officer', 'staff'], true)) {
            Session::flash('danger', 'Unrecognised role.');
            redirect(base_url('/admin/settings/accounts.php'));
        }

        // Demoting the last officer would leave nobody able to create accounts,
        // change settings, or void a record — a locked office.
        if ($role === 'staff' && $account['role'] === 'officer' && AdminRepository::activeOfficerCount() <= 1) {
            Session::flash('danger', 'This is the only active Tourism Officer. Promote someone else first.');
            redirect(base_url('/admin/settings/accounts.php'));
        }

        AdminRepository::setRole($target, $role);
        ActivityLog::record('account.role', 'admin', $target,
            'Changed ' . $account['username'] . ' to ' . $role);
        Session::flash('success', $account['full_name'] . ' is now ' . ($role === 'officer' ? 'a Tourism Officer' : 'Tourism Staff') . '.');
    }

    // ---- Activate / deactivate --------------------------------------------
    if ($action === 'active') {
        $activate = !empty($_POST['activate']);

        if (!$activate && $target === $self) {
            Session::flash('danger', 'You cannot deactivate your own account.');
            redirect(base_url('/admin/settings/accounts.php'));
        }

        if (!$activate && $account['role'] === 'officer' && AdminRepository::activeOfficerCount() <= 1) {
            Session::flash('danger', 'This is the only active Tourism Officer. Promote someone else first.');
            redirect(base_url('/admin/settings/accounts.php'));
        }

        AdminRepository::setActive($target, $activate);
        ActivityLog::record($activate ? 'account.activate' : 'account.deactivate', 'admin', $target,
            ($activate ? 'Reactivated ' : 'Deactivated ') . $account['username']);
        Session::flash('success', $account['full_name'] . ($activate ? ' reactivated.' : ' deactivated.'));
    }

    // ---- Unlock -----------------------------------------------------------
    if ($action === 'unlock') {
        AdminRepository::unlock($target);
        ActivityLog::record('account.unlock', 'admin', $target, 'Unlocked ' . $account['username']);
        Session::flash('success', $account['full_name'] . ' can sign in again.');
    }

    // ---- Reset password ---------------------------------------------------
    if ($action === 'reset') {
        $new = (string) ($_POST['new_password'] ?? '');
        $problems = AdminRepository::passwordProblems($new);

        if ($problems !== []) {
            Session::flash('danger', 'The password ' . implode(', and ', $problems) . '.');
            redirect(base_url('/admin/settings/accounts.php'));
        }

        AdminRepository::changePassword($target, $new);

        // Stamped as changed by an officer, not by the account holder — worth
        // distinguishing if the log is ever read after an incident.
        ActivityLog::record('account.reset', 'admin', $target,
            'Password reset for ' . $account['username'] . ' by an officer');
        Session::flash('success', 'Password reset. Give it to ' . $account['full_name'] . ' privately and ask them to change it.');
    }

    redirect(base_url('/admin/settings/accounts.php'));
}

$accounts   = AdminRepository::all();
$unchanged  = AdminRepository::usingInstallerPassword();
$officers   = AdminRepository::activeOfficerCount();

require __DIR__ . '/../_partials/head.php';
?>

<?php if ($unchanged !== []): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-key"></i>
        <strong><?= n(count($unchanged)) ?> account(s) have never changed their password:</strong>
        <?= e(implode(', ', array_column($unchanged, 'username'))) ?>.
        An installer-generated password may still exist in a screenshot or a notebook.
    </div>
<?php endif; ?>

<div class="panel panel--notice">
    <div class="panel__body">
        <h2><i class="fa-solid fa-user-lock"></i> No public registration</h2>
        <p class="mb-0">
            Accounts can only be created here, by a Tourism Officer. There is no sign-up page
            anywhere in TourSync — the public site has no route to an account, and the login page
            offers no way to create one.
        </p>
    </div>
</div>

<section class="panel">
    <header class="panel__head"><h2><i class="fa-solid fa-users"></i> Accounts (<?= n(count($accounts)) ?>)</h2></header>
    <div class="panel__body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Last sign-in</th><th>Password</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($accounts as $a):
                    $isSelf   = (int) $a['id'] === (int) Auth::id();
                    $isLocked = $a['locked_until'] !== null && strtotime($a['locked_until']) > time(); ?>
                    <tr class="<?= (int) $a['is_active'] === 0 ? 'is-voided' : '' ?>">
                        <td>
                            <span class="cell-strong"><?= e($a['full_name']) ?><?= $isSelf ? ' (you)' : '' ?></span>
                            <span class="cell-sub"><?= e($a['email']) ?></span>
                        </td>
                        <td class="mono small"><?= e($a['username']) ?></td>
                        <td>
                            <span class="pill pill--<?= $a['role'] === 'officer' ? 'ok' : 'manual' ?>">
                                <?= $a['role'] === 'officer' ? 'Officer' : 'Staff' ?>
                            </span>
                        </td>
                        <td class="small"><?= $a['last_login_at'] ? e(format_date($a['last_login_at'], 'M j, g:i A')) : 'Never' ?></td>
                        <td class="small">
                            <?php if ($a['password_changed_at'] === null): ?>
                                <span class="pill pill--flag">Never changed</span>
                            <?php else: ?>
                                <?= e(format_date($a['password_changed_at'], 'M j, Y')) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isLocked): ?>
                                <span class="pill pill--void">Locked</span>
                            <?php elseif ((int) $a['is_active'] === 1): ?>
                                <span class="pill pill--ok">Active</span>
                            <?php else: ?>
                                <span class="pill pill--void">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="d-flex gap-1 justify-content-end flex-wrap">
                                <?php if ($isLocked): ?>
                                    <form method="post"><?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                        <input type="hidden" name="action" value="unlock">
                                        <button class="btn btn-sm btn-outline-success">Unlock</button>
                                    </form>
                                <?php endif; ?>

                                <?php if (!$isSelf): ?>
                                    <form method="post" onsubmit="return confirm('Change this role?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                        <input type="hidden" name="action" value="role">
                                        <input type="hidden" name="role" value="<?= $a['role'] === 'officer' ? 'staff' : 'officer' ?>">
                                        <button class="btn btn-sm btn-outline-secondary">
                                            <?= $a['role'] === 'officer' ? 'Make Staff' : 'Make Officer' ?>
                                        </button>
                                    </form>

                                    <form method="post" onsubmit="return confirm('<?= (int) $a['is_active'] === 1 ? 'Deactivate this account?' : 'Reactivate this account?' ?>');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                        <input type="hidden" name="action" value="active">
                                        <input type="hidden" name="activate" value="<?= (int) $a['is_active'] === 1 ? '0' : '1' ?>">
                                        <button class="btn btn-sm btn-outline-<?= (int) $a['is_active'] === 1 ? 'danger' : 'success' ?>">
                                            <?= (int) $a['is_active'] === 1 ? 'Deactivate' : 'Reactivate' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <button class="btn btn-sm btn-outline-secondary"
                                        onclick="resetPassword(<?= (int) $a['id'] ?>, '<?= e(addslashes($a['full_name'])) ?>')">
                                    Reset Password
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="report-note">
            <?= n($officers) ?> active Tourism Officer<?= $officers === 1 ? '' : 's' ?>.
            The last one cannot be demoted or deactivated — a locked office is as damaging as an open one.
        </p>
    </div>
</section>

<section class="panel">
    <header class="panel__head"><h2><i class="fa-solid fa-user-plus"></i> Create an Account</h2></header>
    <div class="panel__body">
        <form method="post" class="row g-3" novalidate autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">

            <div class="col-md-4">
                <label for="full_name" class="form-label">Full name <span class="req">*</span></label>
                <input type="text" id="full_name" name="full_name" required maxlength="120"
                       class="form-control <?= has_error('full_name') ? 'is-invalid' : '' ?>" value="<?= old('full_name') ?>">
                <?php if (has_error('full_name')): ?><div class="field-error"><?= e(error_for('full_name')) ?></div><?php endif; ?>
            </div>

            <div class="col-md-3">
                <label for="username" class="form-label">Username <span class="req">*</span></label>
                <input type="text" id="username" name="username" required maxlength="60"
                       class="form-control <?= has_error('username') ? 'is-invalid' : '' ?>" value="<?= old('username') ?>">
                <?php if (has_error('username')): ?><div class="field-error"><?= e(error_for('username')) ?></div><?php endif; ?>
            </div>

            <div class="col-md-5">
                <label for="new_email" class="form-label">Email <span class="req">*</span></label>
                <input type="email" id="new_email" name="email" required maxlength="160"
                       class="form-control <?= has_error('email') ? 'is-invalid' : '' ?>" value="<?= old('email') ?>">
                <?php if (has_error('email')): ?><div class="field-error"><?= e(error_for('email')) ?></div><?php endif; ?>
            </div>

            <div class="col-md-4">
                <label for="role" class="form-label">Role <span class="req">*</span></label>
                <select id="role" name="role" class="form-select">
                    <option value="staff">Tourism Staff — daily operations</option>
                    <option value="officer">Tourism Officer — full access</option>
                </select>
                <p class="field-hint">Staff cannot void records, rotate QR codes, or change settings.</p>
            </div>

            <div class="col-md-4">
                <label for="password" class="form-label">Initial password <span class="req">*</span></label>
                <input type="text" id="password" name="password" required
                       class="form-control <?= has_error('password') ? 'is-invalid' : '' ?>">
                <p class="field-hint">At least 10 characters, with a letter and a number. Give it to them privately.</p>
                <?php if (has_error('password')): ?><div class="field-error"><?= e(error_for('password')) ?></div><?php endif; ?>
            </div>

            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-brand w-100"><i class="fa-solid fa-user-plus"></i> Create Account</button>
            </div>
        </form>
    </div>
</section>

<!-- Password reset, posted from the table -->
<form method="post" id="resetForm" class="d-none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset">
    <input type="hidden" name="id" id="resetId">
    <input type="hidden" name="new_password" id="resetPassword">
</form>

<?php
$pageScripts = <<<'HTML'
<script>
function resetPassword(id, name) {
    const value = prompt('Set a new password for ' + name + '.\n\nAt least 10 characters, with a letter and a number.\nGive it to them privately and ask them to change it.');
    if (value === null || value.trim() === '') return;

    document.getElementById('resetId').value = id;
    document.getElementById('resetPassword').value = value;
    document.getElementById('resetForm').submit();
}
</script>
HTML;

require __DIR__ . '/../_partials/foot.php';
