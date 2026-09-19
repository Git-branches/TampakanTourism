<?php
declare(strict_types=1);

/**
 * One guide: their record, their credentials, their certificates, and the card.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\DocumentUploader;
use App\Core\QrService;
use App\Core\Session;
use App\Repositories\TourGuideRosterRepository as Roster;

Auth::require();

$id    = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$guide = $id > 0 ? Roster::find($id) : null;

if ($guide === null) {
    Session::flash('danger', 'That guide could not be found.');
    redirect(base_url('/admin/tour-guides/index.php'));
}

if (is_post()) {
    Csrf::verify();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'certificate') {
        $title = trim((string) ($_POST['title'] ?? ''));

        if ($title === '') {
            Session::flash('danger', 'Give the certificate a name so it can be told apart from the others.');
            redirect(base_url('/admin/tour-guides/view.php?id=' . $id));
        }

        $uploader = new DocumentUploader();
        $stored   = $uploader->store($_FILES['file'] ?? [], 'certificates');

        if ($stored === null) {
            Session::flash('danger', $uploader->firstError() ?? 'That file could not be saved.');
            redirect(base_url('/admin/tour-guides/view.php?id=' . $id));
        }

        Roster::addCertificate($id, $stored + [
            'title'       => $title,
            'issuer'      => (string) ($_POST['issuer'] ?? ''),
            'issued_on'   => (string) ($_POST['issued_on'] ?? ''),
            'expires_on'  => (string) ($_POST['expires_on'] ?? ''),
            'uploaded_by' => Auth::id(),
        ]);

        ActivityLog::record('guide.certificate', 'tour_guide', $id, 'Filed "' . $title . '"');
        Session::flash('success', 'Certificate filed.');
    }

    if ($action === 'certificate_delete') {
        $certificateId = (int) ($_POST['certificate_id'] ?? 0);
        $certificate   = Roster::findCertificate($certificateId);

        /* Belongs to THIS guide, checked rather than assumed. Without it a
           posted id could delete a certificate filed under somebody else. */
        if ($certificate !== null && (int) $certificate['guide_id'] === $id) {
            Roster::deleteCertificate($certificateId);
            ActivityLog::record('guide.certificate.delete', 'tour_guide', $id,
                'Removed "' . $certificate['title'] . '"');
            Session::flash('success', 'Certificate removed.');
        }
    }

    if ($action === 'rotate') {
        Roster::rotateToken($id);
        ActivityLog::record('guide.rotate', 'tour_guide', $id, 'Issued a new verification code');
        Session::flash('warning',
            'A new QR code was issued. Every card already printed for this guide now fails verification — print and hand over a new one.');
    }

    if ($action === 'issued') {
        /* THE SECOND PRESS IS NOT AN ERROR, AND IT IS NOT A SUCCESS EITHER.
           markIssued() only writes when today has not been recorded yet, so a
           double-click, a refresh of this POST, or the same record open in two
           tabs all reach here and only the first one changes anything. Saying
           "Recorded" to the rest would be a lie the officer acts on; saying
           nothing would look like the button was broken. */
        if (Roster::markIssued($id)) {
            ActivityLog::record('guide.issued', 'tour_guide', $id, 'Card issued to ' . $guide['full_name']);
            Session::flash('success', 'Recorded as issued today.');
        } else {
            Session::flash('info', 'Already recorded as issued today &mdash; nothing was changed.');
        }
    }

    redirect(base_url('/admin/tour-guides/view.php?id=' . $id));
}

$pageTitle = (string) $guide['full_name'];
$pageIcon  = 'fa-id-card';

/* Read from the row already in hand, not from a second query. */
$issuedToday  = Roster::issuedToday($guide['id_issued_at'] ?? null);

$credentials  = Roster::credentialsFor($id);
$certificates = Roster::certificatesFor($id);
$effective    = (string) $guide['effective_status'];
$photo        = uploaded_url((string) ($guide['photo_path'] ?? ''));
$verifyUrl    = Roster::verifyUrl((string) $guide['verify_token']);

$tone = match ($effective) {
    'active' => 'ok',
    'expired', 'suspended' => 'flag',
    default  => 'void',
};

