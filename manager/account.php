<?php
declare(strict_types=1);

/**
 * TourSync — the destination manager's own account.
 *
 * Two things live here, and the first is the one that matters operationally:
 * the mobile number the Municipal Tourism Office texts when they answer a
 * report. A manager who files a closure and hears nothing assumes it did not
 * arrive, and drives to town to ask — which is the trip this whole system
 * exists to remove.
 *
 * A manager cannot change their own name or which destination they cover. Both
 * are the Office's to set: a manager who could reassign themselves could reach
 * another destination's figures, and the name on a submitted report has to stay
 * the name that submitted it.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\ManagerAuth;
use App\Core\Session;
use App\Core\SmsGateway;

ManagerAuth::require();

$id = (int) ManagerAuth::id();
$me = Database::first('SELECT * FROM destination_managers WHERE id = ?', [$id]);

$errors = [];

if (is_post()) {
    Csrf::verify();

    $action = (string) ($_POST['action'] ?? '');

    // ---- the number the Office reaches them on ------------------------------
    if ($action === 'contact') {
        $raw    = trim((string) ($_POST['mobile_number'] ?? ''));
        $mobile = null;

        if ($raw !== '') {
            $mobile = SmsGateway::normalise($raw);

            if ($mobile === null) {
                $errors['mobile_number'] = 'That does not look like a Philippine mobile number. '
                    . 'Use 09XXXXXXXXX or +639XXXXXXXXX.';
            }
        }

        if ($errors === []) {
            /* Stored normalised, because the inbound webhook matches a text's
               sender against this column. A number saved as "0917 123 4567"
               and a text arriving from "+639171234567" are the same phone, and
               only one of those spellings matches. */
            Database::run(
                'UPDATE destination_managers
                    SET mobile_number = ?, sms_opt_in = ?, reply_sms_opt_in = ?
                  WHERE id = ?',
                [
                    $mobile,
                    !empty($_POST['sms_opt_in']) ? 1 : 0,
                    !empty($_POST['reply_sms_opt_in']) ? 1 : 0,
                    $id,
                ]
            );

            ActivityLog::record('manager.contact_updated', 'manager', $id,
                'Updated own contact details for ' . ManagerAuth::destinationName());

            Session::flash('success', 'Saved. The Office will reach you on this number.');
            redirect(base_url('/manager/account.php'));
        }
    }

    // ---- password ------------------------------------------------------------
    if ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if (!password_verify($current, (string) $me['password_hash'])) {
            $errors['current_password'] = 'That is not your current password.';
        }

        if (mb_strlen($new) < 10) {
            $errors['new_password'] = 'Use at least 10 characters.';
        } elseif (!preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
            $errors['new_password'] = 'Use at least one letter and one number.';
        }

        if ($new !== $confirm) {
            $errors['confirm_password'] = 'The two passwords do not match.';
        }

        if ($errors === []) {
            Database::run(
                'UPDATE destination_managers
                    SET password_hash = ?, password_changed_at = NOW(),
                        failed_attempts = 0, locked_until = NULL
                  WHERE id = ?',
                [ManagerAuth::hash($new), $id]
            );

            ActivityLog::record('manager.password_changed', 'manager', $id, 'Changed own password');

            Session::flash('success', 'Password changed.');
            redirect(base_url('/manager/account.php'));
        }
    }

    $me = Database::first('SELECT * FROM destination_managers WHERE id = ?', [$id]);
}

$smsLive   = SmsGateway::isLive();
$neverSet  = $me['password_changed_at'] === null;

$pageTitle    = 'My Account';
$pageIcon     = 'fa-user-gear';
$pageSubtitle = ManagerAuth::destinationName();

require __DIR__ . '/_partials/head.php';
?>

<?php if ($neverSet): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-key"></i>
        <strong>You are still using the password the Office gave you.</strong>
        It was read out or written down when your account was created. Change it below.
    </div>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <i class="fa-solid fa-circle-exclamation"></i>
        <?php foreach ($errors as $message): ?><div class="small"><?= e($message) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ===================== CONTACT ===================== -->
