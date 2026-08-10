<?php
declare(strict_types=1);

/**
 * TourSync — manual arrival entry.
 *
 * Not a fallback bolted on late: without it, every visitor who has no
 * smartphone, no battery, or no interest simply vanishes from the statistics,
 * and the Office quietly loses the completeness it had with paper. That would
 * make the system worse than the process it replaces.
 *
 * Records created here are stamped source='manual' with the staff account
 * responsible, so any report can separate self-recorded from staff-recorded
 * arrivals rather than blurring the two.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Session;
use App\Core\Validator;
use App\Repositories\ArrivalRepository;

Auth::require();

$pageTitle    = 'Manual Entry';
$pageIcon     = 'fa-pen';
$pageSubtitle = 'For visitors who cannot use the QR logbook';

$destinations = Database::all("SELECT id, name FROM destinations WHERE status='active' ORDER BY name");

if ($destinations === []) {
    Session::flash('warning', 'Add a destination before recording arrivals.');
    redirect(base_url('/admin/destinations/index.php'));
}

if (is_post()) {
    Csrf::verify();

    $v = new Validator($_POST);
    $v->require('destination_id', 'tourist_type', 'visit_date')
      ->in('tourist_type', array_keys(ArrivalRepository::TYPES))
      ->in('age_bracket', array_keys(ArrivalRepository::AGE_BRACKETS))
      ->in('sex', ['male', 'female', 'prefer_not_to_say'])
      ->in('purpose', array_keys(ArrivalRepository::PURPOSES))
      ->in('stay_type', ['day_trip', 'overnight'])
      ->integer('companions_count', 0, 500)
      ->date('visit_date')
      ->email('email');

    // A visit cannot be recorded for a day that has not happened.
    if ($v->value('visit_date') !== '' && strtotime((string) $v->value('visit_date')) > strtotime('today')) {
        $v->addError('visit_date', 'A visit cannot be recorded for a future date.');
    }

    $destinationExists = Database::scalar(
        "SELECT 1 FROM destinations WHERE id = ? AND status = 'active'",
        [(int) $v->value('destination_id')]
    );
    if ($destinationExists === null) {
        $v->addError('destination_id', 'Choose an active destination.');
    }

    if ($v->fails()) {
        flash_back($v->errors(), $_POST, 'manual.php');
    }

    $visitDate = (string) $v->value('visit_date');

    try {
        $id = ArrivalRepository::record([
            'destination_id'   => (int) $v->value('destination_id'),
            'visit_date'       => $visitDate,
            // Backdated entries land at midday rather than the moment of typing,
            // so the recorded time does not imply precision nobody has.
            'arrived_at'       => $visitDate === date('Y-m-d')
                                    ? date('Y-m-d H:i:s')
                                    : $visitDate . ' 12:00:00',
            'full_name'        => (string) $v->value('full_name', ''),
            'age_bracket'      => (string) $v->value('age_bracket', ''),
            'sex'              => (string) $v->value('sex', ''),
            'contact_number'   => (string) $v->value('contact_number', ''),
            'email'            => (string) $v->value('email', ''),
            'tourist_type'     => (string) $v->value('tourist_type'),
            'stay_type'        => (string) $v->value('stay_type', ''),
            'origin_country'   => (string) $v->value('origin_country', 'Philippines'),
            'origin_province'  => (string) $v->value('origin_province', ''),
            'origin_city'      => (string) $v->value('origin_city', ''),
            'purpose'          => (string) $v->value('purpose', ''),
            'companions_count' => (int) $v->value('companions_count', 0),
            'consent_given'    => !empty($_POST['consent_given']) ? 1 : 0,
            'source'           => 'manual',
            'recorded_by'      => Auth::id(),
            'status'           => 'valid',
        ]);
    } catch (Throwable $e) {
        error_log('Manual arrival failed: ' . $e->getMessage());
        Session::flash('danger', 'The record could not be saved. Please try again.');
        flash_back([], $_POST, 'manual.php');
    }

    $total = 1 + (int) $v->value('companions_count', 0);
    ActivityLog::record('arrival.manual', 'arrival', $id, "Manual entry recorded ({$total} visitor(s))");
    Session::flash('success', "Recorded — {$total} visitor(s) added to the figures for " . format_date($visitDate) . '.');

    // Straight back to a blank form: staff entering a backlog are doing this
    // repeatedly, and a redirect to the list would cost them a click each time.
    redirect(base_url('/admin/arrivals/manual.php'));
}

require __DIR__ . '/../_partials/head.php';
?>

<div class="panel panel--notice">
    <div class="panel__body">
        <h2><i class="fa-solid fa-circle-info"></i> When to use this</h2>
        <p class="mb-0">
            For visitors without a smartphone, without battery or signal, or who decline to use
            the digital logbook — and for encoding a paper logbook the Office already holds.
            Every record made here is marked <strong>Manual</strong> and carries your name, so
            reports can distinguish staff-recorded arrivals from self-recorded ones.
        </p>
    </div>
</div>

<form method="post" class="form-grid" novalidate>
    <?= csrf_field() ?>

    <section class="panel">
        <header class="panel__head"><h2><i class="fa-solid fa-clipboard-list"></i> Visit</h2></header>
        <div class="panel__body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="destination_id" class="form-label">Destination <span class="req">*</span></label>
                    <select id="destination_id" name="destination_id" required
                            class="form-select <?= has_error('destination_id') ? 'is-invalid' : '' ?>">
                        <option value="">Choose…</option>
                        <?php foreach ($destinations as $d): ?>
                            <option value="<?= (int) $d['id'] ?>" <?= old_all()['destination_id'] ?? '' == $d['id'] ? 'selected' : '' ?>>
                                <?= e($d['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (has_error('destination_id')): ?><div class="field-error"><?= e(error_for('destination_id')) ?></div><?php endif; ?>
                </div>

                <div class="col-md-3">
                    <label for="visit_date" class="form-label">Visit date <span class="req">*</span></label>
                    <input type="date" id="visit_date" name="visit_date" required max="<?= date('Y-m-d') ?>"
                           class="form-control <?= has_error('visit_date') ? 'is-invalid' : '' ?>"
                           value="<?= old('visit_date', date('Y-m-d')) ?>">
                    <?php if (has_error('visit_date')): ?><div class="field-error"><?= e(error_for('visit_date')) ?></div><?php endif; ?>
                </div>

                <div class="col-md-3">
                    <label for="companions_count" class="form-label">Companions</label>
                    <input type="number" id="companions_count" name="companions_count" min="0" max="500"
                           class="form-control" value="<?= old('companions_count', '0') ?>">
                    <p class="field-hint">Not counting the respondent.</p>
                </div>

                <div class="col-md-4">
                    <label for="tourist_type" class="form-label">Tourist type <span class="req">*</span></label>
                    <select id="tourist_type" name="tourist_type" required
                            class="form-select <?= has_error('tourist_type') ? 'is-invalid' : '' ?>">
                        <option value="">Choose…</option>
                        <?php foreach (ArrivalRepository::TYPES as $value => $label): ?>
                            <option value="<?= e($value) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (has_error('tourist_type')): ?><div class="field-error"><?= e(error_for('tourist_type')) ?></div><?php endif; ?>
                </div>

                <div class="col-md-4">
                    <label for="stay_type" class="form-label">Stay</label>
                    <select id="stay_type" name="stay_type" class="form-select">
                        <option value="">Not stated</option>
                        <option value="day_trip">Day trip</option>
                        <option value="overnight">Overnight</option>
                    </select>
                    <p class="field-hint">National statistics count these separately.</p>
                </div>

                <div class="col-md-4">
                    <label for="purpose" class="form-label">Purpose</label>
                    <select id="purpose" name="purpose" class="form-select">
                        <option value="">Not stated</option>
                        <?php foreach (ArrivalRepository::PURPOSES as $value => $label): ?>
                            <option value="<?= e($value) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </section>

    <section class="panel">
        <header class="panel__head"><h2><i class="fa-solid fa-map-pin"></i> Origin</h2></header>
        <div class="panel__body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="origin_city" class="form-label">City / municipality</label>
                    <input type="text" id="origin_city" name="origin_city" maxlength="120" class="form-control" value="<?= old('origin_city') ?>">
                </div>
                <div class="col-md-4">
                    <label for="origin_province" class="form-label">Province</label>
                    <input type="text" id="origin_province" name="origin_province" maxlength="120" class="form-control" value="<?= old('origin_province') ?>">
                </div>
                <div class="col-md-4">
                    <label for="origin_country" class="form-label">Country</label>
                    <input type="text" id="origin_country" name="origin_country" maxlength="80" class="form-control" value="<?= old('origin_country', 'Philippines') ?>">
                </div>
            </div>
        </div>
    </section>

    <section class="panel">
        <header class="panel__head"><h2><i class="fa-regular fa-address-card"></i> Visitor Details <span class="text-muted small">optional</span></h2></header>
        <div class="panel__body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="full_name" class="form-label">Name</label>
                    <input type="text" id="full_name" name="full_name" maxlength="160" class="form-control" value="<?= old('full_name') ?>">
                </div>
                <div class="col-md-2">
                    <label for="age_bracket" class="form-label">Age group</label>
                    <select id="age_bracket" name="age_bracket" class="form-select">
                        <option value="">—</option>
                        <?php foreach (ArrivalRepository::AGE_BRACKETS as $value => $label): ?>
                            <option value="<?= e($value) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="sex" class="form-label">Sex</label>
                    <select id="sex" name="sex" class="form-select">
                        <option value="">—</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="contact_number" class="form-label">Contact</label>
                    <input type="text" id="contact_number" name="contact_number" maxlength="40" class="form-control" value="<?= old('contact_number') ?>">
                </div>
                <div class="col-md-2">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" id="email" name="email" maxlength="160"
                           class="form-control <?= has_error('email') ? 'is-invalid' : '' ?>" value="<?= old('email') ?>">
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="consent_given" name="consent_given" value="1">
                        <label class="form-check-label" for="consent_given">
                            The visitor was read the privacy notice and consented to their details being recorded
                        </label>
                        <p class="field-hint">
                            Leave unticked if no personal details were taken — the visit still counts.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="form-actions">
        <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-brand"><i class="fa-solid fa-floppy-disk"></i> Record Arrival</button>
    </div>
</form>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
