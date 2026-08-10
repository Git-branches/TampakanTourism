<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\QrService;

Auth::require();

$pageTitle    = 'QR Codes';
$pageIcon     = 'fa-qrcode';
$pageSubtitle = 'One unique code per destination — print, laminate, and install on site';

$destinations = Database::all(
    "SELECT d.id, d.name, d.slug, d.barangay, d.qr_token, d.qr_version, d.qr_rotated_at,
            c.name AS category_name,
            COALESCE((SELECT COUNT(*) FROM tourist_arrivals a
                       WHERE a.destination_id = d.id AND a.source = 'qr'), 0) AS scans
       FROM destinations d
       LEFT JOIN categories c ON c.id = d.category_id
      WHERE d.status = 'active'
      ORDER BY d.name"
);

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

    <div class="toolbar">
        <p class="result-count mb-0"><?= n(count($destinations)) ?> destination<?= count($destinations) === 1 ? '' : 's' ?> with an active code</p>
        <a href="poster.php?all=1" target="_blank" rel="noopener" class="btn btn-brand btn-sm">
            <i class="fa-solid fa-print"></i> Print All Posters
        </a>
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
                    <a href="poster.php?id=<?= (int) $d['id'] ?>" target="_blank" rel="noopener"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="fa-solid fa-print"></i> Poster
                    </a>
                    <a href="<?= e(QrService::url($d['qr_token'])) ?>" target="_blank" rel="noopener"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i> Test
                    </a>
                    <?php if (Auth::isOfficer()): ?>
                        <form method="post" action="rotate.php" class="d-inline"
                              onsubmit="return confirm('Issue a new code for <?= e(addslashes($d['name'])) ?>?\n\nEvery sign already printed for this destination will stop working and must be replaced.');">
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
</script>';

require __DIR__ . '/../_partials/foot.php';
