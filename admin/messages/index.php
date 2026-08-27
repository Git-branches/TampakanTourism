<?php
declare(strict_types=1);

/**
 * TourSync — messages from the public contact form.                  Feature 7
 *
 * Before this existed the form on the homepage threw every message away. What
 * arrives here now is the first enquiry the office has ever actually received
 * through their own website.
 *
 * There is no reply box. The office answers from their own email, because the
 * visitor gave an email address and expects a reply in their inbox, not a
 * notification from a system they have never heard of. This page records that
 * it was answered so the next person opening it knows.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Paginator;
use App\Core\Session;
use App\Repositories\ContactRepository as Messages;

Auth::require();

if (is_post()) {
    Csrf::verify();

    $id     = (int) ($_POST['id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    $note   = (string) ($_POST['office_note'] ?? '');

    if (!Messages::setStatus($id, $status, (int) Auth::id(), $note)) {
        Session::flash('danger', 'That is not a status this system uses.');
    } else {
        ActivityLog::record('contact.' . $status, 'contact_message', $id, 'Marked ' . Messages::STATUSES[$status]);
        Session::flash('success', 'Marked ' . strtolower(Messages::STATUSES[$status]) . '.');
    }

    redirect(base_url('/admin/messages/index.php#msg' . $id));
}

$status = (string) ($_GET['status'] ?? '');

if ($status !== '' && !isset(Messages::STATUSES[$status])) {
    $status = '';
}

$search = trim((string) ($_GET['q'] ?? ''));

$pager = Paginator::slice(
    Messages::inbox(['status' => $status, 'search' => $search], 500),
    $_GET['page'] ?? null
);

$messages = $pager['rows'];
$counts   = Messages::counts();

$pageTitle    = 'Messages';
$pageIcon     = 'fa-envelope';
$pageSubtitle = 'Enquiries sent through the public website';

require __DIR__ . '/../_partials/head.php';
?>

<div class="stat-grid">
    <?php
    $cards = [
        ['icon' => 'fa-envelope',       'tone' => 'amber', 'value' => $counts['new'],      'label' => 'New',      'q' => 'status=new'],
        ['icon' => 'fa-envelope-open',  'tone' => 'blue',  'value' => $counts['read'],     'label' => 'Read',     'q' => 'status=read'],
        ['icon' => 'fa-reply',          'tone' => 'green', 'value' => $counts['answered'], 'label' => 'Answered', 'q' => 'status=answered'],
        ['icon' => 'fa-ban',            'tone' => 'teal',  'value' => $counts['spam'],     'label' => 'Spam',     'q' => 'status=spam'],
    ];

    foreach ($cards as $card): ?>
        <a class="stat-card stat-card--<?= e($card['tone']) ?>" href="index.php?<?= e($card['q']) ?>">
            <div class="stat-card__icon"><i class="fa-solid <?= e($card['icon']) ?>"></i></div>
            <div class="stat-card__body">
                <p class="stat-card__value"><?= n((int) $card['value']) ?></p>
                <p class="stat-card__label"><?= e($card['label']) ?></p>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<?php
/* Sender, address and subject. Not the body — it holds whole paragraphs, so a
   LIKE across it matches on any ordinary word and the result is every message. */