require __DIR__ . '/../_partials/head.php';
?>

<?php
/* WHY THIS PAGE WAS REBUILT.
 *
 * It had six panels of identical weight, so "Remove from tour guide list" carried the
 * same visual authority as the guide's own name, and about a hundred and twenty
 * words of prose explaining what each panel was for. A record page that has to
 * lecture is a record page whose layout is not doing its job.
 *
 * Now: identity at the top, the read-only record and its qualifications down the
 * main column, everything ACTIONABLE gathered in one narrower column beside it,
 * and the single destructive thing on its own at the very bottom.
 */
$blockers = [];

if ($guide['valid_until'] === null || $guide['valid_until'] === '') {
    $blockers[] = ['No "valid until" date, so there is no card yet.',
                   'Set a date', base_url('/admin/tour-guides/edit.php?id=' . $id)];
}

if ($effective !== 'active' && $effective !== 'no_id') {
    $blockers[] = ['This guide is ' . strtolower(Roster::EFFECTIVE[$effective]) . ', so a printed card would fail.',
                   'Change the status', base_url('/admin/tour-guides/edit.php?id=' . $id)];
}

if (!QrService::isPublishable()) {
    $blockers[] = [QrService::unpublishableReason(),
                   'Set the public address', base_url('/admin/settings/index.php')];
}

$dtStyle = 'font-size:.71rem; letter-spacing:.05em; text-transform:uppercase; color:var(--ink-3)';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <a class="text-muted small" href="<?= e(base_url('/admin/tour-guides/index.php')) ?>">
        <i class="fa-solid fa-arrow-left"></i> Tour Guides
    </a>
    <?php /* NO EDIT BUTTON HERE ANY MORE.
             Editing is done from the list, where it opens in a dialog over the
             row — so this one led out of the record and onto a separate page,
             which is the journey the dialog was added to remove. The contextual
             links further down ("Set a date", "Change the status", "Add them")
             still reach edit.php, because each of those is an answer to
             something this page has just told the officer is missing. */ ?>
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-brand btn-sm" data-dialog="idCard">
            <i class="fa-solid fa-id-card"></i> Tour Guide ID
        </button>
    </div>
</div>

<?php if ($effective !== 'active'): ?>
    <div class="alert alert-warning">
        <strong>This guide cannot be assigned.</strong>
        <?= match ($effective) {
            'expired'   => 'Their ID expired on ' . e(format_date((string) $guide['valid_until'], 'F j, Y')) . '.',
            'no_id'     => 'No ID has been issued &mdash; set a "valid until" date first.',
            'suspended' => 'They are suspended.',
            'revoked'   => 'Their accreditation has been revoked.',
            default     => '',
        } ?>
        <?php if ($guide['status_note']): ?>
            <span class="d-block mt-1"><em><?= e((string) $guide['status_note']) ?></em></span>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php /* IDENTITY IS NOT A PANEL. It is the subject of the page, so it gets no
         heading saying "Guide" &mdash; the photograph and the name say that. */ ?>
<section class="panel mb-3">
    <div class="panel__body d-flex align-items-center gap-3 flex-wrap">
        <?php if ($photo !== null): ?>
            <img src="<?= e($photo) ?>" alt="" width="72" height="72"
                 style="border-radius:10px; object-fit:cover; flex-shrink:0;">
        <?php endif; ?>

        <div class="flex-grow-1" style="min-width:200px">
            <h2 class="h4 mb-1"><?= e((string) $guide['full_name']) ?></h2>
            <p class="mb-0 cell-sub">
                <code><?= e((string) $guide['guide_code']) ?></code>
                <?php if ($guide['valid_until']): ?>
                    &middot; valid until <?= e(format_date((string) $guide['valid_until'], 'F j, Y')) ?>
                <?php endif; ?>
            </p>
        </div>

        <span class="pill pill--<?= $tone ?>"><?= e(Roster::EFFECTIVE[$effective]) ?></span>
    </div>
</section>

