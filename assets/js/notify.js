/* =============================================================================
   TourSync — the one notification system.
   =============================================================================
   Loaded by the officer shell, the manager shell and the public pages, so a
   confirmation looks the same wherever somebody meets it.

   WHY THIS FILE EXISTS AT ALL
   The system had four different ways of speaking to a person: a server-rendered
   toast dock, a hand-built <dialog>, the browser's own confirm(), and in two
   places a bare alert() that announced itself as "localhost says". The last one
   is the tell — it is the browser talking, not the Municipal Tourism Office, and
   it appears in a typeface and a position nobody chose.

   Everything now goes through the handful of functions at the bottom of this
   file. A module that needs to say something calls TourSync.showSuccess(); a
   module that needs to ask calls TourSync.confirmDelete(). Nothing configures
   SweetAlert2 for itself, because six copies of a config is six chances for the
   Cancel button to end up a different colour.

   DEGRADING
   SweetAlert2 is served from this server, not a CDN — but if it still fails to
   load, every function here falls back to the browser's own dialogs rather than
   doing nothing. A confirmation that silently no-ops would let a delete through
   unasked, which is worse than an ugly box.
   ========================================================================== */

(function (window, document) {
    'use strict';

    var HAS_SWAL = typeof window.Swal !== 'undefined';

    /* The house palette, read from CSS rather than repeated here, so a change to
       admin.css does not leave the dialogs on last month's green. */
    function token(name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name);
        return (v && v.trim()) || fallback;
    }

    var GREEN = token('--green', '#2E7D32');
    var RED   = token('--red',   '#C62828');
    var INK   = token('--ink',   '#1C2529');

    /* ---------------------------------------------------------------------
       Shared shape
       ---------------------------------------------------------------------
       buttonsStyling:false hands the buttons back to admin.css, so the dialog
       uses the same .btn the rest of the system uses. Without it SweetAlert
       paints its own, and the Cancel in a confirmation stops matching the
       Cancel three inches above it on the form. */
    var BASE = {
        buttonsStyling:    false,
        reverseButtons:    true,
        focusConfirm:      false,
        heightAuto:        false,
        customClass: {
            popup:       'ts-swal',
            title:       'ts-swal__title',
            htmlContainer: 'ts-swal__text',
            confirmButton: 'btn btn-sm btn-brand',
            cancelButton:  'btn btn-sm btn-outline-secondary',
            denyButton:    'btn btn-sm btn-outline-danger'
        }
    };

    function merge() {
        var out = {}, i, k;
        for (i = 0; i < arguments.length; i++) {
            var src = arguments[i] || {};
            for (k in src) {
                if (!Object.prototype.hasOwnProperty.call(src, k)) { continue; }
                if (k === 'customClass') {
                    out.customClass = merge(out.customClass || {}, src.customClass || {});
                } else {
                    out[k] = src[k];
                }
            }
        }
        return out;
    }

    /* ---------------------------------------------------------------------
       Toasts — top-right, timed, with the progress bar running down
       ---------------------------------------------------------------------
       The old dock sat top-LEFT on purpose: the right of the topbar holds the
       account block, and on a shared office machine a toast that covers whose
       session this is has hidden the one thing worth checking before you act.

       Moved to the right as the brief asks. The overlap is real but brief, and
       it is bought back by pausing the timer on hover — so a toast that is in
       the way can be pushed out of the way by reading it. */
    function toast(icon, message, ms) {
        if (!message) { return; }

        if (!HAS_SWAL) {
            /* No library: fall back to the server-rendered dock if the page has
               one, and to nothing at all rather than a browser alert — a
               success message is not worth interrupting anybody for. */
            var dock = document.getElementById('toastDock');
            if (dock) {
                var el = document.createElement('div');
                el.className = 'toast toast--' + (icon === 'error' ? 'danger' : icon);
                el.setAttribute('role', icon === 'error' ? 'alert' : 'status');
                el.innerHTML = '<div class="toast__body"></div>';
                el.querySelector('.toast__body').textContent = message;
                dock.appendChild(el);
                window.setTimeout(function () { el.remove(); }, ms || 5000);
            }
            return;
        }

        window.Swal.fire(merge(BASE, {
            toast:             true,
            position:          'top-end',
            icon:              icon,
            title:             message,
            showConfirmButton: false,
            timer:             ms || (icon === 'error' ? 7000 : 4500),
            timerProgressBar:  true,
            customClass:       { popup: 'ts-swal ts-swal--toast' },
            didOpen: function (el) {
                /* Hover to read. A four-second timer is fine until the message
                   is a sentence somebody actually needs. */
                el.addEventListener('mouseenter', window.Swal.stopTimer);
                el.addEventListener('mouseleave', window.Swal.resumeTimer);
            }
        }));
    }

    /* ---------------------------------------------------------------------
       Modal messages — for things a toast would let somebody miss
       ------------------------------------------------------------------ */
    function modal(icon, title, text, extra) {
        if (!HAS_SWAL) {
            window.alert(title + (text ? '\n\n' + text : ''));
            return Promise.resolve({ isConfirmed: true });
        }

        return window.Swal.fire(merge(BASE, {
            icon:              icon,
            title:             title,
            text:              text || '',
            confirmButtonText: 'OK',
            confirmButtonColor: icon === 'error' ? RED : GREEN
        }, extra || {}));
    }

    /* ---------------------------------------------------------------------
       Asking before something irreversible
       ------------------------------------------------------------------ */
    function confirmAction(options) {
        var o = options || {};

        var title   = o.title   || 'Please confirm';
        var text    = o.text    || '';
        var confirm = o.confirmText || 'Continue';
        var cancel  = o.cancelText  || 'Cancel';
        var danger  = o.tone !== 'normal';
        var onOk    = typeof o.onConfirm === 'function' ? o.onConfirm : function () {};

        if (!HAS_SWAL) {
            if (window.confirm(title + (text ? '\n\n' + text : ''))) { onOk(); }
            return;
        }

        window.Swal.fire(merge(BASE, {
            icon:               danger ? 'warning' : 'question',
            title:              title,
            text:               text,
            showCancelButton:   true,
            confirmButtonText:  confirm,
            cancelButtonText:   cancel,
            confirmButtonColor: danger ? RED : GREEN,
            customClass: {
                confirmButton: 'btn btn-sm ' + (danger ? 'btn-danger' : 'btn-brand')
            }
        })).then(function (r) {
            if (r && r.isConfirmed) { onOk(); }
        });
    }

    /* The wording the brief asks for, in one place so every delete in the
       system asks the same question. */
    function confirmDelete(callback, what) {
        confirmAction({
            title:       'Are you sure?',
            text:        what
                ? "You won't be able to revert this. " + what + ' will be deleted permanently.'
                : "You won't be able to revert this action.",
            confirmText: 'Yes, delete it',
            cancelText:  'Cancel',
            tone:        'danger',
            onConfirm:   callback
        });
    }

    /* ---------------------------------------------------------------------
       Asking for a value — replaces prompt()
       ------------------------------------------------------------------ */
    function askFor(options) {
        var o = options || {};
        var onOk = typeof o.onConfirm === 'function' ? o.onConfirm : function () {};

        if (!HAS_SWAL) {
            var typed = window.prompt(o.title || '', o.value || '');
            if (typed !== null) { onOk(typed); }
            return;
        }

        window.Swal.fire(merge(BASE, {
            icon:              o.icon || 'question',
            title:             o.title || '',
            html:              o.text || '',
            input:             o.input || 'text',
            inputValue:        o.value || '',
            inputPlaceholder:  o.placeholder || '',
            inputAttributes:   o.attributes || {},
            showCancelButton:  true,
            confirmButtonText: o.confirmText || 'Save',
            cancelButtonText:  'Cancel',
            confirmButtonColor: GREEN,
            inputValidator:    o.validate || null
        })).then(function (r) {
            if (r && r.isConfirmed) { onOk(r.value); }
        });
    }

    /* =====================================================================
       The public surface. Everything else in the system talks to this.
       ================================================================== */
    var TourSync = window.TourSync || {};

    TourSync.showSuccess = function (m, ms) { toast('success', m, ms); };
    TourSync.showError   = function (m, ms) { toast('error',   m, ms); };
    TourSync.showWarning = function (m, ms) { toast('warning', m, ms); };
    TourSync.showInfo    = function (m, ms) { toast('info',    m, ms); };

    /* Modal variants, for a message that must be acknowledged rather than
       glanced at — a failed upload, a rejected file, a permission refusal. */
    TourSync.alertSuccess = function (t, x) { return modal('success', t, x); };
    TourSync.alertError   = function (t, x) { return modal('error',   t || 'Error!', x || 'Something went wrong. Please try again.'); };
    TourSync.alertWarning = function (t, x) { return modal('warning', t, x); };
    TourSync.alertInfo    = function (t, x) { return modal('info',    t, x); };

    TourSync.confirmAction = confirmAction;
    TourSync.confirmDelete = confirmDelete;
    TourSync.askFor        = askFor;
    TourSync.hasSwal       = HAS_SWAL;

    window.TourSync = TourSync;

    /* =====================================================================
       Server flashes → toasts
       =====================================================================
       The server stays the source of truth: Session::flash() still decides what
       is said and in what tone. This only changes what draws it.

       The dock element is left in the markup and hidden by CSS when this script
       runs, so a browser with JavaScript off still shows the message in the
       original dock rather than losing it. */
    function drainFlashes() {
        var node = document.getElementById('flashData');
        if (!node) { return; }

        var items;
        try {
            items = JSON.parse(node.textContent || '[]');
        } catch (e) {
            return;
        }

        if (!items.length) { return; }

        var dock = document.getElementById('toastDock');
        if (dock) { dock.remove(); }

        /* Staggered, or four flashes from one action stack into a wall and the
           last one covers the first. */
        items.forEach(function (f, i) {
            var tone = f.type === 'danger' || f.type === 'error' ? 'error'
                     : f.type === 'success' ? 'success'
                     : f.type === 'warning' ? 'warning'
                     : 'info';

            window.setTimeout(function () { toast(tone, f.message); }, i * 350);
        });
    }

    /* =====================================================================
       Buttons that are busy
       =====================================================================
       A form submitted twice books two guides, approves one report twice, or
       uploads the same 40MB video again. The button says what it is doing and
       stops accepting clicks until the page navigates away.

       Opt-out with data-no-busy on a form whose submit is cancelled by its own
       script — otherwise the button would sit disabled forever. */
    var BUSY_WORDS = [
        [/delete|remove|void|revoke|discard/i, 'Deleting'],
        [/upload|import|attach/i,              'Uploading'],
        [/search|filter|apply/i,               'Searching'],
        [/send|submit|request|notify|text/i,   'Sending'],
        [/approve|reject|decline|confirm/i,    'Processing'],
        [/save|update|edit|add|create|publish/i, 'Saving']
    ];

    function busyLabel(text) {
        for (var i = 0; i < BUSY_WORDS.length; i++) {
            if (BUSY_WORDS[i][0].test(text)) { return BUSY_WORDS[i][1] + '…'; }
        }
        return 'Processing…';
    }

    function markBusy(button) {
        if (!button || button.dataset.busy === 'yes') { return; }

        var label = (button.textContent || '').trim();

        button.dataset.busy = 'yes';
        button.dataset.busyLabel = label;
        button.classList.add('is-busy');
        button.setAttribute('aria-busy', 'true');

        /* The width is pinned first, or the row of buttons beside it jumps as
           "Save" becomes "Saving…". */
        button.style.minWidth = button.offsetWidth + 'px';
        button.innerHTML = '<span class="btn__spin" aria-hidden="true"></span>'
                         + '<span>' + busyLabel(label) + '</span>';

        /* Not disabled: a disabled submit button is not sent with the form, so
           a two-action form would lose which button was pressed. Clicks are
           swallowed instead. */
        button.setAttribute('data-busy-guard', '1');
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!form || form.hasAttribute('data-no-busy') || form.method === 'get') { return; }
        if (form.getAttribute('data-confirm') && form.dataset.confirmed !== 'yes') { return; }

        markBusy(event.submitter
            || form.querySelector('button[type="submit"], input[type="submit"]'));
    });

    /* A click on a button already working goes nowhere. */
    document.addEventListener('click', function (event) {
        var b = event.target.closest && event.target.closest('[data-busy-guard]');
        if (b) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', drainFlashes);
    } else {
        drainFlashes();
    }
})(window, document);
