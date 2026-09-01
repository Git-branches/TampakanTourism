<?php
/**
 * TourSync — one dialog, filled when it opens.
 *
 * Include this once near the end of a list page, then mark the links that
 * should open in it with `data-modal-page`. The script fetches the link's own
 * href with ?modal=1 — which is all it takes for a page guarded by
 * is_modal_request() to render its body and skip the shell — and puts the
 * result in here.
 *
 * WHY FETCH RATHER THAN RENDER INLINE. The house pattern elsewhere is to
 * extract a partial and include it. That works when there is one record; it
 * does not work on a list, because a form's fields carry fixed ids and
 * rendering it once per row would put a dozen elements called id="name" on one
 * page and break every <label for> on it. Rewriting a working form to avoid
 * that is a bigger change than this. X-Frame-Options is DENY, so a dialog
 * cannot iframe an admin page either.
 *
 * WHERE TO PUT IT. Above any sheet on the page that renders the SAME form —
 * the Add sheet on both the destinations and the managers lists does. While
 * this dialog is open the page really does hold two elements with each of that
 * form's ids, and document.getElementById answers with whichever comes first;
 * being above the Add sheet is what makes that the fetched copy. The script
 * empties this on close, so the duplicates exist only while it is open and the
 * Add sheet is shut.
 */
?>
<dialog class="sheet sheet--wide" id="pageModal">
    <header class="sheet__head">
        <h2 id="pageModalTitle"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Loading&hellip;</h2>
        <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
    </header>

    <div class="sheet__body" id="pageModalBody"></div>
</dialog>
