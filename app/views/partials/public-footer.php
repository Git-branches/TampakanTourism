<?php
/**
 * Shared public footer for pages other than the landing page.
 */

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}
?>
<footer class="footer">
    <div class="footer__top">
        <div class="container">
            <div class="row g-4 g-lg-5">

                <div class="col-lg-5 col-md-6">
                    <div class="footer__brand">
                        <img src="<?= e(base_url('assets/img/tampakan_logo.png')) ?>"
                             alt="Official Seal of the Municipality of Tampakan" width="70" height="70">
                    </div>
                    <h4 class="footer__title">Municipality of Tampakan</h4>
                    <p class="footer__text">
                        The official tourism portal of Tampakan, South Cotabato. Promoting sustainable,
                        community-based highland tourism for every visitor and every barangay.
                    </p>
                </div>

                <div class="col-lg-3 col-md-6 col-6">
                    <h4 class="footer__title">Explore</h4>
                    <ul class="footer__links">
                        <li><a href="<?= e(base_url('/')) ?>"><i class="fa-solid fa-angle-right"></i>Home</a></li>
                        <li><a href="<?= e(destinations_url()) ?>"><i class="fa-solid fa-angle-right"></i>Destinations</a></li>
                        <li><a href="<?= e(base_url('/#map')) ?>"><i class="fa-solid fa-angle-right"></i>Tourist Map</a></li>
                        <li><a href="<?= e(base_url('/#travel-guide')) ?>"><i class="fa-solid fa-angle-right"></i>Travel Guide</a></li>
                    </ul>
                </div>

                <div class="col-lg-4 col-md-6">
                    <h4 class="footer__title">Tourism Office</h4>
                    <ul class="footer__contact">
                        <li>
                            <i class="fa-solid fa-location-dot"></i>
                            <span><?= e((string) config('office.address', 'Tampakan Municipal Hall, Kamagong St., Brgy. Poblacion, Tampakan, South Cotabato')) ?></span>
                        </li>
                        <?php if (config('office.phone')): ?>
                            <li><i class="fa-solid fa-phone"></i><span><?= e((string) config('office.phone')) ?></span></li>
                        <?php endif; ?>
                        <?php if (config('office.email')): ?>
                            <li><i class="fa-regular fa-envelope"></i><span><?= e((string) config('office.email')) ?></span></li>
                        <?php endif; ?>
                        <li><i class="fa-regular fa-clock"></i><span>Monday – Friday, 8:00 AM – 5:00 PM</span></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="footer__bottom">
        <div class="container">
            <div class="d-md-flex justify-content-between align-items-center text-center text-md-start">
                <p class="mb-2 mb-md-0">
                    &copy; <?= date('Y') ?> Municipality of Tampakan, South Cotabato, Philippines.
                    All rights reserved.
                </p>
                <ul class="footer__legal">
                    <li><a href="<?= e(base_url('/')) ?>">Home</a></li>
                    <li><a href="<?= e(base_url('/admin/login.php')) ?>">Admin</a></li>
                </ul>
            </div>
        </div>
    </div>
</footer>

<a href="#top" id="backToTop" class="back-to-top" aria-label="Back to top">
    <i class="fa-solid fa-chevron-up"></i>
</a>
