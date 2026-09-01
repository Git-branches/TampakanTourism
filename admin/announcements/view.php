<?php
declare(strict_types=1);

/**
 * Announcement detail, dispatch, and delivery board.
 *
 * Sending is deliberately separate from publishing. Publishing puts a notice
 * on the website and can be corrected freely; sending spends money and reaches
 * people's phones, and cannot be recalled. Tying the two together would mean
 * every typo fix cost a second blast.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Notifier;
use App\Core\Session;
use App\Core\SmsGateway;
use App\Repositories\AnnouncementRepository;
use App\Repositories\ManagerRepository;

Auth::require();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$a  = AnnouncementRepository::find($id);

if ($a === null) {
    Session::flash('danger', 'That announcement no longer exists.');
    redirect(base_url('/admin/announcements/index.php'));
}

$pageTitle    = 'Announcement';
$pageIcon     = 'fa-bullhorn';
$pageSubtitle = $a['title'];

if (is_post()) {
    Csrf::verify();
    $action = (string) ($_POST['action'] ?? '');

    /* WHERE TO GO AFTERWARDS, and why it is not this page.
     *
     * These actions are started from the list's own menu, and sending the
     * officer to a detail screen to be told "status changed to draft" is a
     * navigation they did not ask for and a trip back they now have to make.
     * The list says where to return, filters and page intact.
     *
     * Only a relative path inside this folder is honoured. A caller-supplied
     * redirect target that accepted anything is an open redirect — a link that
     * looks like it goes to the tourism office and lands somewhere else. */
    $back = static function () use ($id): string {
        $asked = trim((string) ($_POST['return'] ?? ''));

        $safe = $asked !== ''
            && !str_contains($asked, '//')
            && !str_contains($asked, '\\')
            && !str_starts_with($asked, '/')
            && preg_match('#^[a-z0-9_-]+\.php(\?[A-Za-z0-9=&_%.+-]*)?$#', $asked) === 1;

        return base_url('/admin/announcements/' . ($safe ? $asked : 'view.php?id=' . $id));
    };

    if ($action === 'status') {
        $status = (string) ($_POST['status'] ?? '');
        if (in_array($status, ['draft', 'published', 'archived'], true)) {
            AnnouncementRepository::setStatus($id, $status);
            ActivityLog::record('announcement.' . $status, 'announcement', $id, ucfirst($status) . ' "' . $a['title'] . '"');
            Session::flash('success', $status === 'published'
                ? 'Published. It is on the public website now.'
                : ($status === 'draft'
                    ? 'Unpublished. It is off the public website and back in draft.'
                    : 'Archived. Nothing was deleted.'));
        }

        redirect($back());
    }

    /* DUPLICATE AND DELETE ARE HANDLED HERE, not in two more files.
     *
     * This page already owns `action=status` and the list's menu already posts
     * to it. Giving each new action its own endpoint would mean three more
     * files repeating the same lookup, the same CSRF check and the same
     * ownership rules — and three places for those to drift apart. */
    if ($action === 'duplicate') {
        $copyId = AnnouncementRepository::duplicate($id, Auth::id());

        if ($copyId === null) {
            Session::flash('danger', 'That announcement no longer exists.');
            redirect(base_url('/admin/announcements/index.php'));
        }

        ActivityLog::record('announcement.duplicate', 'announcement', $copyId,
            'Duplicated "' . $a['title'] . '"');

        Session::flash('success', 'Copied as a draft — it is in the list now. '
            . 'Open Edit on it to change the date and the wording.');

        /* NOT into the copy. Being thrown into an editor is a navigation the
           officer did not ask for; the copy appears where they already are,
           and Edit is one press away on its own row. */
        redirect($back());
    }

    if ($action === 'delete') {
        /* Officer only. Deleting an announcement destroys the delivery board
           with it — the record of who was texted and whether it arrived — and
           that record is the only evidence the office has that a closure notice
           actually went out. */
        if (!Auth::isOfficer()) {
            Session::flash('danger', 'Only the Tourism Officer can delete an announcement.');
            redirect(base_url('/admin/announcements/view.php?id=' . $id));
        }

        AnnouncementRepository::delete($id);

        ActivityLog::record('announcement.delete', 'announcement', $id,
            'Deleted "' . $a['title'] . '"');

        Session::flash('success', 'Deleted "' . $a['title'] . '".');
        redirect($back());
    }

    if ($action === 'dispatch') {
        if (!Auth::isOfficer()) {
            Session::flash('danger', 'Only the Tourism Officer can send notifications.');
            redirect(base_url('/admin/announcements/view.php?id=' . $id));
        }

        if ($a['status'] !== 'published') {
            Session::flash('danger', 'Publish the announcement before sending it.');
            redirect(base_url('/admin/announcements/view.php?id=' . $id));
        }

        // Queue first, then send. If sending dies halfway, the record of who
        // should have been reached still exists and can be retried.
        $queued = Notifier::queue($id, $a['destination_id'] !== null ? (int) $a['destination_id'] : null);
        $result = Notifier::dispatch($id, $a['title'], $a['body']);

        ActivityLog::record(
            'announcement.dispatch', 'announcement', $id,
            sprintf('Dispatched "%s" — %d sent, %d failed, %d skipped',
                $a['title'], $result['sent'], $result['failed'], $result['skipped'])
        );

        if ($result['sent'] === 0 && $result['failed'] === 0 && $queued === 0) {
            Session::flash('warning', 'Everyone on the list has already been sent this announcement.');
        } else {
            Session::flash('success', sprintf(
                '%d message(s) sent, %d failed, %d skipped.%s',
                $result['sent'], $result['failed'], $result['skipped'],
                SmsGateway::isLive() ? '' : ' Test mode — written to storage/logs/sms.log, no real SMS sent.'
            ));
        }
    }

    redirect(base_url('/admin/announcements/view.php?id=' . $id));
}

