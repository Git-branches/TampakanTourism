<?php
declare(strict_types=1);

/**
 * TourSync — Compliance Review › Manage Requirements.
 *
 * What every destination is checked against. A table rather than a list in
 * code, because the office adds requirements and a code deploy is not how a
 * Tourism Officer should have to add "Fire Exit Plan". Officer only: these
 * define what every establishment in the municipality is measured against.
 *
 * ADDING ONE reaches the managers two ways: syncItems() puts it on every open
 * checklist, and announceRequirement() rings every destination's bell.
 *
 * DELETING ONE depends on whether it has been used. Every checklist row
 * CASCADES from its requirement, so a plain DELETE of a requirement that past
 * reports were checked against would erase the photographs and decisions on
 * approved inspections. InspectionRepository::deleteRequirement() deletes an
 * unused one outright and otherwise REMOVES it: off this list and off every open
 * checklist, kept on the reports that already answered it, and restorable from
 * "Removed requirements" below.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;
use App\Repositories\InspectionRepository as Inspections;

Auth::require('officer');

$back = base_url('/admin/inspections/requirements.php');

if (is_post()) {
    Csrf::verify();

    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    // ---- add or edit ------------------------------------------------------
    if ($action === 'save') {
        $title  = trim((string) ($_POST['title'] ?? ''));
        $before = $id > 0 ? Inspections::findRequirement($id) : null;

        if ($title === '') {
            Session::flash('danger', 'A requirement needs a name.');
            redirect($back . ($before !== null ? '?edit=' . $id : '?add=1'));
        }

        if ($id > 0 && $before === null) {
            Session::flash('danger', 'That requirement no longer exists.');
            redirect($back);
        }

        /* Whether it is in use is not a field any more: a new requirement is in
           use, an edit keeps what it was, and Delete / Restore change it. */
        $savedId = Inspections::saveRequirement([
            'title'       => $title,
            'guidance'    => (string) ($_POST['guidance'] ?? ''),
            'is_required' => !empty($_POST['is_required']),
            'is_active'   => $before === null ? true : (int) $before['is_active'] === 1,
            'sort_order'  => (int) ($_POST['sort_order'] ?? 0),
            'min_photos'  => (int) ($_POST['min_photos'] ?? 1),
            'max_photos'  => (int) ($_POST['max_photos'] ?? 2),
        ], $before !== null ? $id : null, (int) Auth::id());

        ActivityLog::record(
            $before !== null ? 'inspection.requirement_updated' : 'inspection.requirement_added',
            'inspection_requirement', $savedId, $title
        );

        if ($before === null) {
            $told = Inspections::announceRequirement($savedId, $title);
            Session::flash('success', 'Requirement added. It is now on every destination\'s compliance checklist, '
                . 'and ' . n($told) . ' destination(s) were notified.');
        } else {
            Session::flash('success', 'Requirement updated.');
        }

        redirect($back);
    }

    $req = $id > 0 ? Inspections::findRequirement($id) : null;

    if ($req === null) {
        Session::flash('danger', 'That requirement no longer exists.');
        redirect($back);
    }

    // ---- delete -----------------------------------------------------------
    if ($action === 'delete') {
        $outcome = Inspections::deleteRequirement($id);

        ActivityLog::record(
            $outcome === 'deleted' ? 'inspection.requirement_deleted' : 'inspection.requirement_removed',
            'inspection_requirement', $outcome === 'deleted' ? null : $id,
            ($outcome === 'deleted' ? 'Deleted: ' : 'Removed from use: ') . $req['title']
        );

        Session::flash('success', $outcome === 'deleted'
            ? '"' . $req['title'] . '" was deleted.'
            : '"' . $req['title'] . '" was removed from every checklist. Past reports that were checked '
              . 'against it keep it, so it is kept under Removed requirements, where it can be restored.');

        redirect($back);
    }

    // ---- restore ----------------------------------------------------------
    if ($action === 'restore') {
        Inspections::saveRequirement([
            'title'       => $req['title'],
            'guidance'    => (string) ($req['guidance'] ?? ''),
            'is_required' => (int) $req['is_required'] === 1,
            'is_active'   => true,
            'sort_order'  => (int) $req['sort_order'],
            'min_photos'  => (int) ($req['min_photos'] ?? 1),
            'max_photos'  => (int) ($req['max_photos'] ?? 2),
        ], $id, (int) Auth::id());

        $told = Inspections::announceRequirement($id, (string) $req['title'], true);

        ActivityLog::record('inspection.requirement_restored', 'inspection_requirement', $id,
            'Restored: ' . $req['title']);

        Session::flash('success', '"' . $req['title'] . '" is back on every checklist. '
            . n($told) . ' destination(s) were notified.');

        redirect($back);
    }

    redirect($back);
}

