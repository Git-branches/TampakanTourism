<?php
declare(strict_types=1);

/**
 * TourSync — the accredited tour guide roster.
 *
 * WHY THIS EXISTS AT ALL
 *
 * tour_guide_requests was built around an office that kept no roster: guide_name
 * and guide_contact are free text because the officer phoned around and typed in
 * whoever answered. That worked, and it left nothing behind — no record of who
 * is accredited, no card anybody could check, and no way to stop a revoked guide
 * being assigned by an officer who had not heard.
 *
 * This is the roster. The request screen still accepts a typed name, so nothing
 * that worked before stops working; what it gains is a list to choose from.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;
use App\Repositories\TourGuideRosterRepository as Roster;

Auth::require();

$pageTitle = 'Tour Guides';
$pageIcon  = 'fa-id-card';

if (is_post()) {
    Csrf::verify();

    $id    = (int) ($_POST['id'] ?? 0);
    $guide = $id > 0 ? Roster::find($id) : null;

    if ($guide === null) {
        Session::flash('danger', 'That guide could not be found.');
        redirect(base_url('/admin/tour-guides/index.php'));
    }

    if (($_POST['action'] ?? '') === 'delete') {
        Roster::delete($id);
        ActivityLog::record('guide.delete', 'tour_guide', $id, 'Removed ' . $guide['full_name']);
        Session::flash('success', $guide['full_name'] . ' was removed from the roster.');
    }

    redirect(base_url('/admin/tour-guides/index.php'));
}

$status = (string) ($_GET['status'] ?? '');

if ($status !== '' && !isset(Roster::STATUSES[$status])) {
    $status = '';
}

$search = trim((string) ($_GET['q'] ?? ''));
$guides = Roster::all(['status' => $status, 'search' => $search]);

/* Counted from the same computed status the rows show, not from a second
   query with its own idea of what "expired" means. */
$tally = array_fill_keys(array_keys(Roster::EFFECTIVE), 0);

foreach (Roster::all() as $row) {
    $tally[$row['effective_status']]++;
}

/* THE ADD FORM'S OWN COPY OF THE RECORD.
 *
 * NOT $g. The table below walks the roster with `foreach ($guides as $g)`, and
 * the sheet is rendered after it — so a variable called $g here would hold the
 * last guide on the page by the time the form read it, and somebody's rejected
 * input would come back silently wearing a stranger's details. The manager
 * registry learned this the same way. */
$sheetGuide = array_fill_keys(
    ['id', 'full_name', 'address', 'mobile_number', 'email', 'photo_path',
     'status', 'valid_until', 'status_note', 'notes'],
    ''
);

$sheetGuide['status'] = 'active';

foreach (array_keys($sheetGuide) as $key) {
    $old = old_all();

    if (isset($old[$key])) {
        $sheetGuide[$key] = $old[$key];
    }
}

/* Rejected input comes back from create.php with the errors attached, and the
   sheet reopens over the roster rather than sending anybody to a second screen
   to read them. */
$sheetOpen = old_all() !== [];

require __DIR__ . '/../_partials/head.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <p class="text-muted mb-0">
        Guides the Municipal Tourism Office has accredited. Only a guide whose card
        is <strong>active and unexpired</strong> can be assigned to a request.
    </p>
    <button type="button" class="btn btn-brand btn-sm" data-dialog="addGuide">
        <i class="fa-solid fa-user-plus"></i> Add tour guide
    </button>
</div>

<?php /* Expired and never-issued are called out because both are silent
         failures: the guide is on the roster, looks fine in a list, and cannot
         be assigned. An officer should learn that here rather than at the
         moment they are trying to answer a visitor. */ ?>
<div class="row g-2 mb-3">
    <?php foreach ([
        ['active',    'Active',        'ok'],
        ['expired',   'Expired',       'flag'],
        ['no_id',     'No ID issued',  'void'],
        ['suspended', 'Suspended',     'flag'],
        ['revoked',   'Revoked',       'void'],
    ] as [$key, $label, $tone]): ?>
        <div class="col-6 col-md">
            <div class="panel h-100">
                <div class="panel__body py-2">
                    <span class="pill pill--<?= $tone ?>"><?= n($tally[$key]) ?></span>
                    <span class="cell-sub d-block mt-1"><?= e($label) ?></span>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<form class="row g-2 mb-3" method="get">
    <div class="col-md-5">
        <input type="search" class="form-control form-control-sm" name="q"
               value="<?= e($search) ?>" placeholder="Name, ID number, or mobile">
    </div>
    <div class="col-md-3">
        <select class="form-select form-select-sm" name="status">
            <option value="">Any status set by the office</option>
            <?php foreach (Roster::STATUSES as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <button class="btn btn-outline-secondary btn-sm w-100" type="submit">Filter</button>
    </div>
</form>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-id-card"></i> Roster</h2>
    </header>

    <?php if ($guides === []): ?>
        <div class="panel__body text-center py-4">
            <h3 class="h6">No guides yet</h3>
            <p class="text-muted mb-3">
                Add the guides the office has accredited. Each one gets an ID number,
                a printable card, and a QR code a visitor can scan to check them.
            </p>
            <button type="button" class="btn btn-brand btn-sm" data-dialog="addGuide">
                <i class="fa-solid fa-user-plus"></i> Add the first guide
            </button>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Guide</th>
                        <th>ID number</th>
                        <th>Valid until</th>
                        <th>Status</th>
                        <th class="text-end">&nbsp;</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($guides as $g): ?>
                    <?php
                    $effective = (string) $g['effective_status'];
                    $tone = match ($effective) {
                        'active'  => 'ok',
                        'expired', 'suspended' => 'flag',
                        default   => 'void',
                    };
                    $photo = uploaded_url((string) ($g['photo_path'] ?? ''));
                    ?>
                    <tr>
                        <td data-label="Guide">
                            <div class="d-flex align-items-center gap-2">
                                <?php if ($photo !== null): ?>
                                    <img src="<?= e($photo) ?>" alt="" width="36" height="36"
                                         style="border-radius:50%; object-fit:cover;">
                                <?php endif; ?>
                                <span>
                                    <strong><?= e((string) $g['full_name']) ?></strong>
                                    <?php if ($g['mobile_number']): ?>
                                        <span class="cell-sub d-block"><?= e((string) $g['mobile_number']) ?></span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </td>
                        <td data-label="ID number">
                            <code><?= e((string) $g['guide_code']) ?></code>
                        </td>
                        <td data-label="Valid until">
                            <?= $g['valid_until']
                                ? e(format_date((string) $g['valid_until'], 'M j, Y'))
                                : '<span class="text-muted">not issued</span>' ?>
                        </td>
                        <td data-label="Status">
                            <span class="pill pill--<?= $tone ?>"><?= e(Roster::EFFECTIVE[$effective]) ?></span>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-outline-secondary btn-sm"
                               href="<?= e(base_url('/admin/tour-guides/view.php?id=' . (int) $g['id'])) ?>">
                                Open
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php /* The roster's own copy of the add form. Same _form.php that create.php and
         edit.php use, so a field added there appears here without anyone
         remembering to. */ ?>
<?php /* --wide, not the 680px default. This form carries three groups where the
         manager sheet carries one, and at 680px the paired credential boxes
         wrap onto separate lines and the sheet becomes a scroll. */ ?>
<dialog class="sheet sheet--wide" id="addGuide"<?= $sheetOpen ? ' data-open' : '' ?>>
    <?php
    $inSheet     = true;
    $isEdit      = false;
    $credentials = [];
    $g           = $sheetGuide;
    require __DIR__ . '/_form.php';
    ?>
</dialog>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
