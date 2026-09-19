<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Paginator;
use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Session;
use App\Core\SmsGateway;
use App\Repositories\ManagerRepository;

Auth::require();

$pageTitle    = 'Destination Managers';
$pageIcon     = 'fa-address-book';
$pageSubtitle = 'Who the Tourism Office notifies';

if (is_post()) {
    Csrf::verify();

    $id     = (int) ($_POST['id'] ?? 0);
    $active = !empty($_POST['activate']);
    $m      = ManagerRepository::find($id);

    /* DELETE IS FOR A MISTAKE, NOT FOR SOMEBODY LEAVING.
     *
     * Somebody who leaves is deactivated: their name stays on the reports they
     * submitted and on the record of who was texted. Delete exists for the
     * entry made by mistake — a duplicate, a wrong number — and it refuses the
     * moment there is anything to lose. The check is repeated in the repository
     * inside a transaction, so a manager who signs in between the page being
     * drawn and the button being pressed is not deleted. Officer only, like
     * issuing a sign-in. */
    if (($_POST['action'] ?? '') === 'delete') {
        if (!Auth::isOfficer()) {
            Session::flash('danger', 'Only the Tourism Officer can delete a manager.');
        } elseif ($m === null) {
            Session::flash('danger', 'That manager is no longer on record.');
        } elseif (ManagerRepository::deleteIfUnused($id)) {
            ActivityLog::record('manager.delete', 'manager', null,
                'Deleted manager entry "' . $m['full_name'] . '" (#' . $id . ', never used)');
            Session::flash('success', $m['full_name'] . ' was deleted.');
        } else {
            Session::flash('danger', $m['full_name'] . ' has records in the system and cannot be deleted. '
                . 'Deactivate them instead — they lose access, and their records keep their name.');
        }

        redirect(base_url('/admin/managers/index.php'));
    }

    if ($m !== null) {
        ManagerRepository::setActive($id, $active);
        ActivityLog::record(
            $active ? 'manager.activate' : 'manager.deactivate',
            'manager', $id,
            ($active ? 'Reactivated ' : 'Deactivated ') . $m['full_name']
        );
        Session::flash('success', $m['full_name'] . ($active
            ? ' reactivated.'
            : ' deactivated — they have been signed out, cannot sign in, and will no longer receive notices.'));
    }

    redirect(base_url('/admin/managers/index.php'));
}

$pager      = Paginator::slice(
    ManagerRepository::all(['search' => trim((string) ($_GET['q'] ?? ''))]),
    $_GET['page'] ?? null
);
$managers   = $pager['rows'];
$counts     = ManagerRepository::counts();

/* The add form is rendered into a dialog at the foot of this page, so the two
   things it needs have to be loaded here as well as in create.php. */
$destinations = Database::all("SELECT id, name, slug FROM destinations WHERE status='active' ORDER BY name");

/* Rejected input comes back from create.php with the errors attached; the
   sheet reopens over the registry rather than sending anybody to a second
   screen to read them. */
/* NOT $m. The table below walks the registry with `foreach ($managers as $m)`,
   and the dialog is rendered after it — so a variable called $m here would hold
   the last manager on the page by the time the form read it, and the rejected
   input would be silently replaced by somebody else's details. */
$sheetManager = array_fill_keys(
    ['id','full_name','position','destination_id','mobile_number','email','sms_opt_in','is_active','username'],
    ''
);

foreach (array_keys($sheetManager) as $k) {
    $old = old_all();
    if (isset($old[$k])) { $sheetManager[$k] = $old[$k]; }
}

$sheetOpen = old_all() !== [];
$uncovered  = ManagerRepository::destinationsWithoutManager();
$driverLive = SmsGateway::isLive();

require __DIR__ . '/../_partials/head.php';
?>

<div class="stat-grid">
    <article class="stat-card stat-card--green">
        <div class="stat-card__icon"><i class="fa-solid fa-address-book"></i></div>
        <div class="stat-card__body">
            <p class="stat-card__value"><?= n($counts['active']) ?></p>
            <p class="stat-card__label">Active managers</p>
        </div>
    </article>
    <article class="stat-card stat-card--blue">
        <div class="stat-card__icon"><i class="fa-solid fa-comment-sms"></i></div>
        <div class="stat-card__body">
            <p class="stat-card__value"><?= n($counts['opted_in']) ?></p>
            <p class="stat-card__label">Will receive SMS</p>
        </div>
    </article>
    <article class="stat-card stat-card--amber">
        <div class="stat-card__icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <div class="stat-card__body">
            <p class="stat-card__value"><?= n(count($uncovered)) ?></p>
            <p class="stat-card__label">Destinations with no manager</p>
        </div>
    </article>
