<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Notifier;
use App\Core\SmsGateway;
use App\Repositories\AnnouncementRepository;
use App\Repositories\ManagerRepository;

Auth::require();

$pageTitle    = 'Announcements';
$pageIcon     = 'fa-bullhorn';
$pageSubtitle = 'Advisories, events, closures, and submission schedules';

$filters = [
    'status' => (string) ($_GET['status'] ?? ''),
    'type'   => (string) ($_GET['type'] ?? ''),
    'search' => trim((string) ($_GET['q'] ?? '')),
];

$result     = AnnouncementRepository::paginate($filters, (int) ($_GET['page'] ?? 1), 15);
$counts     = AnnouncementRepository::statusCounts();
$recipients = count(ManagerRepository::smsRecipients());
$retryable  = Notifier::retryableCount();

require __DIR__ . '/../_partials/head.php';
?>

<?php if ($retryable > 0): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-rotate"></i>
        <strong><?= n($retryable) ?> notification<?= $retryable === 1 ? '' : 's' ?> failed and can still be retried.</strong>
        Open the announcement and use Send again — already-delivered recipients are skipped.
    </div>
<?php endif; ?>

<div class="panel panel--notice">
    <div class="panel__body">
        <h2><i class="fa-solid fa-<?= SmsGateway::isLive() ? 'tower-broadcast' : 'flask' ?>"></i>
            One message, two channels</h2>
        <p>
            An announcement is written once. Its <strong>audience</strong> decides whether it appears
            on the public website, goes to destination managers by SMS, or both — so a closure notice
            never has to be written twice and the two copies cannot drift apart.
        </p>
        <p class="mb-0">
            <strong><?= n($recipients) ?></strong> manager<?= $recipients === 1 ? '' : 's' ?> currently opted in to SMS.
            <?= e(SmsGateway::driver()->describe()) ?>
        </p>
    </div>
</div>

<div class="toolbar">
    <form class="toolbar__filters" method="get">
        <div class="search-field">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="q" value="<?= e($filters['search']) ?>" placeholder="Search title or message">
        </div>

        <select name="status" class="form-select form-select-sm">
            <option value="">All statuses</option>
            <option value="draft"     <?= $filters['status'] === 'draft'     ? 'selected' : '' ?>>Draft (<?= $counts['draft'] ?>)</option>
            <option value="published" <?= $filters['status'] === 'published' ? 'selected' : '' ?>>Published (<?= $counts['published'] ?>)</option>
            <option value="archived"  <?= $filters['status'] === 'archived'  ? 'selected' : '' ?>>Archived (<?= $counts['archived'] ?>)</option>
        </select>

        <select name="type" class="form-select form-select-sm">
            <option value="">All types</option>
            <?php foreach (AnnouncementRepository::TYPES as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $filters['type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
    </form>
    <a href="create.php" class="btn btn-brand btn-sm"><i class="fa-solid fa-plus"></i> New Announcement</a>
</div>

<?php if ($result['rows'] === []): ?>

    <div class="panel"><div class="panel__body">
        <div class="empty">
            <i class="fa-solid fa-bullhorn"></i>
            <p><strong>No announcements yet.</strong></p>
            <p>Publish advisories, closure notices, event listings, and report submission
               schedules from one place.</p>
            <p class="mt-3"><a href="create.php" class="btn btn-brand btn-sm"><i class="fa-solid fa-plus"></i> Write the first one</a></p>
        </div>
    </div></div>

<?php else: ?>

    <div class="announce-list">
        <?php foreach ($result['rows'] as $a):
            $style = AnnouncementRepository::TYPE_STYLE[$a['type']] ?? ['icon' => 'fa-bullhorn', 'tone' => 'blue']; ?>
            <article class="announce announce--<?= e($style['tone']) ?>">
                <div class="announce__icon"><i class="fa-solid <?= e($style['icon']) ?>"></i></div>

                <div class="announce__body">
                    <div class="announce__top">
                        <h3><a href="view.php?id=<?= (int) $a['id'] ?>"><?= e($a['title']) ?></a></h3>
                        <span class="pill pill--<?= $a['status'] === 'published' ? 'ok' : ($a['status'] === 'draft' ? 'flag' : 'void') ?>">
                            <?= e(ucfirst($a['status'])) ?>
                        </span>
                    </div>

                    <p class="announce__summary">
                        <?= e($a['summary'] ?: mb_substr(strip_tags($a['body']), 0, 130) . '…') ?>
                    </p>

                    <div class="announce__meta">
                        <span><i class="fa-solid <?= e($style['icon']) ?>"></i> <?= e(AnnouncementRepository::TYPES[$a['type']]) ?></span>
                        <span><i class="fa-solid fa-users"></i> <?= e(AnnouncementRepository::AUDIENCES[$a['audience']]) ?></span>
                        <?php if ($a['destination_name']): ?>
                            <span><i class="fa-solid fa-location-dot"></i> <?= e($a['destination_name']) ?></span>
                        <?php endif; ?>
                        <?php if ($a['event_date']): ?>
                            <span><i class="fa-regular fa-calendar"></i> <?= e(format_date($a['event_date'])) ?></span>
                        <?php endif; ?>
                        <?php if ((int) $a['notified'] > 0): ?>
                            <span class="announce__sent">
                                <i class="fa-solid fa-paper-plane"></i>
                                <?= n($a['delivered']) ?>/<?= n($a['notified']) ?> sent
                            </span>
                        <?php endif; ?>
                        <span class="announce__when"><?= e(format_date($a['created_at'], 'M j, Y')) ?></span>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if ($result['pages'] > 1): ?>
        <nav class="pager">
            <?php for ($p = 1; $p <= $result['pages']; $p++): ?>
                <a href="?status=<?= e($filters['status']) ?>&page=<?= $p ?>"
                   class="<?= $p === $result['page'] ? 'is-current' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