$board      = Notifier::deliveryBoard($id);
$summary    = Notifier::summary($id);
$recipients = ManagerRepository::smsRecipients($a['destination_id'] !== null ? (int) $a['destination_id'] : null);
$goesBySms  = in_array($a['audience'], ['managers', 'both'], true);
$smsPreview = SmsGateway::compose($a['title'], $a['body'], (string) setting('office_name', 'Tampakan Tourism Office'));
$style      = AnnouncementRepository::TYPE_STYLE[$a['type']] ?? ['icon' => 'fa-bullhorn', 'tone' => 'blue'];

/* Skips the shell when this page was asked for as a dialog fragment.
   Additive: without ?modal=1 nothing here changes at all. */
if (!is_modal_request()) { require __DIR__ . '/../_partials/head.php'; }
?>

<div class="record-bar">
    <div class="record-bar__facts">
        <span class="pill pill--<?= $a['status'] === 'published' ? 'ok' : ($a['status'] === 'draft' ? 'flag' : 'void') ?>">
            <?= e(ucfirst($a['status'])) ?>
        </span>
        <span><i class="fa-solid <?= e($style['icon']) ?>"></i> <?= e(AnnouncementRepository::TYPES[$a['type']]) ?></span>
        <span><i class="fa-solid fa-users"></i> <?= e(AnnouncementRepository::AUDIENCES[$a['audience']]) ?></span>
        <?php if ($a['author_name']): ?><span><i class="fa-regular fa-user"></i> <?= e($a['author_name']) ?></span><?php endif; ?>
    </div>
    <div class="record-bar__actions">
        <?php /* NO EDIT BUTTON HERE ANY MORE.
                 Edit, Duplicate, Publish and Delete all live in the list's own
                 menu now, which is where an officer already is when they decide
                 to do one of them. Repeating Edit inside the page you opened to
                 READ is a second door to the same room, and the two would drift:
                 the menu grew Duplicate and Delete and this toolbar did not.
                 This page is for reading the notice and sending it. */ ?>
        <?php if ($a['status'] === 'published'): ?>
            <a href="<?= e(base_url('/announcement.php?slug=' . $a['slug'])) ?>" target="_blank" rel="noopener"
               class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-up-right-from-square"></i> Public page</a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary">Back</a>
    </div>
</div>

