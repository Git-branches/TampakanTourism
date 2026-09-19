<?php
declare(strict_types=1);

/**
 * Printable A5 QR poster for on-site signage.
 *
 * A print stylesheet rather than a generated PDF: producing a PDF would mean a
 * Composer dependency the brief's cPanel requirement rules out, and the
 * browser's own print dialogue already saves to PDF. One less moving part.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\QrService;

Auth::require();

/* The last gate before ink.
 *
 * Disabling the buttons on the index page is a courtesy; this is the guard.
 * Anyone can reach poster.php by typing the address, and the whole point of
 * the check is that the mistake it prevents is invisible and permanent — a
 * laminated sign that opens nothing, discovered by a tourist months later.
 *
 * Refusing here costs an officer one trip to Settings. Not refusing costs the
 * municipality a reprint of every sign in the field. */
if (!QrService::isPublishable()) {
    http_response_code(409);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Cannot print yet — TourSync</title>
        <link rel="icon" href="<?= e(asset('img/tourism-logo-mark.png')) ?>" type="image/png">
        <style>
            body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1.5rem;
                   font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: #F4F6F4; color: #16211A; }
            .box { max-width: 34rem; background: #fff; border: 1px solid #DCE5DE; border-left: 4px solid #C62828;
                   border-radius: 12px; padding: 1.8rem; line-height: 1.65; }
            h1 { margin: 0 0 .6rem; font-size: 1.3rem; }
            p  { margin: 0 0 .8rem; color: #43514A; }
            code { background: #F1F3F2; padding: .12rem .4rem; border-radius: 4px; }
            a { color: #2E7D32; font-weight: 600; }
        </style>
    </head>
    <body>
        <div class="box">
            <h1>These posters cannot be printed yet</h1>
            <p>
                The codes would point at <code><?= e(QrService::publicBase()) ?>/d/&hellip;</code> —
                <?= e(QrService::unpublishableReason()) ?>
            </p>
            <p>
                A sign printed now would be mounted in the field and open nothing, and nobody would
                find out until a visitor tried it.
            </p>
            <p>
                <a href="<?= e(base_url('/admin/settings/index.php')) ?>">Set the public website address</a>,
                then print.
            </p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if (!empty($_GET['all'])) {
    $destinations = Database::all(
        "SELECT id, name, barangay, qr_token, qr_version FROM destinations
          WHERE status = 'active' ORDER BY name"
    );
} else {
    $id = (int) ($_GET['id'] ?? 0);
    $one = Database::first(
        "SELECT id, name, barangay, qr_token, qr_version FROM destinations WHERE id = ?",
        [$id]
    );
    $destinations = $one !== null ? [$one] : [];
}

if ($destinations === []) {
    http_response_code(404);
    exit('Destination not found.');
}

$instructions = QrService::posterInstructions();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>QR Poster — <?= count($destinations) === 1 ? e($destinations[0]['name']) : 'All Destinations' ?></title>

<?php /* This is the sheet that gets printed and mounted at the destination, so
         the tab it is printed from should not carry XAMPP's logo. */ ?>
<link rel="icon" href="<?= e(asset('img/tourism-logo-mark.png')) ?>" type="image/png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
        font-family: 'Poppins', -apple-system, 'Segoe UI', sans-serif;
        background: #E8ECE9;
        padding: 1.5rem;
        color: #1C2529;
    }

    /* WIDER THAN ONE POSTER, AND IT HAS TO BE.
       This was capped at 148mm — the width of a single A5 sheet, about 559px —
       which was fine when the bar held six words. The moment it also carried
       the note about the browser's own headers, the text filled the whole cap,
       crushed the buttons against the right edge and wrapped them onto their
       own line looking broken. It is a toolbar, not a page element: it spans
       the working area, and the buttons never shrink. */
    .print-bar {
        max-width: 1500px;
        margin: 0 auto 1.2rem;
        display: flex;
        gap: .6rem 1.2rem;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
    }
    .print-bar p { font-size: .82rem; color: #4A5761; max-width: 62ch; }
    .print-bar > div { display: flex; gap: .6rem; flex-shrink: 0; }
    .print-bar button, .print-bar a { white-space: nowrap; flex-shrink: 0; }
    .print-bar button, .print-bar a {
        font: inherit;
        font-size: .84rem;
        font-weight: 600;
        padding: .5rem 1.1rem;
        border-radius: 7px;
        border: 0;
        cursor: pointer;
        text-decoration: none;
        background: #2E7D32;
        color: #fff;
    }
    .print-bar a { background: #fff; color: #2E7D32; border: 1px solid #CBD8CD; }

    /* ---- Many posters, on screen only ----
       zoom rather than transform: scale. A scaled element keeps its original
       footprint in the layout, so ten of them would still reserve ten full A5
       heights and the scrolling would be exactly as long with smaller pictures
       in it. zoom reflows, so the grid really does get shorter. It is also why
       the poster's millimetre sizes still hold: zoom scales them with
       everything else, and print resets it to 1. */
    @media screen {
        .poster-sheets--many {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.4rem;
            justify-items: center;
            align-items: start;
            max-width: 1500px;
            margin: 0 auto;
        }
        .poster-sheets--many .poster { zoom: .58; margin: 0; }
    }

    /* ---- The poster itself: A5 portrait ---- */
    .poster {
        width: 148mm;
        min-height: 210mm;
        margin: 0 auto 1.5rem;
        background: #fff;
        padding: 14mm 12mm;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        box-shadow: 0 6px 24px rgba(0, 0, 0, .12);
        page-break-after: always;
        border-top: 8mm solid #2E7D32;
    }

    /* The seal and the office's own mark side by side, at the height the seal
       alone had — a row adds width, not height, so the poster still fits the
       sheet it was sized for. */
    .poster__seals { display: flex; justify-content: center; gap: 4mm; margin-bottom: 3mm; }
    .poster__seal { width: 22mm; height: 22mm; object-fit: contain; }

    .poster__office {
        font-size: 9pt;
        font-weight: 600;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #2E7D32;
        line-height: 1.4;
    }
    .poster__muni { font-size: 8pt; color: #7B8791; margin-bottom: 6mm; }

    .poster__prompt {
        margin-top: auto;       /* half the slack, above the middle block */
        font-size: 15pt;
        font-weight: 700;
        color: #1C2529;
        margin-bottom: 1mm;
    }
    .poster__sub { font-size: 9.5pt; color: #4A5761; margin-bottom: 6mm; }

    .poster__code {
        padding: 4mm;
        border: 2px solid #E2E8E4;
        border-radius: 4mm;
        margin-bottom: 5mm;
        line-height: 0;
    }
    /* SIZED IN MILLIMETRES, GENERATED MUCH LARGER.
       The library drew the code at 190px and it was placed at its natural size
       — about 50mm, and 190 dots of it however good the printer. Generated at
       512 and laid out in mm, the same square carries enough pixels to stay
       crisp on paper, and 56mm is a comfortable scan from arm's length at a
       trailhead. pixelated keeps the module edges square when the browser
       scales it; smoothing them is what makes a code hard to read. */
    .poster__code img, .poster__code canvas {
        display: block;
        width: 56mm;
        height: 56mm;
        image-rendering: pixelated;
    }

    /* The destination name is printed beneath the code on purpose: if someone
       covers a sign with a sticker pointing elsewhere, a visitor can see that
       the name on the sign and the page they landed on do not match. */
    .poster__name {
        font-size: 16pt;
        font-weight: 700;
        line-height: 1.2;
        margin-bottom: 1mm;
    }
    .poster__place { font-size: 9.5pt; color: #4A5761; margin-bottom: 6mm; }

    /* THE SHEET WAS TOP-LOADED WITH A HOLE ABOVE THE FOOTER.
       One auto margin, on the steps, pushed every bit of slack into a single
       band of nothing between the last instruction and the rule at the bottom —
       about a third of an A5 page. Two auto margins split that slack above and
       below the middle block instead, so the seal stays at the top, the footer
       stays at the bottom, and the poster reads as composed rather than as a
       page that ran out. */
    .poster__steps { text-align: left; width: 100%; margin-bottom: 0; }
    .poster__steps li {
        list-style: none;
        display: flex;
        gap: 3mm;
        font-size: 9.5pt;
        color: #4A5761;
        margin-bottom: 2.5mm;
        align-items: flex-start;
    }
    .poster__steps span {
        flex: 0 0 5mm;
        height: 5mm;
        border-radius: 50%;
        background: #E8F3E9;
        color: #2E7D32;
        font-size: 8pt;
        font-weight: 700;
        display: grid;
        place-items: center;
    }

    .poster__foot {
        width: 100%;
        border-top: 1px solid #E2E8E4;
        padding-top: 3mm;
        margin-top: auto;       /* the other half, below the steps */
        font-size: 7.5pt;
        color: #7B8791;
        display: flex;
        justify-content: space-between;
    }

    @media print {
        /* No margin box at all: it is what makes the page exactly the poster,
           and in Chrome it is also what lets the browser drop its own header
           and footer. The browser still has the final say — see the hint in
           the print bar. */
        @page { size: A5 portrait; margin: 0; }

        html, body {
            background: #fff;
            padding: 0;
            margin: 0;
            /* The chain that gives .poster a page to be 100% of. */
            height: 100%;
        }

        .print-bar { display: none !important; }

        /* The screen's grid and its shrink are undone: on paper each poster is
           a full A5 sheet again, one per page.

           height: 100% IS NOT TIDINESS, IT IS THE PAGINATION.
           .poster takes its height as a percentage, and a percentage needs a
           parent with a resolved height. This wrapper was added between the
           poster and <body> and defaulted to auto, which broke that chain —
           each poster fell back to its natural height, overshot, and ten
           posters came out of the printer as twenty pages. Passing the page
           height through restores it. */
        .poster-sheets,
        .poster-sheets--many { display: block; max-width: none; margin: 0; height: 100%; }
        .poster-sheets--many .poster { zoom: 1; }

        /* ONE POSTER, ONE PAGE.
           This was min-height: 100vh, and vh is the viewport rather than the
           sheet — a hair taller than A5 once rounded, which is why a single
           poster came out of the printer as "1/2" with a blank second page.
           A percentage of the page box cannot overdraw it the same way.

           break-after puts each poster on its own sheet when the office prints
           the whole set, and the last one drops it again so the run does not
           end on a blank page. */
        .poster {
            box-shadow: none;
            margin: 0;
            width: 100%;
            height: 100%;
            min-height: 0;
            overflow: hidden;
            break-inside: avoid;
            break-after: page;
            page-break-after: always;   /* older engines */
        }

        .poster:last-of-type {
            break-after: auto;
            page-break-after: auto;
        }

        /* The step numbers are white on green discs. Browsers drop background
           colours when printing unless told not to, which would leave the
           numbers white on white. */
        .poster__steps span {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
    }
</style>
</head>
<body>

<div class="print-bar">
    <?php /* THE ONE THING THIS PAGE CANNOT DO FOR THEM.
             The date, the localhost address and "1/2" that were coming out on
             the printed sign are drawn by the browser, not by anything here —
             no stylesheet can remove them. @page margin: 0 makes Chrome drop
             them by default, but the officer can turn them back on without
             realising, and then the sign that gets laminated has
             localhost/TampakanTourism across the top. So the tick box is named
             here, next to the button, rather than left to be discovered. */ ?>
    <p>
        <?= count($destinations) ?> poster<?= count($destinations) === 1 ? '' : 's' ?> · A5 portrait · print or save as PDF
        <span style="display:block; color:#7B8791; font-size:.76rem; margin-top:.15rem;">
            If a date or web address appears on the sheet, untick
            <strong>Headers and footers</strong> in the print dialogue &mdash; that part is the browser&rsquo;s, not ours.
        </span>
    </p>
    <div>
        <a href="index.php">Back</a>
        <button onclick="window.print()">Print</button>
    </div>
</div>

<?php /* SIDE BY SIDE ON SCREEN, ONE PER SHEET ON PAPER.
         Ten posters stacked at full A5 height is about eight thousand pixels of
         scrolling to check a set before printing it, and nobody checks the tenth
         one. The modifier is only added when there is more than one: a single
         poster is shown at its real size, because then the page IS the proof. */ ?>
<div class="poster-sheets<?= count($destinations) > 1 ? ' poster-sheets--many' : '' ?>">

<?php foreach ($destinations as $d): ?>
<div class="poster">
    <div class="poster__seals">
        <img class="poster__seal" src="<?= e(asset('img/tampakan_logo.png')) ?>"
             alt="Seal of the Municipality of Tampakan">
        <img class="poster__seal" src="<?= e(asset('img/tourism-logo-mark.png')) ?>"
             alt="Logo of the Tampakan Municipal Tourism Office">
    </div>

    <p class="poster__office">Municipal Tourism Office</p>
    <p class="poster__muni">Municipality of Tampakan &middot; South Cotabato</p>

    <?php /* "Please log your visit" was the old ask, from when this code led to
             a visitor logbook. It does not any more — the manager records
             arrivals now — so the sign asks for nothing and offers something
             instead. A sign that asks a tired visitor for a favour gets
             ignored; one that offers the emergency numbers gets scanned. */ ?>
    <p class="poster__prompt">Scan before you set off.</p>
    <p class="poster__sub">Emergency numbers, opening hours and the story of this place &mdash; on your phone.</p>

    <div class="poster__code" data-qr="<?= e(QrService::url($d['qr_token'])) ?>"></div>

    <p class="poster__name"><?= e($d['name']) ?></p>
    <p class="poster__place"><?= e($d['barangay'] ? 'Barangay ' . $d['barangay'] . ', Tampakan' : 'Tampakan, South Cotabato') ?></p>

    <ol class="poster__steps">
        <?php foreach ($instructions as $i => $step): ?>
            <li><span><?= $i + 1 ?></span><?= e($step) ?></li>
        <?php endforeach; ?>
    </ol>

    <div class="poster__foot">
        <span>Official tourism signage &middot; do not remove</span>
        <span>v<?= (int) $d['qr_version'] ?></span>
    </div>
</div>
<?php endforeach; ?>

</div><?php /* .poster-sheets */ ?>

<script src="<?= e(asset('js/vendor/qrcode.min.js')) ?>"></script>
<script>
document.querySelectorAll('[data-qr]').forEach(function (el) {
    new QRCode(el, {
        text: el.dataset.qr,
        width: 512,
        height: 512,
        correctLevel: QRCode.CorrectLevel.H
    });
});
</script>
</body>
</html>
