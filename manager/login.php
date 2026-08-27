<?php
declare(strict_types=1);

/**
 * TourSync — destination manager sign-in.                          Feature 2
 *
 * The door that removes the trip to the Municipal Tourism Office. A manager
 * signs in here from the destination — often on a phone, often on one bar of
 * signal — and files the arrival report from where the paper logbook sits.
 *
 * Shares the sign-in stylesheet with /admin/login.php on purpose. The two pages
 * link to each other, and a manager who follows the link from the Office door
 * must not feel they have left the system. What differs is the wording, because
 * what happens behind the two doors differs: accounts here are issued by the
 * Office, and there is no registration and no self-service reset.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Csrf;
use App\Core\ManagerAuth;
use App\Core\Session;
use App\Core\Validator;

if (ManagerAuth::check()) {
    redirect(base_url('/manager/index.php'));
}

$errors = [];

if (is_post()) {
    Csrf::verify();

    $v = new Validator($_POST);
    $v->require('username', 'password');

    if ($v->passes()) {
        $failure = ManagerAuth::attempt((string) $v->value('username'), (string) $_POST['password']);

        if ($failure === null) {
            /* Back to whatever they were reaching for — a manager who followed
               a link to a specific report should land on that report. */
            $intended = Session::get('_manager_intended', '');
            Session::forget('_manager_intended');

            Session::flash('success', 'Welcome, ' . ManagerAuth::name() . '.');
            redirect(is_string($intended) && $intended !== '' ? $intended : base_url('/manager/index.php'));
        }

        $errors['form'] = $failure;
    } else {
        $errors = $v->errors();
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
<title>Destination Manager Sign In — Tampakan Tourism Office</title>
<link rel="icon" href="<?= e(asset('img/tampakan_logo.png')) ?>" sizes="any">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Dancing+Script:wght@600&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body class="auth-body">

<div class="auth-grid">

    <!-- ===================== TAMPAKAN ===================== -->
    <section class="auth-hero">
        <div class="auth-hero__inner">
            <span class="auth-hero__script">Welcome to</span>
            <h1 class="auth-hero__title">TAMPAKAN</h1>
            <span class="auth-hero__sub">Destination Manager</span>

            <div class="auth-hero__rule"></div>

            <p class="auth-hero__lede">
                File your arrival reports, compliance photos and site alerts from the destination
                itself &mdash; no trip to the Municipal Tourism Office.
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

    <!-- ===================== SIGN IN ===================== -->
    <main class="auth-panel">
        <div class="auth-card">

            <div class="auth-card__brand">
                <img class="auth-card__seal" src="<?= e(asset('img/tampakan_logo.png')) ?>"
                     alt="Official Seal of the Municipality of Tampakan" width="92" height="92">

                <p class="auth-card__office">Destination Manager Portal</p>
                <h2 class="auth-card__welcome">Welcome Back!</h2>

                <div class="auth-card__divider" aria-hidden="true"><i></i></div>

                <p class="auth-card__lede">
                    Sign in to submit reports for <strong>your destination</strong>.
                </p>
            </div>

            <?php foreach ($flashes as $flash): ?>
                <div class="auth-alert auth-alert--<?= e($flash['type']) ?>" role="alert">
                    <i class="fa-solid fa-circle-info"></i>
                    <span><?= e($flash['message']) ?></span>
                </div>
            <?php endforeach; ?>

            <?php if (isset($errors['form'])): ?>
                <div class="auth-alert auth-alert--danger" role="alert">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?= e($errors['form']) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" novalidate>
                <?= csrf_field() ?>

                <div class="auth-field">
                    <label for="username">Username</label>
                    <div class="auth-input">
                        <i class="fa-regular fa-user auth-input__icon" aria-hidden="true"></i>
                        <input type="text" id="username" name="username" required autofocus
                               autocomplete="username" placeholder="Enter your username"
                               class="<?= isset($errors['username']) ? 'is-invalid' : '' ?>"
                               value="<?= e((string) ($_POST['username'] ?? '')) ?>">
                    </div>
                    <?php if (isset($errors['username'])): ?>
                        <p class="auth-error"><?= e($errors['username']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="auth-field">
                    <label for="password">Password</label>
                    <div class="auth-input">
                        <i class="fa-solid fa-lock auth-input__icon" aria-hidden="true"></i>
                        <input type="password" id="password" name="password" required
                               autocomplete="current-password" placeholder="Enter your password"
                               class="<?= isset($errors['password']) ? 'is-invalid' : '' ?>">
                        <button type="button" class="auth-input__reveal" id="revealPassword"
                                aria-label="Show password">
                            <i class="fa-regular fa-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    <?php if (isset($errors['password'])): ?>
                        <p class="auth-error"><?= e($errors['password']) ?></p>
                    <?php endif; ?>
                </div>

                <button type="submit" class="auth-submit">
                    <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i> Sign In
                </button>
            </form>

            <p class="auth-notice">
                <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                <span>
                    Your account is issued by the Municipal Tourism Office and covers one destination.
                    To have it created, reset or unlocked, contact the Office.
                </span>
            </p>

            <p class="auth-switch">
                Municipal Tourism Office staff?
                <a href="<?= e(base_url('/admin/login.php')) ?>">Sign in to the Office dashboard</a>
            </p>

            <a href="<?= e(base_url('/')) ?>" class="auth-back">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to the public site
            </a>
        </div>
    </main>
</div>

<script>
document.getElementById('revealPassword').addEventListener('click', function () {
    var field = document.getElementById('password');
    var shown = field.type === 'text';

    field.type = shown ? 'password' : 'text';
    this.innerHTML = shown
        ? '<i class="fa-regular fa-eye" aria-hidden="true"></i>'
        : '<i class="fa-regular fa-eye-slash" aria-hidden="true"></i>';
    this.setAttribute('aria-label', shown ? 'Show password' : 'Hide password');

    field.focus();
});
</script>
</body>
</html>
