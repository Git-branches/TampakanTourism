<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/_helpers.php';

use App\Core\Paginator;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Notifier;
use App\Core\SmsGateway;
use App\Repositories\AnnouncementRepository;
use App\Repositories\ManagerRepository;

Auth::require();

/* TWO SECTIONS, ONE TABLE.
 *
 * News answers "what do I need to know" — advisories, closures, schedules,
 * reminders. Events answers "what can I go to". A record belongs to exactly one
 * of them, decided by its type, which is what stops the same festival appearing
 * in both sections of the public homepage.
 *
 * The section is its own parameter and not `?type=event`, because Events is now
 * FIVE types: an event kind is chosen within the section, the way a status is.
 * Two tables would have meant two status workflows, two SMS paths and two
 * public detail pages to hold one extra column. */
$eventsView = ($_GET['section'] ?? '') === 'events';

$sectionTypes = $eventsView
    ? AnnouncementRepository::EVENT_TYPES
    : AnnouncementRepository::NEWS_TYPES;

$filters = [
    'status' => (string) ($_GET['status'] ?? ''),
    'type'   => (string) ($_GET['type'] ?? ''),
    'search' => trim((string) ($_GET['q'] ?? '')),
    'types'  => array_keys($sectionTypes),
];

/* A type from the other section, arrived at by editing the URL, would produce
   an empty list with no explanation. Dropped rather than obeyed. */
if ($filters['type'] !== '' && !isset($sectionTypes[$filters['type']])) {
    $filters['type'] = '';
}

$pageTitle    = $eventsView ? 'Events' : 'Announcements';
$pageIcon     = $eventsView ? 'fa-calendar-day' : 'fa-bullhorn';
$pageSubtitle = $eventsView
    ? 'Festivals, fairs and celebrations — shown in Upcoming Events on the homepage'
    : 'Advisories, closures, reminders, and submission schedules';

$result     = AnnouncementRepository::paginate($filters, (int) ($_GET['page'] ?? 1), Paginator::PER_PAGE);
$pager      = Paginator::adopt($result);
$counts     = AnnouncementRepository::statusCounts();
$recipients = count(ManagerRepository::smsRecipients());
$retryable  = Notifier::retryableCount();

/* The composer is rendered into a dialog at the foot of this page, so what it
   needs is loaded here as well as in create.php. */
$destinations   = Database::all("SELECT id, name FROM destinations WHERE status='active' ORDER BY name");
$recipientCount = $recipients;

/* Rejected input comes back from create.php with its errors; the dialog
   reopens over the list rather than sending anybody to a second screen. */
/* NOT $a. The list below walks the announcements with
   `foreach ($result['rows'] as $a)`, and the dialog is rendered after it — so a
   variable called $a here would hold the last row on the page by the time the
   composer read it, and the rejected draft would come back as somebody else's
   announcement. */
$sheetAnnouncement = array_fill_keys([
    'id','title','summary','body','type','audience','status',
    'destination_id','event_date','event_location','publish_at','expires_at',
    'banner_path',
], '');

$sheetAnnouncement['type']     = $eventsView ? 'event' : 'announcement';   // first of its group
$sheetAnnouncement['audience'] = 'public';
$sheetAnnouncement['status']   = 'draft';

foreach (array_keys($sheetAnnouncement) as $k) {
    $old = old_all();
    if (isset($old[$k])) { $sheetAnnouncement[$k] = $old[$k]; }
}

$sheetOpen = old_all() !== [];

/* WHERE THE MENU'S ACTIONS COME BACK TO.
 *
 * Publishing from page two of the Events door used to land on a detail screen;
 * the officer then navigated back and lost their place. This is the list they
 * were on — section, filters and page — handed to view.php, which only honours
 * a relative path inside this folder. The script uses the same value to refresh
 * the rows in place, so with JavaScript the page does not reload at all. */
$returnTo = 'index.php' . ($_SERVER['QUERY_STRING'] ?? '' ? '?' . $_SERVER['QUERY_STRING'] : '');

require __DIR__ . '/../_partials/head.php';
?>

