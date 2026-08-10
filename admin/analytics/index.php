<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Insights;
use App\Core\ReportBuilder;

Auth::require();

$pageTitle    = 'Analytics';
$pageIcon     = 'fa-chart-line';
$pageSubtitle = 'Trends, comparisons, and visitor composition';

$months  = max(3, min((int) ($_GET['months'] ?? 12), 36));
$start   = date('Y-m-01', strtotime("-" . ($months - 1) . " months"));
$end     = date('Y-m-d');

$report  = ReportBuilder::build('custom', ['start' => $start, 'end' => $end]);
$history = Insights::monthlyHistory($months);
$trend   = Insights::trend(min(6, $months));
$hasData = $report['totals']['visitors'] > 0;

require __DIR__ . '/../_partials/head.php';
?>

<div class="toolbar">
    <form method="get" class="toolbar__filters">
        <label class="filter-date">Period
            <select name="months" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ([3, 6, 12, 24, 36] as $m): ?>
                    <option value="<?= $m ?>" <?= $months === $m ? 'selected' : '' ?>>Last <?= $m ?> months</option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
    <a href="<?= e(base_url('/admin/reports/index.php')) ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fa-solid fa-file-lines"></i> Generate a formal report
    </a>
</div>

<?php if (!$hasData): ?>

    <div class="panel"><div class="panel__body">
        <div class="empty">
            <i class="fa-solid fa-chart-line"></i>
            <p><strong>No arrivals recorded in this period.</strong></p>
            <p>Analytics are computed from recorded arrivals. Nothing here is sample data —
               the charts appear once visitors begin using the digital logbook.</p>
        </div>
    </div></div>

<?php else: ?>

<div class="stat-grid">
    <article class="stat-card stat-card--green">
        <div class="stat-card__icon"><i class="fa-solid fa-users"></i></div>
        <div class="stat-card__body">
            <p class="stat-card__value"><?= n($report['totals']['visitors']) ?></p>
            <p class="stat-card__label">Visitors in period</p>
        </div>
    </article>
    <article class="stat-card stat-card--blue">
        <div class="stat-card__icon"><i class="fa-solid fa-calendar-day"></i></div>
        <div class="stat-card__body">
            <p class="stat-card__value"><?= e((string) $report['totals']['daily_avg']) ?></p>
            <p class="stat-card__label">Average per day</p>
        </div>
    </article>
    <article class="stat-card stat-card--teal">
        <div class="stat-card__icon"><i class="fa-solid fa-user-group"></i></div>
        <div class="stat-card__body">
            <p class="stat-card__value"><?= e((string) $report['totals']['avg_party']) ?></p>
            <p class="stat-card__label">Average party size</p>
        </div>
    </article>
    <article class="stat-card stat-card--amber">
        <div class="stat-card__icon"><i class="fa-solid fa-<?= $trend['available'] && $trend['direction'] === 'falling' ? 'arrow-trend-down' : 'arrow-trend-up' ?>"></i></div>
        <div class="stat-card__body">
            <p class="stat-card__value">
                <?= $trend['available'] && $trend['change_pct'] !== null ? ($trend['change_pct'] > 0 ? '+' : '') . $trend['change_pct'] . '%' : '—' ?>
            </p>
            <p class="stat-card__label">Recent trend</p>
        </div>
    </article>
</div>

<section class="panel">
    <header class="panel__head"><h2><i class="fa-solid fa-chart-area"></i> Monthly Arrivals</h2></header>
    <div class="panel__body">
        <div class="chart-box chart-box--wide"><canvas id="historyChart"></canvas></div>
        <?php if ($trend['available']): ?>
            <p class="report-note mt-3">
                <strong>Method:</strong> <?= e($trend['method']) ?><br>
                <strong>Limitation:</strong> <?= e($trend['limitation']) ?>
            </p>
        <?php endif; ?>
    </div>
</section>

<div class="chart-row">
    <section class="panel">
        <header class="panel__head"><h2><i class="fa-solid fa-chart-column"></i> Destination Comparison</h2></header>
        <div class="panel__body"><div class="chart-box chart-box--tall"><canvas id="destChart"></canvas></div></div>
    </section>

    <section class="panel">
        <header class="panel__head"><h2><i class="fa-solid fa-chart-pie"></i> Visitor Mix</h2></header>
        <div class="panel__body"><div class="chart-box"><canvas id="typeChart"></canvas></div></div>
    </section>
</div>

<div class="chart-row">
    <section class="panel">
        <header class="panel__head"><h2><i class="fa-solid fa-cake-candles"></i> Age Groups</h2></header>
        <div class="panel__body"><div class="chart-box"><canvas id="ageChart"></canvas></div></div>
    </section>

    <section class="panel">
        <header class="panel__head"><h2><i class="fa-solid fa-calendar-week"></i> Day of Week</h2></header>
        <div class="panel__body"><div class="chart-box chart-box--wide"><canvas id="weekdayChart"></canvas></div></div>
    </section>
</div>

