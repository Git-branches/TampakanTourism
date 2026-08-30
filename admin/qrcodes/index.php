<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Paginator;
use App\Core\Auth;
use App\Core\Database;
use App\Core\QrService;

Auth::require();

$pageTitle    = 'QR Codes';
$pageIcon     = 'fa-qrcode';
$pageSubtitle = 'One unique code per destination — print, laminate, and install on site';

$pager        = Paginator::slice(Database::all(
    "SELECT d.id, d.name, d.slug, d.barangay, d.qr_token, d.qr_version, d.qr_rotated_at,
            c.name AS category_name,
            COALESCE((SELECT COUNT(*) FROM tourist_arrivals a
                       WHERE a.destination_id = d.id AND a.source = 'qr'), 0) AS scans
       FROM destinations d
       LEFT JOIN categories c ON c.id = d.category_id
      WHERE d.status = 'active'
      ORDER BY d.name"
), $_GET['page'] ?? null);

$destinations = $pager['rows'];

require __DIR__ . '/../_partials/head.php';
?>

<div class="panel panel--notice">
    <div class="panel__body">
        <h2><i class="fa-solid fa-circle-info"></i> How these codes work</h2>
        <p>
            Each code carries a random 32-character token, never the destination's database ID.
            A printed sign is public — anyone can read it with a phone — so a guessable identifier
            would let someone derive every other destination's URL and file arrivals for places
            they never visited.
        </p>
        <p class="mb-0">
            Rotating a token issues a new one and <strong>invalidates every sign already printed</strong>
            for that destination. Use it when signage is defaced, stolen, or replaced.
        </p>
    </div>
</div>

<?php if ($destinations === []): ?>

    <div class="panel"><div class="panel__body">
        <div class="empty">
            <i class="fa-solid fa-qrcode"></i>
            <p><strong>No active destinations yet.</strong></p>
            <p>Codes are generated automatically when a destination is created.</p>
            <p class="mt-3"><a href="<?= e(base_url('/admin/destinations/create.php')) ?>" class="btn btn-brand btn-sm">
                <i class="fa-solid fa-plus"></i> Add a destination</a></p>
        </div>
    </div></div>

