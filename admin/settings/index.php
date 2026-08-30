<?php
declare(strict_types=1);

/**
 * TourSync — system settings.       Officer only.
 *
 * These are the values the Office should be able to change without a
 * developer: the letterhead on its reports, how long personal data is kept,
 * and the thresholds that decide when an arrival is flagged. Anything that
 * belongs to deployment — database credentials, file paths — stays in
 * config.php where a web form cannot reach it.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Session;
use App\Core\Uploader;
use App\Core\SmsGateway;
use App\Core\Validator;
use App\Repositories\HeroSlideRepository as Hero;

Auth::require('officer');

$pageTitle    = 'Settings';
$pageIcon     = 'fa-gear';
$pageSubtitle = 'Office profile, data retention, and record thresholds';

/** The full set of editable settings, with the rules that guard each. */
$editable = [
    'office_name'         => ['label' => 'Office name',            'type' => 'text', 'max' => 120],
    'office_municipality' => ['label' => 'Municipality',           'type' => 'text', 'max' => 120],
    'office_province'     => ['label' => 'Province',               'type' => 'text', 'max' => 120],
    'office_address'      => ['label' => 'Office address',         'type' => 'text', 'max' => 255],
    'office_phone'        => ['label' => 'Telephone',              'type' => 'text', 'max' => 60],
    'office_email'        => ['label' => 'Email address',          'type' => 'email','max' => 160],

    /* Printed on the back of the tour guide ID. A page NAME rather than a URL:
       it is read off a laminated card by somebody who will then type it into
       the Facebook search box, and a full https:// address is longer, harder to
       read at 2 mm, and no easier to act on. */
    'office_facebook'     => ['label' => 'Facebook page',          'type' => 'text', 'max' => 160],

    /* THE HOMEPAGE HERO IS NO LONGER HERE.
       It was nine keys in this list plus three file fields — the words on three
       slides and nothing else. The office could not add a fourth, could not hold
       one back while its photograph was still being taken, and could not put the
       dry-season picture first in March. It is a table now; see
       App\Repositories\HeroSlideRepository and the hero_* branch below.

       Deliberately NOT in $editable: everything in this list is written from
       `$_POST[$key] ?? ''` on every save, and a slide is a record with its own
       actions, not a value on a form that posts all of itself at once. */

    /* Printed on the tour guide receipt so a visitor knows when they can turn
       up. It had no field on this page at all until now: the code asked for it,
       found nothing, and quietly said nothing. */
    'office_hours'        => ['label' => 'Opening hours',          'type' => 'text', 'max' => 120],

    /* The one setting that gets printed onto physical objects.
       Everything else here can be corrected with a page refresh; this ends up
       laminated on a post at a waterfall, so it is stated deliberately once
       rather than inferred from whichever hostname an officer happened to be
       working on. See QrService::url(). */
    'public_url'          => ['label' => 'Public website address (used by printed QR codes)', 'type' => 'url', 'max' => 200],

    /* Municipal emergency numbers, shown on every destination's QR page.
       Settings rather than columns on destinations: the police station has one
       number for the whole municipality, and holding it in twenty destination
       records is twenty places to correct when it changes — and nineteen
       chances to miss one. */
    'hotline_emergency'   => ['label' => 'Emergency (911 / national)',  'type' => 'text', 'max' => 60],
    'hotline_police'      => ['label' => 'Police station',             'type' => 'text', 'max' => 60],
    'hotline_medical'     => ['label' => 'Rural Health Unit / hospital','type' => 'text', 'max' => 60],
    'hotline_rescue'      => ['label' => 'MDRRMO / rescue',            'type' => 'text', 'max' => 60],
    'hotline_fire'        => ['label' => 'Fire station',               'type' => 'text', 'max' => 60],
    'hotline_tourism'     => ['label' => 'Municipal Tourism Office',   'type' => 'text', 'max' => 60],

    /* WHO IS TEXTED WHEN SOMETHING NEEDS THE OFFICE.
       Urgent destination alerts and every tour guide request go to the officers
       who have opted in on My Account, PLUS whatever is listed here — numbers
       with no account behind them: the office landline, a barangay captain,
       the MDRRMO duty phone.

       It had no field anywhere in the system, so the only way to reach it was
       to edit the database by hand. */
    'alert_sms_recipients' => ['label' => 'Extra numbers to text', 'type' => 'text', 'max' => 400],

    /* Shown on EVERY destination's QR page, unlike destinations.cultural_heritage
       which describes one place. Same words at every sign, because it is the
       same municipality. 'text' with a large max — the validator only measures
       length, and the textarea is chosen at render time. */
    'municipal_heritage_title' => ['label' => 'Heading', 'type' => 'text', 'max' => 120],
    'municipal_heritage'       => ['label' => 'The text itself', 'type' => 'text', 'max' => 4000],

    'retention_months'    => ['label' => 'Retain personal data for (months)', 'type' => 'int', 'min' => 6,  'max' => 120],
    'dedupe_window_hours' => ['label' => 'Duplicate detection window (hours)','type' => 'int', 'min' => 1,  'max' => 72],
    'rate_limit_per_15m'  => ['label' => 'Logbook submissions allowed per 15 minutes', 'type' => 'int', 'min' => 3, 'max' => 100],
    'proximity_metres'    => ['label' => 'Flag arrivals beyond this distance (metres)','type' => 'int', 'min' => 100, 'max' => 5000],
];