<div class="panel-row">
    <div>
        <section class="panel">
            <header class="panel__head"><h2><i class="fa-solid fa-file-lines"></i> <?= e($a['title']) ?></h2></header>
            <div class="panel__body">
                <?php if ($a['summary']): ?><p class="lead-sm"><?= e($a['summary']) ?></p><?php endif; ?>
                <div class="announce-body"><?= nl2br(e($a['body'])) ?></div>

                <dl class="detail-grid mt-4">
                    <?php if ($a['destination_name']): ?>
                        <div><dt>Destination</dt><dd><?= e($a['destination_name']) ?></dd></div>
                    <?php endif; ?>
                    <?php if ($a['event_date']): ?>
                        <div><dt>Event date</dt><dd><?= e(format_date($a['event_date'])) ?></dd></div>
                    <?php endif; ?>
                    <?php if ($a['event_location']): ?>
                        <div><dt>Event location</dt><dd><?= e($a['event_location']) ?></dd></div>
                    <?php endif; ?>
                    <div><dt>Publish at</dt><dd><?= $a['publish_at'] ? e(format_date($a['publish_at'], 'M j, Y g:i A')) : 'Immediately' ?></dd></div>
                    <div><dt>Expires</dt><dd><?= $a['expires_at'] ? e(format_date($a['expires_at'], 'M j, Y g:i A')) : 'Never' ?></dd></div>
                </dl>
            </div>
        </section>

        <?php if ($board !== []): ?>
        <section class="panel">
            <header class="panel__head">
                <h2><i class="fa-solid fa-paper-plane"></i> Delivery Board</h2>
            </header>
            <div class="panel__body">
                <p class="text-muted small">
                    <i class="fa-solid fa-circle-info"></i>
                    SMS can confirm that a message was <strong>sent</strong>, and sometimes that it was
                    delivered to the handset. It cannot report whether it was <strong>read</strong> —
                    no plain SMS system can, and any that claims to is guessing.
                </p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Recipient</th><th>Destination</th><th>Number</th><th>Status</th><th>Sent</th></tr></thead>
                        <tbody>
                        <?php foreach ($board as $n): ?>
                            <tr>
                                <td>
                                    <span class="cell-strong"><?= e($n['full_name']) ?></span>
                                    <?php if ($n['position']): ?><span class="cell-sub"><?= e($n['position']) ?></span><?php endif; ?>
                                </td>
                                <td><?= e($n['destination_name']) ?></td>
                                <td class="mono small"><?= e($n['mobile_number']) ?></td>
                                <td>
                                    <?php if ($n['status'] === 'sent' || $n['status'] === 'delivered'): ?>
                                        <span class="pill pill--ok"><i class="fa-solid fa-check"></i> Sent</span>
                                    <?php elseif ($n['status'] === 'failed'): ?>
                                        <span class="pill pill--void" title="<?= e((string) $n['error_message']) ?>">
                                            Failed (<?= (int) $n['attempts'] ?>/<?= Notifier::maxAttempts() ?>)
                                        </span>
                                    <?php else: ?>
                                        <span class="pill pill--flag">Queued</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?= $n['sent_at'] ? e(format_date($n['sent_at'], 'M j, g:i A')) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
        <?php endif; ?>
    </div>

    <div class="panel-stack">
        <?php
        /* THE STATUS BUTTONS HAVE GONE TO THE LIST, all but one.
         *
         * Publish, Unpublish, Duplicate and Delete are on the row's own menu —
         * one place, reachable without opening anything. Repeating them here
         * would be the same duplication as the Edit button above.
         *
         * Archive is the exception, and only because the menu does not carry
         * it: filing a notice away is a rarer decision than publishing one, and
         * it belongs with the notice in front of you rather than behind an
         * ellipsis on a list. Removing it outright would have quietly deleted a
         * capability nobody asked to lose. */
        ?>
        <section class="panel">
            <header class="panel__head"><h2><i class="fa-solid fa-box-archive"></i> Archive</h2></header>
            <div class="panel__body">
                <?php if ($a['status'] === 'archived'): ?>
                    <p class="text-muted small mb-2">
                        This notice is archived. It is off the public website and out of the
                        working list, but nothing has been deleted.
                    </p>
                    <form method="post"
                          data-confirm="Return &ldquo;<?= e($a['title']) ?>&rdquo; to draft? It comes back into the working list, still unpublished."
                          data-confirm-tone="normal">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="action" value="status">
                        <button name="status" value="draft" class="btn btn-sm btn-outline-secondary w-100">
                            <i class="fa-solid fa-rotate-left"></i> Take out of the archive
                        </button>
                    </form>
                <?php else: ?>
                    <p class="text-muted small mb-2">
                        Files it away: off the public website and out of the working list, but
                        nothing is deleted and it can be brought back at any time.
                    </p>
                    <form method="post"
                          data-confirm="Archive &ldquo;<?= e($a['title']) ?>&rdquo;? It leaves the public website and the working list. Nothing is deleted — it stays under the Archived filter and can be returned to draft."
                          data-confirm-tone="normal">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="action" value="status">
                        <button name="status" value="archived" class="btn btn-sm btn-outline-secondary w-100">
                            <i class="fa-solid fa-box-archive"></i> Archive this notice
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($goesBySms): ?>
        <section class="panel">
            <header class="panel__head"><h2><i class="fa-solid fa-comment-sms"></i> SMS Notification</h2></header>
            <div class="panel__body">
                <div class="sms-preview">
                    <p class="sms-preview__label">Exactly what recipients will receive:</p>
                    <pre class="sms-preview__text"><?= e($smsPreview) ?></pre>
                    <p class="sms-preview__meta">
                        <?= n(mb_strlen($smsPreview)) ?> characters ·
                        <?= n(SmsGateway::segments($smsPreview)) ?> segment(s) ·
                        <?= n(count($recipients)) ?> recipient<?= count($recipients) === 1 ? '' : 's' ?>
                    </p>
                    <?php if ($recipients !== []): ?>
                        <p class="sms-preview__cost">
                            Estimated cost:
                            <strong>PHP <?= number_format(SmsGateway::estimateCost($smsPreview, count($recipients)), 2) ?></strong>
                        </p>
                    <?php endif; ?>
                </div>

                <p class="text-muted small mt-3">
                    <i class="fa-solid fa-<?= SmsGateway::isLive() ? 'tower-broadcast' : 'flask' ?>"></i>
                    <?= e(SmsGateway::driver()->describe()) ?>
                </p>

                <?php if ($summary['total'] > 0): ?>
                    <div class="dispatch-summary">
                        <span><strong><?= n($summary['sent']) ?></strong> sent</span>
                        <span><strong><?= n($summary['failed']) ?></strong> failed</span>
                        <span><strong><?= n($summary['queued']) ?></strong> queued</span>
                    </div>
                <?php endif; ?>

                <?php if ($recipients === []): ?>
                    <div class="alert alert-warning mb-0 mt-3 small">
                        No opted-in managers to notify.
                        <a href="<?= e(base_url('/admin/managers/index.php')) ?>">Register managers</a> first.
                    </div>
                <?php elseif ($a['status'] !== 'published'): ?>
                    <div class="alert alert-info mb-0 mt-3 small">
                        Publish the announcement before sending it.
                    </div>
                <?php elseif (Auth::isOfficer()): ?>
                    <?php
                    /* THE PHP TAGS HERE WERE HTML-ESCAPED, so none of this ran.
                       The officer was asked to confirm spending real SMS credits
                       and shown the source of the ternary instead of the warning.
                       Escaped inside an attribute is invisible in the editor and
                       in view-source; it only appears in the dialog itself.

                       Built above the tag now and echoed in as one string, which
                       is the shape that cannot be escaped by accident. */
                    $dispatchAsk = sprintf(
                        "Send this announcement to %s manager(s)?\n\n%s",
                        n(count($recipients)),
                        SmsGateway::isLive()
                            ? 'This spends real SMS credits and cannot be recalled.'
                            : 'Test mode — written to the log, nothing is actually sent.'
                    );
                    ?>
                    <form method="post" class="mt-3"
                          data-confirm="<?= e($dispatchAsk) ?>"
                          data-confirm-tone="<?= SmsGateway::isLive() ? 'danger' : 'normal' ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="action" value="dispatch">
                        <button class="btn btn-brand w-100">
                            <i class="fa-solid fa-paper-plane"></i>
                            <?= $summary['total'] > 0 ? 'Retry failed / send to new managers' : 'Send Notifications' ?>
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info mb-0 mt-3 small">
                        Only the Tourism Officer can send notifications.
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>
    </div>
</div>

<?php if (!is_modal_request()) { require __DIR__ . '/../_partials/foot.php'; } ?>
