<?php
declare(strict_types=1);

/**
 * Archive or restore a destination — or delete one that nothing depends on.
 *
 * Archive is the withdrawal. A destination with recorded arrivals cannot be removed
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

/* Where the officer came from. The card menu on the list sends its own page
   and filters; the edit screen sends nothing and gets the edit screen back.
   Only a path inside this folder is honoured — never a full URL — so this can
   not be turned into a redirect to somewhere else. */
$return = (string) ($_POST['return'] ?? '');
$back   = preg_match('#^index\.php(\?[^\r\n]*)?$#', $return) === 1
    ? base_url('/admin/destinations/' . $return)
    : null;

$destination = DestinationRepository::find($id);

if ($destination === null) {
    Session::flash('danger', 'That destination no longer exists.');
    redirect(base_url('/admin/destinations/index.php'));
}

/* PERMANENT DELETE, for an entry that nothing depends on.
 *
 * Archive stays the way to withdraw a real destination. This is for the one
 * added by mistake or twice: no arrivals, no reports, no inspections, no
 * reviews, no manager — see DestinationRepository::dependents(), which the
 * repository runs again inside the delete so the list cannot be stale. Officer
 * only, like every other permanent removal in the admin. */
if (($_POST['action'] ?? '') === 'delete') {
    if (!Auth::isOfficer()) {
        Session::flash('danger', 'Only the Tourism Officer can delete a destination.');
        redirect($back ?? base_url('/admin/destinations/index.php'));
    }

    $blocking = DestinationRepository::dependents($id);

    if ($blocking === [] && DestinationRepository::deleteIfUnused($id)) {
        ActivityLog::record('destination.delete', 'destination', null,
            'Deleted "' . $destination['name'] . '" (#' . $id . ') — it had no records');
        Session::flash('success', '"' . $destination['name'] . '" was deleted.');
    } else {
        $what = [];
        foreach ($blocking as $label => $n) {
            $what[] = n($n) . ' ' . $label;
        }

        Session::flash('danger', '"' . $destination['name'] . '" cannot be deleted: it has '
            . ($what !== [] ? implode(', ', $what) : 'records attached')
            . '. Archive it instead — it leaves the public site and every record is kept.');
    }

    redirect($back ?? base_url('/admin/destinations/index.php'));
}

if (!in_array($status, ['active', 'archived'], true)) {
    Session::flash('danger', 'Unrecognised status.');
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

redirect($back ?? base_url('/admin/destinations/edit.php?id=' . $id));
