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

/* The Tourism Office mark that stands BESIDE the seal at the top of the card.
   tourism-logo-mark.png is the office's upload with its blank margin cropped
   off and a real transparent background; the upload itself is JPEG data behind
   a .png name, and inside a 18mm medallion its baked-in margin drew the mark a
   third smaller than the seal next to it. Falls back to the upload, then to
   nothing — an empty medallion is worse than one mark. */
$tgidCrestMark = $tgidAsset('tourism-logo-mark.png') ? asset('img/tourism-logo-mark.png')
    : ($tgidAsset('tourism-logo.png') ? asset('img/tourism-logo.png') : null);

$tgidOfficeName    = trim((string) (setting('office_name', '') ?? '')) ?: 'Municipal Tourism Office';
$tgidOfficeAddress = trim((string) (setting('office_address', '') ?? ''));
$tgidOfficePhone   = trim((string) (setting('office_phone', '') ?? ''));
$tgidOfficeEmail   = trim((string) (setting('office_email', '') ?? ''));
$tgidOfficeFb      = trim((string) (setting('office_facebook', '') ?? ''));
$tgidMunicipality  = trim((string) (setting('office_municipality', '') ?? '')) ?: 'Tampakan';
$tgidProvince      = trim((string) (setting('office_province', '') ?? '')) ?: 'South Cotabato';

/* WHO ISSUED THE CARD, read from the same settings rows the About page uses.
   Not hard-coded: the office was explicit that the Mayor and the Tourism
   Coordinator are edited from Admin > Settings and nowhere else, and an ID that
   still names last term's mayor is worse than one that names nobody.

   Each block is drawn only when its name is filled, so a half-configured
   office gets one signatory rather than a blank line over a printed title. */
$tgidOfficials = array_values(array_filter([
    [
        'name'     => trim((string) (setting('about_coordinator_name', '') ?? '')),
        'position' => trim((string) (setting('about_coordinator_position', '') ?? '')) ?: 'Municipal Tourism Coordinator',
    ],
    [
        'name'     => trim((string) (setting('about_mayor_name', '') ?? '')),
        'position' => trim((string) (setting('about_mayor_position', '') ?? '')) ?: 'Municipal Mayor',
    ],
], static fn(array $o): bool => $o['name'] !== ''));

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

/**
 * $size is given the same way every other dimension on this card is — in
 * millimetres, as a string like "2.4 mm" — but it is emitted through the STYLE
 * attribute rather than SVG's own width/height.
 *
 * The reason is that the card's geometry is one variable: every length in the
 * stylesheet is a multiple of --tgid-u, so the whole card resizes by changing
 * that one number. SVG presentation attributes do not accept calc(), so an icon
 * sized through a plain width attribute would be the only thing on the card
 * NOT following --tgid-u, and it would quietly stay small the next time the office
 * asks for a bigger card. Inline CSS does accept calc(), so it goes there.
 */
$tgidIcon = static function (string $name, string $size, bool $filled = false) use ($tgidIcons): string {
    if (!isset($tgidIcons[$name])) {
        return '';
    }

    $mm = (float) rtrim(trim($size), 'm');
    $px = 'calc(' . $mm . ' * var(--tgid-u))';

    return '<svg viewBox="0 0 24 24" aria-hidden="true" '
        . 'style="width:' . $px . ';height:' . $px . ';flex-shrink:0" '
        . 'fill="' . ($filled ? 'currentColor' : 'none') . '" stroke="currentColor" stroke-width="1.5" '
        . 'stroke-linecap="round" stroke-linejoin="round">' . $tgidIcons[$name] . '</svg>';
};
?>

