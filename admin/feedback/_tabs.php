<?php
declare(strict_types=1);

/* Included by the screens in this folder, never requested directly: without
   this guard the file runs on its own, dies on the first helper it calls and
   prints the server's filesystem path into the response. */
if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

/**
 * TourSync — the two moderation queues under Feedback.
 *
 * Destination reviews and tour guide ratings are both "somebody rated something
 * and an officer decides whether it is published", and they follow the same
 * policy — hide abuse and spam, never hide a review for being negative. They
 * belong beside each other.
 *
 * SEPARATE FILES, not one screen with a switch. A destination review is tied to
 * an arrival or a QR scan and filters by destination and star count; a guide
 * rating is about a named person and filters by guide. Different columns,
 * different joins, different filters. One file would be two screens sharing a
 * `require` and an `if`, which is how both end up half-maintained.
 *
 * $activeTab is set by the including page; anything unrecognised highlights
 * nothing rather than guessing.
 */

$tabs = [
    'destinations' => [
        'label' => 'Destination Reviews',
        'icon'  => 'fa-mountain-sun',
        'href'  => 'index.php',
        'count' => $tabCounts['destinations'] ?? null,
    ],
    'guides' => [
        'label' => 'Tour Guide Ratings',
        'icon'  => 'fa-person-hiking',
        'href'  => 'guides.php',
        'count' => $tabCounts['guides'] ?? null,
    ],
];
?>
<nav class="screen-tabs" aria-label="Feedback sections">
    <?php foreach ($tabs as $key => $tab): ?>
        <a class="screen-tabs__tab<?= ($activeTab ?? '') === $key ? ' is-active' : '' ?>"
           href="<?= e($tab['href']) ?>"
           <?= ($activeTab ?? '') === $key ? 'aria-current="page"' : '' ?>>
            <i class="fa-solid <?= e($tab['icon']) ?>" aria-hidden="true"></i>
            <?= e($tab['label']) ?>

            <?php /* The badge counts what is WAITING on that queue. Zero is drawn
                     as nothing at all rather than as a grey "0" — an empty queue
                     should look empty. */ ?>
            <?php if (!empty($tab['count'])): ?>
                <span class="screen-tabs__count"><?= n((int) $tab['count']) ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>