$all     = Inspections::requirements(false);
$active  = array_values(array_filter($all, static fn (array $r): bool => (int) $r['is_active'] === 1));
$removed = array_values(array_filter($all, static fn (array $r): bool => (int) $r['is_active'] !== 1));

/* ?edit=ID opens the dialog already filled in; ?add=1 reopens an empty one
   after a rejected save. Both through the sheet's own data-open. */
$editRow   = (int) ($_GET['edit'] ?? 0) > 0 ? Inspections::findRequirement((int) $_GET['edit']) : null;
$sheetOpen = $editRow !== null || !empty($_GET['add']);

$counts    = Inspections::counts();
$tabCounts = ['reports' => (int) $counts['submitted'] + (int) $counts['reviewing']];
$activeTab = 'requirements';

$pageTitle    = 'Compliance Review';
$pageIcon     = 'fa-clipboard-check';
$pageSubtitle = 'Manage Requirements · what every destination is checked against';

require __DIR__ . '/../_partials/head.php';
require __DIR__ . '/_tabs.php';

/** One row of either list; $removedList changes the menu. */
$row = static function (array $r, bool $removedList): void {
    $lo = (int) ($r['min_photos'] ?? 1);
    $hi = (int) ($r['max_photos'] ?? $lo);
    $menuLabel = 'Actions for ' . $r['title'];
    ?>
    <tr class="<?= $removedList ? 'text-muted' : '' ?>">
        <td class="num"><?= n((int) $r['sort_order']) ?></td>
        <td>
            <span class="cell-strong"><?= e((string) $r['title']) ?></span>
            <?php if ($r['guidance']): ?>
                <span class="cell-sub"><?= e(mb_substr((string) $r['guidance'], 0, 110)) ?></span>
            <?php endif; ?>
        </td>
        <td class="num"><?= $hi > $lo ? n($lo) . '&ndash;' . n($hi) : n($lo) ?></td>
        <td>
            <?= (int) $r['is_required'] === 1
                ? '<span class="pill pill--flag">Required</span>'
                : '<span class="pill pill--void">Optional</span>' ?>
        </td>
        <td class="text-end">
            <details class="kebab kebab--pop">
                <summary aria-label="<?= e($menuLabel) ?>">
                    <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
                </summary>
                <div class="kebab__menu">
                    <?php if (!$removedList): ?>
                        <a class="kebab__item" href="requirements.php?edit=<?= (int) $r['id'] ?>">
                            <i class="fa-solid fa-pen" aria-hidden="true"></i> Edit
                        </a>

                        <hr class="kebab__rule">

                        <?php
                        /* The question says which of the two will happen, because
                           the officer cannot see from here whether past reports
                           used this requirement. */
                        $inUse = Inspections::requirementInUse((int) $r['id']);
                        $ask   = $inUse
                            ? 'Delete "' . $r['title'] . '"? Past compliance reports were checked against it, so '
                              . 'their photos and decisions are kept: it is removed from every destination\'s '
                              . 'checklist and moved to Removed requirements, where it can be restored.'
                            : 'Delete "' . $r['title'] . '" permanently? No report has used it yet, so nothing '
                              . 'else is lost. It leaves every destination\'s checklist. This cannot be undone.';
                        ?>
                        <form method="post" data-confirm="<?= e($ask) ?>" data-confirm-tone="danger">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button type="submit" class="kebab__item kebab__item--danger">
                                <i class="fa-solid fa-trash-can" aria-hidden="true"></i> Delete
                            </button>
                        </form>
                    <?php else: ?>
                        <?php
                        $ask = 'Restore "' . $r['title'] . '"? It returns to every destination\'s compliance '
                            . 'checklist and each destination\'s managers are notified.';
                        ?>
                        <form method="post" data-confirm="<?= e($ask) ?>" data-confirm-tone="normal">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button type="submit" class="kebab__item">
                                <i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Restore
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </details>
        </td>
    </tr>
    <?php
};
?>

<div class="toolbar">
    <p class="result-count mb-0">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <?= n(count($active)) ?> requirement<?= count($active) === 1 ? '' : 's' ?> on every destination's
        compliance checklist. A new one reaches every manager at once, with a notification.
    </p>
    <button type="button" class="btn btn-brand btn-sm" data-dialog="requirementSheet">
        <i class="fa-solid fa-plus" aria-hidden="true"></i> Add Requirement
    </button>