<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-mobile-screen"></i> How the Office reaches you</h2>
    </header>

    <div class="panel__body">
        <form method="post" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="contact">

            <div class="col-md-6">
                <label for="mobile_number" class="form-label">Mobile number</label>
                <input type="tel" id="mobile_number" name="mobile_number" maxlength="20"
                       class="form-control <?= isset($errors['mobile_number']) ? 'is-invalid' : '' ?>"
                       inputmode="tel" placeholder="09XX XXX XXXX"
                       value="<?= e((string) ($_POST['mobile_number'] ?? $me['mobile_number'] ?? '')) ?>">
                <p class="text-muted small mt-1 mb-0">
                    Used two ways: the Office texts their answer here, and a text you send them from
                    this number is recognised as coming from
                    <strong><?= e(ManagerAuth::destinationName()) ?></strong>.
                </p>
            </div>

            <div class="col-md-6">
                <div class="form-check mt-md-4">
                    <input class="form-check-input" type="checkbox" value="1"
                           id="reply_sms_opt_in" name="reply_sms_opt_in"
                           <?= (int) ($me['reply_sms_opt_in'] ?? 1) === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label" for="reply_sms_opt_in">
                        Text me when the Office answers my report
                        <span class="d-block text-muted small">
                            The answer is always in the portal. This is about whether it also reaches
                            your phone.
                        </span>
                    </label>
                </div>

                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" value="1"
                           id="sms_opt_in" name="sms_opt_in"
                           <?= (int) ($me['sms_opt_in'] ?? 1) === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label" for="sms_opt_in">
                        Text me municipal announcements
                        <span class="d-block text-muted small">
                            Advisories the Office sends to every destination.
                        </span>
                    </label>
                </div>
            </div>

            <?php if (!$smsLive): ?>
                <div class="col-12">
                    <p class="text-muted small mb-0">
                        <em>SMS is in test mode on this system &mdash; messages are written to a log
                        rather than sent to a handset.</em>
                    </p>
                </div>
            <?php endif; ?>

            <div class="col-12">
                <button type="submit" class="btn btn-brand btn-sm">
                    <i class="fa-solid fa-floppy-disk"></i> Save
                </button>
            </div>
        </form>
    </div>
</section>

<!-- ===================== PASSWORD ===================== -->
<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-lock"></i> Password</h2>
    </header>

    <div class="panel__body">
        <form method="post" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="password">

            <div class="col-md-4">
                <label for="current_password" class="form-label">Current password</label>
                <input type="password" id="current_password" name="current_password" required
                       autocomplete="current-password"
                       class="form-control <?= isset($errors['current_password']) ? 'is-invalid' : '' ?>">
            </div>

            <div class="col-md-4">
                <label for="new_password" class="form-label">New password</label>
                <input type="password" id="new_password" name="new_password" required
                       autocomplete="new-password"
                       class="form-control <?= isset($errors['new_password']) ? 'is-invalid' : '' ?>">
                <p class="text-muted small mt-1 mb-0">At least 10 characters, with a letter and a number.</p>
            </div>

            <div class="col-md-4">
                <label for="confirm_password" class="form-label">Repeat new password</label>
                <input type="password" id="confirm_password" name="confirm_password" required
                       autocomplete="new-password"
                       class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>">
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-outline-secondary btn-sm">
                    <i class="fa-solid fa-key"></i> Change password
                </button>
            </div>
        </form>
    </div>
</section>

<!-- ===================== WHAT THE OFFICE SETS ===================== -->
<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-id-card"></i> Set by the Municipal Tourism Office</h2>
    </header>

    <div class="panel__body">
        <dl class="detail-grid">
            <div><dt>Name</dt><dd><?= e((string) $me['full_name']) ?></dd></div>
            <div><dt>Username</dt><dd><?= e((string) $me['username']) ?></dd></div>
            <div><dt>Destination</dt><dd><?= e(ManagerAuth::destinationName()) ?></dd></div>
            <div><dt>Position</dt><dd><?= e((string) ($me['position'] ?: '—')) ?></dd></div>
        </dl>

        <p class="text-muted small mt-3 mb-0">
            These are the Office's to change. Your name appears on every report you submit, and the
            destination decides which figures you can reach &mdash; neither is yours to edit from
            here. Contact the Office if something is wrong.
        </p>
    </div>
</section>

<?php require __DIR__ . '/_partials/foot.php'; ?>
