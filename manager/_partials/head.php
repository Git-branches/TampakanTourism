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

$mgrNav = [
    ['label' => 'Dashboard',        'icon' => 'fa-gauge-high',   'href' => 'index.php',   'file' => 'index.php'],
    /* "Tourist Arrival Reports" is the short UI label for Centralized Tourist
       Arrival Logbook Submission and Monitoring. The screens it covers —
       report-form, logbook and import — all sit under it, so they keep it
       highlighted rather than dropping the sidebar's sense of place. */
    ['label' => 'Tourist Arrival Reports', 'icon' => 'fa-file-lines', 'href' => 'reports.php',
     'file' => 'reports.php', 'also' => ['report-form.php', 'logbook.php', 'import.php']],
    ['label' => 'Compliance Inspection', 'icon' => 'fa-clipboard-check', 'href' => 'inspection.php',
     'file' => 'inspection.php', 'also' => ['inspections.php', 'inspection-view.php']],
    ['label' => 'Report an Alert',  'icon' => 'fa-triangle-exclamation', 'href' => 'alert.php', 'file' => 'alert.php'],
    ['label' => 'Update my destination', 'icon' => 'fa-pen-to-square', 'href' => 'update-info.php', 'file' => 'update-info.php'],
    ['label' => 'My Account',       'icon' => 'fa-user-gear',    'href' => 'account.php', 'file' => 'account.php'],
];

$current = basename($_SERVER['SCRIPT_NAME'] ?? '');
$flashes = App\Core\Session::takeFlash();
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
<body>

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
            <p class="sidebar__group">My Destination</p>
            <ul>
                <?php foreach ($mgrNav as $item): ?>
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
