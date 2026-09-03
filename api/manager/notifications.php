<?php
declare(strict_types=1);

/**
 * TourSync — the destination manager's bell endpoint.
 *
 * The officer's twin, at api/admin/notifications.php. Same contract, same four
 * actions, same answer shape — so assets/js/admin.js drives both bells with one
 * implementation and neither can drift from the other. What differs is the two
 * lines that matter: who is asking, and which stream they are allowed to see.
 *
 * THE SCOPE IS NOT A PARAMETER
 *
 * $destinationId is read from the session. It is never taken from the request,
 * so there is no id here for anyone to change into a neighbouring destination's
 * notifications — the same rule every other manager screen follows. The
 * repository puts it into the WHERE clause of every read AND every write, so a
 * crafted POST cannot mark another site's row either.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Csrf;
use App\Core\ManagerAuth;
use App\Repositories\ManagerNotificationRepository as Notifications;

if (!ManagerAuth::check()) {
    json_response(['error' => 'Not authorised'], 401);
}

$managerId     = (int) ManagerAuth::id();
$destinationId = (int) ManagerAuth::destinationId();

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? 'poll');

/* Reading is a GET; anything that changes a read state is a POST and carries
   the token. A bell that could be emptied by a link in an email is a bell
   somebody else can silence. */
if (is_post()) {
    Csrf::verify();

    $id = (int) ($_POST['id'] ?? 0);

    switch ($action) {
        case 'read':
            Notifications::markRead($id, $managerId, $destinationId);
            break;

        case 'unread':
            Notifications::markUnread($id, $managerId, $destinationId);
            break;

        case 'read-all':
            Notifications::markAllRead($managerId, $destinationId);
            break;

        default:
            json_response(['error' => 'Unknown action'], 400);
    }
}

$items = array_map(
    [Notifications::class, 'present'],
    Notifications::latestFor($managerId, $destinationId, Notifications::DROPDOWN)
);

/* Every answer carries the new unread count, whatever was asked. The badge must
   never be a number the browser worked out for itself. */
json_response([
    'unread' => Notifications::unreadCountFor($managerId, $destinationId),
    'total'  => Notifications::countAll($destinationId),
    'items'  => $items,
]);