if (is_post()) {
    Csrf::verify();

    /* THE HERO SLIDE ACTIONS, HANDLED FIRST AND ALWAYS ENDING IN A REDIRECT.
     *
     * This branch MUST come before the settings loop below, and must never fall
     * through into it. That loop walks every key in $editable and writes
     * `$_POST[$key] ?? ''` — so a request that carries only `action=hero_delete`
     * and an id would save an empty string over the office name, the address,
     * every hotline and the retention window. The same trap that decided the
     * tabs are buttons rather than links, arriving from the other direction.
     *
     * Each case redirects, so there is one exit and no way to reach the settings
     * save by accident. */
    $action = (string) ($_POST['action'] ?? '');

    if (str_starts_with($action, 'hero_')) {
        $back = base_url('/admin/settings/index.php') . '#public';
        $id   = (int) ($_POST['id'] ?? 0);

        /* Anything addressing a specific slide is checked once, here, rather
           than in five places that could each forget. */
        $slide = $id > 0 ? Hero::find($id) : null;

        if ($action !== 'hero_create' && $action !== 'hero_reorder' && $slide === null) {
            Session::flash('danger', 'That slide no longer exists. Somebody may have deleted it.');
            redirect($back);
        }

        /* Uploads go to uploads/banners through Uploader, which re-encodes
           through GD — an image with something smuggled into its metadata does
           not survive — and stores under a random name rather than whatever the
           browser sent. Shared by create and update. */
        $storeImage = static function (string $field) use ($back): ?string {
            if (($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                return null;
            }

            $uploader = new Uploader();
            $stored   = $uploader->store($_FILES[$field], 'banners');

            if ($stored === null) {
                Session::flash('danger', $uploader->firstError() ?? 'That image could not be saved.');
                redirect($back);
            }

            return $stored;
        };

        $words = [
            'eyebrow' => trim((string) ($_POST['eyebrow'] ?? '')),
            'title'   => trim((string) ($_POST['title']   ?? '')),
            'body'    => trim((string) ($_POST['body']    ?? '')),
            'status'  => (string) ($_POST['status'] ?? 'published'),
        ];

        switch ($action) {
            case 'hero_create':
                if ($words['title'] === '') {
                    Session::flash('danger', 'A slide needs a title. It is the large line a visitor reads first.');
                    redirect($back);
                }

                $newId = Hero::create($words);
                $image = $storeImage('image');

                if ($image !== null) {
                    Hero::replaceImage($newId, $image);
                }

                ActivityLog::record('hero.create', 'hero_slide', $newId, 'Added slide: ' . $words['title']);
                Session::flash('success', 'Slide added.');
                break;

            case 'hero_update':
                if ($words['title'] === '') {
                    Session::flash('danger', 'A slide needs a title. It is the large line a visitor reads first.');
                    redirect($back);
                }

                Hero::update($id, $words);

                /* The words are saved BEFORE the photograph is touched. If the
                   upload is rejected the caption edit still landed, rather than
                   both being thrown away over a file that was too large. */
                $image = $storeImage('image');

                if ($image !== null) {
                    Hero::replaceImage($id, $image);
                } elseif (!empty($_POST['remove_image'])) {
                    Hero::clearImage($id);
                }

                ActivityLog::record('hero.update', 'hero_slide', $id, 'Edited slide: ' . $words['title']);
                Session::flash('success', 'Slide saved.');
                break;

            case 'hero_status':
                $next = ((string) $slide['status']) === 'published' ? 'draft' : 'published';
                Hero::setStatus($id, $next);
                ActivityLog::record('hero.status', 'hero_slide', $id,
                    'Slide "' . $slide['title'] . '" set to ' . $next);
                Session::flash('success', $next === 'published'
                    ? 'Slide published. It is on the homepage now.'
                    : 'Slide moved to draft. It is off the homepage but nothing was deleted.');
                break;

            case 'hero_duplicate':
                $copyId = Hero::duplicate($id);
                ActivityLog::record('hero.duplicate', 'hero_slide', $copyId, 'Copied slide ' . $id);
                Session::flash('success', 'Slide copied. The copy is a draft until you publish it.');
                break;

            case 'hero_delete':
                Hero::delete($id);
                ActivityLog::record('hero.delete', 'hero_slide', $id, 'Deleted slide: ' . $slide['title']);
                Session::flash('success', 'Slide deleted.');
                break;

            case 'hero_reorder':
                Hero::reorder((array) ($_POST['order'] ?? []));
                ActivityLog::record('hero.reorder', 'hero_slide', null, 'Reordered the homepage slides');
                Session::flash('success', 'New order saved.');
                break;
        }

        redirect($back);
    }

    /* THE "ABOUT THE OFFICE" BLOCK, ON THE SAME TERMS AS THE HERO.
     *
     * Its own action, handled before the settings loop and ending in a redirect,
     * for the same reason: that loop writes `$_POST[$key] ?? ''` across every key
     * in $editable, so a request carrying only this panel's fields would blank
     * the office name, the hotlines and the retention window.
     *
     * The values are settings rows rather than a table — one block of a fixed
     * shape, not a list — but they are deliberately NOT in $editable, because
     * they are saved by this panel's own button and must not be touched by the
     * page-wide Save. */
    if ($action === 'about_save') {
        $back = base_url('/admin/settings/index.php') . '#public';

        /* The words. Trimmed and clipped to something a layout can hold; the
           lengths are the column's, not an opinion about writing. */
        $fields = [
            'about_eyebrow'       => 60,
            'about_title'         => 80,
            'about_title_em'      => 80,
            'about_lead'          => 900,
            'about_badge_value'   => 30,
            'about_badge_label'   => 80,
            'about_mission_title' => 60,
            'about_mission_text'  => 700,
            'about_vision_title'  => 60,
            'about_vision_text'   => 700,
        ];

        $changed = [];

        foreach ($fields as $key => $max) {
            $value = trim((string) ($_POST[$key] ?? ''));

            /* mb_substr, not substr: this copy carries en dashes and the odd
               ñ, and cutting a multi-byte character in half stores a broken
               sequence that MySQL rejects — a save that fails silently. */
            if (mb_strlen($value) > $max) {
                $value = mb_substr($value, 0, $max);
            }

            if ((string) setting($key, '') !== $value) {
                $changed[$key] = $value;
            }
        }

        foreach ($changed as $key => $value) {
            Database::run(
                'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                [$key, $value]
            );
        }

        /* The two photographs, each replacing the file it supersedes only AFTER
           the new one is safely on disk and the row points at it. */
        foreach (['about_image_main' => 'main', 'about_image_small' => 'small'] as $key => $field) {
            $file = 'image_' . $field;

            if (($_FILES[$file]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $uploader = new Uploader();
                $stored   = $uploader->store($_FILES[$file], 'banners');

                if ($stored === null) {
                    Session::flash('danger', ucfirst($field) . ' photograph: '
                        . ($uploader->firstError() ?? 'that image could not be saved.'));
                    redirect($back);
                }

                $previous = trim((string) (setting($key, '') ?? ''));

                Database::run(
                    'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                    [$key, $stored]
                );

                if ($previous !== '' && $previous !== $stored) {
                    Uploader::delete($previous);
                }

                $changed[$key] = $stored;
            } elseif (!empty($_POST['remove_' . $field])) {
                $previous = trim((string) (setting($key, '') ?? ''));

                Database::run("UPDATE settings SET setting_value = '' WHERE setting_key = ?", [$key]);

                if ($previous !== '') {
                    Uploader::delete($previous);
                }

                $changed[$key] = '';
            }
        }

        if ($changed !== []) {
            ActivityLog::record('settings.about', 'settings', null,
                'Updated the About section: ' . implode(', ', array_keys($changed)));
            Session::flash('success', 'The About section was saved.');
        } else {
            Session::flash('info', 'Nothing changed in the About section.');
        }

        redirect($back);
    }

    $v = new Validator($_POST);
    $changes = [];

    foreach ($editable as $key => $rules) {
        $value = trim((string) ($_POST[$key] ?? ''));

        if ($rules['type'] === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $v->addError($key, 'Enter a valid email address.');
            continue;
        }

        if ($rules['type'] === 'url' && $value !== '') {
            $value = rtrim($value, '/');

            if (!filter_var($value, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $value)) {
                $v->addError($key, 'Enter the full address including http:// or https://.');
                continue;
            }

            /* Rejected at the point of entry rather than at the point of
               printing. By the time a poster is on a wall it is too late to
               discover the address only worked on the office computer. */
            $host = strtolower((string) parse_url($value, PHP_URL_HOST));

            if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
                $v->addError($key, 'This address only works on this computer. Printed QR codes carrying it would open nothing on a visitor\'s phone.');
                continue;
            }
        }

        if ($rules['type'] === 'int') {
            $n = filter_var($value, FILTER_VALIDATE_INT);
            if ($n === false || $n < $rules['min'] || $n > $rules['max']) {
                $v->addError($key, sprintf('Enter a whole number between %d and %d.', $rules['min'], $rules['max']));
                continue;
            }
            $value = (string) $n;
        }

        if ($rules['type'] === 'text' && mb_strlen($value) > $rules['max']) {
            $v->addError($key, 'That is longer than ' . $rules['max'] . ' characters.');
            continue;
        }

        if ((string) setting($key, '') !== $value) {
            $changes[$key] = $value;
        }
    }

    if ($v->fails()) {
        flash_back($v->errors(), $_POST, 'index.php');
    }

    foreach ($changes as $key => $value) {
        Database::run(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$key, $value]
        );
    }

    if ($changes !== []) {
        // The keys are logged, never the values — a setting could hold an
        // address or a phone number, and the audit log is read by more people
        // than the settings screen is.
        ActivityLog::record('settings.update', 'settings', null,
            'Updated: ' . implode(', ', array_keys($changes)));
        Session::flash('success', count($changes) . ' setting(s) saved.');
    } else {
        Session::flash('info', 'Nothing changed.');
    }

    redirect(base_url('/admin/settings/index.php'));
}