<div class="row g-3">

    <!-- ============ the record ============ -->
    <div class="col-lg-8">
        <section class="panel">
            <header class="panel__head"><h2><i class="fa-regular fa-address-card"></i> Details</h2></header>
            <div class="panel__body">
                <?php /* The house .detail-grid, the same one the arrival record
                         uses. It was a bespoke table here for no reason. */ ?>
                <dl class="detail-grid">
                    <div>
                        <dt>Mobile</dt>
                        <dd>
                            <?php if ($guide['mobile_number']): ?>
                                <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $guide['mobile_number']) ?? '') ?>"><?= e((string) $guide['mobile_number']) ?></a>
                            <?php else: ?>
                                <span class="text-muted">&mdash;</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div>
                        <dt>Email</dt>
                        <dd><?= $guide['email'] ? e((string) $guide['email']) : '<span class="text-muted">&mdash;</span>' ?></dd>
                    </div>
                    <div>
                        <dt>Address</dt>
                        <dd><?= $guide['address'] ? e((string) $guide['address']) : '<span class="text-muted">&mdash;</span>' ?></dd>
                    </div>
                    <div>
                        <dt>ID last issued</dt>
                        <dd><?= $guide['id_issued_at']
                            ? e(format_date((string) $guide['id_issued_at'], 'M j, Y'))
                            : '<span class="text-muted">never recorded</span>' ?></dd>
                    </div>
                    <?php if ($guide['notes']): ?>
                        <div style="grid-column:1/-1">
                            <dt>Office notes</dt>
                            <dd><?= nl2br(e((string) $guide['notes'])) ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </div>
        </section>

        <?php /* TWO HALVES OF ONE QUESTION &mdash; what is this guide qualified in.
                 One is typed and goes on the card; the other is the scan that
                 proves it. Side by side, so the difference is visible instead of
                 explained in a paragraph. */ ?>
        <section class="panel mt-3">
            <header class="panel__head"><h2><i class="fa-solid fa-award"></i> Qualifications</h2></header>
            <div class="panel__body">
                <div class="row g-4">

                    <div class="col-md-5">
                        <p class="mb-2" style="<?= $dtStyle ?>">On the card</p>

                        <?php if ($credentials === []): ?>
                            <p class="text-muted small mb-0">
                                None yet.
                                <a href="<?= e(base_url('/admin/tour-guides/edit.php?id=' . $id)) ?>">Add them</a>.
                            </p>
                        <?php else: ?>
                            <ul class="mb-0 ps-3 small">
                                <?php foreach ($credentials as $c): ?>
                                    <li class="mb-1">
                                        <?= e((string) $c['label']) ?>
                                        <?php if ($c['issuer']): ?>
                                            <span class="cell-sub d-block"><?= e((string) $c['issuer']) ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-7" id="certificates">
                        <p class="mb-2" style="<?= $dtStyle ?>">Scanned documents</p>

                        <?php if ($certificates === []): ?>
                            <p class="text-muted small">Nothing filed yet.</p>
                        <?php else: ?>
                            <ul class="list-unstyled mb-2 small">
                                <?php foreach ($certificates as $c): ?>
                                    <?php
                                    $lapsed  = $c['expires_on'] !== null && (string) $c['expires_on'] < date('Y-m-d');
                                    $certUrl = base_url('/admin/tour-guides/certificate.php?id=' . (int) $c['id']);
                                    $isPdf   = (string) $c['mime_type'] === 'application/pdf';

                                    /* One line of context for the viewer's caption, built
                                       once so the title link and the eye say the same thing. */
                                    $certCaption = trim(implode(' · ', array_filter([
                                        (string) $c['title'],
                                        (string) ($c['issuer'] ?? ''),
                                        $c['expires_on'] ? ($lapsed ? 'expired ' : 'valid to ')
                                            . format_date((string) $c['expires_on'], 'M Y') : '',
                                    ])));
                                    ?>
                                    <?php /* flex-nowrap: admin.css wraps every .d-flex.gap-2, which
                                             dropped the eye and the bin under a long title. */ ?>
                                    <li class="d-flex flex-nowrap align-items-start gap-2 mb-2">
                                        <i class="fa-regular <?= $isPdf ? 'fa-file-pdf' : 'fa-file-image' ?> text-muted mt-1"
                                           aria-hidden="true"></i>
                                        <span class="flex-grow-1">
                                            <?php /* OPENS IN A VIEWER, NOT A NEW PAGE.
                                                     The title used to open certificate.php in a
                                                     new tab: a bare file, the raw address in the
                                                     bar, and the record left behind in another
                                                     tab. It opens over the record now. The href
                                                     is kept, so a middle-click and a browser with
                                                     no JavaScript still reach the file. */ ?>
                                            <a target="_blank" rel="noopener" href="<?= e($certUrl) ?>"
                                               data-cert-view
                                               data-cert-kind="<?= $isPdf ? 'pdf' : 'image' ?>"
                                               data-cert-caption="<?= e($certCaption) ?>">
                                                <?= e((string) $c['title']) ?>
                                            </a>
                                            <span class="cell-sub d-block">
                                                <?= $c['issuer'] ? e((string) $c['issuer']) . ' &middot; ' : '' ?>
                                                <?= $c['issued_on'] ? e(format_date((string) $c['issued_on'], 'M Y')) : '' ?>
                                                <?php if ($c['expires_on']): ?>
                                                    <span class="<?= $lapsed ? 'text-danger' : '' ?>">
                                                        &middot; <?= $lapsed ? 'expired' : 'to' ?>
                                                        <?= e(format_date((string) $c['expires_on'], 'M Y')) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </span>
                                        </span>
                                        <?php /* The eye is the obvious way in for anyone who does
                                                 not think to click a title. Same viewer, same
                                                 file, same fallback href. */ ?>
                                        <a class="btn btn-sm btn-link text-secondary p-0" target="_blank" rel="noopener"
                                           href="<?= e($certUrl) ?>"
                                           data-cert-view
                                           data-cert-kind="<?= $isPdf ? 'pdf' : 'image' ?>"
                                           data-cert-caption="<?= e($certCaption) ?>"
                                           title="View" aria-label="View this certificate">
                                            <i class="fa-regular fa-eye" aria-hidden="true"></i>
                                        </a>
                                        <form method="post" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $id ?>">
                                            <input type="hidden" name="action" value="certificate_delete">
                                            <input type="hidden" name="certificate_id" value="<?= (int) $c['id'] ?>">
                                            <button class="btn btn-sm btn-link text-danger p-0" type="submit"
                                                    data-confirm="Delete this certificate and its file?"
                                                    data-confirm-tone="danger"
                                                    aria-label="Delete this certificate">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <?php /* IN A MODAL, NOT UNFOLDED INTO THE RECORD.
                                 This was a disclosure that opened the upload form
                                 inline, pushing the rest of the column down while
                                 the officer filled it in. It is now a sheet over the
                                 record, the same one Add tour guide and Add video
                                 use: the fields, the action, the token and the
                                 multipart upload are exactly as they were — only
                                 where the form appears has changed. A submit still
                                 posts to this page and comes back with its flash. */ ?>
                        <button type="button" class="btn btn-sm btn-link p-0" data-dialog="addCertificate"
                                style="color:var(--green)">
                            <i class="fa-solid fa-plus" aria-hidden="true"></i> Add a certificate
                        </button>

                        <dialog class="sheet" id="addCertificate" aria-labelledby="addCertificateTitle">
                            <form method="post" enctype="multipart/form-data" class="sheet__form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="action" value="certificate">

                                <header class="sheet__head">
                                    <h2 id="addCertificateTitle">
                                        <i class="fa-solid fa-file-circle-plus" aria-hidden="true"></i> Add a certificate
                                    </h2>
                                    <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
                                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                    </button>
                                </header>

                                <div class="sheet__body">
                                    <p class="text-muted small mb-3">
                                        A scanned document filed against
                                        <strong><?= e((string) $guide['full_name']) ?></strong>.
                                        Kept for office staff only — never shown on the public verification page.
                                    </p>

                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label" for="cert_title">Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="cert_title"
                                                   name="title" maxlength="160" required placeholder="First Aid Training">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label" for="cert_issuer">Issued by</label>
                                            <input type="text" class="form-control" id="cert_issuer"
                                                   name="issuer" maxlength="160" placeholder="Philippine Red Cross">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label" for="cert_issued">Date issued</label>
                                            <input type="date" class="form-control" id="cert_issued" name="issued_on">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label" for="cert_expires">Expires</label>
                                            <input type="date" class="form-control" id="cert_expires" name="expires_on">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label" for="cert_file">File <span class="text-danger">*</span></label>
                                            <input type="file" class="form-control" id="cert_file" name="file"
                                                   accept="image/jpeg,image/png,application/pdf" required>
                                            <div class="form-text">JPG, PNG or PDF, up to 8&nbsp;MB.</div>
                                        </div>
                                    </div>
                                </div>

                                <footer class="sheet__foot">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close>Cancel</button>
                                    <button type="submit" class="btn btn-sm btn-brand">
                                        <i class="fa-solid fa-upload" aria-hidden="true"></i> File certificate
                                    </button>
                                </footer>
                            </form>
                        </dialog>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- ============ everything actionable ============ -->
    <div class="col-lg-4">
        <?php /* THE CARD AND ITS QR ARE ONE THING, not two panels. The code on the
                 front opens the page this panel links to; splitting them made a
                 reader hunt for which panel owned which half. */ ?>
        <section class="panel">
            <header class="panel__head"><h2><i class="fa-solid fa-id-card"></i> ID card</h2></header>
            <div class="panel__body">
                <?php if ($blockers === []): ?>
                    <p class="mb-2"><span class="pill pill--ok">Ready to print</span></p>
                <?php else: ?>
                    <p class="mb-2"><span class="pill pill--flag">Not ready to print</span></p>
                    <ul class="small ps-3 mb-3">
                        <?php foreach ($blockers as $b): ?>
                            <li class="mb-1">
                                <?= e((string) $b[0]) ?>
                                <a href="<?= e((string) $b[2]) ?>"><?= e((string) $b[1]) ?></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <button type="button" class="btn btn-brand btn-sm w-100 mb-2" data-dialog="idCard">
                    <i class="fa-solid fa-print"></i> Open the card
                </button>

                <p class="form-text mt-0 mb-3">
                    Built from this record each time &mdash; correcting a detail and printing
                    again <em>is</em> how an ID is reissued.
                </p>

                <hr class="my-3">

                <p class="mb-2" style="<?= $dtStyle ?>">Verification page</p>
                <p class="small mb-2">
                    <?php /* The 32-character token used to be printed in full and
                             wrapped over two lines. Nobody reads it; it is a link. */ ?>
                    <a href="<?= e($verifyUrl) ?>" target="_blank" rel="noopener">
                        What a visitor sees when they scan
                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    </a>
                </p>

                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="rotate">
                    <button class="btn btn-sm btn-outline-secondary w-100" type="submit"
                            data-confirm="Every card already printed for this guide will stop verifying. Continue?"
                            data-confirm-tone="danger">
                        <i class="fa-solid fa-rotate"></i> Issue a new QR code
                    </button>
                    <span class="form-text d-block mt-1">If a card is lost or stolen.</span>
                </form>
            </div>
        </section>
    </div>
