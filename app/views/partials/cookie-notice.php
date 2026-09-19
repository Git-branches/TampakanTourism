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
<?php /* A BAR ACROSS THE FOOT OF THE PAGE, not a panel in the corner.
         -------------------------------------------------------------------
         It was a 480×280 card fixed at the bottom-left, and because it is
         `position: fixed` it sat over whatever scrolled under it. Measured with
         document.elementFromPoint: on the About section's photo grid it covered
         two of the four tiles outright, so a first-time visitor could not open
         the gallery from them — and a first-time visitor is the only person who
         ever sees this.

         The shape was wrong for what this is. As the note above says, this is
         NOT a consent gate: one strictly necessary session cookie, nothing to
         accept or reject. Something with no decision in it has no business
         commanding a quarter of the screen or blocking a control.

         A full-width bar covers a thin strip instead, and the script below adds
         matching padding to the page while it is shown, so nothing is left
         permanently underneath it. Every word, the table, and the dismissal are
         unchanged.

         The inner wrapper is what holds the content to the page's own measure —
         the bar itself must span the full width to read as a bar. */ ?>
<div class="cookie-note" id="cookieNote" hidden role="region" aria-label="Cookie notice">
  <div class="cookie-note__inner">
    <div class="cookie-note__body">
        <?php /* ONE LINE ON THE BAR, THE FULL EXPLANATION ONE CLICK AWAY.
                 A bar has a line to spend. The office's full paragraph ran to
                 three of them and made this 272px tall on a phone — a panel
                 again, in a different shape.
                 Nothing was deleted: the paragraph is the first thing inside
                 "What is stored" below, where a reader who wants it will look
                 and where it no longer costs every visitor a third of a phone
                 screen. */ ?>
        <h2 class="cookie-note__title"><i class="fa-solid fa-cookie-bite" aria-hidden="true"></i> We use cookies</h2>
        <p class="cookie-note__text">
            One cookie, and only to keep the forms secure.
            <strong>No advertising, analytics or tracking.</strong>
        </p>

        <details class="cookie-note__details" id="cookieNoteDetails">
            <summary>What is stored</summary>

            <p class="cookie-note__full">
                This site uses one cookie, and only because the forms would not be safe without it.
                It keeps your session and the security token that protects the visitor logbook,
                feedback, tour guide requests and the chat assistant.
                <strong>No advertising, analytics or tracking cookies are used</strong>, so there is
                nothing here to opt out of.
            </p>
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

    <?php /* ONE CONTROL FOR ONE THING.
             There was a "What is stored" BUTTON here as well as the <details>
             summary of the same name a few lines above — on the old stacked card
             they were far enough apart to read as different things; side by side
             on a bar they were plainly the same control twice.

             The summary is the one that stayed: it is native, it works with no
             JavaScript at all, and it is already where the disclosure opens. */ ?>
    <div class="cookie-note__actions">
        <button type="button" class="btn btn-sm cookie-note__ok" data-cookie-dismiss>Got it</button>
    </div>
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

    /* THE PAGE MAKES ROOM FOR THE BAR RATHER THAN HIDING UNDER IT.
     *
     * A fixed bar covers the foot of the document, so without this the last
     * inch of every page — the footer's own links among them — is unreachable
     * while the notice is up. Measured rather than hard-coded: the bar is one
     * line at desktop width and three on a phone, and a guessed number would be
     * wrong on one of them.
     *
     * Cleared on dismissal, so the page does not keep a gap for something that
     * is no longer there. */
    function fit() {
        var root = document.documentElement;

        if (note.hidden) {
            document.body.style.paddingBottom = '';
            root.style.setProperty('--cookie-bar-h', '0px');
            return;
        }

        var h = note.offsetHeight;

        document.body.style.paddingBottom = h + 'px';

        /* EVERYTHING ELSE IN THE BOTTOM CORNER RIDES ABOVE IT.
           The chat launcher, the chat panel and the back-to-top all add this in
           their `bottom` — see --cookie-bar-h in style.css. Without it, going
           full width put the bar's own "Got it" directly under the "Ask
           Tampakan Tourism" launcher. */
        root.style.setProperty('--cookie-bar-h', h + 'px');
    }

    function open(showDetails) {
        note.hidden = false;
        if (showDetails) {
            var d = document.getElementById('cookieNoteDetails');
            if (d) { d.open = true; }
        }
        fit();
    }

    /* RE-MEASURED WHENEVER THE BAR ACTUALLY CHANGES SIZE.
     *
     * A single measurement at open() was wrong by 24px at tablet width, every
     * time: the height was read before the web font had swapped in, the sentence
     * then reflowed from one line to two, and the page kept the padding for the
     * shorter bar. A listener per cause — fonts, resize, the disclosure — would
     * be three chances to miss a fourth.
     *
     * ResizeObserver watches the thing itself, so every cause is covered by one
     * line. The resize listener stays as the fallback for browsers without it. */
    if (typeof ResizeObserver === 'function') {
        new ResizeObserver(fit).observe(note);
    } else {
        window.addEventListener('resize', fit);
    }

    if (!seen()) {
        /* After first paint, so it never delays the page the visitor asked for. */
        window.setTimeout(function () { open(false); }, 600);
    }

    note.addEventListener('click', function (event) {
        if (event.target.closest('[data-cookie-dismiss]')) {
            note.hidden = true;
            remember();
            fit();
        }

    });

    /* The disclosure is opened by its own <summary> — the duplicate button that
       used to do it has gone. `toggle` fires either way, including when the
       footer link opens it, so the bar is re-measured whenever it changes
       height. */
    var details = document.getElementById('cookieNoteDetails');

    if (details) {
        details.addEventListener('toggle', fit);
    }

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
