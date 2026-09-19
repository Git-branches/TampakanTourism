<?php
declare(strict_types=1);

/**
 * TourSync — tour guide rating moderation.
 *                                     Presentation feedback, 15 September 2026
 *
 * The office accredits guides, issues each an ID, and until now had no way to
 * hear how a visitor found one. The Tour Guide category of the public Contact Us
 * modal is that way, and these are the ratings it collects.
 *
 * THE POLICY IS THE SAME ONE PRINTED ON THE SCREEN NEXT DOOR:
 *     Hide abuse and spam. Never hide a rating for being unflattering.
 * With one thing destination reviews do not have to weigh. These name a private
 * individual holding a municipal accreditation, and a published rating of a
 * named person is a different act from a published rating of a waterfall. So
 * nothing arrives published — GuideReviewRepository hard-codes 'pending' on
 * create — and an officer publishes it or it stays unseen.
 *
 * WHERE A PUBLISHED RATING GOES: nowhere yet, deliberately. No public page
 * reads these. Whether a guide's rating appears beside their name on the public
 * roster is the office's decision to make, not a developer's, and publishing
 * here records the officer's judgement either way. publishedFor() and
 * summaryFor() exist for the day that decision is taken.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Paginator;
use App\Core\Session;
use App\Repositories\FeedbackRepository;
use App\Repositories\GuideReviewRepository as Reviews;

Auth::require();

if (is_post()) {
    Csrf::verify();

    $id     = (int) ($_POST['id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    $back   = base_url('/admin/feedback/guides.php?' . http_build_query(array_filter([
        'status' => $_POST['return_status'] ?? '',
        'guide'  => $_POST['return_guide'] ?? '',
    ])));

    $review = Reviews::find($id);

    if ($review === null) {
        Session::flash('danger', 'That rating no longer exists.');
        redirect($back);
    }

    if (!Reviews::moderate($id, $status, (int) Auth::id())) {
        Session::flash('danger', 'That is not a status this system uses.');
        redirect($back);
    }

    /* The guide's name in the log, because that is what an officer looking back
       through it needs to find. The visitor's name is not — it is on the screen
       this refers to, and an activity log is read by everyone with an account. */
    ActivityLog::record(
        'guide_review.' . $status,
        'guide_review',
        $id,
        ucfirst($status) . ' a ' . $review['rating'] . '-star rating of ' . $review['guide_name']
    );

    Session::flash('success', match ($status) {
        'published' => 'Rating published. It is on record as approved by the Office.',
        'hidden'    => 'Rating hidden. It stays on record but is not published.',
        default     => 'Rating returned to the queue.',
    });

    redirect($back);
}

$status = (string) ($_GET['status'] ?? 'pending');

if (!isset(Reviews::STATUSES[$status]) && $status !== '') {
    $status = 'pending';
}

$guideId = (int) ($_GET['guide'] ?? 0);
$search  = trim((string) ($_GET['q'] ?? ''));

$pager = Paginator::slice(
    Reviews::inbox(['status' => $status, 'guide_id' => $guideId, 'search' => $search], 500),
    $_GET['page'] ?? null
);

$rows   = $pager['rows'];
$counts = Reviews::statusCounts();

/* Every guide who has ever been rated, for the filter — not the whole roster.
   A dropdown offering nineteen names of whom two have ratings is eighteen
   selections that return nothing, and an officer stops trusting the filter. */
$ratedGuides = Database::all(
    "SELECT DISTINCT g.id, g.full_name
       FROM guide_reviews r
       JOIN tour_guides g ON g.id = r.guide_id
      ORDER BY g.full_name"
);

$isFiltered = $status !== 'pending' || $guideId > 0 || $search !== '';

$activeTab = 'guides';
$tabCounts = [
    'destinations' => FeedbackRepository::countPending(),
    'guides'       => Reviews::countPending(),
];

$pageTitle    = 'Tour Guide Ratings';
$pageIcon     = 'fa-person-hiking';
$pageSubtitle = 'Ratings left for accredited guides through the public website';

require __DIR__ . '/../_partials/head.php';
require __DIR__ . '/_tabs.php';
?>

<div class="panel panel--notice">
    <div class="panel__body">
        <h2><i class="fa-solid fa-scale-balanced"></i> Moderation policy</h2>
        <p class="mb-0">
            <strong>Hide abuse and spam. Publish honest criticism.</strong>
            These ratings are about named people the Office has accredited, so nothing is
            published until somebody here approves it. That is a safeguard against abuse — it
            is not a reason to hide a low rating that is fairly given. A guide the Office
            stands behind is better served by knowing what visitors actually said.
        </p>
    </div>
</div>

