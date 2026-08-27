<?php
declare(strict_types=1);

/**
 * TourSync — a manager's arrival reports.                          Feature 2
 *
 * The list that replaces a filing cabinet and a trip to town. Scoped entirely
 * by ManagerAuth::destinationId(); there is no destination in the query string
 * for anyone to edit.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Paginator;
use App\Core\ManagerAuth;
use App\Repositories\ArrivalReportRepository as Reports;

$pageTitle    = 'Tourist Arrival Reports';
$pageIcon     = 'fa-file-lines';

require __DIR__ . '/_partials/head.php';

$destinationId = (int) ManagerAuth::destinationId();
$pager         = Paginator::slice(Reports::forDestination($destinationId), $_GET['page'] ?? null);
$reports       = $pager['rows'];
$counts        = Reports::counts($destinationId);
?>

<div class="stat-grid">
    <?php
    $cards = [
        ['icon' => 'fa-pen',            'tone' => 'amber', 'value' => $counts['draft'],     'label' => 'Draft'],
        ['icon' => 'fa-paper-plane',    'tone' => 'blue',  'value' => $counts['submitted'] + $counts['reviewing'], 'label' => 'Awaiting the Office'],
        ['icon' => 'fa-circle-check',   'tone' => 'green', 'value' => $counts['approved'],  'label' => 'Approved'],
        ['icon' => 'fa-rotate-left',    'tone' => 'teal',  'value' => $counts['rejected'],  'label' => 'Sent back'],
    ];
    foreach ($cards as $card): ?>
        <article class="stat-card stat-card--<?= e($card['tone']) ?>">
            <div class="stat-card__icon"><i class="fa-solid <?= e($card['icon']) ?>"></i></div>
            <div class="stat-card__body">
                <p class="stat-card__value"><?= n((int) $card['value']) ?></p>
                <p class="stat-card__label"><?= e($card['label']) ?></p>
            </div>
        </article>
    <?php endforeach; ?>
</div>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-list"></i> Submissions</h2>
        <a href="report-form.php" class="btn btn-brand btn-sm">
            <i class="fa-solid fa-plus"></i> Add Tourist Report
        </a>
    </header>

    <div class="panel__body">
        <?php if ($reports === []): ?>

            <div class="empty-public">
                <i class="fa-regular fa-file-lines"></i>
                <h3>No reports yet</h3>
                <p>
                    Copy the figures from the paper logbook at your destination into a report here,
                    and the Municipal Tourism Office receives them straight away. You no longer need
                    to bring them in person.
                </p>
                <p class="mt-3">
                    <a href="report-form.php" class="btn btn-brand btn-sm">
                        <i class="fa-solid fa-plus"></i> Add your first report
                    </a>
                </p>
            </div>

        <?php else: ?>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Period</th>
                            <th class="text-end">Days</th>
                            <th class="text-end">Visitors</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $r): ?>
                            <tr>
                                <td>
                                    <span class="cell-strong"><?= e(format_date($r['period_start'], 'M j')) ?>
                                        &ndash; <?= e(format_date($r['period_end'], 'M j, Y')) ?></span>
                                    <?php if ($r['notes']): ?>
                                        <span class="cell-sub"><?= e(mb_substr((string) $r['notes'], 0, 60)) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end num"><?= n((int) $r['day_count']) ?></td>
                                <td class="text-end num"><?= n((int) $r['visitors']) ?></td>
                                <td>
                                    <?php
                                    $tone = match ($r['status']) {
                                        'approved'  => 'ok',
                                        'rejected'  => 'flag',
                                        'draft'     => 'void',
                                        default     => 'qr',
                                    };
                                    ?>
                                    <span class="pill pill--<?= $tone ?>"><?= e(Reports::STATUSES[$r['status']]) ?></span>

                                    <?php if ($r['status'] === 'rejected' && $r['rejection_reason']): ?>
                                        <!-- Shown in the list, not hidden behind a click. A manager
                                             scanning this page needs to know what to fix without
                                             opening each row. -->
                                        <span class="cell-sub text-danger">
                                            <?= e(mb_substr((string) $r['rejection_reason'], 0, 90)) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="report-form.php?id=<?= (int) $r['id'] ?>"
                                       class="btn btn-sm btn-outline-secondary">
                                        <?= in_array($r['status'], ['draft', 'rejected'], true) ? 'Edit' : 'View' ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../app/views/partials/pager.php'; ?>

<?php require __DIR__ . '/_partials/foot.php'; ?>
