<?php
declare(strict_types=1);

/**
 * TourSync — the Municipal Tourism Office's alert inbox.             Feature 3
 *
 * Everything the destinations have reported, urgent first, newest within that.
 * Portal and SMS land in the same list because an officer should not have to
 * check two places to learn the same fact.
 *
 * Acting on an alert happens here rather than on a separate page: an urgent
 * report is read and answered in the same breath, and a click to a detail
 * screen is a click somebody skips at the wrong moment.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Paginator;
use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\SmsGateway;
use App\Repositories\AlertRepository as Alerts;
use App\Repositories\ManagerNotificationRepository as Bell;

Auth::require();

if (is_post()) {
    Csrf::verify();

    $action  = (string) ($_POST['action'] ?? '');
    $id      = (int) ($_POST['id'] ?? 0);
    $adminId = (int) Auth::id();
    $alert   = $id > 0 ? Alerts::find($id) : null;

    if ($alert === null) {
        Session::flash('danger', 'That alert could not be found.');
        redirect(base_url('/admin/alerts/index.php'));
    }

    $where = (string) ($alert['destination_name'] ?: 'an unverified number');

    /* Only a dismissed alert, and only the officer — see
       AlertRepository::deleteDismissed(), whose WHERE clause is the real rule. */
    if ($action === 'delete') {
        if (!Auth::isOfficer()) {
            Session::flash('danger', 'Only the Tourism Officer can delete an alert.');
        } elseif (Alerts::deleteDismissed($id)) {
            ActivityLog::record('alert.deleted', 'destination_alert', null,
                'Deleted dismissed alert #' . $id . ' from ' . $where);
            Session::flash('success', 'The dismissed alert was deleted.');
        } else {
            Session::flash('danger', 'Only a dismissed alert can be deleted. Dismiss it first, with a reason '
                . '— a real report stays on record.');
        }

        redirect(base_url('/admin/alerts/index.php'));
    }

    if ($action === 'acknowledge') {
        Alerts::acknowledge($id, $adminId);
        ActivityLog::record('alert.acknowledged', 'destination_alert', $id, 'Acknowledged: ' . $where);
        Session::flash('success', 'Acknowledged. The manager can see it has been picked up.');
    }

    if ($action === 'resolve' || $action === 'dismiss') {
        $note = trim((string) ($_POST['resolution_note'] ?? ''));

        /* Dismissing without a reason leaves the manager watching an alert go
           quiet with no idea whether anyone read it. */
        if ($action === 'dismiss' && $note === '') {
            Session::flash('danger', 'Please say why this is being dismissed — the manager sees this.');
            redirect(base_url('/admin/alerts/index.php#alert' . $id));
        }

        if ($action === 'resolve') {
            Alerts::resolve($id, $adminId, $note);
            ActivityLog::record('alert.resolved', 'destination_alert', $id, 'Resolved: ' . $where);
            Session::flash('success', 'Marked resolved.');
        } else {
            Alerts::dismiss($id, $adminId, $note);
            ActivityLog::record('alert.dismissed', 'destination_alert', $id, 'Dismissed: ' . $where);
            Session::flash('success', 'Dismissed with your note.');
        }
    }

    if ($action === 'reclassify') {
        Alerts::reclassify($id, (string) ($_POST['category'] ?? ''), (string) ($_POST['severity'] ?? ''));
        Session::flash('success', 'Reclassified.');
    }

    /* The half that makes this two-way. A manager who reports a landslide and
       hears nothing will drive to town to find out whether it arrived.
     *
     * The reply is written into the alert AND texted, in one action. They were
     * two buttons before, which meant an officer could answer in the system and
     * the manager — who is not sitting in front of the portal either — would
     * never learn there was an answer. */
    if ($action === 'reply') {
        $body = trim((string) ($_POST['reply'] ?? ''));

        if ($body === '') {
            Session::flash('danger', 'Write the message first.');
            redirect(base_url('/admin/alerts/index.php#alert' . $id));
        }

        Alerts::recordReply($id, $body, $adminId);

        /* TEXTING IS NOW A CHOICE, AND THE DEFAULT IS TO TEXT.
         *
         * It used to be unconditional: every reply spent a message, whether the
         * officer meant to send one or was only writing the answer down. The
         * box in the dialog is ticked when it opens, so the common case is
         * unchanged and the officer can decline before it costs anything. */
        $wantsSms = ($_POST['notify_sms'] ?? '1') === '1';

        $sms = $wantsSms
            ? Alerts::notifyManagerOfReply($id, $body)
            : ['sent' => false, 'reason' => 'you chose not to text this one'];

        ActivityLog::record('alert.replied', 'destination_alert', $id,
            'Replied to ' . $where . ': ' . mb_substr($body, 0, 100));

        /* The text may not arrive — no signal, no credit, opted out. The
           bell is the copy that cannot fail to be delivered. */
        $alertRow = Alerts::find($id);

        if ($alertRow !== null) {
            Bell::record((int) $alertRow['destination_id'], 'alert_response',
                'The Office replied to your alert', [
                    'body'        => $body,
                    'link'        => base_url('/manager/alert.php'),
                    'entity_type' => 'destination_alert',
                    'entity_id'   => $id,
                ]);
        }

        if ($sms['sent']) {
            Session::flash('success', SmsGateway::isLive()
                ? 'Reply saved and texted to the manager.'
                : 'Reply saved. SMS is in test mode on this system, so the text went to the log instead of a phone.');
        } else {
            /* Saved either way. The manager sees it in the portal; the text is
               the convenience, not the record. */
            Session::flash('warning', 'Reply saved — the manager will see it in their portal. '
                . 'It was not texted: ' . $sms['reason']);
        }
    }

    redirect(base_url('/admin/alerts/index.php#alert' . $id));
}