<div class="stat-grid">
    <?php foreach ([
        ['fa-clock',      'amber', 'pending',   'Awaiting review'],
        ['fa-circle-check','green', 'published', 'Published'],
        ['fa-eye-slash',  'blue',  'hidden',    'Hidden'],
    ] as [$icon, $tone, $key, $label]): ?>
        <a class="stat-card stat-card--<?= e($tone) ?>" href="guides.php?status=<?= e($key) ?>">
            <div class="stat-card__icon"><i class="fa-solid <?= e($icon) ?>"></i></div>
            <div class="stat-card__body">
                <p class="stat-card__value"><?= n((int) $counts[$key]) ?></p>
                <p class="stat-card__label"><?= e($label) ?></p>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<form class="filter-bar" method="get">
    <div class="filter-bar__row">
        <div class="search-field">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Guide or visitor name">
        </div>

        <select name="status" class="form-select form-select-sm" aria-label="Status">
            <option value="">All statuses</option>
            <?php foreach (Reviews::STATUSES as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>

        <?php if ($ratedGuides !== []): ?>
            <select name="guide" class="form-select form-select-sm" aria-label="Guide">
                <option value="">All guides</option>
                <?php foreach ($ratedGuides as $g): ?>
                    <option value="<?= (int) $g['id'] ?>" <?= $guideId === (int) $g['id'] ? 'selected' : '' ?>>
                        <?= e((string) $g['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <div class="filter-bar__actions">
            <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
            <?php if ($isFiltered): ?>
                <a href="guides.php" class="btn btn-sm btn-link">Clear</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<section class="panel">
    <header class="panel__head">
        <h2>
            <i class="fa-solid fa-star"></i>
            Ratings<?= $status !== '' ? ' — ' . e(Reviews::STATUSES[$status]) : '' ?>
        </h2>
        <p class="panel__count"><?= n((int) $pager['total']) ?> found</p>
    </header>

    <?php if ($rows === []): ?>

        <?php /* Two sentences, because they mean different things. One says
                 nobody has rated a guide; the other says nothing matches what
                 was typed. Showing the first for an empty search tells an
                 officer the queue is empty when it is not. */ ?>
        <div class="panel__body">
            <div class="empty">
                <i class="fa-regular fa-star"></i>
                <h3><?= $isFiltered ? 'Nothing matches that filter' : 'No guide ratings yet' ?></h3>
                <p>
                    <?php if ($isFiltered): ?>
                        <?= n(array_sum($counts)) ?> rating(s) have been received &mdash;
                        none of them match.
                        <a href="guides.php">Clear the filter</a> to see them all.
                    <?php else: ?>
                        Ratings left through <strong>Contact Us &rsaquo; Tour Guide</strong> on the public
                        website arrive here. Nothing is published until an officer approves it.
                    <?php endif; ?>
                </p>
            </div>
        </div>

    <?php else: ?>

        <ul class="record-list">
            <?php foreach ($rows as $r): ?>
                <?php
                $id     = (int) $r['id'];
                $rating = (int) $r['rating'];
                $who    = trim((string) ($r['visitor_name'] ?? ''));
                ?>
                <li class="record-list__row<?= $r['status'] === 'pending' ? ' is-unread' : '' ?>" id="gr<?= $id ?>">
                    <div class="grev">

                        <div class="grev__head">
                            <div>
                                <strong class="grev__guide"><?= e((string) $r['guide_name']) ?></strong>
                                <span class="cell-sub"><?= e((string) $r['guide_code']) ?></span>
                            </div>

                            <?php /* The stars, with the number beside them in text.
                                     Five icons alone are a picture; an officer
                                     skimming a column of them, or reading with a
                                     screen reader, needs the figure. */ ?>
                            <span class="grev__stars" aria-hidden="true">
                                <?php for ($s = 1; $s <= 5; $s++): ?>
                                    <i class="fa-<?= $s <= $rating ? 'solid' : 'regular' ?> fa-star"></i>
                                <?php endfor; ?>
                            </span>
                            <span class="grev__score"><?= $rating ?>/5</span>

                            <span class="pill pill--<?= $r['status'] === 'published' ? 'ok'
                                : ($r['status'] === 'hidden' ? 'void' : 'flag') ?>">
                                <?= e(Reviews::STATUSES[$r['status']]) ?>
                            </span>
                        </div>

                        <?php if (trim((string) ($r['comment'] ?? '')) !== ''): ?>
                            <div class="grev__text"><?= nl2br(e((string) $r['comment'])) ?></div>
                        <?php else: ?>
                            <p class="grev__none">No comment was left — a rating only.</p>
                        <?php endif; ?>

                        <p class="grev__by">
                            <?= $who !== '' ? e($who) : 'Anonymous' ?>
                            <?php if (trim((string) ($r['visitor_email'] ?? '')) !== ''): ?>
                                &middot;
                                <a href="mailto:<?= e((string) $r['visitor_email']) ?>"><?= e((string) $r['visitor_email']) ?></a>
                            <?php endif; ?>
                            &middot; <?= e(format_date((string) $r['created_at'], 'M j, Y · g:i A')) ?>
                            <?php if (trim((string) ($r['moderated_by_name'] ?? '')) !== ''): ?>
                                &middot; handled by <?= e((string) $r['moderated_by_name']) ?>
                            <?php endif; ?>
                        </p>

                        <?php /* Plain forms, no JavaScript. This queue is read a
                                 handful of times a month; a background post and a
                                 live-updating card would be machinery around three
                                 buttons. */ ?>
                        <div class="grev__acts">
                            <?php foreach ([
                                'published' => ['fa-circle-check', 'btn-brand',             'Publish'],
                                'hidden'    => ['fa-eye-slash',    'btn-outline-danger',    'Hide'],
                                'pending'   => ['fa-rotate-left',  'btn-outline-secondary', 'Back to queue'],
                            ] as $key => [$icon, $class, $label]): ?>
                                <?php if ($key === $r['status']) { continue; } ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <input type="hidden" name="return_status" value="<?= e($status) ?>">
                                    <input type="hidden" name="return_guide" value="<?= $guideId ?: '' ?>">
                                    <button type="submit" name="status" value="<?= e($key) ?>"
                                            class="btn btn-sm <?= e($class) ?>">
                                        <i class="fa-solid <?= e($icon) ?>" aria-hidden="true"></i> <?= e($label) ?>
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../app/views/partials/pager.php'; ?>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
