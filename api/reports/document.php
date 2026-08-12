<?php
declare(strict_types=1);

/**
 * TourSync — serving a supporting logbook document.                  Feature 2
 *
 * The only route from storage/logbooks to a browser. The files sit under a
 * deny-all .htaccess precisely so this is the single door, and the door checks
 * who is knocking:
 *
 *   Municipal Tourism Office   any signed-in officer or staff member
 *   Destination manager        only documents on their own destination's reports
 *   Anyone else                404
 *
 * 404 rather than 403 for a document that exists but is not yours. A 403 would
 * confirm the id is real, which is enough to map how many submissions a
 * neighbouring destination has made.
 *
 * These are photographs of pages carrying names, home addresses and mobile
 * numbers. Nothing here is cacheable by a shared cache, and nothing is served
 * inline as HTML.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\DocumentUploader;
use App\Core\ManagerAuth;
use App\Repositories\ArrivalReportRepository as Reports;
use App\Repositories\ReportDocumentRepository as Documents;

/** Ends the request without saying whether the document exists. */
$notFound = static function (): never {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Not found.');
};

$documentId = (int) ($_GET['id'] ?? 0);
$reportId   = (int) ($_GET['report'] ?? 0);

if ($documentId <= 0 || $reportId <= 0) {
    $notFound();
}

$report = Reports::find($reportId);

if ($report === null) {
    $notFound();
}

/* Authorisation. An officer sees any submission; a manager sees their own
   destination's and nothing else. Someone signed in as neither gets the same
   answer as someone signed in as nobody. */
if (Auth::check()) {
    // The office reviews every destination's submissions.
} elseif (ManagerAuth::check()) {
    if (!ManagerAuth::owns((int) $report['destination_id'])) {
        $notFound();
    }
} else {
    $notFound();
}

$document = Documents::find($documentId, $reportId);

if ($document === null) {
    $notFound();
}

$absolute = DocumentUploader::pathFor((string) $document['stored_name']);

if ($absolute === null) {
    $notFound();
}

/* Inline for a photograph or PDF the reviewer wants to look at; the download
   parameter forces a save instead. Either way the filename offered back is the
   one the manager uploaded, quoted, and never used to open anything. */
$disposition = ($_GET['download'] ?? '') === '1' ? 'attachment' : 'inline';
$safeName    = str_replace(['"', "\r", "\n"], '', (string) $document['original_name']);

header('Content-Type: ' . $document['mime_type']);
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

/* Discard anything already buffered so the bytes are not preceded by stray
   output from bootstrap. */
while (ob_get_level() > 0) {
    ob_end_clean();
}

readfile($absolute);