</div>

<div class="panel panel--notice">
    <div class="panel__body">
        <h2><i class="fa-solid fa-<?= $driverLive ? 'tower-broadcast' : 'flask' ?>"></i>
            SMS is <?= $driverLive ? 'LIVE' : 'in test mode' ?></h2>
        <p class="mb-0"><?= e(App\Core\SmsGateway::driver()->describe()) ?></p>
    </div>
</div>

<?php if ($uncovered !== []): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <strong><?= n(count($uncovered)) ?> active destination<?= count($uncovered) === 1 ? ' has' : 's have' ?> no manager on record</strong>
        — nobody there receives advisories or closure notices:
        <?= e(implode(', ', array_column($uncovered, 'name'))) ?>
    </div>
<?php endif; ?>

<div class="toolbar">
    <form class="toolbar__filters" method="get">
        <div class="search-field">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="q" value="<?= e((string) ($_GET['q'] ?? '')) ?>" placeholder="Search name, position, or number">
        </div>
        <button type="submit" class="btn btn-sm btn-outline-secondary">Search</button>
    </form>
    <button type="button" class="btn btn-brand btn-sm" data-dialog="addManager">
        <i class="fa-solid fa-plus"></i> Add Manager
    </button>
</div>

<?php if ($managers === []): ?>
    <div class="panel"><div class="panel__body">
        <div class="empty">
            <i class="fa-solid fa-address-book"></i>
            <p><strong>No destination managers registered.</strong></p>
            <p>Add the people responsible at each destination so the Office can reach them
               with advisories, closures, and submission schedules.</p>
            <p class="mt-3">
                <button type="button" class="btn btn-brand btn-sm" data-dialog="addManager">
                    <i class="fa-solid fa-plus"></i> Add the first manager
                </button>
            </p>
        </div>
    </div></div>
