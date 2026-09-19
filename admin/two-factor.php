<?php
declare(strict_types=1);

/**
 * TourSync — the second step of an officer's sign-in.
 *
 * Reached only from admin/login.php, and only when the password was right and
 * the account has two-step verification switched on. Nothing is signed in while
 * this page is open: Auth writes no admin key until a code is accepted, so every
 * guarded page still refuses a session sitting here.
 *
 * Deliberately plain. It asks for one thing, it says what to do when the phone
 * is not to hand, and it offers a way back out.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Core\TwoFactor;

/* Already through? Nothing to do here. */
if (Auth::check()) {
    redirect(base_url('/admin/dashboard.php'));
}

/* No half-finished sign-in — somebody typed the address, or it expired. */
if (!Auth::awaitingTwoFactor()) {
    redirect(base_url('/admin/login.php'));
}

$errors = [];

if (is_post()) {
    Csrf::verify();

    /* The same per-device ceiling the password form has. The code is six digits;
       without a limit here the second step would be the weaker of the two. */
    $throttle = 'twofactor:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    if (!RateLimiter::allow($throttle, 20, 900)) {
        $wait = max(1, (int) ceil(RateLimiter::retryAfter($throttle, 900) / 60));
        ActivityLog::record('auth.2fa.throttled', 'admin', null,
            'Two-step prompt throttled for ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

        $errors['form'] = "Too many attempts from this device. Try again in {$wait} minute(s).";
    } elseif (($_POST['action'] ?? '') === 'cancel') {
        Auth::abandonTwoFactor();
        Session::flash('info', 'Signed out of that attempt.');
        redirect(base_url('/admin/login.php'));
    } else {
        $failure = Auth::completeTwoFactor((string) ($_POST['code'] ?? ''));

        if ($failure === null) {
            RateLimiter::forget($throttle);

            $intended = Session::get('_intended', base_url('/admin/dashboard.php'));
            Session::forget('_intended');
            Session::flash('success', 'Welcome back, ' . Auth::user()['full_name'] . '.');
            redirect(is_string($intended) && $intended !== '' ? $intended : base_url('/admin/dashboard.php'));
        }

        /* completeTwoFactor() clears the pending state after too many wrong
           codes, which sends the officer back to the password form. */
        if (!Auth::awaitingTwoFactor()) {
            Session::flash('danger', $failure);
            redirect(base_url('/admin/login.php'));
        }

        $errors['form'] = $failure;
    }
}

$name    = Auth::pendingName();
$flashes = Session::takeFlash();
?>
<!DOCTYPE html>
<html lang="en" class="auth-html">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#123A1B">
<title>Two-Step Verification — Tampakan Tourism Office</title>
<link rel="icon" href="<?= e(asset('img/tampakan_logo.png')) ?>" sizes="any">
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
            <span class="auth-hero__sub">Tourism Office</span>

            <div class="auth-hero__rule"></div>

            <p class="auth-hero__lede">
                One more step. The code changes every thirty seconds, so nobody who
                learns your password can sign in without your phone.
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
                <img class="auth-card__seal" src="<?= e(asset('img/tampakan_logo.png')) ?>"
                     alt="Official Seal of the Municipality of Tampakan" width="92" height="92">

                <p class="auth-card__office">Tampakan Tourism Office</p>
                <h2 class="auth-card__welcome">Two-Step Verification</h2>

                <div class="auth-card__divider" aria-hidden="true"><i></i></div>

                <p class="auth-card__lede">
                    <?php if ($name !== ''): ?>
                        Hello <strong><?= e($name) ?></strong>. Open your authenticator app and
                    <?php else: ?>
                        Open your authenticator app and
                    <?php endif; ?>
                    enter the <strong>six-digit code</strong> for TourSync.
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

            <form method="post" novalidate autocomplete="off">
                <?= csrf_field() ?>

                <div class="auth-field">
                    <label for="code">Six-digit code</label>
                    <div class="auth-input">
                        <i class="fa-solid fa-mobile-screen auth-input__icon" aria-hidden="true"></i>
                        <?php /* inputmode numeric brings up the number pad on a phone;
                                 autocomplete one-time-code lets iOS and Android offer the
                                 code straight from the notification. */ ?>
                        <input type="text" id="code" name="code" required autofocus
                               inputmode="numeric" autocomplete="one-time-code"
                               maxlength="14" placeholder="000000"
                               class="<?= isset($errors['form']) ? 'is-invalid' : '' ?>">
                    </div>
                    <p class="auth-hint">
                        Lost the phone? Type one of the recovery codes the office gave you
                        when this was switched on. Each one works once.
                    </p>
                </div>

                <button type="submit" class="auth-submit">
                    <i class="fa-solid fa-shield-halved"></i> Verify and sign in
                </button>

                <?php /* formnovalidate, or the empty required code box stops the one
                         control an officer needs when they cannot produce a code. */ ?>
                <button type="submit" name="action" value="cancel" formnovalidate class="auth-secondary">
                    Cancel and sign in as someone else
                </button>
            </form>

            <p class="auth-foot">
                No app and no recovery code? Ask the Tourism Officer to switch two-step
                verification off for your account, then set it up again.
            </p>
        </div>
    </main>
</div>

</body>
</html>
