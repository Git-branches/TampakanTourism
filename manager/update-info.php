<?php
declare(strict_types=1);

/**
 * TourSync — the manager proposing a change to their destination.    Feature 6
 *
 * What this replaces is a phone call to the Municipal Tourism Office, or a trip
 * into town, every time an entrance fee changes or a comfort room goes out of
 * service. The public page then stayed wrong for as long as that took.
 *
 * The manager fills in the page as they believe it should read; the system
 * works out what actually changed and sends only that to the office. Nobody has
 * to describe a change in prose, and the officer reviewing it sees the old and
 * new text side by side rather than a paragraph explaining them.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Csrf;
use App\Core\ManagerAuth;
use App\Core\Session;
use App\Repositories\ChangeRequestRepository as Changes;
use App\Repositories\DestinationRepository;
use App\Repositories\NotificationRepository as Notifications;

ManagerAuth::require();

$destinationId = (int) ManagerAuth::destinationId();
$managerId     = (int) ManagerAuth::id();
$destination   = DestinationRepository::find($destinationId);

if ($destination === null) {
    Session::flash('danger', 'Your destination could not be loaded. Please contact the Tourism Office.');
    redirect(base_url('/manager/index.php'));
}

$errors = [];

if (is_post()) {
    Csrf::verify();

    $action = (string) ($_POST['action'] ?? 'propose');

    if ($action === 'withdraw') {
        /* Scoped to this manager inside the repository — an id in a form is
           not proof of who raised it. */
        $ok = Changes::withdraw((int) ($_POST['request_id'] ?? 0), $managerId);

        Session::flash(
            $ok ? 'success' : 'warning',
            $ok ? 'Request withdrawn.' : 'That request could no longer be withdrawn — the Office may have decided on it.'
        );
        redirect(base_url('/manager/update-info.php'));
    }

    $reason = trim((string) ($_POST['reason'] ?? ''));

    /* Only what actually differs from the live record. A manager who opens the
       page, corrects one line and submits should not file a proposal listing
       eleven unchanged fields for the officer to read through. */
    $changes = Changes::diff($_POST, $destination);

    if ($changes === []) {
        $errors['form'] = 'Nothing on this form is different from what is published. Change something first.';
    } elseif ($reason === '') {
        $errors['reason'] = 'Please say why. The Office is being asked to change a public page and this is what they read first.';
    } else {
        $id = Changes::create($destinationId, $managerId, $changes, $reason);

        ActivityLog::record(
            'destination.change_requested',
            'destination',
            $destinationId,
            count($changes) . ' field(s) proposed for ' . $destination['name']
        );

        /* While this sits undecided the public page shows details a manager has
           said are wrong, so it is worth the officer's attention rather than
           only a number beside a menu. */
        Notifications::record(
            'change_request',
            'Change requested for ' . $destination['name'],
            [
                'body'        => count($changes) . ' field(s) — ' . mb_substr($reason, 0, 160),
                'link'        => base_url('/admin/change-requests/index.php#req' . $id),
                'entity_type' => 'change_request',
                'entity_id'   => $id,
            ]
        );

        Session::flash(
            'success',
            'Sent to the Municipal Tourism Office. ' . count($changes) . ' change(s) are waiting for their review.'
        );
        redirect(base_url('/manager/update-info.php'));
    }
}

$mine = Changes::all(['destination_id' => $destinationId], 30);

/* Waiting proposals are shown above the form because they answer the question a
   manager arrives with — "did my last one go through?" — and because a second
   proposal for the same field, filed because the first looked lost, is a
   contradiction the office then has to resolve. */
$pending = array_values(array_filter($mine, static fn(array $r): bool => $r['status'] === 'pending'));

$pageTitle    = 'Update my destination';
$pageIcon     = 'fa-pen-to-square';
$pageSubtitle = (string) $destination['name'];

require __DIR__ . '/_partials/head.php';
?>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i> <?= e($errors['form']) ?></div>
<?php endif; ?>

