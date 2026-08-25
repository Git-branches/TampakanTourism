<?php
declare(strict_types=1);

/**
 * TourSync — serving a tour guide's certificate.
 *
 * The only route from storage/certificates to a browser. The files sit under a
 * deny-all .htaccess precisely so this is the single door, and the door checks
 * who is knocking: signed-in office staff, and nobody else.
 *
 * A training certificate carries a private individual's full name and often
 * their birth date. The PUBLIC verification page lists the names of these
 * documents so a visitor can see what a guide is qualified in — it never links
 * to the files themselves, and this endpoint is why that distinction holds.
 *
 * 404 rather than 403 for a certificate that exists but is not reachable, so
 * the response does not confirm which ids are real.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\DocumentUploader;
use App\Repositories\TourGuideRosterRepository as Roster;

/** Ends the request without saying whether the certificate exists. */
$notFound = static function (): never {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Not found.');
};

/* Not Auth::require(). That redirects to the sign-in page, which for an <img>
   or an embedded PDF means the browser quietly renders a login form where a
   document should be. A file endpoint answers with a status, not a page. */
if (!Auth::check()) {
    $notFound();
}

$certificateId = (int) ($_GET['id'] ?? 0);

if ($certificateId <= 0) {
    $notFound();
}

$certificate = Roster::findCertificate($certificateId);

if ($certificate === null) {
    $notFound();
}

$absolute = DocumentUploader::pathFor((string) $certificate['stored_name'], 'certificates');

if ($absolute === null) {
    $notFound();
}

$disposition = ($_GET['download'] ?? '') === '1' ? 'attachment' : 'inline';
$safeName    = str_replace(['"', "\r", "\n"], '', (string) $certificate['original_name']);

header('Content-Type: ' . $certificate['mime_type']);
header('Content-Length: ' . filesize($absolute));
header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');

/* Personal data: private caches only, and never stored by a proxy. */
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

/* Stops a browser from second-guessing the declared type and rendering
   something as HTML in this origin. */
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; object-src \'self\'');
header('X-Frame-Options: SAMEORIGIN');

while (ob_get_level() > 0) {
    ob_end_clean();
}

readfile($absolute);