/* ---------------------------------------------------------------------------
   The tabs, which are views rather than a partition
   ------------------------------------------------------------------------ */

/* Severity and status answer different questions — how bad is it, and has
   anybody dealt with it — and the office reads the queue by both. So a tab is a
   pair of filters rather than one column, and an alert may legitimately appear
   under two of them: an acknowledged urgent one is still urgent. */
$tabs = [
    'all'          => ['label' => 'All'],
    'attention'    => ['label' => 'Needs Attention', 'status' => 'new'],
    'urgent'       => ['label' => 'Urgent',          'severity' => 'urgent'],
    'acknowledged' => ['label' => 'Acknowledged',    'status' => 'acknowledged'],
    'resolved'     => ['label' => 'Resolved',        'status' => 'resolved'],
    'dismissed'    => ['label' => 'Dismissed',       'status' => 'dismissed'],
];

$tab = (string) ($_GET['tab'] ?? 'all');

if (!isset($tabs[$tab])) {
    $tab = 'all';
}

$status      = (string) ($_GET['status'] ?? '');
$severity    = (string) ($_GET['severity'] ?? '');
$category    = (string) ($_GET['category'] ?? '');
$destination = (int) ($_GET['destination'] ?? 0);
$search      = trim((string) ($_GET['q'] ?? ''));

if ($status !== '' && !isset(Alerts::STATUSES[$status]))       { $status = ''; }
if ($severity !== '' && !isset(Alerts::SEVERITIES[$severity])) { $severity = ''; }
if ($category !== '' && !isset(Alerts::CATEGORIES[$category])) { $category = ''; }

/* The tab sets the floor; the dropdowns narrow it further. A tab that names a
   status and a Status dropdown that names another would ask for nothing, so
   the explicit choice wins and the tab falls back to All. */
$effectiveStatus   = $status !== ''   ? $status   : ($tabs[$tab]['status']   ?? '');
$effectiveSeverity = $severity !== '' ? $severity : ($tabs[$tab]['severity'] ?? '');

if (($status !== '' && isset($tabs[$tab]['status']) && $status !== $tabs[$tab]['status'])
    || ($severity !== '' && isset($tabs[$tab]['severity']) && $severity !== $tabs[$tab]['severity'])) {
    $tab = 'all';
}

$rows = Alerts::inbox([
    'status'         => $effectiveStatus,
    'severity'       => $effectiveSeverity,
    'category'       => $category,
    'destination_id' => $destination,
    'search'         => $search,
], 300);

/* Urgent means "not closed yet", which no single column says. */
if ($tab === 'urgent' && $severity === '') {
    $rows = array_values(array_filter(
        $rows,
        static fn (array $a): bool => in_array($a['status'], ['new', 'acknowledged'], true)
    ));
}

/* Five to a page by default, and five, ten, twenty-five or fifty on request —
   the office's call, remembered in the address so a filter and a page size
   survive each other. */
$perPage = (int) ($_GET['per'] ?? 5);

if (!in_array($perPage, [5, 10, 25, 50], true)) {
    $perPage = 5;
}

$pager  = Paginator::slice($rows, $_GET['page'] ?? null, $perPage);
$alerts = $pager['rows'];
$counts = Alerts::counts();
$tabN   = Alerts::tabCounts();

$isFiltered  = $tab !== 'all' || $status !== '' || $severity !== ''
    || $category !== '' || $destination > 0 || $search !== '';

$destinations = App\Core\Database::all(
    "SELECT id, name FROM destinations WHERE status = 'active' ORDER BY name"
);

