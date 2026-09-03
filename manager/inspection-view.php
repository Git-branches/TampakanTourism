<?php
declare(strict_types=1);

/**
 * TourSync — a past inspection report, read-only.
 *
 * The manager's record of what they sent and what the office answered. Nothing
 * here can be changed: a submitted report belongs to the review, and one that
 * was approved is the evidence behind a compliance certificate.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\ManagerAuth;
use App\Core\Session;
use App\Repositories\InspectionRepository as Inspections;

ManagerAuth::require();

$id     = (int) ($_GET['id'] ?? 0);
$report = $id > 0 ? Inspections::find($id) : null;

/* Belongs to another destination? Treated as not existing — no "access denied"
   that would confirm the id is real. */
if ($report === null || !ManagerAuth::owns((int) $report['destination_id'])) {
    Session::flash('danger', 'That report could not be found.');
    redirect(base_url('/manager/inspections.php'));
}

$items = Inspections::items($id);

$pageTitle    = 'Inspection Report';
$pageIcon     = 'fa-clipboard-check';
$pageSubtitle = ManagerAuth::destinationName() . ' · '
    . ($report['submitted_at'] ? format_date((string) $report['submitted_at'], 'F j, Y') : 'not submitted');

require __DIR__ . '/_partials/head.php';

$itemTone = static fn (string $s): string => match ($s) {
    'approved'       => 'ok',
    'rejected'       => 'flag',
    'needs_revision' => 'qr',
    'submitted'      => 'qr',
    default          => 'void',
};
?>

<p class="mb-3">
    <a href="inspections.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa-solid fa-arrow-left"></i> Back to history
    </a>
</p>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-circle-info"></i> Outcome</h2>
        <?php
        $tone = match ($report['status']) {
            'approved'  => 'ok',
            'rejected'  => 'flag',
            'reviewing' => 'qr',
            default     => 'void',
        };
        ?>
        <span class="pill pill--<?= $tone ?>"><?= e(Inspections::STATUSES[$report['status']]) ?></span>
    </header>

    <div class="panel__body">
        <dl class="detail-grid">
            <div>
                <dt>Submitted</dt>
                <dd><?= $report['submitted_at'] ? e(format_date((string) $report['submitted_at'], 'F j, Y g:i A')) : '—' ?></dd>
            </div>
            <div>
                <dt>Reviewed by</dt>
                <dd><?= e((string) ($report['reviewed_by_name'] ?: '—')) ?></dd>
            </div>
            <div>
                <dt>Reviewed</dt>
                <dd><?= $report['reviewed_at'] ? e(format_date((string) $report['reviewed_at'], 'F j, Y')) : '—' ?></dd>
            </div>
            <div>
                <dt>Valid until</dt>
                <dd><?= $report['valid_until'] ? e(format_date((string) $report['valid_until'], 'F j, Y')) : '—' ?></dd>
            </div>
        </dl>

        <?php if ($report['office_remarks']): ?>
            <div class="alert alert-<?= $report['status'] === 'approved' ? 'success' : 'warning' ?> mt-3 mb-0">
                <strong>Office remarks:</strong> <?= e((string) $report['office_remarks']) ?>
            </div>
        <?php endif; ?>

        <?php if ((int) $report['site_visit_required'] === 1): ?>
            <div class="alert alert-warning mt-3 mb-0">
                <i class="fa-solid fa-user-check"></i>
                <strong>Site visit requested.</strong>
                <?= $report['site_visit_at'] ? e(format_date((string) $report['site_visit_at'], 'F j, Y \a\t g:i A')) : 'Date to be arranged.' ?>
                <?php if ($report['site_visit_note']): ?>
                    <div class="small mt-1"><?= e((string) $report['site_visit_note']) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php foreach ($items as $item): ?>
    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-solid fa-shield-halved"></i> <?= e((string) $item['title']) ?></h2>
            <span class="pill pill--<?= $itemTone((string) $item['status']) ?>">
                <?= e(Inspections::ITEM_STATUSES[$item['status']]) ?>
            </span>
        </header>

        <div class="panel__body">
            <?php if ($item['office_comment']): ?>
                <div class="alert alert-<?= $item['status'] === 'approved' ? 'success' : 'warning' ?> py-2">
                    <strong>Office:</strong> <?= e((string) $item['office_comment']) ?>
                </div>
            <?php endif; ?>

            <?php if ($item['photos'] === []): ?>
                <p class="text-muted small mb-0"><em>No photo was sent for this standard.</em></p>
            <?php else: ?>
                <div class="evidence-grid">
                    <?php foreach ($item['photos'] as $photo): ?>
                        <figure class="evidence">
                            <?php /* Same viewer as the evidence dialog. The href
                                     remains the no-JavaScript path. */ ?>
                            <a href="<?= e(base_url('/api/inspections/photo.php?id=' . (int) $photo['id'] . '&report=' . $id)) ?>"
                               data-lightbox
                               data-caption="<?= e((string) ($photo['caption'] ?: $item['title'])) ?>">
                                <img src="<?= e(base_url('/api/inspections/photo.php?id=' . (int) $photo['id'] . '&report=' . $id)) ?>"
                                     alt="<?= e((string) ($photo['caption'] ?: $item['title'])) ?>" loading="lazy">
                            </a>
                            <?php if ($photo['caption']): ?>
                                <figcaption><span class="evidence__caption"><?= e((string) $photo['caption']) ?></span></figcaption>
                            <?php endif; ?>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($item['remarks']): ?>
                <p class="text-muted small mt-3 mb-0"><strong>Your remarks:</strong> <?= e((string) $item['remarks']) ?></p>
            <?php endif; ?>
        </div>
    </section>
<?php endforeach; ?>

<?php require __DIR__ . '/_partials/foot.php'; ?>
