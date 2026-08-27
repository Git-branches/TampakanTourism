<?php
declare(strict_types=1);

/**
 * TourSync — the photograph viewer.
 *
 * One element, included by any page that renders [data-lightbox] links.
 *
 * IT LIVED IN index.php ALONE, and the destination page — the only page with a
 * real photo gallery on it — never had it. initLightbox() bails out when the
 * element is missing, so every thumbnail on a destination page fell through to
 * its own href and navigated the visitor out of the site to a bare JPEG, with
 * nothing but the back button to return. The gallery has never worked as a
 * gallery.
 *
 * Kept as a partial rather than copied into the second page for the same reason
 * the page header is a partial: a copy is a thing that drifts, and this one is
 * wired to script.js by four hard-coded ids that both copies would have to keep
 * in step.
 */

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}
?>
<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="Image viewer" hidden>
    <button class="lightbox__close" data-lb-close aria-label="Close viewer">
        <i class="fa-solid fa-xmark"></i>
    </button>
    <button class="lightbox__nav lightbox__nav--prev" data-lb-prev aria-label="Previous image">
        <i class="fa-solid fa-chevron-left"></i>
    </button>
    <button class="lightbox__nav lightbox__nav--next" data-lb-next aria-label="Next image">
        <i class="fa-solid fa-chevron-right"></i>
    </button>
    <figure class="lightbox__stage">
        <img src="" alt="" id="lightboxImg">
        <figcaption id="lightboxCaption"></figcaption>
    </figure>
    <span class="lightbox__counter" id="lightboxCounter"></span>
</div>
