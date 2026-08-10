<?php
/**
 * The report itself, shared by the on-screen view and the print view.
 *
 * One template so the printed page and the screen cannot disagree about what
 * the month's total was. Expects $report from ReportBuilder::build().
 */

use App\Core\ReportBuilder;
use App\Repositories\ArrivalRepository;

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

$t = $report['totals'];
$c = $report['comparison'];
$i = $report['integrity'];
?>

<!-- ===================== HEADLINE FIGURES ===================== -->
<div class="report-figures">
    <div class="figure figure--lead">
        <p class="figure__value"><?= n($t['visitors']) ?></p>
        <p class="figure__label">Total visitor arrivals</p>
        <?php if ($c['change_pct'] !== null): ?>
            <p class="figure__delta <?= $c['change'] >= 0 ? 'is-up' : 'is-down' ?>">
                <i class="fa-solid fa-arrow-<?= $c['change'] >= 0 ? 'up' : 'down' ?>"></i>
                <?= abs($c['change_pct']) ?>% vs <?= e($c['label']) ?>
                (<?= n($c['previous']) ?>)
            </p>
        <?php elseif ($c['previous'] === 0 && $t['visitors'] > 0): ?>
            <p class="figure__delta">No records in the <?= e($c['label']) ?> to compare against.</p>
        <?php endif; ?>
    </div>

    <div class="figure"><p class="figure__value"><?= n($t['records']) ?></p><p class="figure__label">Logbook entries</p></div>
    <div class="figure"><p class="figure__value"><?= e((string) $t['avg_party']) ?></p><p class="figure__label">Average party size</p></div>
    <div class="figure"><p class="figure__value"><?= e((string) $t['daily_avg']) ?></p><p class="figure__label">Visitors per day</p></div>
    <div class="figure"><p class="figure__value"><?= n($t['destinations']) ?></p><p class="figure__label">Destinations visited</p></div>
</div>

<?php if ($t['visitors'] === 0): ?>
    <div class="panel"><div class="panel__body">
        <div class="empty">
            <i class="fa-solid fa-file-circle-question"></i>
            <p><strong>No arrivals were recorded in this period.</strong></p>
            <p>The report is accurate — there is simply nothing to summarise between
               <?= e(format_date($report['period']['start'])) ?> and <?= e(format_date($report['period']['end'])) ?>.</p>
        </div>
    </div></div>
<?php else: ?>

<!-- ===================== VISITOR CLASSIFICATION ===================== -->
<div class="report-grid">
    <section class="panel">
        <header class="panel__head"><h2><i class="fa-solid fa-user-group"></i> Visitors by Type</h2></header>
        <div class="panel__body">
            <table class="table table-sm mb-0">
                <thead><tr><th>Classification</th><th class="text-end">Visitors</th><th class="text-end">Share</th></tr></thead>
                <tbody>
                <?php foreach ($report['types'] as $key => $count):
                    $pct = $t['visitors'] > 0 ? round($count / $t['visitors'] * 100, 1) : 0; ?>
                    <tr>
                        <td><?= e(ArrivalRepository::TYPES[$key] ?? $key) ?></td>
                        <td class="text-end num"><?= n($count) ?></td>
                        <td class="text-end num"><?= $pct ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><th>Total</th><th class="text-end num"><?= n(array_sum($report['types'])) ?></th><th></th></tr></tfoot>
            </table>
        </div>
    </section>

    <section class="panel">
        <header class="panel__head"><h2><i class="fa-solid fa-moon"></i> Day Visitors vs Overnight</h2></header>
        <div class="panel__body">
            <table class="table table-sm mb-0">
                <thead><tr><th>Stay</th><th class="text-end">Visitors</th><th class="text-end">Share</th></tr></thead>
                <tbody>
                <?php foreach (['day_trip' => 'Day visitors (excursionists)', 'overnight' => 'Overnight tourists', 'not_stated' => 'Not stated'] as $key => $label):
                    $count = $report['stay'][$key];
                    $pct = $t['visitors'] > 0 ? round($count / $t['visitors'] * 100, 1) : 0; ?>
                    <tr>
                        <td><?= e($label) ?></td>
                        <td class="text-end num"><?= n($count) ?></td>
                        <td class="text-end num"><?= $pct ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="report-note">
                National tourism statistics count day visitors and overnight tourists separately.
            </p>
        </div>
    </section>
</div>

<!-- ===================== BY DESTINATION ===================== -->
<section class="panel">
    <header class="panel__head"><h2><i class="fa-solid fa-mountain-sun"></i> Arrivals by Destination</h2></header>
    <div class="panel__body">
        <table class="table table-sm mb-0">
            <thead><tr><th>Destination</th><th>Barangay</th><th class="text-end">Entries</th><th class="text-end">Visitors</th><th class="text-end">Share</th></tr></thead>
            <tbody>
            <?php foreach ($report['destinations'] as $d):
                $pct = $t['visitors'] > 0 ? round($d['visitors'] / $t['visitors'] * 100, 1) : 0; ?>
                <tr class="<?= (int) $d['visitors'] === 0 ? 'text-muted' : '' ?>">
                    <td><?= e($d['name']) ?></td>
                    <td class="small"><?= e((string) ($d['barangay'] ?: '—')) ?></td>
                    <td class="text-end num"><?= n($d['records']) ?></td>
                    <td class="text-end num"><strong><?= n($d['visitors']) ?></strong></td>
                    <td class="text-end num"><?= $pct ?>%</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="2">Total</th>
                    <th class="text-end num"><?= n(array_sum(array_column($report['destinations'], 'records'))) ?></th>
                    <th class="text-end num"><?= n(array_sum(array_column($report['destinations'], 'visitors'))) ?></th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
    </div>
