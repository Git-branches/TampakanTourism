<?php
declare(strict_types=1);

/**
 * TourSync — the accredited tour guide tour guide list.
 *
 * WHY THIS EXISTS AT ALL
 *
 * tour_guide_requests was built around an office that kept no tour guide list: guide_name
 * and guide_contact are free text because the officer phoned around and typed in
 * whoever answered. That worked, and it left nothing behind — no record of who
 * is accredited, no card anybody could check, and no way to stop a revoked guide
 * being assigned by an officer who had not heard.
 *
 * This is the tour guide list. The request screen still accepts a typed name, so nothing
 * that worked before stops working; what it gains is a list to choose from.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Paginator;
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
        Session::flash('success', $guide['full_name'] . ' was removed from the tour guide list.');
    }

    redirect(base_url('/admin/tour-guides/index.php'));
}

$status = (string) ($_GET['status'] ?? '');

if ($status !== '' && !isset(Roster::STATUSES[$status])) {
    $status = '';
}

/* One parameter for the derived states, separate from `status` — see the note
   in TourGuideRosterRepository::all(). */
$show = (string) ($_GET['show'] ?? '');

if (!in_array($show, ['active', 'expired', 'no_id', 'barred'], true)) {
    $show = '';
}

$search = trim((string) ($_GET['q'] ?? ''));

/* PAGED, LIKE EVERY OTHER LIST HERE.
 *
 * This screen shipped without a pager — my omission. With one guide on the
 * tour guide list nothing looked wrong; at forty it is a forty-row page with no way to
 * break it, and the office has to scroll past the whole municipality to reach
 * the last name.
 *
 * Paginator::slice()'s own default window rather than a size chosen here, which
 * is what alerts, managers, messages, videos and the rest all do. The request
 * queue is the single exception and only because its cards are 476 px tall. */
$allGuides = Roster::all(['status' => $status, 'show' => $show, 'search' => $search]);

$pager  = Paginator::slice($allGuides, $_GET['page'] ?? null);
$guides = $pager['rows'];

/* Counted from the same computed status the rows show, not from a second
   query with its own idea of what "expired" means. */
$tally = array_fill_keys(array_keys(Roster::EFFECTIVE), 0);

foreach (Roster::all() as $row) {
    $tally[$row['effective_status']]++;
}

/* THE ADD FORM'S OWN COPY OF THE RECORD.
 *
 * NOT $g. The table below walks the tour guide list with `foreach ($guides as $g)`, and
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
   sheet reopens over the tour guide list rather than sending anybody to a second screen
   to read them. */
$sheetOpen = old_all() !== [];

require __DIR__ . '/../_partials/head.php';
?>

<?php
/* THE COUNTS ARE THE FILTER.
 *
 * They were five flat panels that only displayed a number, four of which read
 * zero — a full band of vertical space spent saying nothing, on a screen the
 * office complained was too long. As links they cost the same room and do a job,
 * and they are the same .stat-card the request queue and the rest of the admin
 * already use, so this page stops looking like it came from somewhere else.
 *
 * 'expired' and 'no_id' are derived, not stored, so they filter on their own
 * parameter rather than pretending to be values of `status`. */
$cards = [
    ['icon' => 'fa-circle-check',  'tone' => 'green', 'value' => $tally['active'],    'label' => 'Active',       'q' => 'show=active'],
    ['icon' => 'fa-calendar-xmark','tone' => 'amber', 'value' => $tally['expired'],   'label' => 'Expired',      'q' => 'show=expired'],
    ['icon' => 'fa-id-badge',      'tone' => 'blue',  'value' => $tally['no_id'],     'label' => 'No ID issued', 'q' => 'show=no_id'],
    ['icon' => 'fa-user-slash',    'tone' => 'teal',  'value' => $tally['suspended'] + $tally['revoked'],
     'label' => 'Suspended or revoked', 'q' => 'show=barred'],
];
?>

<div class="stat-grid">
    <?php foreach ($cards as $card): ?>
        <a class="stat-card stat-card--<?= e($card['tone']) ?>" href="index.php?<?= e($card['q']) ?>">
            <div class="stat-card__icon"><i class="fa-solid <?= e($card['icon']) ?>"></i></div>
            <div class="stat-card__body">
                <p class="stat-card__value"><?= n((int) $card['value']) ?></p>
                <p class="stat-card__label"><?= e($card['label']) ?></p>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<?php /* One bar, the same one arrivals, messages and videos use. The search, the
         filter and the primary action were three stacked blocks before. */ ?>
<form class="filter-bar" method="get">
    <div class="filter-bar__row">
        <div class="search-field">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, ID number, or mobile">
        </div>

        <select name="show" class="form-select form-select-sm">
            <option value="">Every guide</option>
            <?php foreach (['active' => 'Active only', 'expired' => 'Expired ID',
                            'no_id' => 'No ID issued', 'barred' => 'Suspended or revoked'] as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $show === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>

        <?php if ($show !== '' || $search !== ''): ?>
            <a class="btn btn-sm btn-link" href="index.php">Clear</a>
        <?php endif; ?>

        <div class="filter-bar__spacer"></div>

        <div class="filter-bar__actions">
            <button type="button" class="btn btn-brand btn-sm" data-dialog="addGuide">
                <i class="fa-solid fa-user-plus"></i> Add tour guide
            </button>
        </div>
    </div>
</form>

<section class="panel">
    <header class="panel__head">
        <?php /* Not plain "Tour Guides": the topbar above already says that, and
                 a panel repeating its own page title says nothing. This names
                 what the table actually holds. */ ?>
        <h2><i class="fa-solid fa-id-card"></i> Accredited tour guides</h2>
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

<?php /* The tour guide list's own copy of the add form. Same _form.php that create.php and
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

<?php /* Renders nothing while there is only one page — the partial decides,
         not this file. */ ?>
<?php require __DIR__ . '/../../app/views/partials/pager.php'; ?>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
