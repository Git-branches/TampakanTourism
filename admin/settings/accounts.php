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

use App\Core\Paginator;
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

$all        = AdminRepository::all();
$pager      = Paginator::slice($all, $_GET['page'] ?? null);
$accounts   = $pager['rows'];
$unchanged  = AdminRepository::usingInstallerPassword();
$officers   = AdminRepository::activeOfficerCount();

/* Counted over the WHOLE list, not the page window. A tally that changes when
   you turn the page is a tally nobody can act on — the roster learned this. */
$tally = ['total' => count($all), 'officers' => 0, 'staff' => 0, 'inactive' => 0];

foreach ($all as $row) {
    if ((int) $row['is_active'] !== 1) {
        $tally['inactive']++;
    }

    ($row['role'] === 'officer') ? $tally['officers']++ : $tally['staff']++;
}

/* The create form reopens over the list when it is rejected, rather than
   sending anybody to a second screen to read why. */
$sheetOpen = old_all() !== [];

require __DIR__ . '/../_partials/section-head.php';
require __DIR__ . '/../_partials/head.php';
?>

<?php $settingsTab = 'accounts'; require __DIR__ . '/../_partials/settings-tabs.php'; ?>

<?php if ($unchanged !== []): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-key"></i>
        <strong><?= n(count($unchanged)) ?> account(s) have never changed their password:</strong>
        <?= e(implode(', ', array_column($unchanged, 'username'))) ?>.
        An installer-generated password may still exist in a screenshot or a notebook.
    </div>
<?php endif; ?>

<?php /* THE COUNTS, NOT A PARAGRAPH ABOUT THEM.
         A "No public registration" notice used to fill a whole panel at the top
         of this page, saying something true that nobody needed to be told twice.
         It is one line under the section title now, and the space it was using
         says how many people can actually sign in. */ ?>
<div class="stat-grid">
    <?php foreach ([
        ['fa-users',         'blue',  $tally['total'],    'Accounts'],
        ['fa-user-shield',   'green', $tally['officers'], $tally['officers'] === 1 ? 'Tourism Officer' : 'Tourism Officers'],
        /* No ternary: "Tourism Staff" is a mass noun and reads correctly at
           nought, one and five. It had one anyway with the same string in both
           branches — a line that looks like a bug even though it is harmless.
           "Tourism Officer" does need one, and has it. */
        ['fa-user',          'teal',  $tally['staff'],    'Tourism Staff'],
        ['fa-user-slash',    'amber', $tally['inactive'], 'Deactivated'],
    ] as [$icon, $tone, $value, $label]): ?>
        <div class="stat-card stat-card--<?= e($tone) ?>">
            <div class="stat-card__icon"><i class="fa-solid <?= e($icon) ?>"></i></div>
            <div class="stat-card__body">
                <p class="stat-card__value"><?= n((int) $value) ?></p>
                <p class="stat-card__label"><?= e($label) ?></p>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php /* One bar, the same one the roster, arrivals and messages use — so the
         primary action sits where it sits on every other list in this system. */ ?>
<div class="filter-bar">
    <div class="filter-bar__row">
        <p class="filter-bar__note">
            <i class="fa-solid fa-user-lock"></i>
            There is no sign-up page anywhere in TourSync. An account exists only because
            the installer or a Tourism Officer created it here.
        </p>

        <div class="filter-bar__spacer"></div>

        <div class="filter-bar__actions">
            <button type="button" class="btn btn-brand btn-sm" data-dialog="addAccount">
                <i class="fa-solid fa-user-plus"></i> Create account
            </button>
        </div>
    </div>
</div>

