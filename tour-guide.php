<?php
declare(strict_types=1);

/**
 * TourSync — the old "Request a Tour Guide" address.
 *
 * The form that lived here is now the Tour Guide Request category of the
 * Contact form on the homepage, posting to the same api/guides/request.php.
 *
 * This file stays as a forwarder rather than being deleted: the address was in
 * the navigation and on every destination page, so it is in bookmarks, browser
 * histories and search results. The destination and the QR marker travel on.
 */

require_once __DIR__ . '/bootstrap.php';

redirect(guide_request_url(
    trim((string) ($_GET['d'] ?? '')),
    ($_GET['src'] ?? '') === 'qr'
));
