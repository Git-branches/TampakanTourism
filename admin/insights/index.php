<?php
declare(strict_types=1);

/**
 * TourSync — assisted decision support.       Feature 4 / Problem 5
 *
 * Every figure on this screen prints the method that produced it and the
 * limitation that applies. That is not modesty — it is what lets an officer
 * defend the number to a Mayor, and what stops the system being trusted
 * further than it deserves.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Insights;

Auth::require();

$pageTitle    = 'Decision Support';
$pageIcon     = 'fa-lightbulb';
$pageSubtitle = 'Explainable prompts for human judgement';

$forecast        = Insights::forecast();
$trend           = Insights::trend(6);
$highTraffic     = Insights::highTraffic(90);
$recommendations = Insights::recommendations();
$monthsOfData    = Insights::monthsOfData();

require __DIR__ . '/../_partials/head.php';
?>

<div class="panel panel--notice">
    <div class="panel__body">
        <h2><i class="fa-solid fa-circle-info"></i> How this works</h2>
        <p>
            <strong>There is no artificial intelligence in this module.</strong> Every figure below is
            arithmetic a spreadsheet could reproduce — sums, averages, a percentile, and a
            least-squares trend line.
        </p>
        <p class="mb-0">
            That is deliberate. These numbers are used to justify staffing and budget decisions,
            and a figure nobody can explain is a figure nobody should act on. Each item states the
            rule that produced it, so you can disagree with the rule rather than with a black box.
        </p>
    </div>
</div>

<!-- ===================== FORECAST ===================== -->
<section class="panel">
    <header class="panel__head"><h2><i class="fa-solid fa-chart-line"></i> Next Month Forecast</h2></header>
    <div class="panel__body">

        <?php if (!$forecast['available']): ?>
            <div class="forecast-blocked">
                <i class="fa-solid fa-hourglass-half"></i>
                <div>
                    <h3>Forecasting is not available yet</h3>
                    <p><?= e($forecast['reason']) ?></p>
                    <p class="report-note mb-0">
                        A forecast drawn from <?= n($forecast['months_of_data']) ?> month(s) of records
                        would be decoration, not information. Showing one — and having the Office plan
                        staffing around it — would do real harm, so the system refuses rather than
                        guesses.
                    </p>
                </div>
            </div>

            <div class="progress-track mt-3">
                <div class="progress-track__bar" style="width: <?= min(100, round($forecast['months_of_data'] / Insights::MIN_MONTHS_FOR_FORECAST * 100)) ?>%"></div>
            </div>
            <p class="report-note">
                <?= n($forecast['months_of_data']) ?> of <?= Insights::MIN_MONTHS_FOR_FORECAST ?> months of history collected.
            </p>

        <?php else: ?>
            <div class="forecast">
                <div class="forecast__figure">
                    <p class="forecast__value"><?= n($forecast['estimate']) ?></p>
                    <p class="forecast__label">expected visitors in <?= e($forecast['target_month']) ?></p>
                    <p class="forecast__range">
                        likely between <strong><?= n($forecast['range_low']) ?></strong>
                        and <strong><?= n($forecast['range_high']) ?></strong>
                    </p>
                </div>
                <dl class="forecast__inputs">
                    <div><dt>Same month last year</dt><dd><?= $forecast['seasonal'] !== null ? n($forecast['seasonal']) : '—' ?></dd></div>
                    <div><dt>Trend line projection</dt><dd><?= n($forecast['trend']) ?></dd></div>
                    <div><dt>Months of history</dt><dd><?= n($forecast['months_of_data']) ?></dd></div>
                    <div><dt>Confidence</dt><dd><?= e(ucfirst($forecast['confidence'])) ?></dd></div>
                </dl>
            </div>
        <?php endif; ?>

        <p class="report-note mt-3">
            <strong>Method:</strong> <?= e($forecast['method']) ?><br>
            <strong>Limitation:</strong> <?= e($forecast['limitation']) ?>
        </p>
    </div>
</section>

<!-- ===================== RECOMMENDATIONS ===================== -->
<section class="panel">
    <header class="panel__head"><h2><i class="fa-solid fa-list-check"></i> Prompts for Attention</h2></header>
    <div class="panel__body">
        <?php if ($recommendations === []): ?>
            <div class="empty empty--sm">
                <p><strong>Nothing needs attention right now.</strong></p>
                <p class="text-muted">
                    Prompts appear when a destination is under sustained pressure, has stopped
                    recording arrivals, has no manager, or when records are awaiting review.
                </p>
            </div>
        <?php else: ?>
            <div class="rec-list">
                <?php foreach ($recommendations as $r): ?>
                    <article class="rec rec--<?= e($r['priority']) ?>">
                        <div class="rec__icon"><i class="fa-solid <?= e($r['icon']) ?>"></i></div>
                        <div class="rec__body">
                            <h3><?= e($r['title']) ?></h3>
                            <p><?= e($r['detail']) ?></p>
                            <p class="rec__rule"><strong>Rule:</strong> <?= e($r['rule']) ?></p>
                        </div>
                        <span class="rec__priority"><?= e(ucfirst($r['priority'])) ?></span>
                    </article>
                <?php endforeach; ?>
            </div>
            <p class="report-note mt-3">
                These are prompts for human judgement, not instructions. The system knows nothing
                about budget, available staff, road conditions, or what else is happening in the
                municipality that week.
            </p>
        <?php endif; ?>
    </div>
</section>

<!-- ===================== HIGH TRAFFIC ===================== -->
<?php if ($highTraffic !== []): ?>
<section class="panel">
    <header class="panel__head"><h2><i class="fa-solid fa-fire"></i> Destination Load — Last 90 Days</h2></header>
    <div class="panel__body">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Destination</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Days recorded</th>
                        <th class="text-end">Daily average</th>
                        <th class="text-end">Busiest day</th>
                        <th class="text-end">Days at peak</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($highTraffic as $d): ?>
                    <tr>
                        <td><?= e($d['name']) ?></td>
                        <td class="text-end num"><?= n($d['total']) ?></td>
                        <td class="text-end num"><?= n($d['active_days']) ?></td>
                        <td class="text-end num"><?= e((string) $d['daily_average']) ?></td>
                        <td class="text-end num"><?= n($d['busiest_day']) ?></td>
                        <td class="text-end num">
                            <?= n($d['days_over']) ?>
                            <?php if ($d['days_over'] >= 5): ?>
                                <i class="fa-solid fa-triangle-exclamation text-warning" title="Sustained pressure"></i>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="report-note mt-3">
            <strong>Method:</strong> "Peak" is each destination's own 90th-percentile daily total,
            not a municipality-wide figure. A site averaging eight visitors and one averaging three
            hundred cannot share a definition of busy.<br>
            <strong>Limitation:</strong> Reflects recorded arrivals only. A destination with no QR
            sign installed shows as quiet regardless of how many people actually visit.
        </p>
    </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
