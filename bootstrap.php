<?php
/**
 * =============================================================================
 *  TourSync — document-root shim
 * -----------------------------------------------------------------------------
 *  Every page includes THIS file, never app/bootstrap.php directly:
 *
 *      require_once __DIR__ . '/bootstrap.php';           // pages at the root
 *      require_once __DIR__ . '/../bootstrap.php';        // pages in /admin
 *      require_once __DIR__ . '/../../bootstrap.php';     // pages one deeper
 *
 *  Why the indirection: the application folder lives in a different place
 *  locally than in production, and this is the only file that has to know.
 *
 *      Laragon  — the vhost DocumentRoot is the project folder itself, so
 *                 app/ sits inside it and is sealed off by app/.htaccess.
 *
 *      cPanel   — move app/ one level ABOVE public_html/ so it is not under
 *                 the web root at all, then change the line below to:
 *
 *                     $appPath = dirname(__DIR__) . '/app';
 *
 *  Nothing else in the codebase changes between the two environments.
 * =============================================================================
 */

$appPath = __DIR__ . '/app';        // ← the one line to change on deployment

if (!is_file($appPath . '/bootstrap.php')) {
    http_response_code(500);
    exit('TourSync application folder not found. Check the path in bootstrap.php.');
}

require_once $appPath . '/bootstrap.php';
