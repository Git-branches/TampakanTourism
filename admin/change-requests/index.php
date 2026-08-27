<?php
declare(strict_types=1);

/**
 * TourSync — reviewing what the managers want changed.               Feature 6
 *
 * Each proposal is shown as OLD TEXT BESIDE NEW TEXT, per field. An officer
 * approving a change to a public government page should be able to see exactly
 * what will differ afterwards, without opening the destination in another tab
 * and comparing by eye.
 *
 * Approval writes the change straight into the destination record, so the
 * public page and the QR page are correct the moment the officer clicks. There
 * is no second "publish" step, because a two-step approval is a step somebody
 * forgets and a manager who was told yes still watches the wrong hours sit on
 * the website.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Paginator;
use App\Core\Session;
use App\Repositories\ChangeRequestRepository as Changes;

Auth::require();

if (is_post()) {
    Csrf::verify();

    $id      = (int) ($_POST['id'] ?? 0);
    $action  = (string) ($_POST['action'] ?? '');
    $note    = (string) ($_POST['review_note'] ?? '');
    $adminId = (int) Auth::id();
    $request = $id > 0 ? Changes::find($id) : null;

    if ($request === null) {
        Session::flash('danger', 'That request could not be found.');
        redirect(base_url('/admin/change-requests/index.php'));
    }

    if ($action === 'approve') {
        $refusal = Changes::approve($id, $adminId, $note);

        if ($refusal !== null) {
            Session::flash('danger', $refusal);
        } else {
            ActivityLog::record(
                'destination.change_approved',
                'destination',
                (int) $request['destination_id'],
                count($request['changes']) . ' field(s) updated on ' . $request['destination_name']
            );
            Session::flash('success', 'Approved. The public page and the QR page now show the new details.');
        }
    }

    if ($action === 'reject') {
        $refusal = Changes::reject($id, $adminId, $note);

        if ($refusal !== null) {
            Session::flash('danger', $refusal);
        } else {
            ActivityLog::record(
                'destination.change_rejected',
                'destination',
                (int) $request['destination_id'],
                'Change request declined for ' . $request['destination_name']
            );
            Session::flash('success', 'Declined, with your reason sent to the manager.');
        }
    }

    redirect(base_url('/admin/change-requests/index.php#req' . $id));
}

$status = (string) ($_GET['status'] ?? '');

if ($status !== '' && !isset(Changes::STATUSES[$status])) {
    $status = '';
}

$pager    = Paginator::slice(Changes::all(['status' => $status], 500), $_GET['page'] ?? null);
$requests = $pager['rows'];
$pending  = Changes::pendingCount();

$pageTitle    = 'Destination Change Requests';
$pageIcon     = 'fa-pen-to-square';
$pageSubtitle = 'What the destination managers want corrected';

require __DIR__ . '/../_partials/head.php';
?>

<?php if ($pending > 0): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-hourglass-half"></i>
        <strong><?= n($pending) ?> request(s) waiting.</strong>
        Until these are decided, the public page shows details a manager has told you are wrong.
    </div>
<?php endif; ?>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-inbox"></i> Requests<?= $status !== '' ? ' — ' . e(Changes::STATUSES[$status]) : '' ?></h2>
        <div class="d-flex gap-2 flex-wrap">
            <?php foreach (Changes::STATUSES as $key => $label): ?>
                <a href="index.php?status=<?= e($key) ?>"
                   class="btn btn-sm <?= $status === $key ? 'btn-brand' : 'btn-outline-secondary' ?>">
                    <?= e($label) ?>
                </a>
            <?php endforeach; ?>
            <?php if ($status !== ''): ?>
                <a href="index.php" class="btn btn-sm btn-outline-secondary">Show all</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($requests === []): ?>
        <div class="panel__body">
            <div class="empty-public">
                <i class="fa-regular fa-pen-to-square"></i>
                <h3><?= $status !== '' ? 'Nothing matches that filter' : 'No change requests' ?></h3>
                <p>
                    When a destination manager corrects their opening hours, fees or facilities,
                    the proposed change appears here for your approval before it goes public.
                </p>
            </div>
        </div>
    <?php endif; ?>
</section>

<?php foreach ($requests as $r): ?>
    <?php $isPending = $r['status'] === 'pending'; ?>
    <section class="panel" id="req<?= (int) $r['id'] ?>">
        <header class="panel__head">
            <h2>
                <i class="fa-solid fa-mountain-sun"></i> <?= e((string) $r['destination_name']) ?>
                <span class="text-muted small">
                    &middot; <?= e((string) ($r['manager_name'] ?: 'a former manager')) ?>
                </span>
            </h2>
            <span class="pill pill--<?= match ($r['status']) {
                'pending'  => 'flag',
                'approved' => 'ok',
                default    => 'void',
            } ?>"><?= e(Changes::STATUSES[$r['status']]) ?></span>
        </header>

        <div class="panel__body">
            <p class="text-muted small">
                Sent <?= e(format_date((string) $r['created_at'], 'F j, Y \a\t g:i A')) ?>
                <?php if ($r['reviewed_at']): ?>
                    &middot; decided <?= e(format_date((string) $r['reviewed_at'], 'M j, Y')) ?>
                    <?php if ($r['reviewer_name']): ?>by <?= e((string) $r['reviewer_name']) ?><?php endif; ?>
                <?php endif; ?>
            </p>

            <?php if ($r['reason']): ?>
                <div class="alert alert-light py-2">
                    <strong>The manager says:</strong> <?= nl2br(e((string) $r['reason'])) ?>
                </div>
            <?php endif; ?>

            <?php if ($r['changes'] === []): ?>
                <p class="text-muted">This request contains no changes.</p>
            <?php else: ?>
                <?php
                /* The live values, read now rather than stored with the
                   proposal. An officer deciding today needs to see what the
                   page says today — the field may have been edited by hand
                   since the manager wrote this, and approving would overwrite
                   that edit. */
                $live = App\Repositories\DestinationRepository::find((int) $r['destination_id']);
                ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width:18%">Field</th>
                                <th style="width:41%">Currently published</th>
                                <th style="width:41%">Proposed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($r['changes'] as $field => $proposed): ?>
                                <?php
                                $label   = Changes::FIELDS[$field]['label'] ?? $field;
                                $current = trim((string) ($live[$field] ?? ''));
                                ?>
                                <tr>
                                    <td data-label="Field"><strong><?= e((string) $label) ?></strong></td>
                                    <td data-label="Currently published">
                                        <?= $current === ''
                                            ? '<span class="text-muted fst-italic">empty</span>'
                                            : nl2br(e($current)) ?>
                                    </td>
                                    <td data-label="Proposed" class="table-active">
                                        <?= trim((string) $proposed) === ''
                                            ? '<span class="text-muted fst-italic">cleared</span>'
                                            : nl2br(e((string) $proposed)) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($r['review_note']): ?>
                <div class="alert alert-<?= $r['status'] === 'approved' ? 'success' : 'info' ?> py-2 mt-3">
                    <strong>Your note:</strong> <?= e((string) $r['review_note']) ?>
                </div>
            <?php endif; ?>

            <?php if ($isPending): ?>
                <form method="post" class="mt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">

                    <label class="form-label small" for="note<?= (int) $r['id'] ?>">
                        Note to the manager <span class="text-muted">(required to decline)</span>
                    </label>
                    <input type="text" class="form-control form-control-sm mb-3" maxlength="600"
                           id="note<?= (int) $r['id'] ?>" name="review_note"
                           placeholder="Approved, thanks — or why this cannot go on the public page as written.">

                    <div class="d-flex gap-2 flex-wrap">
                        <button type="submit" name="action" value="approve" class="btn btn-success btn-sm">
                            <i class="fa-solid fa-check"></i> Approve &amp; publish
                        </button>
                        <button type="submit" name="action" value="reject" class="btn btn-outline-danger btn-sm">
                            <i class="fa-solid fa-xmark"></i> Decline
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </section>
<?php endforeach; ?>

<?php require __DIR__ . '/../../app/views/partials/pager.php'; ?>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
