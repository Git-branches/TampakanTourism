<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;

/* POST ONLY, AND CSRF-CHECKED — the same rule as manager/logout.php.
   This used to sign out on any request, so a GET was enough, and a GET can be
   fired by an <img> on any page an officer happens to open. A signed-in officer
   who lands here by address is sent back to their dashboard rather than signed
   out; one who is not signed in is sent on to the sign-in page from there. */
if (!is_post()) {
    redirect(base_url('/admin/dashboard.php'));
}

Csrf::verify();

Auth::logout();

// Session::destroy() wiped the flash store, so start a fresh session to carry
// the confirmation across to the login page.
Session::start();
Session::flash('success', 'You have been signed out.');

redirect(base_url('/admin/login.php'));
