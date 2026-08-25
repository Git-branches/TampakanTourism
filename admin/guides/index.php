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
        /* Chosen from the roster when there is one; the two text fields remain
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

$pager = Paginator::slice(
    Guides::inbox([
        'status'         => $status,
        'destination_id' => $destinationId,
        'search'         => $search,
    ], 500),
    $_GET['page'] ?? null
);

$requests     = $pager['rows'];

/* Every request's destinations, in ONE query for the whole page. Asking per
   card would be a hundred round trips on a screen that used to take one — the
   cost nobody notices in development and everybody notices on a municipal
   connection. */
$stops = Guides::destinationsForMany(array_column($requests, 'id'));

/* LIVE WORK IS EXPANDED; SETTLED WORK IS A LINE.
 *
 * Every request used to render as a full panel — visitor, both numbers, the
 * dates, the note, the guide block, the office note — whether or not anybody
 * still had to do anything about it. Seven finished requests made this screen
 * three times taller than the window while containing no work at all, which is
 * the state the office actually found it in.
 *
 * OUTSTANDING_STATUSES is reused rather than a second list written here: it
 * already means "a promise made and not yet discharged", which is exactly the
 * question this split asks. A settled request keeps a row with everything an
 * officer looks up about a closed one, and its reference still opens the
 * visitor's receipt. */
$liveRequests    = [];
$settledRequests = [];

foreach ($requests as $r) {
    if (in_array($r['status'], Guides::OUTSTANDING_STATUSES, true)) {
        $liveRequests[] = $r;
    } else {
        $settledRequests[] = $r;
    }
}

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

/* THE ROSTER, AND WHO IS ALREADY OUT.
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

$browsing = $search === '' && $status === '' && $destinationId === 0;

$upcoming = $browsing ? Guides::upcoming(7) : [];
$overdue  = $browsing ? Guides::overdue()   : [];

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

        <div class="filter-bar__actions">
            <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
            <?php if ($search !== '' || $status !== '' || $destinationId > 0): ?>
                <a href="index.php" class="btn btn-sm btn-link">Clear</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-inbox"></i> Requests<?= $status !== '' ? ' — ' . e(Guides::STATUSES[$status]) : '' ?></h2>
        <p class="panel__count">
            <?php /* What still needs somebody, said first. "7 found" was true and
                     unhelpful when all seven were finished. */ ?>
            <?= count($liveRequests) > 0
                ? n(count($liveRequests)) . ' needing action'
                : 'nothing to answer' ?>
            <?php if ($settledRequests !== []): ?>
                &middot; <?= n(count($settledRequests)) ?> settled
            <?php endif; ?>
        </p>
    </header>

    <?php /* KEYED ON LIVE WORK, NOT ON THE ROW COUNT.
             It used to check $requests, so a page holding seven finished
             requests and no open ones rendered a panel with a heading and
             nothing under it. Three cases, and they are genuinely different:
             a filter that matched nothing, a queue that is clear, and an
             office that has never had a request. */ ?>
    <?php if ($liveRequests === []): ?>
        <div class="panel__body">
            <div class="empty-public">
                <?php $isFiltered = $status !== '' || $search !== '' || $destinationId > 0; ?>
                <?php if ($isFiltered): ?>
                    <i class="fa-solid fa-filter-circle-xmark"></i>
                    <h3>Nothing matches that filter</h3>
                    <p>
                        No request matches what you searched for.
                        <a href="index.php">Clear the filter</a> to see every request.
                    </p>
                <?php elseif ($settledRequests !== []): ?>
                    <i class="fa-solid fa-circle-check"></i>
                    <h3>Nothing to answer</h3>
                    <p>
                        Every request has been settled. The finished ones are listed below,
                        and a new one appears here the moment a visitor sends it.
                    </p>
                <?php else: ?>
                    <i class="fa-solid fa-person-hiking"></i>
                    <h3>No requests yet</h3>
                    <p>
                        When a visitor asks for a guide &mdash; from the website or by scanning the QR sign
                        at a destination &mdash; the request appears here and your phone gets a text.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>

