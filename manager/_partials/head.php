<?php
/**
 * TourSync — destination manager shell.                            Feature 2
 *
 * Reuses admin.css rather than carrying a stylesheet of its own: a manager and
 * an officer are looking at the same system and it should look like it. What
 * differs is the navigation, which is short on purpose — a manager has three
 * jobs, and a sidebar listing fourteen modules they cannot open would be
 * fourteen invitations to a 403.
 *
 * The destination name sits in the header of every page. A manager who covers
 * one site does not need reminding, but the officer standing over their
 * shoulder during a handover does, and so does the manager reassigned last
 * month who is about to file figures against the wrong waterfall.
 */

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

use App\Core\ManagerAuth;

ManagerAuth::require();

/* TWO GROUPS, because "My Account" is not one of the manager's field jobs.
   The four above it are the work — look, file, inspect, report — and the
   account sits apart the way it does in the officer's shell.

   "Update my destination" was removed from here: the destination's own
   information is the Admin's to edit, and a manager offered the form was being
   offered a second source of truth for the same record. update-info.php is
   still reachable by its address rather than deleted — see the note at the
   bottom of this array. */
$mgrNavGroups = [
    'My Destination' => [
        ['label' => 'Dashboard', 'icon' => 'fa-gauge-high', 'href' => 'index.php', 'file' => 'index.php'],

        /* "Tourist Arrival Reports" is the short UI label for Centralized Tourist
           Arrival Logbook Submission and Monitoring. The screens it covers —
           report-form, logbook and import — all sit under it, so they keep it
           highlighted rather than dropping the sidebar's sense of place. */
        ['label' => 'Tourist Arrival Reports', 'icon' => 'fa-file-lines', 'href' => 'reports.php',
         'file' => 'reports.php', 'also' => ['report-form.php', 'logbook.php', 'import.php']],

        ['label' => 'Compliance Inspection', 'icon' => 'fa-clipboard-check', 'href' => 'inspection.php',
         'file' => 'inspection.php', 'also' => ['inspections.php', 'inspection-view.php']],

        ['label' => 'Report an Alert', 'icon' => 'fa-triangle-exclamation', 'href' => 'alert.php',
         'file' => 'alert.php'],
    ],

    'Account' => [
        /* update-info.php keeps its route and its permissions. Unlinking it is a
           navigation decision; blocking it would be a permissions change, and
           this task was explicitly not to touch those. A bookmark still works,
           and nothing in the system links here any more. */
        ['label' => 'My Account', 'icon' => 'fa-user-gear', 'href' => 'account.php',
         'file' => 'account.php', 'also' => ['update-info.php']],
    ],
];

$current = basename($_SERVER['SCRIPT_NAME'] ?? '');
$flashes = App\Core\Session::takeFlash();

/* THE BELL'S FIRST PAINT.
 *
 * Read here so the panel is already correct before the poll returns — the
 * officer's shell does the same, and for the same reason: a badge that appears
 * a second after the page is a badge that flickers on every navigation.
 *
 * Silent on failure. A bell is not worth taking a page down for, and the four
 * things beneath it are the manager's actual work. */
$bellUnread = 0;
$bellItems  = [];
$bellTotal  = 0;

try {
    $mgrId   = (int) ManagerAuth::id();
    $mgrDest = (int) ManagerAuth::destinationId();

    $bellUnread = \App\Repositories\ManagerNotificationRepository::unreadCountFor($mgrId, $mgrDest);
    $bellItems  = array_map(
        [\App\Repositories\ManagerNotificationRepository::class, 'present'],
        \App\Repositories\ManagerNotificationRepository::latestFor($mgrId, $mgrDest)
    );
    $bellTotal  = \App\Repositories\ManagerNotificationRepository::countAll($mgrDest);
} catch (\Throwable) {
    $bellUnread = 0;
    $bellItems  = [];
    $bellTotal  = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle ?? 'Destination Manager') ?> — TourSync</title>
<link rel="icon" href="<?= e(asset('img/tampakan_logo.png')) ?>" sizes="any">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<?php
/* The rail state before the first paint — see the note in the officer's
   head.php. The manager shell shares admin.js and the same stylesheet, so it
   had the same 174-pixel jump on every navigation. */
?>
<script>
(function () {
    try {
        if (localStorage.getItem('toursync.sidebar.rail') === '1') {
            document.documentElement.classList.add('is-rail');
        }
    } catch (e) { /* private mode */ }
})();
</script>
</head>
<?php /* is-manager IS THE WHOLE SAFETY MECHANISM FOR THIS SHELL'S STYLING.
         admin.css is shared with the officer, whose interface is finished and
         must not move. Every rule written for the manager is scoped under this
         class, so a selector cannot reach the officer's pages even by accident.
         Adding a manager style is then a local act rather than a system-wide
         one. Nothing else keys off it. */ ?>