/** Rebuilds the query string with one value changed, so a link keeps the rest. */
$link = static function (array $changes) use ($tab, $status, $severity, $category, $destination, $search, $perPage): string {
    $q = array_filter([
        'tab'         => $tab,
        'status'      => $status,
        'severity'    => $severity,
        'category'    => $category,
        'destination' => $destination > 0 ? (string) $destination : '',
        'q'           => $search,
        'per'         => $perPage !== 5 ? (string) $perPage : '',
    ] + [], static fn ($v): bool => $v !== '' && $v !== 'all');

    foreach ($changes as $k => $v) {
        if ($v === '' || $v === null) { unset($q[$k]); } else { $q[$k] = (string) $v; }
    }

    return 'index.php' . ($q === [] ? '' : '?' . http_build_query($q));
};

$pageTitle    = 'Destination Alerts';
$pageIcon     = 'fa-tower-broadcast';
$pageSubtitle = 'What the destinations are reporting';

require __DIR__ . '/../_partials/head.php';
?>

<?php
/* The status a line WEARS, which is not the same as the column it is stored in.
 *
 * A new alert shows how bad it is, because that is the only thing anybody has
 * decided about it yet. Once somebody has acted, it shows what they did. Two
 * badges side by side — "Urgent" and "New" — said one thing twice and left the
 * reader working out which was the state and which the seriousness. */
$badge = static function (array $a): array {
    if ($a['status'] === 'new') {
        return match ($a['severity']) {
            'urgent'  => ['Urgent',          'flag'],
            'warning' => ['Needs attention', 'qr'],
            default   => ['For information', 'void'],
        };
    }

    return match ($a['status']) {
        'acknowledged' => ['Acknowledged', 'qr'],
        'resolved'     => ['Resolved',     'ok'],
        default        => ['Dismissed',    'void'],
    };
};
?>

<?php if ($counts['urgent_new'] > 0): ?>
    <div class="alert alert-danger">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <strong><?= n($counts['urgent_new']) ?> urgent alert(s) have not been picked up.</strong>
        Someone at a destination is waiting to hear that this was read.
    </div>
<?php endif; ?>

<!-- ===================== THE FOUR FIGURES ===================== -->
<div class="stat-grid stat-grid--tight">
    <?php
    $cards = [
        ['icon' => 'fa-bell',                 'tone' => 'amber', 'value' => $counts['new'],          'label' => 'New',          'to' => ['tab' => 'attention']],
        ['icon' => 'fa-triangle-exclamation', 'tone' => 'teal',  'value' => $counts['urgent_new'],   'label' => 'Urgent',       'to' => ['tab' => 'urgent']],
        ['icon' => 'fa-eye',                  'tone' => 'blue',  'value' => $counts['acknowledged'], 'label' => 'Acknowledged', 'to' => ['tab' => 'acknowledged']],
        ['icon' => 'fa-circle-check',         'tone' => 'green', 'value' => $counts['resolved'],     'label' => 'Resolved',     'to' => ['tab' => 'resolved']],
    ];

    foreach ($cards as $card): ?>
        <a class="stat-card stat-card--<?= e($card['tone']) ?>"
           href="index.php?tab=<?= e($card['to']['tab']) ?>">
            <div class="stat-card__icon"><i class="fa-solid <?= e($card['icon']) ?>"></i></div>
            <div class="stat-card__body">
                <p class="stat-card__value"><?= n((int) $card['value']) ?></p>
                <p class="stat-card__label"><?= e($card['label']) ?></p>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<!-- ===================== TABS, THEN FILTERS ===================== -->
<?php /* One compact block. The empty panel that used to sit here held a heading
         and nothing else — a container announcing a list that was rendered
         somewhere below it. */ ?>
