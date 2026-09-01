<?php
declare(strict_types=1);

/**
 * TourSync — downloading a database backup.
 *
 * The single most sensitive response this system can produce: it carries every
 * visitor name and contact number, every logbook entry, every feedback message
 * and the officer's own password hash. So:
 *
 *   - Officer only. A destination manager has no business holding the whole
 *     municipality's records, and neither has anyone who merely found the URL.
 *   - POST with a CSRF token. A GET would be a link somebody could put in an
 *     email, or an <img src> on a page an officer visits while signed in, and
 *     the backup would be taken without them touching anything.
 *   - Streamed, never written to disk. A file under the document root is a file
 *     that can be requested by URL, and the one place a backup must not sit is
 *     inside the webroot of the machine it is a backup of.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Backup;
use App\Core\Csrf;
use App\Core\Session;

Auth::require('officer');

if (!is_post()) {
    Session::flash('danger', 'A backup is taken from the Settings screen.');
    redirect(base_url('/admin/settings/index.php#system'));
}

Csrf::verify();

/* Recorded BEFORE the download begins. A dump of a large database can be
   interrupted half way, and the fact worth keeping is that somebody asked for
   a copy of everything — not that the transfer completed. */
ActivityLog::record('system.backup', 'system', null,
    'Downloaded a full database backup');

$filename = Backup::filename();

/* Any buffering has to go, or the whole dump is held in memory until the end
   and a large one exhausts memory_limit before a single byte is sent. */
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/sql; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

/* No Content-Length: the dump is generated as it goes and its size is not
   known in advance. Sending a wrong one truncates the file. */

/* A backup must not stop half way because the page did — the officer would be
   left holding a partial dump that restores an incomplete database. */
set_time_limit(0);
ignore_user_abort(false);

Backup::stream();
exit;