?>
<form class="filter-bar" method="get">
    <div class="filter-bar__row">
        <div class="search-field">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Sender, email or subject">
        </div>

        <select name="status" class="form-select form-select-sm">
            <option value="">All statuses</option>
            <?php foreach (Messages::STATUSES as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>

        <div class="filter-bar__actions">
            <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
            <?php if ($search !== '' || $status !== ''): ?>
                <a href="index.php" class="btn btn-sm btn-link">Clear</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-inbox"></i> Messages<?= $status !== '' ? ' — ' . e(Messages::STATUSES[$status]) : '' ?></h2>
        <p class="panel__count"><?= n((int) $pager['total']) ?> found</p>
    </header>

    <?php if ($messages === []): ?>
        <?php
        /* "No messages yet" was shown for any empty result, so a search that
           matched nothing told an officer the inbox was empty. It is not the
           same sentence: one means nobody has written, the other means the
           thing you typed is not here. */
        $isFiltered = $status !== '' || $search !== '';
        ?>
        <div class="panel__body">
            <div class="empty-public">
                <i class="fa-regular fa-envelope"></i>
                <h3><?= $isFiltered ? 'Nothing matches that filter' : 'No messages yet' ?></h3>
                <p>
                    <?php if ($isFiltered): ?>
                        <?= n((int) $counts['new'] + (int) $counts['read'] + (int) $counts['answered'] + (int) $counts['spam']) ?>
                        message(s) are in the inbox &mdash; none of them match.
                        <a href="index.php">Clear the filter</a> to see them all.
                    <?php else: ?>
                        Enquiries sent through the contact form on the public website arrive here.
                    <?php endif; ?>
                </p>
            </div>
        </div>
    <?php endif; ?>
</section>

<?php foreach ($messages as $m): ?>
    <section class="panel" id="msg<?= (int) $m['id'] ?>">
        <header class="panel__head">
            <h2>
                <i class="fa-regular fa-envelope"></i> <?= e((string) $m['subject']) ?>
                <span class="text-muted small">&middot; <?= e((string) $m['name']) ?></span>
            </h2>
            <span class="pill pill--<?= match ($m['status']) {
                'new'      => 'flag',
                'answered' => 'ok',
                'spam'     => 'void',
                default    => 'qr',
            } ?>"><?= e(Messages::STATUSES[$m['status']]) ?></span>
        </header>

        <div class="panel__body">
            <p class="mb-2">
                <?php /* mailto with the subject prefilled: the officer replies
                         from their own client, which is where the visitor is
                         expecting the answer to appear. */ ?>
                <a href="mailto:<?= e((string) $m['email']) ?>?subject=<?= e(rawurlencode('Re: ' . $m['subject'])) ?>">
                    <?= e((string) $m['email']) ?>
                </a>
                <?php if ($m['phone']): ?>
                    &middot; <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $m['phone']) ?? '') ?>"><?= e((string) $m['phone']) ?></a>
                <?php endif; ?>
                <span class="text-muted small">
                    &middot; <?= e(format_date((string) $m['created_at'], 'F j, Y \a\t g:i A')) ?>
                </span>
            </p>

            <div class="alert alert-light py-2"><?= nl2br(e((string) $m['message'])) ?></div>

            <?php if ($m['office_note']): ?>
                <p class="text-muted small">
                    <strong>Note:</strong> <?= e((string) $m['office_note']) ?>
                    <?php if ($m['handled_by_name']): ?>&mdash; <?= e((string) $m['handled_by_name']) ?><?php endif; ?>
                </p>
            <?php endif; ?>

            <form method="post" class="mt-3">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">

                <input type="text" class="form-control form-control-sm mb-2" maxlength="600"
                       name="office_note" placeholder="Optional note — what you told them, or why this is spam">

                <div class="d-flex gap-2 flex-wrap">
                    <?php if ($m['status'] === 'new'): ?>
                        <button type="submit" name="status" value="read" class="btn btn-sm btn-outline-secondary">
                            <i class="fa-solid fa-envelope-open"></i> Mark read
                        </button>
                    <?php endif; ?>
                    <button type="submit" name="status" value="answered" class="btn btn-success btn-sm">
                        <i class="fa-solid fa-reply"></i> Mark answered
                    </button>
                    <button type="submit" name="status" value="spam" class="btn btn-outline-danger btn-sm">
                        <i class="fa-solid fa-ban"></i> Spam
                    </button>
                </div>
            </form>
        </div>
    </section>
<?php endforeach; ?>

<?php require __DIR__ . '/../../app/views/partials/pager.php'; ?>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
