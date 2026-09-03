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
        Roster::markIssued($id);
        Session::flash('success', 'Recorded as issued today.');
    }

    redirect(base_url('/admin/tour-guides/view.php?id=' . $id));
}

$pageTitle = (string) $guide['full_name'];
$pageIcon  = 'fa-id-card';

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
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('/admin/tour-guides/edit.php?id=' . $id)) ?>">
            <i class="fa-solid fa-pen"></i> Edit
        </a>
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
                                    <?php $lapsed = $c['expires_on'] !== null && (string) $c['expires_on'] < date('Y-m-d'); ?>
                                    <li class="d-flex align-items-start gap-2 mb-2">
                                        <i class="fa-regular fa-file-lines text-muted mt-1"></i>
                                        <span class="flex-grow-1">
                                            <a target="_blank" rel="noopener"
                                               href="<?= e(base_url('/admin/tour-guides/certificate.php?id=' . (int) $c['id'])) ?>">
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

                        <?php /* FOLDED AWAY UNTIL WANTED. An always-open upload form
                                 is 200px of controls competing with the record, on
                                 most of the visits where nobody is filing anything. */ ?>
                        <details>
                            <?php /* list-style:none so the native triangle does not sit
                                     beside a drawn plus — two markers for one control. */ ?>
                            <summary class="small" style="cursor:pointer; color:var(--green); list-style:none">
                                <i class="fa-solid fa-chevron-right" style="font-size:.7em"></i>
                                Add a certificate
                            </summary>

                            <form method="post" enctype="multipart/form-data" class="row g-2 mt-1">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="action" value="certificate">

                                <div class="col-12">
                                    <label class="form-label small mb-1" for="cert_title">Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" id="cert_title"
                                           name="title" maxlength="160" required placeholder="First Aid Training">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small mb-1" for="cert_issuer">Issued by</label>
                                    <input type="text" class="form-control form-control-sm" id="cert_issuer"
                                           name="issuer" maxlength="160" placeholder="Philippine Red Cross">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1" for="cert_issued">Date issued</label>
                                    <input type="date" class="form-control form-control-sm" id="cert_issued" name="issued_on">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1" for="cert_expires">Expires</label>
                                    <input type="date" class="form-control form-control-sm" id="cert_expires" name="expires_on">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small mb-1" for="cert_file">File <span class="text-danger">*</span></label>
                                    <input type="file" class="form-control form-control-sm" id="cert_file" name="file"
                                           accept="image/jpeg,image/png,application/pdf" required>
                                    <div class="form-text">JPG, PNG or PDF, up to 8&nbsp;MB. Office staff only.</div>
                                </div>
                                <div class="col-12">
                                    <button class="btn btn-brand btn-sm" type="submit">File certificate</button>
                                </div>
                            </form>
                        </details>
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

            <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
               href="<?= e(base_url('/admin/tour-guides/id-card.php?id=' . $id)) ?>">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Open in a tab
            </a>

            <?php /* Posts to this page, the way it always did. It used to live in
                     the standalone card page's toolbar, which the dialog has not
                     got. */ ?>
            <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="issued">
                <button type="submit" class="btn btn-sm btn-outline-secondary">Record as issued today</button>
            </form>

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

<?php require __DIR__ . '/../_partials/foot.php'; ?>
