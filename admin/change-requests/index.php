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
use App\Repositories\ManagerNotificationRepository as Bell;

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

    /* THE DECISION HAS TO REACH THE PERSON WHO ASKED.
     *
     * This was the one office-to-manager path that told nobody. A report
     * approved, an inspection sent back and an alert answered all write to the
     * manager's bell from their own review screens; a change request wrote an
     * ActivityLog line and a flash to the officer's own screen, and stopped.
     * The reject branch went further and claimed otherwise — "Declined, with
     * your reason sent to the manager" — which was simply untrue.
     *
     * The notification is also the ROUTE. manager/update-info.php is linked
     * from nowhere in the manager's shell, deliberately, so this link is the
     * way back to the request and to what the office wrote on it. */
    $link = base_url('/manager/update-info.php');

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

            Bell::record(
                (int) $request['destination_id'],
                'change_approved',
                'Your destination update was approved',
                [
                    'body'        => count($request['changes']) . ' field(s) are now live on the public page'
                                   . ($note !== '' ? ' — ' . $note : '.'),
                    'link'        => $link,
                    'entity_type' => 'change_request',
                    'entity_id'   => $id,
                ]
            );

            Session::flash('success', 'Approved. The public page and the QR page now show the new details, '
                . 'and the manager has been told.');
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

            Bell::record(
                (int) $request['destination_id'],
                'change_declined',
                'Your destination update was declined',
                [
                    /* The reason is the whole point of the notification. Without
                       it the manager knows only that the answer was no. */
                    'body'        => $note !== ''
                        ? $note
                        : 'The Office did not leave a reason. Ask them before sending it again.',
                    'link'        => $link,
                    'entity_type' => 'change_request',
                    'entity_id'   => $id,
                ]
            );

            Session::flash('success', $note !== ''
                ? 'Declined. Your reason has been sent to the manager.'
                : 'Declined. The manager was told, but no reason was given — they cannot act on that.');
        }
    }

    redirect(base_url('/admin/change-requests/index.php#req' . $id));
}

/* OPENS ON WHAT NEEDS DECIDING.
 *
 * The page used to open on every request ever filed, newest first, with the
 * decided ones mixed through the waiting ones. An officer arrives here to
 * answer something, so that is what it shows; 'all' is a tab away.
 *
 * ?status=all is explicit rather than an empty string, so "no parameter" can
 * mean the default instead of meaning everything. */
$status = (string) ($_GET['status'] ?? 'pending');

if ($status !== 'all' && !isset(Changes::STATUSES[$status])) {
    $status = 'pending';
}

$rows = Changes::all(['status' => $status === 'all' ? '' : $status], 500);

$pager    = Paginator::slice($rows, $_GET['page'] ?? null);
$requests = $pager['rows'];
$pending  = Changes::pendingCount();

/* WHICH ONE IS OPEN IN THE RIGHT-HAND PANEL.
 *
 * Server-rendered from ?id=, not a JavaScript selection: the decision forms
 * live in that panel, and a panel built in the browser is a panel whose CSRF
 * token and whose request id came from the browser too. Defaults to the first
 * row, so the page is never a list beside an empty box. */
$openId = (int) ($_GET['id'] ?? 0);
$open   = null;

foreach ($requests as $r) {
    if ((int) $r['id'] === $openId) { $open = $r; break; }
}

if ($open === null && $requests !== []) {
    $open = $requests[0];
}

/* One query for every cover shown, rather than one per row. destinations has no
   image column of its own — the cover lives in destination_photos. */
$covers = [];

if ($requests !== []) {
    $ids  = array_unique(array_map(static fn (array $r): int => (int) $r['destination_id'], $requests));
    $marks = implode(',', array_fill(0, count($ids), '?'));

    foreach (App\Core\Database::all(
        "SELECT destination_id, file_path FROM destination_photos
          WHERE destination_id IN ($marks) AND is_cover = 1",
        array_values($ids)
    ) as $row) {
        $covers[(int) $row['destination_id']] = (string) $row['file_path'];
    }
}

/** The tabs, in the order an officer works through them. */
$tabs = [
    'pending'   => 'Pending Review',
    'approved'  => 'Approved',
    'rejected'  => 'Declined',
    'withdrawn' => 'Withdrawn',
    'all'       => 'All',
];

$tone = static fn (string $s): string => match ($s) {
    'pending'  => 'flag',
    'approved' => 'ok',
    'rejected' => 'void',
    default    => 'void',
};

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


<?php /* =====================================================================
         TWO PANELS: the queue, and the one being decided.

         It was a stack of full-width sections, one per request, each carrying
         its own comparison table and its own decision form. Reading the third
         request meant scrolling past two others' tables, and the officer never
         saw how many were left. The list is now a list; the work happens beside
         it.
         ================================================================== */ ?>
