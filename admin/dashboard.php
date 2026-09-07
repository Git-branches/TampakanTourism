<?php
declare(strict_types=1);

/**
 * TourSync — the Tourism Office dashboard.        Feature 2 / Problem 2
 *
 * FOUR QUESTIONS, IN THIS ORDER
 *
 *   What needs me?            the Action Centre, first, because it is the
 *                             reason to open this screen
 *   How many are coming?      four figures, each against the period before it
 *   Which places are busy?    the top five, ranked, with their movement
 *   Is it rising or falling?  one traffic chart over a chosen window
 *
 * Everything past those four is analysis, and analysis lives on its own page.
 * A dashboard that shows every statistic it can reach is a report, and a report
 * is read once a month; this is read every morning.
 *
 * APPROVED RECORDS ONLY, EVERYWHERE.
 *
 * Every figure below filters status = 'valid'. A flagged record is one the
 * office has not accepted, and counting it here would put a number on the
 * screen that next month's DOT return contradicts. The banner at the top says
 * how many are being held out, so the exclusion is visible rather than silent.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Repositories\ArrivalRepository;
use App\Repositories\TourGuideRepository;

Auth::require();

$pageTitle    = 'Dashboard';
$pageIcon     = 'fa-gauge-high';
$pageSubtitle = 'What needs you, and how the municipality is doing';

// -----------------------------------------------------------------------------
// The window the panels beside the chart describe
// -----------------------------------------------------------------------------

/* Thirty days, fixed. These four panels used to follow the traffic chart's
   period selector; Visitor Trends now reaches twenty-four months, and "the
   busiest hour of the day" averaged over two years is not a staffing answer,
   it is noise. The last month is the window each of them was read at anyway. */
$sideWindow = 30;
$sideName   = 'the last 30 days';

$end       = date('Y-m-d');
$start     = date('Y-m-d', strtotime('-' . ($sideWindow - 1) . ' days'));
$prevEnd   = date('Y-m-d', strtotime('-' . $sideWindow . ' days'));
$prevStart = date('Y-m-d', strtotime('-' . (($sideWindow * 2) - 1) . ' days'));

// -----------------------------------------------------------------------------
// Figures
// -----------------------------------------------------------------------------

$stats  = ArrivalRepository::dashboardStats();

/* Percentage movement, or null when there is no earlier figure to divide by.
   "Up 100%" from a base of nothing is arithmetic pretending to be a finding. */
$change = static function (int|float $now, int|float $was): ?float {
    return $was > 0 ? round((($now - $was) / $was) * 100, 1) : null;
};

// -----------------------------------------------------------------------------
// Visitor Trends — six windows, one query
// -----------------------------------------------------------------------------

/* Daily and monthly are the same reading at two scales, so they are one card
   with a toggle rather than two charts asking the reader to notice that the
   x-axis changed. Monthly leads: this is a municipal office, and the question
   at a monthly meeting is which season is busy, not which Tuesday was.
 *
 * EVERY WINDOW IS COMPUTED HERE, ON THE SERVER, AND SENT WITH THE PAGE.
 *
 * The toggle is instant because there is nothing to fetch, and the four figures
 * under the chart can never describe a period the chart is not showing — both
 * come out of the same day-by-day series, filtered to status = 'valid' once.
 * A client-side switch that re-derived its own totals is exactly how those two
 * drift apart. */

$trendViews = [
    'monthly' => [
        'label'   => 'Monthly',
        'default' => '12m',
        'periods' => [
            '3m'  => ['label' => '3M',  'months' => 3],
            '6m'  => ['label' => '6M',  'months' => 6],
            '12m' => ['label' => '12M', 'months' => 12],
            '24m' => ['label' => '24M', 'months' => 24],
        ],
    ],
    'daily' => [
        'label'   => 'Daily',
        'default' => '30d',
        'periods' => [
            '7d'  => ['label' => '7D',  'days' => 7],
            '30d' => ['label' => '30D', 'days' => 30],
        ],
    ],
];

/* Far enough back for the longest window and the window before it: 24 months
   of bars compared against the 24 months preceding them. */
$seriesFrom = date('Y-m-01', strtotime('-47 months'));
$series     = ArrivalRepository::dailySeries($seriesFrom, $end);

