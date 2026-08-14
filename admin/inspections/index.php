<?php
declare(strict_types=1);

/**
 * TourSync — the Compliance Review queue.
 *
 * Every inspection report a destination manager has handed over, oldest
 * submission first. That order is deliberate: newest-first quietly starves the
 * report that has been waiting three weeks, and the establishment waiting on it
 * cannot open to visitors until somebody looks.
 *
 * Drafts never appear. A draft is a manager still photographing.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Repositories\InspectionRepository as Inspections;

Auth::require();

$pageTitle    = 'Compliance Review';
$pageIcon     = 'fa-clipboard-check';
$pageSubtitle = 'Digital Compliance Inspection · Tourism Standards Verification';

$status        = (string) ($_GET['status'] ?? '');
$destinationId = (int) ($_GET['destination_id'] ?? 0);

if ($status !== '' && !isset(Inspections::STATUSES[$status])) {
    $status = '';
}

$reports      = Inspections::queue(['status' => $status, 'destination_id' => $destinationId]);
$counts       = Inspections::counts();
$destinations = Database::all('SELECT id, name FROM destinations ORDER BY name ASC');

require __DIR__ . '/../_partials/head.php';
?>

<div class="stat-grid">
    <?php
    $cards = [
        ['icon' => 'fa-paper-plane',  'tone' => 'blue',  'value' => $counts['submitted'], 'label' => 'Waiting for review', 'status' => 'submitted'],
        ['icon' => 'fa-eye',          'tone' => 'amber', 'value' => $counts['reviewing'], 'label' => 'Under review',       'status' => 'reviewing'],
        ['icon' => 'fa-circle-check', 'tone' => 'green', 'value' => $counts['approved'],  'label' => 'Compliant',          'status' => 'approved'],
        ['icon' => 'fa-rotate-left',  'tone' => 'teal',  'value' => $counts['rejected'],  'label' => 'Sent back',          'status' => 'rejected'],
    ];

    foreach ($cards as $card): ?>
        <a class="stat-card stat-card--<?= e($card['tone']) ?>" href="index.php?status=<?= e($card['status']) ?>">
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
        <h2><i class="fa-solid fa-list-check"></i> Submitted reports</h2>

        <div class="d-flex gap-2 flex-wrap align-items-center">
            <a href="requirements.php" class="btn btn-sm btn-outline-secondary">
                <i class="fa-solid fa-list-ul"></i> Standards
            </a>

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
                    <?php foreach (Inspections::STATUSES as $key => $label): ?>
                        <?php if ($key === 'draft') { continue; } ?>
                        <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <?php if ($status !== '' || $destinationId > 0): ?>
                    <a href="index.php" class="btn btn-sm btn-outline-secondary">Clear</a>
                <?php endif; ?>
            </form>
        </div>
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
                        When a destination manager photographs their compliance evidence and submits it,
                        the report appears here &mdash; and most of them can be settled without anyone
                        making the trip.
                    <?php endif; ?>
                </p>
            </div>

        <?php else: ?>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Destination</th>
                            <th class="text-end">Standards</th>
                            <th class="text-end">Photos</th>
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

                            /* How long the establishment has been waiting. Shown
                               because a queue that hides its age lets the oldest
                               report sit politely at the bottom of the screen. */
                            $waiting = $r['submitted_at'] !== null
                                && in_array($r['status'], ['submitted', 'reviewing'], true)
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

                                <td class="text-end num">
                                    <span class="cell-strong"><?= n((int) $r['approved_count']) ?> / <?= n((int) $r['item_count']) ?></span>
                                    <?php if ((int) $r['waiting_count'] > 0): ?>
                                        <span class="cell-sub"><?= n((int) $r['waiting_count']) ?> to decide</span>
                                    <?php endif; ?>
                                </td>

                                <td class="text-end num"><?= n((int) $r['photo_count']) ?></td>

                                <td>
                                    <?php if ($r['submitted_at']): ?>
                                        <span class="cell-strong"><?= e(format_date((string) $r['submitted_at'], 'M j')) ?></span>
                                        <?php if ($waiting !== null && $waiting >= 3): ?>
                                            <span class="cell-sub text-danger">waiting <?= n($waiting) ?> day(s)</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="pill pill--<?= $tone ?>"><?= e(Inspections::STATUSES[$r['status']]) ?></span>
                                    <?php if ((int) $r['site_visit_required'] === 1): ?>
                                        <span class="cell-sub text-danger">site visit</span>
                                    <?php endif; ?>
                                </td>

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

<?php require __DIR__ . '/../_partials/foot.php'; ?>