<?php if ($retryable > 0): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-rotate"></i>
        <strong><?= n($retryable) ?> notification<?= $retryable === 1 ? '' : 's' ?> failed and can still be retried.</strong>
        Open the announcement and use Send again — already-delivered recipients are skipped.
    </div>
<?php endif; ?>

<?php /* The SMS explainer belongs on the Announcements door, where an officer is
         deciding who a notice goes to. On the Events door it is four lines
         about a channel events do not use, above the list the officer came
         here for — so Events gets one line about the thing it does. */ ?>
<?php if ($eventsView): ?>
    <p class="result-count mb-3">
        <i class="fa-solid fa-circle-info"></i>
        A published event with a date in the future appears in <strong>Upcoming Events</strong>
        on the homepage, soonest first, and drops off by itself once the date has passed.
    </p>
<?php else: ?>
    <div class="panel panel--notice">
        <div class="panel__body">
            <h2><i class="fa-solid fa-<?= SmsGateway::isLive() ? 'tower-broadcast' : 'flask' ?>"></i>
                One message, two channels</h2>
            <p>
                An announcement is written once. Its <strong>audience</strong> decides whether it appears
                on the public website, goes to destination managers by SMS, or both — so a closure notice
                never has to be written twice and the two copies cannot drift apart.
            </p>
            <p class="mb-0">
                <strong><?= n($recipients) ?></strong> manager<?= $recipients === 1 ? '' : 's' ?> currently opted in to SMS.
                <?= e(SmsGateway::driver()->describe()) ?>
            </p>
        </div>
    </div>
<?php endif; ?>