<style>
    .tgid-root {
        --tgid-forest:#123D1E; --tgid-forest-deep:#0C2E15; --tgid-gold:#C0912F;

        /* GOLD FOR RULES, A DARKER GOLD FOR WORDS.
           #C0912F is the card's accent and it is right for the hairlines and the
           borders on the dark bands. As TEXT on the cream paper it measures
           2.74:1 — "TOUR GUIDE", the guide's role, sat below AA on the one line
           an inspector reads after the name. This is the same colour at the same
           hue, dark enough to pass: 4.64:1 against #FBFAF7.

           Long-standing, not introduced by the A6 rework — found by measuring
           the card rather than looking at it. */
        --tgid-gold-ink:#96691A;

        --tgid-ink:#16211A;

        /* ============ THE CARD'S SIZE, IN ONE PLACE ============
           A6, 105 x 148mm, which is what the office asked for after printing
           the old 2.63 x 3.88in card and finding it no bigger than a school ID
           (measured: 1.43x a school ID's area, against A6's 3.4x).

           --tgid-u is the unit every dimension in this stylesheet is a multiple
           of, so the card scales as one piece. To resize the card, change these
           three and nothing else; keep --tgid-u-base near height / 98.5, the
           height the layout was originally drawn against.

           Stated in millimetres because that is what the office types into a
           card printer. */
        --tgid-w: 105mm;
        --tgid-h: 148mm;
        --tgid-u-base: 1.5mm;

        /* Everything reads --tgid-u; only the back face overrides it, through
           the multiplier below, so a change to --tgid-u-base still moves the
           whole card together. A custom property cannot be defined in terms of
           itself, which is why the base is a separate name. */
        --tgid-u: var(--tgid-u-base);
        --tgid-back-k: 1;

        --tgid-muted:#5A6B60; --tgid-line:#DCE4DE; --tgid-paper:#FBFAF7;
    }
    * { box-sizing: border-box; }




    /* ==================== THE PREVIEW SCALE ====================
       ONE TO ONE, and that is the point.

       This used to magnify the preview 1.7x, because the old 2.63in card was
       only 252px across and hard to read on screen. It also meant the office
       approved a card shown at 113 x 167mm and then printed one at 67 x 99mm,
       which is why the printed card came as a shock. A preview that lies about
       size is worse than a preview that is small.

       At A6 there is no longer a reason to lie: 105mm is 397px, perfectly
       readable. The scale only comes DOWN now, and only to fit a narrow screen.
       It is still forced to 1 for print, so nothing here can reach the paper. */
    .tgid-root { --tgid-s: 1; }

    .tgid-stage-outer {
        width: calc(var(--tgid-w) * var(--tgid-s));
        height: calc(var(--tgid-h) * var(--tgid-s));
        margin: 0 auto;
    }

    /* The scale lives here, on the perspective container, so the flip inside is
       a clean rotateY with nothing else mixed into its transform. */
    .tgid-stage {
        width: var(--tgid-w); height: var(--tgid-h);
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
    /* A6 is 397px wide at 1:1, so these only shrink it enough to clear the
       viewport — never above 1, which would put the preview back to overstating
       the card the office is about to print. */
    @media (max-width: 640px) { .tgid-root { --tgid-s: .88; } }
    @media (max-width: 480px) { .tgid-root { --tgid-s: .72; } }
    @media (max-width: 380px) { .tgid-root { --tgid-s: .6;  } }

    /* ============================ THE CARD ============================
       Its size is --tgid-w x --tgid-h, declared once at the top of this
       stylesheet. Nothing here should state a dimension of its own. */
    .tgid-card {
        position: relative;
        width: var(--tgid-w); height: var(--tgid-h);
        background: var(--tgid-paper);
        border-radius: calc(4 * var(--tgid-u));
        overflow: hidden;
        display: flex; flex-direction: column;
        box-shadow: 0 3px 14px rgba(0,0,0,.13);
    }

    /* The Tourism Office's own logo, very faint, low on the card. Rendered as
       a real element rather than a pseudo-element so it can be left out
       altogether when the file is missing — see the note in the PHP above. */
    .tgid-watermark {
        position: absolute; left: 50%; bottom: 9%;
        width: calc(48 * var(--tgid-u)); height: calc(48 * var(--tgid-u)); transform: translateX(-50%);
        object-fit: contain; opacity: .06; pointer-events: none; z-index: 0;
    }

    /* ---------- crest band, curved ---------- */
    .tgid-band {
        position: relative; flex-shrink: 0; height: calc(11 * var(--tgid-u));
        background: linear-gradient(160deg, var(--tgid-forest) 0%, var(--tgid-forest-deep) 100%);
    }
    /* The paper rises into the band as a wide ellipse, and its gold border
       follows the curve because a border on a rounded box always does. */
    .tgid-band::after {
        content: ''; position: absolute; left: -14%; right: -14%; bottom: calc(-6 * var(--tgid-u));
        height: calc(9.5 * var(--tgid-u)); background: var(--tgid-paper);
        border-top: calc(.5 * var(--tgid-u)) solid var(--tgid-gold);
        border-radius: 50% 50% 0 0 / 100% 100% 0 0;
    }
    /* A WHITE DISC UNDER THE SEAL.
       Sitting straight on the green, the seal's own dark ring merged into the
       band and the whole mark read as a smudge. The disc is what makes it a
       medallion: it separates the seal from the green above and the paper
       below, and it is the treatment the office's own artwork uses. */
    /* TWO MEDALLIONS, SIDE BY SIDE AT THE TOP CENTRE — not one in each corner.
       The office left the choice open. A corner-to-corner pair is the letterhead
       convention and it belongs on a wide format; on a 105mm portrait card the
       two marks end up 70mm apart with a hole between them, and each one sits
       against the card's own corner radius. Centred, they read as a single
       masthead and the office name below stays the width of the card.

       Slightly smaller than the single crest was (12u against 14u) so the pair
       plus its gap is 39.6mm — under 40% of the card's width, which is the point
       past which a masthead starts competing with the photograph. */
    .tgid-crests {
        position: absolute; left: 50%; top: calc(1.6 * var(--tgid-u));
        transform: translateX(-50%); z-index: 2;
        display: flex; align-items: center; gap: calc(2.4 * var(--tgid-u));
    }
    .tgid-crest {
        width: calc(12 * var(--tgid-u)); height: calc(12 * var(--tgid-u));
        padding: calc(1.1 * var(--tgid-u)); background: #fff; border-radius: 50%;
        object-fit: contain;
        box-shadow: 0 calc(.25 * var(--tgid-u)) calc(.9 * var(--tgid-u)) rgba(0,0,0,.28);
    }
    /* MATCHED BY WHAT IS INKED, not by the box.
       In identical discs the two marks did not look identical: measured, the
       municipal seal inks 96% of its canvas and the Tourism Office mark 85%
       across and 66% down, so the same padding drew the tourism mark visibly
       smaller and the pair read as a mistake. Less padding for the one with more
       margin baked in brings the drawn artwork to the same width — 9.6u against
       the seal's 9.4u. Still inside the disc: that asset is generated so its ink
       fits the inscribed circle of its own canvas. */
    .tgid-crest--mark { padding: calc(.35 * var(--tgid-u)); }

    /* ---------- the printed content ---------- */
    .tgid-body {
        position: relative; z-index: 1; flex: 1; min-height: 0;
        /* 3.8u of top padding, not 5.2u. That figure cleared a single 14u crest
           hanging from the band; the masthead is now a pair of 12u medallions,
           whose bottom edge sits at 13.6u against the band's 11u, so 2.6u is
           what has to be cleared and 3.8u clears it with room. The 1.4u this
           gives back is what lets the QR stay at 24mm — see the note on
           .tgid-qrbox canvas for why that size is not negotiable downward. */
        padding: calc(3.8 * var(--tgid-u)) calc(5 * var(--tgid-u)) 0; text-align: center;
        display: flex; flex-direction: column; align-items: center;
    }

    .tgid-office {
        margin: 0; font-size: calc(3.1 * var(--tgid-u)); font-weight: 800; line-height: 1.1;
        color: var(--tgid-forest); text-transform: uppercase;
    }
    .tgid-place {
        margin: calc(.5 * var(--tgid-u)) 0 0; font-size: calc(1.8 * var(--tgid-u)); font-weight: 600; letter-spacing: .18em;
        color: var(--tgid-ink); text-transform: uppercase;
    }

    /* Gold rule broken by a diamond, the way an official masthead divides. */
    .tgid-rule { display: flex; align-items: center; gap: calc(1.2 * var(--tgid-u)); width: 74%; margin: calc(.8 * var(--tgid-u)) 0 0; }
    .tgid-rule::before, .tgid-rule::after { content: ''; flex: 1; height: calc(.3 * var(--tgid-u)); background: var(--tgid-gold); }
    .tgid-rule span { color: var(--tgid-gold-ink); font-size: calc(2 * var(--tgid-u)); line-height: 1; }

    .tgid-portrait {
        width: calc(17 * var(--tgid-u)); height: calc(20.5 * var(--tgid-u)); margin-top: calc(.8 * var(--tgid-u)); flex-shrink: 0; object-fit: cover;
        border: calc(.7 * var(--tgid-u)) solid var(--tgid-forest); border-radius: calc(1.4 * var(--tgid-u)); background: #EFF2F0;
    }
    .tgid-portrait--empty {
        display: flex; align-items: center; justify-content: center;
        font-size: calc(2.1 * var(--tgid-u)); color: var(--tgid-muted); text-align: center; padding: calc(2 * var(--tgid-u));
    }

    .tgid-name {
        margin: calc(1 * var(--tgid-u)) 0 0; font-size: calc(4.2 * var(--tgid-u)); font-weight: 800; line-height: 1.08;
        color: var(--tgid-forest); text-transform: uppercase; word-break: break-word;
    }
    /* A LONG NAME SHRINKS RATHER THAN PUSHING THE CODE OFF THE CARD.
       A card cannot scroll; it just prints clipped. Set from the record's own
       length in PHP rather than by script, because this page is printed and a
       layout that depends on JavaScript having run is one that sometimes has
       not. */
    .tgid-name--long  { font-size: calc(3.6 * var(--tgid-u)); }
    .tgid-name--xlong { font-size: calc(3 * var(--tgid-u)); }

    .tgid-role { display: flex; align-items: center; gap: calc(1.4 * var(--tgid-u)); width: 72%; margin: calc(.6 * var(--tgid-u)) 0 0; }
    .tgid-role::before, .tgid-role::after { content: ''; flex: 1; height: calc(.3 * var(--tgid-u)); background: var(--tgid-gold); }
    .tgid-role span {
        font-size: calc(2.3 * var(--tgid-u)); font-weight: 700; letter-spacing: .22em;
        color: var(--tgid-gold-ink); text-transform: uppercase;
    }

    /* ---------- the two facts ---------- */
    /* TWO COLUMNS, NOT TWO ROWS.
       Stacked, these cost 11 mm of a card that had none to spare — and the
       thing being starved was the QR code, which is the only part of this card
       that does any verifying. Side by side they cost 5 mm, and the 6 mm saved
       goes straight into the code. The icon boxes went with them: they were
       decoration, the code is the mechanism. */
    .tgid-facts {
        width: 100%; margin-top: calc(1.4 * var(--tgid-u)); display: flex; gap: calc(2 * var(--tgid-u));
        border-top: calc(.25 * var(--tgid-u)) solid var(--tgid-line); border-bottom: calc(.25 * var(--tgid-u)) solid var(--tgid-line);
        padding: calc(1.1 * var(--tgid-u)) 0;
    }
    .tgid-fact { flex: 1; min-width: 0; text-align: center; }
    .tgid-fact + .tgid-fact { border-left: calc(.25 * var(--tgid-u)) solid var(--tgid-line); }
    .tgid-fact b {
        display: flex; align-items: center; justify-content: center; gap: calc(.8 * var(--tgid-u));
        font-size: calc(1.85 * var(--tgid-u)); font-weight: 700; letter-spacing: .06em;
        text-transform: uppercase; color: var(--tgid-forest); line-height: 1.2;
    }
    .tgid-fact b svg { flex-shrink: 0; }
    .tgid-fact span {
        display: block; margin-top: calc(.3 * var(--tgid-u)); font-size: calc(2.5 * var(--tgid-u)); font-weight: 800;
        color: var(--tgid-ink); line-height: 1.2;
    }

    /* ---------- the code ---------- */
    .tgid-qrwrap { margin-top: auto; padding-bottom: calc(.7 * var(--tgid-u)); display: flex; flex-direction: column; align-items: center; }
    .tgid-qrbox { padding: calc(.8 * var(--tgid-u)); background: #fff; border: calc(.4 * var(--tgid-u)) solid var(--tgid-gold); border-radius: calc(1.2 * var(--tgid-u)); }
    /* 16u — 24mm at A6, down from 18u (27mm). Those 3mm are what the signatory
       band below is built from.

       NOT a free choice. The verify URL comes out as a 50-module code, so at
       24mm each module is 0.48mm. 0.4mm is the floor below which a phone camera
       starts failing on paper, and 22.5mm left only 0.45mm — measured, not
       assumed, in scratchpad/id-quality.cjs. Shrink this again and re-measure,
       or the card's verification stops working in the one place it is used. */
    .tgid-qrbox canvas, .tgid-qrbox img { width: calc(16 * var(--tgid-u)) !important; height: calc(16 * var(--tgid-u)) !important; display: block; }
    .tgid-qrlabel {
        margin-top: calc(.6 * var(--tgid-u)); padding: calc(.5 * var(--tgid-u)) calc(1.8 * var(--tgid-u)); border-radius: calc(.7 * var(--tgid-u));
        background: var(--tgid-forest-deep); color: #fff;
        font-size: calc(1.6 * var(--tgid-u)); font-weight: 700; letter-spacing: .11em;
    }

    /* ---------- tagline band, curved ---------- */
    /* THE CURVE USED TO CUT THROUGH THE WORDS.
       The paper ellipse reached 3.5 mm down into a 7 mm band while the text sat
       centred in it, so the gold arc crossed the tagline at its midline and the
       script became unreadable. The band is taller, the ellipse is shallower,
       and the text is pinned to the bottom — so the curve finishes well above
       where the words begin. */
    .tgid-tag {
        position: relative; flex-shrink: 0; height: calc(8.5 * var(--tgid-u));
        background: linear-gradient(20deg, var(--tgid-forest) 0%, var(--tgid-forest-deep) 100%);
        display: flex; align-items: flex-end; justify-content: center; gap: calc(1.2 * var(--tgid-u));
        padding-bottom: calc(1.3 * var(--tgid-u)); padding-left: calc(2 * var(--tgid-u)); padding-right: calc(2 * var(--tgid-u));
    }
    .tgid-tag::before {
        content: ''; position: absolute; left: -14%; right: -14%; top: calc(-6 * var(--tgid-u));
        height: calc(8.4 * var(--tgid-u)); background: var(--tgid-paper);
        border-bottom: calc(.5 * var(--tgid-u)) solid var(--tgid-gold);
        border-radius: 0 0 50% 50% / 0 0 100% 100%;
    }
    .tgid-tag i, .tgid-tag span { position: relative; z-index: 1; }
    .tgid-tag i { color: var(--tgid-gold); font-style: normal; font-size: calc(1.7 * var(--tgid-u)); flex-shrink: 0; }

    /* ---------- the signatory band ---------- */
    /* THE LAST BAND NAMES THE AUTHORITY, not a slogan.

       Two blocks, Coordinator then Mayor, reading left to right in the order the
       card is approved. Each is a name over a title; there is no signature rule,
       because nobody signs 19 cards by hand and a printed line nobody signs on
       is a lie about how the card is issued.

       Straight sans, not the cursive the slogan used. Both lines are near-white
       on the dark green gradient, which measures better than 12:1 — the script
       it replaces failed at the same colour purely on letterform. */
    .tgid-sign {
        position: relative; flex-shrink: 0;
        background: linear-gradient(20deg, var(--tgid-forest) 0%, var(--tgid-forest-deep) 100%);
        border-top: calc(.5 * var(--tgid-u)) solid var(--tgid-gold);
        /* STRETCH, so the two blocks are the same height whatever their names
           do. "HON. LEONARD T. ESCOBILLO, RN" takes two lines in half a 105mm
           card and "ROSELILY T. JOYNO, MDMG" takes one; left to size themselves
           the two titles sat at different heights and the band read as crooked.
           Stretched, with the title pushed to the bottom of its block, the two
           titles line up however the names fall. */
        display: flex; align-items: stretch; justify-content: center;
        gap: calc(3 * var(--tgid-u));
        padding: calc(2 * var(--tgid-u)) calc(3 * var(--tgid-u)) calc(2.2 * var(--tgid-u));
    }
    .tgid-sign__who {
        flex: 1 1 0; min-width: 0; text-align: center;
        display: flex; flex-direction: column;
    }
    .tgid-sign__who + .tgid-sign__who {
        border-left: calc(.2 * var(--tgid-u)) solid rgba(255, 255, 255, .22);
    }
    .tgid-sign__who b {
        display: block; font-size: calc(2.05 * var(--tgid-u)); font-weight: 800;
        line-height: 1.15; color: #FFFDF4; text-transform: uppercase;
        letter-spacing: .01em;
        /* The names are the office's own and can be long — "HON. LEONARD T.
           ESCOBILLO, RN" is 29 characters in half a 105mm card. It wraps rather
           than being cut: an ID that abbreviates an official's name is the same
           fault as one that abbreviates the guide's. */
        overflow-wrap: anywhere;
    }
    .tgid-sign__who span {
        /* margin-top: auto pins the title to the bottom of a stretched block,
           which is what keeps the two titles on one line across the band. */
        display: block; margin-top: auto; padding-top: calc(.5 * var(--tgid-u));
        font-size: calc(1.6 * var(--tgid-u)); line-height: 1.2;
        color: #CBDCCB; letter-spacing: .04em;
    }

    /* ============================ BACK ============================ */
    /* ============ THE BACK FACE IS THE DENSE ONE ============
       It carries a certification paragraph, two headed sections, the guide's
       address and phone, up to five credentials and the conditions line, all in
       the space the front gives to a photograph and a QR code. It has ALWAYS
       been slightly too tall — measured at the old size it overran its region by
       4.5mm and the conditions ran under the address band; enlarging the card to
       A6 scaled that to 6.3mm and made it obvious.

       So the back takes a slightly smaller unit, and takes a smaller one again
       when the credential list is long. The step is a multiplier rather than a
       millimetre value so that resizing the card still moves this with it.

       The numbers are measured, not judged — millimetres of spare room below the
       conditions line, by credential count:

           credentials      1     2     3     4     5
           k = 1    (1.50) 11.5   6.6   1.7  over  over
           k = .88  (1.32) 21.6  17.3  13.0   8.6   4.3

       Five is the most that can appear: the list is capped there and the
       verification page carries the rest. 17 of the 19 guides on the roster
       carry one or two, so the common card keeps the full A6 scale. */
    .tgid-back__body { --tgid-u: calc(var(--tgid-u-base) * var(--tgid-back-k)); }
    .tgid-back__body--tight { --tgid-back-k: .88; }

    .tgid-back__body {
        position: relative; z-index: 1; flex: 1; min-height: 0;
        padding: calc(5.2 * var(--tgid-u)) calc(5 * var(--tgid-u)) calc(1.2 * var(--tgid-u)); display: flex; flex-direction: column;
    }
    .tgid-back__office {
        margin: 0; text-align: center; font-size: calc(3 * var(--tgid-u)); font-weight: 800;
        color: var(--tgid-forest); text-transform: uppercase;
    }
    /* The slogan the front could not carry. Italic rather than cursive: it still
       reads as a motto, and it survives a printer at 2mm where a script face
       does not. */
    .tgid-slogan {
        margin: calc(.8 * var(--tgid-u)) 0 0; text-align: center;
        font-size: calc(2 * var(--tgid-u)); font-style: italic;
        color: var(--tgid-forest); line-height: 1.2;
    }
    .tgid-ribbon {
        margin: calc(1.2 * var(--tgid-u)) 0 0; padding: calc(.9 * var(--tgid-u)); text-align: center;
        background: var(--tgid-forest); color: #fff; border-radius: calc(1 * var(--tgid-u));
        font-size: calc(2.1 * var(--tgid-u)); font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
    }
    .tgid-certify {
        margin: calc(1.1 * var(--tgid-u)) 0 0; text-align: center; font-size: calc(2.05 * var(--tgid-u)); line-height: 1.36; color: var(--tgid-ink);
    }

    .tgid-sec { margin-top: calc(1.5 * var(--tgid-u)); }
    .tgid-sec__head { display: flex; align-items: center; gap: calc(1.6 * var(--tgid-u)); }
    .tgid-sec__dot {
        flex-shrink: 0; width: calc(4.8 * var(--tgid-u)); height: calc(4.8 * var(--tgid-u)); border-radius: 50%;
        background: var(--tgid-forest); color: #fff;
        display: flex; align-items: center; justify-content: center;
    }
    .tgid-sec__head h4 {
        flex: 1; margin: 0; font-size: calc(2.3 * var(--tgid-u)); font-weight: 800; letter-spacing: .05em;
        color: var(--tgid-forest); text-transform: uppercase;
        border-bottom: calc(.3 * var(--tgid-u)) solid var(--tgid-gold); padding-bottom: calc(.6 * var(--tgid-u));
    }
    .tgid-sec__rows { margin: calc(.9 * var(--tgid-u)) 0 0; padding-left: calc(6.4 * var(--tgid-u)); font-size: calc(2.1 * var(--tgid-u)); line-height: 1.35; }
    .tgid-sec__row { display: flex; align-items: flex-start; gap: calc(1.5 * var(--tgid-u)); margin-bottom: calc(.7 * var(--tgid-u)); }
    .tgid-sec__row svg { flex-shrink: 0; margin-top: calc(.3 * var(--tgid-u)); color: var(--tgid-forest); }
    .tgid-sec__rows ul { margin: 0; padding-left: calc(3.2 * var(--tgid-u)); }
    .tgid-sec__rows li { margin-bottom: calc(.45 * var(--tgid-u)); }

    .tgid-conditions {
        margin: auto 0 0; padding-top: calc(1 * var(--tgid-u)); text-align: center;
        font-size: calc(1.8 * var(--tgid-u)); line-height: 1.35; color: var(--tgid-muted);
    }

    /* The back's foot is a band rather than a curve — it carries three lines of
       address and a curve would eat the first of them. */
    .tgid-foot {
        position: relative; flex-shrink: 0; padding: calc(1.8 * var(--tgid-u)) calc(4.2 * var(--tgid-u));
        background: linear-gradient(20deg, var(--tgid-forest) 0%, var(--tgid-forest-deep) 100%);
        /* 2.1 mm = 6 pt, the floor below which fine print stops being print.
           It was 1.85 mm (5.2 pt) and the office could not read its own address
           on the card. */
        color: #EAF1EA; font-size: calc(2.1 * var(--tgid-u)); line-height: 1.35; text-align: center;
    }
    /* NO CURVE HERE, and the comment above is why — I added one anyway on the
       first pass and it swallowed the first line of the address, because the
       ellipse reaches 4 mm down into the band. A straight gold rule does the
       same separating job and costs no height. */
    .tgid-foot { border-top: calc(.5 * var(--tgid-u)) solid var(--tgid-gold); }
    /* Centred, because the band is a plaque rather than a list — everything
       else on this side is centred and a left-ragged block under it read as a
       mistake. */
    .tgid-foot__row { position: relative; z-index: 1; display: flex; align-items: flex-start;
                 justify-content: center; gap: calc(1.5 * var(--tgid-u)); text-align: left; }
    .tgid-foot__row + .tgid-foot__row { margin-top: calc(1.1 * var(--tgid-u)); padding-top: calc(1.1 * var(--tgid-u)); border-top: calc(.2 * var(--tgid-u)) solid rgba(255,255,255,.22); }
    .tgid-foot__row svg { flex-shrink: 0; margin-top: calc(.25 * var(--tgid-u)); color: var(--tgid-gold); }
    .tgid-foot__row span + svg { margin-left: calc(2 * var(--tgid-u)); }

    /* ==================== THE SHEET THE CARD PRINTS ON ====================
       A4 LANDSCAPE, and it has to be said out loud.

       This was the one printable page in the project with no @page rule, and
       the omission is what the office felt. Two A6 cards side by side need
       218mm; A4 PORTRAIT offers about 190mm of printable width, so the faces
       wrapped, the pair then needed 308mm of height against A4's ~277mm, and
       the browser's "fit to printable area" shrank the whole thing to make it
       fit. A card that is printed at 88% is not an A6 card, and no amount of
       care over the millimetres inside it survives that.

       A4 landscape is 297 x 210mm. Two A6 side by side with their gap are
       222 x 148mm.

       THE PAGE MARGINS ARE WHAT CENTRE THE PAIR ON THE PAPER.
       This used to be a flat 10mm, which keeps the printer's grippers off the
       artwork and says nothing about where the content sits inside what is
       left — and a block-level flex row starts at the top-left of it. The
       office's preview showed both cards jammed into the corner of an otherwise
       empty sheet, with no room to cut them out by hand.

       The obvious fix, a full-height flex container, was tried and MEASURED, and
       Chrome emits a second, blank sheet for every version of it: height 100%,
       calc(100% - 2px), and 100% with overflow hidden all paginated. A block
       that fills the page's content box is a rounding error away from spilling,
       and the office would simply find a blank page in the tray.

       So the page box is sized to the content instead. 31mm top and bottom
       leaves exactly 148mm — the card's own height — and the horizontal centring
       is done by flex inside the remaining 277mm. One sheet, centred.

       31mm IS DERIVED FROM THE CARD: (210 - 148) / 2. @page cannot read a custom
       property, so resizing the card means recomputing this by hand. It is not
       left to memory: scratchpad/id-sheet.cjs asserts the margins on the paper
       and fails if they drift. */
    @page { size: A4 landscape; margin: 31mm 10mm; }

    @media print {
        .tgid-controls, .tgid-hint, .no-print { display: none !important; }

        html, body { margin: 0; }

        .tgid-root { --tgid-s: 1; }
        .tgid-stage-outer, .tgid-stage { width: auto; height: auto; transform: none; perspective: none; margin: 0; }

        /* THE FLIP IS A SCREEN AFFORDANCE, NOT A DOCUMENT.
           Paper has no back to turn to, so everything 3D is undone and the two
           faces are laid out side by side at exactly 100%. Without this the
           printer would emit one face and a blank rectangle. */
        .tgid-flip {
            position: static; width: auto; height: auto;
            transform: none !important; transform-style: flat; transition: none;
            display: flex; justify-content: center; align-items: center;
            gap: calc(8 * var(--tgid-u));
            /* NOT flex-wrap: wrap. Wrapping is what silently turned one
               landscape sheet into two stacked cards taller than the paper. If
               the pair no longer fits, that should be found here rather than
               absorbed by the printer shrinking the card. */
            flex-wrap: nowrap;
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

            /* AND NO TRANSITION ON THE WAY THERE.
               The screen rule delays visibility by .3s so the far face does not
               wink out mid-flip. That delay applies here too: the moment the
               media becomes print, the front face is still hidden for another
               three hundred milliseconds — and whether it is visible in the
               snapshot the printer receives is then a race. Measured straight
               after the switch it read "hidden / visible": one card and one
               blank rectangle, which is exactly the failure the rules above
               were written to prevent. */
            transition: none !important;
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

        /* Lifted off its ancestors, which are still laid out where the dialog
           put them — centred in a scrolled sheet. `inset: 0` hands it the whole
           page area instead of just the origin, and the flex centring then puts
           the pair in the middle of the paper, matching what the standalone page
           prints. Pinning it to top/left alone is what jammed both cards into
           the corner of the sheet. */
        html.tgid-printing .tgid-stage-outer {
            /* FIXED, NOT ABSOLUTE. An absolutely positioned box is placed
               against its nearest positioned ancestor, and measured here that
               ancestor is the <dialog> itself — 33mm tall and offset inside a
               scrolled admin shell, so `inset: 0` sized the stage to the dialog
               and the pair landed 30mm off the left edge of the paper and 57mm
               above its top. In paged media a fixed box is placed against the
               PAGE, which is the frame this actually wants. */
            position: fixed; inset: 0; margin: 0;
            display: flex; align-items: center; justify-content: center;
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
                <div class="tgid-band">
                    <?php /* BOTH MARKS, as the office asked on 2026-09-18: the
                             Municipality is the authority the card is issued under,
                             the Tourism Office is the office that issued it. The
                             tourism mark is omitted rather than substituted when its
                             file is missing — a wrong mark on an ID is worse than
                             one mark. */ ?>
                    <span class="tgid-crests">
                        <img class="tgid-crest" src="<?= e($tgidSeal) ?>" alt="">
                        <?php if ($tgidCrestMark !== null): ?>
                            <img class="tgid-crest tgid-crest--mark" src="<?= e($tgidCrestMark) ?>" alt="">
                        <?php endif; ?>
                    </span>
                </div>
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
                    /* Past 22 characters a name needs a second line, past 30 a third.
                       Two steps down keep every real name inside the card without ever
                       truncating one — an ID that abbreviates a legal name is not an ID.

                       The thresholds survived the move to A6: the card grew 1.57x wider
                       while the type grew 1.5x with --tgid-u, so the characters that fit
                       on a line changed by about 5% — not enough to move either step. If
                       --tgid-w and --tgid-u ever stop scaling together, re-measure these. */
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

                <?php /* WHO ISSUED IT, where a decorative slogan used to sit.
                         The band carried "Promoting Tampakan, Welcoming the World."
                         in a cursive face at 3.5mm on dark green. It had been
                         reworked twice for legibility and the office still could not
                         read it on the printed card — a thin script at that size on
                         that ground does not survive a printer, whatever the arc
                         above it does.

                         What belongs in the last band of an official ID is the
                         authority that issued it, which is also the thing the office
                         asked for. The slogan moved to the back, where it is dark
                         text on cream and can actually be read. */ ?>
                <?php if ($tgidOfficials !== []): ?>
                    <div class="tgid-sign">
                        <?php foreach ($tgidOfficials as $o): ?>
                            <div class="tgid-sign__who">
                                <b><?= e($o['name']) ?></b>
                                <span><?= e($o['position']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                </div>
            </div>

            <!-- ============================ BACK ============================ -->
            <?php /* No QR here. Deliberately — see the note at the top of this file. */ ?>
            <div class="tgid-face tgid-face--back">
                <div class="tgid-card">
                <div class="tgid-band">
                    <?php /* BOTH MARKS, as the office asked on 2026-09-18: the
                             Municipality is the authority the card is issued under,
                             the Tourism Office is the office that issued it. The
                             tourism mark is omitted rather than substituted when its
                             file is missing — a wrong mark on an ID is worse than
                             one mark. */ ?>
                    <span class="tgid-crests">
                        <img class="tgid-crest" src="<?= e($tgidSeal) ?>" alt="">
                        <?php if ($tgidCrestMark !== null): ?>
                            <img class="tgid-crest tgid-crest--mark" src="<?= e($tgidCrestMark) ?>" alt="">
                        <?php endif; ?>
                    </span>
                </div>
                <?php if ($tgidWatermark !== null): ?><img class="tgid-watermark" src="<?= e($tgidWatermark) ?>" alt=""><?php endif; ?>

                <?php /* Three or more credentials and the back tightens a step — see
                         the measured table beside .tgid-back__body in the stylesheet.
                         Counted from what is RENDERED, not from what the guide holds:
                         the list is capped at five below, and a guide with eight
                         credentials shows the same five as a guide with five. */
                    $tgidShown = min(count($tgidCreds), 5);
                ?>
                <div class="tgid-back__body<?= $tgidShown >= 3 ? ' tgid-back__body--tight' : '' ?>">
                    <h2 class="tgid-back__office"><?= e($tgidOfficeName) ?></h2>
                    <?php /* The office's slogan, moved here off the front's last band.
                             On the front it was near-white cursive on dark green and
                             unreadable on paper at every size it was tried. Here it is
                             dark green on cream — the same words, at a contrast the
                             printer cannot lose. */ ?>
                    <p class="tgid-slogan">Promoting <?= e($tgidTown) ?>, Welcoming the World.</p>
                    <div class="tgid-rule" style="width:60%; margin:calc(1.4 * var(--tgid-u)) auto 0"><span>&#10022;</span></div>

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
