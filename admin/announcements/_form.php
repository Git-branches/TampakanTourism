<?php
/**
 * Shared announcement composer for create.php and edit.php.
 * Expects $a (current values), $destinations, $recipientCount.
 */

use App\Repositories\AnnouncementRepository;

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

$isEdit = !empty($a['id']);

/* THE SAME COMPOSER IN THREE PLACES.
 *
 * create.php and edit.php render this as a full page; the list renders it
 * inside a dialog behind the New Announcement button. Only the chrome differs.
 * Three panels become three labelled groups in the sheet — a panel inside a
 * dialog is a card inside a card, and the nesting reads as a mistake. */
$inSheet = !empty($inSheet);
?>
<?php /* enctype, because of the card picture below. Without it the browser
         sends the filename and nothing else, and the upload fails in the one
         way that leaves no error to read. */ ?>
<form method="post" id="announceForm" novalidate enctype="multipart/form-data"
      <?= $inSheet ? 'action="create.php" class="sheet__form"' : 'class="form-grid"' ?>>
    <?= csrf_field() ?>

    <?php if ($inSheet): ?>
        <header class="sheet__head">
            <h2><i class="fa-solid fa-bullhorn" aria-hidden="true"></i> New announcement</h2>
            <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </header>
        <div class="sheet__body">
    <?php endif; ?>

    <section class="<?= $inSheet ? 'sheet__group' : 'panel' ?>">
        <header class="<?= $inSheet ? 'sheet__legend' : 'panel__head' ?>"><h2><i class="fa-solid fa-pen-nib"></i> Message</h2></header>
        <div class="<?= $inSheet ? 'sheet__fields' : 'panel__body' ?>">
            <div class="row g-3">

                <div class="col-md-8">
                    <label for="title" class="form-label">Title <span class="req">*</span></label>
                    <input type="text" id="title" name="title" required maxlength="200"
                           class="form-control <?= has_error('title') ? 'is-invalid' : '' ?>"
                           value="<?= e((string) ($a['title'] ?? '')) ?>"
                           placeholder="e.g. Trail Advisory: Highland Circuit Partially Closed">
                    <?php if (has_error('title')): ?><div class="field-error"><?= e(error_for('title')) ?></div><?php endif; ?>
                </div>

                <?php
                /* ONE GROUP OR THE OTHER, never both in one list.
                 *
                 * News and Events answer different questions and appear in
                 * different sections of the public homepage, so offering all ten
                 * types on one composer is how a festival ends up filed as a
                 * notice — and then shown twice, which is the fault this split
                 * exists to remove.
                 *
                 * Which group is offered follows the record: an existing event
                 * keeps the event kinds, and a new one takes the group of the
                 * door it was opened from. */
                $currentType = (string) ($a['type'] ?? 'announcement');
                $isEvent     = AnnouncementRepository::isEventType($currentType);
                $group       = $isEvent
                    ? AnnouncementRepository::EVENT_TYPES
                    : AnnouncementRepository::NEWS_TYPES;
                ?>
                <div class="col-md-4">
                    <label for="type" class="form-label">
                        <?= $isEvent ? 'Kind of event' : 'Type' ?> <span class="req">*</span>
                    </label>
                    <select id="type" name="type" required class="form-select">
                        <?php foreach ($group as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $currentType === $value ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field-hint">
                        <?= $isEvent
                            ? 'Shown under Upcoming Events on the homepage &mdash; never under Latest News.'
                            : 'Shown under Latest News &amp; Announcements. For something people can attend, use Events in the sidebar.' ?>
                    </p>
                </div>

                <div class="col-12">
                    <label for="summary" class="form-label">Short summary</label>
                    <input type="text" id="summary" name="summary" maxlength="300" class="form-control"
                           value="<?= e((string) ($a['summary'] ?? '')) ?>"
                           placeholder="One sentence, used on cards and in the announcements list">
                </div>

                <div class="col-12">
                    <label for="body" class="form-label">Full message <span class="req">*</span></label>
                    <textarea id="body" name="body" rows="7" required
                              class="form-control <?= has_error('body') ? 'is-invalid' : '' ?>"><?= e((string) ($a['body'] ?? '')) ?></textarea>
                    <?php if (has_error('body')): ?><div class="field-error"><?= e(error_for('body')) ?></div><?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="<?= $inSheet ? 'sheet__group' : 'panel' ?>">
        <header class="<?= $inSheet ? 'sheet__legend' : 'panel__head' ?>"><h2><i class="fa-solid fa-users-rectangle"></i> Who Receives This</h2></header>
        <div class="<?= $inSheet ? 'sheet__fields' : 'panel__body' ?>">
            <div class="row g-3">

                <div class="col-md-6">
                    <label for="audience" class="form-label">Audience <span class="req">*</span></label>
                    <select id="audience" name="audience" required class="form-select">
                        <?php foreach (AnnouncementRepository::AUDIENCES as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= ($a['audience'] ?? 'public') === $value ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field-hint">
                        One message, one place to write it. The audience decides whether it appears on
                        the website, goes out by SMS, or both.
                    </p>
                </div>

                <div class="col-md-6">
                    <label for="destination_id" class="form-label">Related destination</label>
                    <select id="destination_id" name="destination_id" class="form-select">
                        <option value="">Not specific to one destination</option>
                        <?php foreach ($destinations as $d): ?>
                            <option value="<?= (int) $d['id'] ?>"
                                <?= (int) ($a['destination_id'] ?? 0) === (int) $d['id'] ? 'selected' : '' ?>>
                                <?= e($d['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field-hint">Required in practice for a closure notice, so visitors know which site.</p>
                </div>

                <!-- Live SMS cost estimate, shown before sending rather than
                     discovered on the bill. -->
                <div class="col-12" id="smsPanel" hidden>
                    <div class="sms-estimate">
                        <div>
                            <strong><i class="fa-solid fa-comment-sms"></i> SMS preview</strong>
                            <p class="mb-0">
                                <span id="smsChars">0</span> characters ·
                                <span id="smsSegments">1</span> segment(s) ·
                                <?= n($recipientCount) ?> recipient<?= $recipientCount === 1 ? '' : 's' ?> ·
                                approx. <strong>PHP <span id="smsCost">0.00</span></strong>
                            </p>
                        </div>
                        <p class="sms-estimate__note">
                            Messages are billed per 160-character segment, per recipient. Long messages
                            cost several times more.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="<?= $inSheet ? 'sheet__group' : 'panel' ?>">
        <header class="<?= $inSheet ? 'sheet__legend' : 'panel__head' ?>"><h2><i class="fa-regular fa-calendar"></i> Event &amp; Scheduling</h2></header>
        <div class="<?= $inSheet ? 'sheet__fields' : 'panel__body' ?>">
            <div class="row g-3">

                <?php
                /* THIS IS WHERE AN UPCOMING EVENT COMES FROM, and it was not
                   obvious. There is no separate "Events" screen: an event is an
                   announcement whose type is "Tourism Event" and which carries a
                   date. Set both and it appears in Upcoming Events on the
                   homepage. Said here, next to the field that does it, rather
                   than left for somebody to work out. */
                ?>
                <div class="col-12">
                    <div class="form-note">
                        <span>
                            <strong>Making an Upcoming Event?</strong>
                            Choose <em>Tourism Event</em> as the type above and give it a date
                            below. It then appears in <em>Upcoming Events</em> on the homepage,
                            newest first, and drops off by itself once the date has passed.
                        </span>
                    </div>
                </div>

                <div class="col-md-4">
                    <label for="event_date" class="form-label">Event date</label>
                    <input type="date" id="event_date" name="event_date" class="form-control"
                           value="<?= e((string) ($a['event_date'] ?? '')) ?>">
                    <p class="field-hint">Required for an Upcoming Event &mdash; without it the
                       notice is listed but not scheduled.</p>
                </div>

                <div class="col-md-8">
                    <label for="event_location" class="form-label">Event location</label>
                    <input type="text" id="event_location" name="event_location" maxlength="200" class="form-control"
                           value="<?= e((string) ($a['event_location'] ?? '')) ?>"
                           placeholder="e.g. Municipal Plaza, Poblacion">
                </div>

                <?php
                /* THE PICTURE ON THE CARD.
                 *
                 * announcements.banner_path has existed since the table was
                 * created and nothing ever wrote to it — so every notice and
                 * every event on the homepage fell back to the same stock
                 * photograph, and a row of event cards was six copies of one
                 * picture. There was no field for it anywhere.
                 *
                 * Attached after the announcement is saved, so an upload that
                 * fails cannot take an edited paragraph down with it. */
                $banner = trim((string) ($a['banner_path'] ?? ''));
                ?>
                <?php if ($isEvent): ?>
                <?php
                /* AN EVENT HAS A GALLERY, not one picture.
                 *
                 * The featured photograph is still banner_path — the homepage
                 * card and the large picture on the event page — so an event
                 * made before galleries existed is simply a gallery of one. The
                 * rest live in announcement_photos. The event page lays them
                 * out as one large picture and three beside it, with the others
                 * behind "View all photos", the way Cultural Heritage does.
                 *
                 * Several files in one save, so the request can outgrow PHP's
                 * limits; the script below checks the total before sending,
                 * because a request over post_max_size arrives EMPTY and fails
                 * as an expired session, which explains nothing. */
                $eventPhotos = $isEdit ? AnnouncementRepository::photos((int) $a['id']) : [];
                $perFile     = \App\Core\Uploader::maxBytes();
                $perRequest  = upload_limit_bytes();
                $perSave     = (int) ini_get('max_file_uploads') ?: 20;
                ?>
                <div class="col-12 event-photos-field">
                    <label for="photos" class="form-label">Event photos</label>

                    <?php if ($banner !== '' || $eventPhotos !== []): ?>
                        <ul class="event-photos" aria-label="Current photos">
                            <?php if ($banner !== ''): ?>
                                <li class="event-photos__item is-featured">
                                    <img src="<?= e(base_url($banner)) ?>" alt="Featured photo" loading="lazy">
                                    <span class="event-photos__badge">
                                        <i class="fa-solid fa-star" aria-hidden="true"></i> Featured
                                    </span>
                                    <div class="event-photos__controls">
                                        <label class="form-check">
                                            <input type="radio" class="form-check-input" name="featured_photo" value="0" checked>
                                            <span class="form-check-label">Keep as featured</span>
                                        </label>
                                        <label class="form-check">
                                            <input type="checkbox" class="form-check-input" name="remove_banner" value="1">
                                            <span class="form-check-label">Remove</span>
                                        </label>
                                    </div>
                                </li>
                            <?php endif; ?>

                            <?php foreach ($eventPhotos as $i => $photo): ?>
                                <li class="event-photos__item">
                                    <img src="<?= e(base_url((string) $photo['file_path'])) ?>"
                                         alt="Event photo <?= $i + 2 ?>" loading="lazy">
                                    <div class="event-photos__controls">
                                        <label class="form-check">
                                            <input type="radio" class="form-check-input" name="featured_photo"
                                                   value="<?= (int) $photo['id'] ?>">
                                            <span class="form-check-label">Make featured</span>
                                        </label>
                                        <label class="form-check">
                                            <input type="checkbox" class="form-check-input" name="remove_photos[]"
                                                   value="<?= (int) $photo['id'] ?>">
                                            <span class="form-check-label">Remove</span>
                                        </label>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <input type="file" id="photos" name="photos[]" multiple
                           accept="image/jpeg,image/png,image/webp" class="form-control"
                           data-per-file="<?= (int) $perFile ?>"
                           data-per-request="<?= (int) $perRequest ?>"
                           data-per-save="<?= (int) $perSave ?>">
                    <p class="field-hint">
                        Choose several at once. JPG, PNG or WebP, up to <?= (int) floor($perFile / 1048576) ?> MB each
                        and <?= (int) floor($perRequest / 1048576) ?> MB per save.
                        <?= $banner !== ''
                            ? 'New photos are added after the ones above.'
                            : 'The first photo becomes the featured one &mdash; the homepage card and the large picture on the event page.' ?>
                        An event holds up to <?= AnnouncementRepository::MAX_PHOTOS ?>.
                    </p>
                    <p class="field-error" data-photos-problem hidden></p>
                    <ul class="event-photos event-photos--new" data-photos-preview hidden aria-label="Photos to be added"></ul>

                    <script>
                    /* Scoped to THIS form. The list page can hold two copies of
                       the composer at once — the New Event sheet and an Edit
                       dialog — so nothing here is looked up by id. */
                    (function (field) {
                        var input   = field.querySelector('input[type="file"]');
                        var problem = field.querySelector('[data-photos-problem]');
                        var preview = field.querySelector('[data-photos-preview]');
                        var form    = field.closest('form');
                        var urls    = [];
                        var TYPES   = ['image/jpeg', 'image/png', 'image/webp'];
                        var MB      = 1048576;

                        function limits() {
                            return {
                                perFile:    Number(input.getAttribute('data-per-file')),
                                perRequest: Number(input.getAttribute('data-per-request')),
                                perSave:    Number(input.getAttribute('data-per-save'))
                            };
                        }

                        /* The one thing the browser can know for certain: what is
                           about to be sent. The server still judges every file. */
                        function check() {
                            var files = Array.prototype.slice.call(input.files || []);
                            var l = limits();
                            var total = 0;
                            var wrong = [];
                            var large = [];

                            files.forEach(function (f) {
                                total += f.size;
                                if (TYPES.indexOf(f.type) === -1) { wrong.push(f.name); }
                                else if (f.size > l.perFile) { large.push(f.name); }
                            });

                            if (files.length > l.perSave) {
                                return 'Choose at most ' + l.perSave + ' photos per save. Save, then open the event again to add more.';
                            }
                            if (wrong.length) {
                                return 'Only JPG, PNG and WebP pictures can be added: ' + wrong.join(', ') + '.';
                            }
                            if (large.length) {
                                return 'Over ' + Math.floor(l.perFile / MB) + ' MB: ' + large.join(', ') + '.';
                            }
                            /* A little under the limit: the rest of the form travels
                               in the same request. */
                            if (total > l.perRequest - MB) {
                                return 'These photos add up to ' + (total / MB).toFixed(1) + ' MB, more than one save can carry ('
                                    + Math.floor(l.perRequest / MB) + ' MB). Add them in two or more saves.';
                            }
                            return '';
                        }

                        function draw() {
                            urls.forEach(function (u) { URL.revokeObjectURL(u); });
                            urls = [];
                            preview.innerHTML = '';

                            var files = Array.prototype.slice.call(input.files || []);
                            files.forEach(function (f) {
                                if (TYPES.indexOf(f.type) === -1) { return; }
                                var url = URL.createObjectURL(f);
                                urls.push(url);

                                var li  = document.createElement('li');
                                li.className = 'event-photos__item';
                                var img = document.createElement('img');
                                img.src = url;
                                img.alt = '';
                                li.appendChild(img);
                                var name = document.createElement('span');
                                name.className = 'event-photos__name';
                                name.textContent = f.name;
                                li.appendChild(name);
                                preview.appendChild(li);
                            });

                            preview.hidden = preview.children.length === 0;

                            var said = check();
                            problem.textContent = said;
                            problem.hidden = said === '';
                            input.classList.toggle('is-invalid', said !== '');
                        }

                        input.addEventListener('change', draw);

                        form.addEventListener('submit', function (event) {
                            var said = check();
                            if (said === '') { return; }
                            event.preventDefault();
                            event.stopImmediatePropagation();
                            problem.textContent = said;
                            problem.hidden = false;
                            input.focus();
                        }, true);
                    })(document.currentScript.parentElement);
                    </script>
                </div>
                <?php else: ?>
                <div class="col-12">
                    <label for="banner" class="form-label">Card picture</label>

                    <?php if ($banner !== ''): ?>
                        <div class="hero-current mb-2">
                            <img src="<?= e(base_url($banner)) ?>" alt="Current card picture"
                                 style="max-height:120px;border-radius:8px">
                            <label class="form-check mt-2">
                                <input type="checkbox" class="form-check-input" name="remove_banner" value="1">
                                <span class="form-check-label">Remove this picture</span>
                            </label>
                        </div>
                    <?php endif; ?>

                    <input type="file" id="banner" name="banner" accept="image/*" class="form-control">
                    <p class="field-hint">
                        Shown behind the card on the homepage &mdash; in Upcoming Events for an
                        event, and in Latest News for everything else. Landscape works best,
                        about three parts wide to two tall.
                        <?= $banner !== '' ? 'Choosing a new file replaces the one above.' : 'Left empty, a stock photograph is used.' ?>
                    </p>
                </div>
                <?php endif; ?>

                <div class="col-md-6">
                    <label for="publish_at" class="form-label">Publish at</label>
                    <input type="datetime-local" id="publish_at" name="publish_at" class="form-control"
                           value="<?= e($a['publish_at'] ? date('Y-m-d\TH:i', strtotime((string) $a['publish_at'])) : '') ?>">
                    <p class="field-hint">Leave blank to publish immediately when the status is set to Published.</p>
                </div>

                <div class="col-md-6">
                    <label for="expires_at" class="form-label">Stop showing after</label>
                    <input type="datetime-local" id="expires_at" name="expires_at"
                           class="form-control <?= has_error('expires_at') ? 'is-invalid' : '' ?>"
                           value="<?= e($a['expires_at'] ? date('Y-m-d\TH:i', strtotime((string) $a['expires_at'])) : '') ?>">
                    <p class="field-hint">
                        An advisory that has passed should disappear on its own. Nobody remembers to
                        take one down.
                    </p>
                    <?php if (has_error('expires_at')): ?><div class="field-error"><?= e(error_for('expires_at')) ?></div><?php endif; ?>
                </div>

                <div class="col-12">
                    <label for="status" class="form-label">Status <span class="req">*</span></label>
                    <select id="status" name="status" required class="form-select">
                        <option value="draft"     <?= ($a['status'] ?? 'draft') === 'draft'     ? 'selected' : '' ?>>Draft — not visible anywhere</option>
                        <option value="published" <?= ($a['status'] ?? '')      === 'published' ? 'selected' : '' ?>>Published</option>
                        <option value="archived"  <?= ($a['status'] ?? '')      === 'archived'  ? 'selected' : '' ?>>Archived — withdrawn</option>
                    </select>
                    <p class="field-hint">
                        Publishing does not send the SMS. Sending is a separate, deliberate action on
                        the announcement's page — so a typo fixed after publishing does not cost a
                        second blast.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <?php if ($inSheet): ?>
        </div>

        <footer class="sheet__foot">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close>Cancel</button>
            <button type="submit" class="btn btn-sm btn-brand">
                <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Create Announcement
            </button>
        </footer>
    <?php else: ?>
    <div class="form-actions">
        <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-brand">
            <i class="fa-solid fa-floppy-disk"></i> <?= $isEdit ? 'Save Changes' : 'Create Announcement' ?>
        </button>
    </div>
    <?php endif; ?>
</form>

<script>
(function () {
    const audience = document.getElementById('audience');
    const title    = document.getElementById('title');
    const body     = document.getElementById('body');
    const panel    = document.getElementById('smsPanel');
    const RECIPIENTS = <?= (int) $recipientCount ?>;
    const PER_SEGMENT = 0.50;

    /* Mirrors SmsGateway::compose() closely enough for an estimate. The server
       is still the authority on what is actually sent. */
    function estimate() {
        const goesBySms = audience.value === 'managers' || audience.value === 'both';
        panel.hidden = !goesBySms;
        if (!goesBySms) return;

        const signature = '\n\n- Tampakan Tourism Office';
        let text = (title.value.trim() + '\n\n' + body.value.trim()).replace(/\s*\n\s*/g, '\n');
        text = text.slice(0, 480 - signature.length) + signature;

        const chars = text.length;
        const segments = Math.max(1, Math.ceil(chars / 160));

        document.getElementById('smsChars').textContent = chars;
        document.getElementById('smsSegments').textContent = segments;
        document.getElementById('smsCost').textContent = (segments * RECIPIENTS * PER_SEGMENT).toFixed(2);
    }

    [audience, title, body].forEach((el) => el.addEventListener('input', estimate));
    audience.addEventListener('change', estimate);
    estimate();
})();
</script>
