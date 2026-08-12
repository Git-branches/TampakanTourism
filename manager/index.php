<?php
declare(strict_types=1);

/**
 * TourSync — destination manager dashboard.                        Feature 2
 *
 * Everything on this page is filtered by ManagerAuth::destinationId(), which is
 * read from the session and set at sign-in. No query here takes a destination
 * from the request, so there is no id in a URL for anyone to edit into a
 * neighbouring destination's figures.
 *
 * Kept short on purpose. A manager has three jobs — file the arrival report,
 * attach the paper logbook, raise an alert when the site is in trouble — and a
 * dashboard that buries those under an officer's worth of panels makes the
 * common case slower for the person doing it on a phone at a waterfall.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database;
use App\Core\ManagerAuth;
use App\Core\QrService;

$pageTitle    = 'Dashboard';
$pageIcon     = 'fa-gauge-high';

require __DIR__ . '/_partials/head.php';

$destinationId = (int) ManagerAuth::destinationId();

$destination = Database::first(
    'SELECT d.*, c.name AS category_name
       FROM destinations d
       LEFT JOIN categories c ON c.id = d.category_id
      WHERE d.id = ?',
    [$destinationId]
);

/* The figures already on record for this destination. Until arrival reports
   exist (Priority 2) these come from the QR logbook rows; afterwards the same
   panel shows what the office has accepted. The manager sees their own site's
   numbers either way, which is the point of this screen. */
$recorded = Database::first(
    "SELECT
        COUNT(*)                                   AS records,
        COALESCE(SUM(total_visitors), 0)           AS visitors,
        MAX(visit_date)                            AS latest
       FROM tourist_arrivals
      WHERE destination_id = ? AND status = 'valid'",
    [$destinationId]
);

$thisMonth = Database::first(
    "SELECT COALESCE(SUM(total_visitors), 0) AS visitors
       FROM tourist_arrivals
      WHERE destination_id = ? AND status = 'valid'
        AND visit_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
    [$destinationId]
);
?>

<!-- ===================== THE DESTINATION ===================== -->
<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-mountain-sun"></i> <?= e((string) $destination['name']) ?></h2>
    </header>
    <div class="panel__body">
        <dl class="detail-grid">
            <div>
                <dt>Category</dt>
                <dd><?= e((string) ($destination['category_name'] ?: '—')) ?></dd>
            </div>
            <div>
                <dt>Barangay</dt>
                <dd><?= e((string) ($destination['barangay'] ?: '—')) ?></dd>
            </div>
            <div>
                <dt>Operating hours</dt>
                <dd><?= e((string) ($destination['operating_hours'] ?: 'Not recorded')) ?></dd>
            </div>
            <div>
                <dt>Entrance fee</dt>
                <dd><?= e((string) ($destination['entrance_fee'] ?: 'Not recorded')) ?></dd>
            </div>
        </dl>

        <p class="text-muted small mt-3 mb-0">
            These details are maintained by the Municipal Tourism Office and appear on the public
            page a tourist reaches by scanning your destination's QR code. If any of them is wrong,
            tell the Office &mdash; you do not need to travel there to have it corrected.
        </p>
    </div>
</section>

<!-- ===================== WHAT IS ON RECORD ===================== -->
<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-users"></i> Arrivals on record</h2>
    </header>
    <div class="panel__body">
        <!-- Same markup as the officer dashboard's cards so the shared
             stylesheet applies without a manager-only variant. -->
        <div class="stat-grid">
            <?php
            $cards = [
                ['icon' => 'fa-calendar-day', 'tone' => 'green', 'value' => n((int) $thisMonth['visitors']), 'label' => 'Visitors this month'],
                ['icon' => 'fa-users',        'tone' => 'blue',  'value' => n((int) $recorded['visitors']),  'label' => 'Visitors all time'],
                ['icon' => 'fa-list-check',   'tone' => 'teal',  'value' => n((int) $recorded['records']),   'label' => 'Records'],
                ['icon' => 'fa-clock',        'tone' => 'amber',
                 'value' => $recorded['latest'] ? e(format_date((string) $recorded['latest'], 'M j')) : '—',
                 'label' => 'Latest arrival'],
            ];
            foreach ($cards as $card): ?>
                <article class="stat-card stat-card--<?= e($card['tone']) ?>">
                    <div class="stat-card__icon"><i class="fa-solid <?= e($card['icon']) ?>"></i></div>
                    <div class="stat-card__body">
                        <p class="stat-card__value"><?= $card['value'] ?></p>
                        <p class="stat-card__label"><?= e($card['label']) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <!-- Said plainly, because the whole point of this account is that the
             manager stops making the trip. -->
        <p class="text-muted small mt-3 mb-0">
            Figures for <?= e((string) $destination['name']) ?> only. The Municipal Tourism Office
            sees the same numbers from their end &mdash; you do not need to deliver them in person.
        </p>
    </div>
</section>

<!-- ===================== THE QR SIGN ===================== -->
<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-qrcode"></i> Your destination's QR code</h2>
    </header>
    <div class="panel__body">
        <p class="mb-2">
            The code on the sign at your destination opens this page for tourists. It carries the
            destination information, the emergency and police hotlines, directions, and the
            cultural background &mdash; not the logbook, which stays on paper at the fill-up station.
        </p>

        <p class="mb-3"><code><?= e(QrService::url((string) $destination['qr_token'])) ?></code></p>

        <a href="<?= e(QrService::url((string) $destination['qr_token'])) ?>"
           class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> Open the visitor page
        </a>

        <p class="text-muted small mt-3 mb-0">
            If the sign is damaged, defaced, or missing, report it to the Municipal Tourism Office
            &mdash; they can issue a replacement code, which retires the old one.
        </p>
    </div>
</section>

<?php require __DIR__ . '/_partials/foot.php'; ?>