<body class="is-manager">

<div class="admin-shell">

    <aside class="sidebar" id="sidebar">
        <div class="sidebar__brand">
            <img src="<?= e(asset('img/tampakan_logo.png')) ?>" alt="Seal of the Municipality of Tampakan"
                 width="42" height="42">
            <div>
                <strong>TourSync</strong>
                <small>Destination Manager</small>
            </div>
        </div>

        <nav class="sidebar__nav">
            <?php foreach ($mgrNavGroups as $groupLabel => $groupItems): ?>
                <p class="sidebar__group"><?= e($groupLabel) ?></p>
                <ul>
                    <?php foreach ($groupItems as $item): ?>
                        <?php
                        $pending = !empty($item['pending']);
                        $active  = $current === $item['file'] || in_array($current, $item['also'] ?? [], true);
                        ?>
                        <li>
                            <a class="sidebar__link <?= $active ? 'is-active' : '' ?> <?= $pending ? 'is-pending' : '' ?>"
                               data-label="<?= e($item['label']) ?>"
                               href="<?= $pending ? '#' : e($item['href']) ?>"
                               <?= $pending ? 'aria-disabled="true" title="Coming soon"' : '' ?>>
                                <i class="fa-solid <?= e($item['icon']) ?>"></i>
                                <span><?= e($item['label']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar__foot">
            <form method="post" action="<?= e(base_url('/manager/logout.php')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="sidebar__link" style="width:100%; background:none; border:none; text-align:left;">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    <span>Sign out</span>
                </button>
            </form>
        </div>
    </aside>

    <?php /* And the scroll position, once the element exists to be scrolled. */ ?>
    <script>
    (function () {
        try {
            var at = sessionStorage.getItem('toursync.sidebar.scroll');
            if (at !== null) { document.getElementById('sidebar').scrollTop = parseInt(at, 10) || 0; }
        } catch (e) { /* private mode */ }
    })();
    </script>

    <div class="sidebar-scrim" id="sidebarScrim" hidden></div>

    <div class="admin-main">

        <!-- Same class names as the officer shell, deliberately. The stylesheet
             is shared, and inventing parallel names here would mean every future
             change to the dashboard chrome had to be made twice. -->
        <header class="topbar">
            <button class="topbar__toggle" id="sidebarToggle" aria-label="Toggle navigation">
                <i class="fa-solid fa-bars"></i>
            </button>

            <button class="topbar__rail" id="railToggle" type="button"
                    aria-label="Collapse the sidebar" aria-pressed="false" title="Collapse the sidebar">
                <i class="fa-solid fa-angles-left" aria-hidden="true"></i>
            </button>

            <div class="topbar__title">
                <h1><i class="fa-solid <?= e($pageIcon ?? 'fa-gauge-high') ?>"></i> <?= e($pageTitle ?? 'Dashboard') ?></h1>
                <?php if (!empty($pageSubtitle)): ?><p><?= e($pageSubtitle) ?></p><?php endif; ?>
            </div>

            <div class="topbar__user">
                <?php /* THE SAME BELL AS THE OFFICER'S, down to the class names.
                         assets/js/admin.js drives it from window.TourSyncBell,
                         which the foot sets to the MANAGER endpoint — so the
                         markup, the styling and the interaction are shared and
                         only the stream behind it differs. A second bell
                         implementation would look almost right and behave
                         slightly differently, which is worse than either. */ ?>
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
                                    <a class="bell__link" href="<?= e($item['link'] ?: base_url('/manager/index.php')) ?>">
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

                                    <?php /* Opening the panel must not mark anything read.
                                             This is the only control that changes a state
                                             without leaving the page. */ ?>
                                    <button type="button" class="bell__toggle" data-notification-toggle
                                            title="<?= $item['unread'] ? 'Mark as read' : 'Mark as unread' ?>"
                                            aria-label="<?= $item['unread'] ? 'Mark as read' : 'Mark as unread' ?>"></button>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <p class="bell__empty" id="bellEmpty" <?= $bellItems !== [] ? 'hidden' : '' ?>>
                            Nothing yet. When the Office reviews your reports or answers an alert, it appears here.
                        </p>
                    </div>
                </div>

                <div class="topbar__who">
                    <strong><?= e(ManagerAuth::name()) ?></strong>
                    <small><?= e(ManagerAuth::destinationName()) ?></small>
                </div>
                <div class="topbar__avatar" aria-hidden="true">
                    <?= e(mb_strtoupper(mb_substr(ManagerAuth::name(), 0, 1))) ?>
                </div>
            </div>
        </header>

        <main class="admin-content">

            <?php /* The same dock the officer's shell uses. One system, one
                     way of confirming a change. */ ?>
            <?php require __DIR__ . '/../../app/views/partials/toast-dock.php'; ?>
