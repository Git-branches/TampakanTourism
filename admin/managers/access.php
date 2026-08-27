<?php
declare(strict_types=1);

/**
 * TourSync — issue or reset a manager's sign-in.                    Feature 2
 *
 * Officer only. Handing someone the ability to file arrival figures that become
 * the municipality's official statistics is not a staff-level action.
 *
 * The password is generated here rather than typed. Left to a human under time
 * pressure it becomes the destination name, and this account can write numbers
 * that end up in a report to the Mayor. It is shown exactly once, on the screen
 * of the officer who created it, and stored only as an Argon2id hash — there is
 * no way to display it again, which is why the page says so plainly.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\ManagerAuth;
use App\Core\Session;
use App\Core\Validator;
use App\Repositories\ManagerRepository;

Auth::require('officer');

$id = (int) ($_GET['id'] ?? 0);
$m  = ManagerRepository::find($id);

if ($m === null) {
    Session::flash('danger', 'That manager is no longer on record.');
    redirect(base_url('/admin/managers/index.php'));
}

$pageTitle    = 'Manager Access';
$pageIcon     = 'fa-key';
$pageSubtitle = $m['full_name'];

/** Shown once, immediately after generation, then gone. */
$issued = null;

if (is_post()) {
    Csrf::verify();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'revoke') {
        Database::run(
            'UPDATE destination_managers
                SET username = NULL, password_hash = NULL, password_changed_at = NULL,
                    failed_attempts = 0, locked_until = NULL
              WHERE id = ?',
            [$id]
        );

        ActivityLog::record('manager.access_revoked', 'manager', $id,
            'Sign-in revoked for ' . $m['full_name']);

        Session::flash('success', 'Sign-in revoked. This manager can no longer access the system.');
        redirect(base_url('/admin/managers/access.php?id=' . $id));
    }

    if ($action === 'unlock') {
        Database::run(
            'UPDATE destination_managers SET failed_attempts = 0, locked_until = NULL WHERE id = ?',
            [$id]
        );

        ActivityLog::record('manager.unlocked', 'manager', $id, 'Unlocked ' . $m['full_name']);
        Session::flash('success', 'Account unlocked.');
        redirect(base_url('/admin/managers/access.php?id=' . $id));
    }

    /* Issue or reset. */
    $v = new Validator($_POST);
    $v->require('username')->length('username', 3, 60);

    $username = strtolower(trim((string) $v->value('username', '')));

    if ($username !== '' && preg_match('/^[a-z0-9._-]+$/', $username) !== 1) {
        $v->addError('username', 'Use lowercase letters, numbers, dots, hyphens, or underscores only.');
    }

    $taken = Database::scalar(
        'SELECT COUNT(*) FROM destination_managers WHERE username = ? AND id <> ?',
        [$username, $id]
    );

    if ((int) $taken > 0) {
        $v->addError('username', 'That username is already in use by another manager.');
    }

    /* Admin usernames are checked too. The two tables are separate, but a
       manager and an officer sharing a username is a support call waiting to
       happen — somebody will type one into the other's login page. */
    if ((int) Database::scalar('SELECT COUNT(*) FROM admins WHERE username = ?', [$username]) > 0) {
        $v->addError('username', 'That username belongs to an administrator account.');
    }

    if ($v->fails()) {
        flash_back($v->errors(), $_POST, 'access.php?id=' . $id);
    }

    /* 12 characters from a 32-symbol alphabet — roughly 60 bits. The alphabet
       omits the pairs that get misread off a screen and mistyped on a phone:
       0/O, 1/l/I. */
    $alphabet = '23456789abcdefghjkmnpqrstuvwxyz';
    $password = '';
    for ($i = 0; $i < 12; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    Database::run(
        'UPDATE destination_managers
            SET username = ?, password_hash = ?, password_changed_at = NOW(),
                failed_attempts = 0, locked_until = NULL
          WHERE id = ?',
        [$username, ManagerAuth::hash($password), $id]
    );

    ActivityLog::record(
        'manager.access_issued', 'manager', $id,
        ($m['username'] ? 'Reset' : 'Issued') . ' sign-in for ' . $m['full_name'] . ' (' . $username . ')'
    );

    /* Not flashed through a redirect: a one-time password does not belong in
       the session store, where it would sit on disk until the session expires. */
    $issued = ['username' => $username, 'password' => $password];
    $m      = ManagerRepository::find($id);
}

