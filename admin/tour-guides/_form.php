<?php
/**
 * The tour guide record — shared by index.php's sheet, create.php and edit.php.
 *
 * $g            the guide's fields, blank on create
 * $isEdit       whether this is an existing record
 * $credentials  rows to prefill the repeating credential boxes
 * $inSheet      rendered inside the roster's <dialog> rather than on its own page
 *
 * ONE FILE, THREE PLACES. Same reasoning as the manager registry: a field added
 * here appears in the sheet and on both full pages without anybody remembering
 * to copy it. The only difference between the three is the wrapper.
 */

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

use App\Repositories\TourGuideRosterRepository as Roster;

$inSheet = !empty($inSheet);
$photo   = uploaded_url((string) ($g['photo_path'] ?? ''));
?>

<?php /* enctype on all three: the photograph is part of this form wherever it
         is rendered, and a dialog posts like any other form. */ ?>
<form method="post" enctype="multipart/form-data" novalidate
      <?= $inSheet ? 'action="create.php" class="sheet__form"' : 'class="form-grid"' ?>>
    <?= csrf_field() ?>

    <?php if ($inSheet): ?>
        <header class="sheet__head">
            <h2><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Add a tour guide</h2>
            <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </header>
        <div class="sheet__body">
    <?php else: ?>
        <section class="panel">
            <header class="panel__head">
                <h2><i class="fa-solid fa-user"></i> Guide Details</h2>
            </header>
            <div class="panel__body">
    <?php endif; ?>

            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label" for="full_name">Full name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control <?= has_error('full_name') ? 'is-invalid' : '' ?>"
                           id="full_name" name="full_name" maxlength="160" required
                           value="<?= e((string) $g['full_name']) ?>">
                    <?php if ($msg = error_for('full_name')): ?>
                        <div class="invalid-feedback d-block"><?= e($msg) ?></div>
                    <?php endif; ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="mobile_number">Mobile number</label>
                    <input type="text" class="form-control <?= has_error('mobile_number') ? 'is-invalid' : '' ?>"
                           id="mobile_number" name="mobile_number" maxlength="20"
                           placeholder="09XX XXX XXXX"
                           value="<?= e((string) $g['mobile_number']) ?>">
                    <?php /* This is the number a visitor is texted on assignment,
                             so it is worth saying so where it is typed. */ ?>
                    <div class="form-text">Texted to the visitor on assignment.</div>
                    <?php if ($msg = error_for('mobile_number')): ?>
                        <div class="invalid-feedback d-block"><?= e($msg) ?></div>
                    <?php endif; ?>
                </div>

                <div class="col-md-8">
                    <label class="form-label" for="address">Address</label>
                    <input type="text" class="form-control" id="address" name="address"
                           maxlength="255" value="<?= e((string) $g['address']) ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="email">Email</label>
                    <input type="email" class="form-control <?= has_error('email') ? 'is-invalid' : '' ?>"
                           id="email" name="email" maxlength="190"
                           value="<?= e((string) $g['email']) ?>">
                    <?php if ($msg = error_for('email')): ?>
                        <div class="invalid-feedback d-block"><?= e($msg) ?></div>
                    <?php endif; ?>
                </div>

                <div class="<?= $photo !== null ? 'col-md-8' : 'col-12' ?>">
                    <label class="form-label" for="photo">Profile picture</label>
                    <input type="file" class="form-control" id="photo" name="photo"
                           accept="image/jpeg,image/png,image/webp">
                    <div class="form-text">
                        <?php /* The real ceiling, not a number typed into a template.
                                 It is the smaller of what the app allows and what
                                 php.ini physically accepts, so this line cannot
                                 promise an upload the server will silently drop. */ ?>
                        JPG, PNG or WebP, up to <?= n(\App\Core\Uploader::maxMegabytes()) ?>&nbsp;MB.
                        Appears on the printed ID and on the public verification page.
                    </div>
                    <?php if ($msg = error_for('photo')): ?>
                        <div class="invalid-feedback d-block"><?= e($msg) ?></div>
                    <?php endif; ?>
                </div>

                <?php if ($photo !== null): ?>
                    <div class="col-md-4 d-flex align-items-end gap-2">
                        <img src="<?= e($photo) ?>" alt="Current photograph" width="64" height="64"
                             style="border-radius:8px; object-fit:cover;">
                        <span class="cell-sub">A new picture replaces this one.</span>
                    </div>
                <?php endif; ?>

                <!-- ---------- validity ---------- -->
                <div class="col-12"><hr class="my-1"></div>

                <div class="col-md-4">
                    <label class="form-label" for="valid_until">Valid until</label>
                    <input type="date" class="form-control <?= has_error('valid_until') ? 'is-invalid' : '' ?>"
                           id="valid_until" name="valid_until"
                           value="<?= e((string) $g['valid_until']) ?>">
                    <?php /* The one field that silently decides assignability. An
                             empty date is not a permanent card — it is no card. */ ?>
                    <div class="form-text">
                        Printed on the ID. Until this is set the guide has no card and
                        <strong>cannot be assigned</strong>.
                    </div>
                    <?php if ($msg = error_for('valid_until')): ?>
                        <div class="invalid-feedback d-block"><?= e($msg) ?></div>
                    <?php endif; ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select" id="status" name="status">
                        <?php foreach (Roster::STATUSES as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= (string) $g['status'] === $key ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">
                        &ldquo;Expired&rdquo; is not on this list &mdash; it is worked out from
                        the date beside it, so it is never out of step.
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="status_note">Reason, if suspended or revoked</label>
                    <input type="text" class="form-control" id="status_note" name="status_note"
                           maxlength="600" value="<?= e((string) $g['status_note']) ?>">
                    <?php /* Kept off the public page deliberately: a stranger
                             scanning a card is owed a yes or a no, not somebody's
                             disciplinary history. */ ?>
                    <div class="form-text">Office only &mdash; never on the verification page.</div>
                </div>

                <!-- ---------- credentials ---------- -->
                <div class="col-12"><hr class="my-1"></div>

                <div class="col-12">
                    <label class="form-label">Credentials <span class="text-muted">&mdash; typed, printed on the ID</span></label>
                    <?php /* "Credentials" and "certificates" are near-synonyms in
                             ordinary English, and an officer who has just been shown
                             both words in one form has no way to tell which box takes
                             their scanned document. So the two are named by what the
                             office DOES with them, not by their dictionary meaning:
                             this one is a typed line, the other is a file. */ ?>
                    <p class="form-text mt-0">
                        Short lines, typed. These are what appear on the back of the card and on
                        the public verification page &mdash; <strong>not</strong> the scanned
                        documents, which are filed separately as certificates.
                    </p>

                    <?php
                    /* Plain repeating boxes rather than an add-a-row widget. A guide
                       carries three or four of these; a handful of empty boxes is
                       less to explain than a button, and it degrades to nothing when
                       JavaScript is off.

                       The placeholders are the real ones a Tampakan guide holds,
                       varied down the column rather than four copies of the word
                       "Qualification". A repeated placeholder teaches nothing; a
                       worked example teaches the format in one glance. */
                    $examples = [
                        ['Tour Guide Accreditation', 'DOT Region XII'],
                        ['First Aid and Basic Life Support', 'Philippine Red Cross'],
                        ['Tourism Training', 'TESDA'],
                        ['Local Heritage Orientation', 'Municipal Tourism Office'],
                        ['Qualification', 'Issued by'],
                        ['Qualification', 'Issued by'],
                    ];

                    $rows  = $credentials ?? [];
                    $boxes = $inSheet ? max(4, count($rows) + 1) : max(6, count($rows) + 2);

                    for ($i = 0; $i < $boxes; $i++):
                        $row     = $rows[$i] ?? ['label' => '', 'issuer' => ''];
                        $example = $examples[$i] ?? ['Qualification', 'Issued by'];
                    ?>
                        <div class="row g-2 mb-2">
                            <div class="col-md-7">
                                <label class="visually-hidden" for="credlabel<?= $i ?>">Credential <?= $i + 1 ?></label>
                                <input type="text" class="form-control form-control-sm" id="credlabel<?= $i ?>"
                                       name="credential_label[]" maxlength="160"
                                       placeholder="e.g. <?= e($example[0]) ?>"
                                       value="<?= e((string) $row['label']) ?>">
                            </div>
                            <div class="col-md-5">
                                <label class="visually-hidden" for="credissuer<?= $i ?>">Issued by</label>
                                <input type="text" class="form-control form-control-sm" id="credissuer<?= $i ?>"
                                       name="credential_issuer[]" maxlength="160"
                                       placeholder="e.g. <?= e($example[1]) ?> (optional)"
                                       value="<?= e((string) ($row['issuer'] ?? '')) ?>">
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>

                <?php /* WHERE THE SCANNED DOCUMENTS GO, said here rather than left
                         to be discovered — because this is the spot where somebody
                         with a certificate in their hand looks for the upload box,
                         and finding nothing reads as a missing feature.

                         A certificate is filed AGAINST a guide, so it needs a guide
                         to exist first. Same two-step the arrival reports use for
                         their photographed logbook pages. */ ?>
                <div class="col-12">
                    <div class="alert alert-light py-2 mb-0 small">
                        <i class="fa-solid fa-file-arrow-up text-muted" aria-hidden="true"></i>
                        <strong>Scanned certificates go elsewhere.</strong>
                        <?php if ($isEdit): ?>
                            They are files, not typed lines, so they live on the guide's record &mdash;
                            <a href="<?= e(base_url('/admin/tour-guides/view.php?id=' . (int) $g['id'])) ?>#certificates">open
                            their certificates</a>.
                        <?php else: ?>
                            They are files, not typed lines. Save this guide and their record opens
                            with the upload box on it &mdash; a file has to belong to somebody
                            before it can be filed.
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ---------- office notes ---------- -->
                <div class="col-12">
                    <label class="form-label" for="notes">
                        Office notes <span class="text-muted">(optional)</span>
                    </label>
                    <?php
                    /* A PROMPTED BOX, NOT A BLANK ONE.
                     *
                     * An empty textarea labelled "notes" collects either nothing or
                     * six officers' six different ideas of what belongs in it, and
                     * neither is searchable or useful when somebody is choosing a
                     * guide for a visitor at the counter.
                     *
                     * The prompt names the things that actually decide an
                     * assignment. If one of these turns out to be filled in every
                     * time — languages is the likely one, since the request form
                     * already asks visitors about it — it has earned its own column
                     * and should be promoted out of here. */
                    ?>
                    <textarea class="form-control" id="notes" name="notes" rows="4" maxlength="600"
                              placeholder="Languages spoken: &#10;Knows these sites best: &#10;Usually available: &#10;Anything else the office should know:"><?= e((string) $g['notes']) ?></textarea>
                    <div class="form-text">
                        Read by officers when choosing who to send. Never shown to visitors
                        and never on the verification page.
                    </div>
                </div>
            </div>

    <?php if ($inSheet): ?>
        </div>

        <footer class="sheet__foot">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close>Cancel</button>
            <button type="submit" class="btn btn-sm btn-brand">
                <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Add guide &amp; issue ID
            </button>
        </footer>
    <?php else: ?>
            </div>
        </section>

        <div class="form-actions">
            <a class="btn btn-outline-secondary"
               href="<?= e($isEdit
                    ? base_url('/admin/tour-guides/view.php?id=' . (int) $g['id'])
                    : base_url('/admin/tour-guides/index.php')) ?>">Cancel</a>
            <button type="submit" class="btn btn-brand">
                <i class="fa-solid fa-floppy-disk"></i>
                <?= $isEdit ? 'Save Changes' : 'Add guide &amp; issue ID' ?>
            </button>
        </div>
    <?php endif; ?>
</form>
