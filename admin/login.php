<?php
declare(strict_types=1);

/**
 * TourSync — administrative sign-in.
 *
 * The only unauthenticated page under /admin. There is no registration link
 * and no password-reset-by-email: accounts are created by the installer or by
 * an officer inside the system.
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
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Sign In — TourSync Admin</title>
<link rel="icon" href="<?= e(asset('img/tampakan_logo.png')) ?>" sizes="any">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body class="login-body">

<main class="login-shell">
    <div class="login-card">

        <div class="login-card__brand">
            <img src="<?= e(asset('img/tampakan_logo.png')) ?>"
                 alt="Official Seal of the Municipality of Tampakan" width="76" height="76">
            <h1>TourSync</h1>
            <p>Municipal Tourism Office &middot; Tampakan, South Cotabato</p>
        </div>

        <?php if ($expired): ?>
            <div class="alert alert-warning" role="alert">
                <i class="fa-solid fa-clock"></i> Your session timed out. Please sign in again.
            </div>
        <?php endif; ?>

        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>

        <?php if (isset($errors['form'])): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fa-solid fa-circle-exclamation"></i> <?= e($errors['form']) ?>
            </div>
        <?php endif; ?>

        <form method="post" novalidate>
            <?= csrf_field() ?>

            <div class="mb-3">
                <label for="username" class="form-label">Username or email</label>
                <div class="input-with-icon">
                    <i class="fa-solid fa-user"></i>
                    <input type="text" class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>"
                           id="username" name="username" required autofocus autocomplete="username"
                           value="<?= e((string) ($_POST['username'] ?? '')) ?>">
                </div>
                <?php if (isset($errors['username'])): ?>
                    <div class="field-error"><?= e($errors['username']) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <div class="input-with-icon">
                    <i class="fa-solid fa-lock"></i>
                    <input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                           id="password" name="password" required autocomplete="current-password">
                    <button type="button" class="reveal" id="revealPassword"
                            aria-label="Show password"><i class="fa-regular fa-eye"></i></button>
                </div>
                <?php if (isset($errors['password'])): ?>
                    <div class="field-error"><?= e($errors['password']) ?></div>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-brand w-100">
                <i class="fa-solid fa-right-to-bracket"></i> Sign In
            </button>
        </form>

        <p class="login-card__note">
            <i class="fa-solid fa-shield-halved"></i>
            Authorised personnel only. Every sign-in is recorded in the activity log.
        </p>

        <!-- The two accounts live in separate tables with separate sessions, so
             this page cannot accept a destination manager and must not pretend
             to. Without this line a manager types their password in here, is
             told it is wrong, and has no way of learning why — the manager door
             is otherwise reachable only by typing the URL from memory. -->
        <p class="login-card__switch">
            Managing a tourist destination?
            <a href="<?= e(base_url('/manager/login.php')) ?>">Sign in to the Destination Manager portal</a>
        </p>

        <a href="<?= e(base_url('/')) ?>" class="login-card__back">
            <i class="fa-solid fa-arrow-left"></i> Back to the public site
        </a>
    </div>
</main>

<script>
document.getElementById('revealPassword').addEventListener('click', function () {
    const field = document.getElementById('password');
    const shown = field.type === 'text';
    field.type = shown ? 'password' : 'text';
    this.innerHTML = shown ? '<i class="fa-regular fa-eye"></i>' : '<i class="fa-regular fa-eye-slash"></i>';
    this.setAttribute('aria-label', shown ? 'Show password' : 'Hide password');
});
</script>
</body>
</html>
