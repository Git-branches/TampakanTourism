<?php
declare(strict_types=1);

/**
 * TourSync — data requests sent through the public website.
 *                                     Presentation feedback, 15 September 2026
 *
 * The office hands a paper form to anyone requesting tourism data. The same
 * form is now on the website, and this is where the completed ones arrive.
 *
 * THE ONE THING THAT MAKES THIS SCREEN DIFFERENT FROM THE INBOX BESIDE IT:
 * these rows hold real personal data. A home address, a birthdate, a contact
 * number — none of which a contact message carries. Everything below follows
 * from that:
 *
 *   · Auth::require() at the top, before a single row is read.
 *   · The list shows a name, an organisation and what was asked for. The
 *     address and birthdate are inside the expanded row, not on a line an
 *     officer's screen shows to whoever is standing behind them.
 *   · Anonymise is offered on a request that has been dealt with, and it is
 *     irreversible, and it says so.
 *
 * NO REPLY BOX, same as the inbox. The requester gave an email address and
 * expects an answer in their own inbox, not a notification from a system they
 * have never signed in to. This screen records that the request was handled so
 * the next officer to open it knows where it got to.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Paginator;
use App\Core\Session;
use App\Repositories\ContactRepository as Messages;
use App\Repositories\DataRequestRepository as Requests;

Auth::require();

if (is_post()) {
    Csrf::verify();

    $id     = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['do'] ?? 'status');
    $back   = base_url('/admin/messages/data-requests.php#req' . $id);

    if ($action === 'anonymise') {
        $row = Requests::find($id);

        /* ONLY AFTER IT HAS BEEN DEALT WITH.
           Clearing the requester's details out of a request nobody has answered
           yet destroys the only way to answer it. The button is not rendered on
           an open request either; this is the check that holds when somebody
           posts to the endpoint anyway. */
        if ($row === null) {
            Session::flash('danger', 'That request no longer exists.');
            redirect($back);
        }

        if (!in_array((string) $row['status'], ['fulfilled', 'declined'], true)) {
            Session::flash('danger',
                'Only a fulfilled or declined request can be anonymised — this one is still open.');
            redirect($back);
        }

        if (!empty($row['anonymised_at'])) {
            Session::flash('info', 'That request was already anonymised.');
            redirect($back);
        }

        Requests::anonymise($id);

        /* The reference, never the name. This log is read by everybody with an
           officer account, and a line saying whose personal data was cleared
           would put the name back into a place it was just taken out of. */
        ActivityLog::record('data_request.anonymise', 'data_request', $id,
            'Cleared the personal details on request ' . $row['reference']);

        Session::flash('success',
            'The personal details on ' . e((string) $row['reference']) . ' were cleared.');
        redirect($back);
    }

    $status = (string) ($_POST['status'] ?? '');
    $note   = (string) ($_POST['office_note'] ?? '');

    $ok  = Requests::setStatus($id, $status, (int) Auth::id(), $note);
    $say = $ok
        ? 'Marked ' . strtolower(Requests::STATUSES[$status]) . '.'
        : 'That is not a status this system uses.';

    if ($ok) {
        ActivityLog::record('data_request.' . $status, 'data_request', $id,
            'Marked ' . Requests::STATUSES[$status]);
    }

    Session::flash($ok ? 'success' : 'danger', $say);
    redirect($back);
}

$status = (string) ($_GET['status'] ?? '');

if ($status !== '' && !isset(Requests::STATUSES[$status])) {
    $status = '';
}

$search = trim((string) ($_GET['q'] ?? ''));

/* The same four presets as the inbox. "Since when" is the question, and every
   answer an officer gives to it is one of these. */
$ranges = [
    ''      => 'Any date',
    'today' => 'Today',
    '7d'    => 'Last 7 days',
    '30d'   => 'Last 30 days',
    '1y'    => 'This past year',
];

$range = (string) ($_GET['range'] ?? '');

if (!isset($ranges[$range])) {
    $range = '';
}

$since = match ($range) {
    'today' => date('Y-m-d'),
    '7d'    => date('Y-m-d', strtotime('-6 days')),
    '30d'   => date('Y-m-d', strtotime('-29 days')),
    '1y'    => date('Y-m-d', strtotime('-1 year')),
    default => '',
};

$pager = Paginator::slice(
    Requests::inbox(['status' => $status, 'search' => $search, 'since' => $since], 500),
    $_GET['page'] ?? null
);