<?php if ($pending !== []): ?>
    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-solid fa-hourglass-half"></i> Waiting for the Office</h2>
        </header>
        <div class="panel__body">
            <?php foreach ($pending as $p): ?>
                <div class="border rounded p-3 mb-2">
                    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                        <div>
                            <strong><?= n(count($p['changes'])) ?> change(s)</strong>
                            <span class="text-muted small">
                                &middot; sent <?= e(format_date((string) $p['created_at'], 'M j, Y \a\t g:i A')) ?>
                            </span>
                            <p class="mb-1 text-muted small">
                                <?= e(implode(', ', array_map(
                                    static fn(string $f): string => Changes::FIELDS[$f]['label'] ?? $f,
                                    array_keys($p['changes'])
                                ))) ?>
                            </p>
                            <?php if ($p['reason']): ?>
                                <p class="mb-0 small">&ldquo;<?= e((string) $p['reason']) ?>&rdquo;</p>
                            <?php endif; ?>
                        </div>
                        <form method="post" data-confirm="Withdraw this request?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="withdraw">
                            <input type="hidden" name="request_id" value="<?= (int) $p['id'] ?>">
                            <button class="btn btn-sm btn-outline-secondary">Withdraw</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-pen-to-square"></i> Propose changes</h2>
        <a href="<?= e(base_url('/destination.php?slug=' . urlencode((string) $destination['slug']))) ?>"
           target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> See the public page
        </a>
    </header>

    <div class="panel__body">
        <p class="text-muted">
            Edit anything below to how it should read. The Municipal Tourism Office reviews the change
            before it appears on the public website and on your QR page &mdash; so nothing here goes
            live on its own.
        </p>

        <?php /* What is NOT on this form, and why: status, address, coordinates,
                 the QR token and the destination's name. Archiving a site,
                 moving its map pin or rotating the token behind a printed sign
                 are office decisions with effects outside the destination. */ ?>
        <p class="text-muted small">
            To change the destination's <strong>name, location, or whether it is open to the public</strong>,
            contact the Office directly &mdash; those are not editable here.
        </p>

        <form method="post">
            <?= csrf_field() ?>

            <div class="row g-3">
                <?php foreach (Changes::FIELDS as $field => $rules):
                    $current = (string) ($destination[$field] ?? ''); ?>
                    <div class="col-<?= $rules['type'] === 'textarea' ? '12' : 'md-6' ?>">
                        <label class="form-label" for="<?= e($field) ?>"><?= e($rules['label']) ?></label>

                        <?php if ($rules['type'] === 'textarea'): ?>
                            <textarea class="form-control" id="<?= e($field) ?>" name="<?= e($field) ?>"
                                      rows="4" maxlength="<?= (int) $rules['max'] ?>"><?= e($current) ?></textarea>
                        <?php else: ?>
                            <input type="text" class="form-control" id="<?= e($field) ?>" name="<?= e($field) ?>"
                                   maxlength="<?= (int) $rules['max'] ?>" value="<?= e($current) ?>">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <div class="col-12">
                    <label class="form-label" for="reason">
                        Why are you changing this? <span class="text-danger">*</span>
                    </label>
                    <textarea class="form-control <?= isset($errors['reason']) ? 'is-invalid' : '' ?>"
                              id="reason" name="reason" rows="3" maxlength="600"
                              placeholder="The entrance fee went up in August, and the upper comfort room is closed for repairs until October."><?= e((string) ($_POST['reason'] ?? '')) ?></textarea>
                    <?php if (isset($errors['reason'])): ?>
                        <div class="field-error"><?= e($errors['reason']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <button type="submit" class="btn btn-brand mt-3">
                <i class="fa-solid fa-paper-plane"></i> Send to the Tourism Office
            </button>
        </form>
    </div>
</section>

<?php
$decided = array_values(array_filter($mine, static fn(array $r): bool => $r['status'] !== 'pending'));
?>
<?php if ($decided !== []): ?>
    <section class="panel">
        <header class="panel__head"><h2><i class="fa-solid fa-clock-rotate-left"></i> Earlier requests</h2></header>
        <div class="panel__body">
            <?php foreach (array_slice($decided, 0, 10) as $r): ?>
                <div class="border rounded p-3 mb-2">
                    <span class="pill pill--<?= $r['status'] === 'approved' ? 'ok' : 'void' ?>">
                        <?= e(Changes::STATUSES[$r['status']]) ?>
                    </span>
                    <span class="text-muted small">
                        <?= e(format_date((string) $r['created_at'], 'M j, Y')) ?>
                        <?php if ($r['reviewer_name']): ?>&middot; <?= e((string) $r['reviewer_name']) ?><?php endif; ?>
                    </span>
                    <p class="mb-1 mt-2 small text-muted">
                        <?= e(implode(', ', array_map(
                            static fn(string $f): string => Changes::FIELDS[$f]['label'] ?? $f,
                            array_keys($r['changes'])
                        ))) ?>
                    </p>
                    <?php if ($r['review_note']): ?>
                        <p class="mb-0"><strong>Office:</strong> <?= e((string) $r['review_note']) ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/_partials/foot.php'; ?>
