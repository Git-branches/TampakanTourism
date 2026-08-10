<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Auth;
use App\Core\Session;

Auth::logout();

// Session::destroy() wiped the flash store, so start a fresh session to carry
// the confirmation across to the login page.
Session::start();
Session::flash('success', 'You have been signed out.');

redirect(base_url('/admin/login.php'));
