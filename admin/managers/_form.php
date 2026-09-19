<?php
/**
 * Shared manager form for create.php and edit.php.
 * Expects $m (current values) and $destinations.
 */

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

$isEdit = !empty($m['id']);

/* THE SAME FIELDS IN THREE PLACES.
 *
 * create.php and edit.php render this as a full page; the registry renders it
 * inside a dialog behind the Add Manager button. Only the chrome differs, and
 * it differs here rather than in a second copy of eleven form fields that
 * would drift the first time one of them changed. */
$inSheet = !empty($inSheet);
?>
<form method="post"
      <?= $inSheet ? 'action="create.php" class="sheet__form"' : 'class="form-grid"' ?> novalidate>
    <?= csrf_field() ?>

    <?php if ($inSheet): ?>
        <header class="sheet__head">
            <h2><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Add a destination manager</h2>
            <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </header>
        <div class="sheet__body">
    <?php else: ?>
    <section class="panel">
        <header class="panel__head"><h2><i class="fa-regular fa-address-card"></i> Manager Details</h2></header>
        <div class="panel__body">
    <?php endif; ?>
            <div class="row g-3">

                <div class="col-md-6">
                    <label for="full_name" class="form-label">Full name <span class="req">*</span></label>
                    <input type="text" id="full_name" name="full_name" required maxlength="120"
                           class="form-control <?= has_error('full_name') ? 'is-invalid' : '' ?>"
                           value="<?= e((string) ($m['full_name'] ?? '')) ?>">
                    <?php if (has_error('full_name')): ?><div class="field-error"><?= e(error_for('full_name')) ?></div><?php endif; ?>
                </div>

                <div class="col-md-6">
                    <label for="position" class="form-label">Position</label>
                    <input type="text" id="position" name="position" maxlength="120" class="form-control"
                           value="<?= e((string) ($m['position'] ?? '')) ?>"
                           placeholder="e.g. Site Caretaker, Barangay Tourism Officer">
                </div>

                <div class="col-md-6">
                    <label for="destination_id" class="form-label">Destination <span class="req">*</span></label>
                    <select id="destination_id" name="destination_id" required
                            class="form-select <?= has_error('destination_id') ? 'is-invalid' : '' ?>">
                        <option value="">Choose...</option>
                        <?php foreach ($destinations as $d): ?>
                            <option value="<?= (int) $d['id'] ?>"
                                <?php if (!$isEdit && !empty($d['slug'])): ?>
                                    data-username="manager.<?= e(mb_substr((string) $d['slug'], 0, 48)) ?>"
                                <?php endif; ?>
                                <?= (int) ($m['destination_id'] ?? 0) === (int) $d['id'] ? 'selected' : '' ?>>
                                <?= e($d['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (has_error('destination_id')): ?><div class="field-error"><?= e(error_for('destination_id')) ?></div><?php endif; ?>
                </div>

                <div class="col-md-6">
                    <label for="mobile_number" class="form-label">Mobile number <span class="req">*</span></label>
                    <input type="tel" id="mobile_number" name="mobile_number" required maxlength="20"
                           class="form-control <?= has_error('mobile_number') ? 'is-invalid' : '' ?>"
                           value="<?= e((string) ($m['mobile_number'] ?? '')) ?>"
                           placeholder="0917 123 4567">
                    <p class="field-hint">
                        Stored in international format. 0917..., +63917..., and 63917... are all accepted.
                    </p>
                    <?php if (has_error('mobile_number')): ?><div class="field-error"><?= e(error_for('mobile_number')) ?></div><?php endif; ?>
                </div>

                <div class="col-md-6">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" id="email" name="email" maxlength="160"
                           class="form-control <?= has_error('email') ? 'is-invalid' : '' ?>"
                           value="<?= e((string) ($m['email'] ?? '')) ?>">
                    <p class="field-hint">Optional. The system notifies by SMS; this is for the office records.</p>
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="sms_opt_in" name="sms_opt_in" value="1"
                            <?= !empty($m['sms_opt_in']) || !$isEdit ? 'checked' : '' ?>>
                        <label class="form-check-label" for="sms_opt_in">
                            <strong>Send SMS notifications to this number</strong>
                        </label>
                        <p class="field-hint">
                            Untick if the manager has asked not to be texted. They stay on record and
                            still appear in the registry, but receive no messages.
                        </p>
                    </div>
                </div>

                <?php
                /* THE SIGN-IN, MADE WITH THE ACCOUNT.
                 *
                 * Adding a manager used to create a contact card and nothing
                 * else; the sign-in was a second visit to Access that nobody knew
                 * to make, and a manager was told "you have an account" and then
                 * could not sign in. Only the username is asked for. The password
                 * is generated on save, shown once, and must be replaced by the
                 * manager at first sign-in.
                 *
                 * Officer only, like Access: a sign-in files figures that become
                 * the municipality's official statistics. */
                ?>
                <?php if (!$isEdit && \App\Core\Auth::isOfficer()): ?>
                <div class="col-12">
                    <label for="username" class="form-label">Sign-in username</label>
                    <input type="text" id="username" name="username" maxlength="60"
                           autocomplete="off" autocapitalize="none" spellcheck="false"
                           class="form-control <?= has_error('username') ? 'is-invalid' : '' ?>"
                           value="<?= e((string) ($m['username'] ?? '')) ?>"
                           placeholder="manager.destination-name" data-username-for="destination_id">
                    <p class="field-hint">
                        Left blank, it is made from the destination &mdash; <code>manager.kolondatal</code>.
                        A temporary password is generated when you save and shown to you once; the
                        manager replaces it the first time they sign in.
                    </p>
                    <?php if (has_error('username')): ?><div class="field-error"><?= e(error_for('username')) ?></div><?php endif; ?>
                </div>

                <script>
                /* The placeholder follows the destination, so the blank field says
                   exactly what it will become. Only the placeholder: a name the
                   officer typed is never overwritten. */
                (function () {
                    var field  = document.querySelector('[data-username-for]');
                    var select = field && document.getElementById(field.getAttribute('data-username-for'));
                    if (!select) { return; }
                    function follow() {
                        var chosen = select.options[select.selectedIndex];
                        var name   = chosen && chosen.getAttribute('data-username');
                        field.placeholder = name || 'manager.destination-name';
                    }
                    select.addEventListener('change', follow);
                    follow();
                })();
                </script>
                <?php endif; ?>

                <?php if ($isEdit): ?>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                            <?= !empty($m['is_active']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                        <p class="field-hint">
                            Untick when someone leaves the post. They are signed out at once and cannot
                            sign in again; past reports and delivery records keep their name.
                        </p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
    <?php if ($inSheet): ?>
        </div>

        <footer class="sheet__foot">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close>Cancel</button>
            <button type="submit" class="btn btn-sm btn-brand">
                <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Add Manager
            </button>
        </footer>
    <?php else: ?>
        </div>
    </section>

    <div class="form-actions">
        <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-brand">
            <i class="fa-solid fa-floppy-disk"></i> <?= $isEdit ? 'Save Changes' : 'Add Manager' ?>
        </button>
    </div>
    <?php endif; ?>
</form>
