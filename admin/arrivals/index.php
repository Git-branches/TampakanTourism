<?php
declare(strict_types=1);

/**
 * TourSync — tourist arrivals (admin).      Feature 2 / Problem 2
 *
 * Every row here was written by a visitor's own phone. Nobody collected,
 * consolidated, or delivered anything: this screen reads the same rows the
 * tourist created, which is what removes the manual submission step.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Repositories\ArrivalRepository;

Auth::require();

$pageTitle    = 'Tourist Arrivals';
$pageIcon     = 'fa-user-check';
$pageSubtitle = 'Recorded directly by visitors — no manual submission';

$filters = [
    'from'           => trim((string) ($_GET['from'] ?? '')),
    'to'             => trim((string) ($_GET['to'] ?? '')),
    'destination_id' => (int) ($_GET['destination'] ?? 0) ?: null,
    'tourist_type'   => (string) ($_GET['type'] ?? ''),
    'status'         => (string) ($_GET['status'] ?? ''),
    'source'         => (string) ($_GET['source'] ?? ''),
    'search'         => trim((string) ($_GET['q'] ?? '')),
];

$result = ArrivalRepository::paginate($filters, (int) ($_GET['page'] ?? 1), 25);

$destinations = Database::all("SELECT id, name FROM destinations ORDER BY name");
$flaggedCount = ArrivalRepository::countFlagged();

$queryString = http_build_query(array_filter($_GET, static fn($v, $k) => $v !== '' && $k !== 'page', ARRAY_FILTER_USE_BOTH));

require __DIR__ . '/../_partials/head.php';
?>

<?php if ($flaggedCount > 0 && ($filters['status'] ?? '') !== 'flagged'): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-flag"></i>
        <strong><?= n($flaggedCount) ?> record<?= $flaggedCount === 1 ? '' : 's' ?> flagged for review.</strong>
        These are excluded from every published figure until an officer approves them.
        <a href="?status=flagged" class="alert-link">Review them now</a>.
    </div>
<?php endif; ?>

<form class="filter-bar" method="get">
    <div class="filter-bar__row">
        <div class="search-field">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="q" value="<?= e($filters['search']) ?>" placeholder="Search name or origin">
        </div>

        <select name="destination" class="form-select form-select-sm">
            <option value="">All destinations</option>
            <?php foreach ($destinations as $d): ?>
                <option value="<?= (int) $d['id'] ?>" <?= $filters['destination_id'] === (int) $d['id'] ? 'selected' : '' ?>>
                    <?= e($d['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="type" class="form-select form-select-sm">
            <option value="">All tourist types</option>
            <?php foreach (ArrivalRepository::TYPES as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $filters['tourist_type'] === $value ? 'selected' : '' ?>>
                    <?= e(ucfirst(str_replace('_', ' ', $value))) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="status" class="form-select form-select-sm">
            <option value="">All statuses</option>
            <option value="valid"   <?= $filters['status'] === 'valid'   ? 'selected' : '' ?>>Valid (counted)</option>
            <option value="flagged" <?= $filters['status'] === 'flagged' ? 'selected' : '' ?>>Flagged</option>
            <option value="voided"  <?= $filters['status'] === 'voided'  ? 'selected' : '' ?>>Voided</option>
        </select>

        <select name="source" class="form-select form-select-sm">
            <option value="">Any source</option>
            <option value="qr"     <?= $filters['source'] === 'qr'     ? 'selected' : '' ?>>QR scan</option>
            <option value="manual" <?= $filters['source'] === 'manual' ? 'selected' : '' ?>>Manual entry</option>
        </select>
    </div>

    <div class="filter-bar__row">
        <label class="filter-date">From <input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
        <label class="filter-date">To <input type="date" name="to" value="<?= e($filters['to']) ?>"></label>

        <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
        <a href="index.php" class="btn btn-sm btn-link">Clear</a>

        <div class="filter-bar__spacer"></div>

        <a href="manual.php" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-pen"></i> Manual Entry
        </a>
        <a href="export.php?<?= e($queryString) ?>" class="btn btn-sm btn-brand">
            <i class="fa-solid fa-file-csv"></i> Export CSV
        </a>
    </div>
</form>

<div class="result-summary">
    <span><strong><?= n($result['total']) ?></strong> record<?= $result['total'] === 1 ? '' : 's' ?></span>
    <span><strong><?= n($result['visitors']) ?></strong> visitor<?= $result['visitors'] === 1 ? '' : 's' ?></span>
    <span class="text-muted">One record can be a whole party — the visitor count is what reports use.</span>
</div>

<?php if ($result['rows'] === []): ?>

    <div class="panel"><div class="panel__body">
        <div class="empty">
            <i class="fa-solid fa-inbox"></i>
            <?php if (array_filter($filters)): ?>
                <p><strong>No arrivals match those filters.</strong></p>
                <p><a href="index.php">Clear the filters</a> to see everything.</p>
            <?php else: ?>
                <p><strong>No arrivals recorded yet.</strong></p>
                <p>Records appear here the moment a visitor scans a destination QR code and
                   submits the digital logbook — no collection or encoding step in between.</p>
                <p class="mt-3">
                    <a href="<?= e(base_url('/admin/qrcodes/index.php')) ?>" class="btn btn-brand btn-sm">
                        <i class="fa-solid fa-qrcode"></i> Print the QR posters
                    </a>
                </p>
            <?php endif; ?>
        </div>
    </div></div>

<?php else: ?>

    <div class="panel">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 arrivals-table">
                <thead>
                    <tr>
                        <th>Arrived</th>
                        <th>Destination</th>
                        <th>Type</th>
                        <th>Origin</th>
                        <th class="text-end">Visitors</th>
                        <th>Source</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $a): ?>
                    <tr class="<?= $a['status'] === 'voided' ? 'is-voided' : '' ?>">
                        <td>
                            <span class="cell-strong"><?= e(format_date($a['arrived_at'], 'M j, Y')) ?></span>
                            <span class="cell-sub"><?= e(format_date($a['arrived_at'], 'g:i A')) ?></span>
                        </td>
                        <td><?= e($a['destination_name']) ?></td>
                        <td><span class="tag"><?= e(ucfirst(str_replace('_', ' ', $a['tourist_type']))) ?></span></td>
                        <td>
                            <?php
                            $origin = array_filter([$a['origin_city'], $a['origin_province']]);
                            echo e($origin ? implode(', ', $origin) : ($a['origin_country'] ?: '—'));
                            ?>
                        </td>
                        <td class="text-end num"><?= n($a['total_visitors']) ?></td>
                        <td>
                            <?php if ($a['source'] === 'qr'): ?>
                                <span class="pill pill--qr"><i class="fa-solid fa-qrcode"></i> Scan</span>
                            <?php else: ?>
                                <span class="pill pill--manual"><i class="fa-solid fa-pen"></i> Manual</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($a['status'] === 'valid'): ?>
                                <span class="pill pill--ok">Counted</span>
                            <?php elseif ($a['status'] === 'flagged'): ?>
                                <span class="pill pill--flag" title="<?= e((string) $a['flag_reason']) ?>">Flagged</span>
                            <?php else: ?>
                                <span class="pill pill--void">Voided</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a href="view.php?id=<?= (int) $a['id'] ?>" class="btn btn-sm btn-outline-secondary">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($result['pages'] > 1): ?>
        <nav class="pager" aria-label="Pages">
            <?php
            $start = max(1, $result['page'] - 3);
            $end   = min($result['pages'], $result['page'] + 3);
            for ($p = $start; $p <= $end; $p++): ?>
                <a href="?<?= e($queryString) ?>&page=<?= $p ?>"
                   class="<?= $p === $result['page'] ? 'is-current' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