<div class="cr-split">

    <!-- ============================== THE QUEUE ============================== -->
    <section class="panel cr-list">
        <header class="panel__head">
            <h2><i class="fa-solid fa-inbox"></i> Update Requests</h2>
        </header>

        <nav class="cr-tabs" aria-label="Filter by status">
            <?php foreach ($tabs as $key => $label): ?>
                <a class="cr-tabs__tab<?= $status === $key ? ' is-active' : '' ?>"
                   href="index.php?status=<?= e($key) ?>"
                   <?= $status === $key ? 'aria-current="page"' : '' ?>>
                    <?= e($label) ?>
                    <?php if ($key === 'pending' && $pending > 0): ?>
                        <span class="cr-tabs__n"><?= n($pending) ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($requests === []): ?>
            <div class="panel__body">
                <div class="empty rf-empty">
                    <i class="fa-regular fa-pen-to-square" aria-hidden="true"></i>
                    <h3><?= $status === 'pending' ? 'Nothing waiting' : 'Nothing matches that filter' ?></h3>
                    <p>
                        <?php if ($status === 'pending'): ?>
                            Every request has been decided. When a manager corrects their opening hours,
                            fees or facilities, it appears here for your approval before it goes public.
                        <?php else: ?>
                            <a href="index.php?status=all">Show all requests</a>.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        <?php else: ?>
            <ul class="cr-rows">
                <?php foreach ($requests as $r): ?>
                    <?php
                    $isOpen = $open !== null && (int) $open['id'] === (int) $r['id'];
                    $cover  = $covers[(int) $r['destination_id']] ?? '';
                    ?>
                    <li>
                        <a class="cr-row<?= $isOpen ? ' is-open' : '' ?>"
                           href="index.php?status=<?= e($status) ?>&amp;id=<?= (int) $r['id'] ?>"
                           <?= $isOpen ? 'aria-current="true"' : '' ?>>

                            <?php /* The destination's own cover, so an officer
                                     recognises the place before reading its
                                     name. Falls back to a glyph rather than a
                                     broken image when a site has no photo. */ ?>
                            <?php if ($cover !== ''): ?>
                                <img class="cr-row__pic" src="<?= e(base_url('/' . ltrim($cover, '/'))) ?>"
                                     alt="" loading="lazy" width="48" height="48">
                            <?php else: ?>
                                <span class="cr-row__pic cr-row__pic--none" aria-hidden="true">
                                    <i class="fa-solid fa-mountain-sun"></i>
                                </span>
                            <?php endif; ?>

                            <span class="cr-row__text">
                                <strong><?= e((string) $r['destination_name']) ?></strong>
                                <span><?= e((string) ($r['manager_name'] ?: 'a former manager')) ?></span>
                                <span class="cr-row__when">
                                    <?= e(format_date((string) $r['created_at'], 'M j, Y · g:i A')) ?>
                                </span>
                            </span>

                            <span class="pill pill--<?= e($tone((string) $r['status'])) ?> cr-row__pill">
                                <?= e(Changes::STATUSES[$r['status']]) ?>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php require __DIR__ . '/../../app/views/partials/pager.php'; ?>
        <?php endif; ?>
    </section>

    <!-- ============================= THE DECISION ============================= -->
    <section class="panel cr-detail" id="req<?= $open !== null ? (int) $open['id'] : 0 ?>">
        <?php if ($open === null): ?>
            <div class="panel__body">
                <div class="empty rf-empty">
                    <i class="fa-regular fa-hand-pointer" aria-hidden="true"></i>
                    <h3>Nothing selected</h3>
                    <p>Choose a request on the left to see what is being asked.</p>
                </div>
            </div>
        <?php else: ?>
            <?php
            $isPending = $open['status'] === 'pending';
            $live      = App\Repositories\DestinationRepository::find((int) $open['destination_id']);
            $where     = trim((string) ($live['barangay'] ?? '')) !== ''
                ? $live['barangay'] . ', Tampakan'
                : 'Tampakan, South Cotabato';
            ?>

            <header class="panel__head">
                <h2><i class="fa-solid fa-mountain-sun"></i> <?= e((string) $open['destination_name']) ?></h2>
                <span class="pill pill--<?= e($tone((string) $open['status'])) ?>">
                    <?= e(Changes::STATUSES[$open['status']]) ?>
                </span>
            </header>

            <div class="panel__body">
                <?php /* The four facts that say WHICH request this is, before
                         anything asks the officer to decide it. */ ?>
                <dl class="cr-facts">
                    <div><dt>Location</dt><dd><?= e($where) ?></dd></div>
                    <div><dt>Request ID</dt><dd>#<?= (int) $open['id'] ?></dd></div>
                    <div><dt>Submitted by</dt>
                        <dd><?= e((string) ($open['manager_name'] ?: 'a former manager')) ?></dd></div>
                    <div><dt>Submitted</dt>
                        <dd><?= e(format_date((string) $open['created_at'], 'M j, Y · g:i A')) ?></dd></div>
                    <?php if ($open['reviewed_at']): ?>
                        <div><dt>Decided</dt>
                            <dd>
                                <?= e(format_date((string) $open['reviewed_at'], 'M j, Y · g:i A')) ?>
                                <?php if ($open['reviewer_name']): ?>
                                    by <?= e((string) $open['reviewer_name']) ?>
                                <?php endif; ?>
                            </dd></div>
                    <?php endif; ?>
                </dl>

                <?php if ($open['reason']): ?>
                    <h3 class="cr-head">Manager&rsquo;s Note</h3>
                    <div class="cr-note"><?= nl2br(e((string) $open['reason'])) ?></div>
                <?php endif; ?>

                <?php if ($open['review_note']): ?>
                    <h3 class="cr-head">Your note back</h3>
                    <div class="cr-note cr-note--mine"><?= nl2br(e((string) $open['review_note'])) ?></div>
                <?php endif; ?>

                <h3 class="cr-head">Currently published, and what is proposed</h3>

                <?php if ($open['changes'] === []): ?>
                    <p class="text-muted">This request contains no changes.</p>
                <?php else: ?>
                    <?php /* The live values are read NOW, not stored with the
                             proposal. An officer deciding today has to see what
                             the page says today — the field may have been edited
                             by hand since, and approving would overwrite it. */ ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 rf-diff">
                            <thead>
                                <tr>
                                    <th style="width:20%">Field</th>
                                    <th style="width:40%">Currently published</th>
                                    <th style="width:40%">Proposed change</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($open['changes'] as $field => $proposed): ?>
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
                                        <td data-label="Proposed" class="rf-diff__new">
                                            <?= trim((string) $proposed) === ''
                                                ? '<span class="fst-italic">cleared</span>'
                                                : nl2br(e((string) $proposed)) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if ($isPending): ?>
                    <div class="rf-foot mt-3">
                        <p class="rf-foot__count">
                            Approving replaces the published details. Declining leaves them exactly as they are.
                        </p>

                        <button type="button" class="btn btn-sm btn-outline-danger"
                                data-cr-open="crDecline">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i> Decline
                        </button>

                        <button type="button" class="btn btn-sm btn-brand" data-cr-open="crApprove">
                            <i class="fa-solid fa-check" aria-hidden="true"></i> Approve &amp; Publish
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($isPending): ?>
                <?php /* ---- APPROVE ---- */ ?>
                <dialog class="sheet" id="crApprove" aria-labelledby="crApproveTitle">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $open['id'] ?>">

                        <header class="sheet__head">
                            <h2 id="crApproveTitle">
                                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                Approve and Publish Changes?
                            </h2>
                            <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                            </button>
                        </header>

                        <div class="sheet__body">
                            <p>
                                This will replace the currently published destination information with the
                                proposed changes. <strong><?= e((string) $open['destination_name']) ?></strong>
                                will show <?= n(count($open['changes'])) ?> updated field(s) on the public
                                website and on its QR page straight away.
                            </p>

                            <label class="form-label small" for="approveNote">
                                Note to the manager <span class="text-muted">(optional)</span>
                            </label>
                            <input type="text" class="form-control form-control-sm" maxlength="600"
                                   id="approveNote" name="review_note"
                                   placeholder="Approved, thanks.">
                        </div>

                        <footer class="sheet__foot">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close>
                                Cancel
                            </button>
                            <button type="submit" name="action" value="approve" class="btn btn-sm btn-brand">
                                <i class="fa-solid fa-check" aria-hidden="true"></i> Approve &amp; Publish
                            </button>
                        </footer>
                    </form>
                </dialog>

                <?php /* ---- DECLINE ---- */ ?>
                <dialog class="sheet" id="crDecline" aria-labelledby="crDeclineTitle">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $open['id'] ?>">

                        <header class="sheet__head">
                            <h2 id="crDeclineTitle">
                                <i class="fa-solid fa-xmark" aria-hidden="true"></i> Decline Update Request
                            </h2>
                            <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                            </button>
                        </header>

                        <div class="sheet__body">
                            <p class="text-muted small">
                                The published details stay exactly as they are. The manager is told, and reads
                                what you write here &mdash; it is the only thing that says what to change.
                            </p>

                            <label class="form-label" for="declineReason">
                                Reason for Declining <span class="text-danger">*</span>
                            </label>
                            <?php /* required here AND in the repository: reject()
                                     refuses an empty note, so a post that skips
                                     the browser is refused too. */ ?>
                            <textarea class="form-control" id="declineReason" name="review_note"
                                      rows="3" maxlength="600" required
                                      placeholder="The fee has to match the ordinance — please send the council resolution first."></textarea>
                        </div>

                        <footer class="sheet__foot">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close>
                                Cancel
                            </button>
                            <button type="submit" name="action" value="reject" class="btn btn-sm btn-outline-danger">
                                <i class="fa-solid fa-xmark" aria-hidden="true"></i> Decline Request
                            </button>
                        </footer>
                    </form>
                </dialog>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>

<script>
/* The two decision dialogs. data-dialog-close is the shared script's job. */
(function () {
    'use strict';

    document.addEventListener('click', function (ev) {
        var t = ev.target.closest('[data-cr-open]');
        if (!t) { return; }

        var box = document.getElementById(t.getAttribute('data-cr-open'));
        if (!box || typeof box.showModal !== 'function') { return; }

        ev.preventDefault();
        box.showModal();
    });
})();
</script>
<?php require __DIR__ . '/../../app/views/partials/pager.php'; ?>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