$rows       = $pager['rows'];
$counts     = Requests::counts();
$isFiltered = $status !== '' || $search !== '' || $range !== '';

/** The pill tone each status wears, named once so every row agrees. */
$tone = static fn (string $s): string => match ($s) {
    'new'         => 'flag',
    'in_progress' => 'qr',
    'fulfilled'   => 'ok',
    'declined'    => 'void',
    default       => 'qr',
};

/* For the tab strip: what is waiting, on each of the two screens. */
$activeTab = 'data-requests';
$tabCounts = [
    'messages'      => Messages::unreadCount(),
    'data-requests' => Requests::openCount(),
];

$pageTitle    = 'Data Requests';
$pageIcon     = 'fa-file-lines';
$pageSubtitle = 'Requests for tourism data sent through the public website';

require __DIR__ . '/../_partials/head.php';
require __DIR__ . '/_tabs.php';
?>

<div class="stat-grid">
    <?php
    $cards = [
        ['icon' => 'fa-file-circle-plus',  'tone' => 'amber', 'key' => 'new',         'label' => 'New'],
        ['icon' => 'fa-spinner',           'tone' => 'blue',  'key' => 'in_progress', 'label' => 'In progress'],
        ['icon' => 'fa-circle-check',      'tone' => 'green', 'key' => 'fulfilled',   'label' => 'Fulfilled'],
        ['icon' => 'fa-circle-xmark',      'tone' => 'teal',  'key' => 'declined',    'label' => 'Declined'],
    ];

    foreach ($cards as $card): ?>
        <a class="stat-card stat-card--<?= e($card['tone']) ?>"
           href="data-requests.php?status=<?= e($card['key']) ?>">
            <div class="stat-card__icon"><i class="fa-solid <?= e($card['icon']) ?>"></i></div>
            <div class="stat-card__body">
                <p class="stat-card__value"><?= n((int) $counts[$card['key']]) ?></p>
                <p class="stat-card__label"><?= e($card['label']) ?></p>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<?php
/* Name, organisation and reference. NOT the purpose or the requested data:
   those are paragraphs, a LIKE across them matches on any ordinary word, and an
   officer searching here is looking for a person, a body, or a code somebody
   has quoted at them over the phone. */