$dbVersion = Database::scalar('SELECT VERSION()');
$slides    = Hero::all();
/* The section header is shared with accounts.php and account/index.php, so it is
   a function in a partial rather than a closure owned by this file. */
require __DIR__ . "/../_partials/section-head.php";

require __DIR__ . '/../_partials/head.php';
?>

<?php /* The strip is shared with accounts.php and account/index.php now, so it
         lives in a partial. It renders the five sections of THIS page as buttons
         and the two separate pages as links — the reasoning for that split is
         written where the decision is made, in the partial itself. */ ?>
<?php $settingsTab = 'office'; require __DIR__ . '/../_partials/settings-tabs.php'; ?>

<div class="panel-row">
    <?php /* Named, so the tab script can tell whether this column has anything on
             the current tab. The right rail already had .panel-stack; this one was
             an anonymous <div> and the script could only ever check one side. */ ?>
    <div class="panel-main">
        <?php /* id, because the Save button no longer lives inside this form.
                 It sits in a bar fixed to the bottom of the screen and reaches
                 back with form="settingsForm" — which is what keeps it in the
                 same place on every tab instead of following the end of
                 whichever panels happen to be visible. */ ?>
        <form method="post" id="settingsForm" class="form-grid" novalidate>
            <?= csrf_field() ?>

            <section class="panel" data-settab="office">
                <?php section_head('fa-building-columns', 'Office Profile', 'Appears on report letterheads, the SMS signature, and the public footer.') ?>
                <div class="panel__body">
                    <?php /* The sentence that was here has moved into the section
                             head above. Said twice, three inches apart, it read
                             like a page that had been assembled rather than
                             written. */ ?>
                    <div class="row g-3">
                        <?php foreach (['office_name', 'office_municipality', 'office_province', 'office_address', 'office_phone', 'office_email', 'office_hours', 'office_facebook'] as $key):
                            $rules = $editable[$key]; ?>
                            <div class="col-md-<?= $key === 'office_address' ? '12' : '6' ?>">
                                <label for="<?= e($key) ?>" class="form-label"><?= e($rules['label']) ?></label>
                                <input type="<?= $rules['type'] === 'email' ? 'email' : 'text' ?>"
                                       id="<?= e($key) ?>" name="<?= e($key) ?>" maxlength="<?= (int) $rules['max'] ?>"
                                       class="form-control <?= has_error($key) ? 'is-invalid' : '' ?>"
                                       value="<?= old($key, (string) setting($key, '')) ?>">
                                <?php if (has_error($key)): ?><div class="field-error"><?= e(error_for($key)) ?></div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="panel" data-settab="alerts">
                <?php section_head('fa-phone-volume', 'Emergency Hotlines',
                    'Tap-to-call numbers shown on every destination&rsquo;s QR page.') ?>
                <div class="panel__body">
                    <p class="text-muted small mb-3">
                        Shown at the top of every destination's QR page, as tap-to-call numbers. A visitor
                        reading them is standing at the site, so these must be numbers that are answered.
                        <strong>Leave a line blank if you are not sure of it</strong> &mdash; an unanswered
                        number printed on a sign at a waterfall is worse than no number at all, because
                        somebody will dial it in an emergency and wait.
                    </p>

                    <div class="row g-3">
                        <?php foreach ([
                            'hotline_emergency', 'hotline_police', 'hotline_medical',
                            'hotline_rescue', 'hotline_fire', 'hotline_tourism',
                        ] as $key):
                            $rules = $editable[$key]; ?>
                            <div class="col-md-6">
                                <label for="<?= e($key) ?>" class="form-label"><?= e($rules['label']) ?></label>
                                <input type="text" id="<?= e($key) ?>" name="<?= e($key) ?>"
                                       maxlength="<?= (int) $rules['max'] ?>"
                                       placeholder="0917 123 4567"
                                       class="form-control <?= has_error($key) ? 'is-invalid' : '' ?>"
                                       value="<?= old($key, (string) setting($key, '')) ?>">
                                <?php if (has_error($key)): ?><div class="field-error"><?= e(error_for($key)) ?></div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php
                    /* WHO ACTUALLY GETS TEXTED — counted, not described.
                       "Nobody" is the state this installation was in, and no
                       screen anywhere said so. */
                    $reachable = [];

                    try {
                        $reachable = \App\Repositories\AlertRepository::officeRecipients();
                    } catch (\Throwable $e) {
                        $reachable = [];
                    }
                    ?>

                    <hr class="my-4">

                    <h3 class="h6 mb-2"><i class="fa-solid fa-comment-sms"></i> Who the system texts</h3>

                    <?php if ($reachable === []): ?>
                        <div class="alert alert-danger">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <div>
                                <strong>Nobody is reachable by SMS.</strong>
                                <p class="mb-0">
                                    Urgent destination alerts and tour guide requests are texted to the
                                    office &mdash; and right now that message goes to no one. An officer
                                    is reached only if they have a mobile number on
                                    <a href="<?= e(base_url('/admin/account/index.php')) ?>">My Account</a>
                                    <em>and</em> have kept the alert tick-box on. Add a number there, or
                                    list one below.
                                </p>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="text-muted small mb-3">
                            <?= n(count($reachable)) ?> number<?= count($reachable) === 1 ? '' : 's' ?>
                            will be texted: <?= e(implode(', ', $reachable)) ?>.
                        </p>
                    <?php endif; ?>

                    <p class="text-muted small mb-3">
                        Officers are reached through their own account. Use the box below only for
                        numbers with no account behind them &mdash; the office landline, a barangay
                        captain, the MDRRMO duty phone. Separate several with commas.
                    </p>

                    <?php $extraRules = $editable['alert_sms_recipients']; ?>
                    <label for="alert_sms_recipients" class="form-label"><?= e($extraRules['label']) ?></label>
                    <input type="text" id="alert_sms_recipients" name="alert_sms_recipients"
                           maxlength="<?= (int) $extraRules['max'] ?>"
                           placeholder="0917 123 4567, 0918 765 4321"
                           class="form-control <?= has_error('alert_sms_recipients') ? 'is-invalid' : '' ?>"
                           value="<?= old('alert_sms_recipients', (string) setting('alert_sms_recipients', '')) ?>">
                    <p class="field-hint">
                        Anything that is not a Philippine mobile number is ignored rather than refused,
                        so a landline left here simply never receives one.
                    </p>
                    <?php if (has_error('alert_sms_recipients')): ?>
                        <div class="field-error"><?= e(error_for('alert_sms_recipients')) ?></div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="panel" data-settab="public">
                <?php section_head('fa-people-group', 'Local Culture &amp; Heritage',
                    'Short introduction about Tampakan, shown on every destination&rsquo;s QR page.') ?>
                <div class="panel__body">
                    <p class="text-muted small mb-3">
                        Shown on <strong>every destination's QR page</strong>, below that destination's own
                        heritage section. This is about Tampakan itself &mdash; the people, the customs, the
                        history a visitor should know wherever in the municipality they happen to be standing.
                        Written once here rather than copied into each destination, so correcting it corrects
                        it everywhere.
                    </p>

                    <div class="mb-3">
                        <label for="municipal_heritage_title" class="form-label">
                            <?= e($editable['municipal_heritage_title']['label']) ?>
                        </label>
                        <input type="text" id="municipal_heritage_title" name="municipal_heritage_title"
                               maxlength="120"
                               class="form-control <?= has_error('municipal_heritage_title') ? 'is-invalid' : '' ?>"
                               value="<?= old('municipal_heritage_title', (string) setting('municipal_heritage_title', '')) ?>">
                        <?php if (has_error('municipal_heritage_title')): ?>
                            <div class="field-error"><?= e(error_for('municipal_heritage_title')) ?></div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label for="municipal_heritage" class="form-label">
                            <?= e($editable['municipal_heritage']['label']) ?>
                        </label>
                        <?php /* A textarea, not an input. This is paragraphs — the
                                 B'laan and T'boli communities of the area, the
                                 harvest customs, why the mountain matters. */ ?>
                        <textarea id="municipal_heritage" name="municipal_heritage" rows="8" maxlength="4000"
                                  class="form-control <?= has_error('municipal_heritage') ? 'is-invalid' : '' ?>"
                                  placeholder="Tampakan sits at the foot of Mt. Matutum, on land the B'laan people have lived on for generations&hellip;"><?= old('municipal_heritage', (string) setting('municipal_heritage', '')) ?></textarea>
                        <p class="form-text">
                            Leave blank and the section simply does not appear on any QR page.
                        </p>
                        <?php if (has_error('municipal_heritage')): ?>
                            <div class="field-error"><?= e(error_for('municipal_heritage')) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <?php /* The Homepage Hero panel used to sit HERE, inside this form.
                     It is now rendered after the form closes, because each slide
                     carries its own Save, Delete and Duplicate — and a <form>
                     inside a <form> is not valid HTML. Browsers do not nest them;
                     they drop the inner one, and every slide button would have
                     silently submitted the settings form instead.

                     It is still tagged data-settab="public", so the tab strip
                     shows it in the right place. */ ?>

            <section class="panel" data-settab="office">
                <?php section_head('fa-qrcode', 'Printed Signage Address', 'The one address that gets printed onto physical signs.') ?>
                <div class="panel__body">
                    <p class="text-muted small mb-3">
                        The address a scanned QR code opens. Every other link on this system follows
                        whatever address you are browsing on; this one must not, because it is printed
                        onto signs that stay in the field for years. Set it to the address a tourist on
                        mobile data can reach.
                    </p>

                    <label for="public_url" class="form-label"><?= e($editable['public_url']['label']) ?></label>
                    <input type="url" id="public_url" name="public_url"
                           maxlength="<?= (int) $editable['public_url']['max'] ?>"
                           placeholder="https://tourism.tampakan.gov.ph"
                           class="form-control <?= has_error('public_url') ? 'is-invalid' : '' ?>"
                           value="<?= old('public_url', (string) setting('public_url', '')) ?>">
                    <?php if (has_error('public_url')): ?>
                        <div class="field-error"><?= e(error_for('public_url')) ?></div>
                    <?php endif; ?>

                    <p class="text-muted small mt-2 mb-0">
                        Codes currently point at
                        <code><?= e(App\Core\QrService::publicBase()) ?>/d/&hellip;</code>
                        <?php if (!App\Core\QrService::isPublishable()): ?>
                            &mdash; <strong class="text-danger">not usable on a printed sign.</strong>
                            <?= e(App\Core\QrService::unpublishableReason()) ?>
                        <?php endif; ?>
                    </p>

                    <?php
                    /* THE REHEARSAL.
                       The office is shown this system on a laptop long before
                       anything is launched. On that laptop "localhost" is the
                       laptop, so a scanned code opens the phone doing the
                       scanning and the demonstration dies in the room.

                       This machine's WiFi address works for every phone on the
                       same network, which is the whole audience. Offered rather
                       than applied: only the officer knows whether they are
                       rehearsing or setting up the real thing. */
                    $rehearsal = App\Core\QrService::rehearsalUrl();
                    ?>
                    <?php if ($rehearsal !== '' && $rehearsal !== App\Core\QrService::publicBase()): ?>
                        <div class="form-note mt-3">
                            <span>
                                <strong>Presenting from this computer?</strong>
                                It is reachable on this network at
                                <code><?= e($rehearsal) ?></code>.
                                A phone on the same WiFi can open codes pointing there &mdash;
                                good for a demonstration, never for a sign in the field.
                                <button type="button" class="btn btn-sm btn-outline-secondary ms-2"
                                        data-fill="public_url" data-fill-value="<?= e($rehearsal) ?>">
                                    <i class="fa-solid fa-arrow-up" aria-hidden="true"></i> Use this
                                </button>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="panel" data-settab="records">
                <?php section_head('fa-shield-halved', 'Record Integrity Thresholds', 'When the logbook flags an arrival for review.') ?>
                <div class="panel__body">
                    <p class="text-muted small mb-3">
                        These decide when the logbook flags a submission for review. Loosening them
                        admits more records; tightening them risks rejecting genuine visitors.
                    </p>
                    <div class="row g-3">
                        <?php foreach (['dedupe_window_hours', 'rate_limit_per_15m', 'proximity_metres'] as $key):
                            $rules = $editable[$key]; ?>
                            <div class="col-md-4">
                                <label for="<?= e($key) ?>" class="form-label"><?= e($rules['label']) ?></label>
                                <input type="number" id="<?= e($key) ?>" name="<?= e($key) ?>"
                                       min="<?= (int) $rules['min'] ?>" max="<?= (int) $rules['max'] ?>"
                                       class="form-control <?= has_error($key) ? 'is-invalid' : '' ?>"
                                       value="<?= old($key, (string) setting($key, '')) ?>">
                                <?php if (has_error($key)): ?><div class="field-error"><?= e(error_for($key)) ?></div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="panel" data-settab="records">
                <?php section_head('fa-user-shield', 'Data Privacy', 'How long personal data is kept before the retention job clears it.') ?>
                <div class="panel__body">
                    <p class="text-muted small mb-3">
                        Under <strong>RA 10173</strong>, personal data should not be kept longer than the
                        purpose requires. The retention job clears names and contact details from older
                        records while leaving every count intact — the statistics survive, the personal
                        data does not.
                    </p>
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label for="retention_months" class="form-label"><?= e($editable['retention_months']['label']) ?></label>
                            <input type="number" id="retention_months" name="retention_months" min="6" max="120"
                                   class="form-control <?= has_error('retention_months') ? 'is-invalid' : '' ?>"
                                   value="<?= old('retention_months', (string) setting('retention_months', '36')) ?>">
                            <?php if (has_error('retention_months')): ?><div class="field-error"><?= e(error_for('retention_months')) ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-8">
                            <a href="retention.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fa-solid fa-eraser"></i> Review and run the retention job
                            </a>
                        </div>
                    </div>
                </div>
            </section>

        </form>

        <?php
        /* THE HOMEPAGE HERO — OUTSIDE THE SETTINGS FORM, ON PURPOSE.
         *
         * Every other section on this screen is a value the office types and
         * saves with everything else. A slide is a record: it is added, copied,
         * taken off the front page for a season, dragged above another one and
         * deleted. Those are its own verbs and they need their own forms, which
         * cannot live inside the settings form because HTML has no nested forms
         * — the browser drops the inner one and every slide button would quietly
         * submit the settings instead.
         *
         * So this panel sits after </form> and carries a form per action. It
         * keeps data-settab="public" so the tab strip still owns it, and it
         * lands directly under Local Culture & Heritage, which is where the two
         * public-facing sections belong together. */
        $published = Hero::countPublished();
        ?>
        <section class="panel" data-settab="public" id="heroPanel">
            <?php section_head('fa-images', 'Homepage Hero',
                'The slides that rotate at the top of the public homepage.',
                count($slides) === 1 ? '1 slide' : count($slides) . ' slides',
                $published === 0 ? 'flag' : 'qr') ?>

            <div class="panel__body">
                <div class="hero-bar">
                    <p class="hero-bar__note">
                        <?php if ($slides === []): ?>
                            No slides yet &mdash; the homepage is showing stock photographs.
                        <?php elseif ($published === 0): ?>
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            Every slide is a draft, so the homepage is falling back to stock
                            photographs. Publish at least one.
                        <?php else: ?>
                            <?= n($published) ?> of <?= n(count($slides)) ?> showing on the homepage.
                            Drafts keep their words but stay off the public site.
                        <?php endif; ?>
                    </p>

                    <button type="button" class="btn btn-brand btn-sm" data-dialog="heroAdd">
                        <i class="fa-solid fa-plus"></i> Add Hero Slide
                    </button>
                </div>

                <?php if ($slides === []): ?>
                    <p class="hero-empty">
                        <i class="fa-solid fa-image"></i>
                        Add a slide to put Tampakan's own photographs on its own front page.
                    </p>
                <?php else: ?>
                    <?php /* THE ORDER FORM WRAPS THE LIST.
                             Dragging rewrites the hidden inputs inside it and submits;
                             with no JavaScript the list simply does not drag, and every
                             other action on this panel still works. Reordering is the
                             one thing here that degrades, and it degrades to "you
                             cannot reorder" rather than to a broken page. */ ?>
                    <form method="post" id="heroOrderForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="hero_reorder">

                        <ul class="hero-list" id="heroList">
                            <?php foreach ($slides as $i => $s): ?>
                                <?php
                                $sid   = (int) $s['id'];
                                $thumb = uploaded_url((string) $s['image_path']);
                                $live  = ((string) $s['status']) === 'published';
                                ?>
                                <li class="hero-row<?= $live ? '' : ' is-draft' ?>" data-hero-id="<?= $sid ?>">
                                    <input type="hidden" name="order[]" value="<?= $sid ?>">

                                    <?php /* aria-hidden: the grip is a mouse affordance and
                                             announcing "grip vertical" to a screen reader that
                                             cannot drag is noise. Keyboard reordering is the
                                             two arrow buttons in the actions group. */ ?>
                                    <span class="hero-row__grip" aria-hidden="true" title="Drag to reorder">
                                        <i class="fa-solid fa-grip-vertical"></i>
                                    </span>

                                    <span class="hero-row__num"><?= $i + 1 ?></span>

                                    <span class="hero-row__thumb<?= $thumb === null ? ' is-empty' : '' ?>"
                                          <?= $thumb === null ? 'title="No photograph yet — the homepage shows a stock picture for this slide"' : '' ?>>
                                        <?php if ($thumb !== null): ?>
                                            <img src="<?= e($thumb) ?>" alt="">
                                        <?php else: ?>
                                            <i class="fa-solid fa-image" aria-hidden="true"></i>
                                            <span class="visually-hidden">No photograph yet</span>
                                        <?php endif; ?>
                                    </span>

                                    <div class="hero-row__main">
                                        <p class="hero-row__title">
                                            <?= e(trim((string) $s['title']) !== ''
                                                ? (string) $s['title'] : 'Untitled slide') ?>
                                            <span class="pill pill--<?= $live ? 'ok' : 'void' ?>">
                                                <?= e(Hero::STATUSES[(string) $s['status']]) ?>
                                            </span>
                                        </p>
                                        <?php /* The eyebrow alone. "· no photograph" used to
                                                 hang off the end of this line and, on the
                                                 longest eyebrow, was the half that got cut by
                                                 the ellipsis — a warning that only appeared
                                                 when there was room for it. The empty
                                                 thumbnail to the left already says it, in the
                                                 place somebody is looking for a picture. */ ?>
                                        <p class="hero-row__sub">
                                            <?= e(trim((string) $s['eyebrow']) !== ''
                                                ? (string) $s['eyebrow'] : 'No eyebrow line') ?>
                                        </p>
                                    </div>

                                    <div class="hero-row__actions">
                                        <button type="button" class="icon-btn" data-dialog="heroEdit<?= $sid ?>"
                                                title="Edit this slide" aria-label="Edit this slide">
                                            <i class="fa-solid fa-pen"></i>
                                        </button>

                                        <button type="submit" class="icon-btn" form="heroCopy<?= $sid ?>"
                                                title="Duplicate this slide" aria-label="Duplicate this slide">
                                            <i class="fa-regular fa-copy"></i>
                                        </button>

                                        <button type="submit" class="icon-btn icon-btn--danger" form="heroDel<?= $sid ?>"
                                                data-confirm="Delete &quot;<?= e((string) $s['title']) ?>&quot;? The photograph goes with it."
                                                data-confirm-tone="danger"
                                                title="Delete this slide" aria-label="Delete this slide">
                                            <i class="fa-regular fa-trash-can"></i>
                                        </button>

                                        <button type="button" class="icon-btn" data-hero-expand
                                                aria-expanded="false" title="Show the paragraph"
                                                aria-label="Show the paragraph">
                                            <i class="fa-solid fa-chevron-down"></i>
                                        </button>
                                    </div>

                                    <?php /* The paragraph is the one thing that will not fit on a
                                             row, and it is the thing an officer most wants to check
                                             before publishing. Expanded rather than truncated with
                                             an ellipsis nobody can expand. */ ?>
                                    <div class="hero-row__more" hidden>
                                        <p class="hero-row__body">
                                            <?= e(trim((string) $s['body']) !== ''
                                                ? (string) $s['body'] : 'This slide has no paragraph.') ?>
                                        </p>
                                        <div class="hero-row__more-actions">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary"
                                                    form="heroFlip<?= $sid ?>">
                                                <i class="fa-solid fa-<?= $live ? 'eye-slash' : 'eye' ?>"></i>
                                                <?= $live ? 'Move to draft' : 'Publish this slide' ?>
                                            </button>
                                            <span class="hero-row__moves">
                                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                                        data-hero-move="up" <?= $i === 0 ? 'disabled' : '' ?>
                                                        aria-label="Move up">
                                                    <i class="fa-solid fa-arrow-up"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                                        data-hero-move="down"
                                                        <?= $i === count($slides) - 1 ? 'disabled' : '' ?>
                                                        aria-label="Move down">
                                                    <i class="fa-solid fa-arrow-down"></i>
                                                </button>
                                            </span>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </form>

                    <?php /* The drag half is hidden below 576px along with the grip
                             itself — telling somebody on a phone to drag a handle
                             that is not on their screen is worse than saying
                             nothing. The arrows are there on every width, which is
                             why they are the half that always shows. */ ?>
                    <p class="hero-hint" id="heroHint" hidden>
                        <i class="fa-solid fa-arrows-up-down"></i>
                        <span class="hero-hint__drag">Drag a slide by its handle to reorder, or use</span>
                        <span class="hero-hint__tap">Use</span>
                        the arrows under a slide to move it.
                    </p>
                <?php endif; ?>
            </div>
        </section>

        <?php
        /* "ABOUT THE OFFICE" — the section where the office describes itself.
         *
         * Outside the settings form and carrying its own Save, exactly like the
         * hero: it has two file fields, and the page-wide form deliberately has
         * no enctype since the hero's uploads moved out of it.
         *
         * Settings rows rather than a table, because this is one block of a
         * fixed shape. The hero earned a table by being a LIST the office can
         * lengthen; there will only ever be one mission and one vision. */
        $aboutMain  = uploaded_url((string) (setting('about_image_main', '') ?? ''));
        $aboutSmall = uploaded_url((string) (setting('about_image_small', '') ?? ''));

        /* Two fields rather than asking an officer to type a <span> for the
           coloured half of the heading. The public page joins them. */
        $aboutText = static fn(string $k): string => (string) (setting($k, '') ?? '');
        ?>
        <section class="panel" data-settab="public" id="aboutPanel">
            <?php section_head('fa-building-columns', 'About the Office',
                'The block on the homepage where the office introduces itself.',
                $aboutMain === null && $aboutSmall === null ? 'stock photos' : '',
                'flag') ?>

            <div class="panel__body">
                <form method="post" enctype="multipart/form-data" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="about_save">

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="about_eyebrow">Eyebrow</label>
                            <input type="text" class="form-control" id="about_eyebrow"
                                   name="about_eyebrow" maxlength="60"
                                   value="<?= e($aboutText('about_eyebrow')) ?>">
                            <p class="field-hint">The small line above the heading.</p>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="about_title">Heading</label>
                            <input type="text" class="form-control" id="about_title"
                                   name="about_title" maxlength="80"
                                   value="<?= e($aboutText('about_title')) ?>">
                            <p class="field-hint">Shown in dark text.</p>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="about_title_em">Heading, coloured half</label>
                            <input type="text" class="form-control" id="about_title_em"
                                   name="about_title_em" maxlength="80"
                                   value="<?= e($aboutText('about_title_em')) ?>">
                            <p class="field-hint">Continues the heading in green. Leave empty for none.</p>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="about_lead">Introduction</label>
                            <textarea class="form-control" id="about_lead" name="about_lead"
                                      rows="4" maxlength="900"><?= e($aboutText('about_lead')) ?></textarea>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="about_badge_value">Badge</label>
                            <input type="text" class="form-control" id="about_badge_value"
                                   name="about_badge_value" maxlength="30"
                                   value="<?= e($aboutText('about_badge_value')) ?>">
                            <p class="field-hint">The large line on the card over the photograph.</p>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label" for="about_badge_label">Badge caption</label>
                            <input type="text" class="form-control" id="about_badge_label"
                                   name="about_badge_label" maxlength="80"
                                   value="<?= e($aboutText('about_badge_label')) ?>">
                            <p class="field-hint">Leave both blank and the card is not drawn at all.</p>
                        </div>

                        <?php foreach ([
                            ['mission', 'Mission', 'fa-bullseye'],
                            ['vision',  'Vision',  'fa-eye'],
                        ] as [$part, $label, $icon]): ?>
                            <div class="col-md-6">
                                <label class="form-label" for="about_<?= $part ?>_title">
                                    <i class="fa-solid <?= e($icon) ?>"></i> <?= e($label) ?> heading
                                </label>
                                <input type="text" class="form-control" id="about_<?= $part ?>_title"
                                       name="about_<?= $part ?>_title" maxlength="60"
                                       value="<?= e($aboutText('about_' . $part . '_title')) ?>">

                                <label class="form-label mt-2" for="about_<?= $part ?>_text">
                                    <?= e($label) ?> statement
                                </label>
                                <textarea class="form-control" id="about_<?= $part ?>_text"
                                          name="about_<?= $part ?>_text" rows="4"
                                          maxlength="700"><?= e($aboutText('about_' . $part . '_text')) ?></textarea>
                            </div>
                        <?php endforeach; ?>

                        <?php /* Two photographs: a tall one and the smaller one that
                                 overlaps its corner. Same convention as the hero —
                                 leave a slot empty and the stock picture stands in. */ ?>
                        <?php foreach ([
                            ['main',  'Main photograph', 'Tall, portrait. 900 &times; 1100 works well.', $aboutMain],
                            ['small', 'Inset photograph', 'The smaller one overlapping its corner.',     $aboutSmall],
                        ] as [$slot, $label, $hint, $current]): ?>
                            <div class="col-md-6">
                                <label class="form-label" for="about_img_<?= $slot ?>"><?= e($label) ?></label>
                                <input type="file" class="form-control" id="about_img_<?= $slot ?>"
                                       name="image_<?= $slot ?>" accept="image/jpeg,image/png,image/webp">
                                <p class="field-hint">
                                    <?= $hint ?>
                                    JPG, PNG or WebP up to <?= n(Uploader::maxMegabytes()) ?>&nbsp;MB.
                                    <?= $current === null
                                        ? 'None yet, so a stock photograph is shown.'
                                        : 'Leave empty to keep the one already saved.' ?>
                                </p>

                                <?php if ($current !== null): ?>
                                    <img class="hero-sheet__thumb" src="<?= e($current) ?>"
                                         alt="Current <?= e(strtolower($label)) ?>">
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" value="1"
                                               id="about_rm_<?= $slot ?>" name="remove_<?= $slot ?>">
                                        <label class="form-check-label" for="about_rm_<?= $slot ?>">
                                            Remove it and go back to the stock photograph
                                        </label>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php /* Its own Save, said out loud — the bar at the foot of the
                             screen belongs to the settings form and does not reach
                             this one. */ ?>
                    <div class="about-save">
                        <button type="submit" class="btn btn-brand">
                            <i class="fa-solid fa-floppy-disk"></i> Save the About section
                        </button>
                        <span class="cell-sub">Saved on its own, not by the button at the bottom.</span>
                    </div>
                </form>
            </div>
        </section>

        <?php /* THE SYSTEM TAB, IN THE MAIN COLUMN LIKE EVERY OTHER TAB.
                 Read-only, so it sits outside the settings form with the hero —
                 there is nothing here to save. */ ?>
        <section class="panel" data-settab="system">
            <?php section_head('fa-server', 'System', 'What this installation is running on.') ?>
            <div class="panel__body">
                <?php /* Plain .detail-grid, not --single. The modifier was there because
                         this panel lived in a 340px rail where one column was the only
                         thing that fitted. In the main column it lets auto-fit lay five
                         short facts across the width instead of down a 1,100px page. */ ?>
                <dl class="detail-grid">
                    <div><dt>PHP version</dt><dd><?= e(PHP_VERSION) ?></dd></div>
                    <div><dt>Database</dt><dd><?= e((string) $dbVersion) ?></dd></div>
                    <div><dt>Environment</dt><dd><?= e((string) config('env', 'production')) ?></dd></div>
                    <div><dt>Timezone</dt><dd><?= e(date_default_timezone_get()) ?></dd></div>
                    <div><dt>Base URL</dt><dd class="mono"><?= e(base_url()) ?></dd></div>
                </dl>
            </div>
        </section>

        <?php /* An "Accounts" panel used to sit here whose whole job was a link to
                 accounts.php. User Accounts is a tab of its own now, so the panel
                 was a second door to a room already on the map — and it competed
                 with the tab for the same click. */ ?>
    </div>

    <div class="panel-stack">
        <section class="panel" data-settab="alerts">
            <?php section_head('fa-comment-sms', 'SMS Gateway', 'Which provider sends the texts, and whether it is live.') ?>
            <div class="panel__body">
                <dl class="detail-grid detail-grid--single">
                    <div><dt>Driver</dt><dd><?= e(SmsGateway::driver()->name()) ?></dd></div>
                    <div><dt>Status</dt>
                        <dd><span class="pill pill--<?= SmsGateway::isLive() ? 'ok' : 'flag' ?>">
                            <?= SmsGateway::isLive() ? 'Live' : 'Test mode' ?></span></dd></div>
                </dl>
                <p class="text-muted small mb-0 mt-2"><?= e(SmsGateway::driver()->describe()) ?></p>
                <p class="report-note">
                    The SMS provider and API key live in <code>app/config/config.php</code>, not here.
                    A credential editable from a web form is a credential that can be changed by
                    anyone who reaches that form.
                </p>
            </div>
        </section>

        <?php /* System and Accounts used to sit here, in the right rail, and they
                 were the ONLY thing on their tab. That left the System tab with two
                 panels crushed into the 1fr rail and the 1.9fr main column empty
                 beside them — the mirror image of the fault the other three tabs
                 had. They have moved into the main column, so every tab is now
                 built the same way and the rail carries what a rail is for:
                 a side note beside something else. */ ?>
    </div>