</div>

<?php /* THE ONE DESTRUCTIVE ACTION, ON ITS OWN AND LAST. It had a full panel with
         a heading and a paragraph, which gave deleting a person the same weight
         on the page as their telephone number. */ ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-4 pt-3"
     style="border-top:1px solid var(--line)">
    <p class="cell-sub mb-0" style="max-width:46rem">
        Removing <?= e((string) $guide['full_name']) ?> deletes their credentials and certificate
        files. Requests they were assigned keep the name and number they were answered with.
    </p>
    <form method="post" action="<?= e(base_url('/admin/tour-guides/index.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="action" value="delete">
        <button class="btn btn-sm btn-link text-danger" type="submit"
                data-confirm="Remove this guide from the tour guide list?"
                data-confirm-tone="danger">
            Remove from tour guide list
        </button>
    </form>
</div>


<?php /* THE CARD, OVER THE RECORD — rendered here, not framed.
         It WAS an <iframe>. The document root sends X-Frame-Options: DENY,
         which refuses the frame even same-origin, so the dialog showed a
         broken-page icon. Weakening a site-wide clickjacking header to satisfy
         one modal is the wrong trade; rendering the card where it is needed is
         the right one.

         _card.php is the single implementation — this dialog and the standalone
         page both include it. Every class inside it is prefixed tgid-, because
         this page already loads Bootstrap (.card) and admin.css (.tag, --ink,
         --line). */ ?>
