<?php
declare(strict_types=1);

/**
 * TourSync — tour guide requests.                                    Feature 4
 *
 * Somebody wants a guide. This is where the office answers.
 *
 * Answered here rather than on a detail page, for the same reason the alert
 * inbox works this way: the officer reads the request and arranges the guide in
 * one sitting, and a click through to a second screen is a click somebody skips
 * when the phone is ringing.
 *
 * EVERY DECISION TEXTS THE VISITOR, and the send is recorded on the row. A
 * request marked "assigned" that the visitor never heard about is indis-
 * tinguishable afterwards from one they did, which is how a tourist ends up
 * waiting at a trailhead for a guide nobody told them about.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Paginator;
use App\Core\Session;
use App\Repositories\TourGuideRepository as Guides;

Auth::require();

if (is_post()) {
    Csrf::verify();

    $id      = (int) ($_POST['id'] ?? 0);
    $status  = (string) ($_POST['status'] ?? '');
    $adminId = (int) Auth::id();
    $request = $id > 0 ? Guides::find($id) : null;

    if ($request === null) {
        Session::flash('danger', 'That request could not be found.');
        redirect(base_url('/admin/guides/index.php'));
    }

    $refusal = Guides::decide($id, $status, $adminId, [
        /* Chosen from the tour guide list when there is one; the two text fields remain
           the fallback for a guide who has not been entered yet. The repository
           settles which wins and refuses a guide whose ID is not good. */
        'guide_id'      => $_POST['guide_id']      ?? 0,
        'guide_name'    => $_POST['guide_name']    ?? '',
        'guide_contact' => $_POST['guide_contact'] ?? '',
        'office_note'   => $_POST['office_note']   ?? '',
    ]);

    if ($refusal !== null) {
        Session::flash('danger', $refusal);
        redirect(base_url('/admin/guides/index.php#req' . $id));
    }

    ActivityLog::record(
        'guide.' . $status,
        'tour_guide_request',
        $id,
        Guides::STATUSES[$status] . ': ' . $request['reference_code']
    );

    /* Told, or explicitly not told. 'completed' and 'cancelled' are book-
       keeping the office does after the fact — texting somebody a week later to
       say their finished tour is marked finished is noise. */
    if (in_array($status, ['acknowledged', 'assigned', 'declined'], true)) {
        $sms = Guides::notifyVisitor($id);

        if ($sms['sent']) {
            Session::flash('success', 'Saved, and the visitor has been texted.');
        } else {
            /* Saved either way. What changes is whether the office now has to
               pick up a phone, so say so plainly instead of a bare success. */
            Session::flash('warning', 'Saved — but the visitor was NOT texted. ' . $sms['error']);
        }
    } else {
        Session::flash('success', 'Saved.');
    }

    redirect(base_url('/admin/guides/index.php#req' . $id));
}

$status = (string) ($_GET['status'] ?? '');

if ($status !== '' && !isset(Guides::STATUSES[$status])) {
    $status = '';
}

$search        = trim((string) ($_GET['q'] ?? ''));
$destinationId = (int) ($_GET['destination'] ?? 0);

/* WHEN THE VISIT IS, not when the request came in — see the note in
   TourGuideRepository::inbox(). Validated against the list this page offers so
   a hand-edited query string cannot ask for a window that does not exist. */
$dateWindow = (string) ($_GET['date'] ?? '');

$dateWindows = [
    'today' => 'Today',
    'week'  => 'Next 7 days',
    'month' => 'Next 30 days',
    'past'  => 'Date already passed',
];

if (!isset($dateWindows[$dateWindow])) {
    $dateWindow = '';
}

/* WHICH TAB, decided here because the page size depends on it. */
$tab = ($_GET['tab'] ?? '') === 'settled' ? 'settled' : 'action';

/* HOW MANY TO A PAGE — AND THE TWO TABS DO NOT WANT THE SAME NUMBER.
 *
 * Measured: a live request card is 476 px tall, because it carries the request,
 * the action and the timeline side by side. Six of them is 2,856 px — three and
 * a half windows of scrolling to reach the second one. Two is 1.2 windows, which
 * is one screen and a glance.
 *
 * A settled row is a table line. Two of those to a page would mean five pages to
 * read nine finished requests, which is the same mistake pointing the other way.
 *
 * So the default follows the shape of what is being shown, and the sizes offered
 * differ for the same reason. An allow-list rather than a free number: ?per=99999
 * would fetch and render every request the office has ever taken. */
$pageSizes = $tab === 'settled' ? [15, 25, 50, 100] : [3, 5, 10];

/* THE CHOICE IS REMEMBERED, because a preference you must set again every time
 * is not a preference — it is a chore.
 *
 * A COOKIE, not the session and not a column on `admins`.
 *
 *   session   would be forgotten at every logout, so the officer resets it each
 *             morning; that is the problem, not the fix
 *   admins    would mean a migration and a schema for what is a display setting,
 *             not data the office would ever report on
 *
 * The office shares machines, so this is per-browser rather than per-officer.
 * For "how tall should this page be" that is harmless — and it is the same
 * trade every admin panel makes for a table density setting.
 *
 * Same flags the session cookie uses: httponly so no script can read it, Lax so
 * it is not replayed cross-site, secure whenever the request arrived over TLS.
 * A year, because nobody wants to be asked again in March. */
