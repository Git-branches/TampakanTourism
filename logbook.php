<?php
declare(strict_types=1);

/**
 * =============================================================================
 *  TourSync — Digital Tourist Logbook        Feature 1 / Problem 1
 * -----------------------------------------------------------------------------
 *  Replaces the paper logbook. Reached only from a scanned QR code, so the
 *  destination is already known and is never presented as a choice.
 *
 *  Design constraints that shaped this form:
 *
 *   · It is filled on a phone, outdoors, often one-handed, sometimes on one
 *     bar of signal. Every field that is not needed for a report was removed.
 *   · Name, contact number, and email are OPTIONAL. Under RA 10173 the
 *     Municipality should collect only what the purpose requires, and the
 *     statistics require tourist type, origin, and party size — none of which
 *     identify anybody.
 *   · Consent is explicit and unticked by default.
 * =============================================================================
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Csrf;
use App\Repositories\ArrivalRepository;
use App\Repositories\DestinationRepository;

$token = (string) ($_GET['token'] ?? '');
$d     = DestinationRepository::findByQrToken($token);

if ($d === null) {
    http_response_code(404);
    redirect(destinations_url());
}

$errors = all_errors();
$old    = old_all();

/** Reads a previously rejected value back into the form. */
function lb_old(string $field, string $default = ''): string
{
    $old = old_all();
    return e((string) ($old[$field] ?? $default));
}

function lb_selected(string $field, string $value): string
{
    $old = old_all();
    return (($old[$field] ?? '') === $value) ? 'selected' : '';
}