</div>

<?php /* THE ONE-CLICK SLIDE ACTIONS.
 *
 * Each is a real form with nothing in it but a CSRF token, an action and an id,
 * parked here at the end of the document and reached by the buttons up in the
 * list through form="…". That indirection buys two things: the buttons sit in
 * the row where they belong while the forms sit outside every other form, and
 * each action is a separate POST — so a mis-click on Delete cannot pick up the
 * duplicate's fields, and neither can touch the settings form.
 *
 * Copy and publish/draft need no confirmation: one is undone by deleting the
 * copy, the other by pressing it again. Delete removes a photograph from disk,
 * so it asks. */ ?>
<?php foreach ($slides as $s): ?>
    <?php $sid = (int) $s['id']; ?>
    <form method="post" id="heroCopy<?= $sid ?>" class="d-none">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="hero_duplicate">
        <input type="hidden" name="id" value="<?= $sid ?>">
    </form>

    <form method="post" id="heroFlip<?= $sid ?>" class="d-none">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="hero_status">
        <input type="hidden" name="id" value="<?= $sid ?>">
    </form>

    <form method="post" id="heroDel<?= $sid ?>" class="d-none">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="hero_delete">
        <input type="hidden" name="id" value="<?= $sid ?>">
    </form>
<?php endforeach; ?>