$perCookie = 'tgq_per_' . $tab;
$perPage   = (int) ($_GET['per'] ?? $_COOKIE[$perCookie] ?? $pageSizes[0]);

if (!in_array($perPage, $pageSizes, true)) {
    $perPage = $pageSizes[0];
}

/* Written only when the officer actually chose, so simply visiting a link that
   happens to carry ?per= does not silently rewrite their preference... which it
   would, since the tab links carry it. Guarded on the parameter being present
   AND different from what is stored. */
if (isset($_GET['per']) && (string) ($_COOKIE[$perCookie] ?? '') !== (string) $perPage) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');

    setcookie($perCookie, (string) $perPage, [
        'expires'  => time() + 31536000,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/* SPLIT FIRST, THEN PAGE. THE OTHER ORDER WAS A BUG.
 *
 * This used to page the whole inbox and split the resulting six rows into live
 * and settled — so the header counted what happened to be ON THAT PAGE, not
 * what exists. Page one said "0 needing action, 6 settled" and page two said
 * "0 needing action, 3 settled", and neither list was ever complete on one
 * screen because the two were shuffled together by arrival order.
 *
 * Splitting first makes each tab a list in its own right, with its own honest
 * total and its own pagination. */
$allRequests = Guides::inbox([
    'status'         => $status,
    'destination_id' => $destinationId,
    'search'         => $search,
    'date'           => $dateWindow,
], 500);

$liveRequests    = [];
$settledRequests = [];

foreach ($allRequests as $row) {
    /* OUTSTANDING_STATUSES is reused rather than a second list written here: it
       already means "a promise made and not yet discharged", which is exactly
       the question this split asks. */
    if (in_array($row['status'], Guides::OUTSTANDING_STATUSES, true)) {
        $liveRequests[] = $row;
    } else {
        $settledRequests[] = $row;
    }
}

/* TWO TABS, NOT A FOLD.
 *
 * Settled work was a <details> that opened itself when the queue was clear —
 * a behaviour an officer cannot predict, and one that hid a list rather than
 * filing it. Tabs put both lists at the same level: neither is hidden, each is
 * one click away, and each pages on its own. */
$activeList = $tab === 'settled' ? $settledRequests : $liveRequests;

$pager    = Paginator::slice($activeList, $_GET['page'] ?? null, $perPage);
$requests = $pager['rows'];

/* Every destination on the rows being drawn, in ONE query. Asking per card
   would be a round trip each on a screen that takes one. */
$stops = Guides::destinationsForMany(array_column($requests, 'id'));

/**
 * The same list for one row of the calendar panels.
 *
 * Those two panels are fed by upcoming() and overdue(), which are separate
 * queries from the inbox — a request can appear in a panel without being on
 * the current page of the inbox, so $stops above does not necessarily cover it.
 * Falls back to the primary destination the query already joined.
 *
 * @param array<string, mixed> $row
 */
$stopsFor = static function (array $row) use (&$stops): string {
    $id = (int) $row['id'];

    if (!isset($stops[$id])) {
        $stops[$id] = array_column(Guides::destinationsFor($id), 'name');
    }

    if ($stops[$id] !== []) {
        return e(Guides::nameList($stops[$id]));
    }

    $single = trim((string) ($row['destination_name'] ?? ''));

    if ($single !== '') {
        return e($single);
    }

    return !empty($row['needs_advice'])
        ? '<span class="text-muted">to be advised</span>'
        : '—';
};

/* THE TOUR GUIDE LIST, AND WHO IS ALREADY OUT.
 *
 * Loaded once for the whole page rather than per request card. Only guides
 * whose ID is active and unexpired appear at all — §19's gate — and the
 * repository enforces the same rule again on submit, because a dropdown is a
 * convenience and the rule has to hold against a posted form too.
 *
 * The busy map answers "already assigned that day" in one grouped query. It
 * FLAGS rather than hides: a request carries one optional time and no duration,
 * so "free at 9am" cannot honestly be answered from what is stored, and the
 * officer — who knows their own people — decides. */
$roster = App\Repositories\TourGuideRosterRepository::availableOn(null);

$busyMap = [];

foreach (App\Core\Database::all(
    "SELECT guide_id, preferred_date, COUNT(*) AS c
       FROM tour_guide_requests
      WHERE guide_id IS NOT NULL AND status = 'assigned' AND preferred_date IS NOT NULL
      GROUP BY guide_id, preferred_date"
) as $row) {
    $busyMap[(int) $row['guide_id']][(string) $row['preferred_date']] = (int) $row['c'];
}

$counts       = Guides::counts();
$destinations = App\Core\Database::all(
    "SELECT id, name FROM destinations WHERE status = 'active' ORDER BY name"
);
$openNow  = $counts['new'] + $counts['acknowledged'];

/* THE CALENDAR, NOT JUST THE QUEUE.
 *
 * The inbox is ordered by when a request arrived, which is not the order the
 * visitors turn up in. A request accepted three weeks out was assigned and
 * then out of sight until somebody was standing at the counter. */
/* Only when nobody is searching. The calendar is a standing view of the week;
   somebody who has typed a reference into the filter is looking for one person,
   and a panel beside the result showing four other visitors is noise they have
   to read past to be sure they found the right one. */
/* Where every visitor is collected, and the number they can ring. Both come
   from Settings; the second is blank on this installation, which is why the
   assign panel says so out loud. */
$defaultMeetingPoint = Guides::officeMeetingPoint();
$officePhone         = trim((string) (setting('office_phone', '') ?? ''));

$browsing = $search === '' && $status === '' && $destinationId === 0 && $dateWindow === '';

$upcoming = $browsing ? Guides::upcoming(7) : [];
$overdue  = $browsing ? Guides::overdue()   : [];

/* THE CALENDAR ONLY EARNS ITS SPACE WHEN IT SHOWS SOMETHING THE CARDS DO NOT.
 *
 * These two panels exist because a request accepted three weeks out was assigned
 * and then out of sight — the queue is ordered by arrival, not by when visitors
 * turn up. That is a real problem and they solve it.
 *
 * But every row they hold is also an expanded card further down the same screen
 * unless the queue is paginated or long. Measured on this installation: one
 * upcoming row, one live card, the same request. Two representations of one
 * thing on one screen is the definition of clutter, and it made the officer read
 * past a table to reach the work.
 *
 * So they are filtered down to what is genuinely out of sight, and disappear
 * when that is nothing. */
$onScreen = array_column($liveRequests, 'id');

$notOnScreen = static fn(array $rows): array => array_values(array_filter(
    $rows,
    static fn(array $row): bool => !in_array((int) $row['id'], array_map('intval', $onScreen), true)
));

$upcoming = $notOnScreen($upcoming);
$overdue  = $notOnScreen($overdue);

$pageTitle    = 'Tour Guide Requests';
$pageIcon     = 'fa-person-hiking';
$pageSubtitle = 'Visitors asking the Office to arrange a local guide';

require __DIR__ . '/../_partials/head.php';
?>

<?php if ($counts['new'] > 0): ?>
    <div class="alert alert-danger">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <strong><?= n($counts['new']) ?> request(s) nobody has answered.</strong>
        A visitor who hears nothing assumes the answer is no.
    </div>
<?php endif; ?>

<?php if ($overdue !== []): ?>
    <?php /* Louder than the "nobody answered" banner above, because these are
             visits that have already been and gone. Whatever happened that day,
             nobody wrote it down. */ ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-calendar-xmark"></i>
        <div>
            <strong><?= n(count($overdue)) ?> visit<?= count($overdue) === 1 ? '' : 's' ?>
            went by without being closed.</strong>
            <p class="mb-0">
                Each one still says a guide is expected. Mark what actually happened
                so the records mean something &mdash; and so a guide's afternoon is
                not written down as a visit that never occurred.
            </p>
        </div>
    </div>

    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-solid fa-calendar-xmark"></i> Gone by, still open</h2>
            <p class="panel__count"><?= n(count($overdue)) ?></p>
        </header>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Was due</th><th>Visitor</th><th>Destination</th>
                        <th>Guide</th><th>Status</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($overdue as $o): ?>
                        <tr>
                            <td data-label="Was due">
                                <strong><?= e(format_date((string) $o['preferred_date'], 'M j')) ?></strong>
                                <span class="text-muted small d-block">
                                    <?= n((int) $o['days_late']) ?> day<?= (int) $o['days_late'] === 1 ? '' : 's' ?> ago
                                </span>
                            </td>
                            <td data-label="Visitor">
                                <?= e((string) $o['visitor_name']) ?>
                                <span class="text-muted small d-block"><?= e((string) $o['reference_code']) ?></span>
                            </td>
                            <td data-label="Destination"><?= $stopsFor($o) ?></td>
                            <td data-label="Guide"><?= e((string) ($o['guide_name'] ?: '—')) ?></td>
                            <td data-label="Status">
                                <span class="pill pill--flag"><?= e(Guides::STATUSES[$o['status']]) ?></span>
                            </td>
                            <td data-label="" class="text-end">
                                <a href="#req<?= (int) $o['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                    Close it
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($upcoming !== []): ?>
    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-regular fa-calendar-check"></i> Coming in the next seven days</h2>
            <p class="panel__count"><?= n(count($upcoming)) ?></p>
        </header>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>When</th><th>Visitor</th><th>Party</th>
                        <th>Destination</th><th>Guide</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($upcoming as $u): ?>
                        <?php
                        $away = (int) $u['days_away'];

                        /* Named rather than counted for the two days somebody is
                           actually preparing for. "In 0 days" is not how anyone
                           speaks, and today is the row that matters most. */
                        $when = match (true) {
                            $away === 0 => 'Today',
                            $away === 1 => 'Tomorrow',
                            default     => 'In ' . $away . ' days',
                        };

                        /* Assigned means somebody is expected and knows to come.
                           Anything else, this close to the date, is the office
                           still owing an answer. */
                        $ready = $u['status'] === 'assigned';
                        ?>
                        <tr<?= !$ready && $away <= 1 ? ' class="table-warning"' : '' ?>>
                            <td data-label="When">
                                <strong><?= e($when) ?></strong>
                                <span class="text-muted small d-block">
                                    <?= e(format_date((string) $u['preferred_date'], 'D, M j')) ?>
                                    <?= $u['preferred_time']
                                        ? ' &middot; ' . e(Guides::formatTime((string) $u['preferred_time']))
                                        : ' &middot; <span class="text-muted">any time</span>' ?>
                                </span>
                            </td>
                            <td data-label="Visitor">
                                <a href="#req<?= (int) $u['id'] ?>"><?= e((string) $u['visitor_name']) ?></a>
                                <span class="text-muted small d-block"><?= e((string) $u['reference_code']) ?></span>
                            </td>
                            <td data-label="Party"><?= n((int) $u['party_size']) ?></td>
                            <td data-label="Destination"><?= $stopsFor($u) ?></td>
                            <td data-label="Guide">
                                <?= $u['guide_name']
                                    ? e((string) $u['guide_name'])
                                    : '<span class="text-danger">not assigned</span>' ?>
                            </td>
                            <td data-label="Status">
                                <span class="pill pill--<?= $ready ? 'ok' : 'flag' ?>">
                                    <?= e(Guides::STATUSES[$u['status']]) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<div class="stat-grid">
    <?php
    $cards = [
        ['icon' => 'fa-bell',         'tone' => 'amber', 'value' => $counts['new'],          'label' => 'New',              'q' => 'status=new'],
        ['icon' => 'fa-eye',          'tone' => 'blue',  'value' => $counts['acknowledged'], 'label' => 'Arranging',        'q' => 'status=acknowledged'],
        ['icon' => 'fa-user-check',   'tone' => 'teal',  'value' => $counts['assigned'],     'label' => 'Guide assigned',   'q' => 'status=assigned'],
        ['icon' => 'fa-circle-check', 'tone' => 'green', 'value' => $counts['completed'],    'label' => 'Completed',        'q' => 'status=completed'],
    ];

    foreach ($cards as $card): ?>
        <a class="stat-card stat-card--<?= e($card['tone']) ?>" href="index.php?<?= e($card['q']) ?>">
            <div class="stat-card__icon"><i class="fa-solid <?= e($card['icon']) ?>"></i></div>
            <div class="stat-card__body">
                <p class="stat-card__value"><?= n((int) $card['value']) ?></p>
                <p class="stat-card__label"><?= e($card['label']) ?></p>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($officePhone === '' && $liveRequests !== []): ?>
    <?php /* SAID ONCE, NOT ON EVERY CARD. This is a Settings problem, not a
             property of any one request — repeated down the queue it was six
             copies of the same red sentence, which is how a real warning gets
             read as decoration. */ ?>
    <div class="alert alert-warning py-2 small">
        <i class="fa-solid fa-triangle-exclamation"></i>
        No office telephone number is set, so a visitor's receipt cannot tell them who to ring &mdash;
        <a href="<?= e(base_url('/admin/settings/index.php')) ?>">add one in Settings</a>.
    </div>
<?php endif; ?>

<?php
/* The reference code first, because that is what a visitor reads out over the
   phone or hands across the counter. The status select mirrors whatever the
   cards above set, so a search does not silently discard the status somebody
   had already chosen. */
?>
<form class="filter-bar" method="get">
    <div class="filter-bar__row">
        <div class="search-field">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Reference, name or number">
        </div>

        <select name="status" class="form-select form-select-sm">
            <option value="">All statuses</option>
            <?php foreach (Guides::STATUSES as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="destination" class="form-select form-select-sm">
            <option value="">All destinations</option>
            <?php foreach ($destinations as $d): ?>
                <option value="<?= (int) $d['id'] ?>" <?= $destinationId === (int) $d['id'] ? 'selected' : '' ?>>
                    <?= e((string) $d['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="date" class="form-select form-select-sm">
            <option value="">Any date</option>
            <?php foreach ($dateWindows as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $dateWindow === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>

        <?php /* Carried through the filter so changing a filter does not silently
                 throw away a page size the officer chose. */ ?>
        <input type="hidden" name="per" value="<?= (int) $perPage ?>">

        <div class="filter-bar__spacer"></div>

        <div class="filter-bar__actions">
            <button type="submit" class="btn btn-sm btn-brand">
                <i class="fa-solid fa-filter"></i> Apply filters
            </button>
            <?php if ($search !== '' || $status !== '' || $destinationId > 0 || $dateWindow !== ''): ?>
                <a href="index.php" class="btn btn-sm btn-link">Clear</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php /* TWO TABS, EACH A LIST IN ITS OWN RIGHT.
         The hero "Nothing to answer" block that used to sit here is gone: with a
         tab that already says "Needs action 0", a full-width panel repeating it
         in a headline was the same fact told twice, in more space.

         Links, not JavaScript — each tab is a real URL, so it survives a reload,
         a bookmark and the back button, and it carries every filter with it. */ ?>
<div class="tab-row">
    <?php
    $tabQuery = static function (string $which) use ($perPage): string {
        $params = $_GET;
        unset($params['page']);
        $params['tab'] = $which;
        $params['per'] = $perPage;

        return 'index.php?' . http_build_query($params);
    };
    ?>
    <a class="tab <?= $tab === 'action' ? 'is-active' : '' ?>" href="<?= e($tabQuery('action')) ?>">
        <i class="fa-solid fa-inbox"></i>
        Needs action <?= n(count($liveRequests)) ?>
    </a>
    <a class="tab <?= $tab === 'settled' ? 'is-active' : '' ?>" href="<?= e($tabQuery('settled')) ?>">
        <i class="fa-solid fa-box-archive"></i>
        Settled <?= n(count($settledRequests)) ?>
    </a>
</div>

<?php if ($requests === []): ?>
    <?php /* Said once, quietly, inside the list it belongs to. Three cases that
             genuinely differ: a filter that matched nothing, a clear queue, and
             an office that has never taken a request. */ ?>
    <?php $isFiltered = $status !== '' || $search !== '' || $destinationId > 0 || $dateWindow !== ''; ?>
    <section class="panel">
        <div class="panel__body text-center py-4">
            <?php if ($isFiltered): ?>
                <p class="mb-1"><strong>Nothing matches that filter.</strong></p>
                <p class="text-muted small mb-0"><a href="index.php">Clear it</a> to see every request.</p>
            <?php elseif ($tab === 'settled'): ?>
                <p class="text-muted mb-0">No request has been closed yet.</p>
            <?php elseif ($settledRequests !== []): ?>
                <p class="mb-1"><strong>Nothing to answer.</strong></p>
                <p class="text-muted small mb-0">
                    A new request appears here the moment a visitor sends it.
                </p>
            <?php else: ?>
                <p class="mb-1"><strong>No requests yet.</strong></p>
                <p class="text-muted small mb-0">
                    When a visitor asks for a guide &mdash; from the website or by scanning the
                    QR sign at a destination &mdash; it appears here and your phone gets a text.
                </p>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($tab === 'action'): ?>
<?php foreach ($requests as $r): ?>
    <?php
    $tone = match ($r['status']) {
        'new'          => 'flag',
        'acknowledged' => 'qr',
        'assigned'     => 'ok',
        default        => 'void',
    };

    $telDigits = preg_replace('/[^0-9+]/', '', (string) $r['contact_number']) ?? '';
    $names     = $stops[(int) $r['id']] ?? [];
    $typed     = trim((string) ($r['guide_name'] ?? '')) !== '' && (int) ($r['guide_id'] ?? 0) === 0;

    /* THE TIMELINE IS BUILT FROM TIMESTAMPS THAT EXIST, AND SAYS NOTHING WHEN
       ONE DOES NOT.
     *
     * Three of the five moments are recorded outright: created_at,
     * visitor_notified_at, met_at. The other two — acknowledging and assigning —
     * share one handled_at column, which holds the LAST decision an officer
     * made. So the time is shown only while the request is still sitting at that
     * step; once it moves on, the step is marked done with no time rather than
     * given somebody else's.
     *
     * Inventing a plausible timestamp would be worse than leaving it blank: this
     * is the record an office would reach for if a visitor ever disputed what
     * they were told and when. */
    $assignedYet = trim((string) ($r['guide_name'] ?? '')) !== '';
    $texted      = !empty($r['visitor_notified_at'])
        ? format_date((string) $r['visitor_notified_at'], 'M j, g:i A') : '';

    /* NO SEPARATE "VISITOR NOTIFIED" STEP, and that is a correction.
     *
     * The visitor is texted TWICE — once when the office acknowledges, once when
     * a guide is assigned — but visitor_notified_at is a single column that each
     * send overwrites. So one step called "Visitor notified" could not say which
     * message it meant, and listing it twice would have shown the same timestamp
     * in both places, which is worse than saying nothing.
     *
     * The text is not a stage of its own anyway; it is what each stage DOES. So
     * it hangs off the step that caused it. Four honest steps instead of five
     * ambiguous ones. */
    $steps = [
        [
            'title' => 'Request received',
            'done'  => true,
            'when'  => format_date((string) ($r['issued_at'] ?: $r['created_at']), 'M j, Y 	 g:i A'),
            'note'  => $r['source'] === 'qr' ? 'scanned the QR sign' : 'from the website',
        ],
        [
            'title' => 'Arranging guide',
            'done'  => $r['status'] !== 'new',
            'when'  => $r['status'] === 'acknowledged' && $r['handled_at']
                ? format_date((string) $r['handled_at'], 'M j, Y 	 g:i A') : '',
            'note'  => $r['status'] === 'acknowledged' && $texted !== '' ? 'visitor texted ' . $texted : '',
        ],
        [
            'title' => 'Guide assigned',
            'done'  => $assignedYet,
            'when'  => $r['status'] === 'assigned' && $r['handled_at']
                ? format_date((string) $r['handled_at'], 'M j, Y 	 g:i A') : '',
            'note'  => $assignedYet
                ? (string) $r['guide_name'] . ($texted !== '' && $r['status'] === 'assigned' ? ' · visitor texted' : '')
                : '',
        ],
        [
            'title' => 'Request completed',
            'done'  => $r['status'] === 'completed',
            'when'  => $r['met_at'] ? format_date((string) $r['met_at'], 'M j, Y 	 g:i A') : '',
            'note'  => '',
        ],
    ];

    /* The first step not yet done is where the request is standing. */
    $current = null;

    foreach ($steps as $i => $step) {
        if (!$step['done']) { $current = $i; break; }
    }
    ?>

    <article class="tgq-card" id="req<?= (int) $r['id'] ?>">
        <div class="tgq-grid">

            <!-- ============ what was asked ============ -->
            <div class="tgq-col">
                <div class="tgq-head">
                    <span class="pill pill--<?= $tone ?>"><?= e(Guides::STATUSES[$r['status']]) ?></span>
                    <a class="tgq-head__ref" target="_blank" rel="noopener"
                       title="Open the receipt the visitor was given"
                       href="<?= e(base_url('/booking.php?ref=' . urlencode((string) $r['reference_code']))) ?>">
                        <?= e((string) $r['reference_code']) ?>
                    </a>
                </div>

                <h3 class="tgq-dest">
                    <?= $names !== []
                        ? e(Guides::nameList($names))
                        : (!empty($r['needs_advice'])
                            ? '<span class="text-muted">Asking the Office to advise</span>'
                            : '<span class="text-muted">No destination named</span>') ?>
                </h3>

                <div class="tgq-meta">
                    <span><i class="fa-solid fa-user-group"></i> <?= n((int) $r['party_size']) ?> pax</span>
                    <?php if ($r['preferred_date']): ?>
                        <span><i class="fa-regular fa-calendar"></i> <?= e(format_date((string) $r['preferred_date'], 'M j, Y')) ?></span>
                    <?php else: ?>
                        <span class="text-muted"><i class="fa-regular fa-calendar"></i> no date given</span>
                    <?php endif; ?>
                    <?php if ($r['preferred_time']): ?>
                        <span><i class="fa-regular fa-clock"></i> <?= e(Guides::formatTime((string) $r['preferred_time'])) ?></span>
                    <?php endif; ?>
                </div>

                <div class="tgq-contact">
                    <span><i class="fa-regular fa-user"></i> <?= e((string) $r['visitor_name']) ?></span>
                    <span>
                        <i class="fa-solid fa-phone"></i>
                        <a href="tel:<?= e($telDigits) ?>"><?= e((string) $r['contact_number']) ?></a>
                    </span>
                    <?php if ($r['contact_email']): ?>
                        <span>
                            <i class="fa-regular fa-envelope"></i>
                            <a href="mailto:<?= e((string) $r['contact_email']) ?>"><?= e((string) $r['contact_email']) ?></a>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if ($r['notes']): ?>
                    <p class="tgq-label">Visitor message</p>
                    <div class="tgq-message"><?= nl2br(e((string) $r['notes'])) ?></div>
                <?php endif; ?>

                <?php if ($r['office_note']): ?>
                    <p class="tgq-label">Office note</p>
                    <div class="tgq-message"><?= e((string) $r['office_note']) ?></div>
                <?php endif; ?>

                <p class="tgq-received">
                    <i class="fa-regular fa-calendar-check"></i>
                    Received <?= e(format_date((string) $r['created_at'], 'M j, Y \a\t g:i A')) ?><br>
                    <?= $r['source'] === 'qr' ? 'scanned the QR sign' : 'from the website' ?>
                </p>
            </div>

            <!-- ============ what to do next ============ -->
            <div class="tgq-col">
                <p class="tgq-label" style="margin-top:0">Request action</p>

                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">

                    <?php if ($r['status'] === 'new'): ?>
                        <?php /* NAMED AS A FIRST STEP, because it is. It costs the office
                                 nothing and buys them time, and a visitor who hears
                                 nothing assumes the answer is no. */ ?>
                        <div class="tgq-prompt">
                            <strong>First, acknowledge the visitor.</strong>
                            Let them know the Tourism Office is arranging a guide.
                        </div>
                        <button type="submit" name="status" value="acknowledged"
                                class="btn btn-sm w-100 mb-3"
                                style="background:var(--amber); border-color:var(--amber); color:#fff">
                            <i class="fa-solid fa-bell"></i> Tell visitor we're arranging it
                        </button>
                    <?php endif; ?>

                    <?php if ($assignedYet): ?>
                        <?php /* CONFIRMED, NOT ASKED AGAIN.
                                 After assigning, this column used to redraw the same
                                 "Select an accredited guide" dropdown and the same green
                                 "Assign guide & notify visitor" button — identical to
                                 before, so the screen gave no sign that anything had
                                 happened and an officer could reasonably send the text a
                                 second time.
                                 The arrangement is stated as a fact, and changing it is a
                                 separate, quieter thing. Same pattern the visitor's own
                                 form uses for a destination it already knows. */ ?>
                        <p class="tgq-label" style="margin-top:0">Assigned guide</p>

                        <div class="tgq-assigned">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>
                                <strong><?= e((string) $r['guide_name']) ?></strong>
                                <?php if ($r['guide_contact']): ?>
                                    <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $r['guide_contact']) ?? '') ?>"><?= e((string) $r['guide_contact']) ?></a>
                                <?php endif; ?>
                                <small>
                                    <?= $r['visitor_notified_at']
                                        ? 'Visitor texted ' . e(format_date((string) $r['visitor_notified_at'], 'M j, g:i A'))
                                        : '<span class="text-danger">Visitor NOT texted</span>' ?>
                                </small>
                            </span>
                        </div>

                        <details class="mt-2">
                            <summary class="tgq-more">
                                <i class="fa-solid fa-rotate"></i>
                                Change the guide
                                <i class="fa-solid fa-chevron-right tgq-more__chev"></i>
                            </summary>

                            <?php if ($roster !== []): ?>
                                <select class="form-select form-select-sm mt-2 mb-2" name="guide_id">
                                    <option value="0">Choose a different guide</option>
                                    <?php foreach ($roster as $rg): ?>
                                        <?php $taken = $r['preferred_date']
                                            ? ($busyMap[(int) $rg['id']][(string) $r['preferred_date']] ?? 0) : 0; ?>
                                        <option value="<?= (int) $rg['id'] ?>"
                                                <?= (int) ($r['guide_id'] ?? 0) === (int) $rg['id'] ? 'selected' : '' ?>>
                                            <?= e((string) $rg['full_name']) ?><?= $rg['mobile_number'] ? ' — ' . e((string) $rg['mobile_number']) : '' ?><?= $taken > 0 ? ' · already out that day' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <button type="submit" name="status" value="assigned" class="btn btn-outline-secondary btn-sm w-100"
                                        data-confirm="Reassign this request? The visitor is texted the new guide's name and number."
                                        data-confirm-tone="normal">
                                    <i class="fa-solid fa-user-check"></i> Reassign &amp; text the visitor again
                                </button>
                            <?php endif; ?>
                        </details>
                    <?php else: ?>
                        <p class="tgq-label" style="margin-top:0">Guide assignment</p>

                        <?php if ($roster !== []): ?>
                            <label class="form-label small mb-1" for="gi<?= (int) $r['id'] ?>">
                                Select an accredited guide
                            </label>
                            <select class="form-select form-select-sm mb-2" id="gi<?= (int) $r['id'] ?>" name="guide_id">
                                <option value="0">Choose an accredited guide</option>
                                <?php foreach ($roster as $rg): ?>
                                    <?php
                                    /* Flagged, not hidden. A request carries one optional time
                                       and no duration, so "free at 9am" cannot be answered from
                                       what is stored — the officer decides. */
                                    $taken = $r['preferred_date']
                                        ? ($busyMap[(int) $rg['id']][(string) $r['preferred_date']] ?? 0)
                                        : 0;
                                    ?>
                                    <option value="<?= (int) $rg['id'] ?>">
                                        <?= e((string) $rg['full_name']) ?><?= $rg['mobile_number'] ? ' — ' . e((string) $rg['mobile_number']) : '' ?><?= $taken > 0 ? ' · already out that day' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <button type="submit" name="status" value="assigned" class="btn btn-success btn-sm w-100">
                                <i class="fa-solid fa-user-check"></i> Assign guide &amp; notify visitor
                            </button>

                            <p class="form-text mt-1 mb-0">
                                The visitor is texted the guide's name and number, and told to meet at
                                <?= e($defaultMeetingPoint) ?>.
                            </p>
                        <?php else: ?>
                            <div class="alert alert-warning py-2 small mb-0">
                                <strong>No accredited guide is available.</strong>
                                Every guide is expired, suspended, revoked, or has no ID issued &mdash;
                                <a href="<?= e(base_url('/admin/tour-guides/index.php')) ?>">open the tour guide list</a>.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div class="tgq-or">OR</div>

                    <?php /* The escape hatch, opened by itself when the request already
                             carries a typed name — an arrangement made before the tour guide list
                             existed stays editable. */ ?>
                    <details <?= $typed || $roster === [] ? 'open' : '' ?>>
                        <summary class="tgq-more">
                            <i class="fa-solid fa-plus"></i>
                            Add someone not on the tour guide list
                            <i class="fa-solid fa-chevron-right tgq-more__chev"></i>
                        </summary>

                        <div class="row g-2 mt-1">
                            <div class="col-12">
                                <label class="form-label small mb-1" for="gn<?= (int) $r['id'] ?>">Name</label>
                                <input type="text" class="form-control form-control-sm" maxlength="120"
                                       id="gn<?= (int) $r['id'] ?>" name="guide_name"
                                       value="<?= e((string) ($r['guide_name'] ?? '')) ?>"
                                       placeholder="Who is taking this?">
                            </div>
                            <div class="col-12">
                                <label class="form-label small mb-1" for="gc<?= (int) $r['id'] ?>">Mobile number</label>
                                <input type="text" class="form-control form-control-sm" maxlength="20"
                                       id="gc<?= (int) $r['id'] ?>" name="guide_contact"
                                       value="<?= e((string) ($r['guide_contact'] ?? '')) ?>"
                                       placeholder="09XX XXX XXXX">
                            </div>
                            <div class="col-12">
                                <p class="form-text mt-0 mb-0">
                                    Leaves no record behind &mdash;
                                    <a href="<?= e(base_url('/admin/tour-guides/create.php')) ?>">add them to the tour guide list</a>
                                    instead when there is time.
                                </p>
                            </div>
                        </div>
                    </details>

                    <?php /* THE CLOSING ACTIONS DEPEND ON WHERE THE REQUEST IS.
                             Before a guide is found the question is "can we do this at
                             all"; afterwards it is "did it happen". Asking both at once
                             is how an officer who HAS arranged it gets offered a
                             decline button. */ ?>
                    <?php if ($r['status'] === 'assigned'): ?>
                        <div class="tgq-or">After the visit</div>
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="submit" name="status" value="completed" class="btn btn-outline-secondary btn-sm">
                                <i class="fa-solid fa-flag-checkered"></i> It happened
                            </button>
                            <button type="submit" name="status" value="no_show" class="btn btn-outline-secondary btn-sm">
                                <i class="fa-solid fa-user-slash"></i> They did not come
                            </button>
                            <button type="submit" name="status" value="cancelled" class="btn btn-outline-secondary btn-sm">
                                <i class="fa-solid fa-xmark"></i> Cancelled
                            </button>
                        </div>
                    <?php else: ?>
                        <label class="form-label small mb-1 mt-3" for="on<?= (int) $r['id'] ?>">
                            Note to the visitor <span class="text-muted">&mdash; required to decline</span>
                        </label>
                        <input type="text" class="form-control form-control-sm" maxlength="600"
                               id="on<?= (int) $r['id'] ?>" name="office_note"
                               placeholder="Why the office cannot arrange this">

                        <div class="d-flex align-items-center gap-2 mt-2">
                            <button type="submit" name="status" value="cancelled" class="btn btn-outline-secondary btn-sm">
                                <i class="fa-solid fa-xmark"></i> Visitor cancelled
                            </button>
                            <button type="submit" name="status" value="declined" class="tgq-decline ms-auto"
                                    data-confirm="Decline this request? The visitor is texted the note above."
                                    data-confirm-tone="danger">
                                Decline request
                            </button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>

            <!-- ============ where it stands ============ -->
            <div class="tgq-col">
                <p class="tgq-label" style="margin-top:0">Request timeline</p>

                <ol class="tgq-timeline">
                    <?php foreach ($steps as $i => $step): ?>
                        <?php
                        $state = $step['done'] ? 'done' : ($i === $current ? 'now' : '');
                        ?>
                        <li class="tgq-step <?= $state !== '' ? 'tgq-step--' . $state : '' ?>">
                            <span class="tgq-step__dot">
                                <?php if ($step['done']): ?><i class="fa-solid fa-check"></i><?php endif; ?>
                            </span>
                            <span class="tgq-step__title"><?= e((string) $step['title']) ?></span>
                            <span class="tgq-step__when">
                                <?php if ($step['when'] !== ''): ?>
                                    <?= e((string) $step['when']) ?><br>
                                <?php endif; ?>
                                <?php if ($step['note'] !== ''): ?>
                                    <?= e((string) $step['note']) ?>
                                <?php elseif ($step['when'] === ''): ?>
                                    <?= $step['done'] ? 'Done' : 'Pending' ?>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
    </article>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($tab === 'settled' && $requests !== []): ?>
    <?php /* A TABLE, BECAUSE THIS IS LOOKING UP, NOT DOING.
             Not a fold any more: it was a <details> that opened itself when the
             queue happened to be clear, which is a behaviour nobody can predict.
             It is a tab now — the same level as the work, one click away, and
             paged on its own instead of sharing pages with live requests. */ ?>
    <section class="panel">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Reference</th><th>Destination</th><th>Visitor</th>
                        <th>Visit date</th><th>Guide</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $r): ?>
                    <tr>
                        <td data-label="Reference">
                            <?php /* Still opens the receipt the visitor holds — the one
                                     thing an officer looks up about a closed request. */ ?>
                            <a target="_blank" rel="noopener"
                               href="<?= e(base_url('/booking.php?ref=' . urlencode((string) $r['reference_code']))) ?>">
                                <code><?= e((string) $r['reference_code']) ?></code>
                            </a>
                        </td>
                        <td data-label="Destination"><?= $stopsFor($r) ?></td>
                        <td data-label="Visitor">
                            <?= e((string) $r['visitor_name']) ?>
                            <span class="cell-sub d-block"><?= n((int) $r['party_size']) ?> pax</span>
                        </td>
                        <td data-label="Visit date">
                            <?= $r['preferred_date']
                                ? e(format_date((string) $r['preferred_date'], 'M j, Y'))
                                : '<span class="text-muted">&mdash;</span>' ?>
                        </td>
                        <td data-label="Guide"><?= e((string) ($r['guide_name'] ?: '—')) ?></td>
                        <td data-label="Status">
                            <span class="pill pill--<?= $r['status'] === 'completed' ? 'ok' : 'void' ?>">
                                <?= e(Guides::STATUSES[$r['status']]) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php /* ROWS PER PAGE, beside the pager where somebody already is when they
         decide the page is too short. Submits on change — an extra "apply" for
         a single dropdown is a step nobody would thank us for — and carries
         every other filter with it so choosing 25 does not clear a search.

         Paginator::query() rebuilds those from $_GET, so no list of parameter
         names is maintained here and a filter added later comes along for
         free. */ ?>
<form method="get" class="d-flex align-items-center gap-2 mb-2 justify-content-end">
    <?php foreach ($_GET as $key => $value): ?>
        <?php if (!in_array($key, ['per', 'page'], true) && is_scalar($value)): ?>
            <input type="hidden" name="<?= e((string) $key) ?>" value="<?= e((string) $value) ?>">
        <?php endif; ?>
    <?php endforeach; ?>

    <label class="cell-sub mb-0" for="perPage">Rows per page</label>
    <select id="perPage" name="per" class="form-select form-select-sm" style="width:auto"
            onchange="this.form.submit()">
        <?php foreach ($pageSizes as $size): ?>
            <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option>
        <?php endforeach; ?>
    </select>
</form>

<?php require __DIR__ . '/../../app/views/partials/pager.php'; ?>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
