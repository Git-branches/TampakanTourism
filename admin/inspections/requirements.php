<?php
declare(strict_types=1);

/**
 * TourSync — the tourism standards the office checks.
 *
 * A table rather than a list in code, because the office adds requirements and
 * a code deploy is not how a Tourism Officer should have to add "Fire Exit
 * Plan". Officer-only: these define what every establishment in the
 * municipality is measured against.
 *
 * Retired, never deleted. A requirement removed outright would orphan the
 * answers on every past report — an approved inspection would lose the record
 * of what it approved.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;
use App\Repositories\InspectionRepository as Inspections;

Auth::require('officer');

if (is_post()) {
    Csrf::verify();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $id    = (int) ($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));

        if ($title === '') {
            Session::flash('danger', 'A standard needs a name.');
            redirect(base_url('/admin/inspections/requirements.php'));
        }

        $savedId = Inspections::saveRequirement([
            'title'       => $title,
            'guidance'    => (string) ($_POST['guidance'] ?? ''),
            'is_required' => !empty($_POST['is_required']),
            'is_active'   => !empty($_POST['is_active']),
            'sort_order'  => (int) ($_POST['sort_order'] ?? 0),
        ], $id > 0 ? $id : null, (int) Auth::id());

        ActivityLog::record(
            $id > 0 ? 'inspection.requirement_updated' : 'inspection.requirement_added',
            'inspection_requirement', $savedId, $title
        );

        Session::flash('success', $id > 0 ? 'Standard updated.' : 'Standard added. It appears on every open report.');
        redirect(base_url('/admin/inspections/requirements.php'));
    }

    if ($action === 'toggle') {
        $id  = (int) ($_POST['id'] ?? 0);
        $req = Inspections::findRequirement($id);

        if ($req !== null) {
            Inspections::saveRequirement([
                'title'       => $req['title'],
                'guidance'    => (string) ($req['guidance'] ?? ''),
                'is_required' => (int) $req['is_required'] === 1,
                'is_active'   => (int) $req['is_active'] !== 1,
                'sort_order'  => (int) $req['sort_order'],
            ], $id, (int) Auth::id());

            ActivityLog::record('inspection.requirement_updated', 'inspection_requirement', $id,
                ((int) $req['is_active'] === 1 ? 'Retired' : 'Reinstated') . ': ' . $req['title']);

            Session::flash('success', ((int) $req['is_active'] === 1 ? 'Retired' : 'Reinstated') . ' "' . $req['title'] . '".');
        }

        redirect(base_url('/admin/inspections/requirements.php'));
    }

    redirect(base_url('/admin/inspections/requirements.php'));
}

$editing      = (int) ($_GET['edit'] ?? 0);
$editRow      = $editing > 0 ? Inspections::findRequirement($editing) : null;
$requirements = Inspections::requirements(false);

$pageTitle    = 'Tourism Standards';
$pageIcon     = 'fa-list-ul';
$pageSubtitle = 'What every establishment is checked against';

require __DIR__ . '/../_partials/head.php';
?>

<p class="mb-3">
    <a href="index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa-solid fa-arrow-left"></i> Back to Compliance Review
    </a>
</p>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-list-ul"></i> Standards</h2>
        <span class="text-muted small"><?= n(count($requirements)) ?> defined</span>
    </header>

    <div class="panel__body">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:4rem">Order</th>
                        <th>Standard</th>
                        <th>Required</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requirements as $r): ?>
                        <tr class="<?= (int) $r['is_active'] === 1 ? '' : 'text-muted' ?>">
                            <td class="num"><?= n((int) $r['sort_order']) ?></td>
                            <td>
                                <span class="cell-strong"><?= e((string) $r['title']) ?></span>
                                <?php if ($r['guidance']): ?>
                                    <span class="cell-sub"><?= e(mb_substr((string) $r['guidance'], 0, 110)) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= (int) $r['is_required'] === 1
                                    ? '<span class="pill pill--flag">Required</span>'
                                    : '<span class="pill pill--void">Optional</span>' ?>
                            </td>
                            <td>
                                <?= (int) $r['is_active'] === 1
                                    ? '<span class="pill pill--ok">In use</span>'
                                    : '<span class="pill pill--void">Retired</span>' ?>
                            </td>
                            <td class="text-end">
                                <a href="requirements.php?edit=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                                        <?= (int) $r['is_active'] === 1 ? 'Retire' : 'Reinstate' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="text-muted small mt-3 mb-0">
            Retiring keeps a standard on the reports that already answered it, and stops it appearing on
            new ones. Deleting would erase what a past approval was based on.
        </p>
    </div>
</section>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-<?= $editRow ? 'pen' : 'plus' ?>"></i>
            <?= $editRow ? 'Edit "' . e((string) $editRow['title']) . '"' : 'Add a standard' ?></h2>
        <?php if ($editRow): ?>
            <a href="requirements.php" class="btn btn-sm btn-outline-secondary">Cancel</a>
        <?php endif; ?>
    </header>

    <div class="panel__body">
        <form method="post" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($editRow['id'] ?? 0) ?>">

            <div class="col-12 col-md-6">
                <label class="form-label" for="title">Name</label>
                <input type="text" id="title" name="title" class="form-control" maxlength="160" required
                       value="<?= e((string) ($editRow['title'] ?? '')) ?>"
                       placeholder="e.g. Fire Exit Plan">
            </div>

            <div class="col-6 col-md-3">
                <label class="form-label" for="sort_order">Order</label>
                <input type="number" id="sort_order" name="sort_order" class="form-control" min="0" max="9999"
                       value="<?= (int) ($editRow['sort_order'] ?? ((count($requirements) + 1) * 10)) ?>">
            </div>

            <div class="col-6 col-md-3 d-flex align-items-end">
                <div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_required" name="is_required" value="1"
                               <?= $editRow === null || (int) $editRow['is_required'] === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_required">Required</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                               <?= $editRow === null || (int) $editRow['is_active'] === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">In use</label>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <label class="form-label" for="guidance">What the photo must show</label>
                <textarea id="guidance" name="guidance" class="form-control" rows="2" maxlength="1000"
                          placeholder="e.g. Photograph the extinguisher where it is mounted. Include the pressure gauge and the inspection tag if they can be read."><?= e((string) ($editRow['guidance'] ?? '')) ?></textarea>
                <p class="text-muted small mt-1 mb-0">
                    The difference between "Fire Extinguisher" and "show the pressure gauge" is the
                    difference between one submission and three. This text is shown to the manager.
                </p>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-brand btn-sm">
                    <i class="fa-solid fa-floppy-disk"></i> <?= $editRow ? 'Save changes' : 'Add standard' ?>
                </button>
            </div>
        </form>
    </div>
</section>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