/** Sums one inclusive slice of that series into the four figures shown. */
$sumSlice = static function (string $from, string $to) use ($series): array {
    $visitors = 0;
    $records  = 0;

    foreach ($series as $day => $row) {
        if ($day >= $from && $day <= $to) {
            $visitors += $row['visitors'];
            $records  += $row['records'];
        }
    }

    $days = max(1, (int) ((strtotime($to) - strtotime($from)) / 86400) + 1);

    return [
        'visitors'  => $visitors,
        'records'   => $records,
        'avg_party' => $records > 0 ? round($visitors / $records, 1) : 0.0,
        'daily_avg' => round($visitors / $days, 1),
    ];
};

$trends = [];

foreach ($trendViews as $viewKey => $view) {
    foreach ($view['periods'] as $periodKey => $p) {
        if ($viewKey === 'monthly') {
            $months    = $p['months'];
            $from      = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));
            $to        = $end;
            $priorFrom = date('Y-m-01', strtotime('-' . (($months * 2) - 1) . ' months'));
            $priorTo   = date('Y-m-t', strtotime('-' . $months . ' months'));

            /* Calendar months, so a bar is the month a reader means by it.
               The current month is partial and labelled as such below. */
            $labels = [];
            $values = [];
            for ($i = $months - 1; $i >= 0; $i--) {
                $monthStart = date('Y-m-01', strtotime("-{$i} months"));
                $monthEnd   = date('Y-m-t', strtotime("-{$i} months"));
                $labels[]   = date('M Y', strtotime($monthStart));
                $values[]   = $sumSlice($monthStart, min($monthEnd, $end))['visitors'];
            }

            $name = $months === 12 ? 'the last 12 months' : 'the last ' . $months . ' months';
        } else {
            $days      = $p['days'];
            $from      = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
            $to        = $end;
            $priorFrom = date('Y-m-d', strtotime('-' . (($days * 2) - 1) . ' days'));
            $priorTo   = date('Y-m-d', strtotime('-' . $days . ' days'));

            $labels = [];
            $values = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $d        = date('Y-m-d', strtotime("-{$i} days"));
                $labels[] = date('M j', strtotime($d));
                $values[] = $series[$d]['visitors'] ?? 0;
            }

            $name = 'the last ' . $days . ' days';
        }

        $now   = $sumSlice($from, $to);
        $prior = $sumSlice($priorFrom, $priorTo);

        $trends[$viewKey][$periodKey] = [
            'labels'    => $labels,
            'values'    => $values,
            'visitors'  => $now['visitors'],
            'daily_avg' => $now['daily_avg'],
            'avg_party' => $now['avg_party'],
            'change'    => $change($now['visitors'], $prior['visitors']),
            'name'      => $name,
        ];
    }
}

$trendView   = 'monthly';
$trendPeriod = $trendViews['monthly']['default'];

/* This month against the same stretch of last month, so a comparison made on
   the 4th is against the first four days of last month and not its whole run. */
$monthToDate = (int) date('j');
$lastMonthSoFar = (int) Database::scalar(
    "SELECT COALESCE(SUM(total_visitors), 0)
       FROM tourist_arrivals
      WHERE status = 'valid'
        AND visit_date BETWEEN ? AND ?",
    [
        date('Y-m-01', strtotime('first day of last month')),
        date('Y-m-d', strtotime('first day of last month +' . ($monthToDate - 1) . ' days')),
    ]
);

$monthChange = $change($stats['month'], $lastMonthSoFar);

$todayChange = $change($stats['today'], $stats['yesterday']);

$kpis = [
    [
        'key'    => 'today',
        'value'  => $stats['today'],
        'label'  => "Today's Visitors",
        'icon'   => 'fa-user-clock',
        'tone'   => 'green',
        'change' => $todayChange,
        'since'  => 'vs yesterday',
    ],
    [
        'key'    => 'month',
        'value'  => $stats['month'],
        'label'  => 'This Month',
        'icon'   => 'fa-calendar-day',
        'tone'   => 'blue',
        'change' => $monthChange,
        'since'  => 'vs same days last month',
    ],
    [
        'key'    => 'total',
        'value'  => $stats['total'],
        'label'  => 'Total Visitors',
        'icon'   => 'fa-users',
        'tone'   => 'teal',
        'change' => null,
        'since'  => 'approved records only',
    ],
    [
        'key'    => 'destinations',
        'value'  => $stats['destinations'],
        'label'  => 'Active Destinations',
        'icon'   => 'fa-mountain-sun',
        'tone'   => 'amber',
        'change' => null,
        'since'  => 'open to visitors',
    ],
];

$topDestinations = ArrivalRepository::topDestinations($start, $end, $prevStart, $prevEnd, 5);
$hourly          = ArrivalRepository::hourlyDistribution($start, $end);
$weekdays        = ArrivalRepository::weekdayAverages($start, $end);
$mix             = ArrivalRepository::typeBreakdown($start, $end);
$recentArrivals  = ArrivalRepository::recent(6);
$hasData         = $stats['records'] > 0;

