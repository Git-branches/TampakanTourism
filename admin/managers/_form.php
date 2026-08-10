<?php
/**
 * Shared manager form for create.php and edit.php.
 * Expects $m (current values) and $destinations.
 */

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

$isEdit = !empty($m['id']);
?>
<form method="post" class="form-grid" novalidate>
    <?= csrf_field() ?>

    <section class="panel">
        <header class="panel__head"><h2><i class="fa-regular fa-address-card"></i> Manager Details</h2></header>
        <div class="panel__body">
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

                <?php if ($isEdit): ?>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                            <?= !empty($m['is_active']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                        <p class="field-hint">
                            Untick when someone leaves the post. Past delivery records keep their name.
                        </p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="form-actions">
        <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-brand">
            <i class="fa-solid fa-floppy-disk"></i> <?= $isEdit ? 'Save Changes' : 'Add Manager' ?>
        </button>
    </div>
</form>
