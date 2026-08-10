<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
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

    if ($m !== null) {
        ManagerRepository::setActive($id, $active);
        ActivityLog::record(
            $active ? 'manager.activate' : 'manager.deactivate',
            'manager', $id,
            ($active ? 'Reactivated ' : 'Deactivated ') . $m['full_name']
        );
        Session::flash('success', $m['full_name'] . ($active ? ' reactivated.' : ' deactivated — they will no longer receive notices.'));
    }

    redirect(base_url('/admin/managers/index.php'));
}

$managers   = ManagerRepository::all(['search' => trim((string) ($_GET['q'] ?? ''))]);
$counts     = ManagerRepository::counts();
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
    <a href="create.php" class="btn btn-brand btn-sm"><i class="fa-solid fa-plus"></i> Add Manager</a>
</div>

<?php if ($managers === []): ?>
    <div class="panel"><div class="panel__body">
        <div class="empty">
            <i class="fa-solid fa-address-book"></i>
            <p><strong>No destination managers registered.</strong></p>
            <p>Add the people responsible at each destination so the Office can reach them
               with advisories, closures, and submission schedules.</p>
            <p class="mt-3"><a href="create.php" class="btn btn-brand btn-sm"><i class="fa-solid fa-plus"></i> Add the first manager</a></p>
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
                            <a href="edit.php?id=<?= (int) $m['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <form method="post" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                <input type="hidden" name="activate" value="<?= (int) $m['is_active'] === 1 ? '0' : '1' ?>">
                                <button class="btn btn-sm btn-outline-<?= (int) $m['is_active'] === 1 ? 'danger' : 'success' ?>">
                                    <?= (int) $m['is_active'] === 1 ? 'Deactivate' : 'Reactivate' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