<?php
/* THE SLIDE EDITOR, ONE SHEET PER SLIDE PLUS ONE FOR A NEW ONE.
 *
 * The same .sheet dialog the roster, the managers and the videos use, so a slide
 * is edited the way everything else in this system is edited. Rendering one per
 * slide rather than a single sheet repopulated by JavaScript means the fields
 * are filled by PHP with the values already escaped, and a sheet opened with
 * scripting broken is still the right slide's form.
 *
 * $slide is a local here and shadows nothing — the settings loops above finished
 * long ago and the roster's lesson about a loop variable leaking into a form is
 * respected by giving this its own name. */
$heroSheet = static function (?array $s): void {
    $sid    = $s !== null ? (int) $s['id'] : 0;
    $isNew  = $s === null;
    $thumb  = $isNew ? null : uploaded_url((string) $s['image_path']);
    $status = $isNew ? 'published' : (string) $s['status'];
    ?>
    <dialog class="sheet sheet--wide" id="<?= $isNew ? 'heroAdd' : 'heroEdit' . $sid ?>">
        <form method="post" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $isNew ? 'hero_create' : 'hero_update' ?>">
            <?php if (!$isNew): ?>
                <input type="hidden" name="id" value="<?= $sid ?>">
            <?php endif; ?>

            <header class="sheet__head">
                <h2><i class="fa-solid fa-<?= $isNew ? 'plus' : 'pen' ?>"></i>
                    <?= $isNew ? 'Add Hero Slide' : 'Edit Slide' ?></h2>
                <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </header>

            <div class="sheet__body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" for="hs_title_<?= $sid ?>">
                            Title <span class="req">*</span>
                        </label>
                        <input type="text" class="form-control" id="hs_title_<?= $sid ?>"
                               name="title" required maxlength="<?= Hero::MAX_TITLE ?>"
                               placeholder="e.g. Discover the Beauty of Tampakan"
                               value="<?= $isNew ? '' : e((string) $s['title']) ?>">
                        <p class="field-hint">The large line. This is what a visitor reads first.</p>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="hs_eyebrow_<?= $sid ?>">Eyebrow</label>
                        <input type="text" class="form-control" id="hs_eyebrow_<?= $sid ?>"
                               name="eyebrow" maxlength="<?= Hero::MAX_EYEBROW ?>"
                               placeholder="e.g. Welcome to South Cotabato's Highland Heart"
                               value="<?= $isNew ? '' : e((string) $s['eyebrow']) ?>">
                        <p class="field-hint">The small line above the title. Optional.</p>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="hs_body_<?= $sid ?>">Paragraph</label>
                        <textarea class="form-control" id="hs_body_<?= $sid ?>" name="body" rows="3"
                                  maxlength="<?= Hero::MAX_BODY ?>"
                                  placeholder="Where cool mountain air, rolling highlands, and the living traditions of the B'laan people meet a warm municipal welcome."><?= $isNew ? '' : e((string) $s['body']) ?></textarea>
                    </div>

                    <div class="col-md-7">
                        <label class="form-label" for="hs_image_<?= $sid ?>">Photograph</label>
                        <input type="file" class="form-control" id="hs_image_<?= $sid ?>"
                               name="image" accept="image/jpeg,image/png,image/webp">
                        <p class="field-hint">
                            JPG, PNG or WebP up to <?= n(Uploader::maxMegabytes()) ?>&nbsp;MB.
                            Wide pictures work best &mdash; the hero is 1920&nbsp;&times;&nbsp;1080.
                            <?= $thumb === null
                                ? 'With none, the homepage shows a stock photograph.'
                                : 'Leave this empty to keep the picture already saved.' ?>
                        </p>

                        <?php if ($thumb !== null): ?>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" value="1"
                                       id="hs_rm_<?= $sid ?>" name="remove_image">
                                <label class="form-check-label" for="hs_rm_<?= $sid ?>">
                                    Remove the current photograph
                                </label>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-5">
                        <label class="form-label" for="hs_status_<?= $sid ?>">Status</label>
                        <select class="form-select" id="hs_status_<?= $sid ?>" name="status">
                            <?php foreach (Hero::STATUSES as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field-hint">
                            A draft keeps everything you have typed and stays off the public homepage.
                        </p>

                        <?php if ($thumb !== null): ?>
                            <img class="hero-sheet__thumb" src="<?= e($thumb) ?>" alt="Current photograph">
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <footer class="sheet__foot">
                <button type="button" class="btn btn-outline-secondary" data-dialog-close>Cancel</button>
                <button type="submit" class="btn btn-brand">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <?= $isNew ? 'Add Slide' : 'Save Slide' ?>
                </button>
            </footer>
        </form>
    </dialog>
    <?php
};

$heroSheet(null);

foreach ($slides as $s) {
    $heroSheet($s);
}
?>

<?php /* THE SAVE BAR, FIXED — WHICH WAS THE WHOLE COMPLAINT.
 *
 * It used to be .form-actions: sticky, bottom:0, sitting at the END of the
 * settings form. Sticky only holds a thing down while its container is taller
 * than the screen, and the container here is whichever panels the current tab
 * has left visible. So the button was at the foot of a five-screen form on
 * Office, and directly under the tab strip on System — where the left column
 * has no panels at all and the form collapses to nothing. Same button, four
 * different places, depending on where you had clicked.
 *
 * Fixed to the content area instead: same height, same edge, every tab. It is
 * outside the form and reaches it with form="settingsForm", which is why the
 * form was able to close before the hero panel.
 *
 * No Reset button. It was in the mockup and it is not here on purpose — the
 * only honest reset is a page reload, and a button that silently discards work
 * beside the button that saves it is a button somebody eventually hits by
 * mistake at the end of a long form. */ ?>
<?php /* The bar is fixed, so it occupies no space and would otherwise sit on top
         of the last panel on the page. A spacer of its own height, rather than
         padding on .admin-content — that partial is shared by thirty screens
         that have no bar. */ ?>
<div class="set-bar__gap" aria-hidden="true"></div>

<div class="set-bar" id="settingsBar">
    <div class="set-bar__status" data-dirty-status>
        <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
        <span>
            <strong data-dirty-title>All changes saved</strong>
            <span class="set-bar__sub">Changes across all Settings tabs are saved together.</span>
        </span>
    </div>

    <button type="submit" form="settingsForm" class="btn btn-brand">
        <i class="fa-solid fa-floppy-disk"></i> Save Settings
    </button>
</div>

<script>
(function () {
    'use strict';

    var strip = document.getElementById('settingsTabs');

    if (!strip) { return; }

    var panels  = document.querySelectorAll('[data-settab]');
    var buttons = strip.querySelectorAll('[data-settab-btn]');
    var row     = document.querySelector('.panel-row');
    var main    = document.querySelector('.panel-main');
    var stack   = document.querySelector('.panel-stack');

    function show(which) {
        panels.forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-settab') !== which;
        });

        buttons.forEach(function (button) {
            var on = button.getAttribute('data-settab-btn') === which;
            button.classList.toggle('is-active', on);
            button.setAttribute('aria-selected', on ? 'true' : 'false');
        });

        /* A TWO-COLUMN GRID IS ONLY RIGHT WHEN BOTH COLUMNS HAVE SOMETHING IN
           THEM, AND ON THIS SCREEN THAT IS ONE TAB OUT OF FIVE.

           Panels are distributed like this:

               office   2 left   0 right
               public   2 left   0 right
               records  2 left   0 right
               alerts   1 left   1 right   <- the only genuine two-column tab
               system   0 left   2 right

           The first version of this only asked whether the RIGHT column was
           used, which fixed Office, Public site and Records and left System
           inverted: both its panels squeezed into the 1fr rail while the 1.9fr
           main column sat empty beside them. Same fault, other way round.

           So: ask both sides, collapse to one column unless both are used, and
           hide whichever column has nothing — an empty grid child still takes a
           gap, which is a 1.1rem step of nothing before the content starts.

           Hiding the main column hides the settings form with it. That is safe
           and deliberate: `hidden` is display:none, and a control inside a
           display:none container is still submitted. Only `disabled` removes a
           field from the post, and nothing here disables anything. Verified by
           counting the fields FormData carries while the column is hidden. */
        if (row && main && stack) {
            var onLeft  = main.querySelector('[data-settab="' + which + '"]') !== null;
            var onRight = stack.querySelector('[data-settab="' + which + '"]') !== null;

            main.hidden  = !onLeft;
            stack.hidden = !onRight;

            row.classList.toggle('is-single', !(onLeft && onRight));
        }

        /* In the hash, so a reload — and the redirect after a save — comes back
           to the tab the officer was working in rather than throwing them to the
           top of a five-screen form. */
        if (window.history.replaceState) {
            window.history.replaceState(null, '', '#' + which);
        }
    }

    strip.addEventListener('click', function (event) {
        var button = event.target.closest('[data-settab-btn]');

        if (button) { show(button.getAttribute('data-settab-btn')); }
    });

    /* A FIELD WITH AN ERROR MUST NOT BE HIDDEN BEHIND A TAB.
       The form comes back with the rejected value and a message; if that field
       is on a tab nobody is looking at, the page reads as "it refused and would
       not say why". The tab holding the first error wins over the hash. */
    var bad = document.querySelector('.is-invalid, .field-error');
    var owner = bad ? bad.closest('[data-settab]') : null;

    show(owner ? owner.getAttribute('data-settab')
               : (window.location.hash || '#office').replace('#', ''));

    /* The collapse behaviour that used to be written here now lives in
       assets/js/admin.js, because User Accounts and My Account grew the same
       section headers and a third copy is a third thing to keep in step.
       Two listeners on one click would toggle a section twice and leave it
       exactly as it was, so this copy had to go rather than be left alongside. */

    /* =====================================================================
       Unsaved changes
       =====================================================================
       The bar says which of the two states the form is in, because the tabs
       hide the evidence: an officer who edited a hotline, switched to Office
       and saw a quiet screen has no other way to tell that something is
       pending. Reset on submit rather than on unload — the page redirects
       after a save and comes back fresh. */
    var settings = document.getElementById('settingsForm');
    var bar      = document.getElementById('settingsBar');

    if (settings && bar) {
        var title = bar.querySelector('[data-dirty-title]');
        var dirty = false;

        function markDirty() {
            if (dirty) { return; }

            dirty = true;
            bar.classList.add('is-dirty');
            title.textContent = 'Unsaved changes';
        }

        settings.addEventListener('input',  markDirty);
        settings.addEventListener('change', markDirty);

        settings.addEventListener('submit', function () {
            dirty = false;
            bar.classList.remove('is-dirty');
        });

        /* The browser's own prompt, which is the only one it will honour. The
           wording is not ours to choose and the event must be cancelled the way
           each engine expects, hence both lines. */
        window.addEventListener('beforeunload', function (event) {
            if (!dirty) { return; }

            event.preventDefault();
            event.returnValue = '';
        });
    }

    /* =====================================================================
       The hero slide list
       ===================================================================== */
    var list = document.getElementById('heroList');

    if (!list) { return; }

    var orderForm = document.getElementById('heroOrderForm');
    var hint      = document.getElementById('heroHint');

    /* Hidden in the markup and revealed here: without this script the rows do
       not drag and the arrows do nothing, so an instruction to drag them would
       be a lie told to exactly the people who cannot. */
    if (hint) { hint.hidden = false; }

    /* Expanding a row to read its paragraph. */
    list.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-hero-expand]');

        if (!toggle) { return; }

        var row  = toggle.closest('.hero-row');
        var more = row.querySelector('.hero-row__more');
        var open = more.hidden;

        more.hidden = !open;
        row.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    function renumber() {
        var rows = list.querySelectorAll('.hero-row');

        rows.forEach(function (row, i) {
            row.querySelector('.hero-row__num').textContent = i + 1;

            var up   = row.querySelector('[data-hero-move="up"]');
            var down = row.querySelector('[data-hero-move="down"]');

            if (up)   { up.disabled   = i === 0; }
            if (down) { down.disabled = i === rows.length - 1; }
        });
    }

    /* Submitting the order re-reads the DOM: the hidden order[] inputs live
       inside the rows, so moving a row moves its input with it and the form
       posts whatever arrangement is on screen. */
    function saveOrder() {
        renumber();
        orderForm.submit();
    }

    /* Arrows first — they are the accessible path and they work on a phone,
       where dragging a list inside a scrolling page fights the scroll. */
    list.addEventListener('click', function (event) {
        var move = event.target.closest('[data-hero-move]');

        if (!move || move.disabled) { return; }

        var row = move.closest('.hero-row');

        if (move.getAttribute('data-hero-move') === 'up') {
            if (row.previousElementSibling) {
                list.insertBefore(row, row.previousElementSibling);
            }
        } else if (row.nextElementSibling) {
            list.insertBefore(row.nextElementSibling, row);
        }

        saveOrder();
    });

    /* Dragging. The rows are NOT draggable until a press lands on the grip —
       otherwise a slow click on Delete becomes a drag, and selecting the title
       text to copy it picks the whole row up instead. */
    var dragging = null;

    list.addEventListener('pointerdown', function (event) {
        var grip = event.target.closest('.hero-row__grip');
        var row  = event.target.closest('.hero-row');

        if (row) { row.draggable = !!grip; }
    });

    list.addEventListener('dragstart', function (event) {
        dragging = event.target.closest('.hero-row');

        if (!dragging) { return; }

        dragging.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        /* Firefox refuses to start a drag unless something is set. */
        event.dataTransfer.setData('text/plain', '');
    });

    list.addEventListener('dragover', function (event) {
        if (!dragging) { return; }

        event.preventDefault();

        var over = event.target.closest('.hero-row');

        if (!over || over === dragging) { return; }

        /* Past the midpoint means the pointer has committed to the far side of
           that row, which is where the dragged one should land. Comparing to
           the midpoint rather than to the edges stops the list flickering
           between two arrangements while the pointer sits on a boundary. */
        var box  = over.getBoundingClientRect();
        var past = event.clientY > box.top + box.height / 2;

        list.insertBefore(dragging, past ? over.nextElementSibling : over);
    });

    list.addEventListener('dragend', function () {
        if (!dragging) { return; }

        dragging.classList.remove('is-dragging');
        dragging.draggable = false;
        dragging = null;

        saveOrder();
    });
})();
</script>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
