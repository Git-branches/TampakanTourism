<?php
declare(strict_types=1);

/**
 * TourSync — activity log.       Officer only.
 *
 * Append-only. Nothing in the application updates or deletes a row in this
 * table, and this screen offers no way to. When an officer voids an arrival —
 * the one action that changes a published government figure — this is the
 * evidence of who did it, when, and why.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Paginator;
use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Database;

Auth::require('officer');

$pageTitle    = 'Activity Log';
$pageIcon     = 'fa-clipboard-list';
$pageSubtitle = 'Append-only record of every administrative action';

$filters = [
    'admin_id' => (int) ($_GET['admin'] ?? 0) ?: null,
    'action'   => trim((string) ($_GET['action'] ?? '')),
    'entity'   => trim((string) ($_GET['entity'] ?? '')),
    'from'     => trim((string) ($_GET['from'] ?? '')),
    'to'       => trim((string) ($_GET['to'] ?? '')),
];

$clauses = [];
$params  = [];

if ($filters['admin_id'] !== null) { $clauses[] = 'l.admin_id = ?';   $params[] = $filters['admin_id']; }
if ($filters['entity']  !== '')   { $clauses[] = 'l.entity_type = ?'; $params[] = $filters['entity']; }
if ($filters['from']    !== '')   { $clauses[] = 'l.created_at >= ?'; $params[] = $filters['from'] . ' 00:00:00'; }
if ($filters['to']      !== '')   { $clauses[] = 'l.created_at <= ?'; $params[] = $filters['to'] . ' 23:59:59'; }

if ($filters['action'] !== '') {
    // Prefix match, so "arrival" catches arrival.void, arrival.manual, and the rest.
    $clauses[] = 'l.action LIKE ?';
    $params[]  = $filters['action'] . '%';
}

$where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

$total   = (int) Database::scalar("SELECT COUNT(*) FROM activity_logs l {$where}", $params);
/* Was 40 a page with the arithmetic done here. The SQL still does the paging
   — an activity log is the one table that genuinely grows without limit — but
   the window and the clamping now come from the one place that does it. */
$window  = Paginator::of($total, $_GET['page'] ?? null);
$perPage = $window['perPage'];
$page    = $window['page'];
$offset  = $window['offset'];
$pages   = $window['pages'];

$rows = Database::all(
    "SELECT l.*, a.full_name, a.username
       FROM activity_logs l
       LEFT JOIN admins a ON a.id = l.admin_id
       {$where}
      ORDER BY l.id DESC
      LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$pager    = ['rows' => $rows] + $window;

$admins   = Database::all('SELECT id, full_name FROM admins ORDER BY full_name');
$entities = Database::all('SELECT DISTINCT entity_type FROM activity_logs WHERE entity_type IS NOT NULL ORDER BY entity_type');

/** Actions worth spotting at a glance in a long list. */
$notable = ['arrival.void', 'qr.rotate', 'account.create', 'account.reset', 'account.role',
            'account.deactivate', 'destination.archive', 'settings.update', 'auth.locked', 'retention.run'];

$query = http_build_query(array_filter($_GET, static fn($v, $k) => $v !== '' && $k !== 'page', ARRAY_FILTER_USE_BOTH));

require __DIR__ . '/../_partials/head.php';
?>

<div class="panel panel--notice">
    <div class="panel__body">
        <h2><i class="fa-solid fa-lock"></i> Append-only by design</h2>
        <p class="mb-0">
            Nothing in TourSync updates or deletes a row in this table, and this screen offers no
            way to. A record that can be edited is not evidence — it is only a claim.
        </p>
    </div>
</div>

<form class="filter-bar" method="get">
    <div class="filter-bar__row">
        <select name="admin" class="form-select form-select-sm">
            <option value="">Anyone</option>
            <?php foreach ($admins as $a): ?>
                <option value="<?= (int) $a['id'] ?>" <?= $filters['admin_id'] === (int) $a['id'] ? 'selected' : '' ?>>
                    <?= e($a['full_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="entity" class="form-select form-select-sm">
            <option value="">Anything</option>
            <?php foreach ($entities as $en): ?>
                <option value="<?= e($en['entity_type']) ?>" <?= $filters['entity'] === $en['entity_type'] ? 'selected' : '' ?>>
                    <?= e(ucfirst($en['entity_type'])) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="action" class="form-select form-select-sm">
            <option value="">Any action</option>
            <?php foreach (['auth' => 'Sign in / out', 'arrival' => 'Arrivals', 'destination' => 'Destinations',
                            'qr' => 'QR codes', 'announcement' => 'Announcements', 'feedback' => 'Feedback',
                            'manager' => 'Managers', 'account' => 'Accounts', 'settings' => 'Settings',
                            'report' => 'Reports', 'retention' => 'Data retention'] as $prefix => $label): ?>
                <option value="<?= e($prefix) ?>" <?= $filters['action'] === $prefix ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>

        <label class="filter-date">From <input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
        <label class="filter-date">To <input type="date" name="to" value="<?= e($filters['to']) ?>"></label>

        <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
        <a href="index.php" class="btn btn-sm btn-link">Clear</a>
    </div>
</form>

<p class="result-count"><?= n($total) ?> entr<?= $total === 1 ? 'y' : 'ies' ?></p>

<?php if ($rows === []): ?>
    <div class="panel"><div class="panel__body">
        <div class="empty">
            <i class="fa-solid fa-clipboard-list"></i>
            <p><strong>No entries match those filters.</strong></p>
        </div>
    </div></div>
<?php else: ?>
    <div class="panel">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Detail</th><th>Address</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $l): ?>
                    <tr class="<?= in_array($l['action'], $notable, true) ? 'log-notable' : '' ?>">
                        <td class="small text-nowrap"><?= e(format_date($l['created_at'], 'M j, Y g:i:s A')) ?></td>
                        <td class="small">
                            <?= e((string) ($l['full_name'] ?? 'System')) ?>
                            <?php if ($l['username']): ?>
                                <span class="cell-sub mono"><?= e($l['username']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><code class="log-action"><?= e($l['action']) ?></code></td>
                        <td class="small"><?= e((string) ($l['description'] ?? '—')) ?></td>
                        <td class="small mono text-muted"><?= e(ActivityLog::readableIp($l['ip_address'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="pager">
            <?php
            $start = max(1, $page - 3);
            $stop  = min($pages, $page + 3);
            for ($p = $start; $p <= $stop; $p++): ?>
                <a href="?<?= e($query) ?>&page=<?= $p ?>" class="<?= $p === $page ? 'is-current' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../app/views/partials/pager.php'; ?>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