<dialog class="sheet sheet--wide" id="idCard">
    <div class="sheet__form">
        <header class="sheet__head">
            <h2>
                <i class="fa-solid fa-id-card" aria-hidden="true"></i>
                Tour Guide ID &mdash; <?= e((string) $guide['guide_code']) ?>
            </h2>
            <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </header>

        <div class="sheet__body" id="idCardBody">
            <?php if ($effective !== 'active' || !QrService::isPublishable()): ?>
                <div class="alert alert-warning py-2 small">
                    <?php if ($effective !== 'active'): ?>
                        <strong>This card would not verify.</strong>
                        Scanning it shows <strong><?= e(strtoupper(Roster::EFFECTIVE[$effective])) ?></strong>.
                    <?php else: ?>
                        <strong>Do not print yet.</strong> <?= e(QrService::unpublishableReason()) ?>
                        <a href="<?= e(base_url('/admin/settings/index.php')) ?>">Set the public address</a>.
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php /* The dialog draws the controls itself, in its footer, so there
                     is one row of buttons rather than two and the flip control is
                     never below the fold. */ ?>
            <?php $tgidControls = false; require __DIR__ . '/_card.php'; ?>
        </div>

        <footer class="sheet__foot">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close>Close</button>

            <?php /* "Open in a tab" is gone. It existed because a browser can be
                     awkward about printing from inside a dialog, but it sent the
                     officer to a second page that prints the same card — and the
                     Print ID button here already handles the dialog case.
                     id-card.php is untouched and still reachable at its own
                     address for anyone who needs it. */ ?>

            <?php /* Posts to this page, the way it always did. It used to live in
                     the standalone card page's toolbar, which the dialog has not
                     got.

                     ONCE A DAY, AND IT SAYS SO. The button used to stay live
                     after it had been pressed, so an officer with no way to tell
                     whether it had worked pressed it again — and each press
                     moved the recorded time. The state is now on the control
                     itself. The guard that actually prevents the second write is
                     in markIssued(); this is the half the officer can see. */ ?>
            <?php if ($issuedToday): ?>
                <span class="btn btn-sm btn-outline-secondary disabled" aria-disabled="true"
                      title="Recorded <?= e(format_date((string) $guide['id_issued_at'], 'M j, Y g:i A')) ?>">
                    <i class="fa-solid fa-check text-success" aria-hidden="true"></i> Issued Today
                </span>
            <?php else: ?>
                <form method="post" class="d-inline" data-busy-label="Recording&hellip;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="issued">
                    <button type="submit" class="btn btn-sm btn-outline-secondary">Record as issued today</button>
                </form>
            <?php endif; ?>

            <?php /* Same ids the component's script binds to. */ ?>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="tgidFlipBtn"
                    aria-controls="tgidFlip" aria-pressed="false">
                <span aria-hidden="true">&#8635;</span> <span id="tgidFlipLabel">View Back</span>
            </button>

            <span class="badge bg-light text-dark border" role="status" aria-live="polite">
                Showing: <strong id="tgidSideLabel">Front</strong>
            </span>

            <button type="button" class="btn btn-sm btn-brand" id="idPrint">
                <i class="fa-solid fa-print"></i> Print ID
            </button>
        </footer>
    </div>