</section>

<!-- ===================== DEMOGRAPHICS ===================== -->
<div class="report-grid">
    <section class="panel">
        <header class="panel__head"><h2><i class="fa-solid fa-cake-candles"></i> Age Groups</h2></header>
        <div class="panel__body">
            <table class="table table-sm mb-0">
                <tbody>
                <?php
                $ageLabels = ArrivalRepository::AGE_BRACKETS + ['not_stated' => 'Not stated'];
                foreach ($report['demographics']['age'] as $key => $count):
                    $pct = $t['visitors'] > 0 ? round($count / $t['visitors'] * 100) : 0; ?>
                    <tr>
                        <td><?= e($ageLabels[$key] ?? $key) ?></td>
                        <td style="width:45%"><span class="bar"><i style="width:<?= $pct ?>%"></i></span></td>
                        <td class="text-end num"><?= n($count) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <header class="panel__head"><h2><i class="fa-solid fa-venus-mars"></i> Sex</h2></header>
        <div class="panel__body">
            <table class="table table-sm mb-0">
                <tbody>
                <?php foreach (['male' => 'Male', 'female' => 'Female', 'prefer_not_to_say' => 'Prefer not to say', 'not_stated' => 'Not stated'] as $key => $label):
                    $count = $report['demographics']['sex'][$key];
                    $pct = $t['visitors'] > 0 ? round($count / $t['visitors'] * 100) : 0; ?>
                    <tr>
                        <td><?= e($label) ?></td>
                        <td style="width:45%"><span class="bar"><i style="width:<?= $pct ?>%"></i></span></td>
                        <td class="text-end num"><?= n($count) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="report-note">
                Age and sex are optional on the logbook. A high "not stated" share is visitors
                exercising that choice, not missing data.
            </p>
        </div>
    </section>
</div>

<!-- ===================== ORIGINS ===================== -->
<?php if ($report['origins']['cities'] !== [] || $report['origins']['countries'] !== []): ?>
<div class="report-grid report-grid--three">
    <?php foreach ([
        'cities'    => ['Top Cities / Municipalities', 'fa-city'],
        'provinces' => ['Top Provinces', 'fa-map'],
        'countries' => ['Top Countries', 'fa-globe'],
    ] as $key => $meta):
        if ($report['origins'][$key] === []) continue; ?>
        <section class="panel">
            <header class="panel__head"><h2><i class="fa-solid <?= e($meta[1]) ?>"></i> <?= e($meta[0]) ?></h2></header>
            <div class="panel__body">
                <table class="table table-sm mb-0">
                    <tbody>
                    <?php foreach ($report['origins'][$key] as $o): ?>
                        <tr>
                            <td><?= e($o['place']) ?></td>
                            <td class="text-end num"><?= n($o['visitors']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ===================== PEAK DAYS ===================== -->
<?php if ($report['peak']['busiest_dates'] !== []): ?>
<div class="report-grid">
    <section class="panel">
        <header class="panel__head"><h2><i class="fa-solid fa-arrow-trend-up"></i> Busiest Days</h2></header>
        <div class="panel__body">
            <table class="table table-sm mb-0">
                <tbody>
                <?php foreach ($report['peak']['busiest_dates'] as $d): ?>
                    <tr>
                        <td><?= e(format_date($d['visit_date'], 'l, M j, Y')) ?></td>
                        <td class="text-end num"><?= n($d['visitors']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <header class="panel__head"><h2><i class="fa-solid fa-calendar-week"></i> By Day of Week</h2></header>
        <div class="panel__body">
            <table class="table table-sm mb-0">
                <tbody>
                <?php
                $maxDay = max(array_map(static fn($w) => (int) $w['visitors'], $report['peak']['weekdays'] ?: [['visitors' => 1]]));
                foreach ($report['peak']['weekdays'] as $w):
                    $pct = $maxDay > 0 ? round((int) $w['visitors'] / $maxDay * 100) : 0; ?>
                    <tr>
                        <td><?= e($w['day']) ?></td>
                        <td style="width:50%"><span class="bar"><i style="width:<?= $pct ?>%"></i></span></td>
                        <td class="text-end num"><?= n($w['visitors']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php endif; ?>

<?php endif; /* end has-visitors */ ?>

<!-- ===================== RECORD INTEGRITY ===================== -->
<section class="panel panel--integrity">
    <header class="panel__head"><h2><i class="fa-solid fa-shield-halved"></i> Record Integrity</h2></header>
    <div class="panel__body">
        <p class="report-note mb-3">
            Printed on every report on purpose. A total presented without saying what was left
            out invites the question at the worst possible moment.
        </p>
        <div class="integrity-grid">
            <div><span><?= n($i['valid_records']) ?></span>Counted entries</div>
            <div><span><?= n($i['qr_visitors']) ?></span>Visitors self-recorded by QR</div>
            <div><span><?= n($i['manual_visitors']) ?></span>Visitors recorded by staff</div>
            <div class="<?= $i['flagged_records'] > 0 ? 'is-warn' : '' ?>">
                <span><?= n($i['flagged_visitors']) ?></span>Excluded — awaiting review
            </div>
            <div class="<?= $i['voided_records'] > 0 ? 'is-warn' : '' ?>">
                <span><?= n($i['voided_visitors']) ?></span>Excluded — voided by an officer
            </div>
        </div>

        <?php if ($i['flagged_records'] > 0): ?>
            <p class="report-note mt-3">
                <i class="fa-solid fa-flag"></i>
                <?= n($i['flagged_records']) ?> record(s) are held out of these totals pending review.
                If approved, the visitor total would rise by <?= n($i['flagged_visitors']) ?>.
            </p>
        <?php endif; ?>
    </div>
</section>
