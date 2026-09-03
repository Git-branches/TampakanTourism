<?php
/**
 * =============================================================================
 *  TourSync — the tour guide ID card, as a component.
 * -----------------------------------------------------------------------------
 *  Included by two callers and rendered identically by both:
 *
 *      admin/tour-guides/id-card.php   the standalone page, for printing
 *      admin/tour-guides/view.php      inside the record's dialog
 *
 *  WHY IT IS NOT AN IFRAME
 *
 *  It was, briefly. The document root sends `X-Frame-Options: DENY`, which
 *  refuses the frame even same-origin, so the dialog showed a broken-page icon.
 *  The fix is not to weaken a site-wide clickjacking header for one modal; it is
 *  to render the card where it is needed.
 *
 *  WHY EVERY CLASS IS PREFIXED tgid-
 *
 *  This markup now lands on a page that already loads Bootstrap and admin.css.
 *  Three collisions were real, not hypothetical: `.card` is Bootstrap's, `.tag`
 *  is admin.css's, and `--ink` / `--line` are admin.css custom properties that a
 *  bare `:root` block here would have repainted across every admin screen. The
 *  prefix and the `.tgid-root` scope are what make the component safe to drop
 *  anywhere.
 *
 *  EXPECTS  $guide  a row from TourGuideRosterRepository::find()
 *  OPTIONAL $tgidStandalone  true on the standalone page, where printing may
 *                            assume it owns the document
 * =============================================================================
 */

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

use App\Core\QrService;
use App\Repositories\TourGuideRosterRepository as Roster;

/* Deliberately distinct names. This file is included INTO a page that has its
   own $photo and $credentials, and quietly overwriting a caller's variables is
   the kind of bug that shows up three screens away. */
$tgidStandalone = !empty($tgidStandalone);

/* WHETHER THE COMPONENT DRAWS ITS OWN CONTROLS.
 *
 * On its own page it must — nothing else would. Inside the record's dialog it
 * must not: that dialog already has a footer with Print in it, and a second row
 * of buttons inside the scrolling body put the flip control below the fold,
 * which is the one control the card exists to offer.
 *
 * The host renders them instead, reusing the same ids — the script below binds
 * by id and does not care which element rendered them. */
$tgidControls = $tgidControls ?? true;
$tgidId         = (int) $guide['id'];
$tgidCreds      = Roster::credentialsFor($tgidId);
$tgidPhoto      = uploaded_url((string) ($guide['photo_path'] ?? ''));
$tgidVerifyUrl  = Roster::verifyUrl((string) $guide['verify_token']);
$tgidEffective  = (string) ($guide['effective_status'] ?? Roster::effectiveStatus($guide));

$tgidAsset = static fn(string $rel): bool => is_file(dirname(APP_PATH) . DIRECTORY_SEPARATOR . 'assets'
    . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . $rel);

/* Two marks, two jobs: the municipality's seal is the authority the card is
   issued under; the Tourism Office logo is the office that issued it. Both fall
   back rather than breaking — a wrong mark on an official ID is worse than a
   plain background. */
$tgidSeal      = $tgidAsset('tampakan-seal.png') ? asset('img/tampakan-seal.png') : asset('img/tampakan_logo.png');
$tgidWatermark = $tgidAsset('tourism-logo.png')  ? asset('img/tourism-logo.png')  : null;

$tgidOfficeName    = trim((string) (setting('office_name', '') ?? '')) ?: 'Municipal Tourism Office';
$tgidOfficeAddress = trim((string) (setting('office_address', '') ?? ''));
$tgidOfficePhone   = trim((string) (setting('office_phone', '') ?? ''));
$tgidOfficeEmail   = trim((string) (setting('office_email', '') ?? ''));
$tgidOfficeFb      = trim((string) (setting('office_facebook', '') ?? ''));
$tgidMunicipality  = trim((string) (setting('office_municipality', '') ?? '')) ?: 'Tampakan';
$tgidProvince      = trim((string) (setting('office_province', '') ?? '')) ?: 'South Cotabato';

/* office_municipality holds "Municipality of Tampakan", which is right on a
   letterhead and wrong twice here: the masthead already says MUNICIPAL TOURISM
   OFFICE, and the tagline would read "Promoting Municipality of Tampakan".
   Stripped for display only; the setting is left as the office typed it. */
$tgidTown = preg_replace('/^\s*(?:municipality|city|town)\s+of\s+/iu', '', $tgidMunicipality) ?: $tgidMunicipality;

/* Icons, drawn rather than fetched. This card is printed, sometimes from a
   machine that is offline, and an ID with empty boxes where its icons should be
   is not an ID. */
