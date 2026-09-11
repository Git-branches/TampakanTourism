<?php
declare(strict_types=1);

/**
 * TourSync — messages from the public contact form.                  Feature 7
 *
 * Before this existed the form on the homepage threw every message away. What
 * arrives here now is the first enquiry the office has ever actually received
 * through their own website.
 *
 * There is no reply box. The office answers from their own email, because the
 * visitor gave an email address and expects a reply in their inbox, not a
 * notification from a system they have never heard of. This page records that
 * it was answered so the next person opening it knows.
 *
 * AN INBOX, NOT A STACK OF LETTERS.
 *
 * Every message used to be printed in full, one panel each, with its own note
 * field and three buttons — so six enquiries filled several screens and the
 * officer scrolled past whole paragraphs looking for the one they wanted. The
 * list is now one compact line per message and the message itself opens in a
 * dialog. Scanning and reading are different jobs and the screen does one of
 * them at a time.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Paginator;
use App\Core\Session;
use App\Repositories\ContactRepository as Messages;

Auth::require();

if (is_post()) {
    Csrf::verify();

    $id     = (int) ($_POST['id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    $note   = (string) ($_POST['office_note'] ?? '');

    /* THE SAME HANDLER ANSWERS BOTH WAYS.
     *
     * The dialog posts in the background so the officer keeps their place in a
     * long inbox. A browser with no JavaScript posts the form and is redirected
     * exactly as before — same checks, same order, same flash. Nothing below
     * this line knows which one it is serving until it has finished. */
    $wantsJson = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

    $ok  = Messages::setStatus($id, $status, (int) Auth::id(), $note);
    $say = $ok
        ? 'Marked ' . strtolower(Messages::STATUSES[$status]) . '.'
        : 'That is not a status this system uses.';

    if ($ok) {
        ActivityLog::record('contact.' . $status, 'contact_message', $id, 'Marked ' . Messages::STATUSES[$status]);
    }

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $row = $ok ? Messages::find($id) : null;

        echo json_encode([
            'ok'      => $ok,
            'message' => $say,
            'id'      => $id,
            'status'  => $ok ? $status : null,
            'label'   => $ok ? Messages::STATUSES[$status] : null,
            /* The four cards at the top are counts, and an action moves one of
               them. Sent back so they follow the list instead of going stale
               until somebody reloads. */
            'counts'  => Messages::counts(),
            'note'    => $row['office_note'] ?? '',
            'handled' => $row['handled_by_name'] ?? '',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    Session::flash($ok ? 'success' : 'danger', $say);
    redirect(base_url('/admin/messages/index.php#msg' . $id));
}

$status = (string) ($_GET['status'] ?? '');

if ($status !== '' && !isset(Messages::STATUSES[$status])) {
    $status = '';
}

$search   = trim((string) ($_GET['q'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));

/* Presets rather than a date picker. "Since when" is the question, and every
   answer an officer gives to it is one of these four. */
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
    Messages::inbox([
        'status'   => $status,
        'search'   => $search,
        'category' => $category,
        'since'    => $since,
    ], 500),
    $_GET['page'] ?? null
);

$messages   = $pager['rows'];
$counts     = Messages::counts();
$categories = Messages::categories();
$isFiltered = $status !== '' || $search !== '' || $category !== '' || $range !== '';

/** The pill tone each status wears, named once so the list and dialog agree. */
$tone = static fn (string $s): string => match ($s) {
    'new'      => 'flag',
    'answered' => 'ok',
    'spam'     => 'void',
    default    => 'qr',
};

$pageTitle    = 'Messages';
$pageIcon     = 'fa-envelope';
$pageSubtitle = 'Enquiries sent through the public website';

require __DIR__ . '/../_partials/head.php';
?>

<div class="stat-grid">
    <?php
    $cards = [
        ['icon' => 'fa-envelope',       'tone' => 'amber', 'key' => 'new',      'label' => 'New'],
        ['icon' => 'fa-envelope-open',  'tone' => 'blue',  'key' => 'read',     'label' => 'Read'],
        ['icon' => 'fa-reply',          'tone' => 'green', 'key' => 'answered', 'label' => 'Answered'],
        ['icon' => 'fa-ban',            'tone' => 'teal',  'key' => 'spam',     'label' => 'Spam'],
    ];

    foreach ($cards as $card): ?>
        <a class="stat-card stat-card--<?= e($card['tone']) ?>" href="index.php?status=<?= e($card['key']) ?>">
            <div class="stat-card__icon"><i class="fa-solid <?= e($card['icon']) ?>"></i></div>
            <div class="stat-card__body">
                <p class="stat-card__value" data-count="<?= e($card['key']) ?>"><?= n((int) $counts[$card['key']]) ?></p>
                <p class="stat-card__label"><?= e($card['label']) ?></p>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<?php
/* Sender, address and subject. Not the body — it holds whole paragraphs, so a
   LIKE across it matches on any ordinary word and the result is every message. */
?>
<form class="filter-bar" method="get">
    <div class="filter-bar__row">
        <div class="search-field">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Sender, email or subject">
        </div>

        <select name="status" class="form-select form-select-sm" aria-label="Status">
            <option value="">All statuses</option>
            <?php foreach (Messages::STATUSES as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="category" class="form-select form-select-sm" aria-label="Category">
            <option value="">All categories</option>
            <?php foreach ($categories as $topic): ?>
                <option value="<?= e($topic) ?>" <?= $category === $topic ? 'selected' : '' ?>><?= e($topic) ?></option>
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
                <a href="index.php" class="btn btn-sm btn-link">Clear</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-inbox"></i> Messages<?= $status !== '' ? ' — ' . e(Messages::STATUSES[$status]) : '' ?></h2>
        <p class="panel__count"><?= n((int) $pager['total']) ?> found</p>
    </header>

    <?php if ($messages === []): ?>

        <?php
        /* "No messages yet" was shown for any empty result, so a search that
           matched nothing told an officer the inbox was empty. It is not the
           same sentence: one means nobody has written, the other means the
           thing you typed is not here. */
        ?>
        <div class="panel__body">
            <div class="empty">
                <i class="fa-regular fa-envelope"></i>
                <h3><?= $isFiltered ? 'Nothing matches that filter' : 'No messages yet' ?></h3>
                <p>
                    <?php if ($isFiltered): ?>
                        <?= n(array_sum($counts)) ?>
                        message(s) are in the inbox &mdash; none of them match.
                        <a href="index.php">Clear the filter</a> to see them all.
                    <?php else: ?>
                        Enquiries sent through the contact form on the public website arrive here.
                    <?php endif; ?>
                </p>
            </div>
        </div>

    <?php else: ?>

        <?php /* One line per message. The whole row opens it, and the menu on the
                 right is for deciding without reading — an obvious spam does not
                 need to be opened to be marked as spam. */ ?>
        <ul class="inbox">
            <?php foreach ($messages as $i => $m): ?>
                <?php
                $id      = (int) $m['id'];
                $preview = trim(preg_replace('/\s+/', ' ', (string) $m['message']) ?? '');

                /* Cut on the server as well as in CSS. line-clamp hides the rest
                   but still ships two thousand characters into the row, and a
                   copied-out list would carry every one of them. */
                if (mb_strlen($preview) > 150) {
                    $preview = mb_substr($preview, 0, 150) . '…';
                }
                ?>
                <li class="inbox__row<?= $m['status'] === 'new' ? ' is-unread' : '' ?>"
                    id="msg<?= $id ?>" data-msg="<?= $id ?>">

                    <?php /* The dot is the unread mark. aria-label rather than a
                             title, so it is announced instead of hovered. */ ?>
                    <span class="inbox__dot" aria-hidden="true"></span>

                    <button type="button" class="inbox__open" data-msg-open="<?= $id ?>">
                        <span class="inbox__subject"><?= e((string) $m['subject']) ?></span>
                        <span class="inbox__preview"><?= e($preview) ?></span>
                    </button>

                    <div class="inbox__from">
                        <span class="cell-strong"><?= e((string) $m['name']) ?></span>
                        <span class="cell-sub"><?= e((string) $m['email']) ?></span>
                        <?php if ($m['phone']): ?>
                            <span class="cell-sub"><?= e((string) $m['phone']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="inbox__when">
                        <span class="cell-strong"><?= e(format_date((string) $m['created_at'], 'M j, Y')) ?></span>
                        <span class="cell-sub"><?= e(format_date((string) $m['created_at'], 'g:i A')) ?></span>
                    </div>

                    <span class="pill pill--<?= e($tone((string) $m['status'])) ?>" data-msg-pill="<?= $id ?>">
                        <?= e(Messages::STATUSES[$m['status']]) ?>
                    </span>

                    <?php /* <details> rather than a scripted dropdown: Escape,
                             clicking away and keyboard focus are the browser's
                             job, and the menu still opens with the script
                             blocked. Same component as the videos list. */ ?>
                    <details class="kebab kebab--pop">
                        <summary aria-label="Actions for the message from <?= e((string) $m['name']) ?>">
                            <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
                        </summary>

                        <div class="kebab__menu">
                            <button type="button" class="kebab__item" data-msg-open="<?= $id ?>">
                                <i class="fa-solid fa-envelope-open-text" aria-hidden="true"></i> View Message
                            </button>

                            <?php /* Real forms, so the menu works with the script
                                     blocked. The script intercepts them below and
                                     posts in the background instead. */ ?>
                            <?php foreach ([
                                'read'     => ['fa-envelope-open', 'Mark as Read'],
                                'answered' => ['fa-reply',         'Mark as Answered'],
                                'spam'     => ['fa-ban',           'Mark as Spam'],
                            ] as $key => $meta): ?>
                                <?php if ($key === $m['status']) { continue; } ?>
                                <form method="post" data-msg-act="<?= $id ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <button class="kebab__item<?= $key === 'spam' ? ' kebab__item--danger' : '' ?>"
                                            name="status" value="<?= e($key) ?>"
                                            <?= $key === 'spam'
                                                ? 'data-confirm="Mark this message as spam? It stays in the inbox under Spam, but it will no longer be counted as an enquiry waiting for a reply."'
                                                : '' ?>>
                                        <i class="fa-solid <?= e($meta[0]) ?>" aria-hidden="true"></i> <?= e($meta[1]) ?>
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </details>
                </li>
            <?php endforeach; ?>
        </ul>

    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../app/views/partials/pager.php'; ?>

<?php if ($messages !== []): ?>

    <?php
    /* Every message on this page, for the dialog to read. Inline rather than
       fetched: the rows are already here, so a round trip per open would be a
       spinner in front of something the browser is holding. The body is capped
       at 2000 characters by the public form, and a page shows at most a couple
       of dozen. */
    $payload = [];

    foreach ($messages as $m) {
        $payload[(string) $m['id']] = [
            'subject' => (string) $m['subject'],
            'status'  => (string) $m['status'],
            'label'   => Messages::STATUSES[$m['status']],
            'tone'    => $tone((string) $m['status']),
            'name'    => (string) $m['name'],
            'email'   => (string) $m['email'],
            'phone'   => (string) ($m['phone'] ?? ''),
            'when'    => format_date((string) $m['created_at'], 'M j, Y · g:i A'),
            'body'    => (string) $m['message'],
            'note'    => (string) ($m['office_note'] ?? ''),
            'handled' => (string) ($m['handled_by_name'] ?? ''),
        ];
    }
    ?>

    <script type="application/json" id="msgData"><?= json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?></script>

    <dialog class="sheet sheet--msg" id="msgModal" aria-labelledby="msgModalTitle">
        <header class="sheet__head">
            <h2 id="msgModalTitle"><i class="fa-regular fa-envelope" aria-hidden="true"></i> Message Details</h2>
            <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </header>

        <div class="sheet__body">
            <div class="msg-head">
                <p class="msg-subject" id="msgSubject"></p>
                <span class="pill" id="msgPill"></span>
            </div>

            <dl class="msg-meta">
                <div><dt>From</dt><dd id="msgName"></dd></div>
                <div><dt>Email</dt><dd><a id="msgEmail" href="#"></a></dd></div>
                <div><dt>Contact number</dt><dd id="msgPhone"></dd></div>
                <div><dt>Received</dt><dd id="msgWhen"></dd></div>
            </dl>

            <?php /* The message as it was typed: paragraphs kept, and the box
                     scrolls rather than growing the dialog past the screen. */ ?>
            <div class="msg-body" id="msgBody"></div>

            <form method="post" id="msgForm" data-no-busy>
                <?= csrf_field() ?>
                <input type="hidden" name="id" id="msgId" value="">

                <label class="form-label" for="msgNote">Internal note (optional)</label>
                <input type="text" class="form-control form-control-sm" id="msgNote"
                       name="office_note" maxlength="600"
                       placeholder="What you told them, or why this is spam">
                <p class="msg-note-hint">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                    Seen by the Office only. The sender is never shown this.
                </p>

                <p class="msg-note-saved" id="msgSavedNote" hidden></p>
            </form>
        </div>

        <footer class="sheet__foot msg-foot">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close>Close</button>

            <div class="msg-foot__acts">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-msg-set="read">
                    <i class="fa-solid fa-envelope-open" aria-hidden="true"></i> Mark as Read
                </button>
                <button type="button" class="btn btn-sm btn-brand" data-msg-set="answered">
                    <i class="fa-solid fa-reply" aria-hidden="true"></i> Mark as Answered
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger" data-msg-set="spam">
                    <i class="fa-solid fa-ban" aria-hidden="true"></i> Mark as Spam
                </button>
            </div>
        </footer>
    </dialog>

<?php endif; ?>

<?php
$pageScripts = <<<'HTML'
<script>
(function () {
    'use strict';

    var raw = document.getElementById('msgData');
    if (!raw) { return; }

    var DATA   = JSON.parse(raw.textContent || '{}');
    var modal  = document.getElementById('msgModal');
    var form   = document.getElementById('msgForm');
    var open   = null;                 // the id currently in the dialog

    var el = function (id) { return document.getElementById(id); };

    /* The row menu moved into assets/js/admin.js — both this list and the
       alert inbox want it, and one copy is the point. It acts on
       <details class="kebab kebab--pop"> wherever it finds it. */

    /* ---------------------------------------------------------------
       Opening one
       --------------------------------------------------------------- */
    function show(id) {
        var m = DATA[String(id)];
        if (!m || !modal) { return; }

        open = String(id);

        el('msgSubject').textContent = m.subject;
        el('msgName').textContent    = m.name;
        el('msgWhen').textContent    = m.when;
        el('msgBody').textContent    = m.body;
        el('msgId').value            = open;

        /* mailto with the subject prefilled: the officer replies from their own
           client, which is where the visitor is expecting the answer. */
        var mail = el('msgEmail');
        mail.textContent = m.email;
        mail.href = 'mailto:' + m.email + '?subject=' + encodeURIComponent('Re: ' + m.subject);

        var phone = el('msgPhone');
        if (m.phone) {
            phone.innerHTML = '';
            var tel = document.createElement('a');
            tel.href = 'tel:' + m.phone.replace(/[^0-9+]/g, '');
            tel.textContent = m.phone;
            phone.appendChild(tel);
        } else {
            phone.textContent = 'Not given';
        }

        paintPill(m.tone, m.label);
        paintNote(m.note, m.handled);

        el('msgNote').value = '';
        modal.showModal();
    }

    function paintPill(tone, label) {
        var pill = el('msgPill');
        pill.className = 'pill pill--' + tone;
        pill.textContent = label;
    }

    function paintNote(note, handled) {
        var box = el('msgSavedNote');
        if (!note) { box.hidden = true; box.textContent = ''; return; }
        box.hidden = false;
        box.textContent = handled ? note + ' — ' + handled : note;
    }

    /* Anything that names a message opens it: the row itself, and View Message
       in the menu. */
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest && event.target.closest('[data-msg-open]');
        if (!trigger) { return; }
        event.preventDefault();

        /* Close the menu behind it, or it is still hanging open underneath. */
        var menu = trigger.closest('details.kebab');
        if (menu) { menu.open = false; }

        show(trigger.getAttribute('data-msg-open'));
    });

    /* ---------------------------------------------------------------
       Deciding — from the menu or from the dialog

       Both post the form the server already had. The page is not reloaded:
       an officer three screens down an inbox should not be sent back to the
       top for marking one message read.
       --------------------------------------------------------------- */
    var TONES = { new: 'flag', read: 'qr', answered: 'ok', spam: 'void' };

    function ask(status, proceed) {
        if (status !== 'spam') { proceed(); return; }

        var question = 'Mark this message as spam? It stays in the inbox under Spam, '
                     + 'but it will no longer be counted as an enquiry waiting for a reply.';

        if (window.TourSync && typeof window.TourSync.confirmAction === 'function') {
            window.TourSync.confirmAction({
                title: 'Are you sure?',
                text: question,
                confirmText: 'Yes, mark as spam',
                tone: 'danger',
                onConfirm: proceed
            });
            return;
        }

        if (window.confirm(question)) { proceed(); }
    }

    function send(id, status, note, done) {
        var body = new FormData();
        body.append('_token', document.querySelector('input[name="_token"]').value);
        body.append('id', id);
        body.append('status', status);
        body.append('office_note', note || '');

        fetch(window.location.pathname, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(function (d) {
                if (!d || d.ok !== true) {
                    say('danger', (d && d.message) || 'That could not be saved.');
                    return;
                }

                apply(d);
                say('success', d.message);
                if (done) { done(d); }
            })
            .catch(function () {
                say('danger', 'That could not be saved — please check your connection and try again.');
            });
    }

    /* The row, the four counters and the dialog all move together, because
       they are three views of the row that just changed. */
    function apply(d) {
        var row = document.querySelector('[data-msg="' + d.id + '"]');
        if (row) { row.classList.toggle('is-unread', d.status === 'new'); }

        var pill = document.querySelector('[data-msg-pill="' + d.id + '"]');
        if (pill) {
            pill.className = 'pill pill--' + (TONES[d.status] || 'qr');
            pill.textContent = d.label;
        }

        Object.keys(d.counts || {}).forEach(function (key) {
            var cell = document.querySelector('[data-count="' + key + '"]');
            if (cell) { cell.textContent = Number(d.counts[key]).toLocaleString('en-US'); }
        });

        var held = DATA[String(d.id)];
        if (held) {
            held.status = d.status;
            held.label  = d.label;
            held.tone   = TONES[d.status] || 'qr';
            held.note   = d.note || held.note;
            held.handled = d.handled || held.handled;
        }

        if (open === String(d.id)) {
            paintPill(TONES[d.status] || 'qr', d.label);
            paintNote(d.note, d.handled);
        }
    }

    /* showError, not showDanger — 'danger' is the server's word for a flash,
       'error' is what notify.js calls the toast. */
    function say(tone, text) {
        var fn = window.TourSync && window.TourSync[tone === 'danger' ? 'showError' : 'showSuccess'];
        if (typeof fn === 'function') { fn(text); }
    }

    /* From the menu. These are real forms so the menu works without a script;
       intercepted here so it does not reload the page when there is one. */
    document.addEventListener('submit', function (event) {
        var f = event.target;
        if (!f || !f.hasAttribute || !f.hasAttribute('data-msg-act')) { return; }

        event.preventDefault();

        var pressed = event.submitter || f.querySelector('button[name="status"]');
        if (!pressed) { return; }

        var id     = f.getAttribute('data-msg-act');
        var status = pressed.value;
        var menu   = f.closest('details.kebab');

        ask(status, function () {
            if (menu) { menu.open = false; }
            send(id, status, '');
        });
    });

    /* From the dialog. The note travels with whichever decision is pressed —
       one action, the way the office asked for it. */
    if (modal) {
        modal.addEventListener('click', function (event) {
            var btn = event.target.closest && event.target.closest('[data-msg-set]');
            if (!btn || !open) { return; }

            var status = btn.getAttribute('data-msg-set');
            var note   = el('msgNote').value;

            ask(status, function () {
                send(open, status, note, function () {
                    el('msgNote').value = '';
                    modal.close();
                });
            });
        });
    }
})();
</script>
HTML;
?>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
