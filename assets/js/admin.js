/**
 * TourSync — shell behaviour for the officer and manager dashboards.
 *
 * Both shells load this. They share admin.css and the same markup, so sharing
 * the script too is what stops the two drifting into different behaviour for
 * the same control.
 *
 * Everything here is progressive: with the script blocked, the sidebar is
 * simply always expanded and toasts stay on screen until dismissed by hand.
 * Nothing becomes unreachable.
 */
(function () {
    'use strict';

    /* ---------------------------------------------------------------------
       Sidebar — overlay on phones, rail on desktop
       ------------------------------------------------------------------ */

    var shell   = document.querySelector('.admin-shell');
    var sidebar = document.getElementById('sidebar');
    var scrim   = document.getElementById('sidebarScrim');
    var toggle  = document.getElementById('sidebarToggle');
    var rail    = document.getElementById('railToggle');

    if (sidebar && toggle) {
        var open  = function () { sidebar.classList.add('is-open');    if (scrim) scrim.hidden = false; };
        var close = function () { sidebar.classList.remove('is-open'); if (scrim) scrim.hidden = true;  };

        toggle.addEventListener('click', function () {
            sidebar.classList.contains('is-open') ? close() : open();
        });

        if (scrim) scrim.addEventListener('click', close);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') close();
        });
    }

    if (shell && rail) {
        var KEY  = 'toursync.sidebar.rail';
        var root = document.documentElement;

        /* THE CLASS LIVES ON <html>, NOT ON THE SHELL.
         *
         * head.php sets it inline before the browser paints, which is the only
         * way the sidebar can be drawn at the right width the first time. That
         * script runs before <body> exists, so <html> is the only element it
         * can reach — and both places must agree, or pressing the button would
         * fight what the page loaded with. */
        var paint = function (railed) {
            root.classList.toggle('is-rail', railed);
            rail.setAttribute('aria-pressed', railed ? 'true' : 'false');

            var label = railed ? 'Expand the sidebar' : 'Collapse the sidebar';
            rail.setAttribute('aria-label', label);
            rail.setAttribute('title', label);

            var icon = rail.querySelector('i');
            if (icon) {
                icon.className = railed ? 'fa-solid fa-angles-right' : 'fa-solid fa-angles-left';
            }
        };

        /* Already applied by head.php before the first paint — this only brings
           the button's label and icon into line with it. Reading localStorage
           again would be a second chance to disagree with what is on screen, so
           the class already on the page is the authority. */
        paint(root.classList.contains('is-rail'));

        rail.addEventListener('click', function () {
            var next = !root.classList.contains('is-rail');
            paint(next);

            /* Remembered per browser. Re-collapsing on every page load is the
               reason people stop using a control like this. */
            try { window.localStorage.setItem(KEY, next ? '1' : '0'); } catch (e) { /* private mode */ }

            /* Leaflet and Chart.js size themselves to their container once, so
               a layout that changed width under them stays the old width until
               something tells them to look again. */
            window.dispatchEvent(new Event('resize'));
        });
    }

    /* ---------------------------------------------------------------------
       The sidebar keeps its place
       ---------------------------------------------------------------------
       .sidebar is its own scroll container, so every navigation reset it to the
       top. On the officer's sidebar that is nineteen links: somebody working in
       Settings, at the bottom, scrolled back down after every single save.

       sessionStorage rather than localStorage — the position belongs to this
       browsing session, not to the machine forever.
       ------------------------------------------------------------------ */

    if (sidebar) {
        var SCROLL_KEY = 'toursync.sidebar.scroll';

        /* Restoring it here is what made the sidebar visibly jump: this file
           loads from the foot, so the browser had already painted the sidebar
           at the top. head.php now sets scrollTop inline the moment the element
           has been parsed. All that is left here is remembering it. */
        var remember = function () {
            try { window.sessionStorage.setItem(SCROLL_KEY, String(sidebar.scrollTop)); }
            catch (e) { /* private mode */ }
        };

        /* Written on a timer rather than on every scroll event — a fast wheel
           fires dozens of them and each one is a synchronous storage write. */
        var scrollTimer = null;

        sidebar.addEventListener('scroll', function () {
            if (scrollTimer) { window.clearTimeout(scrollTimer); }
            scrollTimer = window.setTimeout(remember, 120);
        });

        /* And once more on the way out, so a click that navigates immediately
           still records where the person was. */
        window.addEventListener('pagehide', remember);
    }

    /* ---------------------------------------------------------------------
       Toasts
       ------------------------------------------------------------------ */

    var dock = document.getElementById('toastDock');

    if (dock) {
        var dismiss = function (toast) {
            if (!toast || toast.classList.contains('is-leaving')) return;

            toast.classList.add('is-leaving');

            /* Removed after the leave animation, or straight away when the
               person has asked for reduced motion and there is none. */
            var done = function () { if (toast.parentNode) toast.parentNode.removeChild(toast); };
            var motion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            if (motion) { done(); return; }

            toast.addEventListener('animationend', done, { once: true });
            window.setTimeout(done, 400);   // in case the animation never fires
        };

        Array.prototype.forEach.call(dock.querySelectorAll('.toast'), function (toast) {
            var close = toast.querySelector('.toast__close');
            if (close) close.addEventListener('click', function () { dismiss(toast); });

            /* THE FIVE SECONDS ARE COUNTED BY CSS, not by a timer here.
               .toast__timer runs a 5s animation and CSS pauses it on hover or
               focus-within, so a long message cannot escape somebody still
               reading it — and pausing needs no bookkeeping in JavaScript. */
            var timer = toast.querySelector('.toast__timer');

            if (timer) {
                timer.addEventListener('animationend', function () { dismiss(toast); });
            }

            /* Esc closes the one being hovered or focused. */
            toast.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') dismiss(toast);
            });
        });
    }
    /* THE SYSTEM ALREADY HAS ONE WAY TO SAY THINGS. USE IT.
     *
     * Two wrong answers before this one. First I built a toast by hand and
     * it was a near-miss of the real thing: no icon, a stray Bootstrap
     * `show` class, role="status" even on an error. Then I cloned the
     * server's own node into #toastDock — right markup, still wrong, and
     * for a reason the stylesheet spells out beside the rule itself:
     *
     *     notify.js removes this dock and redraws the flashes as
     *     SweetAlert2 toasts, which position themselves. So this is the
     *     no-script path.
     *
     * The dock is the FALLBACK, pinned to the left of the content for a
     * browser with no JavaScript. Everywhere else the office sees a
     * SweetAlert toast at top-end, and it dismisses itself. Appending to
     * the dock put a fallback toast on the left of a page that had already
     * moved past it — which is exactly what was reported: wrong corner, and
     * nothing to make it go away.
     *
     * TourSync.showSuccess and friends are that one way. The server still
     * decides the words and the tone; this only asks the house to draw it.
     * The dock remains the answer when the script is blocked, because then
     * the browser follows the redirect and the server renders it there. */
    /* The message and its tone, read out of a fetched page's dock. Split out so
       adoptToast and stashToast cannot disagree about what counts as a flash. */
    function readToast(doc) {
        var made = doc.querySelector('#toastDock .toast');

        if (!made) { return null; }

        var body    = made.querySelector('.toast__body');
        var message = (body ? body.textContent : made.textContent).trim();

        if (message === '') { return null; }

        var tone = 'Info';

        if (made.classList.contains('toast--success')) { tone = 'Success'; }
        if (made.classList.contains('toast--danger'))  { tone = 'Error'; }
        if (made.classList.contains('toast--warning')) { tone = 'Warning'; }

        return { node: made, message: message, tone: tone };
    }

    var TOAST_STASH = 'toursync.toast.pending';

    /* Hold a flash across a deliberate reload. Used only by a form marked
       data-modal-reload: its POST already consumed the flash server-side, so
       without this the reloaded page would say nothing happened. */
    function stashToast(doc) {
        var got = readToast(doc);

        if (got === null) { return; }

        try {
            sessionStorage.setItem(TOAST_STASH, JSON.stringify({
                message: got.message,
                tone: got.tone
            }));
        } catch (e) { /* private mode — the message is lost, the upload is not */ }
    }

    /* And replay it, once, on the page that comes back. */
    (function () {
        var raw;

        try {
            raw = sessionStorage.getItem(TOAST_STASH);
            if (raw !== null) { sessionStorage.removeItem(TOAST_STASH); }
        } catch (e) { return; }

        if (!raw) { return; }

        var held;

        try { held = JSON.parse(raw); } catch (e) { return; }

        if (!held || !held.message) { return; }

        /* notify.js defines TourSync on its own script tag, which runs before
           this one, but the toast is drawn on DOMContentLoaded either way. */
        document.addEventListener('DOMContentLoaded', function () {
            var show = window.TourSync && window.TourSync['show' + (held.tone || 'Info')];

            if (typeof show === 'function') { show(held.message); }
        });
    })();

    function adoptToast(doc) {
        var got = readToast(doc);

        if (got === null) { return; }

        var made    = got.node;
        var message = got.message;
        var tone    = got.tone;

        var show = window.TourSync && window.TourSync['show' + tone];

        if (typeof show === 'function') {
            show(message);
            return;
        }

        /* notify.js did not load. The dock is still in the markup and still
           styled, so the message is shown rather than lost. */
        var dock = document.getElementById('toastDock');

        if (dock) { dock.appendChild(made.cloneNode(true)); }
    }


    /* ---------------------------------------------------------------------
       Confirmation
       ---------------------------------------------------------------------
       Twenty-five actions across the system asked for confirmation through the
       browser's confirm(). Reliable, but outside the design and unable to give
       the consequence any emphasis.

       Same sentences, same decisions — a dialog that belongs to the page.
       Built on <dialog>, so focus trapping, Escape and the backdrop are the
       browser's job rather than this script's.

       A form or button opts in with data-confirm="the question". Add
       data-confirm-tone="normal" when the action is not destructive, which
       repaints the icon; the default assumes it is.

       PROGRESSIVE: if <dialog> is unsupported the native confirm() runs instead,
       which is exactly what these controls did before.
       ------------------------------------------------------------------ */

    var dialog = null;

    var askFirst = function (message, tone, proceed) {
        /* SweetAlert2 when it is loaded, which is everywhere the shells run.
           The <dialog> below is what answers if notify.js or the library did
           not load — kept rather than deleted because it is the difference
           between a delete being questioned and a delete just happening. */
        if (window.TourSync && window.TourSync.hasSwal) {
            /* THE BUTTON SAYS WHAT IT WILL DO.
             *
             * Every destructive action shared one label, "Yes, continue" — the
             * same word for deleting a video, voiding an arrival and revoking
             * an account. A confirmation is read in a hurry, and the button is
             * the part that gets read; it should name the act, not agree to an
             * unspecified one.
             *
             * Taken from the question rather than from a new attribute on
             * twenty-five call sites, all of which already say plainly what
             * they are about to do. */
            var verb = 'Yes, continue';

            if (tone !== 'normal') {
                if (/\bdelete/i.test(message))      { verb = 'Yes, delete it'; }
                else if (/\bremove/i.test(message)) { verb = 'Yes, remove it'; }
                else if (/\bvoid/i.test(message))   { verb = 'Yes, void it'; }
                else if (/\brevoke/i.test(message)) { verb = 'Yes, revoke it'; }
                else if (/\bwithdraw/i.test(message)) { verb = 'Yes, withdraw it'; }
            } else {
                verb = 'Continue';
            }

            window.TourSync.confirmAction({
                title:       tone === 'normal' ? 'Please confirm' : 'Are you sure?',
                text:        message,
                confirmText: verb,
                tone:        tone === 'normal' ? 'normal' : 'danger',
                onConfirm:   proceed
            });
            return;
        }

        if (!window.HTMLDialogElement) {
            if (window.confirm(message)) { proceed(); }
            return;
        }

        if (!dialog) {
            dialog = document.createElement('dialog');
            dialog.className = 'confirm-dialog';
            dialog.innerHTML =
                '<form method="dialog" class="confirm-dialog__form">'
              +   '<div class="confirm-dialog__body">'
              +     '<span class="confirm-dialog__icon"><i class="fa-solid fa-triangle-exclamation"></i></span>'
              +     '<div class="confirm-dialog__text">'
              +       '<h2>Please confirm</h2><p></p>'
              +     '</div>'
              +   '</div>'
              +   '<div class="confirm-dialog__actions">'
              +     '<button value="cancel" class="btn btn-sm btn-outline-secondary">Cancel</button>'
              +     '<button value="go" class="btn btn-sm btn-outline-danger" data-confirm-go>Continue</button>'
              +   '</div>'
              + '</form>';
            document.body.appendChild(dialog);
        }

        dialog.setAttribute('data-tone', tone === 'normal' ? 'normal' : 'danger');
        dialog.querySelector('.confirm-dialog__text p').textContent = message;

        var go = dialog.querySelector('[data-confirm-go]');
        go.className = 'btn btn-sm ' + (tone === 'normal' ? 'btn-success' : 'btn-outline-danger');

        dialog.querySelector('.confirm-dialog__icon i').className = tone === 'normal'
            ? 'fa-solid fa-circle-question'
            : 'fa-solid fa-triangle-exclamation';

        /* once:true — the dialog element is reused, and a listener left behind
           would fire again for the next thing anybody confirms. */
        dialog.addEventListener('close', function () {
            if (dialog.returnValue === 'go') { proceed(); }
        }, { once: true });

        dialog.showModal();
    };

    document.addEventListener('submit', function (event) {
        var form = event.target;
        var ask  = form.getAttribute && form.getAttribute('data-confirm');

        if (!ask || form.dataset.confirmed === 'yes') { return; }

        event.preventDefault();

        askFirst(ask, form.getAttribute('data-confirm-tone'), function () {
            /* Marked before resubmitting so this handler stands aside the second
               time, rather than asking again in a loop. */
            form.dataset.confirmed = 'yes';

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(event.submitter || undefined);
            } else {
                form.submit();
            }
        });
    }, true);

    /* Buttons and links that carry the attribute themselves — a submit button
       whose form has several actions, or a plain link that deletes. */
    document.addEventListener('click', function (event) {
        var el = event.target.closest && event.target.closest('[data-confirm]');

        if (!el || el.tagName === 'FORM' || el.dataset.confirmed === 'yes') { return; }

        event.preventDefault();

        askFirst(el.getAttribute('data-confirm'), el.getAttribute('data-confirm-tone'), function () {
            el.dataset.confirmed = 'yes';
            el.click();
        });
    }, true);

    /* ---------------------------------------------------------------------
       Sheets — a form in a dialog
       ---------------------------------------------------------------------
       Started on the videos screen and moved here the moment a second page
       wanted one. A button opens a sheet with data-dialog="theId"; anything
       inside closes it with data-dialog-close.

       A sheet carrying data-open opens itself on load, which is how a form
       comes back with its validation errors still on screen: the server
       redirects to the list, the list renders the sheet with the rejected
       input in it, and the sheet reopens over the list.

       Native <dialog>, so focus trapping, Escape and the backdrop are the
       browser's. Where it is unsupported the markup is still a real form on
       the page rather than a template, so nothing becomes unreachable.
       ------------------------------------------------------------------ */

    /* <dialog> has no "opened" event of its own, and something inside one may
       need to know. A Leaflet map built in a closed dialog measures its
       container as zero pixels and paints grey tiles for ever — it has to be
       told to measure again once it can be seen. */
    function openSheet(sheet) {
        if (!sheet || !sheet.showModal) { return false; }

        sheet.showModal();
        sheet.dispatchEvent(new CustomEvent('sheet:open', { bubbles: true }));

        return true;
    }

    document.addEventListener('click', function (event) {
        var opener = event.target.closest && event.target.closest('[data-dialog]');

        if (opener) {
            var sheet = document.getElementById(opener.getAttribute('data-dialog'));

            if (openSheet(sheet)) { event.preventDefault(); }

            return;
        }

        var closer = event.target.closest && event.target.closest('[data-dialog-close]');

        if (closer) {
            var owner = closer.closest('dialog');

            if (owner && owner.close) {
                event.preventDefault();
                owner.close();
            }
        }
    });

    Array.prototype.forEach.call(document.querySelectorAll('dialog[data-open]'), openSheet);

    /* Only one kebab menu open at a time — two overlapping menus is how
       somebody presses Delete on the wrong row. */
    document.addEventListener('toggle', function (event) {
        var opened = event.target;

        if (opened.tagName !== 'DETAILS' || !opened.open || !opened.classList.contains('kebab')) {
            return;
        }

        Array.prototype.forEach.call(document.querySelectorAll('details.kebab[open]'), function (other) {
            if (other !== opened) { other.open = false; }
        });
    }, true);

    /* ---------------------------------------------------------------------
       A file too big for the server, caught before it is sent
       ---------------------------------------------------------------------
       A 56MB video was chosen, uploaded for a minute over an office
       connection, and thrown away by PHP before a line of this application
       ran — post_max_size empties $_POST and $_FILES both, so the page saw a
       POST with no CSRF token and answered "your session expired".

       Nothing was wrong with the session. The file was sixteen megabytes over.

       Checked here first because the browser knows the size instantly and the
       upload never has to start. app/bootstrap.php still catches it server
       side for anything that gets past this.

       Opt in with data-max-mb on the input; the value comes from php.ini
       through upload_limit_mb(), so it cannot drift from what the server
       will actually take.
       ------------------------------------------------------------------ */

    function tooBig(input) {
        var cap = parseFloat(input.getAttribute('data-max-mb'));

        if (!cap || !input.files || !input.files.length) { return null; }

        var total = 0;
        var worst = 0;

        Array.prototype.forEach.call(input.files, function (f) {
            total += f.size;
            if (f.size > worst) { worst = f.size; }
        });

        /* Multiple files travel in one submission, so the total is what the
           server weighs — but a single oversized file is the likelier mistake
           and deserves the clearer sentence. */
        var limit = cap * 1048576;

        if (worst > limit) {
            return 'That file is ' + (worst / 1048576).toFixed(1) + ' MB. '
                 + 'This server accepts ' + cap + ' MB per upload.';
        }

        if (total > limit) {
            return 'Those files come to ' + (total / 1048576).toFixed(1) + ' MB together. '
                 + 'This server accepts ' + cap + ' MB per submission.';
        }

        return null;
    }

    function complain(input, message) {
        input.value = '';

        if (window.TourSync) {
            window.TourSync.alertWarning('File too large', message);
        } else {
            window.alert(message);
        }
    }

    /* On choosing it, so the answer comes back immediately rather than after a
       minute of watching a progress bar. */
    document.addEventListener('change', function (event) {
        var input = event.target;

        if (!input || input.type !== 'file' || !input.hasAttribute('data-max-mb')) { return; }

        var problem = tooBig(input);
        if (problem) { complain(input, problem); }
    });

    /* And again on submit, for a file dropped in by other means. */
    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!form || !form.querySelectorAll) { return; }

        var inputs = form.querySelectorAll('input[type=file][data-max-mb]');

        for (var i = 0; i < inputs.length; i++) {
            var problem = tooBig(inputs[i]);

            if (problem) {
                event.preventDefault();
                event.stopImmediatePropagation();
                complain(inputs[i], problem);
                return;
            }
        }
    }, true);

    /* ---------------------------------------------------------------------
       Modules that do not exist yet explain themselves
       ------------------------------------------------------------------ */

    Array.prototype.forEach.call(document.querySelectorAll('.sidebar__link.is-pending'), function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            var phase = link.querySelector('.sidebar__phase');
            var when  = phase ? 'Phase ' + phase.textContent.replace('P', '') : 'a later phase';

            /* Was a bare alert(), which announced itself as "localhost says". */
            if (window.TourSync) {
                window.TourSync.alertInfo('Not built yet',
                    'This module is scheduled for ' + when + '.');
            } else {
                window.alert('This module is scheduled for ' + when + ' and has not been built yet.');
            }
        });
    });

    /* ---------------------------------------------------------------------
       The bell
       ---------------------------------------------------------------------
       The badge and the first five rows are already on the page, printed by
       head.php. This keeps them current, and it never does arithmetic: every
       reply from the server carries the whole answer — the count AND the list —
       and the browser prints what it was told.

       A badge maintained by adding and subtracting in the browser drifts out of
       step with the database the first time two tabs are open, and what people
       then see is a 3 that will not go away.

       POLLING, because this deploys to shared hosting with no long-lived
       process and no WebSocket. Twenty seconds, paused while the tab is hidden:
       an officer with the dashboard open in a background tab all afternoon
       should not be asking the server anything.
       ------------------------------------------------------------------ */

    /* IN ITS OWN SCOPE, and this is not decoration.
     *
     * Everything above is `var` inside one long IIFE, so `var` is function
     * scoped and every block shares one namespace. The bell arrived with its
     * own open()/close()/timer/ask() and quietly took over the sidebar's,
     * because those names were already taken thirty lines from the top — the
     * hamburger on a phone then called the bell's open() and did nothing
     * visible. Names that only exist inside the bell now stay inside it. */
    (function () {
        var bell = document.getElementById('bell');

        if (!bell || !window.TourSyncBell) { return; }

        var conf   = window.TourSyncBell;
        var button = document.getElementById('bellButton');
        var panel  = document.getElementById('bellPanel');
        var badge  = document.getElementById('bellBadge');
        var list   = document.getElementById('bellList');
        var empty  = document.getElementById('bellEmpty');
        var foot   = document.getElementById('bellFoot');
        var allBtn = document.getElementById('bellMarkAll');

        var esc = function (text) {
            var d = document.createElement('div');
            d.textContent = text == null ? '' : String(text);
            return d.innerHTML;
        };

        /* Everything on screen, from one answer. */
        var render = function (data) {
            if (!data) { return; }

            var unread = parseInt(data.unread, 10) || 0;

            badge.textContent = unread;
            badge.hidden = unread === 0;
            allBtn.disabled = unread === 0;

            var items = data.items || [];

            list.innerHTML = items.map(function (n) {
                return '<li class="bell__item' + (n.unread ? ' is-unread' : '') + '"'
                     + ' data-notification="' + n.id + '">'
                     + '<a class="bell__link" href="' + esc(n.link || '#') + '">'
                     + '<span class="bell__icon bell__icon--' + esc(n.tone) + '">'
                     + '<i class="fa-solid ' + esc(n.icon) + '" aria-hidden="true"></i></span>'
                     + '<span class="bell__text"><strong>' + esc(n.title) + '</strong>'
                     + (n.body ? '<span class="bell__body">' + esc(n.body) + '</span>' : '')
                     + '<span class="bell__when" title="' + esc(n.exact) + '">'
                     + esc(n.label) + ' &middot; ' + esc(n.when) + '</span></span></a>'
                     + '<button type="button" class="bell__toggle" data-notification-toggle'
                     + ' title="' + (n.unread ? 'Mark as read' : 'Mark as unread') + '"'
                     + ' aria-label="' + (n.unread ? 'Mark as read' : 'Mark as unread') + '"></button>'
                     + '</li>';
            }).join('');

            empty.hidden = items.length !== 0;

            if (foot) {
                foot.hidden = (parseInt(data.total, 10) || 0) <= items.length;
            }
        };

        var ask = function (body) {
            var options = { credentials: 'same-origin' };

            if (body) {
                body.append('_token', conf.token);
                options.method = 'POST';
                options.body = body;
            }

            return fetch(conf.url, options)
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(render)
                .catch(function () { /* A dropped poll is not worth a message. */ });
        };

        var act = function (action, id) {
            var body = new FormData();
            body.append('action', action);

            if (id) { body.append('id', id); }

            return ask(body);
        };

        /* ---- opening it ------------------------------------------------
           Opening the panel deliberately marks nothing. Somebody glancing at
           what arrived has not dealt with any of it, and a bell that empties
           itself on a look is a bell that loses things. */
        var open = function (yes) {
            panel.hidden = !yes;
            button.setAttribute('aria-expanded', yes ? 'true' : 'false');
            bell.classList.toggle('is-open', yes);

            if (yes) { ask(null); }
        };

        button.addEventListener('click', function (event) {
            event.stopPropagation();
            open(panel.hidden);
        });

        document.addEventListener('click', function (event) {
            if (!panel.hidden && !bell.contains(event.target)) { open(false); }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !panel.hidden) { open(false); button.focus(); }
        });

        /* ---- one row -----------------------------------------------------
           The small button on the right toggles read state and stays put. The
           row itself is a link: following it marks that one read and takes the
           officer to the thing it is about. */
        list.addEventListener('click', function (event) {
            var row = event.target.closest('[data-notification]');
            if (!row) { return; }

            var id = row.getAttribute('data-notification');

            if (event.target.closest('[data-notification-toggle]')) {
                event.preventDefault();
                act(row.classList.contains('is-unread') ? 'read' : 'unread', id);
                return;
            }

            var link = event.target.closest('.bell__link');

            if (link) {
                /* Marked read before the page changes, and the navigation is
                   left to happen on its own — waiting for the response first
                   would put a visible pause between the click and the page. */
                if (row.classList.contains('is-unread')) { act('read', id); }
            }
        });

        allBtn.addEventListener('click', function () { act('read-all'); });

        /* ---- keeping up --------------------------------------------------- */
        var timer = null;

        var start = function () {
            stop();
            timer = window.setInterval(function () { ask(null); },
                Math.max(5000, parseInt(conf.every, 10) || 20000));
        };

        var stop = function () {
            if (timer) { window.clearInterval(timer); timer = null; }
        };

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                stop();
            } else {
                /* Straight away on coming back, then on the timer. Somebody
                   returning to the tab should not wait twenty seconds to find
                   out what happened while they were away. */
                ask(null);
                start();
            }
        });

        if (!document.hidden) { start(); }
    })();

    /* ---------------------------------------------------------------------
       Collapsing a section
       ---------------------------------------------------------------------
       Any panel whose header came from section_head() carries a [data-collapse]
       button. Settings, User Accounts and My Account all use it, so the
       behaviour lives here rather than being copied into each page.

       Which sections an officer keeps folded is a working habit, not a setting,
       so it stays in localStorage and never reaches the server.
       ------------------------------------------------------------------ */
    (function () {
        var toggles = document.querySelectorAll('[data-collapse]');

        if (!toggles.length) { return; }

        var KEY = 'toursync.folded';

        function folded() {
            try {
                return JSON.parse(window.localStorage.getItem(KEY) || '[]') || [];
            } catch (e) {
                return [];   /* private window, storage off, or corrupt JSON */
            }
        }

        function remember(list) {
            try {
                window.localStorage.setItem(KEY, JSON.stringify(list));
            } catch (e) { /* folding still works for this visit */ }
        }

        /* The slug section_head() stamped on the header. Not a position — panels
           get added and reordered, and an index would quietly start folding a
           different section the next time somebody inserts one above it — and no
           longer the heading TEXT either, because the pre-paint script in
           head.php has to build CSS selectors out of these before the document
           has a body to search, and a heading cannot go in a selector. */
        function keyOf(panel) {
            var head = panel.querySelector('[data-section]');

            return head ? head.getAttribute('data-section') : '';
        }

        function fold(panel, shut) {
            var toggle = panel.querySelector('[data-collapse]');

            panel.classList.toggle('is-collapsed', shut);

            if (toggle) {
                toggle.setAttribute('aria-expanded', shut ? 'false' : 'true');
                toggle.setAttribute('aria-label', (shut ? 'Expand' : 'Collapse') + ' this section');
            }
        }

        /* A SECOND LIST, FOR SECTIONS THAT START FOLDED.
           A panel the server rendered collapsed (data-folded-default) has to be
           able to stay open once somebody opens it — otherwise the next
           navigation folds it again and the control looks broken. Storing only
           the folded keys cannot express "opened this one", because its default
           is the opposite of everything else's.

           Nothing on the officer's side sets a default, so this list stays empty
           there and the behaviour is exactly what it was. */
        var OPEN_KEY = 'toursync.unfolded';

        function unfolded() {
            try {
                return JSON.parse(window.localStorage.getItem(OPEN_KEY) || '[]') || [];
            } catch (e) {
                return [];
            }
        }

        function rememberOpen(list) {
            try {
                window.localStorage.setItem(OPEN_KEY, JSON.stringify(list));
            } catch (e) { /* folding still works for this visit */ }
        }

        var shut = folded();
        var open = unfolded();

        document.querySelectorAll('.panel').forEach(function (panel) {
            var toggle = panel.querySelector('[data-collapse]');

            if (!toggle) { return; }

            var key = keyOf(panel);

            if (shut.indexOf(key) !== -1) {
                fold(panel, true);
                return;
            }

            /* Only a default-folded panel can be re-opened by this list; for
               everything else "not folded" is already the rendered state. */
            if (toggle.hasAttribute('data-folded-default') && open.indexOf(key) !== -1) {
                fold(panel, false);
            }
        });


        document.addEventListener('click', function (event) {
            var toggle = event.target.closest && event.target.closest('[data-collapse]');

            if (!toggle) { return; }

            var panel = toggle.closest('.panel');
            var close = !panel.classList.contains('is-collapsed');
            var key   = keyOf(panel);
            var list  = folded().filter(function (k) { return k !== key; });

            fold(panel, close);

            if (close) { list.push(key); }

            remember(list);

            /* And the mirror of it, so a default-folded section stays however
               the user last left it. Kept in step with the list above: a key is
               never in both. */
            var opened = unfolded().filter(function (k) { return k !== key; });

            if (!close) { opened.push(key); }

            rememberOpen(opened);
        });

        /* A FOLDED SECTION MUST NOT SWALLOW AN ERROR.
           The fields still post while folded — display:none does not stop a
           control being submitted — but a message nobody can see is a refusal
           with no reason given. */
        var bad = document.querySelector('.is-invalid, .field-error');

        if (bad) {
            var owner = bad.closest('.panel');

            if (owner) { fold(owner, false); }
        }
    })();

    /* ---------------------------------------------------------------------
       The overflow menu on a card
       ---------------------------------------------------------------------
       A destination tile has five things you can do to it and room for two.
       The other three live behind a "…" button, where they can be spelled out
       instead of reduced to an unlabelled icon.

       Delegated, so a card added later works without registering anything, and
       written once here rather than per screen.
       ------------------------------------------------------------------ */
    (function () {
        function closeAll(except) {
            document.querySelectorAll('.card-menu__panel').forEach(function (panel) {
                if (panel === except) { return; }

                panel.hidden = true;

                var owner = document.querySelector('[data-card-menu="' + panel.id + '"]');

                if (owner) { owner.setAttribute('aria-expanded', 'false'); }
            });
        }

        document.addEventListener('click', function (event) {
            var toggle = event.target.closest && event.target.closest('[data-card-menu]');

            if (toggle) {
                var panel = document.getElementById(toggle.getAttribute('data-card-menu'));

                if (!panel) { return; }

                var open = panel.hidden;

                /* One at a time: two open menus overlapping each other is a
                   guess about which one a click belongs to. */
                closeAll(open ? panel : null);

                panel.hidden = !open;
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');

                return;
            }

            /* A click anywhere else closes them — including on a link inside a
               menu, which is fine because the page is about to change. */
            closeAll(null);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') { return; }

            var open = document.querySelector('.card-menu__panel:not([hidden])');

            if (!open) { return; }

            var owner = document.querySelector('[data-card-menu="' + open.id + '"]');

            closeAll(null);

            /* Focus goes back to the button that opened it, or Escape leaves
               somebody tabbing from the top of the page again. */
            if (owner) { owner.focus(); }
        });
    })();

    /* ---------------------------------------------------------------------
       Showing a password
       ---------------------------------------------------------------------
       The sign-in page has had a reveal button since it was written. Every
       other password field in the admin had none — four on My Account alone,
       where somebody is being asked to type a password they cannot check
       before renaming their own account.

       Built here rather than in the markup so it covers every field at once,
       including any added later, and so a page with scripting off simply gets
       the plain field it always had rather than a button that does nothing.

       The sign-in page is skipped: it has its own, inside .auth-input, and two
       buttons in one box would be worse than none.
       ------------------------------------------------------------------ */
    (function () {
        var fields = document.querySelectorAll('input[type="password"]');

        Array.prototype.forEach.call(fields, function (input) {
            if (input.closest('.auth-input') || input.closest('.pw-field')) { return; }

            /* Wrapped rather than positioned against its parent: the parent is a
               grid column that may hold a label and a hint too, and the button
               has to sit against the INPUT. */
            var wrap = document.createElement('div');

            wrap.className = 'pw-field';
            input.parentNode.insertBefore(wrap, input);
            wrap.appendChild(input);

            var button = document.createElement('button');

            button.type = 'button';
            button.className = 'pw-field__reveal';
            button.setAttribute('aria-label', 'Show password');
            button.innerHTML = '<i class="fa-regular fa-eye" aria-hidden="true"></i>';

            wrap.appendChild(button);
        });

        document.addEventListener('click', function (event) {
            var button = event.target.closest && event.target.closest('.pw-field__reveal');

            if (!button) { return; }

            var input = button.parentNode.querySelector('input');

            if (!input) { return; }

            var shown = input.type === 'text';

            /* THE CARET GOES BACK WHERE IT WAS — read BEFORE the type changes,
               because changing it discards the selection. Somebody halfway
               through a long password who pressed this to check one character
               should not be returned to the end of it.

               My first version read input.value.length, which is the end, under
               a comment claiming it restored the position. The probe put the
               caret at 6 and got 20 back. */
            var start = input.selectionStart;
            var end   = input.selectionEnd;

            input.type = shown ? 'password' : 'text';
            button.innerHTML = shown
                ? '<i class="fa-regular fa-eye" aria-hidden="true"></i>'
                : '<i class="fa-regular fa-eye-slash" aria-hidden="true"></i>';
            button.setAttribute('aria-label', shown ? 'Show password' : 'Hide password');

            try {
                input.focus();

                if (start !== null) {
                    input.setSelectionRange(start, end);
                }
            } catch (e) {
                input.focus();   /* a browser that refuses setSelectionRange here */
            }
        });
    })();

    /* ---------------------------------------------------------------------
       The pre-paint stand-in comes out
       ---------------------------------------------------------------------
       head.php injects a <style> before <body> exists, so a panel belonging to
       another tab, or a section the officer had folded, is never painted even
       once. By the time this line runs the real state is on the elements —
       `hidden` for the tabs, `.is-collapsed` for the sections — and the
       stand-in has to go, or it would keep overriding every decision made from
       here on: the error-forcing above, and every later click on a chevron.

       Outside the collapse block on purpose. That block returns early on a page
       with no foldable sections, and this must run on every page that got a
       stand-in — otherwise a screen with tabs but no chevrons would keep its
       panels hidden by a stylesheet nobody could see.
       ------------------------------------------------------------------ */
    var preState = document.getElementById('preStateStyle');

    if (preState && preState.parentNode) {
        preState.parentNode.removeChild(preState);
    }

    /* ---------------------------------------------------------------------
       A suggested value typed into a field for you
       ---------------------------------------------------------------------
       Used by the printed-signage address, where the thing to type is this
       machine's WiFi address — something an officer would otherwise have to
       find with ipconfig, and mistype.

       It fills the box and stops. Saving stays a deliberate press of Save, the
       same as every other setting on the screen.
       ------------------------------------------------------------------ */
    document.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('[data-fill]');

        if (!button) { return; }

        var field = document.getElementById(button.getAttribute('data-fill'));

        if (!field) { return; }

        field.value = button.getAttribute('data-fill-value') || '';
        field.focus();

        /* So anything watching the field — validation, a dirty-form guard —
           sees this the same way it sees typing. */
        field.dispatchEvent(new Event('input',  { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
    });

    /* ---------------------------------------------------------------------
       A whole page opened inside a dialog
       ---------------------------------------------------------------------
       The destinations list keeps everything else in a sheet, so leaving the
       list to edit a destination, add a photograph, draw a route or write a
       heritage item was the one place the screen still went somewhere else and
       came back.

       A link marked data-modal-page is fetched with ?modal=1 — which is all it
       takes for those pages to render their body and skip the shell — and put
       into #pageModal. The link keeps its href, so this only intercepts a
       plain left click: Ctrl-click, middle-click and "open in new tab" go to
       the full page exactly as before, and so does this whole feature with
       JavaScript off.
       ------------------------------------------------------------------ */
    (function () {
        var modal = document.getElementById('pageModal');
        var body  = document.getElementById('pageModalBody');
        var title = document.getElementById('pageModalTitle');

        if (!modal || !body || !title || !modal.showModal || !window.fetch) { return; }

        /* Bumped on every open. A slow first fetch that lands after the officer
           has already opened something else must not overwrite it. */
        var turn = 0;

        function fragmentUrl(href) {
            return href + (href.indexOf('?') === -1 ? '?' : '&') + 'modal=1';
        }

        /* innerHTML never runs a <script>, and two of these pages need theirs:
           the coordinate picker on Edit and the drag-reorder on Heritage. Each
           tag is replaced with a fresh one, in order, and a src has to finish
           before the next runs — the inline picker is useless if Leaflet has
           not defined L yet. */
        function runScripts(scripts, done) {
            if (scripts.length === 0) { done(); return; }

            var old  = scripts.shift();
            var copy = document.createElement('script');

            Array.prototype.forEach.call(old.attributes, function (attr) {
                copy.setAttribute(attr.name, attr.value);
            });

            if (old.src) {
                /* Either outcome continues: a CDN that cannot be reached should
                   leave the form usable without its map, not half-loaded. */
                copy.onload = copy.onerror = function () { runScripts(scripts, done); };
                old.parentNode.replaceChild(copy, old);
                return;
            }

            copy.textContent = old.textContent;
            old.parentNode.replaceChild(copy, old);
            runScripts(scripts, done);
        }

        /* Every form on those four pages omits action, which means "post to the
           page I am on". Injected here, that page is the list — the post would
           go to index.php and nothing would be saved. Naming the source URL puts
           it back where it has always gone, so the handler runs, redirects and
           flashes exactly as it does today. */
        function pointFormsAt(url) {
            Array.prototype.forEach.call(body.querySelectorAll('form'), function (form) {
                if (!form.getAttribute('action')) { form.setAttribute('action', url); }
            });
        }

        /* The page this dialog is showing, so a form inside it can be sent
           without navigating and the dialog refilled from the same address. */
        var showing = '';
        var showingLabel = '';

        function load(href, label) {
            var mine = ++turn;

            showing      = href;
            showingLabel = label || showingLabel;

            title.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Loading…';
            body.innerHTML  = '';

            if (!modal.open) { modal.showModal(); }

            fetch(fragmentUrl(href), {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' }
            }).then(function (response) {
                if (!response.ok) { throw new Error('HTTP ' + response.status); }

                return response.text();
            }).then(function (html) {
                if (mine !== turn) { return; }

                body.innerHTML = html;
                title.textContent = label || 'Destination';
                pointFormsAt(href);

                runScripts(Array.prototype.slice.call(body.querySelectorAll('script')), function () {
                    if (mine !== turn) { return; }

                    /* What tells a Leaflet map built in a closed dialog to
                       measure itself again. Same event the sheets already
                       fire, so _form.php needed no change to listen for it. */
                    modal.dispatchEvent(new CustomEvent('sheet:open', { bubbles: true }));
                });
            }).catch(function () {
                if (mine !== turn) { return; }

                title.textContent = 'Could not open that';
                body.innerHTML = '<p class="text-muted">This did not load. '
                    + '<a href="' + href.replace(/"/g, '&quot;') + '">Open the full page instead</a>.</p>';
            });
        }

        document.addEventListener('click', function (event) {
            if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            var link = event.target.closest && event.target.closest('a[data-modal-page]');

            if (!link || link.target === '_blank') { return; }

            event.preventDefault();

            /* These links live in the card's overflow menu, which would
               otherwise stay open behind the dialog. */
            var panel = link.closest('.card-menu__panel');

            if (panel) {
                panel.hidden = true;

                var owner = document.querySelector('[data-card-menu="' + panel.id + '"]');

                if (owner) { owner.setAttribute('aria-expanded', 'false'); }
            }

            load(link.getAttribute('href'), link.getAttribute('data-modal-title') || link.textContent.trim());
        });

        /* -----------------------------------------------------------------
           A FORM INSIDE THE DIALOG STAYS INSIDE THE DIALOG.
           -----------------------------------------------------------------
           Every page fetched in here is a real page, and its forms post and
           redirect the way they always have. Correct for the address bar, wrong
           for the dialog: adding a heritage item threw the officer out of the
           modal and onto heritage.php — the exact navigation the modal exists
           to remove — and the same for photos and for routes.

           Sent by fetch instead, then the dialog is refilled by load(), which
           already points the forms back at their own page and re-runs any
           script they carry. Reusing it is why this is twenty lines rather than
           a second copy of the loader.

           NOT MARKED PER FORM. There are ten across three pages and more to
           come; being inside #pageModal is the rule, rather than a list
           somebody has to remember to add to.

           WITH THE SCRIPT BLOCKED none of this runs, the form posts normally
           and the officer lands on the full page. Slower, and correct.

           It runs AFTER the confirmation, which re-submits with
           confirmed="yes" — so a delete in here is still asked about first.
           -------------------------------------------------------------- */
        document.addEventListener('submit', function (event) {
            var form = event.target;

            if (!form || !form.closest || !form.closest('#pageModal')) { return; }
            if (form.getAttribute('data-confirm') && form.dataset.confirmed !== 'yes') { return; }

            var action = form.getAttribute('action') || showing;

            if (!action) { return; }

            event.preventDefault();

            var data    = new FormData(form);
            var pressed = event.submitter;

            /* A <button name= value=> is only sent when it is the one pressed,
               and FormData cannot know which that was. */
            if (pressed && pressed.name) { data.append(pressed.name, pressed.value); }

            Array.prototype.forEach.call(form.querySelectorAll('button'), function (b) {
                b.disabled = true;
            });

            fetch(action, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' }
            }).then(function (response) {
                if (!response.ok) { throw new Error('HTTP ' + response.status); }

                return response.text();
            }).then(function (html) {
                /* The response is the page the server redirected to, so it
                   carries the flash the application decided on. */
                var said = new DOMParser().parseFromString(html, 'text/html');

                /* OPT-IN: close and reload instead of refilling the dialog.
                   For a form whose result changes the page BEHIND the dialog —
                   the manager's evidence upload turns a card from "0 photos ·
                   Pending" into "1 photo · Awaiting review" — refilling the
                   dialog would leave that card lying until the next navigation.
                   Nothing carries this attribute unless it says so, so every
                   existing dialog keeps refilling exactly as before. */
                if (form.hasAttribute('data-modal-reload')) {
                    /* THE FLASH IS ALREADY SPENT. The fetch above followed the
                       redirect, so the server handed this response its "Photo
                       added" message and cleared it — reloading now would show
                       nothing at all. So the message is carried across the
                       reload by hand and replayed on the other side. */
                    stashToast(said);
                    modal.close();
                    window.location.reload();
                    return;
                }

                load(showing, showingLabel);
                adoptToast(said);
            }).catch(function () {
                /* Whatever went wrong, the officer must not be left looking at a
                   dialog full of disabled buttons. */
                window.location.href = action;
            });
        });

        /* Emptied on close so the ids borrowed from _form.php stop colliding
           with the Add sheet's copy, and so a map is not left running behind a
           dialog nobody can see.

           THE GUARD IS NOT DEFENSIVE, IT IS THE FIX. A dialog's `close` event
           is queued, not dispatched synchronously — so closing one row's dialog
           and opening the next one straight away runs this handler AFTER the
           new load has already started. It then cleared the body the officer
           was looking at, and bumping the turn counter made the new fetch
           discard its own answer when it landed: an empty dialog that never
           filled, roughly one reopen in two.

           If it is open again, this close belongs to the previous one and has
           nothing left to tidy. The turn is no longer touched here at all —
           superseding a load is load()'s own business. */
        modal.addEventListener('close', function () {
            if (modal.open) { return; }

            body.innerHTML = '';
        });
    })();


    /* ---------------------------------------------------------------------
       A menu action that does not leave the page
       ---------------------------------------------------------------------
       Publish, Unpublish, Duplicate and Delete are POSTs, and a POST that
       redirects is a navigation: the officer is taken somewhere, told what
       happened, and has to come back — losing their filter and their page on
       the way.

       A form marked data-ajax is sent by fetch instead. The server does exactly
       what it always did, including the redirect; the difference is that the
       browser never goes there. What comes back is the list as it now stands,
       flash message and all, and the rows and the toast are lifted out of it.

       NOTHING ABOUT THE SERVER CHANGED. With this script blocked the same form
       posts normally, the redirect happens for real, and the officer lands back
       on the same list — slower, and correct.

       IT RUNS AFTER THE CONFIRMATION, not instead of it. The confirm handler
       above intercepts the first submit and re-submits with confirmed="yes";
       this listener stands aside until it sees that mark, so a Delete is still
       asked about before anything is sent.
       ------------------------------------------------------------------ */
    (function () {
        if (!window.fetch || !window.DOMParser) { return; }

        /* Swapped after a successful action. The list of rows, the pager, and
           anything the page shows when the list is empty. */
        var REGIONS = ['.announce-list', '.pager', '.panel:has(.empty)'];


        function refresh(url, done) {
            fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');

                    REGIONS.forEach(function (selector) {
                        var next, here;

                        try {
                            next = doc.querySelector(selector);
                            here = document.querySelector(selector);
                        } catch (e) {
                            return;   /* :has() is unsupported on older engines */
                        }

                        if (next && here) {
                            here.replaceWith(next.cloneNode(true));
                        } else if (here && !next) {
                            here.remove();
                        }
                    });

                    done(doc);
                })
                .catch(function () { done(null); });
        }

        document.addEventListener('submit', function (event) {
            var form = event.target;

            if (!form || !form.hasAttribute || !form.hasAttribute('data-ajax')) { return; }

            /* Let the confirmation run first. It re-submits once answered, and
               that second pass is the one this handles. */
            if (form.getAttribute('data-confirm') && form.dataset.confirmed !== 'yes') { return; }

            event.preventDefault();

            var data   = new FormData(form);
            var pressed = event.submitter;

            /* A <button name=… value=…> is only included when it is the one
               pressed, and FormData does not know which that was. Publish and
               Unpublish are the same form with different values. */
            if (pressed && pressed.name) { data.append(pressed.name, pressed.value); }

            var back = data.get('return') || window.location.pathname + window.location.search;

            form.querySelectorAll('button').forEach(function (b) { b.disabled = true; });

            fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' },
            }).then(function (response) {
                if (!response.ok) { throw new Error('HTTP ' + response.status); }

                /* The response IS the redirected page, so the flash has already
                   been consumed by it — re-fetching the list here would show
                   nothing. Read the message out of what came back, then refresh
                   the rows separately. */
                return response.text();
            }).then(function (html) {
                /* The response IS the redirected page, so its toast dock holds
                   the flash the application decided on. Kept aside while the
                   rows are refreshed, then moved into the live dock. */
                var said = new DOMParser().parseFromString(html, 'text/html');

                refresh(back, function () {
                    adoptToast(said);
                });
            }).catch(function () {
                /* Whatever went wrong, the officer must not be left looking at a
                   dead menu wondering whether it worked. */
                window.location.href = back;
            });

            /* The menu stays open behind a dialog otherwise. */
            var panel = form.closest('.card-menu__panel');

            if (panel) {
                panel.hidden = true;

                var owner = document.querySelector('[data-card-menu="' + panel.id + '"]');

                if (owner) { owner.setAttribute('aria-expanded', 'false'); }
            }
        });
    })();

})();