$tgidIcons = [
    'id'       => '<path d="M4 5h16a1 1 0 011 1v12a1 1 0 01-1 1H4a1 1 0 01-1-1V6a1 1 0 011-1z"/><circle cx="9" cy="10.5" r="2"/><path d="M6 16c0-1.6 1.3-2.6 3-2.6s3 1 3 2.6M15 9.5h3M15 12.5h3M15 15.5h2.5"/>',
    'calendar' => '<path d="M7 3v3M17 3v3M4 8.5h16M5 5.5h14a1 1 0 011 1v12a1 1 0 01-1 1H5a1 1 0 01-1-1v-12a1 1 0 011-1z"/><path d="M8 12h2M14 12h2M8 15.5h2M14 15.5h2"/>',
    'pin'      => '<path d="M12 2a7 7 0 00-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 00-7-7z"/><circle cx="12" cy="9" r="2.5" fill="#fff" stroke="none"/>',
    'phone'    => '<path d="M6.6 3h3l1.5 4-2 1.5a12 12 0 006.4 6.4l1.5-2 4 1.5v3a2 2 0 01-2.2 2A17 17 0 014.6 5.2 2 2 0 016.6 3z"/>',
    'person'   => '<circle cx="12" cy="8" r="4"/><path d="M4.5 20c0-4 3.4-6 7.5-6s7.5 2 7.5 6z"/>',
    'badge'    => '<circle cx="12" cy="9" r="5.5"/><path d="M8.5 13.5L7 21l5-2.4 5 2.4-1.5-7.5"/>',
    'facebook' => '<path d="M13.5 22v-8h2.7l.4-3.1h-3.1V8.9c0-.9.25-1.5 1.55-1.5h1.65V4.6a22 22 0 00-2.4-.12c-2.4 0-4.05 1.47-4.05 4.16v2.32H7.5V14h2.75v8z"/>',
    'mail'     => '<path d="M4 5h16a1 1 0 011 1v12a1 1 0 01-1 1H4a1 1 0 01-1-1V6a1 1 0 011-1z"/><path d="M3.6 6.4l8.4 5.6 8.4-5.6" fill="none" stroke="#fff"/>',
];

$tgidIcon = static function (string $name, string $size, bool $filled = false) use ($tgidIcons): string {
    if (!isset($tgidIcons[$name])) {
        return '';
    }

    return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" aria-hidden="true" '
        . 'fill="' . ($filled ? 'currentColor' : 'none') . '" stroke="currentColor" stroke-width="1.5" '
        . 'stroke-linecap="round" stroke-linejoin="round">' . $tgidIcons[$name] . '</svg>';
};
?>

