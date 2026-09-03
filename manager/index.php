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
 * WHAT THIS SCREEN IS FOR
 *
 * A manager has three jobs — file the arrival report, attach the paper logbook,
 * raise an alert when the site is in trouble — and one question they cannot
 * answer without help: is my destination busier or quieter than it was, and am
 * I behind on anything.
 *
 * So the top half answers the question and the bottom half is the reference
 * material. The trend, the compliance progress and the four things worth doing
 * sit above the fold; the destination's own details and the QR address, which
 * are read once and then remembered, sit below it.
 *
 * THE CHART IS ONE QUERY, NOT THREE.
 *
 * The three ranges are built in PHP from a single ninety-five-day pull. Three
 * round trips for what is one table scan would be three chances for the filter
 * to feel slow on a phone with one bar, and the whole point of the filter is
 * that it answers instantly.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database;
use App\Core\ManagerAuth;
use App\Core\QrService;
use App\Repositories\InspectionRepository as Inspections;

ManagerAuth::require();

$destinationId = (int) ManagerAuth::destinationId();

$destination = Database::first(
    'SELECT d.*, c.name AS category_name
       FROM destinations d
       LEFT JOIN categories c ON c.id = d.category_id
      WHERE d.id = ?',
    [$destinationId]
);

/* The figures already on record for this destination. */
$recorded = Database::first(
    "SELECT
        COUNT(*)                         AS records,
        COALESCE(SUM(total_visitors), 0) AS visitors,
        MAX(visit_date)                  AS latest
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

// -----------------------------------------------------------------------------
// The trend
// -----------------------------------------------------------------------------

/* Ninety-five days rather than ninety: "last 3 months" is measured back from
   the first of the month three months ago, which on the 31st is more than 90
   days, and a series that quietly starts mid-month would understate its own
   first column. */
$daily = [];

foreach (Database::all(
    "SELECT visit_date, COALESCE(SUM(total_visitors), 0) AS v
       FROM tourist_arrivals
      WHERE destination_id = ? AND status = 'valid'
        AND visit_date >= (CURDATE() - INTERVAL 95 DAY)
        AND visit_date <= CURDATE()
      GROUP BY visit_date",
    [$destinationId]
) as $row) {
    $daily[(string) $row['visit_date']] = (int) $row['v'];
}

/* A DAY WITH NO ARRIVALS IS A ZERO, NOT A GAP.
   Plotting only the days that have rows draws a line between last Tuesday and
   this Friday as though the days between did not happen, which reads as steady
   traffic across a closure. Every day in range is emitted. */
$series = static function (int $days, string $label) use ($daily): array {
    $out = [];
    $today = new DateTimeImmutable('today');

    for ($i = $days - 1; $i >= 0; $i--) {
        $day = $today->sub(new DateInterval('P' . $i . 'D'));
        $key = $day->format('Y-m-d');
        $out[] = ['t' => $day->format($label), 'v' => $daily[$key] ?? 0];
    }

    return $out;
};

$week  = $series(7, 'D');           // Mon, Tue…
$month = $series((int) date('j'), 'j');   // 1..today, so "this month" means this month

/* Thirteen weeks, summed. Three monthly points is not a line, and ninety daily
   ones on a phone is a smear. */
$quarter = [];
$today   = new DateTimeImmutable('today');
$weekEnd = $today;

for ($w = 0; $w < 13; $w++) {
    $start = $weekEnd->sub(new DateInterval('P6D'));
    $sum   = 0;

    for ($d = 0; $d < 7; $d++) {
        $sum += $daily[$start->add(new DateInterval('P' . $d . 'D'))->format('Y-m-d')] ?? 0;
    }

    array_unshift($quarter, ['t' => $start->format('M j'), 'v' => $sum]);
    $weekEnd = $start->sub(new DateInterval('P1D'));
}

$chart = [
    'week'    => $week,
    'month'   => $month,
    'quarter' => $quarter,
];

$hasTrend = array_sum(array_column($week, 'v'))
          + array_sum(array_column($month, 'v'))
          + array_sum(array_column($quarter, 'v')) > 0;

// -----------------------------------------------------------------------------
// Compliance
// -----------------------------------------------------------------------------

$inspection = Inspections::openFor($destinationId);
$inspItems  = Inspections::items((int) $inspection['id']);

/* MEASURED AGAINST WHAT ACTUALLY BLOCKS THE SUBMIT, WHICH IS NOT EVERY STANDARD.
 *
 * The first version of this meter counted all five and reported "5 still to
 * photograph" while the inspection page, two clicks away, said "4 required
 * standard(s) still have no photo". Only required standards gate the submit —
 * a destination with no restroom cannot photograph a clean one, so the optional
 * ones are exempt. A manager reading the dashboard would have thought they were
 * one photograph further from being able to send than they were.
 *
 * missingRequired() is the gate itself, so the number here and the number that
 * decides whether Submit works cannot drift apart. Deriving it a second time is
 * what caused the disagreement. */
$inspMissing = Inspections::missingRequired((int) $inspection['id']);

$inspRequired = 0;
$inspOptional = 0;
$inspPhotos   = 0;
$optionalDone = 0;

foreach ($inspItems as $it) {
    $inspPhotos += (int) $it['photo_count'];

    if ((int) $it['is_required'] === 1) {
        $inspRequired++;
        continue;
    }

    $inspOptional++;

    if ((int) $it['photo_count'] > 0) {
        $optionalDone++;
    }
}

$inspPending = count($inspMissing);
$inspDone    = $inspRequired - $inspPending;
$inspPct     = $inspRequired > 0 ? (int) round(($inspDone / $inspRequired) * 100) : 100;

$pageTitle = 'Dashboard';
$pageIcon  = 'fa-gauge-high';

require __DIR__ . '/_partials/head.php';
?>

<!-- ===================== WHAT IS ON RECORD ===================== -->
<div class="stat-grid">
    <?php
    $cards = [
        ['icon' => 'fa-calendar-day', 'tone' => 'green', 'value' => n((int) $thisMonth['visitors']), 'label' => 'Visitors this month'],
        ['icon' => 'fa-users',        'tone' => 'blue',  'value' => n((int) $recorded['visitors']),  'label' => 'Visitors all time'],
        ['icon' => 'fa-list-check',   'tone' => 'teal',  'value' => n((int) $recorded['records']),   'label' => 'Records'],
        ['icon' => 'fa-clock',        'tone' => 'amber',
         'value' => $recorded['latest'] ? e(format_date((string) $recorded['latest'], 'M j')) : '&mdash;',
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

<!-- ===================== TREND + COMPLIANCE + ACTIONS ===================== -->
<div class="mgr-row">

    <!-- ---------- the trend ---------- -->
    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-solid fa-chart-line"></i> Visitor Arrivals</h2>

            <?php /* Radio inputs, not buttons: the choice is one of three and a
                     radio group says so to a screen reader without any ARIA. */ ?>
            <div class="mgr-chips mgr-chips--range" role="radiogroup" aria-label="Range">
                <?php foreach ([
                    'week'    => 'This Week',
                    'month'   => 'This Month',
                    'quarter' => 'Last 3 Months',
                ] as $key => $label): ?>
                    <label class="mgr-chip">
                        <input type="radio" name="mgrRange" value="<?= e($key) ?>"
                               <?= $key === 'month' ? 'checked' : '' ?>>
                        <span><?= e($label) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </header>

        <div class="panel__body">
            <?php if (!$hasTrend): ?>
                <?php /* No invented curve. An empty chart that draws a flat line
                         at zero looks like a broken chart; this says which it is. */ ?>
                <div class="mgr-empty">
                    <i class="fa-solid fa-chart-line"></i>
                    <p class="mb-1"><strong>No arrivals recorded yet</strong></p>
                    <p class="text-muted small mb-0">
                        The line appears once the Office approves an arrival report for
                        <?= e((string) $destination['name']) ?>.
                    </p>
                </div>
            <?php else: ?>
                <div class="mgr-chart"><canvas id="mgrTrend"></canvas></div>
                <p class="text-muted small mt-2 mb-0" id="mgrTrendNote"></p>
            <?php endif; ?>
        </div>
    </section>

    <!-- ---------- compliance, then the four things worth doing ---------- -->
    <div class="mgr-stack">

        <section class="panel">
            <header class="panel__head">
                <h2><i class="fa-solid fa-clipboard-check"></i> Compliance Inspection</h2>
                <span class="pill pill--<?= $inspection['status'] === 'approved' ? 'ok'
                    : ($inspection['status'] === 'rejected' ? 'flag' : 'qr') ?>">
                    <?= e(Inspections::STATUSES[$inspection['status']]) ?>
                </span>
            </header>

            <div class="panel__body">
                <div class="mgr-meter">
                    <div class="mgr-meter__bar">
                        <span style="width: <?= $inspPct ?>%"></span>
                    </div>
                    <p class="mgr-meter__read">
                        <strong><?= n($inspDone) ?></strong> of <?= n($inspRequired) ?> required standards have evidence
                        <span class="mgr-meter__pct"><?= $inspPct ?>%</span>
                    </p>
                </div>

                <p class="mgr-meter__sub">
                    <?php if ($inspPending > 0): ?>
                        <i class="fa-solid fa-camera"></i>
                        <?= n($inspPending) ?> still to photograph before you can send
                    <?php else: ?>
                        <i class="fa-solid fa-circle-check"></i>
                        Every required standard has a photo &mdash; ready to send
                    <?php endif; ?>
                    <span class="cell-sub">&middot; <?= n($inspPhotos) ?> photo<?= $inspPhotos === 1 ? '' : 's' ?> sent</span>
                </p>

                <?php /* The optional ones, said separately rather than folded into
                         the count. They do not hold the submit up, but a manager
                         who never hears about them will never send one. */ ?>
                <?php if ($inspOptional > 0): ?>
                    <p class="mgr-meter__sub">
                        <i class="fa-regular fa-square-check"></i>
                        <?= n($optionalDone) ?> of <?= n($inspOptional) ?> optional standard<?= $inspOptional === 1 ? '' : 's' ?>
                        photographed <span class="cell-sub">&middot; not required to send</span>
                    </p>
                <?php endif; ?>

                <a class="btn btn-sm btn-brand" href="<?= e(base_url('/manager/inspection.php')) ?>">
                    <i class="fa-solid fa-clipboard-check"></i> View Inspection
                </a>
            </div>
        </section>

        <section class="panel">
            <header class="panel__head">
                <h2><i class="fa-solid fa-bolt"></i> Quick Actions</h2>
            </header>

            <div class="panel__body">
                <div class="mgr-actions">
                    <?php foreach ([
                        ['alert.php',       'fa-triangle-exclamation', 'Report an Alert',        'urgent'],
                        ['inspection.php',  'fa-clipboard-check',      'Compliance Inspection',  ''],
                        ['reports.php',     'fa-file-lines',           'Tourist Arrival Reports', ''],
                        ['account.php',     'fa-user-gear',            'My Account',             ''],
                    ] as [$href, $icon, $label, $tone]): ?>
                        <a class="mgr-action <?= $tone !== '' ? 'mgr-action--' . e($tone) : '' ?>"
                           href="<?= e(base_url('/manager/' . $href)) ?>">
                            <i class="fa-solid <?= e($icon) ?>" aria-hidden="true"></i>
                            <span><?= e($label) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </div>
</div>

<!-- ===================== REFERENCE: THE SITE AND ITS SIGN ===================== -->
<div class="mgr-row">

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
                Maintained by the Municipal Tourism Office. If any of it is wrong, tell them
                &mdash; you do not need to travel there to have it corrected.
            </p>
        </div>
    </section>

    <?php /* THE QR PANEL, COMPACTED.
             It was a paragraph, an address, a button and a second paragraph.
             The address and the button are the only parts anybody comes back
             for; the explanation is one line under them now. */ ?>
    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-solid fa-qrcode"></i> Your QR code</h2>
        </header>
        <div class="panel__body">
            <div class="mgr-qr">
                <code class="mgr-qr__url"><?= e(QrService::url((string) $destination['qr_token'])) ?></code>
                <a href="<?= e(QrService::url((string) $destination['qr_token'])) ?>"
                   class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Open
                </a>
            </div>

            <p class="text-muted small mb-0">
                What a tourist reaches by scanning the sign: destination information, hotlines,
                directions and cultural background &mdash; not the logbook, which stays on paper.
                If the sign is damaged or missing, tell the Office and they will issue a
                replacement, which retires the old code.
            </p>
        </div>
    </section>
</div>

<?php if ($hasTrend): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
/* The three ranges, already built server-side from one query. Switching is a
   relabel and a redraw — no request, so it answers on a bad signal. */
(function () {
    var DATA = <?= json_encode($chart, JSON_THROW_ON_ERROR) ?>;
    var NOTE = {
        week:    'Daily, the last seven days.',
        month:   'Daily, from the first of this month.',
        quarter: 'Weekly totals, the last thirteen weeks.'
    };

    var canvas = document.getElementById('mgrTrend');
    var note   = document.getElementById('mgrTrendNote');

    if (!canvas || typeof window.Chart === 'undefined') { return; }

    var chart = new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: { labels: [], datasets: [{
            data: [],
            borderColor: '#1B5E20',
            backgroundColor: 'rgba(27, 94, 32, .10)',
            borderWidth: 2,
            pointRadius: 2,
            pointHoverRadius: 4,
            tension: .3,
            fill: true
        }] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(0,0,0,.06)' } },
                x: { grid: { display: false } }
            }
        }
    });

    function draw(key) {
        var rows = DATA[key] || [];

        chart.data.labels = rows.map(function (r) { return r.t; });
        chart.data.datasets[0].data = rows.map(function (r) { return r.v; });
        chart.update();

        if (note) {
            var total = rows.reduce(function (a, r) { return a + r.v; }, 0);
            note.textContent = NOTE[key] + ' ' + total.toLocaleString() + ' visitor'
                             + (total === 1 ? '' : 's') + ' in this range.';
        }
    }

    Array.prototype.forEach.call(
        document.querySelectorAll('input[name="mgrRange"]'),
        function (input) {
            input.addEventListener('change', function () { draw(input.value); });
        }
    );

    var picked = document.querySelector('input[name="mgrRange"]:checked');
    draw(picked ? picked.value : 'month');
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/_partials/foot.php'; ?>