<section class="panel alert-controls">
    <nav class="alert-tabs" aria-label="Alert status">
        <?php foreach ($tabs as $key => $t): ?>
            <a class="alert-tabs__tab<?= $tab === $key ? ' is-active' : '' ?>"
               href="<?= e($link(['tab' => $key === 'all' ? null : $key, 'status' => null, 'severity' => null, 'page' => null])) ?>">
                <?= e($t['label']) ?>
                <span class="alert-tabs__n"><?= n((int) ($tabN[$key] ?? 0)) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <form class="alert-filters" method="get">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <?php if ($perPage !== 5): ?><input type="hidden" name="per" value="<?= (int) $perPage ?>"><?php endif; ?>

        <select name="destination" class="form-select form-select-sm" aria-label="Destination">
            <option value="">All destinations</option>
            <?php foreach ($destinations as $d): ?>
                <option value="<?= (int) $d['id'] ?>" <?= $destination === (int) $d['id'] ? 'selected' : '' ?>>
                    <?= e((string) $d['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="category" class="form-select form-select-sm" aria-label="Alert type">
            <option value="">All types</option>
            <?php foreach (Alerts::CATEGORIES as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= $category === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="status" class="form-select form-select-sm" aria-label="Status">
            <option value="">All statuses</option>
            <?php foreach (Alerts::STATUSES as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>

        <div class="search-field">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search alerts">
        </div>

        <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>

        <?php if ($isFiltered): ?>
            <a href="index.php" class="btn btn-sm btn-link">Clear</a>
        <?php endif; ?>

        <a href="inbound.php" class="btn btn-sm btn-outline-secondary alert-filters__log">
            <i class="fa-solid fa-message"></i> SMS log
        </a>
    </form>
</section>

<!-- ===================== THE LIST ===================== -->
<?php if ($alerts === []): ?>

    <section class="panel">
        <div class="panel__body">
            <div class="empty">
                <i class="fa-regular fa-bell"></i>
                <h2><?= $isFiltered ? 'Nothing matches that filter' : 'No destination alerts' ?></h2>
                <p>
                    <?php if ($isFiltered): ?>
                        <?= n((int) ($tabN['all'] ?? 0)) ?> alert(s) are on file &mdash; none of them match.
                        <a href="index.php">Clear the filter</a> to see them all.
                    <?php else: ?>
                        All destinations are currently reporting normally. When a manager reports a
                        closure, a hazard or an injury &mdash; from the portal or by text &mdash; it
                        appears here.
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </section>

<?php else: ?>

    <ul class="alert-list">
        <?php foreach ($alerts as $a): ?>
            <?php
            $id         = (int) $a['id'];
            [$badgeText, $badgeTone] = $badge($a);
            $open       = in_array($a['status'], ['new', 'acknowledged'], true);
            $unverified = $a['destination_id'] === null;
            $place      = (string) ($a['destination_name'] ?: 'Unverified sender');

            /* Two lines at most in the list. The whole message is one click away
               and the row's height must not follow how much somebody wrote. */
            $preview = trim(preg_replace('/\s+/', ' ', (string) $a['message']) ?? '');
            if (mb_strlen($preview) > 190) {
                $preview = mb_substr($preview, 0, 190) . '…';
            }
            ?>
            <li class="alert-row<?= $a['severity'] === 'urgent' && $open ? ' is-urgent' : '' ?>"
                id="alert<?= $id ?>" data-alert="<?= $id ?>">

                <div class="alert-row__main">
                    <p class="alert-row__head">
                        <span class="alert-row__place"><?= e($place) ?></span>
                        <span class="pill pill--void"><?= e(Alerts::CATEGORIES[$a['category']]) ?></span>
                        <span class="pill pill--<?= e($badgeTone) ?>" data-alert-badge="<?= $id ?>"><?= e($badgeText) ?></span>
                    </p>

                    <p class="alert-row__meta">
                        <?= e(format_date((string) $a['created_at'], 'M j, Y · g:i A')) ?>
                        <?php if ($a['raised_by_name']): ?>
                            &middot; <?= e((string) $a['raised_by_name']) ?>
                        <?php endif; ?>
                        <?php if ($a['channel'] === 'sms'): ?>
                            &middot; <i class="fa-solid fa-message" aria-hidden="true"></i>
                            by text from <?= e((string) ($a['from_number'] ?: 'unknown')) ?>
                        <?php endif; ?>
                    </p>

                    <?php if ($unverified): ?>
                        <p class="alert-row__unverified">
                            <i class="fa-solid fa-circle-question" aria-hidden="true"></i>
                            From a number that matches no active manager &mdash; treat as unverified.
                        </p>
                    <?php endif; ?>

                    <p class="alert-row__text"><?= e($preview) ?></p>

                    <?php if ($a['resolution_note']): ?>
                        <p class="alert-row__done">
                            <strong>Reported action:</strong> <?= e((string) $a['resolution_note']) ?>
                            <?php if ($a['acknowledged_by_name']): ?>
                                <span class="cell-sub">&mdash; <?= e((string) $a['acknowledged_by_name']) ?></span>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($a['office_reply']): ?>
                        <p class="alert-row__reply">
                            <strong>Your reply:</strong> <?= e((string) $a['office_reply']) ?>
                            <span class="cell-sub">
                                <?= $a['reply_sent_at']
                                    ? 'texted ' . e(format_date((string) $a['reply_sent_at'], 'M j, g:i A'))
                                    : 'not texted' ?>
                            </span>
                        </p>
                    <?php endif; ?>

                </div>

                <?php /* The same <details> menu the messages inbox uses, opened
                         as a popover so the panel's overflow cannot clip it. */ ?>
                <details class="kebab kebab--pop">
                    <summary aria-label="More actions for the alert from <?= e($place) ?>">
                        <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
                    </summary>

                    <div class="kebab__menu">
                        <button type="button" class="kebab__item" data-alert-open="details" data-id="<?= $id ?>">
                            <i class="fa-solid fa-file-lines" aria-hidden="true"></i> View report
                        </button>
                        <button type="button" class="kebab__item" data-alert-open="activity" data-id="<?= $id ?>">
                            <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> View activity
                        </button>
                        <?php if ($open): ?>
                            <button type="button" class="kebab__item" data-alert-open="reclassify" data-id="<?= $id ?>">
                                <i class="fa-solid fa-tags" aria-hidden="true"></i> Reclassify
                            </button>
                            <button type="button" class="kebab__item kebab__item--danger" data-alert-open="dismiss" data-id="<?= $id ?>">
                                <i class="fa-solid fa-ban" aria-hidden="true"></i> Dismiss
                            </button>
                        <?php endif; ?>

                        <?php /* Delete, for what the office already decided was not a
                                 real incident. A resolved alert is the record that a
                                 hazard was dealt with and stays. */ ?>
                        <?php if ($a['status'] === 'dismissed' && Auth::isOfficer()): ?>
                            <?php
                            $askDelete = 'Delete this dismissed alert from ' . $place . ' permanently? '
                                . 'Its report, replies and activity go with it. The inbound SMS log keeps '
                                . 'its line. This cannot be undone.';
                            ?>
                            <form method="post" data-confirm="<?= e($askDelete) ?>" data-confirm-tone="danger">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <button type="submit" name="action" value="delete"
                                        class="kebab__item kebab__item--danger">
                                    <i class="fa-solid fa-trash-can" aria-hidden="true"></i> Delete
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </details>

                <?php /* Its own grid row, spanning under the kebab as well, so
                         Reply reaches the card's right edge instead of stopping
                         42px short of it. */ ?>
                <div class="alert-row__acts">
                    <?php if ($a['status'] === 'new'): ?>
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button type="submit" name="action" value="acknowledge"
                                    class="btn btn-sm btn-outline-secondary">
                                <i class="fa-solid fa-eye"></i> Acknowledge
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($open): ?>
                        <button type="button" class="btn btn-sm btn-brand" data-alert-open="resolve" data-id="<?= $id ?>">
                            <i class="fa-solid fa-circle-check"></i> Resolve
                        </button>
                    <?php endif; ?>

                    <button type="button" class="btn btn-sm btn-outline-secondary" data-alert-open="reply" data-id="<?= $id ?>">
                        <i class="fa-solid fa-reply"></i> Reply to Manager
                    </button>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php /* Hidden below one page, as asked: a pager under five rows is furniture
             that says the list is longer than it is. The test is against five and
             not against $perPage, or picking "50 per page" on a list of fifteen
             would take the size selector away with it and strand the reader. */ ?>
    <?php if ($pager['total'] > 5): ?>
        <div class="alert-pager">
            <p class="alert-pager__count">
                Showing <?= n($pager['from']) ?>&ndash;<?= n($pager['to']) ?> of
                <?= n($pager['total']) ?> alert<?= $pager['total'] === 1 ? '' : 's' ?>
            </p>

            <form method="get" class="alert-pager__size">
                <?php foreach (['tab' => $tab === 'all' ? '' : $tab, 'status' => $status,
                                'severity' => $severity, 'category' => $category,
                                'destination' => $destination > 0 ? (string) $destination : '',
                                'q' => $search] as $k => $v): ?>
                    <?php if ($v !== ''): ?>
                        <input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <label class="visually-hidden" for="perPage">Alerts per page</label>
                <select id="perPage" name="per" class="form-select form-select-sm"
                        onchange="this.form.submit()">
                    <?php foreach ([5, 10, 25, 50] as $n): ?>
                        <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?> per page</option>
                    <?php endforeach; ?>
                </select>
            </form>

            <nav class="alert-pager__pages" aria-label="Pages">
                <a class="alert-pager__step<?= $pager['page'] <= 1 ? ' is-off' : '' ?>"
                   href="<?= e($link(['page' => $pager['page'] - 1])) ?>"
                   <?= $pager['page'] <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                   aria-label="Previous page">&lsaquo;</a>

                <?php for ($i = 1; $i <= $pager['pages']; $i++): ?>
                    <a class="alert-pager__num<?= $i === $pager['page'] ? ' is-active' : '' ?>"
                       href="<?= e($link(['page' => $i === 1 ? null : $i])) ?>"><?= $i ?></a>
                <?php endfor; ?>

                <a class="alert-pager__step<?= $pager['page'] >= $pager['pages'] ? ' is-off' : '' ?>"
                   href="<?= e($link(['page' => $pager['page'] + 1])) ?>"
                   <?= $pager['page'] >= $pager['pages'] ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                   aria-label="Next page">&rsaquo;</a>
            </nav>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php if ($alerts !== []): ?>

    <?php
    /* Every alert on this page, for the dialogs to read. Inline rather than
       fetched: the rows are already here, and a round trip per open would put
       a spinner in front of something the browser is holding. */
    $payload = [];

    foreach ($alerts as $a) {
        [$bText, $bTone] = $badge($a);

        $payload[(string) $a['id']] = [
            'place'    => (string) ($a['destination_name'] ?: 'Unverified sender'),
            'category' => Alerts::CATEGORIES[$a['category']],
            'catKey'   => (string) $a['category'],
            'sevKey'   => (string) $a['severity'],
            'badge'    => $bText,
            'tone'     => $bTone,
            'when'     => format_date((string) $a['created_at'], 'F j, Y \a\t g:i A'),
            'who'      => (string) ($a['raised_by_name'] ?: ''),
            'channel'  => (string) $a['channel'],
            'number'   => (string) ($a['from_number'] ?: ''),
            'body'     => (string) $a['message'],
            'note'     => (string) ($a['resolution_note'] ?? ''),
            'reply'    => (string) ($a['office_reply'] ?? ''),
            'texted'   => $a['reply_sent_at']
                ? format_date((string) $a['reply_sent_at'], 'M j, g:i A') : '',
            'open'     => in_array($a['status'], ['new', 'acknowledged'], true),

            /* Enough to draw the history without a second request: every step
               this alert has been through is already on its own row. */
            'steps'    => array_values(array_filter([
                ['Reported', format_date((string) $a['created_at'], 'M j, Y · g:i A'),
                 (string) ($a['raised_by_name'] ?: ($a['channel'] === 'sms' ? 'by text' : ''))],

                $a['acknowledged_at'] ? ['Acknowledged',
                    format_date((string) $a['acknowledged_at'], 'M j, Y · g:i A'),
                    (string) ($a['acknowledged_by_name'] ?: '')] : null,

                $a['replied_at'] ? ['Office replied',
                    format_date((string) $a['replied_at'], 'M j, Y · g:i A'),
                    (string) ($a['replied_by_name'] ?: '')
                        . ($a['reply_sent_at'] ? ' · texted' : ' · not texted')] : null,

                $a['resolved_at'] ? [
                    $a['status'] === 'dismissed' ? 'Dismissed' : 'Resolved',
                    format_date((string) $a['resolved_at'], 'M j, Y · g:i A'),
                    (string) ($a['acknowledged_by_name'] ?: '')] : null,
            ])),
        ];
    }
    ?>

    <script type="application/json" id="alertData"><?= json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?></script>

    <?php /* ONE DIALOG PER JOB, filled when it opens.
             The three that write share a <form> each so a browser with the
             script blocked still posts them; the two that only read carry no
             form at all. */ ?>

    <!-- ---------------- resolve ---------------- -->
    <dialog class="sheet sheet--alert" id="alertResolve" aria-labelledby="alertResolveTitle">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="id" data-alert-id>

            <header class="sheet__head">
                <h2 id="alertResolveTitle"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Resolve Alert</h2>
                <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </header>

            <div class="sheet__body">
                <dl class="alert-facts" data-alert-facts></dl>

                <label class="form-label" for="resolveNote">Resolution / action taken</label>
                <textarea id="resolveNote" name="resolution_note" class="form-control" rows="3"
                          maxlength="600" placeholder="e.g. Barangay cleared the trail; reopened 2pm"></textarea>
                <p class="alert-hint">The manager sees this on their own screen.</p>

                <label class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" name="notify_manager" value="1" checked>
                    <span class="form-check-label">Notify the destination manager</span>
                </label>
            </div>

            <footer class="sheet__foot">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close>Cancel</button>
                <button type="submit" name="action" value="resolve" class="btn btn-sm btn-brand">
                    <i class="fa-solid fa-circle-check"></i> Resolve Alert
                </button>
            </footer>
        </form>
    </dialog>

    <!-- ---------------- reply ---------------- -->
    <dialog class="sheet sheet--alert" id="alertReply" aria-labelledby="alertReplyTitle">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="id" data-alert-id>

            <header class="sheet__head">
                <h2 id="alertReplyTitle"><i class="fa-solid fa-reply" aria-hidden="true"></i> Reply to Manager</h2>
                <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </header>

            <div class="sheet__body">
                <dl class="alert-facts" data-alert-facts></dl>

                <label class="form-label" for="replyBody">Message</label>
                <textarea id="replyBody" name="reply" class="form-control" rows="3" maxlength="300"
                          placeholder="e.g. Received. Barangay rescue is on the way."></textarea>

                <label class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" checked disabled>
                    <span class="form-check-label">Save to the alert record</span>
                </label>
                <p class="alert-hint">Always saved &mdash; it is the copy that cannot fail to arrive.</p>

                <label class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" name="notify_sms" value="1" id="replySms" checked>
                    <span class="form-check-label">Send SMS notification</span>
                </label>
                <p class="alert-hint">
                    <?php if (SmsGateway::isLive()): ?>
                        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                        Texting is live on this system &mdash; this sends a real message and spends credit.
                    <?php else: ?>
                        SMS is in test mode &mdash; the text goes to the log rather than a phone.
                    <?php endif; ?>
                </p>
            </div>

            <footer class="sheet__foot">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close>Cancel</button>
                <button type="submit" name="action" value="reply" class="btn btn-sm btn-brand">
                    <i class="fa-solid fa-paper-plane"></i> Send Reply
                </button>
            </footer>
        </form>
    </dialog>

    <!-- ---------------- reclassify ---------------- -->
    <dialog class="sheet sheet--alert" id="alertReclassify" aria-labelledby="alertReclassifyTitle">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="id" data-alert-id>

            <header class="sheet__head">
                <h2 id="alertReclassifyTitle"><i class="fa-solid fa-tags" aria-hidden="true"></i> Reclassify Alert</h2>
                <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </header>

            <div class="sheet__body">
                <dl class="alert-facts" data-alert-facts></dl>

                <p class="alert-hint mb-3">
                    The type and urgency were read off the wording of the message. You have the whole
                    of it &mdash; correct them if the guess is wrong.
                </p>

                <label class="form-label" for="reCategory">Alert type</label>
                <select id="reCategory" name="category" class="form-select form-select-sm mb-3">
                    <?php foreach (Alerts::CATEGORIES as $k => $label): ?>
                        <option value="<?= e($k) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <label class="form-label" for="reSeverity">Urgency</label>
                <select id="reSeverity" name="severity" class="form-select form-select-sm">
                    <?php foreach (Alerts::SEVERITIES as $k => $label): ?>
                        <option value="<?= e($k) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <footer class="sheet__foot">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close>Cancel</button>
                <button type="submit" name="action" value="reclassify" class="btn btn-sm btn-brand">Save</button>
            </footer>
        </form>
    </dialog>

    <!-- ---------------- dismiss ---------------- -->
    <dialog class="sheet sheet--alert" id="alertDismiss" aria-labelledby="alertDismissTitle">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="id" data-alert-id>

            <header class="sheet__head">
                <h2 id="alertDismissTitle"><i class="fa-solid fa-ban" aria-hidden="true"></i> Dismiss Alert</h2>
                <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </header>

            <div class="sheet__body">
                <dl class="alert-facts" data-alert-facts></dl>

                <label class="form-label" for="dismissNote">Why is this being dismissed? <span class="req">*</span></label>
                <textarea id="dismissNote" name="resolution_note" class="form-control" rows="3"
                          maxlength="600" required
                          placeholder="e.g. Duplicate of the alert raised an hour earlier."></textarea>
                <p class="alert-hint">
                    Required. The manager sees this &mdash; an alert that goes quiet with no reason
                    leaves them wondering whether anyone read it.
                </p>
            </div>

            <footer class="sheet__foot">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close>Cancel</button>
                <button type="submit" name="action" value="dismiss" class="btn btn-sm btn-outline-danger">
                    <i class="fa-solid fa-ban"></i> Dismiss Alert
                </button>
            </footer>
        </form>
    </dialog>

    <!-- ---------------- the report, read only ---------------- -->
    <dialog class="sheet sheet--alert" id="alertDetails" aria-labelledby="alertDetailsTitle">
        <header class="sheet__head">
            <h2 id="alertDetailsTitle"><i class="fa-solid fa-file-lines" aria-hidden="true"></i> Alert Report</h2>
            <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </header>

        <div class="sheet__body">
            <dl class="alert-facts" data-alert-facts></dl>
            <div class="alert-body" id="alertFullBody"></div>
            <div id="alertExtras"></div>
        </div>

        <footer class="sheet__foot">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close>Close</button>
        </footer>
    </dialog>

    <!-- ---------------- what has happened to it ---------------- -->
    <dialog class="sheet sheet--alert" id="alertActivity" aria-labelledby="alertActivityTitle">
        <header class="sheet__head">
            <h2 id="alertActivityTitle"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Activity</h2>
            <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </header>

        <div class="sheet__body">
            <dl class="alert-facts" data-alert-facts></dl>
            <ol class="alert-steps" id="alertSteps"></ol>
        </div>

        <footer class="sheet__foot">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close>Close</button>
        </footer>
    </dialog>

<?php endif; ?>

<?php if ($alerts !== []): ?>
<script>
(function () {
    'use strict';

    var raw = document.getElementById('alertData');
    if (!raw) { return; }

    var DATA = JSON.parse(raw.textContent || '{}');

    var DIALOGS = {
        resolve:    'alertResolve',
        reply:      'alertReply',
        reclassify: 'alertReclassify',
        dismiss:    'alertDismiss',
        details:    'alertDetails',
        activity:   'alertActivity',
    };

    /* Every dialog opens on the same four facts, so they are built once and
       poured into whichever one is opening. A reader who has just clicked a row
       in a list of thirty needs to see which one they are acting on. */
    function facts(dl, m) {
        dl.innerHTML = '';

        var add = function (term, value, pill) {
            var d = document.createElement('div');
            var t = document.createElement('dt');
            var v = document.createElement('dd');
            t.textContent = term;
            if (pill) {
                var s = document.createElement('span');
                s.className = 'pill pill--' + pill;
                s.textContent = value;
                v.appendChild(s);
            } else {
                v.textContent = value;
            }
            d.appendChild(t);
            d.appendChild(v);
            dl.appendChild(d);
        };

        add('Destination', m.place);
        add('Alert type', m.category);
        add('Status', m.badge, m.tone);
        add('Reported', m.when + (m.who ? ' · ' + m.who : ''));
    }

    function open(kind, id) {
        var m = DATA[String(id)];
        var d = document.getElementById(DIALOGS[kind]);
        if (!m || !d) { return; }

        d.querySelectorAll('[data-alert-facts]').forEach(function (dl) { facts(dl, m); });

        var idField = d.querySelector('[data-alert-id]');
        if (idField) { idField.value = id; }

        if (kind === 'resolve') {
            d.querySelector('#resolveNote').value = m.note || '';
        }

        if (kind === 'reply') {
            d.querySelector('#replyBody').value = m.reply || '';
            /* Ticked afresh each time. A dialog that remembered the last
               decision would text somebody because of a choice made about a
               different alert. */
            d.querySelector('#replySms').checked = true;
        }

        if (kind === 'reclassify') {
            d.querySelector('#reCategory').value = m.catKey;
            d.querySelector('#reSeverity').value = m.sevKey;
        }

        if (kind === 'dismiss') {
            d.querySelector('#dismissNote').value = '';
        }

        if (kind === 'details') {
            d.querySelector('#alertFullBody').textContent = m.body;

            var extras = d.querySelector('#alertExtras');
            extras.innerHTML = '';

            var block = function (label, text, tone, tail) {
                if (!text) { return; }
                var p = document.createElement('p');
                p.className = 'alert-row__' + tone;
                var b = document.createElement('strong');
                b.textContent = label + ' ';
                p.appendChild(b);
                p.appendChild(document.createTextNode(text));
                if (tail) {
                    var s = document.createElement('span');
                    s.className = 'cell-sub';
                    s.textContent = ' ' + tail;
                    p.appendChild(s);
                }
                extras.appendChild(p);
            };

            block('Reported action:', m.note, 'done', '');
            block('Your reply:', m.reply, 'reply', m.texted ? 'texted ' + m.texted : 'not texted');

            if (m.channel === 'sms' && m.number) {
                block('Came by text from:', m.number, 'meta', '');
            }
        }

        if (kind === 'activity') {
            var ol = d.querySelector('#alertSteps');
            ol.innerHTML = '';
            (m.steps || []).forEach(function (s) {
                var li = document.createElement('li');
                var t = document.createElement('span');
                var w = document.createElement('span');
                t.className = 'alert-steps__what';
                t.textContent = s[0];
                w.className = 'alert-steps__when';
                w.textContent = s[1] + (s[2] ? ' · ' + s[2] : '');
                li.appendChild(t);
                li.appendChild(w);
                ol.appendChild(li);
            });
        }

        d.showModal();
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest && event.target.closest('[data-alert-open]');
        if (!trigger) { return; }

        event.preventDefault();

        /* Close the menu behind it, or it hangs open under the dialog. */
        var menu = trigger.closest('details.kebab');
        if (menu) { menu.open = false; }

        open(trigger.getAttribute('data-alert-open'), trigger.getAttribute('data-id'));
    });

    /* Dismiss demands a reason and the browser will not show its own message
       for a required field inside a dialog that is already open — it focuses
       silently. Said out loud instead. */
    var dismiss = document.getElementById('alertDismiss');

    if (dismiss) {
        dismiss.querySelector('form').addEventListener('submit', function (e) {
            var note = dismiss.querySelector('#dismissNote');
            if (note.value.trim() !== '') { return; }

            e.preventDefault();
            note.focus();

            var say = window.TourSync && window.TourSync.showError;
            if (typeof say === 'function') {
                say('Please say why this is being dismissed — the manager sees this.');
            }
        });
    }
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