function lb_checked(string $field, string $value): string
{
    $old = old_all();
    return (($old[$field] ?? '') === $value) ? 'checked' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Log Your Visit — <?= e($d['name']) ?></title>
<meta name="theme-color" content="#2E7D32">
<link rel="icon" href="<?= e(asset('img/tampakan_logo.jpg')) ?>" sizes="any">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/logbook.css')) ?>">
</head>
<body class="lb-body">

<header class="lb-gov">
    <img src="<?= e(asset('img/tampakan_logo.jpg')) ?>" alt="Seal of the Municipality of Tampakan" width="34" height="34">
    <div>
        <strong>Municipal Tourism Office</strong>
        <span>Municipality of Tampakan, South Cotabato</span>
    </div>
</header>

<main class="lb-shell">

    <div class="lb-card lb-card--head">
        <p class="lb-eyebrow"><i class="fa-solid fa-location-dot"></i> You are logging a visit to</p>
        <h1><?= e($d['name']) ?></h1>
        <p class="lb-muted"><?= e($d['barangay'] ? 'Barangay ' . $d['barangay'] . ', Tampakan' : 'Tampakan, South Cotabato') ?></p>
    </div>

    <?php if ($errors !== []): ?>
        <div class="lb-alert lb-alert--error">
            <i class="fa-solid fa-circle-exclamation"></i>
            <div>
                <strong>Please check the highlighted fields.</strong>
                <?php if (isset($errors['form'])): ?><p><?= e($errors['form']) ?></p><?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(base_url('/api/arrivals/submit.php')) ?>" class="lb-form" id="logbookForm" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($d['qr_token']) ?>">

        <!-- Honeypot. Hidden from people, irresistible to naive bots.
             A submission with this filled is discarded silently. -->
        <div class="lb-hp" aria-hidden="true">
            <label for="website">Website</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>

        <!-- Time the form was rendered. A submission that arrives implausibly
             fast was not typed by a person. -->
        <input type="hidden" name="rendered_at" value="<?= time() ?>">

        <!-- ============ WHO IS VISITING ============ -->
        <section class="lb-card">
            <h2 class="lb-h2"><i class="fa-solid fa-user-group"></i> About Your Visit</h2>

            <div class="lb-field">
                <label for="tourist_type">I am a <span class="lb-req">*</span></label>
                <select id="tourist_type" name="tourist_type" required
                        class="<?= isset($errors['tourist_type']) ? 'is-invalid' : '' ?>">
                    <option value="">Please choose…</option>
                    <?php foreach (ArrivalRepository::TYPES as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= lb_selected('tourist_type', $value) ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['tourist_type'])): ?>
                    <p class="lb-error"><?= e($errors['tourist_type']) ?></p>
                <?php endif; ?>
            </div>

            <div class="lb-field">
                <label for="companions_count">How many people are with you? <span class="lb-req">*</span></label>
                <input type="number" id="companions_count" name="companions_count" min="0" max="200"
                       inputmode="numeric" value="<?= lb_old('companions_count', '0') ?>" required
                       class="<?= isset($errors['companions_count']) ? 'is-invalid' : '' ?>">
                <p class="lb-hint">Not counting yourself. Enter 0 if you are visiting alone.</p>
                <?php if (isset($errors['companions_count'])): ?>
                    <p class="lb-error"><?= e($errors['companions_count']) ?></p>
                <?php endif; ?>
            </div>

            <div class="lb-field">
                <label>Are you staying overnight in Tampakan?</label>
                <div class="lb-choices">
                    <label class="lb-choice">
                        <input type="radio" name="stay_type" value="day_trip" <?= lb_checked('stay_type', 'day_trip') ?>>
                        <span>Day trip only</span>
                    </label>
                    <label class="lb-choice">
                        <input type="radio" name="stay_type" value="overnight" <?= lb_checked('stay_type', 'overnight') ?>>
                        <span>Staying overnight</span>
                    </label>
                </div>
                <p class="lb-hint">
                    National tourism statistics count day visitors and overnight tourists separately.
                </p>
            </div>

            <div class="lb-field">
                <label for="purpose">Main purpose of your visit</label>
                <select id="purpose" name="purpose">
                    <option value="">Prefer not to say</option>
                    <?php foreach (ArrivalRepository::PURPOSES as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= lb_selected('purpose', $value) ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </section>

        <!-- ============ WHERE FROM ============ -->
        <section class="lb-card">
            <h2 class="lb-h2"><i class="fa-solid fa-map-pin"></i> Where Are You From?</h2>

            <div class="lb-row">
                <div class="lb-field">
                    <label for="origin_city">City or municipality</label>
                    <input type="text" id="origin_city" name="origin_city" maxlength="120"
                           value="<?= lb_old('origin_city') ?>" placeholder="e.g. Koronadal">
                </div>
                <div class="lb-field">
                    <label for="origin_province">Province</label>
                    <input type="text" id="origin_province" name="origin_province" maxlength="120"
                           value="<?= lb_old('origin_province') ?>" placeholder="e.g. South Cotabato">
                </div>
            </div>

            <div class="lb-field" id="countryField">
                <label for="origin_country">Country</label>
                <input type="text" id="origin_country" name="origin_country" maxlength="80"
                       value="<?= lb_old('origin_country', 'Philippines') ?>">
            </div>
        </section>

        <!-- ============ OPTIONAL DETAILS ============ -->
        <section class="lb-card">
            <h2 class="lb-h2"><i class="fa-regular fa-address-card"></i> About You <span class="lb-optional">optional</span></h2>
            <p class="lb-muted lb-mb">
                Everything in this section is optional. The statistics do not need it — we ask only
                so we can reach you if something is left behind or an advisory affects your trip.
            </p>

            <div class="lb-field">
                <label for="full_name">Name</label>
                <input type="text" id="full_name" name="full_name" maxlength="160"
                       value="<?= lb_old('full_name') ?>" autocomplete="name">
            </div>

            <div class="lb-row">
                <div class="lb-field">
                    <label for="age_bracket">Age group</label>
                    <select id="age_bracket" name="age_bracket">
                        <option value="">Prefer not to say</option>
                        <?php foreach (ArrivalRepository::AGE_BRACKETS as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= lb_selected('age_bracket', $value) ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="lb-field">
                    <label for="sex">Sex</label>
                    <select id="sex" name="sex">
                        <option value="">Prefer not to say</option>
                        <option value="male"   <?= lb_selected('sex', 'male') ?>>Male</option>
                        <option value="female" <?= lb_selected('sex', 'female') ?>>Female</option>
                    </select>
                </div>
            </div>

            <div class="lb-row">
                <div class="lb-field">
                    <label for="contact_number">Mobile number</label>
                    <input type="tel" id="contact_number" name="contact_number" maxlength="40"
                           inputmode="tel" value="<?= lb_old('contact_number') ?>" placeholder="0917 123 4567"
                           class="<?= isset($errors['contact_number']) ? 'is-invalid' : '' ?>">
                    <?php if (isset($errors['contact_number'])): ?>
                        <p class="lb-error"><?= e($errors['contact_number']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="lb-field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" maxlength="160"
                           inputmode="email" value="<?= lb_old('email') ?>"
                           class="<?= isset($errors['email']) ? 'is-invalid' : '' ?>">
                    <?php if (isset($errors['email'])): ?>
                        <p class="lb-error"><?= e($errors['email']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- ============ PRIVACY AND CONSENT ============ -->
        <section class="lb-card lb-card--privacy">
            <h2 class="lb-h2"><i class="fa-solid fa-shield-halved"></i> Privacy Notice</h2>

            <div class="lb-privacy">
                <p>
                    The Municipal Tourism Office of Tampakan collects this information to count
                    visitor arrivals and prepare tourism reports, in accordance with
                    <strong>Republic Act No. 10173, the Data Privacy Act of 2012</strong>.
                </p>
                <ul>
                    <li>Your visit is counted in municipal statistics. Those figures are anonymous.</li>
                    <li>Your name and contact details, if given, are used only to reach you about this visit.</li>
                    <li>We do not sell, trade, or publish your personal information.</li>
                    <li>You may ask us to correct or delete your details at any time.</li>
                </ul>
            </div>

            <label class="lb-consent <?= isset($errors['consent']) ? 'is-invalid' : '' ?>">
                <input type="checkbox" name="consent" value="1" required>
                <span>I have read the privacy notice and consent to the Municipal Tourism Office
                      recording this visit. <span class="lb-req">*</span></span>
            </label>
            <?php if (isset($errors['consent'])): ?>
                <p class="lb-error"><?= e($errors['consent']) ?></p>
            <?php endif; ?>
        </section>

        <div class="lb-submit">
            <button type="submit" class="lb-btn lb-btn--primary lb-btn--lg" id="submitBtn">
                <i class="fa-solid fa-paper-plane"></i> Submit My Visit
            </button>
            <a href="<?= e(base_url('/d/' . $d['qr_token'])) ?>" class="lb-back">
                <i class="fa-solid fa-arrow-left"></i> Back to destination
            </a>
        </div>
    </form>

    <footer class="lb-foot">
        <p>&copy; <?= date('Y') ?> Municipality of Tampakan, South Cotabato</p>
    </footer>
</main>

<script>
(function () {
    const form    = document.getElementById('logbookForm');
    const button  = document.getElementById('submitBtn');
    const type    = document.getElementById('tourist_type');
    const country = document.getElementById('countryField');

    /* A local or domestic visitor should not have to confirm they are in the
       Philippines; a foreign visitor should not be shown a province box first. */
    function syncOrigin() {
        const foreign = type.value === 'foreign';
        country.style.display = foreign ? '' : 'none';
        if (!foreign) {
            document.getElementById('origin_country').value = 'Philippines';
        }
    }
    type.addEventListener('change', syncOrigin);
    syncOrigin();

    /* Prevents a double submission producing two arrival records when the
       connection is slow — which, at a mountain destination, it usually is. */
    form.addEventListener('submit', function () {
        if (form.checkValidity()) {
            button.disabled = true;
            button.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Submitting…';
        }
    });
})();
</script>
</body>
</html>