</dialog>

<script>
(function () {
    'use strict';

    var print = document.getElementById('idPrint');

    if (!print) { return; }

    print.addEventListener('click', function () {
        /* PRINTING OUT OF A <dialog> IS NOT UNIFORM ACROSS BROWSERS.
           A dialog opened with showModal() sits in the top layer, and what a
           printer receives from there varies — some browsers emit the page
           behind it, some emit nothing. Rather than gamble on the office's
           browser, the document marks itself and the print stylesheet in
           _card.php hides everything except the card. */
        document.documentElement.classList.add('tgid-printing');
        window.print();
    });

    /* Cleared however the dialog ends — afterprint does not fire in every
       browser when a print is cancelled, so the class is removed on focus too. */
    var clear = function () { document.documentElement.classList.remove('tgid-printing'); };

    window.addEventListener('afterprint', clear);
    window.addEventListener('focus', clear);
})();
</script>

<?php /* THE CERTIFICATE VIEWER.
         The same dialog and styling the inspection review uses for evidence
         photos (.rev-lightbox, global in admin.css), with its own id and its own
         data-cert-view trigger so neither page's script handles the other's.

         AN IMAGE IS SHOWN; A PDF IS OFFERED.
         An image loads through <img>, which is not framing and is unaffected by
         the site's X-Frame-Options. A PDF could only be shown inside an iframe or
         object — and the document root sends X-Frame-Options: DENY alongside the
         endpoint's own SAMEORIGIN, which a browser resolves to DENY. So rather
         than a blank grey rectangle, a PDF gets a clear panel with Open and
         Download. */ ?>