$errors = all_errors();
$locked = $m['locked_until'] !== null && strtotime((string) $m['locked_until']) > time();

require __DIR__ . '/../_partials/head.php';
?>

<?php if ($issued !== null): ?>
    <!-- The one moment this password exists in readable form. -->
    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-solid fa-circle-check"></i> Sign-in issued</h2>
        </header>
        <div class="panel__body">
            <div class="alert alert-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <strong>Write this down now.</strong>
                The password is stored only as a hash and cannot be shown again. If it is lost,
                come back here and reset it &mdash; there is no way to recover the old one.
            </div>

            <dl class="detail-grid">
                <div>
                    <dt>Sign-in address</dt>
                    <dd><code><?= e(base_url('/manager/login.php')) ?></code></dd>
                </div>
                <div>
                    <dt>Username</dt>
                    <dd><code><?= e($issued['username']) ?></code></dd>
                </div>
                <div>
                    <dt>Password</dt>
                    <dd><code style="font-size:1.1rem; letter-spacing:.08em;"><?= e($issued['password']) ?></code></dd>
                </div>
            </dl>
        </div>
    </section>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <i class="fa-solid fa-circle-exclamation"></i>
        <?php foreach ($errors as $msg): ?><div class="small"><?= e($msg) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-key"></i> <?= $m['username'] ? 'Reset sign-in' : 'Issue a sign-in' ?></h2>
    </header>
    <div class="panel__body">

        <dl class="detail-grid mb-3">
            <div><dt>Manager</dt><dd><?= e($m['full_name']) ?></dd></div>
            <div><dt>Destination</dt><dd><?= e((string) ($m['destination_name'] ?? '—')) ?></dd></div>
            <div>
                <dt>Current access</dt>
                <dd>
                    <?php if ($m['username']): ?>
                        <span class="pill pill--ok">Active</span> <code><?= e((string) $m['username']) ?></code>
                    <?php else: ?>
                        <span class="pill pill--void">No sign-in issued</span>
                    <?php endif; ?>
                </dd>
            </div>
            <div>
                <dt>Last signed in</dt>
                <dd><?= $m['last_login_at'] ? e(format_date($m['last_login_at'], 'M j, Y g:i A')) : 'Never' ?></dd>
            </div>
        </dl>

        <?php if ($locked): ?>
            <div class="alert alert-warning">
                <i class="fa-solid fa-lock"></i>
                This account is locked after too many failed attempts until
                <?= e(format_date((string) $m['locked_until'], 'g:i A')) ?>.
                <form method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="unlock">
                    <button type="submit" class="btn btn-sm btn-outline-secondary ms-2">Unlock now</button>
                </form>
            </div>
        <?php endif; ?>

        <form method="post" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="issue">

            <label for="username" class="form-label">Username</label>
            <input type="text" id="username" name="username" required maxlength="60"
                   class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>"
                   value="<?= e(old('username', (string) ($m['username'] ?? ''))) ?>"
                   placeholder="jadas.falls">
            <p class="text-muted small mt-2">
                Lowercase letters, numbers, dots, hyphens, and underscores. A password is generated
                automatically and shown once &mdash; it is never typed here and never stored in readable form.
            </p>

            <div class="mt-3 d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-brand btn-sm">
                    <i class="fa-solid fa-key"></i>
                    <?= $m['username'] ? 'Reset password' : 'Issue sign-in' ?>
                </button>
                <a href="<?= e(base_url('/admin/managers/index.php')) ?>" class="btn btn-sm btn-outline-secondary">Back</a>
            </div>
        </form>

        <?php if ($m['username']): ?>
            <hr class="my-4">
            <form method="post" data-confirm="Revoke sign-in for &lt;?= e(addslashes($m['full_name'])) ?&gt;?

They will lose access immediately. Their submitted reports are kept.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="revoke">
                <button type="submit" class="btn btn-sm btn-outline-danger">
                    <i class="fa-solid fa-ban"></i> Revoke sign-in
                </button>
                <span class="text-muted small ms-2">
                    Removes access without deleting the manager or any report they filed.
                </span>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
