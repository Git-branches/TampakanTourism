<?php
declare(strict_types=1);

/**
 * The tour guide ID card, served through Apache.
 *
 * WHAT THIS GUARDS
 *
 * That the back of the card shows only the back.
 *
 * Reported from a phone on the office LAN: flipping to the back left the front
 * painted through it in mirror writing — the crest, the photograph and the
 * guide's name, all reversed, on top of the real back. Desktop Chrome never
 * showed it, so it survived every check made at a laptop.
 *
 * The cause was one missing declaration. backface-visibility is not inherited.
 * It was set on .tgid-face, but everything visible lives in .tgid-card, which
 * has overflow: hidden and a box-shadow — enough for WebKit to promote it to
 * its own compositing layer, and a composited element paints its own backface
 * no matter what its ancestor asked for.
 *
 * The fix is in two parts and this suite guards both, because the first is a
 * rendering hint and the second is what actually makes it true:
 *
 *   1. .tgid-card carries backface-visibility: hidden itself.
 *   2. The far face is taken out of the paint with visibility, which involves
 *      no 3D at all and so cannot be flattened away by a browser.
 *
 * And the part that is easy to break while fixing the above: PAPER GETS BOTH
 * FACES. The print stylesheet lays them side by side, so it has to lift the
 * visibility lock — with !important, because the screen rule is written with
 * three classes and would otherwise outrank the dialog-print path and send one
 * blank card to the printer. That failure would only ever be seen on paper,
 * which is the one place nobody re-checks.
 *
 * It goes over real HTTP so the assertions are made against what a browser is
 * actually sent, not against the source file.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;

echo "\n=== tour guide ID card ===\n\n";

if (!test_server_up()) {
    echo "  Apache is not answering — skipped.\n";
    test_finish();
}

[$sid] = test_sign_in_officer();

/* Any guide with a card will do; the CSS is the same for all of them. */
$guide = Database::first('SELECT id, full_name FROM tour_guides ORDER BY id LIMIT 1');

if ($guide === null) {
    echo "  No tour guide on file — nothing to render, skipped.\n";
    test_finish();
}

$id   = (int) $guide['id'];
$html = test_get_as($sid, '/admin/tour-guides/id-card.php?id=' . $id);

echo "  rendering the card for {$guide['full_name']} (id {$id})\n\n";

/* ---- it rendered at all ------------------------------------------------- */

check('the page has a front face', str_contains($html, 'tgid-face--front'), true);
check('the page has a back face',  str_contains($html, 'tgid-face--back'),  true);

/* Both faces are always in the markup — the flip hides one, it does not remove
   it — so a missing face means the card itself failed to build. */
check('and the flip container holding them',
    str_contains($html, 'id="tgidFlip"'), true);

/* ---- 1. the declaration that was missing -------------------------------- */

/* Comments are served to the browser and there are plenty of them in this file,
   so they are stripped before anything is matched — otherwise every assertion
   here breaks the next time someone explains a rule. */
$squash = static function (string $s): string {
    $s = (string) preg_replace('#/\*.*?\*/#s', '', $s);
    return (string) preg_replace('/\s+/', ' ', $s);
};
$css = $squash($html);

/* Every `.tgid-face { … }` rule in the page, screen and print alike. Anchoring
   to "@media print" instead looked right and was not: the admin layout has its
   own print block earlier in the document, so a lazy .*? from the first one ran
   forward and matched the SCREEN rule — a green tick for the wrong text. */
preg_match_all('/\.tgid-face \{([^}]*)\}/', $css, $faceRules);
$faceRules = $faceRules[1];

check('.tgid-card hides its own backface',
    (bool) preg_match('/\.tgid-card \{[^}]*backface-visibility: hidden/', $css), true);

check('and it does so for WebKit too, which is where it broke',
    (bool) preg_match('/\.tgid-card \{[^}]*-webkit-backface-visibility: hidden/', $css), true);

/* ---- 2. the lock that does not depend on 3D ----------------------------- */

check('showing the front takes the back out of the paint',
    str_contains($css, '.tgid-flip:not(.is-back) .tgid-face--back { visibility: hidden; }'), true);

check('showing the back takes the FRONT out of the paint',
    str_contains($css, '.tgid-flip.is-back .tgid-face--front { visibility: hidden; }'), true);

/* The swap is delayed to the midpoint of the flip so it happens while the card
   is edge-on. A zero delay would make the far face vanish before it turned. */
check('the swap is delayed to the middle of the flip',
    (bool) preg_match('/\.tgid-face \{ transition: visibility 0s linear \.3s; \}/', $css), true);

/* ---- 3. paper gets both faces ------------------------------------------- */

check('the print stylesheet lifts the visibility lock',
    (bool) array_filter($faceRules,
        static fn (string $r): bool => str_contains($r, 'visibility: visible !important')), true);

check('and it restores the backface it hid for the screen',
    (bool) preg_match('/@media print \{.*?\.tgid-card \{[^}]*backface-visibility: visible/s', $css), true);

/* The dialog-print path hides the whole document and turns the card back on.
   Its selector is two classes and an element; the screen rule that hides a face
   is three classes and would win without the !important checked above. */
check('the dialog-print path still turns the card back on',
    str_contains($css, 'html.tgid-printing .tgid-stage-outer * { visibility: visible; }'), true);

/* ---- 4. the same card in the record's dialog ---------------------------- */

/* view.php requires the identical partial, so a fix applied to one and not the
   other is impossible by construction — but only while it stays one file. */
$view = test_get_as($sid, '/admin/tour-guides/view.php?id=' . $id);

check('the record page carries the same card',
    str_contains($view, 'tgid-face--front') && str_contains($view, 'tgid-face--back'), true);

check('and the same backface fix, from the same partial',
    (bool) preg_match('/\.tgid-card \{[^}]*backface-visibility: hidden/', $squash($view)), true);

/* ---- 5. reduced motion -------------------------------------------------- */

/* With the animation off there is no edge-on moment to hide the swap behind,
   so the delay has to go too or the far face lingers for .3s in full view. */
check('reduced motion drops the delay as well as the flip',
    (bool) preg_match('/prefers-reduced-motion: reduce\) \{ \.tgid-flip \{ transition: none; \} \.tgid-face \{ transition: none; \} \}/', $css), true);

test_finish();
