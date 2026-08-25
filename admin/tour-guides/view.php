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

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <a class="text-muted small" href="<?= e(base_url('/admin/tour-guides/index.php')) ?>">
            <i class="fa-solid fa-arrow-left"></i> Roster
        </a>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-outline-secondary btn-sm"
           href="<?= e(base_url('/admin/tour-guides/edit.php?id=' . $id)) ?>">
            <i class="fa-solid fa-pen"></i> Edit
        </a>
        <?php /* Opens over the record rather than in a new tab. Looking at
                 somebody's ID is a glance, not a departure — and the officer is
                 usually mid-way through checking the details behind it. */ ?>
        <button type="button" class="btn btn-brand btn-sm" data-dialog="idCard">
            <i class="fa-solid fa-id-card"></i> Tour Guide ID
        </button>
    </div>
</div>

<?php if ($effective !== 'active'): ?>
    <?php /* Said once, loudly, at the top. An officer opening this record is
             usually about to assign this person to a visitor. */ ?>
    <div class="alert alert-warning">
        <strong>This guide cannot be assigned.</strong>
        <?= match ($effective) {
            'expired'   => 'Their ID expired on ' . e(format_date((string) $guide['valid_until'], 'F j, Y'))
                         . '. Set a new "valid until" date to reinstate them.',
            'no_id'     => 'No ID has been issued — set a "valid until" date first.',
            'suspended' => 'They are suspended.',
            'revoked'   => 'Their accreditation has been revoked.',
            default     => '',
        } ?>
        <?php if ($guide['status_note']): ?>
            <span class="d-block mt-1"><em><?= e((string) $guide['status_note']) ?></em></span>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-5">
        <section class="panel">
            <header class="panel__head">
                <h2><i class="fa-solid fa-user"></i> Guide</h2>
                <span class="pill pill--<?= $tone ?>"><?= e(Roster::EFFECTIVE[$effective]) ?></span>
            </header>
            <div class="panel__body">
                <div class="d-flex gap-3 mb-3">
                    <?php if ($photo !== null): ?>
                        <img src="<?= e($photo) ?>" alt="" width="84" height="84"
                             style="border-radius:8px; object-fit:cover;">
                    <?php endif; ?>
                    <div>
                        <h3 class="h5 mb-1"><?= e((string) $guide['full_name']) ?></h3>
                        <code><?= e((string) $guide['guide_code']) ?></code>
                    </div>
                </div>

                <table class="table table-sm mb-0">
                    <tr>
                        <th class="text-muted fw-normal" style="width:40%">Valid until</th>
                        <td><?= $guide['valid_until']
                                ? e(format_date((string) $guide['valid_until'], 'F j, Y'))
                                : '<span class="text-muted">not issued</span>' ?></td>
                    </tr>
                    <?php if ($guide['mobile_number']): ?>
                        <tr><th class="text-muted fw-normal">Mobile</th>
                            <td><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $guide['mobile_number']) ?? '') ?>"><?= e((string) $guide['mobile_number']) ?></a></td></tr>
                    <?php endif; ?>
                    <?php if ($guide['email']): ?>
                        <tr><th class="text-muted fw-normal">Email</th><td><?= e((string) $guide['email']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($guide['address']): ?>
                        <tr><th class="text-muted fw-normal">Address</th><td><?= e((string) $guide['address']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($guide['id_issued_at']): ?>
                        <tr><th class="text-muted fw-normal">ID last issued</th>
                            <td><?= e(format_date((string) $guide['id_issued_at'], 'M j, Y')) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($guide['notes']): ?>
                        <tr><th class="text-muted fw-normal">Office notes</th>
                            <td><?= nl2br(e((string) $guide['notes'])) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </section>

        <?php
        /* THE CARD, AND WHY IT CAN OR CANNOT BE PRINTED — on the record, not
         * only inside the card page.
         *
         * There is no "Generate ID" step and there deliberately is not one: the
         * card is drawn from this record every time it is opened, so correcting
         * a name and reprinting IS the regeneration. But a module with no button
         * called "generate" leaves somebody hunting for one, and the two things
         * that actually block a print — no expiry date, no public address — were
         * only discoverable by clicking through and reading a warning.
         *
         * So the blockers are named here, next to the button, before the click. */
        $blockers = [];

        if ($guide['valid_until'] === null || $guide['valid_until'] === '') {
            $blockers[] = [
                'what' => 'No “valid until” date, so there is no card to print.',
                'fix'  => ['Set a date', base_url('/admin/tour-guides/edit.php?id=' . $id)],
            ];
        }

        if ($effective !== 'active' && $effective !== 'no_id') {
            $blockers[] = [
                'what' => 'This guide is ' . strtolower(Roster::EFFECTIVE[$effective])
                        . ', so a printed card would fail verification.',
                'fix'  => ['Change the status', base_url('/admin/tour-guides/edit.php?id=' . $id)],
            ];
        }

        if (!QrService::isPublishable()) {
            $blockers[] = [
                'what' => QrService::unpublishableReason(),
                'fix'  => ['Set the public address', base_url('/admin/settings/index.php')],
            ];
        }
        ?>
        <section class="panel mt-3">
            <header class="panel__head">
                <h2><i class="fa-solid fa-id-card"></i> Tour Guide ID</h2>
            </header>
            <div class="panel__body">
                <p class="text-muted small">
                    There is no separate &ldquo;generate&rdquo; step. The card is built from this
                    record every time it is opened &mdash; so correcting a detail and printing
                    again <em>is</em> how an ID is reissued.
                </p>

                <?php if ($blockers === []): ?>
                    <p class="mb-2">
                        <span class="pill pill--ok">Ready to print</span>
                    </p>
                    <button type="button" class="btn btn-brand btn-sm" data-dialog="idCard">
                        <i class="fa-solid fa-print"></i> Open the card &mdash; front &amp; back
                    </button>
                <?php else: ?>
                    <p class="mb-2"><span class="pill pill--flag">Not ready to print</span></p>
                    <ul class="small mb-2">
                        <?php foreach ($blockers as $b): ?>
                            <li>
                                <?= e((string) $b['what']) ?>
                                <a href="<?= e((string) $b['fix'][1]) ?>"><?= e((string) $b['fix'][0]) ?></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php /* Still openable. An officer rehearsing the layout before the
                             office has settled its public address is doing something
                             reasonable, and the card page repeats the warning. */ ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-dialog="idCard">
                        Preview it anyway
                    </button>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel mt-3">
            <header class="panel__head">
                <h2><i class="fa-solid fa-qrcode"></i> Verification</h2>
            </header>
            <div class="panel__body">
                <p class="text-muted small">
                    The QR code on the front of the card opens this page. It carries a
                    random token, not the guide's record number &mdash; so scanning one
                    card tells nobody anything about the next.
                </p>
                <p class="mb-2">
                    <a href="<?= e($verifyUrl) ?>" target="_blank" rel="noopener"
                       class="text-break small"><?= e($verifyUrl) ?></a>
                </p>

                <form method="post"
                      onsubmit="return confirm('Every card already printed for this guide will stop verifying. Continue?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="rotate">
                    <button class="btn btn-outline-danger btn-sm" type="submit">
                        <i class="fa-solid fa-rotate"></i> Issue a new QR code
                    </button>
                    <span class="form-text d-block mt-1">Use this if a card is lost or stolen.</span>
                </form>
            </div>
        </section>
    </div>

    <div class="col-lg-7">
        <section class="panel">
            <header class="panel__head">
                <h2><i class="fa-solid fa-certificate"></i> Credentials &mdash; typed lines</h2>
            </header>
            <div class="panel__body">
                <?php if ($credentials === []): ?>
                    <p class="text-muted mb-0">
                        None recorded. These are the short lines printed on the back of the card
                        and shown on the verification page &mdash;
                        <a href="<?= e(base_url('/admin/tour-guides/edit.php?id=' . $id)) ?>">type them in</a>.
                        The scanned documents go in <a href="#certificates">Certificates</a> below.
                    </p>
                <?php else: ?>
                    <ul class="mb-0">
                        <?php foreach ($credentials as $c): ?>
                            <li>
                                <?= e((string) $c['label']) ?>
                                <?php if ($c['issuer']): ?>
                                    <span class="cell-sub">&mdash; <?= e((string) $c['issuer']) ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel mt-3" id="certificates">
            <header class="panel__head">
                <h2><i class="fa-solid fa-file-shield"></i> Certificates &mdash; scanned documents</h2>
            </header>
            <div class="panel__body">
                <?php /* Held under storage/, not uploads/. A training certificate
                         carries a private individual's full name and often their
                         birth date; "the URL is long" is obscurity, not access
                         control. They are served through certificate.php, which
                         checks who is asking. */ ?>
                <p class="text-muted small">
                    <strong>This is where files go.</strong> Upload the scan or photograph of the
                    actual certificate here. Readable by signed-in office staff only &mdash; the
                    public verification page lists their names but never the files themselves.
                </p>

                <?php if ($certificates !== []): ?>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm align-middle mb-0">
                            <tbody>
                            <?php foreach ($certificates as $c): ?>
                                <?php
                                $lapsed = $c['expires_on'] !== null
                                    && (string) $c['expires_on'] < date('Y-m-d');
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= e((string) $c['title']) ?></strong>
                                        <?php if ($c['issuer']): ?>
                                            <span class="cell-sub d-block"><?= e((string) $c['issuer']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted">
                                        <?= $c['issued_on'] ? e(format_date((string) $c['issued_on'], 'M j, Y')) : '—' ?>
                                        <?php if ($c['expires_on']): ?>
                                            <span class="d-block <?= $lapsed ? 'text-danger' : '' ?>">
                                                <?= $lapsed ? 'expired ' : 'until ' ?>
                                                <?= e(format_date((string) $c['expires_on'], 'M j, Y')) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener"
                                           href="<?= e(base_url('/admin/tour-guides/certificate.php?id=' . (int) $c['id'])) ?>">
                                            View
                                        </a>
                                        <form method="post" class="d-inline"
                                              onsubmit="return confirm('Delete this certificate and its file?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $id ?>">
                                            <input type="hidden" name="action" value="certificate_delete">
                                            <input type="hidden" name="certificate_id" value="<?= (int) $c['id'] ?>">
                                            <button class="btn btn-outline-danger btn-sm" type="submit">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="certificate">

                    <div class="col-md-6">
                        <label class="form-label small" for="cert_title">Certificate name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" id="cert_title"
                               name="title" maxlength="160" required placeholder="First Aid Training">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small" for="cert_issuer">Issuing organisation</label>
                        <input type="text" class="form-control form-control-sm" id="cert_issuer"
                               name="issuer" maxlength="160" placeholder="Philippine Red Cross">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="cert_issued">Date issued</label>
                        <input type="date" class="form-control form-control-sm" id="cert_issued" name="issued_on">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="cert_expires">Expires</label>
                        <input type="date" class="form-control form-control-sm" id="cert_expires" name="expires_on">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small" for="cert_file">File <span class="text-danger">*</span></label>
                        <input type="file" class="form-control form-control-sm" id="cert_file" name="file"
                               accept="image/jpeg,image/png,application/pdf" required>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-brand btn-sm" type="submit">
                            <i class="fa-solid fa-upload"></i> File certificate
                        </button>
                        <span class="form-text ms-2">JPG, PNG or PDF, up to 8&nbsp;MB.</span>
                    </div>
                </form>
            </div>
        </section>

        <section class="panel mt-3">
            <header class="panel__head">
                <h2><i class="fa-solid fa-triangle-exclamation"></i> Remove</h2>
            </header>
            <div class="panel__body">
                <p class="text-muted small">
                    Removing a guide deletes their credentials and certificate files. Requests
                    they were assigned to keep the name and number they were answered with &mdash;
                    a visitor's record of what they were told must not be rewritten.
                </p>
                <form method="post" action="<?= e(base_url('/admin/tour-guides/index.php')) ?>"
                      onsubmit="return confirm('Remove <?= e(addslashes((string) $guide['full_name'])) ?> from the roster?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="delete">
                    <button class="btn btn-outline-danger btn-sm" type="submit">
                        <i class="fa-solid fa-trash"></i> Remove from roster
                    </button>
                </form>
            </div>
        </section>
    </div>
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
