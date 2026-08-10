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
