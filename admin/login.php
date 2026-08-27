<?php
declare(strict_types=1);

/**
 * TourSync — administrative sign-in.
 *
 * The only unauthenticated page under /admin.
 *
 * THERE IS NO "FORGOT PASSWORD" LINK, and its absence is deliberate. This
 * system has no self-service reset: there is no email sender configured, and an
 * SMS reset would be a new authentication path into an account that can change
 * the municipality's official tourism figures — not something to bolt on beside
 * a login form. What the page says instead is the thing that actually works:
 * the Tourism Officer can reset a colleague's password from Settings. A link
 * that goes nowhere is worse than a sentence that tells you who to ask.
 *
 * The logic below is unchanged from the plain version this replaced — same CSRF
 * check, same validator, same lockout messages from Auth::attempt(), same
 * return-to-intended-page behaviour. Only the markup is new.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\Validator;

// Already signed in? Nothing to do here.
if (Auth::check()) {
    redirect(base_url('/admin/dashboard.php'));
}

$errors = [];

if (is_post()) {
    Csrf::verify();

    $v = new Validator($_POST);
    $v->require('username', 'password');

    if ($v->passes()) {
        $failure = Auth::attempt((string) $v->value('username'), (string) $_POST['password']);

        if ($failure === null) {
            // Return the officer to whatever they were trying to reach.
            $intended = Session::get('_intended', base_url('/admin/dashboard.php'));
            Session::forget('_intended');
            Session::flash('success', 'Welcome back, ' . Auth::user()['full_name'] . '.');
            redirect(is_string($intended) && $intended !== '' ? $intended : base_url('/admin/dashboard.php'));
        }

        $errors['form'] = $failure;
    } else {
        $errors = $v->errors();
    }
}

$flashes = Session::takeFlash();
$expired = Session::get('_expired', false);
Session::forget('_expired');
?>
<!DOCTYPE html>
<html lang="en" class="auth-html">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#123A1B">
<title>Sign In — Tampakan Tourism Office</title>
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
            <span class="auth-hero__sub">Tourism Office</span>

            <div class="auth-hero__rule"></div>

            <p class="auth-hero__lede">
                Discover the natural beauty, rich culture, and warm hospitality of Tampakan.
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

                <p class="auth-card__office">Tampakan Tourism Office</p>
                <h2 class="auth-card__welcome">Welcome Back!</h2>

                <div class="auth-card__divider" aria-hidden="true"><i></i></div>

                <p class="auth-card__lede">
                    Sign in to continue to the <strong>Tourism Management System</strong>.
                </p>
            </div>

            <?php if ($expired): ?>
                <div class="auth-alert auth-alert--warning" role="alert">
                    <i class="fa-solid fa-clock"></i>
                    <span>Your session timed out. Please sign in again.</span>
                </div>
            <?php endif; ?>

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
                    <label for="username">Username or email</label>
                    <div class="auth-input">
                        <i class="fa-regular fa-user auth-input__icon" aria-hidden="true"></i>
                        <input type="text" id="username" name="username" required autofocus
                               autocomplete="username" placeholder="Enter your username or email"
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
                    Authorised personnel only. Every sign-in is recorded in the activity log.
                    Forgotten your password? The Tourism Officer can reset it from Settings.
                </span>
            </p>

            <?php
            /* The two accounts live in separate tables with separate sessions, so
               this page cannot accept a destination manager and must not pretend
               to. Without this, a manager types a correct password here, is told
               it is wrong, and has no way of learning why — the manager door is
               otherwise reachable only by typing the URL from memory. */
            ?>
            <p class="auth-switch">
                Managing a tourist destination?
                <a href="<?= e(base_url('/manager/login.php')) ?>">Sign in to the Destination Manager portal</a>
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

    /* Keep the caret where it was. Retyping a long password because the reveal
       button sent the cursor to the start is a small thing that happens every
       time. */
    field.focus();
});
</script>
</body>
</html>
