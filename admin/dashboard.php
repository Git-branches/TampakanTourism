<?php
declare(strict_types=1);

/**
 * TourSync — administrative dashboard.      Feature 2 / Problem 2
 *
 * Every figure is counted from the database at load, then refreshed by polling
 * so an officer with the screen open sees arrivals appear without reloading.
 * Nothing here is a placeholder: an empty system honestly reports zero.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Repositories\ArrivalRepository;

Auth::require();

$pageTitle    = 'Dashboard';
$pageIcon     = 'fa-gauge-high';
$pageSubtitle = 'Municipal tourism at a glance';

$stats       = ArrivalRepository::dashboardStats();
$mostVisited = Database::first(
    "SELECT d.name, COALESCE(SUM(s.total_visitors), 0) AS visitors
       FROM destinations d
       LEFT JOIN arrival_daily_summary s ON s.destination_id = d.id
      WHERE d.status = 'active'
      GROUP BY d.id, d.name
      ORDER BY visitors DESC
      LIMIT 1"
);

$recentArrivals = ArrivalRepository::recent(8);
$hasData        = $stats['records'] > 0;

/* Day-over-day movement, shown only when there is a yesterday to compare to. */
$delta = null;
if ($stats['yesterday'] > 0) {
    $delta = round((($stats['today'] - $stats['yesterday']) / $stats['yesterday']) * 100);
}

$cards = [
    ['key' => 'today', 'label' => "Today's Arrivals", 'value' => $stats['today'], 'icon' => 'fa-user-clock',   'tone' => 'green'],
    ['key' => 'month', 'label' => 'This Month',       'value' => $stats['month'], 'icon' => 'fa-calendar-day', 'tone' => 'blue'],
    ['key' => 'total', 'label' => 'Total Recorded',   'value' => $stats['total'], 'icon' => 'fa-users',        'tone' => 'teal'],
    ['key' => 'destinations', 'label' => 'Active Destinations', 'value' => $stats['destinations'], 'icon' => 'fa-mountain-sun', 'tone' => 'amber'],
];

require __DIR__ . '/_partials/head.php';
?>

<div class="live-bar">
    <span class="live-dot" aria-hidden="true"></span>
    <span>Live — refreshing every 30 seconds</span>
    <span class="live-bar__time">Updated <time id="lastUpdated"><?= e(date('g:i:s A')) ?></time></span>
</div>

<?php if ($stats['flagged'] > 0): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-flag"></i>
        <strong><?= n($stats['flagged']) ?> record<?= $stats['flagged'] === 1 ? '' : 's' ?> flagged for review</strong>
        — held out of every figure below until an officer approves them.
        <a href="<?= e(base_url('/admin/arrivals/index.php?status=flagged')) ?>" class="alert-link">Review</a>
    </div>
<?php endif; ?>

<!-- ===================== COUNTERS ===================== -->
<div class="stat-grid">
    <?php foreach ($cards as $card): ?>
        <article class="stat-card stat-card--<?= e($card['tone']) ?>">
            <div class="stat-card__icon"><i class="fa-solid <?= e($card['icon']) ?>"></i></div>
            <div class="stat-card__body">
                <p class="stat-card__value" data-stat="<?= e($card['key']) ?>"><?= n($card['value']) ?></p>
                <p class="stat-card__label"><?= e($card['label']) ?></p>
                <?php if ($card['key'] === 'today' && $delta !== null): ?>
                    <p class="stat-card__delta <?= $delta >= 0 ? 'is-up' : 'is-down' ?>">
                        <i class="fa-solid fa-arrow-<?= $delta >= 0 ? 'up' : 'down' ?>"></i>
                        <?= abs($delta) ?>% vs yesterday
                    </p>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</div>

