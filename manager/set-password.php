<?php
declare(strict_types=1);

/**
 * TourSync — the manager's first sign-in: replace the temporary password.
 *
 * The office generates a manager's first password and hands it over on paper or
 * by phone, so at least two people know it. Until the manager chooses their own,
 * ManagerAuth::require() sends every manager page here — the dashboard, the
 * logbook and the reports are not reachable on a password somebody else holds.
 *
 * The current password is not asked for again. The manager typed it seconds ago
 * to get here, and this page can do exactly one thing, so asking twice protects
 * nothing and costs a phone user another fumble with a 12-character code.
 *
 * Laid out as the sign-in card rather than inside the portal: it is the second
 * half of signing in, and the portal's sidebar would be a row of links that all
 * lead back to this page.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\ManagerAuth;
use App\Core\Password;
use App\Core\Session;

ManagerAuth::require(passwordPage: true);

/* Reached on purpose after the change is made, or by a bookmark: the ordinary
   change lives on My Account, with the current password asked for. */
if (!ManagerAuth::mustChangePassword()) {
    redirect(base_url('/manager/account.php'));
}

$id     = (int) ManagerAuth::id();
$errors = [];

if (is_post()) {
    Csrf::verify();

    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    $hash    = (string) Database::scalar('SELECT password_hash FROM destination_managers WHERE id = ?', [$id]);

    $problems = Password::problems($new);

    if ($problems !== []) {
        $errors['new_password'] = 'The new password ' . implode(', and ', $problems) . '.';
    } elseif ($hash !== '' && password_verify($new, $hash)) {
        $errors['new_password'] = 'Choose a password different from the temporary one.';
    }

    if ($new !== $confirm) {
        $errors['confirm_password'] = 'The two passwords do not match.';
    }

    if ($errors === []) {
        Database::run(
            'UPDATE destination_managers
                SET password_hash = ?, must_change_password = 0, password_changed_at = NOW(),
                    failed_attempts = 0, locked_until = NULL
              WHERE id = ?',
            [ManagerAuth::hash($new), $id]
        );

        /* This session continues on the new password; the temporary one — and
           any other session opened with it — is finished. */
        ManagerAuth::refreshCredentials();

        ActivityLog::record('manager.password_set', 'manager', $id,
            'Replaced the temporary password at first sign-in');

        Session::flash('success', 'Password changed successfully. Welcome to the Destination Manager portal.');
        redirect(base_url('/manager/index.php'));
    }
}

$flashes = Session::takeFlash();
?>
<!DOCTYPE html>
<html lang="en" class="auth-html">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#123A1B">
<title>Set Your New Password — Tampakan Tourism Office</title>
<link rel="icon" href="<?= e(asset('img/tourism-logo-mark.png')) ?>" type="image/png">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Dancing+Script:wght@600&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body class="auth-body">

