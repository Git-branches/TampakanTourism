<?php
/**
 * TourSync — the cookie notice for the public site.
 *
 * WHAT THIS IS NOT: a consent gate. A consent banner exists to ask permission
 * for cookies that need permission — analytics, advertising, personalisation,
 * anything that follows a visitor. This site sets exactly one cookie,
 * `toursync_sid`, and it is strictly necessary: it carries the CSRF token that
 * makes the logbook, the feedback form, the tour guide request and the chatbot
 * safe to submit. It is HttpOnly, SameSite=Lax, and it expires when the browser
 * closes. There is no advertising, no analytics, no tracking pixel, and no
 * third-party cookie anywhere on the site.
 *
 * So there is nothing to accept or reject, and offering "Accept all / Reject
 * optional" would be theatre: two buttons that do the same nothing. What is
 * owed to the visitor is the truth about what is stored, which is what this is.
 * If an analytics tool is ever added, THIS is the file that must grow real
 * consent buttons, and the tool must stay switched off until one is pressed.
 *
 * The dismissal is remembered in localStorage rather than in a cookie: setting
 * a cookie to record that somebody read a notice about cookies would add the
 * very thing the notice says the site avoids.
 */

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}
?>
<div class="cookie-note" id="cookieNote" hidden role="region" aria-label="Cookie notice">
    <div class="cookie-note__body">
        <h2 class="cookie-note__title"><i class="fa-solid fa-cookie-bite" aria-hidden="true"></i> We use cookies</h2>
        <p class="cookie-note__text">
            This site uses one cookie, and only because the forms would not be safe without it.
            It keeps your session and the security token that protects the visitor logbook,
            feedback, tour guide requests and the chat assistant.
            <strong>No advertising, analytics or tracking cookies are used</strong>, so there is
            nothing here to opt out of.
        </p>

        <details class="cookie-note__details" id="cookieNoteDetails">
            <summary>What is stored</summary>
            <div class="cookie-note__table-wrap">
                <table class="cookie-note__table">
                    <thead>
                        <tr><th>Name</th><th>Purpose</th><th>Kept for</th><th>Type</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>toursync_sid</code></td>
                            <td>Your session and the CSRF token that proves a form was submitted from this site</td>
                            <td>Until you close the browser</td>
                            <td>Strictly necessary</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="cookie-note__small">
                The cookie cannot be read by JavaScript (HttpOnly) and is not sent to other sites
                (SameSite=Lax). Your choice to dismiss this notice is remembered in your browser's
                own storage, not in a cookie. To clear either, use your browser's site-data settings.
            </p>
        </details>
    </div>

    <div class="cookie-note__actions">
        <button type="button" class="btn btn-sm cookie-note__more" data-cookie-more>What is stored</button>
        <button type="button" class="btn btn-sm cookie-note__ok" data-cookie-dismiss>Got it</button>
    </div>
</div>

<script>
/* Shown only when it has not been dismissed, and never in a way that blocks the
   page: a notice about a strictly necessary cookie must not stand between a
   visitor and the destination they came to read. */
(function () {
    var note = document.getElementById('cookieNote');

    if (!note) { return; }

    var KEY = 'toursync.cookie-notice';

    function seen() {
        try { return localStorage.getItem(KEY) === 'seen'; } catch (e) { return false; }
    }

    function remember() {
        try { localStorage.setItem(KEY, 'seen'); } catch (e) { /* private mode: it reappears, which is harmless */ }
    }

    function open(showDetails) {
        note.hidden = false;
        if (showDetails) {
            var d = document.getElementById('cookieNoteDetails');
            if (d) { d.open = true; }
        }
    }

    if (!seen()) {
        /* After first paint, so it never delays the page the visitor asked for. */
        window.setTimeout(function () { open(false); }, 600);
    }

    note.addEventListener('click', function (event) {
        if (event.target.closest('[data-cookie-dismiss]')) {
            note.hidden = true;
            remember();
        }

        if (event.target.closest('[data-cookie-more]')) {
            var d = document.getElementById('cookieNoteDetails');
            if (d) { d.open = !d.open; }
        }
    });

    /* The footer link, so the notice can be read again after it is dismissed. */
    document.addEventListener('click', function (event) {
        var link = event.target.closest('[data-cookie-open]');

        if (!link) { return; }

        event.preventDefault();
        open(true);
        note.scrollIntoView({ block: 'nearest' });
    });
})();
</script>