<?php if (!$hasData): ?>
    <div class="panel panel--notice">
        <div class="panel__body">
            <h2><i class="fa-solid fa-qrcode"></i> Waiting for the first arrival</h2>
            <p>
                The counters above are querying live tables and honestly report zero — no sample
                data is displayed anywhere in this system. They begin moving the moment a visitor
                scans a destination QR code and submits the digital logbook.
            </p>
            <p class="mb-0">
                <a href="<?= e(base_url('/admin/qrcodes/index.php')) ?>" class="btn btn-brand btn-sm">
                    <i class="fa-solid fa-print"></i> Print the QR posters
                </a>
                <a href="<?= e(base_url('/admin/arrivals/manual.php')) ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fa-solid fa-pen"></i> Record one manually
                </a>
            </p>
        </div>
    </div>
<?php endif; ?>

<!-- ===================== CHARTS ===================== -->
<div class="chart-row">
    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-solid fa-chart-line"></i> Arrivals — Last 30 Days</h2>
        </header>
        <div class="panel__body">
            <?php if ($hasData): ?>
                <div class="chart-box"><canvas id="trendChart"></canvas></div>
            <?php else: ?>
                <div class="empty empty--sm"><p>The trend line appears once arrivals are recorded.</p></div>
            <?php endif; ?>
        </div>
    </section>

    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-solid fa-chart-pie"></i> Visitor Mix</h2>
        </header>
        <div class="panel__body">
            <?php if ($hasData): ?>
                <div class="chart-box"><canvas id="typeChart"></canvas></div>
            <?php else: ?>
                <div class="empty empty--sm"><p>No visitor data yet.</p></div>
            <?php endif; ?>
        </div>
    </section>
</div>

<div class="panel-row">
    <!-- ===================== RECENT ARRIVALS ===================== -->
    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-solid fa-clock-rotate-left"></i> Recent Arrivals</h2>
            <a href="<?= e(base_url('/admin/arrivals/index.php')) ?>" class="panel__link">View all</a>
        </header>
        <div class="panel__body">
            <?php if ($recentArrivals === []): ?>
                <div class="empty">
                    <i class="fa-solid fa-inbox"></i>
                    <p><strong>No arrivals recorded yet.</strong></p>
                    <p>Records will appear here as soon as visitors begin using the digital logbook.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Time</th><th>Destination</th><th>Type</th><th class="text-end">Visitors</th><th></th></tr></thead>
                        <tbody id="recentBody">
                        <?php foreach ($recentArrivals as $row): ?>
                            <tr>
                                <td><?= e(format_date($row['arrived_at'], 'M j, g:i A')) ?></td>
                                <td><?= e($row['destination_name']) ?></td>
                                <td><span class="tag"><?= e(ucfirst(str_replace('_', ' ', $row['tourist_type']))) ?></span></td>
                                <td class="text-end num"><?= n($row['total_visitors']) ?></td>
                                <td class="text-end">
                                    <?php if ($row['status'] === 'flagged'): ?>
                                        <span class="pill pill--flag">Flagged</span>
                                    <?php elseif ($row['status'] === 'voided'): ?>
                                        <span class="pill pill--void">Voided</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <div class="panel-stack">
        <section class="panel">
            <header class="panel__head"><h2><i class="fa-solid fa-trophy"></i> Most Visited</h2></header>
            <div class="panel__body">
                <?php if ($mostVisited === null || (int) $mostVisited['visitors'] === 0): ?>
                    <div class="empty empty--sm"><p>No arrivals recorded yet.</p></div>
                <?php else: ?>
                    <p class="highlight" data-stat="most_visited_name"><?= e($mostVisited['name']) ?></p>
                    <p class="text-muted"><span data-stat="most_visited_count"><?= n($mostVisited['visitors']) ?></span> visitors recorded</p>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <header class="panel__head"><h2><i class="fa-solid fa-chart-column"></i> By Destination</h2></header>
            <div class="panel__body">
                <?php if ($hasData): ?>
                    <div class="chart-box chart-box--tall"><canvas id="destChart"></canvas></div>
                <?php else: ?>
                    <div class="empty empty--sm"><p>Comparison appears once arrivals exist.</p></div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<?php
$chartUrl = base_url('/api/admin/chart.php');
$statsUrl = base_url('/api/admin/stats.php');

// Interpolated into the heredoc below as a JavaScript literal. Defined here
// rather than patched in afterwards: a heredoc expands {$var} itself, so a
// placeholder left for str_replace would be consumed before it ever ran.
$hasDataJs = $hasData ? 'true' : 'false';

