<?php
declare(strict_types=1);

/**
 * TourSync — what this manager has done, in order.
 *
 * The officer has had admin/logs/index.php since the trail was built. The
 * manager had no view of their own at all, which meant the one question a
 * manager actually asks about their own account — "did my report go, and when?"
 * — could only be answered by the Office.
 *
 * SCOPED TO THIS MANAGER, NOT THIS DESTINATION.
 *
 * activity_logs carries admin_id and manager_id side by side, so a row is
 * attributable to exactly one person. This page filters on manager_id = me. A
 * destination with two managers is therefore not a shared trail: each sees
 * their own, and neither is shown the Office's side of the same events. Widening
 * it to the destination would be a permissions decision, not a layout one.
 *
 * Read-only. There is no handler here at all — an audit trail nobody can edit
 * is the only kind worth keeping.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Database;
use App\Core\ManagerAuth;
use App\Core\Paginator;

ManagerAuth::require();

$managerId = (int) ManagerAuth::id();

/* READ OFF THE ACTIONS A MANAGER ACTUALLY WRITES, not copied from the
   officer's list. The first version of this offered "My account" (account.*),
   which no manager row has ever used, and left out manager.* — which is 67 of
   the 83 rows on this account. A filter that returns nothing and a filter that
   is missing are the same failure from the reader's side.

   Each value is matched as a prefix, so one entry covers a family:
   manager.login / manager.logout / manager.contact_updated, and so on. Anything
   outside this list is still reachable under "Any activity". */
$actionGroups = [
    'report'      => 'Arrival reports',
    'inspection'  => 'Compliance inspections',
    'alert'       => 'Destination alerts',
    'manager'     => 'Sign in & account',
    'destination' => 'Destination changes',
];

$filters = [
    'action' => (string) ($_GET['action'] ?? ''),
    'from'   => (string) ($_GET['from'] ?? ''),
    'to'     => (string) ($_GET['to'] ?? ''),
];

$where  = ['l.manager_id = ?'];
$params = [$managerId];

if ($filters['action'] !== '' && isset($actionGroups[$filters['action']])) {
    $where[]  = 'l.action LIKE ?';
    $params[] = $filters['action'] . '%';
}

if ($filters['from'] !== '') {
    $where[]  = 'l.created_at >= ?';
    $params[] = $filters['from'] . ' 00:00:00';
}

if ($filters['to'] !== '') {
    $where[]  = 'l.created_at <= ?';
    $params[] = $filters['to'] . ' 23:59:59';
}

$sql = 'WHERE ' . implode(' AND ', $where);

$total  = (int) Database::scalar("SELECT COUNT(*) FROM activity_logs l {$sql}", $params);
$window = Paginator::of($total, $_GET['page'] ?? null);

/* Paged in SQL. The activity log is the one table that grows without limit, so
   fetching it all to throw most away is the thing not to do here. */
$rows = Database::all(
    "SELECT l.* FROM activity_logs l {$sql}
      ORDER BY l.id DESC
      LIMIT {$window['perPage']} OFFSET {$window['offset']}",
    $params
);

$pager = ['rows' => $rows] + $window;

$pageTitle    = 'Activity Log';
$pageIcon     = 'fa-gear';
$pageSubtitle = 'Everything done from this account, newest first';

$mgrSettingsTab = 'logs';

require __DIR__ . '/_partials/head.php';
require __DIR__ . '/_partials/settings-tabs.php';
?>

<form method="get" class="filter-bar">
    <div class="filter-bar__row">
        <select name="action" class="form-select form-select-sm" aria-label="Kind of activity">
            <option value="">Any activity</option>
            <?php foreach ($actionGroups as $prefix => $label): ?>
                <option value="<?= e($prefix) ?>" <?= $filters['action'] === $prefix ? 'selected' : '' ?>>
                    <?= e($label) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label class="filter-date">From <input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
        <label class="filter-date">To <input type="date" name="to" value="<?= e($filters['to']) ?>"></label>

        <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
        <a href="logs.php" class="btn btn-sm btn-link">Clear</a>
    </div>
</form>

<p class="result-count"><?= n($total) ?> entr<?= $total === 1 ? 'y' : 'ies' ?></p>

<?php if ($rows === []): ?>
    <section class="panel">
        <div class="panel__body">
            <div class="empty rf-empty">
                <i class="fa-solid fa-clipboard-list" aria-hidden="true"></i>
                <?php if ($filters !== ['action' => '', 'from' => '', 'to' => '']): ?>
                    <h3>Nothing matches those filters</h3>
                    <p>Widen the dates, or <a href="logs.php">clear the filters</a>.</p>
                <?php else: ?>
                    <h3>Nothing recorded yet</h3>
                    <p>
                        Submitting a report, sending an inspection or raising an alert is written
                        here as you do it, with the date and time it happened.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </section>
<?php else: ?>
    <section class="panel">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Action</th>
                        <th>Detail</th>
                        <?php /* No "Who" column: every row on this page is you. */ ?>
                        <th>Address</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($rows as $l): ?>
                        <tr>
                            <td class="small text-nowrap">
                                <?= e(format_date((string) $l['created_at'], 'M j, Y g:i A')) ?>
                            </td>
                            <td><code class="log-action"><?= e((string) $l['action']) ?></code></td>
                            <td class="small"><?= e((string) ($l['description'] ?? '—')) ?></td>
                            <td class="small mono text-muted">
                                <?= e(ActivityLog::readableIp($l['ip_address'])) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/partials/pager.php'; ?>

<?php require __DIR__ . '/_partials/foot.php'; ?>
