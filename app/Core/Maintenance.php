<?php
declare(strict_types=1);

namespace App\Core;

/**
 * TourSync — closing the public site without taking the office off the air.
 *
 * The office needs a way to say "the site is being worked on" during a
 * migration, a bulk import, or the half hour after a bad edit — without the
 * visitor seeing a half-updated page and without an officer having to edit a
 * file over FTP to do it.
 *
 * THE RULE THAT MAKES THIS SAFE: it closes the PUBLIC site only. The admin and
 * manager areas stay open, and so does the sign-in page. A maintenance switch
 * that locks out the only people who can turn it off is not a switch, it is a
 * trap — and it would be reachable by exactly one route afterwards, which is
 * somebody with database access at eleven at night.
 *
 * The QR endpoint is closed with everything else on purpose. A visitor standing
 * at a waterfall during a migration should be told the system is briefly down,
 * not shown a page assembled from tables half way through being altered.
 */
final class Maintenance
{
    /** Is the public site currently closed? */
    public static function isOn(): bool
    {
        return trim((string) setting('maintenance_mode', '0')) === '1';
    }

    /** What the visitor is told. Falls back to something true and unalarming. */
    public static function message(): string
    {
        $written = trim((string) setting('maintenance_message', ''));

        return $written !== ''
            ? $written
            : 'The Tampakan tourism website is briefly offline for scheduled maintenance. '
              . 'Please try again shortly.';
    }

    /**
     * Whether THIS request is one the switch is allowed to stop.
     *
     * Anything under /admin or /manager is exempt, as is the API the admin
     * screens themselves call. Decided from the script path rather than from a
     * session, because the sign-in page must answer before anybody has one.
     */
    public static function appliesToThisRequest(): bool
    {
        $path = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        foreach (['/admin/', '/manager/', '/api/admin/'] as $exempt) {
            if (str_contains($path, $exempt)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ends the request with a plain notice, or returns and lets the page run.
     *
     * 503 with Retry-After, not 200 with a nice picture: a search engine that
     * crawls during a migration must not record the notice as the page's real
     * content, and 503 is the one status that tells it to come back.
     */
    public static function guard(): void
    {
        if (!self::isOn() || !self::appliesToThisRequest()) {
            return;
        }

        if (!headers_sent()) {
            http_response_code(503);
            header('Retry-After: 3600');
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store');
        }

        $message = self::message();
        $office  = trim((string) setting('office_name', 'Municipal Tourism Office'));
        $phone   = trim((string) setting('office_phone', ''));

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<title>Briefly offline &mdash; ' . e($office) . '</title>'
           . '<style>'
           . 'body{margin:0;min-height:100vh;display:grid;place-items:center;padding:2rem;'
           . 'font-family:"Segoe UI",system-ui,sans-serif;background:#F4F7F5;color:#1C2529}'
           . '.m{max-width:34rem;text-align:center}'
           . '.m h1{font-size:1.5rem;margin:0 0 .75rem}'
           . '.m p{line-height:1.6;color:#5B6771;margin:0 0 .6rem}'
           . '.m .i{font-size:2.5rem;margin-bottom:1rem}'
           . '</style></head><body><div class="m">'
           . '<div class="i">&#9881;&#65039;</div>'
           . '<h1>Briefly offline</h1>'
           . '<p>' . e($message) . '</p>'
           . ($phone !== '' ? '<p>Urgent enquiries: <strong>' . e($phone) . '</strong></p>' : '')
           . '<p><small>' . e($office) . ' &middot; Tampakan, South Cotabato</small></p>'
           . '</div></body></html>';

        exit;
    }
}