<dialog class="rev-lightbox" id="certLightbox" aria-label="Certificate">
    <button type="button" class="rev-lightbox__close" data-cert-close aria-label="Close">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
    </button>
    <figure class="rev-lightbox__frame">
        <img id="certLightboxImg" src="" alt="">

        <div class="cert-pdf" id="certLightboxPdf" hidden>
            <i class="fa-regular fa-file-pdf" aria-hidden="true"></i>
            <p class="cert-pdf__title" id="certLightboxPdfTitle"></p>
            <p class="cert-pdf__note">PDF documents open in their own tab.</p>
            <div class="cert-pdf__actions">
                <a class="btn btn-sm btn-brand" id="certLightboxOpen" target="_blank" rel="noopener" href="#">
                    <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> Open PDF
                </a>
                <a class="btn btn-sm btn-outline-secondary" id="certLightboxDownload" href="#">
                    <i class="fa-solid fa-download" aria-hidden="true"></i> Download
                </a>
            </div>
        </div>

        <figcaption id="certLightboxCap"></figcaption>
    </figure>
</dialog>

<script>
(function () {
    'use strict';

    var box = document.getElementById('certLightbox');

    if (!box || typeof box.showModal !== 'function') { return; }

    var img      = document.getElementById('certLightboxImg');
    var pdf      = document.getElementById('certLightboxPdf');
    var pdfTitle = document.getElementById('certLightboxPdfTitle');
    var openPdf  = document.getElementById('certLightboxOpen');
    var download = document.getElementById('certLightboxDownload');
    var cap      = document.getElementById('certLightboxCap');

    document.addEventListener('click', function (event) {
        var link = event.target.closest && event.target.closest('a[data-cert-view]');

        if (!link) { return; }

        /* A middle or modified click means "open it in a tab" — let it. */
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) { return; }

        event.preventDefault();

        var href    = link.getAttribute('href');
        var caption = link.getAttribute('data-cert-caption') || '';
        var isPdf   = link.getAttribute('data-cert-kind') === 'pdf';

        if (isPdf) {
            img.hidden = true;
            img.removeAttribute('src');
            pdfTitle.textContent = caption.split(' · ')[0] || 'Certificate';
            openPdf.setAttribute('href', href);
            download.setAttribute('href', href + (href.indexOf('?') === -1 ? '?' : '&') + 'download=1');
            pdf.hidden = false;
        } else {
            pdf.hidden = true;
            img.hidden = false;
            img.src = href;
            img.alt = caption || 'Certificate';
        }

        cap.textContent = caption;
        cap.hidden = caption === '';

        box.showModal();
    });

    var shut = function () { if (box.open) { box.close(); } };

    box.addEventListener('click', function (event) {
        /* The close button, or a click on the dim backdrop around the file. */
        if (event.target.closest('[data-cert-close]') || event.target === box) { shut(); }
    });

    /* Emptied on close, so reopening another certificate never flashes the
       previous one while the next image loads. */
    box.addEventListener('close', function () {
        img.removeAttribute('src');
        pdf.hidden = true;
    });
})();
</script>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
