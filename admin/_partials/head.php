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

/* Urgent alerts nobody has picked up, on EVERY admin screen.
 *
 * An alert that only appears on the alerts page is an alert that waits for
 * somebody to open the alerts page. A manager reporting an injury needs the
 * count to follow the officer around the dashboard. One cheap COUNT, and it
 * fails silently — a badge is not worth breaking a page over. */
$urgentAlerts = 0;

try {
    $urgentAlerts = \App\Repositories\AlertRepository::urgentWaiting();
} catch (\Throwable $e) {
    $urgentAlerts = 0;
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
        ['label' => 'Tourist Arrival Reports', 'icon' => 'fa-inbox',     'href' => 'arrival-reports/index.php', 'dir' => 'arrival-reports'],
        ['label' => 'Tourist Arrivals','icon' => 'fa-user-check',       'href' => 'arrivals/index.php',     'dir' => 'arrivals'],
        ['label' => 'Destinations',    'icon' => 'fa-mountain-sun',     'href' => 'destinations/index.php', 'dir' => 'destinations'],
        ['label' => 'QR Codes',        'icon' => 'fa-qrcode',           'href' => 'qrcodes/index.php',      'dir' => 'qrcodes'],
        ['label' => 'Feedback',        'icon' => 'fa-comment-dots',     'href' => 'feedback/index.php',     'dir' => 'feedback'],
    ]],
    ['group' => 'Standards', 'items' => [
        ['label' => 'Destination Alerts', 'icon' => 'fa-tower-broadcast', 'href' => 'alerts/index.php', 'dir' => 'alerts'],
        ['label' => 'Compliance Review','icon' => 'fa-clipboard-check', 'href' => 'inspections/index.php',  'dir' => 'inspections'],
    ]],
    ['group' => 'Communication', 'items' => [
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
    ['group' => 'System', 'items' => [
        ['label' => 'My Account',      'icon' => 'fa-user-gear',        'href' => 'account/index.php', 'dir' => 'account'],
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
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
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
                           href="<?= $isPending ? '#' : e(base_url('/admin/' . $item['href'])) ?>"
                           <?= $isPending ? 'aria-disabled="true" title="Arrives in Phase ' . (int) $item['phase'] . '"' : '' ?>>
                            <i class="fa-solid <?= e($item['icon']) ?>"></i>
                            <span><?= e($item['label']) ?></span>
                            <?php if ($item['dir'] === 'alerts' && $urgentAlerts > 0): ?>
                                <em class="sidebar__badge" title="Urgent alerts not yet picked up"><?= n($urgentAlerts) ?></em>
                            <?php endif; ?>
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

    <div class="sidebar-scrim" id="sidebarScrim" hidden></div>

    <!-- ================= MAIN ================= -->
    <div class="admin-main">

        <header class="topbar">
            <button class="topbar__toggle" id="sidebarToggle" aria-label="Toggle navigation">
                <i class="fa-solid fa-bars"></i>
            </button>

            <div class="topbar__title">
                <h1><i class="fa-solid <?= e($pageIcon ?? 'fa-gauge-high') ?>"></i> <?= e($pageTitle ?? 'Dashboard') ?></h1>
                <?php if (!empty($pageSubtitle)): ?>
                    <p><?= e($pageSubtitle) ?></p>
                <?php endif; ?>
            </div>

            <div class="topbar__user">
                <div class="topbar__who">
                    <strong><?= e($user['full_name']) ?></strong>
                    <small><?= $isOfficer ? 'Tourism Officer' : 'Tourism Staff' ?></small>
                </div>
                <div class="topbar__avatar" aria-hidden="true">
                    <?= e(mb_strtoupper(mb_substr($user['full_name'], 0, 1))) ?>
                </div>
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

            <?php foreach ($flashes as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
                    <?= e($flash['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endforeach; ?>