<style>
    .tgid-root {
        --tgid-forest:#123D1E; --tgid-forest-deep:#0C2E15; --tgid-gold:#C0912F;
        --tgid-ink:#16211A; --tgid-muted:#5A6B60; --tgid-line:#DCE4DE; --tgid-paper:#FBFAF7;
    }
    * { box-sizing: border-box; }




    /* ==================== THE PREVIEW SCALE ====================
       A browser draws an inch as 96 px, so a 2.63 in card is 252 px across on
       screen — genuinely too small to read, while printing at exactly the right
       size. Those are two different problems and this solves only the first:
       --s magnifies the PREVIEW and is forced back to 1 for print, so what
       comes out of the printer is untouched. */
    .tgid-root { --tgid-s: 1.7; }

    .tgid-stage-outer {
        width: calc(2.63in * var(--tgid-s));
        height: calc(3.88in * var(--tgid-s));
        margin: 0 auto;
    }

    /* The scale lives here, on the perspective container, so the flip inside is
       a clean rotateY with nothing else mixed into its transform. */
    .tgid-stage {
        width: 2.63in; height: 3.88in;
        perspective: 1600px;
        transform: scale(var(--tgid-s));
        transform-origin: top left;
    }

    .tgid-flip {
        position: relative; width: 100%; height: 100%;
        transform-style: preserve-3d;
        transition: transform .6s cubic-bezier(.42, .04, .28, 1);
    }
    .tgid-flip.is-back { transform: rotateY(180deg); }

    .tgid-face {
        position: absolute; inset: 0;
        -webkit-backface-visibility: hidden; backface-visibility: hidden;
    }
    .tgid-face--back { transform: rotateY(180deg); }

    /* THE BACK USED TO SHOW THE FRONT THROUGH IT, MIRRORED.
       Reported from a phone on the office LAN: flip to the back and the crest,
       the photograph and RHONJON ROMERO were all there in reverse, behind the
       real back. Desktop Chrome never showed it.

       backface-visibility is NOT an inherited property. It was set on
       .tgid-face, but everything you can actually see lives in .tgid-card,
       which has overflow: hidden and a box-shadow — enough for WebKit to give
       it its own compositing layer, and a composited child paints its own
       backface regardless of what its ancestor asked for. So the property has
       to be on the element that does the painting. */
    .tgid-card { -webkit-backface-visibility: hidden; backface-visibility: hidden; }

    /* And a second lock that does not involve 3D at all, because the one above
       is a rendering hint and this is a government ID: the far face is taken
       out of the paint entirely. The .3s delay is the midpoint of the .6s
       flip, so the swap happens while the card is edge-on and the animation
       looks exactly as it did. */
    .tgid-face { transition: visibility 0s linear .3s; }
    .tgid-flip:not(.is-back) .tgid-face--back  { visibility: hidden; }
    .tgid-flip.is-back       .tgid-face--front { visibility: hidden; }

    @media (prefers-reduced-motion: reduce) {
        .tgid-flip { transition: none; }
        /* No flip to hide the swap behind, so it must not lag either. */
        .tgid-face { transition: none; }
    }

    /* ---------- the controls under the card ---------- */
    .tgid-controls {
        max-width: 62rem; margin: 1.5rem auto 0;
        display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; justify-content: center;
    }
    .tgid-controls button {
        padding: .6rem 1.1rem; border: 1px solid var(--tgid-forest); border-radius: 8px;
        background: #fff; color: var(--tgid-forest); font: inherit; font-size: .88rem;
        cursor: pointer; display: inline-flex; align-items: center; gap: .45rem;
    }
    .tgid-controls button:hover { background: #F1F5F2; }
    .tgid-controls .primary { background: var(--tgid-forest); color: #fff; border-color: var(--tgid-forest); }
    .tgid-controls .primary:hover { background: var(--tgid-forest-deep); }

    /* Which face is showing, said in words. An animation that has just finished
       leaves somebody unsure which side they are looking at. */
    .tgid-side {
        display: inline-flex; align-items: center; gap: .4rem;
        padding: .35rem .8rem; border-radius: 999px;
        background: #E8F0E9; color: var(--tgid-forest);
        font-size: .78rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
    }
    .tgid-side__dot { width: .5rem; height: .5rem; border-radius: 50%; background: var(--tgid-gold); }

    .tgid-hint { max-width: 62rem; margin: .7rem auto 0; text-align: center;
            font-size: .8rem; color: var(--tgid-muted); }

    /* ---------- smaller screens ---------- */
    /* The card keeps its proportions and simply gets a smaller --s; nothing is
       reflowed, because a card that reflows is not a card. */
    @media (max-width: 900px) { .tgid-root { --tgid-s: 1.4; } }
    @media (max-width: 640px) { .tgid-root { --tgid-s: 1.15; } }
    @media (max-width: 420px) { .tgid-root { --tgid-s: .95; } }

    /* ============================ THE CARD ============================
       2.63 x 3.88 in. Stated in inches because that is the number the office
       types into a card printer, and a rounded millimetre equivalent is how a
       batch comes back a hair short. */
    .tgid-card {
        position: relative;
        width: 2.63in; height: 3.88in;
        background: var(--tgid-paper);
        border-radius: 4mm;
        overflow: hidden;
        display: flex; flex-direction: column;
        box-shadow: 0 3px 14px rgba(0,0,0,.13);
    }

    /* The Tourism Office's own logo, very faint, low on the card. Rendered as
       a real element rather than a pseudo-element so it can be left out
       altogether when the file is missing — see the note in the PHP above. */
    .tgid-watermark {
        position: absolute; left: 50%; bottom: 9%;
        width: 48mm; height: 48mm; transform: translateX(-50%);
        object-fit: contain; opacity: .06; pointer-events: none; z-index: 0;
    }

    /* ---------- crest band, curved ---------- */
    .tgid-band {
        position: relative; flex-shrink: 0; height: 11mm;
        background: linear-gradient(160deg, var(--tgid-forest) 0%, var(--tgid-forest-deep) 100%);
    }
    /* The paper rises into the band as a wide ellipse, and its gold border
       follows the curve because a border on a rounded box always does. */
    .tgid-band::after {
        content: ''; position: absolute; left: -14%; right: -14%; bottom: -6mm;
        height: 9.5mm; background: var(--tgid-paper);
        border-top: .5mm solid var(--tgid-gold);
        border-radius: 50% 50% 0 0 / 100% 100% 0 0;
    }
    /* A WHITE DISC UNDER THE SEAL.
       Sitting straight on the green, the seal's own dark ring merged into the
       band and the whole mark read as a smudge. The disc is what makes it a
       medallion: it separates the seal from the green above and the paper
       below, and it is the treatment the office's own artwork uses. */
    .tgid-crest {
        position: absolute; left: 50%; top: 1.6mm; transform: translateX(-50%);
        width: 14mm; height: 14mm; z-index: 2;
        padding: 1.1mm; background: #fff; border-radius: 50%;
        object-fit: contain;
        box-shadow: 0 .25mm .9mm rgba(0,0,0,.28);
    }

    /* ---------- the printed content ---------- */
    .tgid-body {
        position: relative; z-index: 1; flex: 1; min-height: 0;
        padding: 5.2mm 5mm 0; text-align: center;
        display: flex; flex-direction: column; align-items: center;
    }

    .tgid-office {
        margin: 0; font-size: 3.1mm; font-weight: 800; line-height: 1.1;
        color: var(--tgid-forest); text-transform: uppercase;
    }
    .tgid-place {
        margin: .5mm 0 0; font-size: 1.8mm; font-weight: 600; letter-spacing: .18em;
        color: var(--tgid-ink); text-transform: uppercase;
    }

    /* Gold rule broken by a diamond, the way an official masthead divides. */
    .tgid-rule { display: flex; align-items: center; gap: 1.2mm; width: 74%; margin: .8mm 0 0; }
    .tgid-rule::before, .tgid-rule::after { content: ''; flex: 1; height: .3mm; background: var(--tgid-gold); }
    .tgid-rule span { color: var(--tgid-gold); font-size: 2mm; line-height: 1; }

    .tgid-portrait {
        width: 17mm; height: 20.5mm; margin-top: .8mm; flex-shrink: 0; object-fit: cover;
        border: .7mm solid var(--tgid-forest); border-radius: 1.4mm; background: #EFF2F0;
    }
    .tgid-portrait--empty {
        display: flex; align-items: center; justify-content: center;
        font-size: 2.1mm; color: var(--tgid-muted); text-align: center; padding: 2mm;
    }

    .tgid-name {
        margin: 1mm 0 0; font-size: 4.2mm; font-weight: 800; line-height: 1.08;
        color: var(--tgid-forest); text-transform: uppercase; word-break: break-word;
    }
    /* A LONG NAME SHRINKS RATHER THAN PUSHING THE CODE OFF THE CARD.
       A card cannot scroll; it just prints clipped. Set from the record's own
       length in PHP rather than by script, because this page is printed and a
       layout that depends on JavaScript having run is one that sometimes has
       not. */
    .tgid-name--long  { font-size: 3.6mm; }
    .tgid-name--xlong { font-size: 3mm; }

    .tgid-role { display: flex; align-items: center; gap: 1.4mm; width: 72%; margin: .6mm 0 0; }
    .tgid-role::before, .tgid-role::after { content: ''; flex: 1; height: .3mm; background: var(--tgid-gold); }
    .tgid-role span {
        font-size: 2.3mm; font-weight: 700; letter-spacing: .22em;
        color: var(--tgid-gold); text-transform: uppercase;
    }

    /* ---------- the two facts ---------- */
    /* TWO COLUMNS, NOT TWO ROWS.
       Stacked, these cost 11 mm of a card that had none to spare — and the
       thing being starved was the QR code, which is the only part of this card
       that does any verifying. Side by side they cost 5 mm, and the 6 mm saved
       goes straight into the code. The icon boxes went with them: they were
       decoration, the code is the mechanism. */
    .tgid-facts {
        width: 100%; margin-top: 1.4mm; display: flex; gap: 2mm;
        border-top: .25mm solid var(--tgid-line); border-bottom: .25mm solid var(--tgid-line);
        padding: 1.1mm 0;
    }
    .tgid-fact { flex: 1; min-width: 0; text-align: center; }
    .tgid-fact + .tgid-fact { border-left: .25mm solid var(--tgid-line); }
    .tgid-fact b {
        display: flex; align-items: center; justify-content: center; gap: .8mm;
        font-size: 1.85mm; font-weight: 700; letter-spacing: .06em;
        text-transform: uppercase; color: var(--tgid-forest); line-height: 1.2;
    }
    .tgid-fact b svg { flex-shrink: 0; }
    .tgid-fact span {
        display: block; margin-top: .3mm; font-size: 2.5mm; font-weight: 800;
        color: var(--tgid-ink); line-height: 1.2;
    }

    /* ---------- the code ---------- */
    .tgid-qrwrap { margin-top: auto; padding-bottom: .7mm; display: flex; flex-direction: column; align-items: center; }
    .tgid-qrbox { padding: .8mm; background: #fff; border: .4mm solid var(--tgid-gold); border-radius: 1.2mm; }
    .tgid-qrbox canvas, .tgid-qrbox img { width: 18mm !important; height: 18mm !important; display: block; }
    .tgid-qrlabel {
        margin-top: .6mm; padding: .5mm 1.8mm; border-radius: .7mm;
        background: var(--tgid-forest-deep); color: #fff;
        font-size: 1.6mm; font-weight: 700; letter-spacing: .11em;
    }

    /* ---------- tagline band, curved ---------- */
    /* THE CURVE USED TO CUT THROUGH THE WORDS.
       The paper ellipse reached 3.5 mm down into a 7 mm band while the text sat
       centred in it, so the gold arc crossed the tagline at its midline and the
       script became unreadable. The band is taller, the ellipse is shallower,
       and the text is pinned to the bottom — so the curve finishes well above
       where the words begin. */
    .tgid-tag {
        position: relative; flex-shrink: 0; height: 8.5mm;
        background: linear-gradient(20deg, var(--tgid-forest) 0%, var(--tgid-forest-deep) 100%);
        display: flex; align-items: flex-end; justify-content: center; gap: 1.2mm;
        padding-bottom: 1.3mm; padding-left: 2mm; padding-right: 2mm;
    }
    .tgid-tag::before {
        content: ''; position: absolute; left: -14%; right: -14%; top: -6mm;
        height: 8.4mm; background: var(--tgid-paper);
        border-bottom: .5mm solid var(--tgid-gold);
        border-radius: 0 0 50% 50% / 0 0 100% 100%;
    }
    .tgid-tag i, .tgid-tag span { position: relative; z-index: 1; }
    .tgid-tag i { color: var(--tgid-gold); font-style: normal; font-size: 1.7mm; flex-shrink: 0; }
    .tgid-tag span {
        font-family: 'Segoe Script', 'Brush Script MT', 'Snell Roundhand', cursive;
        /* Larger and near-white. A thin script at 2.3 mm in cream on dark
           green is decoration that fails at the only size it is ever printed. */
        /* 2.35 mm, which leaves ~3 mm of margin inside a 66.8 mm band. The
           tagline was never unreadable because of its size — it was unreadable
           because the gold arc crossed it at the midline. That is fixed above;
           this only has to fit. */
        font-size: 2.35mm; color: #FFFDF4;
        /* NEVER two lines. The band is a fixed 7.5 mm and a wrapped tagline
           grows upward over the QR code — which is the one thing on the front
           that has to stay scannable. */
        white-space: nowrap;
    }

    /* ============================ BACK ============================ */
    .tgid-back__body {
        position: relative; z-index: 1; flex: 1; min-height: 0;
        padding: 5.2mm 5mm 1.2mm; display: flex; flex-direction: column;
    }
    .tgid-back__office {
        margin: 0; text-align: center; font-size: 3mm; font-weight: 800;
        color: var(--tgid-forest); text-transform: uppercase;
    }
    .tgid-ribbon {
        margin: 1.2mm 0 0; padding: .9mm; text-align: center;
        background: var(--tgid-forest); color: #fff; border-radius: 1mm;
        font-size: 2.1mm; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
    }
    .tgid-certify {
        margin: 1.1mm 0 0; text-align: center; font-size: 2.05mm; line-height: 1.36; color: var(--tgid-ink);
    }

    .tgid-sec { margin-top: 1.5mm; }
    .tgid-sec__head { display: flex; align-items: center; gap: 1.6mm; }
    .tgid-sec__dot {
        flex-shrink: 0; width: 4.8mm; height: 4.8mm; border-radius: 50%;
        background: var(--tgid-forest); color: #fff;
        display: flex; align-items: center; justify-content: center;
    }
    .tgid-sec__head h4 {
        flex: 1; margin: 0; font-size: 2.3mm; font-weight: 800; letter-spacing: .05em;
        color: var(--tgid-forest); text-transform: uppercase;
        border-bottom: .3mm solid var(--tgid-gold); padding-bottom: .6mm;
    }
    .tgid-sec__rows { margin: .9mm 0 0; padding-left: 6.4mm; font-size: 2.1mm; line-height: 1.35; }
    .tgid-sec__row { display: flex; align-items: flex-start; gap: 1.5mm; margin-bottom: .7mm; }
    .tgid-sec__row svg { flex-shrink: 0; margin-top: .3mm; color: var(--tgid-forest); }
    .tgid-sec__rows ul { margin: 0; padding-left: 3.2mm; }
    .tgid-sec__rows li { margin-bottom: .45mm; }

    .tgid-conditions {
        margin: auto 0 0; padding-top: 1mm; text-align: center;
        font-size: 1.8mm; line-height: 1.35; color: var(--tgid-muted);
    }

    /* The back's foot is a band rather than a curve — it carries three lines of
       address and a curve would eat the first of them. */
    .tgid-foot {
        position: relative; flex-shrink: 0; padding: 1.8mm 4.2mm;
        background: linear-gradient(20deg, var(--tgid-forest) 0%, var(--tgid-forest-deep) 100%);
        /* 2.1 mm = 6 pt, the floor below which fine print stops being print.
           It was 1.85 mm (5.2 pt) and the office could not read its own address
           on the card. */
        color: #EAF1EA; font-size: 2.1mm; line-height: 1.35; text-align: center;
    }
    /* NO CURVE HERE, and the comment above is why — I added one anyway on the
       first pass and it swallowed the first line of the address, because the
       ellipse reaches 4 mm down into the band. A straight gold rule does the
       same separating job and costs no height. */
    .tgid-foot { border-top: .5mm solid var(--tgid-gold); }
    /* Centred, because the band is a plaque rather than a list — everything
       else on this side is centred and a left-ragged block under it read as a
       mistake. */
    .tgid-foot__row { position: relative; z-index: 1; display: flex; align-items: flex-start;
                 justify-content: center; gap: 1.5mm; text-align: left; }
    .tgid-foot__row + .tgid-foot__row { margin-top: 1.1mm; padding-top: 1.1mm; border-top: .2mm solid rgba(255,255,255,.22); }
    .tgid-foot__row svg { flex-shrink: 0; margin-top: .25mm; color: var(--tgid-gold); }
    .tgid-foot__row span + svg { margin-left: 2mm; }

    @media print {
        .tgid-controls, .tgid-hint, .no-print { display: none !important; }

        /* THE FLIP IS A SCREEN AFFORDANCE, NOT A DOCUMENT.
           Paper has no back to turn to, so everything 3D is undone and the two
           faces are laid out side by side at exactly 100%. Without this the
           printer would emit one face and a blank rectangle. */
        .tgid-root { --tgid-s: 1; }
        .tgid-stage-outer, .tgid-stage { width: auto; height: auto; transform: none; perspective: none; margin: 0; }
        .tgid-flip {
            position: static; width: auto; height: auto;
            transform: none !important; transform-style: flat; transition: none;
            display: flex; gap: 8mm; flex-wrap: wrap;
        }
        .tgid-face {
            position: static; inset: auto; transform: none !important;
            -webkit-backface-visibility: visible; backface-visibility: visible;
            /* Paper gets BOTH faces, so the screen rule that hides the far one
               has to be lifted here — !important because it is written with
               three classes and would otherwise outrank everything below,
               including the dialog-print path, and send one blank card out of
               the printer. */
            visibility: visible !important;
        }
        .tgid-card { -webkit-backface-visibility: visible; backface-visibility: visible; }
        .tgid-card { box-shadow: none; page-break-inside: avoid; }
        /* The green bands ARE the design, not decoration a printer may drop. */
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }

    /* ============ PRINTING OUT OF THE RECORD'S DIALOG ============
       A dialog opened with showModal() lives in the top layer, and what a
       printer receives from there is not uniform: some browsers emit the page
       behind it, some emit nothing. So the click sets .tgid-printing on <html>
       and these rules strip the document down to the card alone.

       HIDDEN BY VISIBILITY, NOT BY display.

       The first attempt used `body > *:not(dialog) { display: none }` and
       printed a blank page. The dialog is not a child of <body> — the admin
       layout wraps it three elements deep — so that rule removed the very
       container the dialog sits in. visibility does not have that failure mode:
       an ancestor can be hidden while a descendant turns itself back on, so it
       works at any nesting depth and survives a change to the layout.

       Scoped to the class, so the standalone page is untouched by all of it. */
    @media print {
        html.tgid-printing body * { visibility: hidden; }

        html.tgid-printing .tgid-stage-outer,
        html.tgid-printing .tgid-stage-outer * { visibility: visible; }

        /* Lifted to the page origin, because its ancestors are still laid out
           where the dialog put them — centred, and possibly scrolled. */
        html.tgid-printing .tgid-stage-outer {
            position: absolute; top: 0; left: 0; margin: 0;
        }

        /* Nothing between the card and the page may clip or scroll it. */
        html.tgid-printing dialog {
            position: static; display: block; inset: auto;
            width: auto; max-width: none; height: auto; max-height: none;
            margin: 0; padding: 0; border: 0; border-radius: 0;
            background: none; box-shadow: none; overflow: visible;
        }
        html.tgid-printing dialog::backdrop { display: none; }
        html.tgid-printing .sheet__form { display: block; overflow: visible; }
        html.tgid-printing .sheet__body {
            padding: 0 !important; overflow: visible !important; max-height: none !important;
        }
    }
</style>

<div class="tgid-root">
<div class="tgid-stage-outer">
    <div class="tgid-stage">
        <?php /* ONE CARD, TWO FACES, and they are the same two cards as
                 before — stacked in 3D rather than side by side. The office
                 looks at one thing at a time, the way they will hold it. */ ?>
        <div class="tgid-flip" id="tgidFlip">

            <!-- ============================ FRONT ============================ -->
            <div class="tgid-face tgid-face--front">
                <div class="tgid-card">
                <div class="tgid-band"><img class="tgid-crest" src="<?= e($tgidSeal) ?>" alt=""></div>
                <?php if ($tgidWatermark !== null): ?><img class="tgid-watermark" src="<?= e($tgidWatermark) ?>" alt=""><?php endif; ?>

                <div class="tgid-body">
                    <h1 class="tgid-office"><?= e($tgidOfficeName) ?></h1>
                    <p class="tgid-place"><?= e($tgidTown) ?> &bull; <?= e($tgidProvince) ?></p>
                    <div class="tgid-rule"><span>&#10022;</span></div>

                    <?php if ($tgidPhoto !== null): ?>
                        <img class="tgid-portrait" src="<?= e($tgidPhoto) ?>" alt="">
                    <?php else: ?>
                        <div class="tgid-portrait tgid-portrait--empty">No photograph on file</div>
                    <?php endif; ?>

                    <?php
                    /* Measured at 2.63 in wide: past 22 characters a name needs a second
                       line, past 30 a third. Two steps down keep every real name inside
                       the card without ever truncating one — an ID that abbreviates a
                       legal name is not an ID. */
                    $tgidLen    = mb_strlen((string) $guide['full_name']);
                    /* PREFIXED HERE TOO. A class attribute built in PHP is invisible to any
                       search-and-replace that reads the markup, which is exactly how the
                       name lost its styling once — rendered as a plain <p> because
                       .name no longer existed. */
                    $tgidNameClass = $tgidLen > 30 ? ' tgid-name--xlong' : ($tgidLen > 22 ? ' tgid-name--long' : '');
                    ?>
                    <p class="tgid-name<?= $tgidNameClass ?>"><?= e((string) $guide['full_name']) ?></p>

                    <div class="tgid-role"><span>Tour Guide</span></div>

                    <div class="tgid-facts">
                        <div class="tgid-fact">
                            <b><?= $tgidIcon('id', '2.4mm') ?> Guide ID</b>
                            <span><?= e((string) $guide['guide_code']) ?></span>
                        </div>
                        <div class="tgid-fact">
                            <b><?= $tgidIcon('calendar', '2.4mm') ?> Valid Until</b>
                            <?php /* Short month. "August 25, 2027" does not fit half the
                                     card's width at a legible size, and an ID whose expiry
                                     has to be squinted at is an ID nobody checks. */ ?>
                            <span><?= $guide['valid_until']
                                ? e(format_date((string) $guide['valid_until'], 'M j, Y'))
                                : '&mdash;' ?></span>
                        </div>
                    </div>

                    <?php /* The only code on the card. */ ?>
                    <div class="tgid-qrwrap">
                        <div class="tgid-qrbox"><div id="tgidQr" data-url="<?= e($tgidVerifyUrl) ?>"></div></div>
                        <span class="tgid-qrlabel">Scan to Verify</span>
                    </div>
                </div>

                <div class="tgid-tag">
                    <i>&#10022;</i>
                    <span>Promoting <?= e($tgidTown) ?>, Welcoming the World.</span>
                    <i>&#10022;</i>
                </div>
                </div>
            </div>

            <!-- ============================ BACK ============================ -->
            <?php /* No QR here. Deliberately — see the note at the top of this file. */ ?>
            <div class="tgid-face tgid-face--back">
                <div class="tgid-card">
                <div class="tgid-band"><img class="tgid-crest" src="<?= e($tgidSeal) ?>" alt=""></div>
                <?php if ($tgidWatermark !== null): ?><img class="tgid-watermark" src="<?= e($tgidWatermark) ?>" alt=""><?php endif; ?>

                <div class="tgid-back__body">
                    <h2 class="tgid-back__office"><?= e($tgidOfficeName) ?></h2>
                    <div class="tgid-rule" style="width:60%; margin:1.4mm auto 0"><span>&#10022;</span></div>

                    <p class="tgid-ribbon">Tour Guide Identification</p>

                    <p class="tgid-certify">
                        This card certifies that the bearer is an authorised tour guide
                        under the <?= e($tgidOfficeName) ?>.
                    </p>

                    <div class="tgid-sec">
                        <div class="tgid-sec__head">
                            <span class="tgid-sec__dot"><?= $tgidIcon('person', '3.2mm', true) ?></span>
                            <h4>Guide Information</h4>
                        </div>
                        <div class="tgid-sec__rows">
                            <?php if ($guide['address']): ?>
                                <div class="tgid-sec__row"><?= $tgidIcon('pin', '2.7mm', true) ?><span><?= e((string) $guide['address']) ?></span></div>
                            <?php endif; ?>
                            <?php if ($guide['mobile_number']): ?>
                                <div class="tgid-sec__row"><?= $tgidIcon('phone', '2.7mm', true) ?><span><?= e((string) $guide['mobile_number']) ?></span></div>
                            <?php endif; ?>
                            <?php if (!$guide['address'] && !$guide['mobile_number']): ?>
                                <div class="tgid-sec__row"><span>&mdash;</span></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="tgid-sec">
                        <div class="tgid-sec__head">
                            <span class="tgid-sec__dot"><?= $tgidIcon('badge', '3.2mm') ?></span>
                            <h4>Qualifications</h4>
                        </div>
                        <div class="tgid-sec__rows">
                            <?php if ($tgidCreds === []): ?>
                                <p style="margin:0">&mdash;</p>
                            <?php else: ?>
                                <?php /* Five at most. The verification page carries the full
                                         list and has room; a sixth line here pushes the
                                         conditions off the card. */ ?>
                                <ul>
                                    <?php foreach (array_slice($tgidCreds, 0, 5) as $c): ?>
                                        <li><?= e((string) $c['label']) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php /* THE ADDRESS IS PRINTED ONCE, NOT TWICE.
                             This line used to repeat the whole office address, which the
                             band below already carries in full — the same forty words on
                             one 66 mm card, and the duplication was what pushed the back
                             over its height. It points at the band instead. */ ?>
                    <p class="tgid-conditions">
                        Valid only until the date on the front. Non-transferable.<br>
                        If found, please return to the office at the address below.
                    </p>
                </div>

                <?php /* Blank settings print nothing rather than a placeholder. A made-up
                         telephone number on an official ID is worse than a missing one —
                         somebody will dial it. */ ?>
                <div class="tgid-foot">
                    <div class="tgid-foot__row">
                        <?= $tgidIcon('pin', '2.6mm', true) ?>
                        <span><?= e($tgidOfficeName) ?><?= $tgidOfficeAddress !== '' ? ',<br>' . e($tgidOfficeAddress) : '' ?></span>
                    </div>
                    <?php if ($tgidOfficePhone !== '' || $tgidOfficeEmail !== ''): ?>
                        <div class="tgid-foot__row">
                            <?php if ($tgidOfficePhone !== ''): ?>
                                <?= $tgidIcon('phone', '2.6mm', true) ?><span><?= e($tgidOfficePhone) ?></span>
                            <?php endif; ?>
                            <?php if ($tgidOfficeEmail !== ''): ?>
                                <?= $tgidIcon('mail', '2.6mm') ?><span><?= e($tgidOfficeEmail) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($tgidOfficeFb !== ''): ?>
                        <div class="tgid-foot__row">
                            <?= $tgidIcon('facebook', '2.6mm', true) ?><span><?= e($tgidOfficeFb) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<?php if ($tgidControls): ?>
<div class="tgid-root">
<div class="tgid-controls no-print">
    <button type="button" id="tgidFlipBtn" aria-controls="tgidFlip" aria-pressed="false">
        <span aria-hidden="true">&#8635;</span> <span id="tgidFlipLabel">View Back</span>
    </button>

    <?php /* Said in words as well as shown, and announced to a screen reader —
             an animation that has just finished leaves somebody unsure which
             side they ended on. */ ?>
    <span class="tgid-side" role="status" aria-live="polite">
        <span class="tgid-side__dot"></span><span id="tgidSideLabel">Front</span>
    </span>

    <button type="button" class="primary" onclick="window.print()">
        <span aria-hidden="true">&#128424;</span> Print ID
    </button>

    <button type="button" id="tgidPdfBtn">
        <span aria-hidden="true">&#8681;</span> Save as PDF
    </button>
</div>

<?php /* HONEST ABOUT WHAT "SAVE" IS. Both buttons open the same browser dialog,
         because the browser is the only PDF writer available here — generating
         one server-side would mean a Composer dependency this deployment does
         not have. Saying so beats a button that looks like it does something
         else. */ ?>
<p class="tgid-hint no-print">
    Both buttons open your browser's print dialog &mdash; for a file, choose
    <strong>Save as PDF</strong> as the destination. Set scale to <strong>100%</strong>;
    &ldquo;fit to page&rdquo; will print the card the wrong size.
    Both sides print together.
</p>
</div>
<?php endif; ?>

<script src="<?= e(asset('js/vendor/qrcode.min.js')) ?>"></script>
<script>
(function () {
    /* ---- turning the card over ----------------------------------------
       A real card has two sides and you turn it; two rectangles side by side
       is a spec sheet. The rotation is 0.6s on an ease that starts slowly —
       fast enough not to wait for, slow enough to read as the same object
       turning rather than a swap.

       The button carries aria-pressed and the indicator is a live region, so
       the state is available to somebody who cannot see the animation. Anyone
       who has asked their system for reduced motion gets the same flip with
       the transition off, handled in CSS. */
    /* BOUND BY DELEGATION, NOT BY REFERENCE.
       The host may render these controls anywhere — the record's dialog puts
       them in its footer, which is parsed AFTER this script. Looking the button
       up now would find nothing and the card would never turn, which is exactly
       what happened the first time. Delegation on the document does not care
       what order anything was parsed in, or whether the host re-renders. */
    var showBack = false;

    var turn = function () {
        var flip = document.getElementById('tgidFlip');

        if (!flip) { return; }

        showBack = !showBack;
        flip.classList.toggle('is-back', showBack);

        var btn   = document.getElementById('tgidFlipBtn');
        var label = document.getElementById('tgidFlipLabel');
        var side  = document.getElementById('tgidSideLabel');

        if (btn)   { btn.setAttribute('aria-pressed', showBack ? 'true' : 'false'); }
        if (label) { label.textContent = showBack ? 'View Front' : 'View Back'; }
        if (side)  { side.textContent  = showBack ? 'Back' : 'Front'; }
    };

    document.addEventListener('click', function (event) {
        if (!event.target.closest) { return; }

        /* The button, or the card itself — turning over the thing you are
           looking at is the obvious gesture. The card is not a button and is
           deliberately left out of the tab order; the real control is in the
           toolbar. */
        if (event.target.closest('#tgidFlipBtn') || event.target.closest('#tgidFlip')) {
            turn();
        }
    });

    var stage = document.getElementById('tgidFlip');
    if (stage) { stage.style.cursor = 'pointer'; }

    /* Save and Print reach the same dialog. See the note under the buttons. */
    var pdf = document.getElementById('tgidPdfBtn');
    if (pdf) { pdf.addEventListener('click', function () { window.print(); }); }

    /* PRINTING MUST NEVER DEPEND ON WHICH SIDE IS SHOWING.
       The print stylesheet flattens the stage, but a card left flipped would
       still carry .is-back into the flat layout and render mirrored. Cleared
       before the dialog opens and restored after it closes. */
    var wasBack = false;

    window.addEventListener('beforeprint', function () {
        var f = document.getElementById('tgidFlip');
        wasBack = !!f && f.classList.contains('is-back');
        if (wasBack) { f.classList.remove('is-back'); }
    });

    window.addEventListener('afterprint', function () {
        var f = document.getElementById('tgidFlip');
        if (wasBack && f) { f.classList.add('is-back'); }
    });

    var box = document.getElementById('tgidQr');

    if (!box || typeof QRCode === 'undefined') { return; }

    new QRCode(box, {
        text: box.dataset.url,
        width: 180,
        height: 180,
        /* H: the card gets handled, scuffed and rained on, and the code still
           has to read. Same level the destination signage uses. */
        correctLevel: QRCode.CorrectLevel.H
    });
})();
</script>