<div class="auth-grid">

    <section class="auth-hero">
        <div class="auth-hero__inner">
            <span class="auth-hero__script">Welcome to</span>
            <h1 class="auth-hero__title">TAMPAKAN</h1>
            <span class="auth-hero__sub">Destination Manager</span>

            <div class="auth-hero__rule"></div>

            <p class="auth-hero__lede">
                One step before your portal opens: a password only you know.
            </p>
        </div>

        <div class="auth-hero__foot">
            <i class="fa-solid fa-location-dot auth-hero__pin" aria-hidden="true"></i>
            <span>
                <span class="auth-hero__place">Tampakan, South Cotabato</span>
                <span class="auth-hero__tag">Nature &middot; Culture &middot; Community</span>
            </span>
        </div>
    </section>

    <main class="auth-panel">
        <div class="auth-card">

            <div class="auth-card__brand">
                <div class="auth-card__seals">
                    <img class="auth-card__seal" src="<?= e(asset('img/tampakan_logo.png')) ?>"
                         alt="Official Seal of the Municipality of Tampakan" width="92" height="92">
                    <img class="auth-card__seal" src="<?= e(asset('img/tourism-logo-mark.png')) ?>"
                         alt="Logo of the Tampakan Municipal Tourism Office" width="92" height="92">
                </div>

                <p class="auth-card__office"><?= e(ManagerAuth::destinationName()) ?></p>
                <h2 class="auth-card__welcome">Set Your New Password</h2>

                <div class="auth-card__divider" aria-hidden="true"><i></i></div>

                <p class="auth-card__lede">
                    Your temporary password must be replaced before continuing.
                </p>
            </div>

            <?php foreach ($flashes as $flash): ?>
                <?php /* "Welcome, …" from the sign-in is not news on this card — it
                         is the card they were just welcomed onto. */ ?>
                <?php if ($flash['type'] === 'success') { continue; } ?>
                <div class="auth-alert auth-alert--<?= e($flash['type']) ?>" role="alert">
                    <i class="fa-solid fa-circle-info"></i>
                    <span><?= e($flash['message']) ?></span>
                </div>
            <?php endforeach; ?>

            <?php if ($errors !== []): ?>
                <div class="auth-alert auth-alert--danger" role="alert">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span>The password was not changed. See below.</span>
                </div>
            <?php endif; ?>

            <form method="post" novalidate id="setPassword">
                <?= csrf_field() ?>

                <?php /* The username, for password managers: without it a saved
                         password is filed against nothing and offered nowhere. */ ?>
                <input type="text" name="username" value="<?= e((string) (ManagerAuth::user()['username'] ?? '')) ?>"
                       autocomplete="username" hidden aria-hidden="true" tabindex="-1" readonly>

                <div class="auth-field">
                    <label for="new_password">New Password</label>
                    <div class="auth-input">
                        <i class="fa-solid fa-lock auth-input__icon" aria-hidden="true"></i>
                        <input type="password" id="new_password" name="new_password" required autofocus
                               autocomplete="new-password" placeholder="At least 10 characters"
                               aria-describedby="newPasswordRule"
                               class="<?= isset($errors['new_password']) ? 'is-invalid' : '' ?>">
                        <button type="button" class="auth-input__reveal" data-reveal="new_password"
                                aria-label="Show password">
                            <i class="fa-regular fa-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    <?php if (isset($errors['new_password'])): ?>
                        <p class="auth-error"><?= e($errors['new_password']) ?></p>
                    <?php else: ?>
                        <p class="auth-hint" id="newPasswordRule">
                            At least 10 characters, with a letter and a number.
                        </p>
                    <?php endif; ?>
                </div>

                <div class="auth-field">
                    <label for="confirm_password">Confirm New Password</label>
                    <div class="auth-input">
                        <i class="fa-solid fa-lock auth-input__icon" aria-hidden="true"></i>
                        <input type="password" id="confirm_password" name="confirm_password" required
                               autocomplete="new-password" placeholder="Type it again"
                               class="<?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>">
                        <button type="button" class="auth-input__reveal" data-reveal="confirm_password"
                                aria-label="Show password">
                            <i class="fa-regular fa-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    <?php if (isset($errors['confirm_password'])): ?>
                        <p class="auth-error"><?= e($errors['confirm_password']) ?></p>
                    <?php endif; ?>
                    <p class="auth-error" id="confirmMismatch" hidden>The two passwords do not match.</p>
                </div>

                <button type="submit" class="auth-submit">
                    <i class="fa-solid fa-key" aria-hidden="true"></i> Change Password
                </button>
            </form>

            <p class="auth-notice">
                <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                <span>
                    The Municipal Tourism Office cannot see or recover this password. If you forget it,
                    ask the Office to issue a new temporary one.
                </span>
            </p>

            <a href="<?= e(base_url('/manager/logout.php')) ?>" class="auth-back">
                <i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i> Sign out instead
            </a>
        </div>
    </main>
</div>

<script>
(function () {
    document.querySelectorAll('[data-reveal]').forEach(function (button) {
        button.addEventListener('click', function () {
            var field = document.getElementById(button.getAttribute('data-reveal'));
            var shown = field.type === 'text';

            field.type = shown ? 'password' : 'text';
            button.innerHTML = shown
                ? '<i class="fa-regular fa-eye" aria-hidden="true"></i>'
                : '<i class="fa-regular fa-eye-slash" aria-hidden="true"></i>';
            button.setAttribute('aria-label', shown ? 'Show password' : 'Hide password');
            field.focus();
        });
    });

    /* The mismatch is said before the round trip; the rules themselves are the
       server's to judge, so only the one thing the browser can know for certain
       is checked here. */
    var form    = document.getElementById('setPassword');
    var first   = document.getElementById('new_password');
    var second  = document.getElementById('confirm_password');
    var warning = document.getElementById('confirmMismatch');

    form.addEventListener('submit', function (event) {
        if (second.value !== '' && first.value !== second.value) {
            event.preventDefault();
            warning.hidden = false;
            second.classList.add('is-invalid');
            second.focus();
        }
    });

    second.addEventListener('input', function () {
        warning.hidden = true;
        second.classList.remove('is-invalid');
    });
})();
</script>
</body>
</html>