<div class="report-grid report-grid--three">
    <?php foreach ([
        'cities'    => ['Top Origins — Cities', 'fa-city'],
        'provinces' => ['Top Origins — Provinces', 'fa-map'],
        'countries' => ['Top Origins — Countries', 'fa-globe'],
    ] as $key => $meta):
        if ($report['origins'][$key] === []) continue; ?>
        <section class="panel">
            <header class="panel__head"><h2><i class="fa-solid <?= e($meta[1]) ?>"></i> <?= e($meta[0]) ?></h2></header>
            <div class="panel__body">
                <table class="table table-sm mb-0">
                    <tbody>
                    <?php foreach ($report['origins'][$key] as $o): ?>
                        <tr><td><?= e($o['place']) ?></td><td class="text-end num"><?= n($o['visitors']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<?php
$chartData = json_encode([
    'history'  => ['labels' => array_column($history, 'label'), 'values' => array_column($history, 'visitors')],
    'moving'   => $trend['available'] ? Insights::movingAverage(array_column($history, 'visitors'), 3) : [],
    'dest'     => [
        'labels' => array_column(array_slice($report['destinations'], 0, 8), 'name'),
        'values' => array_map('intval', array_column(array_slice($report['destinations'], 0, 8), 'visitors')),
    ],
    'types'    => ['labels' => ['Local', 'Domestic', 'Foreign', 'Overseas Filipino'], 'values' => array_values($report['types'])],
    'age'      => ['labels' => array_values(App\Repositories\ArrivalRepository::AGE_BRACKETS + ['not_stated' => 'Not stated']),
                   'values' => array_values($report['demographics']['age'])],
    'weekday'  => ['labels' => array_column($report['peak']['weekdays'], 'day'),
                   'values' => array_map('intval', array_column($report['peak']['weekdays'], 'visitors'))],
], JSON_UNESCAPED_UNICODE);

$pageScripts = '
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (typeof Chart === "undefined") return;
    const D = ' . $chartData . ';

    Chart.defaults.font.family = "\'Poppins\', -apple-system, sans-serif";
    Chart.defaults.color = "#7B8791";

    const GREEN = "#2E7D32", BLUE = "#0288D1", TEAL = "#00796B", AMBER = "#EF6C00";
    const noLegend = { plugins: { legend: { display: false } }, responsive: true, maintainAspectRatio: false };

    const h = document.getElementById("historyChart");
    if (h && D.history.labels.length) {
        const ctx = h.getContext("2d");
        const fill = ctx.createLinearGradient(0, 0, 0, 240);
        fill.addColorStop(0, "rgba(46,125,50,.22)");
        fill.addColorStop(1, "rgba(46,125,50,0)");

        new Chart(ctx, {
            type: "line",
            data: { labels: D.history.labels, datasets: [
                { label: "Visitors", data: D.history.values, borderColor: GREEN, backgroundColor: fill,
                  borderWidth: 2, fill: true, tension: .35, pointRadius: 2, pointHoverRadius: 5 },
                { label: "3-month average", data: D.moving, borderColor: BLUE, borderWidth: 1.5,
                  borderDash: [5, 4], fill: false, tension: .35, pointRadius: 0 }
            ]},
            options: { responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: "bottom", labels: { boxWidth: 12, font: { size: 11 } } } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } } }
        });
    }

    const d = document.getElementById("destChart");
    if (d && D.dest.labels.length) {
        new Chart(d, { type: "bar",
            data: { labels: D.dest.labels, datasets: [{ data: D.dest.values, backgroundColor: BLUE, borderRadius: 4 }] },
            options: Object.assign({ indexAxis: "y" }, noLegend,
                { scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: { grid: { display: false }, ticks: { font: { size: 11 } } } } }) });
    }

    const t = document.getElementById("typeChart");
    if (t) {
        new Chart(t, { type: "doughnut",
            data: { labels: D.types.labels, datasets: [{ data: D.types.values, backgroundColor: [GREEN, BLUE, AMBER, TEAL], borderWidth: 0 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: "62%",
                plugins: { legend: { position: "bottom", labels: { boxWidth: 12, padding: 10, font: { size: 11 } } } } } });
    }

    const a = document.getElementById("ageChart");
    if (a) {
        new Chart(a, { type: "bar",
            data: { labels: D.age.labels, datasets: [{ data: D.age.values, backgroundColor: TEAL, borderRadius: 4 }] },
            options: Object.assign({}, noLegend,
                { scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false }, ticks: { font: { size: 10 } } } } }) });
    }

    const w = document.getElementById("weekdayChart");
    if (w && D.weekday.labels.length) {
        new Chart(w, { type: "radar",
            data: { labels: D.weekday.labels, datasets: [{ data: D.weekday.values,
                backgroundColor: "rgba(46,125,50,.18)", borderColor: GREEN, borderWidth: 2, pointBackgroundColor: GREEN }] },
            options: Object.assign({}, noLegend, { scales: { r: { beginAtZero: true, ticks: { precision: 0 } } } }) });
    }
})();
</script>';

require __DIR__ . '/../_partials/foot.php';
