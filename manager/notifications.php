<?php
declare(strict_types=1);

/**
 * TourSync — everything the Office has sent this destination.
 *
 * The bell holds four. This is the rest of them, newest first, and the only
 * place a manager can reach a notification that has scrolled out of the panel.
 *
 * The officer has had admin/notifications/index.php since the bell was built;
 * the manager's bell was wired to the same kind of stream and given nowhere to
 * go. This is that page, on the manager's own repository and scoped to the
 * manager's own destination.
 *
 * NOTHING HERE IS MARKED READ BY LOOKING AT IT. Opening a list is not reading
 * it, and a page that quietly clears the badge is a page that loses the one
 * thing the badge was for. Every state change is a button somebody presses.
 *
 * All writes happen before any output — head.php starts the page, and a
 * redirect after that is a redirect that cannot send its header.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Csrf;
use App\Core\ManagerAuth;
use App\Core\Paginator;
use App\Core\Session;
use App\Repositories\ManagerNotificationRepository as Notices;

ManagerAuth::require();

$managerId     = (int) ManagerAuth::id();
$destinationId = (int) ManagerAuth::destinationId();

if (is_post()) {
    Csrf::verify();

    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    /* Both ids are passed to the repository, which checks them. A notification
       belonging to another destination is not an error to report — it simply
       does not match, and nothing happens. */
    if ($action === 'read'   && $id > 0) { Notices::markRead($id, $managerId, $destinationId); }
    if ($action === 'unread' && $id > 0) { Notices::markUnread($id, $managerId, $destinationId); }

    if ($action === 'read-all') {
        $n = Notices::markAllRead($managerId, $destinationId);
        Session::flash('success', $n > 0
            ? $n . ' notification(s) marked as read.'
            : 'There was nothing left to mark.');
    }

    redirect(base_url('/manager/notifications.php?' . Paginator::query([])));
}

$total  = Notices::countAll($destinationId);
$window = Paginator::of($total, $_GET['page'] ?? null);

$rows = array_map(
    [Notices::class, 'present'],
    Notices::latestFor($managerId, $destinationId, $window['perPage'], $window['offset'])
);

$pager  = ['rows' => $rows] + $window;
$unread = Notices::unreadCountFor($managerId, $destinationId);

$pageTitle    = 'Notifications';
$pageIcon     = 'fa-gear';
$pageSubtitle = 'Everything the Office has sent you, newest first';

$mgrSettingsTab = 'notifications';

require __DIR__ . '/_partials/head.php';
require __DIR__ . '/_partials/settings-tabs.php';
?>

<?php if ($unread > 0): ?>
    <div class="page-actions">
        <form method="post">
            <?= csrf_field() ?>
            <button type="submit" name="action" value="read-all" class="btn btn-sm btn-outline-secondary">
                <i class="fa-solid fa-check-double" aria-hidden="true"></i>
                Mark all <?= n($unread) ?> as read
            </button>
        </form>
    </div>
<?php endif; ?>

<?php if ($rows === []): ?>
    <section class="panel">
        <div class="panel__body">
            <div class="empty rf-empty">
                <i class="fa-regular fa-bell" aria-hidden="true"></i>
                <h3>Nothing yet</h3>
                <p>
                    When the Municipal Tourism Office approves a report, sends one back for
                    correction, answers an alert you raised or reviews an inspection, it appears here.
                </p>
            </div>
        </div>
    </section>
<?php else: ?>
    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-regular fa-bell"></i> All notifications</h2>
            <p class="panel__count"><?= n($unread) ?> unread of <?= n($total) ?></p>
        </header>

        <ul class="notice-list">
            <?php foreach ($rows as $item): ?>
                <li class="notice<?= $item['unread'] ? ' is-unread' : '' ?>">
                    <span class="bell__icon bell__icon--<?= e($item['tone']) ?>">
                        <i class="fa-solid <?= e($item['icon']) ?>" aria-hidden="true"></i>
                    </span>

                    <div class="notice__text">
                        <strong>
                            <?php if ($item['link'] !== ''): ?>
                                <a href="<?= e($item['link']) ?>"><?= e($item['title']) ?></a>
                            <?php else: ?>
                                <?= e($item['title']) ?>
                            <?php endif; ?>
                        </strong>

                        <?php if ($item['body'] !== ''): ?>
                            <p><?= e($item['body']) ?></p>
                        <?php endif; ?>

                        <span class="notice__when" title="<?= e($item['exact']) ?>">
                            <?= e($item['label']) ?> &middot; <?= e($item['when']) ?>
                        </span>
                    </div>

                    <?php /* One control, and it says which way it will go. */ ?>
                    <form method="post" class="notice__action">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                        <button type="submit" name="action"
                                value="<?= $item['unread'] ? 'read' : 'unread' ?>"
                                class="btn btn-sm btn-outline-secondary">
                            <?= $item['unread'] ? 'Mark as read' : 'Mark as unread' ?>
                        </button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/partials/pager.php'; ?>

<?php require __DIR__ . '/_partials/foot.php'; ?>