</div>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-list-check"></i> Compliance Requirements</h2>
        <span class="text-muted small"><?= n(count($active)) ?> in use</span>
    </header>

    <div class="panel__body">
        <?php if ($active === []): ?>
            <div class="empty">
                <i class="fa-solid fa-list-check"></i>
                <h3>No requirements yet</h3>
                <p>Add the first one, and every destination's manager will be asked to photograph it.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:4rem">Order</th>
                            <th>Requirement</th>
                            <th>Photos</th>
                            <th>Required</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($active as $r) { $row($r, false); } ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($removed !== []): ?>
    <?php /* Folded: kept for the record and for Restore, not for daily work. */ ?>
    <details class="panel requirement-removed">
        <summary class="panel__head">
            <h2><i class="fa-solid fa-box-archive"></i> Removed requirements</h2>
            <span class="text-muted small"><?= n(count($removed)) ?> &middot; kept because past reports used them</span>
        </summary>
        <div class="panel__body">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                        <?php foreach ($removed as $r) { $row($r, true); } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </details>
<?php endif; ?>

<?php /* ONE DIALOG FOR ADD AND EDIT. Edit reloads with ?edit=ID and the sheet
         opens itself filled in (data-open), so there is one form to keep right
         instead of two. */ ?>
<dialog class="sheet" id="requirementSheet"<?= $sheetOpen ? ' data-open' : '' ?>>
    <form method="post" class="sheet__form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($editRow['id'] ?? 0) ?>">

        <header class="sheet__head">
            <h2>
                <i class="fa-solid fa-<?= $editRow ? 'pen' : 'plus' ?>" aria-hidden="true"></i>
                <?= $editRow ? 'Edit requirement' : 'Add a compliance requirement' ?>
            </h2>
            <?php /* Closing an edit goes back to the plain list, so a reload
                     does not reopen it. */ ?>
            <?php if ($editRow): ?>
                <a href="requirements.php" class="sheet__close" aria-label="Close">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </a>
            <?php else: ?>
                <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            <?php endif; ?>
        </header>

        <div class="sheet__body">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="reqTitle">Requirement <span class="req">*</span></label>
                    <input type="text" id="reqTitle" name="title" class="form-control" maxlength="160" required
                           value="<?= e((string) ($editRow['title'] ?? '')) ?>"
                           placeholder="e.g. Fire Exit Plan">
                </div>

                <div class="col-12">
                    <label class="form-label" for="reqGuidance">What the photo must show</label>
                    <textarea id="reqGuidance" name="guidance" class="form-control" rows="3" maxlength="1000"
                              placeholder="e.g. Photograph the exit plan where it is posted, and the exit it points to."><?= e((string) ($editRow['guidance'] ?? '')) ?></textarea>
                    <p class="field-hint">
                        Shown to the manager on their checklist. "Show the pressure gauge" is the difference
                        between one submission and three.
                    </p>
                </div>

                <div class="col-6">
                    <label class="form-label" for="reqMin">Photos needed</label>
                    <input type="number" id="reqMin" name="min_photos" class="form-control" min="1" max="10"
                           value="<?= (int) ($editRow['min_photos'] ?? 1) ?>">
                    <p class="field-hint">A report cannot be submitted with fewer.</p>
                </div>

                <div class="col-6">
                    <label class="form-label" for="reqMax">Photos suggested, up to</label>
                    <input type="number" id="reqMax" name="max_photos" class="form-control" min="1" max="10"
                           value="<?= (int) ($editRow['max_photos'] ?? 2) ?>">
                    <p class="field-hint">Guidance for the manager, not enforced.</p>
                </div>

                <div class="col-6">
                    <label class="form-label" for="reqOrder">Order on the checklist</label>
                    <input type="number" id="reqOrder" name="sort_order" class="form-control" min="0" max="9999"
                           value="<?= (int) ($editRow['sort_order'] ?? ((count($all) + 1) * 10)) ?>">
                </div>

                <div class="col-6 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="reqRequired" name="is_required" value="1"
                               <?= $editRow === null || (int) $editRow['is_required'] === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="reqRequired">Required to submit</label>
                    </div>
                </div>
            </div>
        </div>

        <footer class="sheet__foot">
            <?php if ($editRow): ?>
                <a href="requirements.php" class="btn btn-sm btn-outline-secondary">Cancel</a>
            <?php else: ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close>Cancel</button>
            <?php endif; ?>
            <button type="submit" class="btn btn-sm btn-brand">
                <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                <?= $editRow ? 'Save Changes' : 'Add Requirement' ?>
            </button>
        </footer>
    </form>
</dialog>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
