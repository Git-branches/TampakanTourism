<?php
declare(strict_types=1);

/**
 * TourSync — destination manager sign-in.                          Feature 2
 *
 * The door that removes the trip to the Municipal Tourism Office. A manager
 * signs in here from the destination — often on a phone, often on one bar of
 * signal — and files the arrival report from where the paper logbook actually
 * sits.
 *
 * Deliberately separate from /admin/login.php. Same house style so it is
 * recognisably the same system, different session and different destination, so
 * the two can never be mistaken for one another. Accounts are issued by the
 * Tourism Office; there is no registration and no reset-by-email.
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
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Destination Manager Sign In — TourSync</title>
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
            <p>Destination Manager &middot; Tampakan, South Cotabato</p>
        </div>

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
                <label for="username" class="form-label">Username</label>
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
            Your account is issued by the Municipal Tourism Office and covers one destination.
            To have it created or reset, contact the Office.
        </p>

        <p class="login-card__switch">
            Municipal Tourism Office staff?
            <a href="<?= e(base_url('/admin/login.php')) ?>">Sign in to the Office dashboard</a>
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
