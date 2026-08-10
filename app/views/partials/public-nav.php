<?php
/**
 * The public navigation — used by every page, including the homepage.
 *
 * One navbar in two visual states, not two navbars:
 *
 *   transparent  the homepage, laid over the hero photograph and turning
 *                solid on scroll
 *   solid        every other page, where there is no hero behind it
 *
 * Set $navTransparent = true before including this on the homepage. The link
 * list itself is identical everywhere and comes from public_nav(), so the same
 * label always leads to the same place.
 */

use App\Core\Auth;

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

$navTransparent = $navTransparent ?? false;
$current        = basename($_SERVER['SCRIPT_NAME'] ?? '');
$navItems       = public_nav();

/* Kept separate from $navTransparent rather than reusing it. The two happen to
   agree today — both are true only on the homepage — but they answer different
   questions: one is about the hero behind the navbar, the other about whether
   the office details are worth the vertical space. A page that later wants a
   hero without the utility bar, or the reverse, sets one without disturbing
   the other. */
$showTopbar     = $showTopbar ?? ($current === 'index.php');

/* Pages that carry their own full-bleed photograph can suppress the navbar
   entirely by setting $showNavbar = false before including this.
 *
 * The rest of the partial still runs, which is the point of the flag rather
   than simply not including the file: the reading-progress bar below belongs
   to every page whether or not it has a header, and a page that skips the
   include loses it silently. */
$showNavbar     = $showNavbar ?? true;

/* A destination or announcement detail page is still "inside" its section, so
   the parent link stays highlighted rather than nothing being marked.
 *
 * "destinations" is a token rather than a filename now. The catalogue moved
 * into a section of the homepage when destinations.php was retired, so there is
 * no file left to name — but a visitor reading about Jadas Falls should still
 * see Destinations lit up in the navbar. */
$activeMatch = match ($current) {
    'destination.php'  => 'destinations',
    'announcement.php' => 'announcements',
    default            => $current,
};
?>
<!-- Reading progress. Lives with the header rather than on one page, so the
     scroll behaviour is identical everywhere. -->
<div class="scroll-progress" role="presentation"><span id="scrollProgress"></span></div>

<!-- Utility bar — homepage only.
     The address, hours, and social links are a welcome, not a tool: they
     belong where somebody is deciding whether to visit at all. On the
     destination, map, and announcement pages the visitor is already working
     with a catalogue, a map, or an advisory, and the bar only pushes that
     content further down the screen. All of it is still in the footer of
     every page, which is where a returning visitor looks for it. -->
<?php if ($showTopbar): ?>
<div class="topbar d-none d-lg-block">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center">
            <ul class="topbar__list">
                <li><i class="fa-solid fa-location-dot"></i> Poblacion, Tampakan, South Cotabato</li>
                <?php if (setting('office_phone')): ?>
                    <li><i class="fa-solid fa-phone"></i> <?= e((string) setting('office_phone')) ?></li>
                <?php endif; ?>
                <li><i class="fa-regular fa-clock"></i> Monday &ndash; Friday, 8:00 AM &ndash; 5:00 PM</li>
            </ul>
            <ul class="topbar__list topbar__list--social">
                <li><a href="#" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a></li>
                <li><a href="#" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a></li>
                <?php if (setting('office_email')): ?>
                    <li><a href="mailto:<?= e((string) setting('office_email')) ?>" aria-label="Email"><i class="fa-solid fa-envelope"></i></a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- The sticky lives on <header>, not on the <nav> inside it.
     A sticky element can only travel within its own parent's box, and this
     header is exactly as tall as the navbar — so sticking the nav itself gave
     it nowhere to go and it simply scrolled away. The homepage escapes this by
     using position:fixed to overlay the hero; every other page sticks the
     header instead, so the bar follows without covering the content. -->
<?php if ($showNavbar): ?>
<header class="site-header <?= $navTransparent ? 'site-header--overlay' : 'site-header--sticky' ?>">
<nav class="navbar navbar-expand-xl main-nav <?= $navTransparent ? '' : 'is-solid' ?>" id="mainNav">
    <div class="container">

        <a class="navbar-brand brand" href="<?= e($navTransparent ? '#home' : base_url('/')) ?>">
            <span class="brand__logos">
                <img src="<?= e(base_url('assets/img/tampakan_logo.jpg')) ?>"
                     alt="Official Seal of the Municipality of Tampakan, Province of South Cotabato"
                     width="58" height="58">
            </span>
            <span class="brand__text">
                <strong>Tampakan</strong>
                <small>Municipal Tourism Office</small>
            </span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu"
                aria-controls="navMenu" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon-custom"><i class="fa-solid fa-bars"></i></span>
        </button>

        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav ms-auto align-items-xl-center">

                <?php foreach ($navItems as $item):
                    $isActive = $item['match'] !== '' && $item['match'] === $activeMatch; ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $isActive ? 'active' : '' ?>"
                           href="<?= e($item['href']) ?>"
                           <?= $isActive ? 'aria-current="page"' : '' ?>>
                            <?= e($item['label']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>

                <!-- Admin entry point. The dashboard itself is a separate module
                     with its own gate; this is only the door. -->
                <li class="nav-item nav-item--cta">
                    <a class="btn btn-admin" href="<?= e(base_url('/admin/login.php')) ?>">
                        <i class="fa-solid fa-lock"></i>
                        <?= Auth::check() ? 'Admin Panel' : 'Admin Login' ?>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>
</header>
<?php endif; ?>
