<?php
declare(strict_types=1);

/**
 * TourSync — the manager's Settings tab strip.
 *
 * The officer's shell keeps My Account, User Accounts and the settings panels
 * behind one Settings entry with a strip of tabs across the top. The manager's
 * sidebar had a bare "My Account" instead, and no route at all to their own
 * activity or to the notifications behind the bell — the bell showed four and
 * there was nowhere to see the rest.
 *
 * Three tabs, all real links. Unlike the officer's five panel tabs, none of
 * these is a hidden panel of one shared form: each is its own page with its own
 * POST handler, so navigating between them cannot blank a field on another.
 *
 * Expects $mgrSettingsTab: one of me|logs|notifications.
 */

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

$mgrSettingsTab = $mgrSettingsTab ?? 'me';

$mgrTabList = [
    'me'            => ['fa-user-gear',          'My Account',   'account.php'],
    'logs'          => ['fa-clock-rotate-left',  'Activity Log', 'logs.php'],
    'notifications' => ['fa-bell',               'Notifications', 'notifications.php'],
];
?>
<div class="tab-row" id="mgrSettingsTabs">
    <?php foreach ($mgrTabList as $key => [$icon, $label, $page]): ?>
        <?php $isActive = $key === $mgrSettingsTab; ?>
        <?php /* aria-current, not aria-selected: these are links to other pages,
                 not tabs this page shows and hides. */ ?>
        <a class="tab<?= $isActive ? ' is-active' : '' ?>"
           href="<?= e(base_url('/manager/' . $page)) ?>"
           <?= $isActive ? 'aria-current="page"' : '' ?>>
            <i class="fa-solid <?= e($icon) ?>" aria-hidden="true"></i> <?= $label ?>
        </a>
    <?php endforeach; ?>
</div>
