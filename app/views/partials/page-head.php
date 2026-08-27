<?php
declare(strict_types=1);

/**
 * TourSync — the public page masthead.
 *
 * ONE HEADER, USED BY EVERY INTERIOR PUBLIC PAGE. It exists because the markup
 * was previously copied into each page that wanted it, and copies drift: the
 * tour guide page grew a white masthead of its own while the map kept the green
 * one, and the two sat one click apart looking like different websites.
 *
 * WHY THE H1 COLOUR IS SET IN CSS AND NOT INHERITED
 *
 * The global rule `h1 { color: var(--ink) }` is near-black and beats anything
 * inherited from this banner. A version of the guide page relied on inheriting
 * white from the parent and shipped with a title that was almost invisible
 * against the dark green. `.page-head h1` states white explicitly; nothing here
 * should ever depend on inheritance for legibility.
 *
 * USAGE — set these before requiring, then unset nothing; the partial cleans up
 * after itself so a second header on one page cannot inherit the first's state.
 *
 *     $head = [
 *         'title'  => 'Request a Tour Guide',
 *         'sub'    => 'One short sentence saying what the page is for.',
 *         'crumbs' => [
 *             ['label' => 'Home', 'href' => base_url('/')],
 *             ['label' => 'Tour Guide'],          // no href = current page
 *         ],
 *     ];
 *     require __DIR__ . '/app/views/partials/page-head.php';
 *
 * 'sub' and 'crumbs' are both optional. Everything is escaped here, so callers
 * pass plain text — there is deliberately no slot for raw HTML.
 *
 * THE 'standalone' FLAG, and when to set it
 *
 * The site navbar is ON by default on interior pages. Two pages switch it off
 * and each has a reason of its own, not a site-wide rule:
 *
 *   destination.php  a full-bleed cover photograph, which a white bar crops
 *   map.php          the map wants every pixel of viewport height it can get
 *
 * On those pages this banner is the only header and the breadcrumb is the only
 * way back, so it takes extra top padding and a heavier breadcrumb. That is
 * what 'standalone' => true selects.
 *
 * Leave it false when the navbar is present. Setting both gives the page two
 * headers stacked, and pads for a navbar that is already there.
 */

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

$head = isset($head) && is_array($head) ? $head : [];

$headTitle  = trim((string) ($head['title'] ?? ''));
$headSub    = trim((string) ($head['sub'] ?? ''));
$headCrumbs = isset($head['crumbs']) && is_array($head['crumbs']) ? $head['crumbs'] : [];

/* Defaults to whether the navbar was actually suppressed, so a page that turns
   the navbar off gets the right padding without having to say so twice — and
   an explicit flag still wins when a page wants to differ. */
$headStandalone = array_key_exists('standalone', $head)
    ? (bool) $head['standalone']
    : (isset($showNavbar) && $showNavbar === false);

/* An optional Font Awesome icon on the subtitle — a calendar beside a date
   reads faster than the word "Published".
 *
 * Whitelisted to `fa-` class names rather than accepted as markup. The rest of
 * this partial escapes everything precisely so there is no way to inject a tag
 * through it, and an icon slot that took raw HTML would undo that for the sake
 * of one calendar. */
$headIcon = trim((string) ($head['icon'] ?? ''));

if ($headIcon !== '' && !preg_match('/^fa-[a-z0-9- ]{1,60}$/', $headIcon)) {
    $headIcon = '';
}
?>
<header class="page-head <?= $headStandalone ? 'page-head--top' : '' ?>">
    <div class="container">

        <?php if ($headCrumbs !== []): ?>
            <nav aria-label="Breadcrumb" class="crumbs">
                <?php foreach ($headCrumbs as $i => $crumb): ?>
                    <?php if ($i > 0): ?>
                        <i class="fa-solid fa-angle-right" aria-hidden="true"></i>
                    <?php endif; ?>

                    <?php if (!empty($crumb['href'])): ?>
                        <a href="<?= e((string) $crumb['href']) ?>"><?= e((string) ($crumb['label'] ?? '')) ?></a>
                    <?php else: ?>
                        <?php /* The current page. Marked for screen readers as
                                 well as visually — a breadcrumb whose last item
                                 is just text reads as another link otherwise. */ ?>
                        <span aria-current="page"><?= e((string) ($crumb['label'] ?? '')) ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <h1><?= e($headTitle) ?></h1>

        <?php if ($headSub !== ''): ?>
            <p>
                <?php if ($headIcon !== ''): ?>
                    <i class="<?= e($headIcon) ?>" aria-hidden="true"></i>
                <?php endif; ?>
                <?= e($headSub) ?>
            </p>
        <?php endif; ?>
    </div>
</header>
<?php
/* Cleared so a page that renders two headers cannot pick up the first one's
   title by accident. */
unset($head, $headTitle, $headSub, $headCrumbs, $headStandalone, $headIcon);
