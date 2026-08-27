<?php
declare(strict_types=1);

/**
 * TourSync — the Municipal Tourism Office's review queue.            Feature 2
 *
 * Everything a destination manager has handed over, oldest submission first.
 * That order is deliberate: a queue sorted newest-first quietly starves the
 * report that has been waiting three weeks, and the manager waiting on it is
 * the one who stopped travelling to town on the promise that this is faster.
 *
 * Drafts never appear here. A draft has not been handed over, and an officer
 * reading figures a manager is still typing would be reviewing a guess.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Paginator;
use App\Core\Auth;
use App\Core\Database;
use App\Repositories\ArrivalReportRepository as Reports;
use App\Repositories\ReportDocumentRepository as Documents;

Auth::require();

$pageTitle    = 'Reports to Review';
$pageIcon     = 'fa-inbox';
$pageSubtitle = 'Centralized Tourist Arrival Logbook Submission and Monitoring';

$status        = (string) ($_GET['status'] ?? '');
$destinationId = (int) ($_GET['destination_id'] ?? 0);

/* Only a status this system actually has. An unknown value falls back to "all"
   rather than returning an empty queue that looks like "nothing to review". */
if ($status !== '' && !isset(Reports::STATUSES[$status])) {
    $status = '';
}

$pager = Paginator::slice(
    Reports::queue([
        'status'         => $status,
        'destination_id' => $destinationId,
    ], 500),
    $_GET['page'] ?? null
);
$reports = $pager['rows'];

$counts       = Reports::counts();
$destinations = Database::all('SELECT id, name FROM destinations ORDER BY name ASC');

require __DIR__ . '/../_partials/head.php';

/** Builds a filter link that keeps whatever else is already filtered. */
$filterUrl = static function (array $overrides) use ($status, $destinationId): string {
    $query = array_filter([
        'status'         => $overrides['status']         ?? $status,
        'destination_id' => $overrides['destination_id'] ?? ($destinationId ?: ''),
    ], static fn ($v) => $v !== '' && $v !== 0);

    return 'index.php' . ($query === [] ? '' : '?' . http_build_query($query));
};
?>

<div class="stat-grid">
    <?php
    $cards = [
        ['icon' => 'fa-paper-plane',  'tone' => 'blue',  'value' => $counts['submitted'], 'label' => 'Waiting for review', 'status' => 'submitted'],
        ['icon' => 'fa-eye',          'tone' => 'amber', 'value' => $counts['reviewing'], 'label' => 'Under review',       'status' => 'reviewing'],
        ['icon' => 'fa-circle-check', 'tone' => 'green', 'value' => $counts['approved'],  'label' => 'Approved',           'status' => 'approved'],
        ['icon' => 'fa-rotate-left',  'tone' => 'teal',  'value' => $counts['rejected'],  'label' => 'Sent back',          'status' => 'rejected'],
    ];

    foreach ($cards as $card): ?>
        <a class="stat-card stat-card--<?= e($card['tone']) ?>" href="<?= e($filterUrl(['status' => $card['status']])) ?>">
            <div class="stat-card__icon"><i class="fa-solid <?= e($card['icon']) ?>"></i></div>
            <div class="stat-card__body">
                <p class="stat-card__value"><?= n((int) $card['value']) ?></p>
                <p class="stat-card__label"><?= e($card['label']) ?></p>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-list-check"></i> Review queue</h2>

        <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
            <select name="destination_id" class="form-select form-select-sm" style="width:auto"
                    onchange="this.form.submit()">
                <option value="">All destinations</option>
                <?php foreach ($destinations as $d): ?>
                    <option value="<?= (int) $d['id'] ?>" <?= $destinationId === (int) $d['id'] ? 'selected' : '' ?>>
                        <?= e((string) $d['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="status" class="form-select form-select-sm" style="width:auto"
                    onchange="this.form.submit()">
                <option value="">All statuses</option>
                <?php foreach (Reports::STATUSES as $key => $label): ?>
                    <?php if ($key === 'draft') { continue; /* never the office's business */ } ?>
                    <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>

            <?php if ($status !== '' || $destinationId > 0): ?>
                <a href="index.php" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </header>

    <div class="panel__body">
        <?php if ($reports === []): ?>

            <div class="empty-public">
                <i class="fa-regular fa-folder-open"></i>
                <h3><?= $status !== '' || $destinationId > 0 ? 'Nothing matches that filter' : 'Nothing waiting' ?></h3>
                <p>
                    <?php if ($status !== '' || $destinationId > 0): ?>
                        Try clearing the filter to see the whole queue.
                    <?php else: ?>
                        When a destination manager submits their arrival figures, the report appears here
                        for review &mdash; no delivery, no waiting for the end of the month.
                    <?php endif; ?>
                </p>
            </div>

        <?php else: ?>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Destination</th>
                            <th>Period</th>
                            <th class="text-end">Records</th>
                            <th>Method</th>
                            <th>Submitted</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($reports as $r): ?>
                            <?php
                            $tone = match ($r['status']) {
                                'approved'  => 'ok',
                                'rejected'  => 'flag',
                                'reviewing' => 'qr',
                                default     => 'void',
                            };

                            /* How long a manager has been waiting. Shown because
                               a queue that hides its age lets the oldest report
                               sit politely at the bottom of the screen. */
                            $waitingDays = $r['submitted_at'] !== null && in_array($r['status'], ['submitted', 'reviewing'], true)
                                ? (int) floor((time() - strtotime((string) $r['submitted_at'])) / 86400)
                                : null;
                            ?>
                            <tr>
                                <td>
                                    <span class="cell-strong"><?= e((string) $r['destination_name']) ?></span>
                                    <?php if ($r['submitted_by_name']): ?>
                                        <span class="cell-sub"><?= e((string) $r['submitted_by_name']) ?></span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= e(format_date($r['period_start'], 'M j')) ?>
                                    &ndash; <?= e(format_date($r['period_end'], 'M j, Y')) ?>
                                </td>

                                <td class="text-end num">
                                    <span class="cell-strong"><?= n((int) $r['entry_count']) ?></span>
                                    <?php if ((int) $r['visitors'] > 0): ?>
                                        <span class="cell-sub"><?= n((int) $r['visitors']) ?> visitor(s)</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="cell-strong">
                                        <?= e(Documents::methodLabel((int) $r['entry_count'], (int) $r['document_count'])) ?>
                                    </span>
                                    <?php if ((int) $r['document_count'] > 0): ?>
                                        <span class="cell-sub">
                                            <i class="fa-solid fa-paperclip"></i>
                                            <?= n((int) $r['document_count']) ?> file(s)
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($r['submitted_at']): ?>
                                        <span class="cell-strong"><?= e(format_date((string) $r['submitted_at'], 'M j')) ?></span>
                                        <?php if ($waitingDays !== null && $waitingDays >= 3): ?>
                                            <span class="cell-sub text-danger">waiting <?= n($waitingDays) ?> day(s)</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>

                                <td><span class="pill pill--<?= $tone ?>"><?= e(Reports::STATUSES[$r['status']]) ?></span></td>

                                <td class="text-end">
                                    <a href="review.php?id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                        <?= in_array($r['status'], ['submitted', 'reviewing'], true) ? 'Review' : 'View' ?>
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

<?php require __DIR__ . '/../../app/views/partials/pager.php'; ?>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
