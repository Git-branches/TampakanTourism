<?php
declare(strict_types=1);

/**
 * TourSync — the bell's one endpoint.
 *
 * Everything the bell does comes through here: what is unread, what the latest
 * five are, and the three ways to change a read state.
 *
 * ONE FILE, NOT FOUR
 *
 * poll / read / unread / read-all are the same conversation about the same
 * thing, and every one of them has to answer with the new unread count anyway —
 * the badge must never be a number the browser guessed. Four files would have
 * been four copies of that answer.
 *
 * WHY POLLING AND NOT A SOCKET
 *
 * This deploys to cPanel shared hosting with no long-lived processes and no
 * WebSocket, so "real time" here means a short poll. It is deliberately cheap:
 * two indexed counts, and the list only when the count has moved.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Repositories\NotificationRepository as Notifications;

if (!Auth::check()) {
    json_response(['error' => 'Not authorised'], 401);
}

$adminId = (int) Auth::id();
$action  = (string) ($_POST['action'] ?? $_GET['action'] ?? 'poll');

/* Reading is a GET; anything that changes a read state is a POST and carries
   the token. A bell that could be emptied by a link in an email is a bell
   somebody else can silence. */
if (is_post()) {
    Csrf::verify();

    $id = (int) ($_POST['id'] ?? 0);

    switch ($action) {
        case 'read':
            if ($id > 0) { Notifications::markRead($id, $adminId); }
            break;

        case 'unread':
            if ($id > 0) { Notifications::markUnread($id, $adminId); }
            break;

        case 'read-all':
            Notifications::markAllRead($adminId);
            break;

        default:
            json_response(['error' => 'Unknown action'], 400);
    }
}

/* THE ANSWER IS ALWAYS THE SAME SHAPE.
 *
 * Count and list together, whatever was asked. The browser never adds or
 * subtracts one from the badge itself: it prints what the server last said.
 * A badge maintained by arithmetic in two places drifts, and the first anybody
 * notices is a 3 that will not go away. */
$items = array_map(
    [Notifications::class, 'present'],
    Notifications::latestFor($adminId, Notifications::DROPDOWN)
);

json_response([
    'unread' => Notifications::unreadCountFor($adminId),
    'total'  => Notifications::countAll(),
    'items'  => $items,
]);