<?php foreach ($liveRequests as $r): ?>
    <?php
    $tone = match ($r['status']) {
        'new'          => 'flag',
        'acknowledged' => 'qr',
        'assigned'     => 'ok',
        'completed'    => 'ok',
        default        => 'void',
    };
    $open      = in_array($r['status'], Guides::OPEN_STATUSES, true);
    $telDigits = preg_replace('/[^0-9+]/', '', (string) $r['contact_number']) ?? '';
    ?>
    <section class="panel" id="req<?= (int) $r['id'] ?>">
        <header class="panel__head">
            <h2>
                <i class="fa-solid fa-<?= $r['source'] === 'qr' ? 'qrcode' : 'globe' ?>"></i>
                <?php
                /* Every destination on the request, not just the primary one. A
                   heading naming one of the three a visitor asked for is a
                   heading the officer will act on and get wrong.

                   'asking us to advise' is a different thing from an empty
                   field, and the officer answers the two differently — the
                   first is a request for a recommendation. */
                $names = $stops[(int) $r['id']] ?? [];

                echo $names !== []
                    ? e(Guides::nameList($names))
                    : (!empty($r['needs_advice'])
                        ? '<span class="text-muted">Asking the Office to advise</span>'
                        : '<span class="text-muted">No destination named</span>');
                ?>
                <a class="text-muted small" target="_blank" rel="noopener"
                   title="Open the receipt the visitor was given"
                   href="<?= e(base_url('/booking.php?ref=' . urlencode((string) $r['reference_code']))) ?>">
                    &middot; <?= e((string) $r['reference_code']) ?>
                </a>
            </h2>
            <span class="pill pill--<?= $tone ?>"><?= e(Guides::STATUSES[$r['status']]) ?></span>
        </header>

        <div class="panel__body">

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <p class="mb-1">
                        <strong><?= e((string) $r['visitor_name']) ?></strong>
                        &middot; <?= n((int) $r['party_size']) ?> pax
                    </p>
                    <?php /* Tappable. The officer is arranging this by phone, and a
                             number they have to retype is a number they mistype. */ ?>
                    <p class="mb-1">
                        <i class="fa-solid fa-phone text-muted"></i>
                        <a href="tel:<?= e($telDigits) ?>"><?= e((string) $r['contact_number']) ?></a>
                        <?php if ($r['contact_email']): ?>
                            &middot; <a href="mailto:<?= e((string) $r['contact_email']) ?>"><?= e((string) $r['contact_email']) ?></a>
                        <?php endif; ?>
                    </p>
                </div>

                <div class="col-md-6">
                    <p class="mb-1">
                        <i class="fa-regular fa-calendar text-muted"></i>
                        <?= $r['preferred_date']
                                ? e(format_date((string) $r['preferred_date'], 'l, F j, Y'))
                                : '<span class="text-muted">No date given</span>' ?>
                        <?php if ($r['preferred_time']): ?>
                            &middot; <?= e(Guides::formatTime((string) $r['preferred_time'])) ?>
                        <?php endif; ?>
                    </p>
                    <p class="text-muted small mb-0">
                        Received <?= e(format_date((string) $r['created_at'], 'M j, Y \a\t g:i A')) ?>
                        &middot; <?= $r['source'] === 'qr' ? 'scanned the QR sign' : 'from the website' ?>
                        <?php if ($r['office_notified_at']): ?>
                            &middot; office texted
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <?php if ($r['notes']): ?>
                <div class="alert alert-light py-2">
                    <strong>From the visitor:</strong> <?= nl2br(e((string) $r['notes'])) ?>
                </div>
            <?php endif; ?>

            <?php if ($r['guide_name']): ?>
                <div class="alert alert-success py-2">
                    <strong>Guide:</strong> <?= e((string) $r['guide_name']) ?>
                    <?php if ($r['guide_contact']): ?>
                        &middot; <?= e((string) $r['guide_contact']) ?>
                    <?php endif; ?>
                    <span class="cell-sub">
                        <?= $r['visitor_notified_at']
                                ? 'visitor texted ' . e(format_date((string) $r['visitor_notified_at'], 'M j, g:i A'))
                                : 'VISITOR NOT YET TEXTED' ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($r['office_note']): ?>
                <div class="alert alert-info py-2">
                    <strong>Office note:</strong> <?= e((string) $r['office_note']) ?>
                    <?php if ($r['handled_by_name']): ?>
                        <span class="cell-sub">&mdash; <?= e((string) $r['handled_by_name']) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($open || $r['status'] === 'assigned'): ?>
                <form method="post" class="mt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">

                    <?php if ($r['status'] === 'new'): ?>
                        <?php /* One tap that costs nothing and buys the office time:
                                 the visitor learns a person has seen it. */ ?>
                        <button type="submit" name="status" value="acknowledged" class="btn btn-brand btn-sm mb-3">
                            <i class="fa-solid fa-eye"></i> Tell them we're arranging it
                        </button>
                    <?php endif; ?>

                    <?php /* PICK FROM THE ROSTER — the normal path now that one
                             exists. Expired, suspended and revoked guides are not
                             on this list at all; a guide already booked that day is
                             shown and marked, not hidden, because the officer knows
                             things the calendar does not. */ ?>
                    <?php if ($roster !== []): ?>
                        <div class="mb-2">
                            <label class="form-label small" for="gi<?= (int) $r['id'] ?>">
                                Assign an accredited guide
                            </label>
                            <select class="form-select form-select-sm" id="gi<?= (int) $r['id'] ?>" name="guide_id">
                                <option value="0">&mdash; choose from the roster &mdash;</option>
                                <?php foreach ($roster as $rg): ?>
                                    <?php
                                    $taken = $r['preferred_date']
                                        ? ($busyMap[(int) $rg['id']][(string) $r['preferred_date']] ?? 0)
                                        : 0;
                                    ?>
                                    <option value="<?= (int) $rg['id'] ?>"
                                            <?= (int) ($r['guide_id'] ?? 0) === (int) $rg['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $rg['full_name']) ?>
                                        <?= $rg['mobile_number'] ? ' — ' . e((string) $rg['mobile_number']) : '' ?>
                                        <?= $taken > 0 ? ' (already has ' . $taken . ' that day)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Choosing here fills in the name and number the visitor is texted.
                                Leave it unset to type someone who is not on the roster yet.
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="text-muted small mb-2">
                            No guide on the roster has a valid ID yet &mdash;
                            <a href="<?= e(base_url('/admin/tour-guides/index.php')) ?>">add one</a>,
                            or type a name below.
                        </p>
                    <?php endif; ?>

                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label small" for="gn<?= (int) $r['id'] ?>">Guide's name</label>
                            <input type="text" class="form-control form-control-sm" maxlength="120"
                                   id="gn<?= (int) $r['id'] ?>" name="guide_name"
                                   value="<?= e((string) ($r['guide_name'] ?? '')) ?>"
                                   placeholder="Who is taking this?">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small" for="gc<?= (int) $r['id'] ?>">Guide's number</label>
                            <input type="text" class="form-control form-control-sm" maxlength="20"
                                   id="gc<?= (int) $r['id'] ?>" name="guide_contact"
                                   value="<?= e((string) ($r['guide_contact'] ?? '')) ?>"
                                   placeholder="09XX XXX XXXX">
                        </div>
                        <?php /* No "where to meet" field. Every visitor is collected at
                                 the Tourism Office, so the place is read from Settings
                                 when the request is assigned rather than typed out
                                 again on each one. It is shown below so the officer can
                                 see what the visitor will be told. */ ?>

                        <div class="col-md-5">
                            <label class="form-label small" for="on<?= (int) $r['id'] ?>">
                                Note to the visitor <span class="text-muted">(required to decline)</span>
                            </label>
                            <input type="text" class="form-control form-control-sm" maxlength="600"
                                   id="on<?= (int) $r['id'] ?>" name="office_note"
                                   placeholder="Meeting point, what to bring, or why not">
                        </div>
                    </div>

                    <p class="text-muted small mt-3 mb-0">
                        <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                        They will be told to meet at <strong><?= e($defaultMeetingPoint) ?></strong>.
                        <?php if ($officePhone === ''): ?>
                            <span class="text-danger">
                                No office telephone number is set, so the receipt cannot tell them
                                who to ring &mdash;
                                <a href="<?= e(base_url('/admin/settings/index.php')) ?>">add one in Settings</a>.
                            </span>
                        <?php endif; ?>
                    </p>

                    <div class="d-flex gap-2 flex-wrap mt-3">
                        <button type="submit" name="status" value="assigned" class="btn btn-success btn-sm">
                            <i class="fa-solid fa-user-check"></i> Assign &amp; text the visitor
                        </button>

                        <?php if ($r['status'] === 'assigned'): ?>
                            <button type="submit" name="status" value="completed" class="btn btn-outline-secondary btn-sm">
                                <i class="fa-solid fa-flag-checkered"></i> Mark completed
                            </button>

                            <?php /* Not the same as completed, and not the same as
                                     cancelled. The visitor did not come and did not
                                     say so — a guide held the afternoon open. Writing
                                     that down as "completed" makes the office's own
                                     numbers a work of fiction. */ ?>
                            <button type="submit" name="status" value="no_show" class="btn btn-outline-secondary btn-sm">
                                <i class="fa-solid fa-user-slash"></i> Did not arrive
                            </button>
                        <?php endif; ?>

                        <button type="submit" name="status" value="declined" class="btn btn-outline-danger btn-sm">
                            <i class="fa-solid fa-ban"></i> Decline
                        </button>

                        <button type="submit" name="status" value="cancelled" class="btn btn-outline-secondary btn-sm">
                            <i class="fa-solid fa-xmark"></i> Visitor cancelled
                        </button>
                    </div>
                </form>
            <?php endif; ?>

        </div>
    </section>
<?php endforeach; ?>

<?php if ($settledRequests !== []): ?>
    <?php /* OPEN ONLY WHEN THERE IS NOTHING ELSE TO LOOK AT.
             With live work on screen the queue leads and this stays shut; with
             none, an entirely collapsed page would read as an empty screen.
             <details> rather than script — it keeps working with JavaScript off
             and the browser handles the keyboard. */ ?>
    <details class="panel" <?= $liveRequests === [] ? 'open' : '' ?>>
        <summary class="panel__head" style="cursor:pointer; list-style:revert">
            <h2 style="display:inline"><i class="fa-solid fa-box-archive"></i> Settled</h2>
            <span class="panel__count"><?= n(count($settledRequests)) ?></span>
        </summary>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Reference</th><th>Destination</th><th>Visitor</th>
                        <th>Visit date</th><th>Guide</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($settledRequests as $r): ?>
                    <tr>
                        <td data-label="Reference">
                            <?php /* Still opens the receipt the visitor holds — the
                                     one thing an officer looks up about a closed
                                     request. */ ?>
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
                                : '<span class="text-muted">—</span>' ?>
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
    </details>
<?php endif; ?>

<?php require __DIR__ . '/../../app/views/partials/pager.php'; ?>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
