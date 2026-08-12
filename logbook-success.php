<?php
declare(strict_types=1);

/**
 * TourSync — logbook confirmation.
 *
 * Also the natural moment to invite a rating: the visitor has just confirmed
 * they are standing at the destination, which makes this the one review on
 * the internet with evidence behind it. The feedback capture itself is
 * Phase 5; this page prepares its place.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Session;

/* The arrival stays in the session until the visitor either rates or leaves.
   Clearing it here would break the rating form below, which reads the visit
   from the session rather than from a form field — a record id in a hidden
   input could be edited to attach a review to somebody else's visit. */
$arrival = Session::get('_last_arrival');

/* Reaching this page directly, without having just submitted, is not an
   error worth an error page — send them somewhere useful. */
if ($arrival === null && empty($_GET['ok'])) {
    redirect(destinations_url());
}

$destinationName = $arrival['destination'] ?? null;
$destinationId   = (int) ($arrival['destination_id'] ?? 0);
$visitors        = (int) ($arrival['visitors'] ?? 1);
$token           = $arrival['token'] ?? null;
$alreadyRated    = !empty($_GET['rated']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Visit Recorded — Tampakan Tourism</title>
<meta name="theme-color" content="#2E7D32">
<link rel="icon" href="<?= e(asset('img/tampakan_logo.png')) ?>" sizes="any">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/logbook.css')) ?>">
</head>
<body class="lb-body">

<header class="lb-gov">
    <img src="<?= e(asset('img/tampakan_logo.png')) ?>" alt="Seal of the Municipality of Tampakan" width="34" height="34">
    <div>
        <strong>Municipal Tourism Office</strong>
        <span>Municipality of Tampakan, South Cotabato</span>
    </div>
</header>

<main class="lb-shell">

    <div class="lb-card lb-card--center lb-success">
        <div class="lb-icon lb-icon--ok"><i class="fa-solid fa-check"></i></div>

        <h1>Your visit has been recorded</h1>

        <?php if ($destinationName !== null): ?>
            <p class="lb-success__where">
                <?= e($destinationName) ?>
                <span><?= n($visitors) ?> visitor<?= $visitors === 1 ? '' : 's' ?> &middot; <?= e(date('F j, Y')) ?></span>
            </p>
        <?php endif; ?>

        <p class="lb-muted">
            Salamat po. Your visit now counts towards the municipality's tourism figures, which
            help the Tourism Office plan facilities, guides, and services for visitors.
        </p>
    </div>

    <div class="lb-card">
        <h2 class="lb-h2"><i class="fa-solid fa-shield-halved"></i> What happens to your information</h2>
        <ul class="lb-list">
            <li><i class="fa-solid fa-chart-simple"></i>
                Your visit is added to anonymous arrival statistics for this destination.</li>
            <li><i class="fa-solid fa-user-shield"></i>
                Any name or contact details you gave are stored separately and are never published.</li>
            <li><i class="fa-solid fa-trash-can"></i>
                You may ask the Tourism Office to delete your details at any time.</li>
        </ul>
    </div>

    <?php if ($destinationId > 0 && !$alreadyRated): ?>
    <div class="lb-card">
        <h2 class="lb-h2"><i class="fa-regular fa-star"></i> How was your visit?</h2>
        <p class="lb-muted lb-mb">
            Optional — but a rating from someone who actually made the trip is worth far more
            than an anonymous one, and it tells the Tourism Office what is working.
        </p>

        <form method="post" action="<?= e(base_url('/api/feedback/submit.php')) ?>" id="rateForm">
            <?= csrf_field() ?>
            <input type="hidden" name="destination_id" value="<?= $destinationId ?>">
            <input type="hidden" name="rendered_at" value="<?= time() ?>">

            <div class="lb-hp" aria-hidden="true">
                <label for="fb_website">Website</label>
                <input type="text" id="fb_website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <!-- Radio inputs rather than a JavaScript widget: the stars stay
                 operable by keyboard and screen reader, and still work if the
                 script never loads on a weak mountain connection. -->
            <fieldset class="lb-stars">
                <legend class="lb-field-label">Your rating <span class="lb-req">*</span></legend>
                <div class="lb-stars__row">
                    <?php for ($s = 5; $s >= 1; $s--): ?>
                        <input type="radio" id="star<?= $s ?>" name="rating" value="<?= $s ?>" required>
                        <label for="star<?= $s ?>" title="<?= $s ?> star<?= $s === 1 ? '' : 's' ?>">
                            <i class="fa-solid fa-star"></i>
                            <span class="visually-hidden"><?= $s ?> stars</span>
                        </label>
                    <?php endfor; ?>
                </div>
            </fieldset>

            <div class="lb-field">
                <label for="comment">Comments</label>
                <textarea id="comment" name="comment" rows="4" maxlength="1000"
                          placeholder="What did you enjoy? Is there anything the Tourism Office should improve?"></textarea>
            </div>

            <div class="lb-field">
                <label for="visitor_name">Your name <span class="lb-optional">optional</span></label>
                <input type="text" id="visitor_name" name="visitor_name" maxlength="120"
                       placeholder="Shown with your review if you give it">
            </div>

            <button type="submit" class="lb-btn lb-btn--primary lb-btn--lg">
                <i class="fa-regular fa-paper-plane"></i> Send My Review
            </button>

            <p class="lb-hint lb-mt">
                Reviews are read by the Municipal Tourism Office before they appear publicly.
                Negative reviews are published too — only abuse and spam are removed.
            </p>
        </form>
    </div>
    <?php elseif ($alreadyRated): ?>
    <div class="lb-card lb-card--center">
        <h2 class="lb-h2"><i class="fa-solid fa-check"></i> Review received</h2>
        <p class="lb-muted">Thank you. It will appear on the destination page once reviewed.</p>
    </div>
    <?php endif; ?>

    <div class="lb-actions">
        <?php if ($token !== null): ?>
            <a href="<?= e(base_url('/d/' . $token)) ?>" class="lb-btn lb-btn--ghost">
                <i class="fa-solid fa-arrow-left"></i> Back to destination
            </a>
        <?php endif; ?>
        <a href="<?= e(destinations_url()) ?>" class="lb-btn lb-btn--primary">
            <i class="fa-solid fa-compass"></i> Explore more of Tampakan
        </a>
    </div>

    <footer class="lb-foot">
        <p>&copy; <?= date('Y') ?> Municipality of Tampakan, South Cotabato</p>
        <p><a href="<?= e(base_url('/')) ?>">Tampakan Tourism Portal</a></p>
    </footer>
</main>

</body>
</html>