/* The busiest hour and day, named rather than left for the reader to find in a
   chart. Staffing is the decision these two answer. */
$peakHour = null;
$peakDay  = null;

foreach ($hourly as $h) {
    if ($peakHour === null || $h['visitors'] > $peakHour['visitors']) { $peakHour = $h; }
}

foreach ($weekdays as $d) {
    if ($peakDay === null || $d['visitors'] > $peakDay['visitors']) { $peakDay = $d; }
}

if (($peakHour['visitors'] ?? 0) === 0) { $peakHour = null; }
if (($peakDay['visitors'] ?? 0) === 0)  { $peakDay = null; }

// -----------------------------------------------------------------------------
// What is waiting
// -----------------------------------------------------------------------------

/* Each of these is somebody outside the office waiting on a decision from
   inside it. Wrapped one at a time: a table that has not been migrated on some
   installation must not take the dashboard down with it. */
$countWaiting = static function (string $sql): int {
    try {
        return (int) Database::scalar($sql);
    } catch (\Throwable) {
        return 0;
    }
};

$actions = [
    ['label' => 'Arrival Reports', 'sub' => 'to review',   'icon' => 'fa-inbox',
     'href' => base_url('/admin/arrival-reports/index.php'),
     'n' => $countWaiting("SELECT COUNT(*) FROM arrival_reports WHERE status IN ('submitted','reviewing')")],

    ['label' => 'Compliance', 'sub' => 'to review', 'icon' => 'fa-clipboard-check',
     'href' => base_url('/admin/inspections/index.php'),
     'n' => $countWaiting("SELECT COUNT(*) FROM inspection_reports WHERE status IN ('submitted','reviewing')")],

    /* THIS COUNTED A STATUS THAT DOES NOT EXIST.
     *
     * It asked for status = 'pending'. That table's statuses are new,
     * acknowledged, assigned, completed, declined, cancelled and no_show —
     * there has never been a 'pending' among them, so the tile read 0 however
     * many visitors were waiting for an answer. It was wrong from the day it
     * was written and looked right, because a quiet tile looks like good news.
     *
     * openCount() is the definition the rest of the system already uses:
     * OPEN_STATUSES, 'new' and 'acknowledged', documented there as "the office
     * must act now". A fourth hand-written copy of that SQL is how the four
     * drift apart. */
    ['label' => 'Guide Requests', 'sub' => 'unanswered', 'icon' => 'fa-person-hiking',
     'href' => base_url('/admin/guides/index.php'),
     'n' => (static function (): int {
         try { return TourGuideRepository::openCount(); } catch (\Throwable) { return 0; }
     })()],

    ['label' => 'Alerts', 'sub' => 'unresolved', 'icon' => 'fa-triangle-exclamation',
     'href' => base_url('/admin/alerts/index.php'),
     'n' => $countWaiting("SELECT COUNT(*) FROM destination_alerts WHERE status <> 'resolved'")],

    ['label' => 'Change Requests', 'sub' => 'pending', 'icon' => 'fa-pen-to-square',
     'href' => base_url('/admin/change-requests/index.php'),
     'n' => $countWaiting("SELECT COUNT(*) FROM destination_change_requests WHERE status = 'pending'")],

    ['label' => 'Messages', 'sub' => 'unread', 'icon' => 'fa-envelope',
     'href' => base_url('/admin/messages/index.php'),
     'n' => $countWaiting("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'")],

    ['label' => 'Reviews', 'sub' => 'to moderate', 'icon' => 'fa-comment-dots',
     'href' => base_url('/admin/feedback/index.php'),
     'n' => $countWaiting("SELECT COUNT(*) FROM feedback WHERE status = 'pending'")],
];

$waiting = array_sum(array_column($actions, 'n'));

require __DIR__ . '/_partials/head.php';
?>

<div class="live-bar">
    <span class="live-dot" aria-hidden="true"></span>
    <span>Live &mdash; refreshing every 30 seconds</span>
    <span class="live-bar__time">Updated <time id="lastUpdated"><?= e(date('g:i:s A')) ?></time></span>
</div>