<div class="toolbar">
    <form class="toolbar__filters" method="get">
 <?php /* Without this, applying a filter on the Events door lands on the
          Announcements one — the section is in the query string, and a GET
          form replaces the whole query string with its own fields. */ ?>
 <?php if ($eventsView): ?><input type="hidden" name="section" value="events"><?php endif; ?>
        <div class="search-field">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="q" value="<?= e($filters['search']) ?>" placeholder="Search title or message">
        </div>

        <select name="status" class="form-select form-select-sm">
            <option value="">All statuses</option>
            <option value="draft"     <?= $filters['status'] === 'draft'     ? 'selected' : '' ?>>Draft (<?= $counts['draft'] ?>)</option>
            <option value="published" <?= $filters['status'] === 'published' ? 'selected' : '' ?>>Published (<?= $counts['published'] ?>)</option>
            <option value="archived"  <?= $filters['status'] === 'archived'  ? 'selected' : '' ?>>Archived (<?= $counts['archived'] ?>)</option>
        </select>

        <select name="type" class="form-select form-select-sm">
            <option value="">All <?= $eventsView ? 'event kinds' : 'types' ?></option>
            <?php foreach ($sectionTypes as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $filters['type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
    </form>
    <button type="button" class="btn btn-brand btn-sm" data-dialog="addAnnouncement">
        <i class="fa-solid fa-plus"></i> <?= $eventsView ? 'New Event' : 'New Announcement' ?>
    </button>
</div>

<?php if ($result['rows'] === []): ?>

    <div class="panel"><div class="panel__body">
        <div class="empty">
            <i class="fa-solid fa-bullhorn"></i>
            <p><strong>No announcements yet.</strong></p>
            <p>Publish advisories, closure notices, event listings, and report submission
               schedules from one place.</p>
            <p class="mt-3">
                <button type="button" class="btn btn-brand btn-sm" data-dialog="addAnnouncement">
                    <i class="fa-solid fa-plus"></i> Write the first one
                </button>
            </p>
        </div>
    </div></div>

<?php else: ?>

    <div class="announce-list">
        <?php foreach ($result['rows'] as $a):
            $style = AnnouncementRepository::TYPE_STYLE[$a['type']] ?? ['icon' => 'fa-bullhorn', 'tone' => 'blue']; ?>
            <article class="announce announce--<?= e($style['tone']) ?>">
                <?php /* The card picture where there is one, so the officer can see at a
                         glance which notices will look like every other notice on the
                         homepage. The type icon stays when there is none. */ ?>
                <?php if (!empty($a['banner_path'])): ?>
                    <div class="announce__thumb">
                        <img src="<?= e(base_url($a['banner_path'])) ?>" alt="" loading="lazy">
                    </div>
                <?php else: ?>
                    <div class="announce__icon"><i class="fa-solid <?= e($style['icon']) ?>"></i></div>
                <?php endif; ?>

                <div class="announce__body">
                    <div class="announce__top">
                        <h3><a href="view.php?id=<?= (int) $a['id'] ?>"><?= e($a['title']) ?></a></h3>
                        <span class="pill pill--<?= $a['status'] === 'published' ? 'ok' : ($a['status'] === 'draft' ? 'flag' : 'void') ?>">
                            <?= e(ucfirst($a['status'])) ?>
                        </span>
                    </div>

                    <p class="announce__summary">
                        <?= e($a['summary'] ?: mb_substr(strip_tags($a['body']), 0, 130) . '…') ?>
                    </p>

                    <div class="announce__meta">
                        <span><i class="fa-solid <?= e($style['icon']) ?>"></i> <?= e(AnnouncementRepository::TYPES[$a['type']]) ?></span>
                        <span><i class="fa-solid fa-users"></i> <?= e(AnnouncementRepository::AUDIENCES[$a['audience']]) ?></span>
                        <?php if ($a['destination_name']): ?>
                            <span><i class="fa-solid fa-location-dot"></i> <?= e($a['destination_name']) ?></span>
                        <?php endif; ?>
                        <?php if ($a['event_date']): ?>
                            <span><i class="fa-regular fa-calendar"></i> <?= e(format_date($a['event_date'])) ?></span>
                        <?php endif; ?>
                        <?php if ((int) $a['notified'] > 0): ?>
                            <span class="announce__sent">
                                <i class="fa-solid fa-paper-plane"></i>
                                <?= n($a['delivered']) ?>/<?= n($a['notified']) ?> sent
                            </span>
                        <?php endif; ?>
                        <span class="announce__when"><?= e(format_date($a['created_at'], 'M j, Y')) ?></span>
                    </div>
                </div>

                <?php
                /* THE ROW HAD NO ACTIONS AT ALL. Editing a notice or taking a live one
                 * down meant opening it first — three clicks to change a status, from
                 * a screen that already showed the status.
                 *
                 * Five things, in the order they are reached for: Edit, View,
                 * Duplicate, Publish/Unpublish, and Delete last behind a rule.
                 *
                 * They all post to view.php, which has handled these actions all
                 * along; this is a second DOOR to that handler, not a second
                 * implementation of it — and view.php no longer repeats them, so
                 * there is one place for each and no pair to drift apart.
                 *
                 * Every one of them asks first, and each question says what actually
                 * happens to this named notice rather than whether you are sure. */
                $menuId = 'annMenu' . (int) $a['id'];
                $title  = (string) $a['title'];
                ?>
                <div class="announce__actions card-menu">
                    <button type="button" class="btn btn-sm btn-outline-secondary card-menu__toggle"
                            data-card-menu="<?= e($menuId) ?>" aria-haspopup="true" aria-expanded="false"
                            aria-label="More for <?= e($title) ?>">
                        <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
                    </button>

                    <div class="card-menu__panel" id="<?= e($menuId) ?>" hidden>
                        <a href="edit.php?id=<?= (int) $a['id'] ?>"
                           data-modal-page data-modal-title="Edit &mdash; <?= e($title) ?>">
                            <i class="fa-solid fa-pen" aria-hidden="true"></i> Edit
                        </a>

                        <a href="view.php?id=<?= (int) $a['id'] ?>"
                           data-modal-page data-modal-title="<?= e($title) ?>">
                            <i class="fa-solid fa-eye" aria-hidden="true"></i> View
                        </a>

                        <?php /* Straight into the copy afterwards — duplicating is never the
                                 end of the task, and a draft called "… (copy)" left sitting in
                                 the list is how two nearly-identical notices get published. */ ?>
                        <form method="post" action="view.php" data-ajax
                              data-confirm="Make a copy of &ldquo;<?= e($title) ?>&rdquo;? It is saved as a draft with its own address, and opens for editing. Nothing is published and nobody is texted."
                              data-confirm-tone="normal">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <input type="hidden" name="return" value="<?= e($returnTo) ?>">
                            <input type="hidden" name="action" value="duplicate">
                            <button type="submit" class="card-menu__item">
                                <i class="fa-solid fa-copy" aria-hidden="true"></i> Duplicate
                            </button>
                        </form>

                        <?php
                        /* ONE TOGGLE, NOT THREE MOVES.
                         *
                         * This was Publish / Return to draft / Archive, which is the status
                         * column read out loud rather than the decision an officer is
                         * making. The decision is binary: is this on the website or not.
                         * Archive is a rarer thing and lives with the notice itself.
                         *
                         * Unpublishing is the one that needs care: it takes a live notice
                         * off the public site, and if it has already gone out by SMS that
                         * cannot be unsent — so the question says how many people already
                         * have it. */
                        $live = $a['status'] === 'published';

                        $ask = $live
                            ? 'Unpublish "' . $title . '"? It comes off the public website at once and '
                              . 'returns to draft. '
                              . ((int) $a['notified'] > 0
                                  ? 'It has already been sent to ' . n((int) $a['notified'])
                                    . ' manager(s) by SMS, and that cannot be unsent.'
                                  : 'Nobody is told it has gone.')
                            : 'Publish "' . $title . '"? It appears on the public website immediately. '
                              . 'Sending it to destination managers by SMS is a separate step.';
                        ?>
                        <form method="post" action="view.php" data-ajax
                              data-confirm="<?= e($ask) ?>"
                              data-confirm-tone="<?= $live ? 'danger' : 'normal' ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <input type="hidden" name="return" value="<?= e($returnTo) ?>">
                            <input type="hidden" name="action" value="status">
                            <button type="submit" name="status" value="<?= $live ? 'draft' : 'published' ?>"
                                    class="card-menu__item <?= $live ? 'is-danger' : '' ?>">
                                <i class="fa-solid <?= $live ? 'fa-eye-slash' : 'fa-bullhorn' ?>" aria-hidden="true"></i>
                                <?= $live ? 'Unpublish' : 'Publish' ?>
                            </button>
                        </form>

                        <?php if (Auth::isOfficer()): ?>
                            <?php
                            /* LAST, AND SEPARATED. The only irreversible thing on this menu,
                             * and it takes the delivery board with it — the record of who was
                             * texted about this notice and whether it arrived, which is the
                             * only evidence the office has that a closure went out.
                             *
                             * Officer only, and the question says what goes rather than
                             * asking whether you are sure. */
                            $sent = (int) $a['notified'];

                            $askDelete = 'Delete "' . $title . '" permanently? This cannot be undone.'
                                . ($sent > 0
                                    ? ' The delivery record for ' . n($sent) . ' SMS recipient(s) is destroyed with it.'
                                    : '')
                                . ' To take it off the website without losing it, use Unpublish or Archive instead.';
                            ?>
                            <hr class="card-menu__rule">

                            <form method="post" action="view.php" data-ajax
                                  data-confirm="<?= e($askDelete) ?>" data-confirm-tone="danger">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <input type="hidden" name="return" value="<?= e($returnTo) ?>">
                                <input type="hidden" name="action" value="delete">
                                <button type="submit" class="card-menu__item is-danger">
                                    <i class="fa-solid fa-trash-can" aria-hidden="true"></i> Delete
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php /* Was a hand-rolled row of numbers that rebuilt the query from
             $filters['status'] alone, so page two of any other filter
             quietly showed the unfiltered list. */ ?>
    <?php require __DIR__ . '/../../app/views/partials/pager.php'; ?>

<?php endif; ?>

<?php /* Edit opens in here. ABOVE the composer sheet on purpose: both render
 the same _form.php, so while this is open the page holds two elements
 with each of that form's ids. See the partial. */ ?>
<?php require __DIR__ . '/../_partials/page-modal.php'; ?>

<?php /* The list's own copy of the composer. Same _form.php create.php and
         edit.php use, so a field added there appears here without anyone
         remembering to. */ ?>
<dialog class="sheet sheet--wide" id="addAnnouncement"<?= $sheetOpen ? ' data-open' : '' ?>>
    <?php $inSheet = true; $a = $sheetAnnouncement; require __DIR__ . '/_form.php'; ?>
</dialog>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
