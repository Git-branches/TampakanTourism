<?php
declare(strict_types=1);

/**
 * TourSync — destination listing (admin).
 *
 * Feature 5 / Problem 4: the one place destination information is maintained.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Repositories\CategoryRepository;
use App\Repositories\DestinationRepository;

Auth::require();

$pageTitle    = 'Destinations';
$pageIcon     = 'fa-mountain-sun';
$pageSubtitle = 'Every tourist destination in the municipality';

$filters = [
    'search'      => trim((string) ($_GET['q'] ?? '')),
    'category_id' => (int) ($_GET['category'] ?? 0) ?: null,
    'status'      => in_array($_GET['status'] ?? '', ['active', 'archived', 'all'], true)
                        ? $_GET['status'] : 'active',
    'sort'        => (string) ($_GET['sort'] ?? 'created_at'),
    'dir'         => (string) ($_GET['dir'] ?? 'desc'),
];

$result     = DestinationRepository::paginate($filters, (int) ($_GET['page'] ?? 1), 12);
$categories = CategoryRepository::all();

$counts = [
    'active'   => (int) App\Core\Database::scalar("SELECT COUNT(*) FROM destinations WHERE status = 'active'"),
    'archived' => (int) App\Core\Database::scalar("SELECT COUNT(*) FROM destinations WHERE status = 'archived'"),
];

/** Rebuilds the current query string with one value replaced. */
function filter_url(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($params);
}

require __DIR__ . '/../_partials/head.php';
?>

<div class="toolbar">
    <form class="toolbar__filters" method="get">
        <div class="search-field">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="q" value="<?= e($filters['search']) ?>"
                   placeholder="Search by name, barangay, or description">
        </div>

        <select name="category" class="form-select form-select-sm">
            <option value="">All categories</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= $filters['category_id'] === (int) $c['id'] ? 'selected' : '' ?>>
                    <?= e($c['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="status" class="form-select form-select-sm">
            <option value="active"   <?= $filters['status'] === 'active'   ? 'selected' : '' ?>>Active (<?= $counts['active'] ?>)</option>
            <option value="archived" <?= $filters['status'] === 'archived' ? 'selected' : '' ?>>Archived (<?= $counts['archived'] ?>)</option>
            <option value="all"      <?= $filters['status'] === 'all'      ? 'selected' : '' ?>>All</option>
        </select>

        <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
        <?php if ($filters['search'] || $filters['category_id'] || $filters['status'] !== 'active'): ?>
            <a href="index.php" class="btn btn-sm btn-link">Clear</a>
        <?php endif; ?>
    </form>

    <a href="create.php" class="btn btn-brand btn-sm">
        <i class="fa-solid fa-plus"></i> Add Destination
    </a>
</div>

<?php if ($result['rows'] === []): ?>

    <div class="panel">
        <div class="panel__body">
            <div class="empty">
                <i class="fa-solid fa-mountain-sun"></i>
                <?php if ($filters['search'] || $filters['category_id']): ?>
                    <p><strong>No destinations match those filters.</strong></p>
                    <p><a href="index.php">Clear the filters</a> to see everything.</p>
                <?php else: ?>
                    <p><strong>No destinations registered yet.</strong></p>
                    <p>Add the first one and it appears on the public website immediately —
                       no code change, no separate upload.</p>
                    <p class="mt-3"><a href="create.php" class="btn btn-brand btn-sm">
                        <i class="fa-solid fa-plus"></i> Add the first destination</a></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php else: ?>

    <p class="result-count">
        <?= n($result['total']) ?> destination<?= $result['total'] === 1 ? '' : 's' ?>
        <?= $filters['status'] !== 'all' ? '(' . e($filters['status']) . ')' : '' ?>
    </p>

    <div class="dest-grid">
        <?php foreach ($result['rows'] as $d): ?>
            <article class="dest-tile <?= $d['status'] === 'archived' ? 'is-archived' : '' ?>">

                <div class="dest-tile__media">
                    <?php if (!empty($d['cover_photo'])): ?>
                        <img src="<?= e(base_url($d['cover_photo'])) ?>" alt="<?= e($d['name']) ?>" loading="lazy">
                    <?php else: ?>
                        <div class="dest-tile__placeholder"><i class="fa-solid fa-image"></i><span>No photo</span></div>
                    <?php endif; ?>

                    <?php if ($d['status'] === 'archived'): ?>
                        <span class="dest-tile__flag dest-tile__flag--archived">Archived</span>
                    <?php elseif ((int) $d['is_featured'] === 1): ?>
                        <span class="dest-tile__flag dest-tile__flag--featured"><i class="fa-solid fa-star"></i> Featured</span>
                    <?php endif; ?>
                </div>

                <div class="dest-tile__body">
                    <?php if (!empty($d['category_name'])): ?>
                        <span class="tag"><?= e($d['category_name']) ?></span>
                    <?php endif; ?>

                    <h3><?= e($d['name']) ?></h3>

                    <p class="dest-tile__meta">
                        <i class="fa-solid fa-location-dot"></i>
                        <?= e($d['barangay'] ?: 'Barangay not set') ?>
                    </p>

                    <p class="dest-tile__excerpt">
                        <?= e($d['short_description'] ?: 'No short description yet.') ?>
                    </p>

                    <div class="dest-tile__stats">
                        <span title="Recorded visitors"><i class="fa-solid fa-users"></i> <?= n($d['visitors']) ?></span>
                        <span title="Photos"><i class="fa-solid fa-image"></i> <?= n($d['photo_count']) ?></span>
                        <span title="Map coordinates">
                            <i class="fa-solid fa-map-pin"></i>
                            <?= ($d['latitude'] !== null && $d['longitude'] !== null) ? 'Set' : 'Not set' ?>
                        </span>
                    </div>
                </div>

                <div class="dest-tile__actions">
                    <a href="edit.php?id=<?= (int) $d['id'] ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="fa-solid fa-pen"></i> Edit
                    </a>
                    <a href="photos.php?id=<?= (int) $d['id'] ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="fa-solid fa-images"></i> Photos
                    </a>
                    <?php if ($d['status'] === 'active'): ?>
                        <a href="<?= e(base_url('/destination.php?slug=' . $d['slug'])) ?>"
                           target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="View public page">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if ($result['pages'] > 1): ?>
        <nav class="pager" aria-label="Pages">
            <?php for ($p = 1; $p <= $result['pages']; $p++): ?>
                <a href="<?= e(filter_url(['page' => $p])) ?>"
                   class="<?= $p === $result['page'] ? 'is-current' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