<section class="panel">
    <?php section_head('fa-users', 'Accounts',
        'Everyone who can sign in, and what each of them may do.',
        $tally['total'] === 1 ? '1 account' : $tally['total'] . ' accounts') ?>
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
                                    <?php /* The question names the person and says what changes.
                                             "Change this role?" is a question about a row; an
                                             officer with six rows on screen needs to know which
                                             one they are about to promote. */ ?>
                                    <form method="post"
                                          data-confirm="<?= $a['role'] === 'officer'
                                              ? 'Make ' . e($a['full_name']) . ' Tourism Staff? They lose access to settings, accounts and voiding records.'
                                              : 'Make ' . e($a['full_name']) . ' a Tourism Officer? They gain full access, including this page.' ?>"
                                          data-confirm-tone="normal">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                        <input type="hidden" name="action" value="role">
                                        <input type="hidden" name="role" value="<?= $a['role'] === 'officer' ? 'staff' : 'officer' ?>">
                                        <button class="btn btn-sm btn-outline-secondary">
                                            <?= $a['role'] === 'officer' ? 'Make Staff' : 'Make Officer' ?>
                                        </button>
                                    </form>

                                    <?php
                                    /* THE PHP TAGS HERE WERE HTML-ESCAPED — `&lt;?=` and `?&gt;`
                                       — so this never ran. The officer clicked Deactivate and
                                       the confirmation dialog showed them the source code of
                                       the ternary instead of a question. Escaped inside an
                                       attribute is invisible in the editor and invisible in
                                       view-source; it only shows up in the dialog. */
                                    $isOn = (int) $a['is_active'] === 1;
                                    ?>
                                    <form method="post"
                                          data-confirm="<?= $isOn
                                              ? 'Deactivate ' . e($a['full_name']) . '? They will not be able to sign in.'
                                              : 'Reactivate ' . e($a['full_name']) . '?' ?>"
                                          data-confirm-tone="<?= $isOn ? 'danger' : 'normal' ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                        <input type="hidden" name="action" value="active">
                                        <input type="hidden" name="activate" value="<?= $isOn ? '0' : '1' ?>">
                                        <button class="btn btn-sm btn-outline-<?= $isOn ? 'danger' : 'success' ?>">
                                            <?= $isOn ? 'Deactivate' : 'Reactivate' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php /* Data attributes, not onclick with addslashes.
                                         addslashes escapes for a PHP string literal, not for an
                                         HTML attribute inside a JavaScript argument — a surname
                                         with an apostrophe was one nesting level away from
                                         breaking the handler. The value goes through e() into an
                                         attribute and the script reads it back as data. */ ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        data-reset-id="<?= (int) $a['id'] ?>"
                                        data-reset-name="<?= e($a['full_name']) ?>">
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

<?php /* A SHEET, LIKE EVERY OTHER "ADD" IN THIS SYSTEM.
         It was a permanent panel below the table: a six-field form open on the
         screen at all times, on a page an officer visits mostly to reset one
         password. The roster, the managers and the videos all put creation
         behind a button, and this now matches them.

         --wide, not the 680px default: six fields in three columns wrap onto
         separate lines at 680 and the sheet becomes a scroll. */ ?>
<dialog class="sheet sheet--wide" id="addAccount"<?= $sheetOpen ? ' data-open' : '' ?>>
    <form method="post" novalidate autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">

        <header class="sheet__head">
            <h2><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Create an account</h2>
            <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </header>

        <div class="sheet__body">
            <div class="row g-3">
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

            </div>
        </div>

        <footer class="sheet__foot">
            <button type="button" class="btn btn-outline-secondary" data-dialog-close>Cancel</button>
            <button type="submit" class="btn btn-brand">
                <i class="fa-solid fa-user-plus"></i> Create account
            </button>
        </footer>
    </form>
</dialog>

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
/* Was a bare prompt(). Two things it could not do: mask what was being typed,
   and refuse a password the server was going to reject anyway. Both matter on
   an office machine where somebody may be standing behind you.

   Reached by delegation from the buttons' data-reset-id / data-reset-name rather
   than by an onclick that had to pass a name through addslashes — see the note
   in the table. Delegation also means a row added to the DOM later still works. */
document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-reset-id]');

    if (!button) { return; }

    resetPassword(button.getAttribute('data-reset-id'),
                  button.getAttribute('data-reset-name'));
});

function resetPassword(id, name) {
    var send = function (value) {
        document.getElementById('resetId').value = id;
        document.getElementById('resetPassword').value = value;
        document.getElementById('resetForm').submit();
    };

    if (!window.TourSync) {
        var typed = window.prompt('Set a new password for ' + name + '.');
        if (typed !== null && typed.trim() !== '') { send(typed); }
        return;
    }

    /* The name comes from the accounts table, so it is escaped before it goes
       anywhere near innerHTML — an apostrophe in a surname should not end a tag. */
    var safe = String(name).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });

    window.TourSync.askFor({
        icon:        'question',
        title:       'Reset password',
        text:        'A new password for <strong>' + safe + '</strong>.<br>'
                   + '<span class="text-muted small">'
                   + 'At least 10 characters, with a letter and a number. Give it to them '
                   + 'privately and ask them to change it.</span>',
        input:       'password',
        placeholder: 'New password',
        confirmText: 'Reset password',
        attributes:  { autocomplete: 'new-password', autocapitalize: 'off', spellcheck: 'false' },
        validate: function (value) {
            var v = (value || '').trim();
            if (v.length < 10)      { return 'That is under 10 characters.'; }
            if (!/[A-Za-z]/.test(v)) { return 'It needs at least one letter.'; }
            if (!/[0-9]/.test(v))    { return 'It needs at least one number.'; }
            return null;
        },
        onConfirm: function (value) { send(value.trim()); }
    });
}
</script>
HTML;

require __DIR__ . '/../../app/views/partials/pager.php';
require __DIR__ . '/../_partials/foot.php';
