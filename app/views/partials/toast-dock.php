<?php
declare(strict_types=1);

/**
 * TourSync — the flash-message dock, shared by the officer and manager shells.
 *
 * Expects $flashes, already taken from the session by the shell that includes
 * this. Renders server-side so a message survives a page that carries no
 * JavaScript, and so a screen reader announces it on load rather than after a
 * script has run.
 *
 * TOP-LEFT, not the customary top-right. The right of the topbar holds the
 * account block, and on a shared office machine a toast that covers whose
 * session this is has hidden the one thing worth checking before you act.
 *
 * The five-second timer lives in CSS (an animation on .toast__timer), and the
 * script in foot.php only removes the element when it finishes. Doing it that
 * way means hover-to-pause is one CSS line rather than a cleared interval, and
 * a browser with the script blocked still shows the message — it simply stays.
 */

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

$flashes = isset($flashes) && is_array($flashes) ? $flashes : [];
?>
<?php
/* THE SAME MESSAGES, TWICE, ON PURPOSE.
 *
 * assets/js/notify.js reads this block and draws each flash as a SweetAlert2
 * toast, then removes the dock below so the message is not shown twice.
 *
 * The dock is still rendered because that removal only happens if the script
 * runs. With JavaScript off — or blocked, or still loading — the office keeps
 * the plain dock it has always had instead of losing the message entirely.
 * The server remains the only thing that decides what is said. */
?>
<script type="application/json" id="flashData"><?= json_encode(
    array_map(static fn(array $f): array => [
        'type'    => (string) ($f['type'] ?? 'info'),
        'message' => (string) ($f['message'] ?? ''),
    ], $flashes),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?></script>

<div class="toast-dock" id="toastDock" aria-live="polite">
    <?php foreach ($flashes as $flash):
        $type = (string) ($flash['type'] ?? 'info');

        /* Bootstrap-ish names arrive from Session::flash() across the codebase;
           anything unrecognised is shown as information rather than dropped. */
        $tone = match ($type) {
            'success'           => 'success',
            'danger', 'error'   => 'danger',
            'warning'           => 'warning',
            default             => 'info',
        };

        $icon = match ($tone) {
            'success' => 'fa-circle-check',
            'danger'  => 'fa-circle-exclamation',
            'warning' => 'fa-triangle-exclamation',
            default   => 'fa-circle-info',
        };
        ?>
        <?php /* An error is an alert — it interrupts. Everything else is a
                 status and waits its turn in the screen reader's queue. */ ?>
        <div class="toast toast--<?= e($tone) ?>" role="<?= $tone === 'danger' ? 'alert' : 'status' ?>">
            <i class="fa-solid <?= e($icon) ?> toast__icon" aria-hidden="true"></i>
            <div class="toast__body"><?= e((string) ($flash['message'] ?? '')) ?></div>
            <button type="button" class="toast__close" aria-label="Dismiss">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
            <span class="toast__timer" aria-hidden="true"></span>
        </div>
    <?php endforeach; ?>
</div>
