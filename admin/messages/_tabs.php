<?php
declare(strict_types=1);

/* Included by the screens in this folder, never requested directly: without
   this guard the file runs on its own, dies on the first helper it calls and
   prints the server's filesystem path into the response. */
if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

/**
 * TourSync — the two screens under Communication › Messages.
 *
 * WHY A STRIP AND NOT A SECOND SIDEBAR ENTRY.
 *
 * Both of these are "something the public sent us through the website", and an
 * officer looking for one has no reason to know in advance which of two sidebar
 * items it is under. The sidebar already has nineteen entries; adding a
 * twentieth for a screen that belongs beside an existing one makes the list
 * longer without making anything easier to find.
 *
 * They are separate FILES rather than one file with a ?tab switch because the
 * two hold different records with different columns, different statuses and
 * different filters. One file would be two screens sharing a `require` and an
 * `if`, which is how both end up half-maintained.
 *
 * $activeTab is set by the including page. Anything unrecognised highlights
 * nothing rather than guessing.
 */

$tabs = [
    'messages' => [
        'label' => 'Messages',
        'icon'  => 'fa-envelope',
        'href'  => 'index.php',
        'count' => $tabCounts['messages'] ?? null,
    ],
    'data-requests' => [
        'label' => 'Data Requests',
        'icon'  => 'fa-file-lines',
        'href'  => 'data-requests.php',
        'count' => $tabCounts['data-requests'] ?? null,
    ],
];
?>
<nav class="screen-tabs" aria-label="Messages sections">
    <?php foreach ($tabs as $key => $tab): ?>
        <a class="screen-tabs__tab<?= ($activeTab ?? '') === $key ? ' is-active' : '' ?>"
           href="<?= e($tab['href']) ?>"
           <?= ($activeTab ?? '') === $key ? 'aria-current="page"' : '' ?>>
            <i class="fa-solid <?= e($tab['icon']) ?>" aria-hidden="true"></i>
            <?= e($tab['label']) ?>

            <?php /* The badge counts what is WAITING, not what exists. A number
                     that only ever goes up is not something an officer can act
                     on, and it stops meaning anything by the second month. Zero
                     is drawn as nothing at all rather than as a grey "0" — an
                     empty queue should look empty. */ ?>
            <?php if (!empty($tab['count'])): ?>
                <span class="screen-tabs__count"><?= n((int) $tab['count']) ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>
