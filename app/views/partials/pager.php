<?php
declare(strict_types=1);

/**
 * TourSync — the pager, shared by every list that has one.
 *
 * Expects $pager, the array App\Core\Paginator returns.
 *
 * FOUR COPIES OF THIS EXISTED, one per module that had grown a pager, each with
 * a different window size and its own way of rebuilding the query string. Two of
 * them dropped the active filter on page two, because the string was assembled
 * from a hand-kept list of parameters that nobody updated when a filter was
 * added.
 *
 * Renders nothing when there is only one page: a pager under six rows is
 * furniture, and a control that never does anything teaches people to stop
 * looking at that part of the screen.
 */

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

$pager = isset($pager) && is_array($pager) ? $pager : null;

if ($pager === null || ($pager['pages'] ?? 1) <= 1) {
    return;
}

/* Every other parameter carried through, so paging never resets a filter. */
$query = App\Core\Paginator::query();
$base  = '?' . ($query !== '' ? $query . '&' : '');

$page  = (int) $pager['page'];
$pages = (int) $pager['pages'];

/* A window of at most seven numbers, kept centred. Beyond that the row wraps on
   a phone and the numbers stop being a row. */
$start = max(1, min($page - 3, $pages - 6));
$end   = min($pages, max($page + 3, 7));
?>
<nav class="pager" aria-label="Pages">
    <p class="pager__count"><?= n((int) $pager['from']) ?>&ndash;<?= n((int) $pager['to']) ?> of <?= n((int) $pager['total']) ?></p>

    <div class="pager__links">
        <?php /* Disabled rather than hidden at the ends: a row of numbers that
                 changes width as you page through it makes the number you were
                 about to press move. */ ?>
        <?php if ($page > 1): ?>
            <a class="pager__step" href="<?= e($base . 'page=' . ($page - 1)) ?>" rel="prev" aria-label="Previous page">
                <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
            </a>
        <?php else: ?>
            <span class="pager__step is-disabled" aria-hidden="true">
                <i class="fa-solid fa-chevron-left"></i>
            </span>
        <?php endif; ?>

        <?php if ($start > 1): ?>
            <a href="<?= e($base . 'page=1') ?>">1</a>
            <?php if ($start > 2): ?><span class="pager__gap">&hellip;</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($p = $start; $p <= $end; $p++): ?>
            <?php if ($p === $page): ?>
                <span class="is-current" aria-current="page"><?= n($p) ?></span>
            <?php else: ?>
                <a href="<?= e($base . 'page=' . $p) ?>"><?= n($p) ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($end < $pages): ?>
            <?php if ($end < $pages - 1): ?><span class="pager__gap">&hellip;</span><?php endif; ?>
            <a href="<?= e($base . 'page=' . $pages) ?>"><?= n($pages) ?></a>
        <?php endif; ?>

        <?php if ($page < $pages): ?>
            <a class="pager__step" href="<?= e($base . 'page=' . ($page + 1)) ?>" rel="next" aria-label="Next page">
                <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
            </a>
        <?php else: ?>
            <span class="pager__step is-disabled" aria-hidden="true">
                <i class="fa-solid fa-chevron-right"></i>
            </span>
        <?php endif; ?>
    </div>
</nav>
<?php
unset($pager, $query, $base, $page, $pages, $start, $end);