<?php else: ?>

    <?php
    /* Printing is blocked, not merely warned about.
     *
     * A QR poster is the one artefact this system produces that cannot be
     * corrected after the fact: it gets laminated and bolted to a post at a
     * destination, and the failure is silent — nobody learns the code is dead
     * until a tourist is standing in front of it with a phone. So the address
     * is checked before the print button is offered at all. */
    $signageReady = QrService::isPublishable();
    ?>

    <?php if (!$signageReady): ?>
        <div class="alert alert-danger">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <strong>These codes cannot be printed yet.</strong>
            They point at <code><?= e(QrService::publicBase()) ?>/d/&hellip;</code> &mdash;
            <?= e(QrService::unpublishableReason()) ?>
            Set the public website address in
            <a href="<?= e(base_url('/admin/settings/index.php')) ?>" class="alert-link">Settings</a> first.

            <?php if (($rehearsal = QrService::rehearsalUrl()) !== ''): ?>
                <?php /* Demonstrating at the office on this laptop is a real
                         stage of this project, so the way through is named here
                         rather than left for the officer to work out. */ ?>
                <hr>
                <p class="mb-0">
                    Only showing the system to the office? This computer is reachable
                    on this network at <code><?= e($rehearsal) ?></code> &mdash;
                    set that as the address and a phone on the same WiFi can scan these codes.
                </p>
            <?php endif; ?>
        </div>
    <?php elseif (($qrDrift = QrService::drift()) !== ''): ?>
        <?php /* THE SILENT ONE. A rehearsal address is handed out by the router
                 and changes without saying so; every code here still renders
                 perfectly and opens nothing. This is louder than the LAN
                 caution below because the officer cannot see it any other
                 way. */ ?>
        <div class="alert alert-danger">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <strong>The address these codes carry is out of date.</strong>
            <?= e($qrDrift) ?>
        </div>
    <?php elseif (($qrWarning = QrService::warning()) !== ''): ?>
        <!-- A caution, not a gate. Printing on a LAN address is exactly what a
             rehearsal needs; the officer just has to know these sheets are not
             the ones that go on the posts. -->
        <div class="alert alert-warning">
            <i class="fa-solid fa-circle-info"></i>
            <strong>Test prints only.</strong>
            <?= e($qrWarning) ?>
        </div>
    <?php endif; ?>

    <div class="toolbar">
        <p class="result-count mb-0"><?= n(count($destinations)) ?> destination<?= count($destinations) === 1 ? '' : 's' ?> with an active code</p>
        <?php if ($signageReady): ?>
            <a href="poster.php?all=1" target="_blank" rel="noopener" class="btn btn-brand btn-sm">
                <i class="fa-solid fa-print"></i> Print All Posters
            </a>
        <?php else: ?>
            <button type="button" class="btn btn-brand btn-sm" disabled
                    title="Set the public website address in Settings before printing">
                <i class="fa-solid fa-print"></i> Print All Posters
            </button>
        <?php endif; ?>
    </div>

    <div class="qr-grid">
        <?php foreach ($destinations as $d): ?>
            <article class="qr-card">
                <div class="qr-card__code" data-qr="<?= e(QrService::url($d['qr_token'])) ?>"></div>

                <div class="qr-card__body">
                    <h3><?= e($d['name']) ?></h3>
                    <p class="qr-card__meta">
                        <?= e($d['barangay'] ? 'Barangay ' . $d['barangay'] : 'Tampakan') ?>
                        <?= $d['category_name'] ? ' · ' . e($d['category_name']) : '' ?>
                    </p>

                    <dl class="qr-card__facts">
                        <div><dt>Version</dt><dd>v<?= (int) $d['qr_version'] ?></dd></div>
                        <div><dt>Scans logged</dt><dd><?= n($d['scans']) ?></dd></div>
                        <div><dt>Rotated</dt><dd><?= $d['qr_rotated_at'] ? e(format_date($d['qr_rotated_at'], 'M j, Y')) : 'Never' ?></dd></div>
                    </dl>

                    <p class="qr-card__url"><code><?= e(QrService::url($d['qr_token'])) ?></code></p>
                </div>

                <div class="qr-card__actions">
                    <?php if ($signageReady): ?>
                        <a href="poster.php?id=<?= (int) $d['id'] ?>" target="_blank" rel="noopener"
                           class="btn btn-sm btn-outline-secondary">
                            <i class="fa-solid fa-print"></i> Poster
                        </a>
                    <?php else: ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                                title="Set the public website address in Settings before printing">
                            <i class="fa-solid fa-print"></i> Poster
                        </button>
                    <?php endif; ?>
                    <?php
                    /* A poster is printed FROM this system. A signmaker is not:
                       a tarpaulin shop wants a file emailed to them, and until
                       now the only way to give them one was a screenshot of a
                       150px preview.

                       Drawn at 1200px by the same library that draws the
                       preview, so the token still never leaves this machine.
                       Gated with the poster because it is the same artefact —
                       a file carrying a dead address is worse than no file,
                       since it comes back as a tarpaulin. */
                    ?>
                    <?php if ($signageReady): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                data-qr-download="<?= e(QrService::url($d['qr_token'])) ?>"
                                data-qr-name="<?= e($d['slug']) ?>-qr-v<?= (int) $d['qr_version'] ?>">
                            <i class="fa-solid fa-download"></i> PNG
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                                title="Set the public website address in Settings before downloading">
                            <i class="fa-solid fa-download"></i> PNG
                        </button>
                    <?php endif; ?>
                    <a href="<?= e(QrService::url($d['qr_token'])) ?>" target="_blank" rel="noopener"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i> Test
                    </a>
                    <?php if (Auth::isOfficer()): ?>
                        <?php
                        /* Rotating a code kills every laminated sign already
                           bolted up at that destination, so this asks through
                           the same dialog as every other irreversible action
                           rather than through the browser's own box. */
                        ?>
                        <form method="post" action="rotate.php" class="d-inline"
                              data-confirm="Issue a new code for <?= e($d['name']) ?>? Every sign already printed for this destination will stop working and must be replaced.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="fa-solid fa-rotate"></i> Rotate
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<?php
$pageScripts = '
<script src="' . e(asset('js/vendor/qrcode.min.js')) . '"></script>
<script>
/* Codes are drawn in the browser from the token, so no destination token is
   ever sent to an external QR service. */
document.querySelectorAll("[data-qr]").forEach(function (el) {
    new QRCode(el, {
        text: el.dataset.qr,
        width: 150,
        height: 150,
        correctLevel: QRCode.CorrectLevel.H   // survives rain damage and scuffing
    });
});

/* Handing the office an actual file.
   ---------------------------------------------------------------------------
   Drawn fresh at 1200px rather than scaled up from the 150px preview: a QR code
   enlarged by the browser gets soft edges, and a soft edge on a tarpaulin two
   metres away is the difference between a scan and a shrug. Error correction
   stays at H, the level that survives a scuffed, rained-on sign.

   The canvas is never added to the page. It is drawn, read, and dropped.

   toBlob rather than toDataURL — a 1200px code is a ~40KB data: URI, and some
   browsers refuse to navigate to one that size, which fails as a download that
   simply never happens. */
document.querySelectorAll("[data-qr-download]").forEach(function (button) {
    button.addEventListener("click", function () {
        var box = document.createElement("div");

        new QRCode(box, {
            text: button.dataset.qrDownload,
            width: 1200,
            height: 1200,
            correctLevel: QRCode.CorrectLevel.H
        });

        var canvas = box.querySelector("canvas");

        if (!canvas || !canvas.toBlob) { return; }

        canvas.toBlob(function (blob) {
            var url  = URL.createObjectURL(blob);
            var link = document.createElement("a");

            link.href     = url;
            link.download = button.dataset.qrName + ".png";
            document.body.appendChild(link);
            link.click();
            link.remove();

            /* Revoked on a delay: Firefox cancels an in-flight download when the
               object URL is released in the same tick as the click. */
            window.setTimeout(function () { URL.revokeObjectURL(url); }, 10000);
        }, "image/png");
    });
});
</script>';

require __DIR__ . '/../../app/views/partials/pager.php';
require __DIR__ . '/../_partials/foot.php';
