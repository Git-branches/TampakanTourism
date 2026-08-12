/* =============================================================================
   TourSync — visitor assistant                                     Feature 4
   -----------------------------------------------------------------------------
   Drives the widget in app/views/partials/chat-widget.php. The answering is all
   server-side; this file is transport, rendering, and the manners around them.

   It builds every message with createElement and textContent — never innerHTML.
   Answers are assembled from destination names, advisory titles, and facility
   labels typed by staff into the admin, and the day someone types a script tag
   into an entrance-fee field is the day an innerHTML shortcut becomes a stored
   XSS on the municipality's homepage. Escaping in one place beats remembering.
   ========================================================================== */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var launcher = document.getElementById('chatLauncher');
        var panel    = document.getElementById('chatPanel');
        var log      = document.getElementById('chatLog');
        var form     = document.getElementById('chatForm');
        var input    = document.getElementById('chatInput');
        var send     = document.getElementById('chatSend');
        var closeBtn = document.getElementById('chatClose');
        var clearBtn = document.getElementById('chatClear');
        var suggest  = document.getElementById('chatSuggest');
        var welcome  = document.getElementById('chatWelcome');

        if (!launcher || !panel || !form || !input) {
            return;   // Partial not on this page.
        }

        /* The last question asked, so a failed one can be retried without the
           visitor retyping it. */
        var lastQuestion = '';

        var config = window.TourSyncChat || {};
        var askUrl = config.askUrl;
        var tokenUrl = config.tokenUrl;

        if (!askUrl || !tokenUrl) {
            return;
        }

        /* The markup ships hidden so a failed script never leaves a dead
           button on the page. Reaching this line is the proof it works. */
        launcher.hidden = false;

        /* Tells the stylesheet that the bottom-right corner is now shared, so
           the back-to-top button lifts clear of the launcher. Set here rather
           than in the markup on purpose: if this script fails to load, the
           launcher never appears and the back-to-top must not move to make
           room for a button that is not there. */
        document.body.classList.add('has-chat-dock');

        var token = null;
        var busy  = false;

        /* ---- Opening and closing ---------------------------------------- */

        function open() {
            panel.hidden = false;
            launcher.setAttribute('aria-expanded', 'true');
            document.body.classList.add('has-chat-open');
            window.setTimeout(function () { input.focus(); }, 60);
            primeToken();
        }

        function close() {
            panel.hidden = true;
            launcher.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('has-chat-open');
            launcher.focus();
        }

        launcher.addEventListener('click', function () {
            if (panel.hidden) { open(); } else { close(); }
        });

        if (closeBtn) {
            closeBtn.addEventListener('click', close);
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                showWelcome();
                input.value = '';
                input.focus();
            });
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !panel.hidden) {
                close();
            }
        });

        /* ---- The CSRF token --------------------------------------------- */

        /* Collected when the panel opens rather than on page load, so a visitor
           who never opens the assistant never costs a request. */
        function primeToken() {
            if (token) { return Promise.resolve(token); }

            return fetch(tokenUrl, { credentials: 'same-origin', cache: 'no-store' })
                .then(function (res) { return res.ok ? res.json() : null; })
                .then(function (data) {
                    token = (data && data.token) || null;
                    return token;
                })
                .catch(function () { return null; });
        }

        /* ---- Rendering --------------------------------------------------- */

        function bubble(role) {
            var wrap = document.createElement('div');
            wrap.className = 'chat-msg chat-msg--' + role;

            var body = document.createElement('div');
            body.className = 'chat-bubble';
            wrap.appendChild(body);

            log.appendChild(wrap);
            return { wrap: wrap, body: body };
        }

        function scrollDown() {
            log.scrollTop = log.scrollHeight;
        }

        function addUser(text) {
            var el = bubble('user');
            var p = document.createElement('p');
            p.textContent = text;
            el.body.appendChild(p);
            scrollDown();
        }

        /* One paragraph per blank-line-separated block, so a two-part answer
           ("it is open 8-5" / "but there is a closure notice") reads as two
           thoughts rather than one run-on. */
        function addParagraphs(parent, text) {
            String(text).split(/\n{2,}/).forEach(function (chunk) {
                var trimmed = chunk.trim();
                if (!trimmed) { return; }

                var p = document.createElement('p');
                p.textContent = trimmed;
                parent.appendChild(p);
            });
        }

        function addAnswer(data) {
            var el = bubble('bot');

            addParagraphs(el.body, data.reply || '');

            if (data.facts && data.facts.length) {
                var dl = document.createElement('dl');
                dl.className = 'chat-facts';

                data.facts.forEach(function (fact) {
                    var dt = document.createElement('dt');
                    dt.textContent = fact.label;

                    var dd = document.createElement('dd');
                    dd.textContent = fact.value;

                    dl.appendChild(dt);
                    dl.appendChild(dd);
                });

                el.body.appendChild(dl);
            }

            if (data.links && data.links.length) {
                var nav = document.createElement('div');
                nav.className = 'chat-links';

                data.links.forEach(function (link) {
                    var a = document.createElement('a');
                    a.href = link.href;
                    a.textContent = link.label;

                    /* Anything leaving the site opens elsewhere — a visitor
                       mid-conversation should not lose the conversation to a
                       Google Maps tab. */
                    if (/^https?:\/\//i.test(link.href) && link.href.indexOf(location.origin) !== 0) {
                        a.target = '_blank';
                        a.rel = 'noopener';
                    }

                    nav.appendChild(a);
                });

                el.body.appendChild(nav);
            }

            scrollDown();
            renderSuggestions(data.suggestions);
        }

        /* An error the visitor can act on, not just read. The retry button is
           the difference between "something went wrong" and a way forward —
           and a transient network blip is the most likely cause. */
        function addError(text, retryable) {
            var el = bubble('bot');
            el.wrap.classList.add('chat-msg--error');

            var p = document.createElement('p');
            p.textContent = text;
            el.body.appendChild(p);

            if (retryable && lastQuestion) {
                var retry = document.createElement('button');
                retry.type = 'button';
                retry.className = 'chat-retry';
                retry.textContent = 'Try again';
                retry.addEventListener('click', function () {
                    el.wrap.remove();
                    ask(lastQuestion);
                });
                el.body.appendChild(retry);
            }

            scrollDown();
        }

        /* Restores the opening screen. Used on first paint and by Clear, so
           the two states are literally the same markup rather than two
           near-identical copies that drift apart. */
        function showWelcome() {
            log.textContent = '';

            if (welcome && welcome.content) {
                log.appendChild(welcome.content.cloneNode(true));
            }

            if (suggest) { suggest.textContent = ''; }
            lastQuestion = '';
            scrollDown();
        }

        function renderSuggestions(list) {
            if (!suggest) { return; }

            suggest.textContent = '';

            (list || []).forEach(function (item) {
                /* Short label on the pill, full question in data-ask. Older
                   answers may still send a bare string; treat it as both. */
                var label = (item && item.label) || item;
                var ask   = (item && item.ask)   || item;

                if (!label) { return; }

                var chip = document.createElement('button');
                chip.type = 'button';
                chip.className = 'chat-chip';
                chip.textContent = label;
                chip.setAttribute('data-chat-suggest', '');
                chip.setAttribute('data-ask', ask);
                suggest.appendChild(chip);
            });
        }

        /* ---- Asking ------------------------------------------------------ */

        function thinking() {
            var el = bubble('bot');
            el.wrap.classList.add('chat-msg--thinking');

            var dots = document.createElement('span');
            dots.className = 'chat-dots';
            dots.setAttribute('aria-label', 'Looking that up');
            dots.appendChild(document.createElement('i'));
            dots.appendChild(document.createElement('i'));
            dots.appendChild(document.createElement('i'));

            el.body.appendChild(dots);
            scrollDown();

            return el.wrap;
        }

        function ask(question) {
            if (busy) { return; }

            busy = true;
            lastQuestion = question;
            send.disabled = true;
            addUser(question);

            if (suggest) { suggest.textContent = ''; }

            var pending = thinking();

            primeToken().then(function () {
                var body = new FormData();
                body.append('q', question);
                if (token) { body.append('_token', token); }

                return fetch(askUrl, {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin',
                    cache: 'no-store'
                });
            }).then(function (res) {
                /* A stale token is the one failure worth retrying silently —
                   the visitor did nothing wrong and should not be told to
                   reload a page that is about to work. */
                if (res && res.status === 403) {
                    token = null;
                    return primeToken().then(function () {
                        var retry = new FormData();
                        retry.append('q', question);
                        if (token) { retry.append('_token', token); }

                        return fetch(askUrl, {
                            method: 'POST',
                            body: retry,
                            credentials: 'same-origin',
                            cache: 'no-store'
                        });
                    });
                }

                return res;
            }).then(function (res) {
                return res ? res.json().catch(function () { return null; }) : null;
            }).then(function (data) {
                pending.remove();

                if (!data) {
                    addError('I could not reach the assistant. Please check your connection.', true);
                    renderSuggestions([]);
                    return;
                }

                if (!data.ok) {
                    addError(data.error || 'Something went wrong.', false);
                    renderSuggestions([]);
                    return;
                }

                addAnswer(data);
            }).catch(function () {
                pending.remove();
                addError('I could not reach the assistant. Please check your connection.', true);
            }).then(function () {
                busy = false;
                send.disabled = false;
                input.focus();
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var question = input.value.trim();
            if (question === '') { return; }

            input.value = '';
            ask(question);
        });

        /* Delegated: the chip row is rebuilt after every answer. The question
           sent is data-ask, not the visible label — "Entrance fee" would not
           tell a stateless assistant which destination is meant. */
        document.addEventListener('click', function (event) {
            var chip = event.target.closest('[data-chat-suggest]');
            if (!chip) { return; }

            ask((chip.getAttribute('data-ask') || chip.textContent).trim());
        });

        /* The opening screen. Painted from the same template Clear uses, so
           there is one definition of "what this looks like before you ask
           anything" rather than two that drift. */
        showWelcome();
    });
})();
