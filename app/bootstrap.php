<?php
declare(strict_types=1);

/**
 * =============================================================================
 *  TourSync — application bootstrap
 * -----------------------------------------------------------------------------
 *  Every page in the system begins by including this file, and only this file.
 *  It runs the same seven steps in the same order for public and admin pages
 *  alike, so there is exactly one place to reason about how a request starts.
 *
 *  Pages do not include it directly — they include the shim at the document
 *  root (/bootstrap.php), which is the single file that changes between the
 *  local Laragon layout and cPanel deployment.
 * =============================================================================
 */

use App\Core\Auth;
use App\Core\Database;
use App\Core\Session;

define('TOURSYNC', true);
define('APP_PATH', __DIR__);

// -----------------------------------------------------------------------------
// 1. Configuration
// -----------------------------------------------------------------------------
$configFile = APP_PATH . '/config/config.php';

if (!is_file($configFile)) {
    http_response_code(500);
    exit('TourSync is not configured. Copy app/config/config.sample.php to app/config/config.php.');
}

$GLOBALS['__toursync_config'] = require $configFile;
$config = $GLOBALS['__toursync_config'];

// -----------------------------------------------------------------------------
// 2. Error handling
//    Development shows problems immediately. Production shows nothing to the
//    visitor — a stack trace on a government site leaks paths and credentials.
// -----------------------------------------------------------------------------
$isProduction = ($config['env'] ?? 'production') === 'production';

error_reporting(E_ALL);
ini_set('display_errors', $isProduction ? '0' : '1');
ini_set('log_errors', '1');

date_default_timezone_set('Asia/Manila');
mb_internal_encoding('UTF-8');

// -----------------------------------------------------------------------------
// 3. Autoloader for App\Core and App\Repositories
//    A hand-written PSR-4 loader keeps the project Composer-free, which is
//    what lets it deploy to shared hosting by upload alone.
// -----------------------------------------------------------------------------
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = APP_PATH . DIRECTORY_SEPARATOR . $relative . '.php';

    if (is_file($file)) {
        require $file;
    }
});

require APP_PATH . '/helpers.php';

// -----------------------------------------------------------------------------
// 4. Security headers
//    Sent on every response, before any output.
// -----------------------------------------------------------------------------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(self), camera=(), microphone=()');
    header_remove('X-Powered-By');

    if ($isProduction) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// -----------------------------------------------------------------------------
// 5. Session
// -----------------------------------------------------------------------------
Session::start(
    (int) ($config['security']['session_idle'] ?? 30),
    (int) ($config['security']['session_absolute'] ?? 480)
);

// -----------------------------------------------------------------------------
// 5b. A POST too large for PHP to accept
//
//     When the body exceeds post_max_size, PHP throws the WHOLE request away —
//     $_POST and $_FILES both arrive empty — but still reports the method as
//     POST. Every page then ran Csrf::verify(), found no token, and told the
//     officer their session had expired.
//
//     It had not. They had chosen a video a few megabytes over the limit, and
//     the message sent them off to sign in again instead of to a smaller file.
//     VideoUploader has a clear message for exactly this, and it never ran,
//     because no application code sees a request PHP discarded at the door.
//
//     Detected before any page calls Csrf::verify(), so the honest error is the
//     one that reaches the screen. Nothing in this codebase reads a raw request
//     body, so an empty $_POST with bytes on the wire has only this cause.
// -----------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && $_POST === []
    && $_FILES === []
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {

    $sent  = (int) $_SERVER['CONTENT_LENGTH'];
    $limit = (static function (): int {
        $raw  = trim((string) ini_get('post_max_size'));
        $unit = strtolower(substr($raw, -1));
        $n    = (int) $raw;

        return match ($unit) {
            'g'     => $n * 1024 * 1024 * 1024,
            'm'     => $n * 1024 * 1024,
            'k'     => $n * 1024,
            default => $n,
        };
    })();

    $message = sprintf(
        'That upload is %s MB. This server accepts %s MB per submission, so nothing was saved. '
        . 'Use a smaller file, or add the video as a YouTube link instead — a link has no size limit.',
        number_format($sent / 1048576, 1),
        number_format($limit / 1048576, 0)
    );

    /* Back to the page they were on, but only when it is this site's own —
       Referer is set by the browser and must never be followed off-host. */
    $back  = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $host  = strtolower((string) parse_url($back, PHP_URL_HOST));
    $mine  = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $local = $back !== '' && ($host === '' || $host === $mine || $host === explode(':', $mine)[0]);

    if ($local) {
        Session::flash('danger', $message);
        header('Location: ' . $back, true, 303);
        exit;
    }

    http_response_code(413);
    header('Content-Type: text/plain; charset=utf-8');
    exit($message);
}

// -----------------------------------------------------------------------------
// 6. Database (lazy — no connection is opened until a query runs)
// -----------------------------------------------------------------------------
Database::configure($config['database']);

Auth::configure(
    (int) ($config['security']['max_login_attempts'] ?? 5),
    (int) ($config['security']['lockout_minutes'] ?? 15)
);

// -----------------------------------------------------------------------------
// 7. Consume one-request state
//    Validation errors and rejected input were written to the session by the
//    PREVIOUS request. They are lifted into globals and removed from the
//    session immediately, which is what makes them survive exactly one page
//    load.
//
//    They must NOT be left in $_SESSION for the helpers to read. An earlier
//    version did that and cleared them again in a shutdown function — but
//    shutdown runs on every request, including the one where flash_back()
//    had just written a fresh set, so errors were erased before the redirect
//    could ever store them and no form ever showed a message.
// -----------------------------------------------------------------------------
$GLOBALS['__toursync_errors'] = Session::get('_errors', []);
$GLOBALS['__toursync_old']    = Session::get('_old', []);

Session::forget('_errors');
Session::forget('_old');

// -----------------------------------------------------------------------------
// 8. Maintenance mode
//
//    Last, so a closed public site has already had its session started and its
//    security headers sent — and so the notice can read office_name and
//    office_phone out of the settings table.
//
//    PUBLIC PAGES ONLY. /admin, /manager and the admin API are exempt: a switch
//    that locks out the only people who can turn it off is a trap, not a
//    switch. See Maintenance::appliesToThisRequest().
// -----------------------------------------------------------------------------
\App\Core\Maintenance::guard();
