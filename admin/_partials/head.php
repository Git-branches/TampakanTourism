<?php
/**
 * Admin layout — opening markup, sidebar, and topbar.
 *
 * Every admin page sets $pageTitle and optionally $pageIcon before including
 * this file, and includes foot.php at the end.
 *
 * The sidebar shows only what the signed-in role may reach. That is a
 * convenience, not a control — the real gate is Auth::require() at the top
 * of each page.
 */

use App\Core\Auth;
use App\Core\Session;

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

$user       = Auth::user();
$isOfficer  = Auth::isOfficer();
$current    = basename($_SERVER['SCRIPT_NAME']);
$currentDir = basename(dirname($_SERVER['SCRIPT_NAME']));
$flashes    = Session::takeFlash();

/* THE FOUR SIDEBAR COUNTS ARE GONE, AND SO ARE THEIR QUERIES.
 *
 * The nav no longer draws a badge, so the counts had no reader — but they were
 * still five COUNT(*) queries on every admin page load, for four numbers nobody
 * saw. Dead work is worse than visible work.
 *
 * Each module still leads with its own count on its own screen: the request
 * queue says "2 needing action", the alerts inbox and the change-request list
 * do the same. Nothing was lost except the duplication.
 */

/* THE BELL.
 *
 * Rendered from the server so the first paint already carries the right number
 * — a badge that appears a second after the page, or corrects itself from 0 to
 * 4, is a badge people learn to distrust. The poll in admin.js only keeps it
 * up to date after that.
 *
 * Silent on failure, like the counts above it: a bell is not worth taking a
 * page down for. */
$bellUnread = 0;
$bellItems  = [];

try {
    $bellUnread = \App\Repositories\NotificationRepository::unreadCountFor((int) Auth::id());
    $bellItems  = array_map(
        [\App\Repositories\NotificationRepository::class, 'present'],
        \App\Repositories\NotificationRepository::latestFor((int) Auth::id())
    );
} catch (\Throwable $e) {
    $bellUnread = 0;
    $bellItems  = [];
}

$bellTotal = 0;

try {
    $bellTotal = \App\Repositories\NotificationRepository::countAll();
} catch (\Throwable $e) {
    $bellTotal = 0;
}

/**
 * Sidebar definition. 'phase' marks modules that are not built yet — they
 * render as visibly pending rather than as broken links, so the build
 * progress is legible from inside the system itself.
 */
$nav = [
    ['group' => 'Overview', 'items' => [
        ['label' => 'Dashboard',       'icon' => 'fa-gauge-high',       'href' => 'dashboard.php', 'dir' => 'admin'],
    ]],
    ['group' => 'Tourism Records', 'items' => [
        ['label' => 'Reports to Review','icon' => 'fa-inbox',            'href' => 'arrival-reports/index.php', 'dir' => 'arrival-reports'],
        ['label' => 'Visitor Register','icon' => 'fa-address-card',     'href' => 'arrivals/index.php',     'dir' => 'arrivals'],
        ['label' => 'Destinations',    'icon' => 'fa-mountain-sun',     'href' => 'destinations/index.php', 'dir' => 'destinations'],
        ['label' => 'QR Codes',        'icon' => 'fa-qrcode',           'href' => 'qrcodes/index.php',      'dir' => 'qrcodes'],
        ['label' => 'Feedback',        'icon' => 'fa-comment-dots',     'href' => 'feedback/index.php',     'dir' => 'feedback'],
        ['label' => 'Tour Guide Requests','icon' => 'fa-person-hiking', 'href' => 'guides/index.php',     'dir' => 'guides'],
        ['label' => 'Tour Guides',     'icon' => 'fa-id-card',          'href' => 'tour-guides/index.php', 'dir' => 'tour-guides'],
    ]],
    ['group' => 'Standards', 'items' => [
        ['label' => 'Destination Alerts', 'icon' => 'fa-tower-broadcast', 'href' => 'alerts/index.php', 'dir' => 'alerts'],
        ['label' => 'Compliance Review','icon' => 'fa-clipboard-check', 'href' => 'inspections/index.php',  'dir' => 'inspections'],
        ['label' => 'Change Requests', 'icon' => 'fa-pen-to-square',    'href' => 'change-requests/index.php', 'dir' => 'change-requests'],
    ]],
    ['group' => 'Communication', 'items' => [
        ['label' => 'Messages',        'icon' => 'fa-envelope',         'href' => 'messages/index.php',     'dir' => 'messages'],
        ['label' => 'Promotional Videos','icon' => 'fa-film',           'href' => 'videos/index.php',       'dir' => 'videos'],
        ['label' => 'Announcements',   'icon' => 'fa-bullhorn',         'href' => 'announcements/index.php','dir' => 'announcements'],
        ['label' => 'Destination Managers','icon' => 'fa-address-book', 'href' => 'managers/index.php',     'dir' => 'managers'],
    ]],
    /* Decision Support and Budget Planner were removed from the officer's side
       on the client's instruction — the assistant on the public site covers the
       advisory need. app/Core/Insights.php stays: Analytics reads its monthly
       history, trend and moving average, and deleting it would take the charts
       with it. */
    ['group' => 'Analysis', 'items' => [
        ['label' => 'Reports',         'icon' => 'fa-file-lines',       'href' => 'reports/index.php',      'dir' => 'reports'],
        ['label' => 'Analytics',       'icon' => 'fa-chart-line',       'href' => 'analytics/index.php',    'dir' => 'analytics'],
    ]],
    /* "My Account" is deliberately NOT here. It is a tab of Settings for an
       officer, and it is reached from your own name in the topbar by anyone —
       which is where people look for it, and costs the sidebar nothing. */
    ['group' => 'System', 'items' => [
        ['label' => 'Activity Logs',   'icon' => 'fa-clipboard-list',   'href' => 'logs/index.php',    'dir' => 'logs',     'officer' => true],
        ['label' => 'Settings',        'icon' => 'fa-gear',             'href' => 'settings/index.php','dir' => 'settings', 'officer' => true],
    ]],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle ?? 'Admin') ?> — TourSync</title>