<?php if ($stats['flagged'] > 0): ?>
    <?php /* WHY A FIGURE IS MISSING, WITHOUT SENDING ANYONE ANYWHERE.
             These rows predate the current flow: nothing the system writes now
             can be flagged, because approving a report records every line as
             valid. They are held out of the totals below, and this says so —
             but there is no longer a screen that clears them one at a time, so
             it no longer offers a button that would lead nowhere. */ ?>
    <div class="flagbar">
        <i class="fa-solid fa-flag" aria-hidden="true"></i>
        <p>
            <strong><?= n($stats['flagged']) ?> older record<?= $stats['flagged'] === 1 ? '' : 's' ?> held out of these figures</strong>
            &mdash; carried over from before reports were reviewed, and not counted in any total below.
        </p>
    </div>
<?php endif; ?>

<!-- ===================== ACTION CENTRE =====================
     First on the page. Everything below is worth knowing; this is the part
     somebody is waiting on. -->
<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-bell"></i> Action Center</h2>
        <?php if ($waiting > 0): ?>
            <span class="pill pill--qr"><?= n($waiting) ?> waiting</span>
        <?php else: ?>
            <span class="pill pill--ok">Nothing outstanding</span>
        <?php endif; ?>
    </header>

    <div class="panel__body">
        <div class="act">
            <?php foreach ($actions as $a): ?>
                <?php /* A tile with nothing in it stays. "0 alerts" is an answer;
                         a row that vanishes when it empties teaches an officer to
                         wonder whether it broke. Only the ones with work in them
                         are given weight. */ ?>
                <a class="act__tile<?= $a['n'] > 0 ? ' is-due' : '' ?>" href="<?= e($a['href']) ?>">
                    <span class="act__icon"><i class="fa-solid <?= e($a['icon']) ?>" aria-hidden="true"></i></span>
                    <span class="act__n"><?= n($a['n']) ?></span>
                    <span class="act__label"><?= e($a['label']) ?></span>
                    <span class="act__sub"><?= e($a['sub']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ===================== FOUR FIGURES ===================== -->
<div class="kpi">
    <?php foreach ($kpis as $k): ?>
        <article class="kpi__card kpi__card--<?= e($k['tone']) ?>">
            <span class="kpi__icon"><i class="fa-solid <?= e($k['icon']) ?>" aria-hidden="true"></i></span>
            <p class="kpi__value" data-stat="<?= e($k['key']) ?>"><?= n($k['value']) ?></p>
            <p class="kpi__label"><?= e($k['label']) ?></p>

            <?php if ($k['change'] !== null): ?>
                <p class="kpi__delta <?= $k['change'] >= 0 ? 'is-up' : 'is-down' ?>">
                    <i class="fa-solid fa-arrow-<?= $k['change'] >= 0 ? 'up' : 'down' ?>" aria-hidden="true"></i>
                    <?= e(number_format(abs($k['change']), 1)) ?>%
                    <span><?= e($k['since']) ?></span>
                </p>
            <?php else: ?>
                <p class="kpi__delta is-flat"><span><?= e($k['since']) ?></span></p>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</div>

<?php /* A full-width "Waiting for the first arrival" panel used to sit here on an
         empty database. It was removed: the KPI row above already reads zero on
         every tile, so the panel spent a third of the screen repeating that, and
         the panels below carry their own empty wording. $hasData still gates
         those and the chart script. */ ?>

<!-- ===================== VISITOR TRENDS, THEN TOP FIVE AND MIX ===================== -->
<?php /* Full width. It is the reading an officer looks at longest, and a wide
         window shows a season where a narrow one showed a week. Top Destinations
         and Visitor Mix pair up underneath, filling the half-row the chart used
         to leave empty beside it. */ ?>
<section class="panel" id="trendPanel">
    <header class="panel__head panel__head--controls">
        <h2><i class="fa-solid fa-chart-column"></i> Visitor Trends</h2>

        <?php /* Two controls, one line: what scale, then how far back. Both are
                 buttons rather than links — every window is already on the page,
                 so switching is instant and the officer keeps their scroll. */ ?>
        <div class="trend__controls">
            <div class="rangeset rangeset--view" role="tablist" aria-label="Scale">
                <?php foreach ($trendViews as $key => $v): ?>
                    <button type="button" role="tab"
                            class="rangeset__opt<?= $key === $trendView ? ' is-active' : '' ?>"
                            aria-selected="<?= $key === $trendView ? 'true' : 'false' ?>"
                            data-trend-view="<?= e($key) ?>"><?= e($v['label']) ?></button>
                <?php endforeach; ?>
            </div>

            <?php /* One period strip per scale; only the active one is shown, so
                     the row keeps its height whichever is on. */ ?>
            <?php foreach ($trendViews as $key => $v): ?>
                <div class="rangeset trend__periods" data-trend-periods="<?= e($key) ?>"
                     aria-label="Period" <?= $key === $trendView ? '' : 'hidden' ?>>
                    <?php foreach ($v['periods'] as $pKey => $p): ?>
                        <button type="button"
                                class="rangeset__opt<?= ($key === $trendView && $pKey === $trendPeriod) ? ' is-active' : '' ?>"
                                data-trend-period="<?= e($pKey) ?>"><?= e($p['label']) ?></button>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </header>

    <div class="panel__body">
        <?php if ($hasData): ?>
            <?php $shown = $trends[$trendView][$trendPeriod]; ?>

            <div class="chart-box chart-box--trend"><canvas id="trendChart"></canvas></div>

            <?php /* Rendered by the server for the default view, then rewritten
                     in place by the toggle from the same numbers. A screen with
                     no JavaScript still shows the twelve-month reading rather
                     than four empty slots. */ ?>
            <dl class="summary" id="trendSummary">
                <div>
                    <dt>Visitors in period</dt>
                    <dd data-trend-figure="visitors"><?= n($shown['visitors']) ?></dd>
                </div>
                <div>
                    <dt>Average per day</dt>
                    <dd data-trend-figure="daily_avg"><?= e(number_format($shown['daily_avg'], 1)) ?></dd>
                </div>
                <div>
                    <dt>Average party size</dt>
                    <dd data-trend-figure="avg_party"><?= e(number_format($shown['avg_party'], 1)) ?></dd>
                </div>
                <div>
                    <dt>Change vs previous period</dt>
                    <dd data-trend-figure="change">
                        <?php if ($shown['change'] === null): ?>
                            <span class="summary__flat">no earlier period</span>
                        <?php else: ?>
                            <span class="<?= $shown['change'] >= 0 ? 'is-up' : 'is-down' ?>">
                                <i class="fa-solid fa-arrow-<?= $shown['change'] >= 0 ? 'up' : 'down' ?>" aria-hidden="true"></i>
                                <?= e(number_format(abs($shown['change']), 1)) ?>%
                            </span>
                        <?php endif; ?>
                    </dd>
                </div>
            </dl>
        <?php else: ?>
            <div class="empty empty--sm"><p>The trend appears once arrivals are recorded.</p></div>
        <?php endif; ?>
    </div>
</section>

<div class="dash-row">
    <!-- ---------- top five ---------- -->
    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-solid fa-ranking-star"></i> Top Destinations</h2>
            <a href="<?= e(base_url('/admin/destinations/index.php')) ?>" class="panel__link">View all</a>
        </header>

        <div class="panel__body">
            <?php if ($topDestinations === [] || (int) $topDestinations[0]['visitors'] === 0): ?>
                <div class="empty empty--sm"><p>No arrivals in <?= e($sideName) ?>.</p></div>
            <?php else: ?>
                <?php $best = max(1, (int) $topDestinations[0]['visitors']); ?>
                <ol class="rank">
                    <?php foreach ($topDestinations as $i => $d): ?>
                        <li class="rank__row">
                            <span class="rank__n"><?= $i + 1 ?></span>
                            <span class="rank__name"><?= e($d['name']) ?></span>

                            <?php /* The bar is the comparison the eye makes
                                     first; the number is for the one it
                                     cannot. Scaled to the leader, not to the
                                     panel, so second place looks like second
                                     place. */ ?>
                            <span class="rank__bar" aria-hidden="true">
                                <span style="width: <?= (int) round(((int) $d['visitors'] / $best) * 100) ?>%"></span>
                            </span>

                            <span class="rank__v"><?= n($d['visitors']) ?></span>

                            <span class="rank__d">
                                <?php if ($d['change'] === null): ?>
                                    <span class="is-flat">&mdash;</span>
                                <?php else: ?>
                                    <span class="<?= $d['change'] >= 0 ? 'is-up' : 'is-down' ?>">
                                        <i class="fa-solid fa-arrow-<?= $d['change'] >= 0 ? 'up' : 'down' ?>" aria-hidden="true"></i>
                                        <?= e(number_format(abs($d['change']), 0)) ?>%
                                    </span>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    </section>

    <!-- ---------- visitor mix ---------- -->
    <section class="panel">
        <header class="panel__head"><h2><i class="fa-solid fa-chart-pie"></i> Visitor Mix</h2></header>

        <div class="panel__body">
            <?php $mixTotal = array_sum($mix); ?>
            <?php if ($mixTotal === 0): ?>
                <div class="empty empty--sm"><p>No arrivals in <?= e($sideName) ?>.</p></div>
            <?php else: ?>
                <div class="mix">
                    <div class="mix__chart">
                        <canvas id="mixChart"></canvas>
                        <?php /* The total sits in the hole in the middle, where
                                 a doughnut leaves room for the one figure the
                                 slices are all fractions of. */ ?>
                        <span class="mix__total">
                            <b><?= n($mixTotal) ?></b>
                            <small>visitors</small>
                        </span>
                    </div>

                    <ul class="mix__key">
                        <?php foreach ([
                            'local'             => ['Local', 'local'],
                            'domestic'          => ['Domestic', 'domestic'],
                            'foreign'           => ['Foreign', 'foreign'],
                            'overseas_filipino' => ['Overseas Filipino', 'ofw'],
                        ] as $key => $meta): ?>
                            <li>
                                <span class="mix__dot mix__dot--<?= e($meta[1]) ?>" aria-hidden="true"></span>
                                <span class="mix__name"><?= e($meta[0]) ?></span>
                                <span class="mix__pct"><?= e(number_format(($mix[$key] / $mixTotal) * 100, 1)) ?>%</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- ===================== WHEN PEOPLE COME ===================== -->
<div class="chart-row">
    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-solid fa-clock"></i> Peak Visiting Hours</h2>
            <?php if ($peakHour !== null): ?>
                <span class="pill pill--qr">Busiest at <?= e($peakHour['label']) ?></span>
            <?php endif; ?>
        </header>
        <div class="panel__body">
            <?php if ($peakHour === null): ?>
                <div class="empty empty--sm"><p>No arrivals in <?= e($sideName) ?>.</p></div>
            <?php else: ?>
                <div class="chart-box"><canvas id="hourChart"></canvas></div>
                <p class="text-muted small mt-2 mb-0">
                    Taken from the time on each arrival record, over <?= e($sideName) ?>.
                </p>
            <?php endif; ?>
        </div>
    </section>

    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-solid fa-calendar-week"></i> Day of Week</h2>
            <?php if ($peakDay !== null): ?>
                <span class="pill pill--qr">Busiest on <?= e($peakDay['day']) ?></span>
            <?php endif; ?>
        </header>
        <div class="panel__body">
            <?php if ($peakDay === null): ?>
                <div class="empty empty--sm"><p>No arrivals in <?= e($sideName) ?>.</p></div>
            <?php else: ?>
                <div class="chart-box"><canvas id="weekChart"></canvas></div>
                <p class="text-muted small mt-2 mb-0">
                    Average visitors per day, not the total &mdash; a six-week window holds more
                    Mondays than Fridays.
                </p>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- ===================== RECENT ARRIVALS ===================== -->
<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-clock-rotate-left"></i> Recent Arrivals</h2>
        <?php /* To the reports, not to a register of individuals: a line here
                 came from a logbook page a manager submitted, and the report is
                 where that page and every line on it can be read. */ ?>
        <a href="<?= e(base_url('/admin/arrival-reports/index.php')) ?>" class="panel__link">View reports</a>
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
                    <thead>
                        <tr>
                            <th>Time</th><th>Destination</th><th>Visitor Type</th>
                            <th class="text-end">Visitors</th><th></th>
                        </tr>
                    </thead>
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

<?php /* Where the rest is. Age groups and origins by city and country used to
         be on an Analytics page; they are on the formal returns instead, which
         is where anyone needing them was going to end up anyway. */ ?>
<p class="dash-more">
    Age groups, origins by city and country and the destination comparison are in
    <a href="<?= e(base_url('/admin/reports/index.php')) ?>">Reports</a>,
    which produces the formal, exportable returns.
</p>

<?php
$statsUrl = base_url('/api/admin/stats.php');

/* Interpolated into the heredoc below as JavaScript literals. Defined here
   rather than patched in afterwards: a heredoc expands {$var} itself, so a
   placeholder left for str_replace would be consumed before it ever ran. */
$hasDataJs = $hasData ? 'true' : 'false';

/* All six Visitor Trends windows, already summed by the server. */
$trendsJs     = json_encode($trends, JSON_UNESCAPED_UNICODE);
$trendViewJs  = json_encode($trendView);
$trendPerJs   = json_encode($trendPeriod);
$trendDefault = json_encode(array_map(
    static fn (array $v): string => $v['default'],
    $trendViews
), JSON_UNESCAPED_UNICODE);

/* The three small charts are handed their figures rather than fetched — they
   describe the fixed thirty-day window the server just computed. */
$smallCharts = json_encode([
    'hours' => [
        'labels' => array_column($hourly, 'label'),
        'values' => array_column($hourly, 'visitors'),
        'peak'   => $peakHour['hour'] ?? null,
    ],
    'week' => [
        'labels' => array_column($weekdays, 'day'),
        'values' => array_map('floatval', array_column($weekdays, 'visitors')),
        'peak'   => $peakDay['day'] ?? null,
    ],
    'mix' => [
        'labels' => ['Local', 'Domestic', 'Foreign', 'Overseas Filipino'],
        'values' => [
            (int) ($mix['local'] ?? 0),
            (int) ($mix['domestic'] ?? 0),
            (int) ($mix['foreign'] ?? 0),
            (int) ($mix['overseas_filipino'] ?? 0),
        ],
    ],
], JSON_UNESCAPED_UNICODE);

$pageScripts = <<<HTML
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    const HAS_DATA  = {$hasDataJs};
    const STATS_URL = '{$statsUrl}';
    const D         = {$smallCharts};
    const TRENDS    = {$trendsJs};
    const DEFAULTS  = {$trendDefault};
    let   view      = {$trendViewJs};
    let   period    = {$trendPerJs};

    /* ---------------------------------------------------------------
       Counter polling. Refreshes the four figures in place so an
       officer with the screen open sees arrivals appear.
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
                if (typeof data[key] !== 'number') return;

                const next = data[key].toLocaleString();
                if (el.textContent !== next) {
                    el.textContent = next;
                    el.classList.remove('is-bumped');
                    void el.offsetWidth;          // restart the animation
                    el.classList.add('is-bumped');
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

    if (!HAS_DATA || typeof Chart === 'undefined') return;

    Chart.defaults.font.family = "'Poppins', -apple-system, 'Segoe UI', sans-serif";
    Chart.defaults.color = '#7B8791';

    const GREEN = '#2E7D32', BLUE = '#0288D1', TEAL = '#00796B', AMBER = '#EF6C00';
    const GRID  = '#EDF1EE';
    const QUIET = '#C7D6CA';        // an unremarkable bar
    const bare  = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } };

    /* ---------------------------------------------------------------
       Visitor Trends. Monthly bars or a daily line, in the same box.

       Chart.js cannot change an existing chart's type, so the switch
       destroys and rebuilds. The canvas and its wrapper keep a fixed
       height either way, so nothing below moves while it happens.
       --------------------------------------------------------------- */
    const trendEl = document.getElementById('trendChart');
    let   chart   = null;

    /* PHP renders the first view with number_format(); this renders every one
       after it. Both must produce the same string, or a figure changes shape
       the first time the officer touches the toggle — "1,234.5" becoming
       "1234.5" reads as a different number. Locale is pinned for the same
       reason: a browser set to German would group with dots. */
    const fmt = (n, dp) => n.toLocaleString('en-US', {
        minimumFractionDigits: dp, maximumFractionDigits: dp
    });

    function figures(d) {
        const set = (key, text) => {
            const el = document.querySelector('[data-trend-figure="' + key + '"]');
            if (el) { el.textContent = text; }
        };

        set('visitors', fmt(d.visitors, 0));
        set('daily_avg', fmt(d.daily_avg, 1));
        set('avg_party', fmt(d.avg_party, 1));

        /* The one figure that is markup rather than a number: an arrow
           carries the direction, which is half of what it says. */
        const cell = document.querySelector('[data-trend-figure="change"]');
        if (!cell) return;

        if (d.change === null) {
            cell.innerHTML = '<span class="summary__flat">no earlier period</span>';
            return;
        }

        const up   = d.change >= 0;
        const span = document.createElement('span');
        span.className = up ? 'is-up' : 'is-down';
        span.innerHTML = '<i class="fa-solid fa-arrow-' + (up ? 'up' : 'down') + '" aria-hidden="true"></i> ';
        span.appendChild(document.createTextNode(fmt(Math.abs(d.change), 1) + '%'));
        cell.replaceChildren(span);
    }

    function draw() {
        if (!trendEl) return;

        const d = (TRENDS[view] || {})[period];
        if (!d) return;

        if (chart) { chart.destroy(); }

        const ctx = trendEl.getContext('2d');
        const monthly = view === 'monthly';

        /* Monthly is a bar because a month is a bucket you compare, and
           daily is a line because a day is a point on a run. */
        let dataset;
        if (monthly) {
            dataset = {
                label: 'Visitors', data: d.values,
                backgroundColor: GREEN, hoverBackgroundColor: '#1B5E20',
                borderRadius: 4, borderSkipped: false,
                maxBarThickness: 46, categoryPercentage: .78, barPercentage: .9
            };
        } else {
            const fill = ctx.createLinearGradient(0, 0, 0, 240);
            fill.addColorStop(0, 'rgba(46,125,50,.22)');
            fill.addColorStop(1, 'rgba(46,125,50,0)');
            dataset = {
                label: 'Visitors', data: d.values,
                borderColor: GREEN, backgroundColor: fill,
                borderWidth: 2, fill: true, tension: .32,
                pointRadius: d.values.length > 14 ? 0 : 3,
                pointHoverRadius: 5, pointHoverBackgroundColor: GREEN
            };
        }

        chart = new Chart(ctx, {
            type: monthly ? 'bar' : 'line',
            data: { labels: d.labels, datasets: [dataset] },
            options: Object.assign({}, bare, {
                animation: { duration: 320 },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (c) => c.parsed.y.toLocaleString() + ' approved visitors'
                        }
                    }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: GRID } },
                    x: {
                        grid: { display: false },
                        ticks: {
                            autoSkip: true,
                            maxRotation: 0,
                            maxTicksLimit: d.labels.length > 24 ? 10 : 12
                        }
                    }
                }
            })
        });

        figures(d);
    }

    /* Scale first, then how far back. Choosing a scale restores that
       scale's own default period rather than carrying "24M" into a
       view whose longest window is thirty days. */
    document.querySelectorAll('[data-trend-view]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const next = btn.dataset.trendView;
            if (next === view) return;

            view   = next;
            period = DEFAULTS[view];

            document.querySelectorAll('[data-trend-view]').forEach((b) => {
                const on = b.dataset.trendView === view;
                b.classList.toggle('is-active', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
            });

            document.querySelectorAll('[data-trend-periods]').forEach((strip) => {
                strip.hidden = strip.dataset.trendPeriods !== view;
                strip.querySelectorAll('[data-trend-period]').forEach((b) => {
                    b.classList.toggle('is-active', b.dataset.trendPeriod === period);
                });
            });

            draw();
        });
    });

    document.querySelectorAll('[data-trend-period]').forEach((btn) => {
        btn.addEventListener('click', () => {
            if (btn.dataset.trendPeriod === period) return;

            period = btn.dataset.trendPeriod;

            btn.parentNode.querySelectorAll('[data-trend-period]').forEach((b) => {
                b.classList.toggle('is-active', b === btn);
            });

            draw();
        });
    });

    draw();

    /* ---------------------------------------------------------------
       Visitor mix. The total lives in the middle of the ring, in HTML,
       so the legend beside it can carry the percentages instead.
       --------------------------------------------------------------- */
    const m = document.getElementById('mixChart');
    if (m) {
        new Chart(m, {
            type: 'doughnut',
            data: { labels: D.mix.labels, datasets: [{
                data: D.mix.values,
                backgroundColor: [GREEN, BLUE, AMBER, TEAL],
                borderWidth: 0
            }]},
            options: Object.assign({}, bare, { cutout: '68%' })
        });
    }

    /* ---------------------------------------------------------------
       Peak hours and busiest day. The peak bar is the only coloured
       one — the chart exists to answer "when", and colouring all
       twenty-four makes the reader find the answer themselves.
       --------------------------------------------------------------- */
    const h = document.getElementById('hourChart');
    if (h) {
        new Chart(h, {
            type: 'bar',
            data: { labels: D.hours.labels, datasets: [{
                data: D.hours.values,
                backgroundColor: D.hours.values.map((v, i) => (i === D.hours.peak ? GREEN : QUIET)),
                borderRadius: 3, borderSkipped: false
            }]},
            options: Object.assign({}, bare, {
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: GRID } },
                    x: { grid: { display: false }, ticks: { font: { size: 9 }, maxTicksLimit: 12 } }
                }
            })
        });
    }

    const w = document.getElementById('weekChart');
    if (w) {
        new Chart(w, {
            type: 'bar',
            data: { labels: D.week.labels, datasets: [{
                data: D.week.values,
                backgroundColor: D.week.labels.map((l) => (l === D.week.peak ? GREEN : QUIET)),
                borderRadius: 3, borderSkipped: false
            }]},
            options: Object.assign({}, bare, {
                scales: {
                    y: { beginAtZero: true, grid: { color: GRID } },
                    x: { grid: { display: false } }
                }
            })
        });
    }
})();
</script>
HTML;

require __DIR__ . '/_partials/foot.php';