<?php else: ?>
    <div class="panel">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Name</th><th>Destination</th><th>Mobile</th><th>SMS</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($managers as $m): ?>
                    <tr class="<?= (int) $m['is_active'] === 0 ? 'is-voided' : '' ?>">
                        <td>
                            <span class="cell-strong"><?= e($m['full_name']) ?></span>
                            <?php if ($m['position']): ?><span class="cell-sub"><?= e($m['position']) ?></span><?php endif; ?>
                        </td>
                        <td>
                            <?= e($m['destination_name']) ?>
                            <?php if ($m['destination_status'] === 'archived'): ?>
                                <span class="pill pill--void">Archived</span>
                            <?php endif; ?>
                        </td>
                        <td class="mono"><?= e($m['mobile_number']) ?></td>
                        <td>
                            <?php if ((int) $m['sms_opt_in'] === 1): ?>
                                <span class="pill pill--ok"><i class="fa-solid fa-check"></i> Opted in</span>
                            <?php else: ?>
                                <span class="pill pill--void">Opted out</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int) $m['is_active'] === 1): ?>
                                <span class="pill pill--ok">Active</span>
                            <?php else: ?>
                                <span class="pill pill--void">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php /* ONE MENU, NOT FOUR BUTTONS.
                                     Edit, Access, Deactivate and Delete sat in a row
                                     of four differently sized buttons that pushed the
                                     table wide and put Deactivate a slip away from
                                     Access. They live behind the same ⋮ menu the
                                     alerts and messages lists use, with the two
                                     actions that take something away last, below a
                                     rule. The popover variant floats clear of the
                                     table's scroll box, so the menu is never cut off. */ ?>
                            <details class="kebab kebab--pop">
                                <summary aria-label="Actions for <?= e($m['full_name']) ?>">
                                    <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
                                </summary>

                                <div class="kebab__menu">
                            <?php /* Still a real href, so a middle-click, a Ctrl-click and a
                                     browser with no JavaScript all reach the full page. The
                                     script intercepts a plain click and opens the dialog. */ ?>
                            <a href="edit.php?id=<?= (int) $m['id'] ?>" class="kebab__item"
                               data-modal-page data-modal-title="Edit <?= e($m['full_name']) ?>">
                                <i class="fa-solid fa-pen" aria-hidden="true"></i> Edit
                            </a>

                            <?php if (Auth::isOfficer()): ?>
                                <!-- Officer only. Issuing a sign-in hands someone the ability to
                                     file figures that become the municipality's official
                                     statistics — not a staff-level action. -->
                                <a href="access.php?id=<?= (int) $m['id'] ?>" class="kebab__item"
                                   data-modal-page
                                   data-modal-title="<?= ($m['username'] ?? null) ? 'Access' : 'Issue sign-in' ?> &mdash; <?= e($m['full_name']) ?>">
                                    <i class="fa-solid fa-key" aria-hidden="true"></i>
                                    <?= ($m['username'] ?? null) ? 'Access' : 'Issue sign-in' ?>
                                </a>
                            <?php endif; ?>

                            <hr class="kebab__rule">

                            <?php
                            /* DEACTIVATING SOMEBODY WAS ONE UNGUARDED CLICK.
                             *
                             * The button sits immediately after Edit and Access in a row of
                             * identically sized controls, and it silently stops a real person
                             * receiving the SMS notices this office sends about their own
                             * destination — closures, advisories, submission reminders. They
                             * are not told. The first anyone learns of a misclick is a
                             * manager who stopped answering.
                             *
                             * Asked through the same dialog as every other consequential
                             * action here rather than through the browser's own box, and
                             * phrased as what actually happens to that named person. */
                            $askActivate = (int) $m['is_active'] === 1
                                ? 'Deactivate ' . $m['full_name'] . '? They are signed out at once and '
                                  . 'cannot sign in, and stop receiving advisories, closures and submission '
                                  . 'reminders for ' . ($m['destination_name'] ?? 'their destination') . '. '
                                  . 'Every report they filed is kept, under their name. They are not notified of this.'
                                : 'Reactivate ' . $m['full_name'] . '? They can sign in again with their '
                                  . 'existing password, and begin receiving notices again.';
                            ?>
                            <form method="post"
                                  data-confirm="<?= e($askActivate) ?>"
                                  data-confirm-tone="<?= (int) $m['is_active'] === 1 ? 'danger' : 'normal' ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                <input type="hidden" name="activate" value="<?= (int) $m['is_active'] === 1 ? '0' : '1' ?>">
                                <?php if ((int) $m['is_active'] === 1): ?>
                                    <button class="kebab__item kebab__item--danger">
                                        <i class="fa-solid fa-user-slash" aria-hidden="true"></i> Deactivate
                                    </button>
                                <?php else: ?>
                                    <button class="kebab__item">
                                        <i class="fa-solid fa-user-check" aria-hidden="true"></i> Reactivate
                                    </button>
                                <?php endif; ?>
                            </form>

                            <?php
                            /* Offered only for an entry with nothing behind it — never
                               signed in, nothing filed. Anyone with a history has
                               Deactivate, which keeps it; the server refuses a delete
                               of them regardless of what the page showed. */
                            $deletable = Auth::isOfficer()
                                && $m['last_login_at'] === null
                                && ManagerRepository::history((int) $m['id']) === [];
                            ?>
                            <?php if ($deletable): ?>
                                <?php
                                $askDelete = 'Delete ' . $m['full_name'] . ' permanently? This entry has never '
                                    . 'signed in or filed anything, so nothing else is lost. This cannot be undone.';
                                ?>
                                <form method="post"
                                      data-confirm="<?= e($askDelete) ?>" data-confirm-tone="danger">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button class="kebab__item kebab__item--danger">
                                        <i class="fa-solid fa-trash-can" aria-hidden="true"></i> Delete
                                    </button>
                                </form>
                            <?php endif; ?>
                                </div>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../app/views/partials/pager.php'; ?>

<?php /* Edit and Access open in here. ABOVE the Add sheet on purpose: both
         render the same _form.php, so while this is open the page holds two
         elements with each of that form's ids and getElementById answers with
         whichever comes first. See the partial. */ ?>
<?php require __DIR__ . '/../_partials/page-modal.php'; ?>

<?php /* The registry's own copy of the add form. Same _form.php create.php and
         edit.php use, so a field added there appears here without anyone
         remembering to. */ ?>
<dialog class="sheet" id="addManager"<?= $sheetOpen ? ' data-open' : '' ?>>
    <?php $inSheet = true; $m = $sheetManager; require __DIR__ . '/_form.php'; ?>
</dialog>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
