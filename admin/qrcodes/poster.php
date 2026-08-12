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
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
        font-family: 'Poppins', -apple-system, 'Segoe UI', sans-serif;
        background: #E8ECE9;
        padding: 1.5rem;
        color: #1C2529;
    }

    .print-bar {
        max-width: 148mm;
        margin: 0 auto 1.2rem;
        display: flex;
        gap: .6rem;
        justify-content: space-between;
        align-items: center;
    }
    .print-bar p { font-size: .82rem; color: #4A5761; }
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

    .poster__seal { width: 22mm; height: 22mm; object-fit: contain; margin-bottom: 3mm; }

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
    .poster__code img, .poster__code canvas { display: block; }

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

    .poster__steps { text-align: left; width: 100%; margin-bottom: auto; }
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
        margin-top: 6mm;
        font-size: 7.5pt;
        color: #7B8791;
        display: flex;
        justify-content: space-between;
    }

    @media print {
        @page { size: A5 portrait; margin: 0; }
        body { background: #fff; padding: 0; }
        .print-bar { display: none; }
        .poster { box-shadow: none; margin: 0; width: 100%; min-height: 100vh; }
    }
</style>
</head>
<body>

<div class="print-bar">
    <p><?= count($destinations) ?> poster<?= count($destinations) === 1 ? '' : 's' ?> · A5 portrait · print or save as PDF</p>
    <div>
        <a href="index.php">Back</a>
        <button onclick="window.print()">Print</button>
    </div>
</div>

<?php foreach ($destinations as $d): ?>
<div class="poster">
    <img class="poster__seal" src="<?= e(asset('img/tampakan_logo.png')) ?>"
         alt="Seal of the Municipality of Tampakan">

    <p class="poster__office">Municipal Tourism Office</p>
    <p class="poster__muni">Municipality of Tampakan &middot; South Cotabato</p>

    <p class="poster__prompt">Welcome! Please log your visit.</p>
    <p class="poster__sub">It takes less than a minute and helps us serve visitors better.</p>

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

<script src="<?= e(asset('js/vendor/qrcode.min.js')) ?>"></script>
<script>
document.querySelectorAll('[data-qr]').forEach(function (el) {
    new QRCode(el, {
        text: el.dataset.qr,
        width: 190,
        height: 190,
        correctLevel: QRCode.CorrectLevel.H
    });
});
</script>
</body>
</html>
