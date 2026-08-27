<?php
declare(strict_types=1);

/**
 * TourSync — the manager's inspection history.
 *
 * Every compliance submission this destination has made, newest first, with the
 * office's answer on each. Scoped entirely by ManagerAuth::destinationId() —
 * there is no destination in the query string for anyone to edit.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Paginator;
use App\Core\ManagerAuth;
use App\Repositories\InspectionRepository as Inspections;

$pageTitle = 'Inspection History';
$pageIcon  = 'fa-clock-rotate-left';

require __DIR__ . '/_partials/head.php';

$destinationId = (int) ManagerAuth::destinationId();
$pager         = Paginator::slice(Inspections::forDestination($destinationId), $_GET['page'] ?? null);
$reports       = $pager['rows'];
$counts        = Inspections::counts($destinationId);
$standing      = Inspections::currentStanding($destinationId);
?>

<?php if ($standing !== null): ?>
    <div class="alert alert-success">
        <i class="fa-solid fa-certificate"></i>
        <strong><?= e(ManagerAuth::destinationName()) ?> is currently compliant.</strong>
        Approved <?= e(format_date((string) $standing['reviewed_at'], 'F j, Y')) ?>
        <?php if ($standing['valid_until']): ?>
            &middot; valid until <strong><?= e(format_date((string) $standing['valid_until'], 'F j, Y')) ?></strong>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="stat-grid">
    <?php
    $cards = [
        ['icon' => 'fa-pen',           'tone' => 'amber', 'value' => $counts['draft'], 'label' => 'Draft'],
        ['icon' => 'fa-paper-plane',   'tone' => 'blue',  'value' => $counts['submitted'] + $counts['reviewing'], 'label' => 'With the Office'],
        ['icon' => 'fa-circle-check',  'tone' => 'green', 'value' => $counts['approved'], 'label' => 'Approved'],
        ['icon' => 'fa-rotate-left',   'tone' => 'teal',  'value' => $counts['rejected'], 'label' => 'Sent back'],
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
        <h2><i class="fa-solid fa-clipboard-list"></i> Submissions</h2>
        <a href="inspection.php" class="btn btn-brand btn-sm">
            <i class="fa-solid fa-camera"></i> Open current report
        </a>
    </header>

    <div class="panel__body">
        <?php if ($reports === []): ?>

            <div class="empty-public">
                <i class="fa-regular fa-clipboard"></i>
                <h3>No inspection reports yet</h3>
                <p>
                    Photograph the fire extinguisher, the first aid kit, the signage and the visitor area,
                    and send them to the Municipal Tourism Office from here. Most compliance checks can be
                    settled from the photos &mdash; an officer travels out only when a picture cannot
                    answer the question.
                </p>
                <p class="mt-3">
                    <a href="inspection.php" class="btn btn-brand btn-sm">
                        <i class="fa-solid fa-camera"></i> Start the first report
                    </a>
                </p>
            </div>

        <?php else: ?>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Submitted</th>
                            <th class="text-end">Standards</th>
                            <th class="text-end">Photos</th>
                            <th>Status</th>
                            <th>Office remarks</th>
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
                                'draft'     => 'void',
                                default     => 'qr',
                            };
                            ?>
                            <tr>
                                <td>
                                    <?php if ($r['submitted_at']): ?>
                                        <span class="cell-strong"><?= e(format_date((string) $r['submitted_at'], 'M j, Y')) ?></span>
                                        <span class="cell-sub"><?= e(format_date((string) $r['submitted_at'], 'g:i A')) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">not submitted</span>
                                        <span class="cell-sub">started <?= e(format_date((string) $r['created_at'], 'M j')) ?></span>
                                    <?php endif; ?>
                                </td>

                                <td class="text-end num">
                                    <span class="cell-strong"><?= n((int) $r['approved_count']) ?> / <?= n((int) $r['item_count']) ?></span>
                                    <span class="cell-sub">approved</span>
                                </td>

                                <td class="text-end num"><?= n((int) $r['photo_count']) ?></td>

                                <td>
                                    <span class="pill pill--<?= $tone ?>"><?= e(Inspections::STATUSES[$r['status']]) ?></span>
                                    <?php if ((int) $r['site_visit_required'] === 1): ?>
                                        <span class="cell-sub text-danger">site visit requested</span>
                                    <?php endif; ?>
                                </td>

                                <td class="small">
                                    <?= $r['office_remarks']
                                        ? e(mb_substr((string) $r['office_remarks'], 0, 90))
                                        : '<span class="text-muted">&mdash;</span>' ?>
                                </td>

                                <td class="text-end">
                                    <?php if (in_array($r['status'], ['draft', 'rejected'], true)): ?>
                                        <a href="inspection.php" class="btn btn-sm btn-outline-secondary">Continue</a>
                                    <?php else: ?>
                                        <a href="inspection-view.php?id=<?= (int) $r['id'] ?>"
                                           class="btn btn-sm btn-outline-secondary">View</a>
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

<?php require __DIR__ . '/../app/views/partials/pager.php'; ?>

<?php require __DIR__ . '/_partials/foot.php'; ?>
