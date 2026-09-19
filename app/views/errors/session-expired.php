<?php
/**
 * TourSync — what an officer sees when a form is rejected by the CSRF guard.
 *
 * This replaced a line of plain text:
 *
 *     Your session expired or the form was submitted from an untrusted page.
 *     Please reload and try again.
 *
 * It was accurate and it was the only thing on the screen — black Times New
 * Roman on white, no heading, no seal, no way forward. The office met it by
 * leaving the sign-in page open over a break and then typing their password,
 * which is the ordinary way to meet it, and it reads as a page that escaped
 * from a developer's machine rather than part of a municipal system.
 *
 * WHAT IT DOES NOT DO: weaken the guard. The request is still refused, still
 * 403, and still nothing is written. Only the reply is different.
 *
 * SELF-CONTAINED, LIKE 500.php. The styles are inline and the markup depends on
 * no partial, no stylesheet and no session — the session is the thing that has
 * just gone. A page that needs what failed cannot report that it failed.
 *
 * EXPECTS
 *   $csrfReturnUrl    where the primary button should go
 *   $csrfReturnLabel  what that button should say
 *   $csrfAltUrl       a second way out, or '' for none
 *   $csrfAltLabel     what that one says
 *   $csrfLogo         a URL for the Tourism Office mark, or '' to leave it out
 *
 * EVERY WAY OUT IS A LINK, AND THAT IS DELIBERATE. The first draft offered
 * "Reload this page", which is the obvious thing to put on an error page and
 * wrong here: this page is the response to a POST, so reloading re-submits it
 * and the browser asks "Confirm Form Resubmission" — a second confusing screen
 * stacked on the one being apologised for. history.back() lands in the same
 * place. A plain GET link cannot.
 */

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

$csrfReturnUrl   = $csrfReturnUrl   ?? '/';
$csrfReturnLabel = $csrfReturnLabel ?? 'Go to the sign-in page';
$csrfAltUrl      = $csrfAltUrl      ?? '';
$csrfAltLabel    = $csrfAltLabel    ?? '';
$csrfLogo        = $csrfLogo        ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Session Timed Out — Tampakan Tourism Office</title>
<?php if ($csrfLogo !== ''): ?>
    <link rel="icon" href="<?= htmlspecialchars($csrfLogo, ENT_QUOTES, 'UTF-8') ?>" type="image/png">
<?php endif; ?>
<style>
    *, *::before, *::after { box-sizing: border-box; }

    body {
        margin: 0;
        min-height: 100vh;
        display: grid;
        place-items: center;
        padding: 2rem 1.25rem;
        background: #F2F6F3;
        color: #16211A;
        font-family: 'Poppins', -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
        line-height: 1.65;
    }

    .card {
        width: 100%;
        max-width: 30rem;
        background: #fff;
        border: 1px solid #DCE5DE;
        border-radius: 14px;
        padding: 2.4rem 2rem;
        box-shadow: 0 2px 14px rgba(22, 33, 26, .08);
        text-align: center;
    }

    .seal { width: 58px; height: 58px; object-fit: contain; margin-bottom: 1.1rem; }

    /* An amber clock, not a red cross. Nothing has gone wrong and nothing was
       lost — a session simply ended, which is the system doing its job. A red
       error mark would tell an officer they had broken something. */
    .mark {
        width: 62px; height: 62px;
        margin: 0 auto 1.15rem;
        border-radius: 50%;
        background: #FDF3E3;
        color: #8A5A00;
        display: grid;
        place-items: center;
    }
    .mark svg { width: 30px; height: 30px; }

    h1 { font-size: 1.28rem; font-weight: 700; margin: 0 0 .55rem; color: #123D1E; }

    p { font-size: .92rem; color: #43514A; margin: 0 0 .9rem; }
    p:last-of-type { margin-bottom: 0; }

    .actions {
        margin-top: 1.6rem;
        display: flex;
        flex-wrap: wrap;
        gap: .6rem;
        justify-content: center;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        padding: .62rem 1.15rem;
        border-radius: 9px;
        border: 1px solid transparent;
        font-size: .9rem;
        font-weight: 600;
        text-decoration: none;
        cursor: pointer;
        font-family: inherit;
    }
    .btn--primary { background: #1B6B33; color: #fff; }
    .btn--primary:hover { background: #123D1E; }
    .btn--quiet { background: #fff; color: #2F3E36; border-color: #CBD8CF; }
    .btn--quiet:hover { background: #F4F8F5; }

    .foot {
        margin-top: 1.5rem;
        padding-top: 1.1rem;
        border-top: 1px solid #E8EEE9;
        font-size: .76rem;
        color: #6B7A71;
    }

    @media (prefers-reduced-motion: no-preference) {
        .card { animation: rise .25s ease-out; }
        @keyframes rise { from { opacity: 0; transform: translateY(6px); } }
    }
</style>
</head>
<body>

<main class="card">
    <?php if ($csrfLogo !== ''): ?>
        <img class="seal" src="<?= htmlspecialchars($csrfLogo, ENT_QUOTES, 'UTF-8') ?>"
             alt="Tampakan Municipal Tourism Office" width="58" height="58">
    <?php endif; ?>

    <div class="mark" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
             stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="9"></circle>
            <path d="M12 7v5l3 2"></path>
        </svg>
    </div>

    <h1>Your session timed out</h1>

    <?php /* WHAT HAPPENED, WHAT IT MEANS, WHAT TO DO — in that order, and in
             the words an officer would use. "The form was submitted from an
             untrusted page" is what the guard checks; it is not what happened
             to them. */ ?>
    <p>
        You were signed out after a period of inactivity, so the form could not be
        submitted. This is a security measure &mdash; it protects the tourism records
        when a computer is left unattended.
    </p>
    <p>
        <strong>Nothing was saved and nothing was lost.</strong>
        Sign in again and the page you were on will still be there.
    </p>

    <div class="actions">
        <a class="btn btn--primary" href="<?= htmlspecialchars($csrfReturnUrl, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($csrfReturnLabel, ENT_QUOTES, 'UTF-8') ?>
        </a>
        <?php if ($csrfAltUrl !== ''): ?>
            <a class="btn btn--quiet" href="<?= htmlspecialchars($csrfAltUrl, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($csrfAltLabel, ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endif; ?>
    </div>

    <p class="foot">
        If you were not away from your computer, the page may simply have been open
        for a long time. Signing in again will fix it.
    </p>
</main>

</body>
</html>