?>
<form class="filter-bar" method="get">
    <div class="filter-bar__row">
        <div class="search-field">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="q" value="<?= e($search) ?>"
                   placeholder="Name, organisation or reference">
        </div>

        <select name="status" class="form-select form-select-sm" aria-label="Status">
            <option value="">All statuses</option>
            <?php foreach (Requests::STATUSES as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="range" class="form-select form-select-sm" aria-label="Date">
            <?php foreach ($ranges as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $range === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>

        <div class="filter-bar__actions">
            <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
            <?php if ($isFiltered): ?>
                <a href="data-requests.php" class="btn btn-sm btn-link">Clear</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<section class="panel">
    <header class="panel__head">
        <h2>
            <i class="fa-solid fa-file-lines"></i>
            Data Requests<?= $status !== '' ? ' — ' . e(Requests::STATUSES[$status]) : '' ?>
        </h2>
        <p class="panel__count"><?= n((int) $pager['total']) ?> found</p>
    </header>

    <?php if ($rows === []): ?>

        <?php /* Two different sentences. One means nobody has asked for data;
                 the other means the thing you typed is not here. Showing the
                 first for a search that matched nothing tells an officer the
                 screen is empty when it is not. */ ?>
        <div class="panel__body">
            <div class="empty">
                <i class="fa-regular fa-file-lines"></i>
                <h3><?= $isFiltered ? 'Nothing matches that filter' : 'No data requests yet' ?></h3>
                <p>
                    <?php if ($isFiltered): ?>
                        <?= n(array_sum($counts)) ?> request(s) have been received &mdash;
                        none of them match.
                        <a href="data-requests.php">Clear the filter</a> to see them all.
                    <?php else: ?>
                        Requests made through <strong>Contact Us &rsaquo; Data Request</strong> on the
                        public website arrive here.
                    <?php endif; ?>
                </p>
            </div>
        </div>

    <?php else: ?>

        <ul class="record-list">
            <?php foreach ($rows as $r): ?>
                <?php
                $id        = (int) $r['id'];
                $name      = Requests::fullName($r);
                $age       = Requests::age($r['birthdate'] ?? null);
                $anonymous = !empty($r['anonymised_at']);
                $closed    = in_array((string) $r['status'], ['fulfilled', 'declined'], true);

                /* Cut on the server, not only in CSS. line-clamp hides the rest
                   but still ships two thousand characters into the row, and a
                   copied-out list would carry every one of them. */
                $preview = trim(preg_replace('/\s+/', ' ', (string) $r['requested_data']) ?? '');
                if (mb_strlen($preview) > 150) {
                    $preview = mb_substr($preview, 0, 150) . '…';
                }
                ?>
                <li class="record-list__row<?= $r['status'] === 'new' ? ' is-unread' : '' ?>" id="req<?= $id ?>">
                    <details class="req">
                        <summary class="req__summary">
                            <span class="req__ref"><?= e((string) $r['reference']) ?></span>

                            <span class="req__who">
                                <strong><?= e($name) ?></strong>
                                <?php if (trim((string) ($r['organisation'] ?? '')) !== ''): ?>
                                    <em><?= e((string) $r['organisation']) ?></em>
                                <?php endif; ?>
                            </span>

                            <span class="req__preview"><?= e($preview) ?></span>

                            <span class="pill pill--<?= e($tone((string) $r['status'])) ?>">
                                <?= e(Requests::STATUSES[$r['status']]) ?>
                            </span>

                            <span class="req__when"><?= e(format_date((string) $r['created_at'], 'M j, Y')) ?></span>
                        </summary>

                        <div class="req__body">

                            <?php if ($anonymous): ?>
                                <p class="req__flag">
                                    <i class="fa-solid fa-user-slash" aria-hidden="true"></i>
                                    The requester&rsquo;s personal details were cleared on
                                    <?= e(format_date((string) $r['anonymised_at'], 'M j, Y')) ?>.
                                    What they asked for is kept.
                                </p>
                            <?php endif; ?>

                            <?php /* THE THREE SECTIONS OF THE PAPER FORM, in the
                                     order the paper form has them, so an officer
                                     holding one can read across. */ ?>
                            <h4 class="req__heading">Requester</h4>
                            <dl class="req__meta">
                                <div><dt>Name</dt><dd><?= e($name) ?></dd></div>
                                <div>
                                    <dt>Department / Organization</dt>
                                    <dd><?= e(trim((string) ($r['organisation'] ?? '')) !== ''
                                            ? (string) $r['organisation'] : '—') ?></dd>
                                </div>
                                <div>
                                    <dt>Title / Job / Designation</dt>
                                    <dd><?= e(trim((string) ($r['designation'] ?? '')) !== ''
                                            ? (string) $r['designation'] : '—') ?></dd>
                                </div>
                                <div>
                                    <dt>Home address</dt>
                                    <dd><?= e(trim((string) ($r['home_address'] ?? '')) !== ''
                                            ? (string) $r['home_address'] : '—') ?></dd>
                                </div>
                                <div>
                                    <dt>Birthdate</dt>
                                    <dd>
                                        <?php /* AGE IS DERIVED, NOT STORED. A stored one
                                                 is wrong the day after the requester's
                                                 birthday and nothing would correct it. */ ?>
                                        <?= !empty($r['birthdate'])
                                            ? e(format_date((string) $r['birthdate'], 'M j, Y'))
                                              . ($age !== null ? ' &middot; age ' . n($age) : '')
                                            : '—' ?>
                                    </dd>
                                </div>
                                <div>
                                    <dt>Civil status</dt>
                                    <dd><?= e(trim((string) ($r['civil_status'] ?? '')) !== ''
                                            ? (string) $r['civil_status'] : '—') ?></dd>
                                </div>
                            </dl>

                            <h4 class="req__heading">Contact</h4>
                            <dl class="req__meta">
                                <div>
                                    <dt>Email</dt>
                                    <dd>
                                        <?php if ($anonymous): ?>
                                            —
                                        <?php else: ?>
                                            <a href="mailto:<?= e((string) $r['email']) ?>"><?= e((string) $r['email']) ?></a>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                                <div>
                                    <dt>Contact number</dt>
                                    <dd><?= e(trim((string) ($r['contact_number'] ?? '')) !== ''
                                            ? (string) $r['contact_number'] : '—') ?></dd>
                                </div>
                                <div>
                                    <dt>Received</dt>
                                    <dd><?= e(format_date((string) $r['created_at'], 'M j, Y · g:i A')) ?></dd>
                                </div>
                            </dl>

                            <h4 class="req__heading">The request</h4>
                            <dl class="req__meta">
                                <div>
                                    <dt>Preferred completion date</dt>
                                    <dd>
                                        <?php if (empty($r['needed_by'])): ?>
                                            —
                                        <?php else: ?>
                                            <?= e(format_date((string) $r['needed_by'], 'M j, Y')) ?>
                                            <?php /* Said here rather than left for an
                                                     officer to work out from the date.
                                                     A commitment that has already passed
                                                     is the one thing on this row that
                                                     needs acting on today. */ ?>
                                            <?php if (!$closed && strtotime((string) $r['needed_by']) < strtotime('today')): ?>
                                                <span class="pill pill--void">past</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                            </dl>

                            <h5 class="req__label">Purpose of request</h5>
                            <div class="req__text"><?= nl2br(e((string) $r['purpose'])) ?></div>

                            <h5 class="req__label">Requested data / content</h5>
                            <div class="req__text"><?= nl2br(e((string) $r['requested_data'])) ?></div>

                            <?php if (trim((string) ($r['office_note'] ?? '')) !== ''): ?>
                                <h5 class="req__label">Office note</h5>
                                <div class="req__text req__text--note">
                                    <?= nl2br(e((string) $r['office_note'])) ?>
                                    <?php if (trim((string) ($r['handled_by_name'] ?? '')) !== ''): ?>
                                        <span class="cell-sub">
                                            — <?= e((string) $r['handled_by_name']) ?><?php
                                            if (!empty($r['handled_at'])): ?>,
                                                <?= e(format_date((string) $r['handled_at'], 'M j, Y')) ?>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php /* Plain forms, no JavaScript. This screen is read a
                                     handful of times a month; a background post and a
                                     live-updating card would be machinery around four
                                     buttons. */ ?>
                            <form method="post" class="req__act">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="do" value="status">

                                <label class="form-label" for="note<?= $id ?>">Internal note (optional)</label>
                                <input type="text" class="form-control form-control-sm" id="note<?= $id ?>"
                                       name="office_note" maxlength="1000"
                                       value="<?= e((string) ($r['office_note'] ?? '')) ?>"
                                       placeholder="What was sent, or why this was declined">
                                <p class="msg-note-hint">
                                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                                    Seen by the Office only. The requester is never shown this.
                                </p>

                                <div class="req__buttons">
                                    <?php foreach ([
                                        'in_progress' => ['fa-spinner',      'btn-outline-secondary', 'In progress'],
                                        'fulfilled'   => ['fa-circle-check', 'btn-brand',             'Fulfilled'],
                                        'declined'    => ['fa-circle-xmark', 'btn-outline-danger',    'Decline'],
                                    ] as $key => [$icon, $class, $label]): ?>
                                        <?php if ($key === $r['status']) { continue; } ?>
                                        <button type="submit" name="status" value="<?= e($key) ?>"
                                                class="btn btn-sm <?= e($class) ?>">
                                            <i class="fa-solid <?= e($icon) ?>" aria-hidden="true"></i>
                                            <?= e($label) ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </form>

                            <?php /* ANONYMISE — offered only once the request is closed
                                     and not already done.

                                     Its own form, outside the one above: nested forms
                                     are invalid HTML and the browser drops the inner
                                     one, which would make this button run the status
                                     save instead — clearing nothing while appearing
                                     to work.

                                     data-confirm is a PLAIN STRING built before the
                                     tag. A previous round of these was written with
                                     PHP still inside the attribute quotes and showed
                                     officers raw source where the question belonged,
                                     on two irreversible actions. */ ?>
                            <?php if ($closed && !$anonymous): ?>
                                <?php $ask = 'Clear the personal details on ' . $r['reference']
                                    . '? The name, address, birthdate, email and contact number are '
                                    . 'permanently removed. What was requested, and the fact that it was '
                                    . 'handled, are kept. This cannot be undone.'; ?>
                                <form method="post" class="req__anon" data-confirm="<?= e($ask) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <input type="hidden" name="do" value="anonymise">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="fa-solid fa-user-slash" aria-hidden="true"></i>
                                        Clear personal details
                                    </button>
                                    <span class="cell-sub">
                                        Once the request has been answered, the personal details are no
                                        longer needed. Clearing them is how the Office keeps only what
                                        it still has a reason to hold.
                                    </span>
                                </form>
                            <?php endif; ?>
                        </div>
                    </details>
                </li>
            <?php endforeach; ?>
        </ul>

    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../app/views/partials/pager.php'; ?>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
