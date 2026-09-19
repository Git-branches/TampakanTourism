<?php
declare(strict_types=1);

/**
 * TourSync — the two screens under Compliance Review.
 *
 * Manage Requirements first, because it decides what the second one contains:
 * every submitted report is a set of photographs against these requirements.
 * The sidebar still lands on Submitted Reports, which is the daily work.
 *
 * Separate files rather than one file with a ?tab switch, the same reasoning
 * as the Messages strip: different records, different actions, different
 * forms. The same component and class names, so both strips look and behave
 * as one pattern.
 *
 * $activeTab is set by the including page; anything else highlights nothing.
 */

$tabs = [
    'requirements' => [
        'label' => 'Manage Requirements',
        'icon'  => 'fa-list-check',
        'href'  => 'requirements.php',
        'count' => null,
    ],
    'reports' => [
        'label' => 'Submitted Reports',
        'icon'  => 'fa-clipboard-check',
        'href'  => 'index.php',
        /* What is waiting on the office, never the total. */
        'count' => $tabCounts['reports'] ?? null,
    ],
];
?>
<nav class="screen-tabs" aria-label="Compliance sections">
    <?php foreach ($tabs as $key => $tab): ?>
        <a class="screen-tabs__tab<?= ($activeTab ?? '') === $key ? ' is-active' : '' ?>"
           href="<?= e($tab['href']) ?>"
           <?= ($activeTab ?? '') === $key ? 'aria-current="page"' : '' ?>>
            <i class="fa-solid <?= e($tab['icon']) ?>" aria-hidden="true"></i>
            <?= e($tab['label']) ?>
            <?php if (!empty($tab['count'])): ?>
                <span class="screen-tabs__count"><?= n((int) $tab['count']) ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>
