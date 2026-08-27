<?php
declare(strict_types=1);

/**
 * TourSync — the Settings tab strip, shared by three pages.
 *
 * WHY SOME TABS ARE BUTTONS AND SOME ARE LINKS
 *
 * The five settings sections live in ONE form on index.php with ONE Save. Its
 * handler walks every key in $editable and writes `$_POST[$key] ?? ''`, so a
 * field absent from the request is saved as an empty string. A link tab there
 * would post only the visible fields and blank every setting on every other tab
 * — verified: a post carrying office_name alone wipes the address, the hotlines,
 * the hero and the retention window. So those five are BUTTONS that hide panels
 * with CSS, and every input stays in the document.
 *
 * User Accounts and My Account are separate pages with their own forms and their
 * own POST handlers. Nothing is lost by navigating to them, so those two are
 * real links. Unsaved settings are protected by the beforeunload guard on
 * index.php, which fires before the browser leaves.
 *
 * ROLE MATTERS HERE. Settings and User Accounts are Auth::require('officer');
 * My Account is open to any signed-in admin. So a Tourism Staff member reaching
 * My Account must not be shown six tabs that would refuse them — they get no
 * strip at all, because a strip of one tab is not navigation.
 *
 * Expects $settingsTab: one of office|public|alerts|records|system|accounts|me.
 */

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

use App\Core\Auth;

$settingsTab = $settingsTab ?? 'office';

/* Staff never see this strip. My Account is the only page here they can reach,
   and it stands on its own. */
if (!Auth::isOfficer()) {
    return;
}

/* href === null marks the five that are panels on index.php rather than pages.
   Defined once, so a tab added later cannot appear on one page and not another. */
$settingsTabList = [
    'office'   => ['fa-building-columns', 'Office',          null],
    'public'   => ['fa-image',            'Public site',     null],
    'alerts'   => ['fa-tower-broadcast',  'Alerts &amp; SMS', null],
    'records'  => ['fa-shield-halved',    'Records',         null],
    'system'   => ['fa-server',           'System',          null],
    'accounts' => ['fa-users-gear',       'User Accounts',   'accounts.php'],
    'me'       => ['fa-user-gear',        'My Account',      'account.php'],
];

/* Where each real page lives, resolved from the app root rather than relative to
   whichever folder is including this. */
$settingsTabHref = [
    'office'   => base_url('/admin/settings/index.php#office'),
    'public'   => base_url('/admin/settings/index.php#public'),
    'alerts'   => base_url('/admin/settings/index.php#alerts'),
    'records'  => base_url('/admin/settings/index.php#records'),
    'system'   => base_url('/admin/settings/index.php#system'),
    'accounts' => base_url('/admin/settings/accounts.php'),
    'me'       => base_url('/admin/account/index.php'),
];

/* On index.php the five panel tabs are buttons; anywhere else every tab is a
   link, because there is no panel on this page for them to reveal. */
$onSettingsIndex = in_array($settingsTab, ['office', 'public', 'alerts', 'records', 'system'], true);
?>
<div class="tab-row" id="settingsTabs" role="tablist">
    <?php foreach ($settingsTabList as $key => [$icon, $label, $page]): ?>
        <?php
        $isPanel  = $page === null;
        $isActive = $key === $settingsTab;
        ?>

        <?php if ($onSettingsIndex && $isPanel): ?>
            <button type="button" class="tab<?= $isActive ? ' is-active' : '' ?>" role="tab"
                    data-settab-btn="<?= e($key) ?>"
                    aria-selected="<?= $isActive ? 'true' : 'false' ?>">
                <i class="fa-solid <?= e($icon) ?>" aria-hidden="true"></i> <?= $label ?>
            </button>
        <?php else: ?>
            <?php /* aria-current rather than aria-selected: this one is a link to the
                     page you are on, not a tab in a tablist this page controls. */ ?>
            <a class="tab<?= $isActive ? ' is-active' : '' ?>"
               href="<?= e($settingsTabHref[$key]) ?>"
               <?= $isActive ? 'aria-current="page"' : '' ?>>
                <i class="fa-solid <?= e($icon) ?>" aria-hidden="true"></i> <?= $label ?>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
