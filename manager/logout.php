<?php
declare(strict_types=1);

/**
 * TourSync — destination manager sign-out.                         Feature 2
 *
 * POST only, and CSRF-checked. A sign-out reachable by GET can be triggered by
 * an <img> tag on any page a manager visits — harmless as pranks go, but it
 * logs somebody out mid-report on a phone at a waterfall, which is not.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Csrf;
use App\Core\ManagerAuth;
use App\Core\Session;

if (!is_post()) {
    redirect(base_url('/manager/index.php'));
}

Csrf::verify();

ManagerAuth::logout();

Session::flash('success', 'You have been signed out.');
redirect(base_url('/manager/login.php'));
