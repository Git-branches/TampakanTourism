<?php
/**
 * The report itself, shared by the on-screen view and the print view.
 *
 * One template so the printed page and the screen cannot disagree about what
 * the month's total was. Expects $report from ReportBuilder::build().
 *
 * -----------------------------------------------------------------------------
 * PRINT PAGINATION — EVERY SECTION IS CLASSIFIED, AND THE TEST IS "CAN IT GROW?"
 * -----------------------------------------------------------------------------
 * Each section carries report-print-section--keep or --flow. The rules live in
 * assets/css/report-print.css; the decision lives here, with the markup, because
 * it depends on what the section CONTAINS:
 *
 *   --keep   a bounded list. It can never outgrow a page, so it travels whole
 *            rather than leaving two of its four rows at the foot of one sheet.
 *   --flow   a list that grows with the data. It continues across pages, with
 *            its column headings repeated and its rows kept whole.
 *
 * The audit, and it is short because only one section can grow:
 *
 *   KEEP  destination header        three lines, fixed
 *   KEEP  empty-state notice        fixed
 *   KEEP  Visitors by Type          four classifications, fixed
 *   KEEP  Day Visitors vs Overnight three rows and a note, fixed
 *   FLOW  Arrivals by Destination   ONE ROW PER DESTINATION — unbounded
 *   KEEP  Age Groups                seven brackets plus not-stated, fixed
 *   KEEP  Sex                       four rows and a note, fixed
 *   KEEP  Top Cities/Provinces/...  capped at ten by ReportBuilder::origins()
 *   KEEP  Busiest Days              capped at five by the query
 *   KEEP  By Day of Week            seven rows, fixed
 *   KEEP  Record Integrity          five tiles and a note, fixed
 *
 * ADDING A SECTION: classify it. If its row count comes from the database and
 * has no LIMIT, it is --flow. If in doubt it is --flow — an unnecessary page
 * break is untidy, a section the browser has to break anyway is not.
 */

use App\Core\ReportBuilder;
use App\Repositories\ArrivalRepository;

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

$t = $report['totals'];
$c = $report['comparison'];
$i = $report['integrity'];

/* Set by the on-screen view only. The print view and the CSV have no use for a
   link, and printing one would put a dead "Generate Report" button on paper. */
$reportLinks = $reportLinks ?? false;

/* Scoped or not. Every section below reads the same figures either way — the
   narrowing happened in SQL — so the only thing that changes here is that the
   report says which destination it is about. */
$dest = $report['destination'] ?? null;
?>

<?php if ($dest !== null): ?>
    <?php /* WHAT THIS REPORT COVERS, stated before any figure.
             The office generates these to compare against a site's own
             submission, and the comparison is worthless if the sheet in their
             hand does not say plainly which site, which dates, and when it was
             produced. On the printed copy this is the paragraph that makes the
             page self-describing once it has left the screen. */ ?>
    <section class="panel panel--notice report-print-section report-print-section--keep">
        <div class="panel__body">
            <h2 class="h6 mb-2">
                <i class="fa-solid fa-location-dot"></i>
                Destination report &mdash; <?= e((string) $dest['name']) ?>
                <?php if (($dest['status'] ?? '') !== 'active'): ?>
                    <span class="tag tag--muted">archived</span>
                <?php endif; ?>
            </h2>
            <p class="mb-0 small">
                <?php if (!empty($dest['barangay'])): ?>
                    Barangay <?= e((string) $dest['barangay']) ?>.
                <?php endif; ?>
                Covering <strong><?= e(format_date($report['period']['start'])) ?></strong>
                to <strong><?= e(format_date($report['period']['end'])) ?></strong>
                (<?= e($report['period']['label']) ?>).
                Every figure below counts only arrivals recorded against this destination.
            </p>
        </div>
    </section>
<?php endif; ?>

