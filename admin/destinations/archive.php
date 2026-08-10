<?php
declare(strict_types=1);

/**
 * Archive or restore a destination.
 *
 * There is no delete. A destination with recorded arrivals cannot be removed
 * without destroying official tourism statistics — the foreign key is
 * RESTRICT and would refuse — so archiving is the only withdrawal path.
 * Archived destinations vanish from the public site and the map while every
 * historical arrival stays countable in past reports.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;
use App\Repositories\DestinationRepository;

Auth::require();

if (!is_post()) {
    redirect(base_url('/admin/destinations/index.php'));
}

Csrf::verify();

$id     = (int) ($_POST['id'] ?? 0);
$status = (string) ($_POST['status'] ?? '');

if (!in_array($status, ['active', 'archived'], true)) {
    Session::flash('danger', 'Unrecognised status.');
    redirect(base_url('/admin/destinations/index.php'));
}

$destination = DestinationRepository::find($id);

if ($destination === null) {
    Session::flash('danger', 'That destination no longer exists.');
    redirect(base_url('/admin/destinations/index.php'));
}

DestinationRepository::setStatus($id, $status);

ActivityLog::record(
    $status === 'archived' ? 'destination.archive' : 'destination.restore',
    'destination',
    $id,
    ($status === 'archived' ? 'Archived "' : 'Restored "') . $destination['name'] . '"'
);

Session::flash(
    'success',
    $status === 'archived'
        ? '"' . $destination['name'] . '" was archived and is no longer public. Its arrival records are untouched.'
        : '"' . $destination['name'] . '" is public again.'
);

redirect(base_url('/admin/destinations/edit.php?id=' . $id));
