<?php
declare(strict_types=1);

/**
 * TourSync — serving one compliance photograph.
 *
 * The only route from storage/inspections to a browser. Those files sit under a
 * deny-all rule precisely so this is the single door, and the door checks who
 * is knocking:
 *
 *   Municipal Tourism Office   any signed-in officer or staff member
 *   Destination manager        only photos on their own destination's reports
 *   Anyone else                404
 *
 * 404 rather than 403 for a photo that exists but is not yours. A 403 confirms
 * the id is real, which is enough to count how many inspections a neighbouring
 * destination has filed.
 *
 * These are photographs of a private establishment's interior — restrooms,
 * storerooms, whatever was behind the camera. Not public, not cacheable by a
 * shared cache, and never rendered as anything but an image.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\DocumentUploader;
use App\Core\ManagerAuth;
use App\Repositories\InspectionRepository as Inspections;

$notFound = static function (): never {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Not found.');
};

$photoId  = (int) ($_GET['id'] ?? 0);
$reportId = (int) ($_GET['report'] ?? 0);

if ($photoId <= 0 || $reportId <= 0) {
    $notFound();
}

$report = Inspections::find($reportId);

if ($report === null) {
    $notFound();
}

if (Auth::check()) {
    // The office reviews every destination's evidence.
} elseif (ManagerAuth::check()) {
    if (!ManagerAuth::owns((int) $report['destination_id'])) {
        $notFound();
    }
} else {
    $notFound();
}

$photo = Inspections::findPhoto($photoId, $reportId);

if ($photo === null) {
    $notFound();
}

$absolute = DocumentUploader::pathFor((string) $photo['stored_name'], 'inspections');

if ($absolute === null) {
    $notFound();
}

$safeName = str_replace(['"', "\r", "\n"], '', (string) $photo['original_name']);

header('Content-Type: ' . $photo['mime_type']);
header('Content-Length: ' . filesize($absolute));
header('Content-Disposition: inline; filename="' . $safeName . '"');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\'');
header('X-Frame-Options: SAMEORIGIN');

while (ob_get_level() > 0) {
    ob_end_clean();
}

readfile($absolute);