<link rel="icon" href="<?= e(asset('img/tampakan_logo.png')) ?>" sizes="any">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">

<?php
/* THE RAIL STATE, BEFORE THE FIRST PAINT.
 *
 * This used to be decided in admin.js, which loads from the foot of the page.
 * The browser had therefore already painted a 258px sidebar and laid the whole
 * page out beside it; the script then collapsed it to 84px and everything on
 * screen jumped 174 pixels sideways. On every single navigation.
 *
 * That is what "the sidebar breaks when I click around" was. Not a broken
 * sidebar — a correct one, applied one paint too late.
 *
 * Inline and in the head on purpose: it must run before the browser has
 * anything to show. Kept to the one decision that changes layout; everything
 * else stays in admin.js where it can be read.
 *
 * The class goes on <html> because <body> does not exist yet at this point. */
?>
<script>
(function () {
    try {
        if (localStorage.getItem('toursync.sidebar.rail') === '1') {
            document.documentElement.classList.add('is-rail');
        }
    } catch (e) { /* private mode: the sidebar simply opens expanded */ }
})();
</script>
</head>
<body>

<div class="admin-shell">

    <!-- ================= SIDEBAR ================= -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar__brand">
            <img src="<?= e(asset('img/tampakan_logo.png')) ?>"
                 alt="Seal of the Municipality of Tampakan" width="42" height="42">
            <span>
                <strong>TourSync</strong>
                <small>Tourism Office</small>
            </span>
        </div>

        <nav class="sidebar__nav" aria-label="Admin sections">
            <?php foreach ($nav as $group): ?>
                <p class="sidebar__group"><?= e($group['group']) ?></p>
                <ul>
                <?php foreach ($group['items'] as $item):
                    if (!empty($item['officer']) && !$isOfficer) {
                        continue;   // cosmetic only — the page gate is authoritative
                    }
                    $isActive  = $currentDir === $item['dir'] || ($item['dir'] === 'admin' && $current === $item['href']);
                    $isPending = isset($item['phase']);
                ?>
                    <li>
                        <a class="sidebar__link <?= $isActive ? 'is-active' : '' ?> <?= $isPending ? 'is-pending' : '' ?>"
                           data-label="<?= e($item['label']) ?>"
                           href="<?= $isPending ? '#' : e(base_url('/admin/' . $item['href'])) ?>"
                           <?= $isPending ? 'aria-disabled="true" title="Arrives in Phase ' . (int) $item['phase'] . '"' : '' ?>>
                            <i class="fa-solid <?= e($item['icon']) ?>"></i>
                            <span><?= e($item['label']) ?></span>
                            <?php /* NO COUNTS HERE. The office asked for the sidebar to be
                                     left plain — the bell in the topbar is where a number
                                     belongs, and two of them competing on one screen is
                                     noise rather than emphasis.

                                     The counts themselves are still computed above and are
                                     still correct; they simply are not drawn on the nav.
                                     Each module's own screen still leads with them. */ ?>
                            <?php if ($isPending): ?>
                                <em class="sidebar__phase">P<?= (int) $item['phase'] ?></em>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar__foot">
            <a href="<?= e(base_url('/')) ?>" target="_blank" rel="noopener">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> View public site
            </a>
        </div>
    </aside>

    <?php
    /* THE SCROLL POSITION, ALSO BEFORE THE FIRST PAINT.
     *
     * Same fault as the rail: admin.js restored this from the foot of the page,
     * so the sidebar painted at the top and then jumped down to where the
     * person had left it. Nineteen links on the officer's sidebar, and the jump
     * was visible on every load.
     *
     * Placed here rather than in the head because the element has to exist to
     * be scrolled — this runs the moment the browser finishes parsing it, which
     * is still before anything is shown. */
    ?>
    <script>
    (function () {
        var at, bar;

        try {
            at  = parseInt(sessionStorage.getItem('toursync.sidebar.scroll'), 10);
            bar = document.getElementById('sidebar');
        } catch (e) {
            return;   /* private mode: the sidebar simply starts at the top */
        }

        if (!bar || !at) { return; }

        bar.scrollTop = at;

        /* AND AGAIN, ONCE, IF IT DID NOT TAKE.
         *
         * scrollTop on an element that is not yet taller than its box is
         * silently ignored, and on a cold load the sidebar occasionally is not
         * — webfonts and icons land a moment later and it grows. Measured over
         * repeated runs this happens rarely, but "rarely" here means the jump
         * this whole arrangement exists to prevent.
         *
         * Re-applied only when the first attempt was actually lost, so the
         * common path still sets it once, before anything is painted. */
        if (bar.scrollTop !== at) {
            document.addEventListener('DOMContentLoaded', function () {
                if (bar.scrollTop !== at) { bar.scrollTop = at; }
            });
        }
    })();
    </script>

    <div class="sidebar-scrim" id="sidebarScrim" hidden></div>

    <!-- ================= MAIN ================= -->
    <div class="admin-main">

        <header class="topbar">
            <button class="topbar__toggle" id="sidebarToggle" aria-label="Toggle navigation">
                <i class="fa-solid fa-bars"></i>
            </button>

            <?php /* Desktop only — below 992px the button above already opens
                     the sidebar as an overlay, which is the right control on a
                     phone. Collapsing to a rail buys 190px, which on the wide
                     tables here is the difference between fitting and
                     scrolling sideways. */ ?>
            <button class="topbar__rail" id="railToggle" type="button"
                    aria-label="Collapse the sidebar" aria-pressed="false" title="Collapse the sidebar">
                <i class="fa-solid fa-angles-left" aria-hidden="true"></i>
            </button>

            <div class="topbar__title">
                <h1><i class="fa-solid <?= e($pageIcon ?? 'fa-gauge-high') ?>"></i> <?= e($pageTitle ?? 'Dashboard') ?></h1>
                <?php if (!empty($pageSubtitle)): ?>
                    <p><?= e($pageSubtitle) ?></p>
                <?php endif; ?>
            </div>

            <?php /* Beside the account block, which is where a person looks for
                     "things about me" — and far from the sidebar, which answers
                     the different question of where work is waiting. */ ?>
            <div class="bell" id="bell">
                <button type="button" class="bell__button" id="bellButton"
                        aria-haspopup="true" aria-expanded="false"
                        aria-label="Notifications">
                    <i class="fa-regular fa-bell" aria-hidden="true"></i>
                    <span class="bell__badge" id="bellBadge"
                          <?= $bellUnread === 0 ? 'hidden' : '' ?>
                          aria-live="polite"><?= n($bellUnread) ?></span>
                </button>

                <div class="bell__panel" id="bellPanel" hidden>
                    <header class="bell__head">
                        <h2>Notifications</h2>
                        <button type="button" class="bell__all" id="bellMarkAll"
                                <?= $bellUnread === 0 ? 'disabled' : '' ?>>Mark all as read</button>
                    </header>

                    <ul class="bell__list" id="bellList">
                        <?php foreach ($bellItems as $item): ?>
                            <li class="bell__item<?= $item['unread'] ? ' is-unread' : '' ?>"
                                data-notification="<?= (int) $item['id'] ?>">
                                <a class="bell__link" href="<?= e($item['link'] ?: base_url('/admin/notifications/index.php')) ?>">
                                    <span class="bell__icon bell__icon--<?= e($item['tone']) ?>">
                                        <i class="fa-solid <?= e($item['icon']) ?>" aria-hidden="true"></i>
                                    </span>
                                    <span class="bell__text">
                                        <strong><?= e($item['title']) ?></strong>
                                        <?php if ($item['body'] !== ''): ?>
                                            <span class="bell__body"><?= e($item['body']) ?></span>
                                        <?php endif; ?>
                                        <span class="bell__when" title="<?= e($item['exact']) ?>">
                                            <?= e($item['label']) ?> &middot; <?= e($item['when']) ?>
                                        </span>
                                    </span>
                                </a>

                                <?php /* Opening the panel must not mark anything read, so this
                                         is the only control that changes a state without
                                         leaving the page. */ ?>
                                <button type="button" class="bell__toggle" data-notification-toggle
                                        title="<?= $item['unread'] ? 'Mark as read' : 'Mark as unread' ?>"
                                        aria-label="<?= $item['unread'] ? 'Mark as read' : 'Mark as unread' ?>"></button>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <p class="bell__empty" id="bellEmpty" <?= $bellItems !== [] ? 'hidden' : '' ?>>
                        Nothing yet. New requests, messages and alerts appear here.
                    </p>

                    <footer class="bell__foot" <?= $bellTotal <= count($bellItems) ? 'hidden' : '' ?> id="bellFoot">
                        <a href="<?= e(base_url('/admin/notifications/index.php')) ?>">
                            View all notifications
                        </a>
                    </footer>
                </div>
            </div>

            <?php /* YOUR NAME IS THE WAY TO YOUR ACCOUNT.
                     "My Account" came out of the sidebar — for an officer it is a
                     tab of Settings now, and the sidebar was long. But Settings is
                     Auth::require('officer'), so removing it outright would leave a
                     Tourism Staff member with NO route to their own page and no way
                     to change their own password. This block was the obvious place
                     to put it back: it is where people look, it costs no row in the
                     sidebar, and it works for both roles.

                     The name and the avatar are one link. The sign-out stays a
                     separate one beside it — a click meant for "my account" must
                     never be able to end the session by a few pixels. */ ?>
            <div class="topbar__user">
                <a class="topbar__me" href="<?= e(base_url('/admin/account/index.php')) ?>"
                   title="Your profile and password">
                    <span class="topbar__who">
                        <strong><?= e($user['full_name']) ?></strong>
                        <small><?= $isOfficer ? 'Tourism Officer' : 'Tourism Staff' ?></small>
                    </span>
                    <span class="topbar__avatar" aria-hidden="true">
                        <?= e(mb_strtoupper(mb_substr($user['full_name'], 0, 1))) ?>
                    </span>
                    <span class="visually-hidden">My account</span>
                </a>

                <a href="<?= e(base_url('/admin/logout.php')) ?>" class="topbar__signout" title="Sign out">
                    <i class="fa-solid fa-right-from-bracket"></i>
                </a>
            </div>
        </header>

        <main class="admin-content">

            <?php
            /* Shown on every admin screen until the password is changed. A
               one-time notice at first sign-in is dismissed and forgotten; an
               installer password left in place for a year is a real finding. */
            if (\App\Core\Auth::check()) {
                $pwChanged = \App\Core\Database::scalar(
                    'SELECT password_changed_at FROM admins WHERE id = ?', [\App\Core\Auth::id()]
                );
                if ($pwChanged === null && $currentDir !== 'account'): ?>
                    <div class="alert alert-warning">
                        <i class="fa-solid fa-key"></i>
                        <strong>Your account still uses the password generated by the installer.</strong>
                        It was printed to a terminal and may survive in a screenshot or a notebook.
                        <a href="<?= e(base_url('/admin/account/index.php')) ?>" class="alert-link">Change it now</a>.
                    </div>
                <?php endif;
            }
            ?>

            <?php /* Flash messages are toasts now, docked top-left and gone in
                     five seconds. They used to render here inline, which on a
                     long form put the confirmation above the fold the person
                     had already scrolled past, and left it on screen over
                     unrelated work until the next navigation. */ ?>
            <?php require __DIR__ . '/../../app/views/partials/toast-dock.php'; ?>
