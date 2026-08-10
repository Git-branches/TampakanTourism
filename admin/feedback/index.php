<?php
declare(strict_types=1);

/**
 * TourSync — feedback moderation.       Feature 5
 *
 * The policy is printed on the screen, not just enforced in code: hide abuse
 * and spam, never hide a review for being negative. A municipal page showing
 * only five-star reviews tells a visitor nothing and invites the accusation
 * that the office is filtering criticism.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Session;
use App\Repositories\FeedbackRepository;

Auth::require();

$pageTitle    = 'Visitor Feedback';
$pageIcon     = 'fa-comment-dots';
$pageSubtitle = 'Ratings and comments from visitors';

if (is_post()) {
    Csrf::verify();

    $id     = (int) ($_POST['id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');

    if (!in_array($status, ['published', 'hidden', 'pending'], true)) {
        Session::flash('danger', 'Unrecognised moderation action.');
        redirect(base_url('/admin/feedback/index.php'));
    }

    $review = FeedbackRepository::find($id);

    if ($review === null) {
        Session::flash('danger', 'That review no longer exists.');
        redirect(base_url('/admin/feedback/index.php'));
    }

    FeedbackRepository::moderate($id, $status, (int) Auth::id());

    ActivityLog::record(
        'feedback.' . $status,
        'feedback',
        $id,
        ucfirst($status) . ' a ' . $review['rating'] . '-star review of "' . $review['destination_name'] . '"'
    );

    Session::flash('success', match ($status) {
        'published' => 'Review published — it is now visible on the destination page.',
        'hidden'    => 'Review hidden. It stays on record but is no longer public.',
        default     => 'Review returned to the queue.',
    });

    redirect(base_url('/admin/feedback/index.php?' . http_build_query(array_filter([
        'status' => $_POST['return_status'] ?? '',
    ]))));
}

$filters = [
    'status'         => (string) ($_GET['status'] ?? 'pending'),
    'destination_id' => (int) ($_GET['destination'] ?? 0) ?: null,
    'rating'         => (int) ($_GET['rating'] ?? 0) ?: null,
    'low_only'       => !empty($_GET['low']),
];

if (!in_array($filters['status'], ['pending', 'published', 'hidden', ''], true)) {
    $filters['status'] = 'pending';
}

$result       = FeedbackRepository::paginate($filters, (int) ($_GET['page'] ?? 1), 20);
$counts       = FeedbackRepository::statusCounts();
$distribution = FeedbackRepository::distribution();
$destinations = Database::all("SELECT id, name FROM destinations ORDER BY name");

$totalPublished = array_sum($distribution);
$average = $totalPublished > 0
    ? round(array_sum(array_map(static fn($k, $v) => $k * $v, array_keys($distribution), $distribution)) / $totalPublished, 1)
    : 0;

require __DIR__ . '/../_partials/head.php';
?>

<div class="panel panel--notice">
    <div class="panel__body">
        <h2><i class="fa-solid fa-scale-balanced"></i> Moderation policy</h2>
        <p class="mb-0">
            <strong>Hide abuse and spam. Publish criticism.</strong>
            A negative review that is honest belongs on the page — it tells other visitors what
            to expect and tells this office what to fix. Hiding it because it is unflattering
            makes every remaining review worthless, and is the kind of thing a visitor notices.
        </p>
    </div>
</div>

<div class="stat-grid">
    <article class="stat-card stat-card--amber">
        <div class="stat-card__icon"><i class="fa-solid fa-clock"></i></div>
        <div class="stat-card__body">
            <p class="stat-card__value"><?= n($counts['pending']) ?></p>
            <p class="stat-card__label">Awaiting review</p>
        </div>
    </article>
    <article class="stat-card stat-card--green">
        <div class="stat-card__icon"><i class="fa-solid fa-eye"></i></div>
        <div class="stat-card__body">
            <p class="stat-card__value"><?= n($counts['published']) ?></p>
            <p class="stat-card__label">Published</p>
        </div>
    </article>
    <article class="stat-card stat-card--teal">
        <div class="stat-card__icon"><i class="fa-solid fa-star"></i></div>
        <div class="stat-card__body">
            <p class="stat-card__value"><?= $average > 0 ? e((string) $average) : '—' ?></p>
            <p class="stat-card__label">Average rating</p>
        </div>
    </article>
    <article class="stat-card stat-card--blue">
        <div class="stat-card__icon"><i class="fa-solid fa-eye-slash"></i></div>
        <div class="stat-card__body">
            <p class="stat-card__value"><?= n($counts['hidden']) ?></p>
            <p class="stat-card__label">Hidden</p>
        </div>
    </article>
</div>

<?php if ($totalPublished > 0): ?>
    <section class="panel">
        <header class="panel__head"><h2><i class="fa-solid fa-chart-simple"></i> Rating Distribution</h2></header>
        <div class="panel__body">
            <?php foreach ([5, 4, 3, 2, 1] as $star):
                $count = $distribution[$star];
                $pct   = $totalPublished > 0 ? round($count / $totalPublished * 100) : 0; ?>
                <div class="dist-row">
                    <span class="dist-row__label"><?= $star ?> <i class="fa-solid fa-star"></i></span>
                    <span class="dist-row__bar"><i style="width: <?= $pct ?>%"></i></span>
                    <span class="dist-row__count"><?= n($count) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<div class="tab-row">
    <?php
    $tabs = [
        ''          => 'All (' . n(array_sum($counts)) . ')',
        'pending'   => 'Pending (' . n($counts['pending']) . ')',
        'published' => 'Published (' . n($counts['published']) . ')',
        'hidden'    => 'Hidden (' . n($counts['hidden']) . ')',
    ];
    foreach ($tabs as $value => $label): ?>
        <a href="?status=<?= e($value) ?>" class="tab <?= $filters['status'] === $value ? 'is-active' : '' ?>">
            <?= e($label) ?>
        </a>
    <?php endforeach; ?>

    <a href="?status=&low=1" class="tab tab--warn <?= $filters['low_only'] ? 'is-active' : '' ?>">
        <i class="fa-solid fa-triangle-exclamation"></i> Needs attention (1–2 stars)
    </a>
</div>

<?php if ($result['rows'] === []): ?>

    <div class="panel"><div class="panel__body">
        <div class="empty">
            <i class="fa-regular fa-comment-dots"></i>
            <?php if ($filters['status'] === 'pending'): ?>
                <p><strong>Nothing waiting for review.</strong></p>
                <p>New ratings appear here as visitors submit them after logging a visit.</p>
            <?php else: ?>
                <p><strong>No reviews in this view.</strong></p>
            <?php endif; ?>
        </div>
    </div></div>

<?php else: ?>

    <div class="review-list">
        <?php foreach ($result['rows'] as $r): ?>
            <article class="review <?= $r['rating'] <= 2 ? 'review--low' : '' ?>">
                <header class="review__head">
                    <div>
                        <div class="review__stars" aria-label="<?= (int) $r['rating'] ?> out of 5">
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                                <i class="fa-<?= $s <= (int) $r['rating'] ? 'solid' : 'regular' ?> fa-star"></i>
                            <?php endfor; ?>
                        </div>
                        <p class="review__meta">
                            <strong><?= e($r['visitor_name'] ?: 'Anonymous visitor') ?></strong>
                            on <?= e($r['destination_name']) ?>
                        </p>
                    </div>

                    <div class="review__flags">
                        <?php if ($r['arrival_id'] !== null): ?>
                            <span class="pill pill--ok" title="Submitted immediately after a logged visit at this destination">
                                <i class="fa-solid fa-circle-check"></i> Verified visit
                            </span>
                        <?php else: ?>
                            <span class="pill pill--manual" title="Not linked to a recorded arrival">Unverified</span>
                        <?php endif; ?>

                        <?php if ($r['status'] === 'published'): ?>
                            <span class="pill pill--ok">Published</span>
                        <?php elseif ($r['status'] === 'hidden'): ?>
                            <span class="pill pill--void">Hidden</span>
                        <?php else: ?>
                            <span class="pill pill--flag">Pending</span>
                        <?php endif; ?>
                    </div>
                </header>

                <?php if ($r['comment']): ?>
                    <p class="review__body"><?= nl2br(e($r['comment'])) ?></p>
                <?php else: ?>
                    <p class="review__body review__body--empty">Rating only — no comment left.</p>
                <?php endif; ?>

                <footer class="review__foot">
                    <span class="review__when">
                        <i class="fa-regular fa-clock"></i> <?= e(format_date($r['created_at'], 'M j, Y g:i A')) ?>
                        <?php if ($r['visit_date']): ?>
                            · visited <?= e(format_date($r['visit_date'])) ?>
                        <?php endif; ?>
                        <?php
                        $origin = array_filter([$r['origin_city'], $r['origin_province'], $r['origin_country']]);
                        if ($origin): ?>
                            · from <?= e(implode(', ', $origin)) ?>
                        <?php endif; ?>
                        <?php if ($r['moderator_name']): ?>
                            · reviewed by <?= e($r['moderator_name']) ?>
                        <?php endif; ?>
                    </span>

                    <span class="review__actions">
                        <?php if ($r['status'] !== 'published'): ?>
                            <form method="post" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <input type="hidden" name="status" value="published">
                                <input type="hidden" name="return_status" value="<?= e($filters['status']) ?>">
                                <button class="btn btn-sm btn-brand"><i class="fa-solid fa-check"></i> Publish</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($r['status'] !== 'hidden'): ?>
                            <form method="post" class="d-inline"
                                  onsubmit="return confirm('Hide this review?\n\nOnly abuse and spam should be hidden — not criticism.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <input type="hidden" name="status" value="hidden">
                                <input type="hidden" name="return_status" value="<?= e($filters['status']) ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-eye-slash"></i> Hide</button>
                            </form>
                        <?php endif; ?>
                    </span>
                </footer>
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
