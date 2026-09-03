<?php
/**
 * TourSync — the evidence viewer.
 *
 * WHAT THIS REPLACES
 *
 * Clicking a photograph used to open api/inspections/photo.php in a new tab: a
 * bare image on a black background, with the raw query string in the address
 * bar and the browser's own title reading "photo.php (1080x1920)". It worked,
 * and it looked like the system had handed the manager over to something else.
 *
 * WHY A <dialog> AND NOT A DIV
 *
 * The photograph is usually opened from INSIDE #pageModal, which is itself a
 * dialog opened with showModal() — and a showModal() dialog lives in the
 * browser's top layer, where no z-index can reach it. A div lightbox would have
 * rendered underneath the very dialog that launched it. The top layer is a
 * stack, so a second showModal() sits above the first, which is the only
 * arrangement that works here.
 *
 * THE LINK STILL HAS ITS href.
 *
 * With JavaScript off the anchor opens the image directly, exactly as before.
 * The viewer is an improvement layered on top, not a replacement for the only
 * way to see the evidence.
 *
 * MANAGER ONLY. The officer's review screen renders the same photo grid and is
 * finished; its markup carries no data-lightbox, and the script below binds to
 * that attribute alone. This file is required from the manager foot and nowhere
 * else.
 */

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}
?>
<dialog class="mgr-lightbox" id="mgrLightbox" aria-label="Evidence photo">
    <button type="button" class="mgr-lightbox__close" data-lightbox-close aria-label="Close">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
    </button>

    <figure class="mgr-lightbox__frame">
        <img id="mgrLightboxImg" src="" alt="">
        <figcaption id="mgrLightboxCap"></figcaption>
    </figure>
</dialog>

<script>
(function () {
    var box = document.getElementById('mgrLightbox');

    if (!box || typeof box.showModal !== 'function') { return; }

    var img = document.getElementById('mgrLightboxImg');
    var cap = document.getElementById('mgrLightboxCap');

    /* Delegated on the document, because the photographs the manager clicks
       most often are the ones inside #pageModal — injected after this script
       ran, so nothing bound directly to them would exist yet. */
    document.addEventListener('click', function (event) {
        var link = event.target.closest && event.target.closest('a[data-lightbox]');

        if (!link) { return; }

        /* Let a middle click or ctrl-click do what the person asked for: open
           the raw image in a tab. Only a plain left click is intercepted. */
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) { return; }

        event.preventDefault();

        img.src = link.getAttribute('href');
        img.alt = link.getAttribute('data-caption') || 'Evidence photo';
        cap.textContent = link.getAttribute('data-caption') || '';
        cap.hidden = cap.textContent === '';

        box.showModal();
    });

    document.addEventListener('click', function (event) {
        if (!box.open) { return; }

        /* The backdrop counts as the dialog itself, so a click that landed on
           the element and not on the picture is a click outside it. */
        if (event.target === box || (event.target.closest && event.target.closest('[data-lightbox-close]'))) {
            box.close();
        }
    });

    /* Drop the source on close so a large photograph is not held in memory
       behind a dialog nobody can see, and so reopening cannot flash the
       previous one while the new file loads. */
    box.addEventListener('close', function () {
        img.src = '';
    });
})();
</script>
