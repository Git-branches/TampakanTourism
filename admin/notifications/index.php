<?php
declare(strict_types=1);

/**
 * TourSync — everything the bell has ever said.
 *
 * The dropdown shows five, which is the right number for a menu and the wrong
 * number for looking something up. This is where an officer comes back to find
 * the request that arrived last Tuesday.
 *
 * Read state is per officer, so this page is one person's view of a shared
 * list — marking something read here hides nothing from a colleague.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Paginator;
use App\Core\Session;
use App\Repositories\NotificationRepository as Notifications;

Auth::require();

$adminId = (int) Auth::id();

if (is_post()) {
    Csrf::verify();

    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'read' && $id > 0)   { Notifications::markRead($id, $adminId); }
    if ($action === 'unread' && $id > 0) { Notifications::markUnread($id, $adminId); }

    if ($action === 'read-all') {
        Notifications::markAllRead($adminId);
        Session::flash('success', 'Everything is marked as read.');
    }

    redirect(base_url('/admin/notifications/index.php?' . Paginator::query([])));
}

$total  = Notifications::countAll();
$window = Paginator::of($total, $_GET['page'] ?? null);

$rows = array_map(
    [Notifications::class, 'present'],
    Notifications::latestFor($adminId, $window['perPage'], $window['offset'])
);

$pager  = ['rows' => $rows] + $window;
$unread = Notifications::unreadCountFor($adminId);

$pageTitle    = 'Notifications';
$pageIcon     = 'fa-bell';
$pageSubtitle = 'Everything that has come in, newest first';

require __DIR__ . '/../_partials/head.php';
?>

<div class="page-actions">
    <?php if ($unread > 0): ?>
        <form method="post">
            <?= csrf_field() ?>
            <button type="submit" name="action" value="read-all" class="btn btn-sm btn-outline-secondary">
                <i class="fa-solid fa-check-double" aria-hidden="true"></i>
                Mark all <?= n($unread) ?> as read
            </button>
        </form>
    <?php endif; ?>
</div>

<?php if ($rows === []): ?>
    <section class="panel">
        <div class="panel__body">
            <div class="empty-public">
                <i class="fa-regular fa-bell" aria-hidden="true"></i>
                <h3>Nothing yet</h3>
                <p>
                    New tour guide requests, messages from the public website, submitted
                    reports and urgent destination alerts all appear here as they arrive.
                </p>
            </div>
        </div>
    </section>
<?php else: ?>
    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-regular fa-bell"></i> All notifications</h2>
            <p class="panel__count">
                <?= n($unread) ?> unread of <?= n($total) ?>
            </p>
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

                    <?php /* One control, and it says which way it will go. Opening a
                             list is not reading it, so nothing here changes state
                             until somebody presses something. */ ?>
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

<?php require __DIR__ . '/../../app/views/partials/pager.php'; ?>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
