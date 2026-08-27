<?php
declare(strict_types=1);

/**
 * TourSync — the settings-style section header.
 *
 * An icon in a rounded chip, a title, a one-line subtitle saying what the
 * section governs, an optional count, and a collapse control.
 *
 * It began as a closure inside admin/settings/index.php. Three screens now want
 * it — Settings, User Accounts and My Account — and three copies of a header is
 * three chances for one of them to drift, which is what makes a group of pages
 * feel assembled rather than designed.
 *
 * The SUBTITLE is the part that earns this. A settings section has no rows
 * underneath to explain it, so "Records" has to carry "when the logbook flags an
 * arrival for review" beside it or the officer opens all five tabs looking for
 * the one they wanted.
 */

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

if (!function_exists('section_head')) {
    /**
     * @param string $icon     Font Awesome class, e.g. 'fa-building-columns'
     * @param string $title    Already-escaped HTML — callers pass entities like &amp;
     * @param string $subtitle Already-escaped HTML
     * @param string $badge    Optional count, escaped here
     * @param string $tone     Pill tone: qr | ok | flag | void
     */
    function section_head(
        string $icon,
        string $title,
        string $subtitle = '',
        string $badge = '',
        string $tone = 'qr'
    ): void {
        ?>
        <header class="panel__head set-head">
            <span class="set-head__icon"><i class="fa-solid <?= e($icon) ?>"></i></span>

            <div class="set-head__text">
                <h2><?= $title ?></h2>
                <?php if ($subtitle !== ''): ?>
                    <p><?= $subtitle ?></p>
                <?php endif; ?>
            </div>

            <?php if ($badge !== ''): ?>
                <span class="pill pill--<?= e($tone) ?>"><?= e($badge) ?></span>
            <?php endif; ?>

            <?php /* type="button", explicitly. A bare <button> inside a form defaults
                     to type="submit", and eleven of those would mean eleven ways to
                     save the page by pressing Enter in a text field. */ ?>
            <button type="button" class="set-head__toggle" data-collapse
                    aria-expanded="true" aria-label="Collapse this section">
                <i class="fa-solid fa-chevron-up" aria-hidden="true"></i>
            </button>
        </header>
        <?php
    }
}
