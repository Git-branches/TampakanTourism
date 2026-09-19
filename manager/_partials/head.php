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

        /* RELINKED 2026-09-07, and the label is the reason it is safe to.
         *
         * This was unlinked because "destination information is the Admin's to
         * edit, and a manager offered the form was being offered a second
         * source of truth for the same record." The concern was right; the page
         * was not the thing causing it. update-info.php writes NOTHING to the
         * destination — it files a proposal the office must approve, and it
         * cannot touch the name, the location, the coordinates, the QR token or
         * whether the site is open at all. Those stay office decisions.
         *
         * "Request", not "Edit" or "Update": the label has to say that somebody
         * else decides, or the manager reads their own draft as published.
         *
         * Unlinking it had also taken away the only route to the office's
         * ANSWER, so an officer could decline with a reason and the manager
         * would never see it. See admin/change-requests/index.php. */
        ['label' => 'Request a Detail Update', 'icon' => 'fa-pen-to-square',
         'href' => 'update-info.php', 'file' => 'update-info.php'],
    ],

    'System' => [
        /* ONE ENTRY, THREE PAGES — the shape the officer's shell already has.
           The sidebar said "My Account" and led to one page; a manager had no
           route to their own activity trail, and none to the notifications
           behind the bell beyond the four it could show. Settings is the
           container for all three, and account.php stays the landing because
           it is the one anybody comes here for.

           update-info.php keeps its route and its permissions. Unlinking it is a
           navigation decision; blocking it would be a permissions change, and
           this task was explicitly not to touch those. A bookmark still works,
           and nothing in the system links here any more. */
        ['label' => 'Settings', 'icon' => 'fa-gear', 'href' => 'account.php',
         'file' => 'account.php',
         'also' => ['logs.php', 'notifications.php']],
    ],
];

/* Also nav-prefixed. update-info.php uses $current for the live value of the
   field it is drawing, and any page is free to — the name is far too ordinary
   for a shared partial to claim. */
$navCurrent = basename($_SERVER['SCRIPT_NAME'] ?? '');
$flashes    = App\Core\Session::takeFlash();

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

/* Once a day, shared with the office's shell: old notifications everyone has
   read are cleared. See App\Core\Housekeeping. */
\App\Core\Housekeeping::runDaily();

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
<link rel="icon" href="<?= e(asset('img/tourism-logo-mark.png')) ?>" type="image/png">
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
            <img src="<?= e(asset('img/tourism-logo-mark.png')) ?>" alt="Tampakan Municipal Tourism Office"
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
                        /* nav-PREFIXED, AND THAT PREFIX IS THE WHOLE POINT.
                         *
                         * A partial required into a page shares that page's
                         * variable scope, so every name assigned here lands in
                         * the caller's. These were $pending and $active — and
                         * `$pending` is exactly what update-info.php calls its
                         * list of waiting change requests. head.php runs AFTER
                         * that list is built, so the array was overwritten with
                         * a boolean and the page died on
                         * "foreach() argument must be of type array|object,
                         * bool given". It went unseen only because nothing
                         * linked to that page. */
                        $navPending = !empty($item['pending']);
                        $navActive  = $navCurrent === $item['file']
                            || in_array($navCurrent, $item['also'] ?? [], true);
                        ?>
                        <li>
                            <a class="sidebar__link <?= $navActive ? 'is-active' : '' ?> <?= $navPending ? 'is-pending' : '' ?>"
                               data-label="<?= e($item['label']) ?>"
                               href="<?= $navPending ? '#' : e($item['href']) ?>"
                               <?= $navPending ? 'aria-disabled="true" title="Coming soon"' : '' ?>>
                                <i class="fa-solid <?= e($item['icon']) ?>"></i>
                                <span><?= e($item['label']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </nav>

        <?php /* Sign-out used to sit here. It moved to the top bar beside the
                 manager's name, where the officer's has always been, and the
                 foot now carries what the officer's foot carries: the way out
                 to the site the public sees.

                 It matters more to a manager than to an officer. This is how
                 they check that the destination page a visitor reaches — the
                 one their QR code opens — actually shows what they submitted.
                 New tab, so a half-typed logbook page is not lost to it. */ ?>
        <div class="sidebar__foot">
            <a href="<?= e(base_url('/')) ?>" target="_blank" rel="noopener">
                <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> View public site
            </a>
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

                        <?php /* THE WAY OUT OF THE DROPDOWN.
                                 $bellTotal was already being counted here and
                                 never shown, so the panel could hold four of
                                 nine and say nothing about the other five. The
                                 officer's bell has had this footer all along;
                                 hidden on the same rule, so it appears only
                                 when there is genuinely more than fits. */ ?>
                        <footer class="bell__foot" id="bellFoot"
                                <?= $bellTotal <= count($bellItems) ? 'hidden' : '' ?>>
                            <a href="<?= e(base_url('/manager/notifications.php')) ?>">
                                View all notifications
                            </a>
                        </footer>
                    </div>
                </div>

                <?php /* YOUR NAME IS THE WAY TO YOUR SETTINGS, and sign-out sits
                         beside it — the officer's shell has worked this way all
                         along, and a manager who learns one shell should not
                         have to learn the other.

                         The name and the avatar are ONE link. Sign-out is a
                         SEPARATE control beside it: a click meant for "my
                         settings" must never end the session by a few pixels.

                         IT STAYS A POST FORM. The officer's sign-out is a plain
                         link; this one is a CSRF-checked POST, and logout.php
                         refuses anything else on purpose — a sign-out reachable
                         by GET can be fired by an <img> on any page a manager
                         opens, which logs them out mid-report on a phone at a
                         waterfall. Matching the officer's LOOK is the request;
                         matching its method would be a downgrade, so the button
                         is styled as that link rather than replaced by one. */ ?>
                <a class="topbar__me" href="<?= e(base_url('/manager/account.php')) ?>"
                   title="Your settings, activity and notifications">
                    <span class="topbar__who">
                        <strong><?= e(ManagerAuth::name()) ?></strong>
                        <small><?= e(ManagerAuth::destinationName()) ?></small>
                    </span>
                    <span class="topbar__avatar" aria-hidden="true">
                        <?= e(mb_strtoupper(mb_substr(ManagerAuth::name(), 0, 1))) ?>
                    </span>
                    <span class="visually-hidden">My settings</span>
                </a>

                <?php /* data-confirm is the shell's own convention — admin.js
                         catches it on the form and asks through SweetAlert2, so
                         this is the same dialog every other confirmed action in
                         the system uses. With JavaScript off the form still
                         posts and still signs out; the question is a courtesy,
                         never the thing standing between a click and the act. */ ?>
                <form method="post" action="<?= e(base_url('/manager/logout.php')) ?>"
                      class="topbar__out"
                      data-confirm="Are you sure you want to sign out of TourSync?">
                    <?= csrf_field() ?>
                    <button type="submit" class="topbar__signout" title="Sign out">
                        <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
                        <span class="visually-hidden">Sign out</span>
                    </button>
                </form>
            </div>
        </header>

        <main class="admin-content">

            <?php /* The same dock the officer's shell uses. One system, one
                     way of confirming a change. */ ?>
            <?php require __DIR__ . '/../../app/views/partials/toast-dock.php'; ?>
