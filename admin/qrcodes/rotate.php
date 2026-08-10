<?php
declare(strict_types=1);

/**
 * Issues a new QR token for a destination.
 *
 * Officer-only, because it invalidates physical signage that someone has to
 * travel to a mountain barangay to replace. Staff can print and test codes;
 * only the Tourism Officer can retire one.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\QrService;
use App\Core\Session;

Auth::require('officer');

if (!is_post()) {
    redirect(base_url('/admin/qrcodes/index.php'));
}

Csrf::verify();

$id = (int) ($_POST['id'] ?? 0);
$destination = Database::first('SELECT id, name, qr_version FROM destinations WHERE id = ?', [$id]);

if ($destination === null) {
    Session::flash('danger', 'That destination no longer exists.');
    redirect(base_url('/admin/qrcodes/index.php'));
}

QrService::rotate($id);

$newVersion = (int) $destination['qr_version'] + 1;

ActivityLog::record(
    'qr.rotate',
    'destination',
    $id,
    'QR token rotated for "' . $destination['name'] . '" (now v' . $newVersion . ')'
);

Session::flash(
    'warning',
    'A new code was issued for "' . $destination['name'] . '" (v' . $newVersion . '). '
    . 'Every sign already printed for this destination has stopped working — print and install the replacement.'
);

redirect(base_url('/admin/qrcodes/index.php'));