$pageScripts = <<<HTML
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    const HAS_DATA  = {$hasDataJs};
    const STATS_URL = '{$statsUrl}';
    const CHART_URL = '{$chartUrl}';

    /* ---------------------------------------------------------------
       Counter polling. Refreshes numbers in place so an officer with
       the dashboard open sees arrivals appear without reloading.
       --------------------------------------------------------------- */
    async function refreshStats() {
        /* Polling a hidden tab burns the visitor's data and the server's
           capacity for a screen nobody is looking at. */
        if (document.hidden) return;

        try {
            const res = await fetch(STATS_URL, { credentials: 'same-origin' });
            if (!res.ok) return;
            const data = await res.json();

            document.querySelectorAll('[data-stat]').forEach((el) => {
                const key = el.dataset.stat;
                if (key === 'most_visited_name' && data.most_visited) {
                    el.textContent = data.most_visited.name;
                } else if (key === 'most_visited_count' && data.most_visited) {
                    el.textContent = data.most_visited.visitors.toLocaleString();
                } else if (typeof data[key] === 'number') {
                    const next = data[key].toLocaleString();
                    if (el.textContent !== next) {
                        el.textContent = next;
                        el.classList.remove('is-bumped');
                        void el.offsetWidth;          // restart the animation
                        el.classList.add('is-bumped');
                    }
                }
            });

            const stamp = document.getElementById('lastUpdated');
            if (stamp) {
                stamp.textContent = new Date().toLocaleTimeString([], {
                    hour: 'numeric', minute: '2-digit', second: '2-digit'
                });
            }
        } catch (e) {
            /* A failed poll is not worth interrupting the officer over —
               the next one in 30 seconds will most likely succeed. */
        }
    }

    setInterval(refreshStats, 30000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshStats(); });

    /* ---------------------------------------------------------------
       Charts
       --------------------------------------------------------------- */
    if (!HAS_DATA || typeof Chart === 'undefined') return;

    Chart.defaults.font.family = "'Poppins', -apple-system, 'Segoe UI', sans-serif";
    Chart.defaults.color = '#7B8791';

    const GREEN = '#2E7D32', BLUE = '#0288D1', TEAL = '#00796B', AMBER = '#EF6C00';

    async function series(name, extra) {
        const res = await fetch(CHART_URL + '?series=' + name + (extra || ''), { credentials: 'same-origin' });
        return res.ok ? res.json() : null;
    }

    series('trend', '&days=30').then((d) => {
        const el = document.getElementById('trendChart');
        if (!el || !d) return;

        const ctx = el.getContext('2d');
        const fill = ctx.createLinearGradient(0, 0, 0, 220);
        fill.addColorStop(0, 'rgba(46,125,50,.25)');
        fill.addColorStop(1, 'rgba(46,125,50,0)');

        new Chart(ctx, {
            type: 'line',
            data: { labels: d.labels, datasets: [{
                label: 'Visitors', data: d.values,
                borderColor: GREEN, backgroundColor: fill,
                borderWidth: 2, fill: true, tension: .35,
                pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: GREEN
            }]},
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#EDF1EE' } },
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } }
                }
            }
        });
    });

    series('types').then((d) => {
        const el = document.getElementById('typeChart');
        if (!el || !d) return;
        new Chart(el, {
            type: 'doughnut',
            data: { labels: d.labels, datasets: [{ data: d.values,
                backgroundColor: [GREEN, BLUE, AMBER, TEAL], borderWidth: 0 }]},
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '62%',
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 12, font: { size: 11 } } } }
            }
        });
    });

    series('destinations').then((d) => {
        const el = document.getElementById('destChart');
        if (!el || !d) return;
        new Chart(el, {
            type: 'bar',
            data: { labels: d.labels, datasets: [{ data: d.values, backgroundColor: BLUE, borderRadius: 4 }]},
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#EDF1EE' } },
                    y: { grid: { display: false }, ticks: { font: { size: 11 } } }
                }
            }
        });
    });
})();
</script>
HTML;

require __DIR__ . '/_partials/foot.php';