<!-- ===================== HEADLINE FIGURES ===================== -->
<div class="report-figures report-print-summary">
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
    <div class="panel report-print-section report-print-section--keep"><div class="panel__body">
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
    <section class="panel report-print-section report-print-section--keep">
        <header class="panel__head"><h2><i class="fa-solid fa-user-group"></i> Visitors by Type</h2></header>
        <div class="panel__body">
            <table class="table table-sm mb-0 report-print-table">
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

    <section class="panel report-print-section report-print-section--keep">
        <header class="panel__head"><h2><i class="fa-solid fa-moon"></i> Day Visitors vs Overnight</h2></header>
        <div class="panel__body">
            <table class="table table-sm mb-0 report-print-table">
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
<section class="panel report-print-section report-print-section--flow">
    <header class="panel__head"><h2><i class="fa-solid fa-mountain-sun"></i> Arrivals by Destination</h2></header>
    <div class="panel__body">
        <table class="table table-sm mb-0 report-print-table">
            <thead><tr>
                <th>Destination</th><th>Barangay</th>
                <th class="text-end">Entries</th><th class="text-end">Visitors</th><th class="text-end">Share</th>
                <?php if ($reportLinks && $dest === null): ?><th></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($report['destinations'] as $d):
                $pct = $t['visitors'] > 0 ? round($d['visitors'] / $t['visitors'] * 100, 1) : 0; ?>
                <tr class="<?= (int) $d['visitors'] === 0 ? 'text-muted' : '' ?>">
                    <td><?= e($d['name']) ?></td>
                    <td class="small"><?= e((string) ($d['barangay'] ?: '—')) ?></td>
                    <td class="text-end num"><?= n($d['records']) ?></td>
                    <td class="text-end num"><strong><?= n($d['visitors']) ?></strong></td>
                    <td class="text-end num"><?= $pct ?>%</td>
                    <?php if ($reportLinks && $dest === null): ?>
                        <?php /* THE PER-DESTINATION ACTION LIVES HERE, on the row it
                                 belongs to, rather than as a second form above. The
                                 officer is already looking at the line whose figure
                                 they want to check against the site's own sheet.
                                 It carries the period currently on screen, so the
                                 destination report opens on the same dates rather
                                 than resetting to this month. */ ?>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary"
                               href="?<?= e(http_build_query(array_merge(
                                   array_diff_key($_GET, ['save' => 1]),
                                   ['destination' => (int) $d['id']]
                               ))) ?>">
                                <i class="fa-solid fa-file-lines"></i> Generate Report
                            </a>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="2">Total</th>
                    <th class="text-end num"><?= n(array_sum(array_column($report['destinations'], 'records'))) ?></th>
                    <th class="text-end num"><?= n(array_sum(array_column($report['destinations'], 'visitors'))) ?></th>
                    <th></th>
                    <?php if ($reportLinks && $dest === null): ?><th></th><?php endif; ?>
                </tr>
            </tfoot>
        </table>
    </div>
</section>

<!-- ===================== DEMOGRAPHICS ===================== -->
<div class="report-grid">
    <section class="panel report-print-section report-print-section--keep">
        <header class="panel__head"><h2><i class="fa-solid fa-cake-candles"></i> Age Groups</h2></header>
        <div class="panel__body">
            <table class="table table-sm mb-0 report-print-table">
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

    <section class="panel report-print-section report-print-section--keep">
        <header class="panel__head"><h2><i class="fa-solid fa-venus-mars"></i> Sex</h2></header>
        <div class="panel__body">
            <table class="table table-sm mb-0 report-print-table">
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
        <section class="panel report-print-section report-print-section--keep">
            <header class="panel__head"><h2><i class="fa-solid <?= e($meta[1]) ?>"></i> <?= e($meta[0]) ?></h2></header>
            <div class="panel__body">
                <table class="table table-sm mb-0 report-print-table">
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
    <section class="panel report-print-section report-print-section--keep">
        <header class="panel__head"><h2><i class="fa-solid fa-arrow-trend-up"></i> Busiest Days</h2></header>
        <div class="panel__body">
            <table class="table table-sm mb-0 report-print-table">
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

    <section class="panel report-print-section report-print-section--keep">
        <header class="panel__head"><h2><i class="fa-solid fa-calendar-week"></i> By Day of Week</h2></header>
        <div class="panel__body">
            <table class="table table-sm mb-0 report-print-table">
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
<section class="panel panel--integrity report-print-section report-print-section--keep">
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
