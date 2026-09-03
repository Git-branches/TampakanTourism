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
     * @param bool   $folded   Start collapsed, for a section that is reference
     *                         material rather than something to act on. The
     *                         CALLER must also put is-collapsed on its .panel —
     *                         this function only draws the header, and a chevron
     *                         pointing down over an open panel is worse than no
     *                         default at all. Defaults false, so every existing
     *                         caller is untouched.
     */
    function section_head(
        string $icon,
        string $title,
        string $subtitle = '',
        string $badge = '',
        string $tone = 'qr',
        bool $folded = false
    ): void {
        /* A STABLE SLUG, BECAUSE THE PRE-PAINT SCRIPT HAS NO DOM TO SEARCH.
         *
         * Which sections are folded is remembered in localStorage, and the head
         * script has to turn that list into CSS selectors before <body> exists —
         * so the key has to be something a selector can hold. It used to be the
         * heading text, which cannot go in one.
         *
         * Derived from the title rather than a hand-written id, so a section
         * added later cannot forget to have one. Entities are decoded first:
         * "Local Culture &amp; Heritage" must not become "local-culture-amp-". */
        $slug = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $slug), '-'));
        ?>
        <header class="panel__head set-head" data-section="<?= e($slug) ?>">
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
                    <?= $folded ? 'data-folded-default' : '' ?>
                    aria-expanded="<?= $folded ? 'false' : 'true' ?>"
                    aria-label="<?= $folded ? 'Expand' : 'Collapse' ?> this section">
                <i class="fa-solid fa-chevron-up" aria-hidden="true"></i>
            </button>
        </header>
        <?php
    }
}
