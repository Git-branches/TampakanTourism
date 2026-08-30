/* =============================================================================
   TAMPAKAN TOURISM PORTAL — Main Script
   Municipality of Tampakan, South Cotabato, Philippines
   -----------------------------------------------------------------------------
   Modules
     01. Preloader
     02. AOS (Animate On Scroll)
     03. Sticky navbar + scroll progress + back-to-top
     04. Scroll spy (active nav highlighting)
     05. Smooth anchor scrolling
     06. Animated statistics counters
     07. Leaflet tourist map
     08. Photo gallery lightbox
     09. Contact form validation
     10. Image loading & fallbacks
   ========================================================================== */

(function () {
    'use strict';

    /* Honour the OS "reduce motion" preference throughout. */
    const prefersReducedMotion =
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* Small helpers */
    const $  = (selector, scope = document) => scope.querySelector(selector);
    const $$ = (selector, scope = document) => Array.from(scope.querySelectorAll(selector));


    /* =========================================================================
       01. PRELOADER
       Hidden as soon as the window finishes loading. A timeout guarantees the
       page is never left behind the overlay if an asset stalls.
       ====================================================================== */
    /* How long the loader stays up at minimum, in ms.
       On localhost the page is ready in tens of milliseconds, so without a
       floor the loader flashes for a fraction of a second — the ring never
       completes a revolution and reads as frozen rather than spinning. One
       full rotation is 900ms, so this guarantees the visitor sees at least
       one complete turn. It costs nothing on a slow connection, where the
       real load time already exceeds it. */
    const PRELOADER_MIN_MS = 1100;

    function initPreloader() {
        const preloader = $('#preloader');
        const startedAt = performance.now();

        let dismissed = false;

        const dismiss = () => {
            if (dismissed) return;
            dismissed = true;

            if (preloader) {
                preloader.classList.add('is-hidden');
                // Remove from the accessibility tree once the fade completes.
                window.setTimeout(() => preloader.remove(), 700);
            }
            document.body.classList.add('is-ready');

            /* Release the scroll reveals (see module 02). Holding them until
               now means the first screen animates for the visitor rather than
               playing out unseen behind the overlay. */
            document.dispatchEvent(new CustomEvent('tt:reveal-start'));
        };

        /* A spinner that cannot spin is worse than no spinner: under reduced
           motion the ring is frozen by the stylesheet, so clear the overlay
           straight away rather than showing a stalled one. */
        if (prefersReducedMotion) {
            dismiss();
            return;
        }

        // Hold until the page is ready AND the minimum display time has passed.
        window.addEventListener('load', () => {
            const elapsed = performance.now() - startedAt;
            window.setTimeout(dismiss, Math.max(PRELOADER_MIN_MS - elapsed, 0));
        });

        window.setTimeout(dismiss, 6000);   // safety net if an asset stalls
    }


    /* =========================================================================
       02. SCROLL ENTRANCE CHOREOGRAPHY
       -------------------------------------------------------------------------
       AOS is the engine; the plan below is the direction. Rather than tagging
       `data-aos` by hand in the markup, every rule lives here so the motion
       reads as one designed system instead of per-section decoration.

       Three principles, borrowed from how print and film handle a reveal:

         1. One reveal per object. A card animates; its title, badge and text
            do not animate separately. Nested reveals read as noise.
         2. Stagger follows the eye. Items on the same visual row cascade
            left to right; a new row restarts at zero, so a 3-column grid never
            accumulates a sluggish 6-deep delay. Row grouping is measured from
            live layout, so it re-derives itself correctly at every breakpoint.
         3. Motion carries meaning. Split layouts converge inward, galleries
            scale up, statistics pop, editorial imagery wipes. The vocabulary
            is small and each verb is used for one purpose.

       Because the attributes are written by JS, a visitor without JavaScript —
       or one who asks for reduced motion — simply gets the fully visible page.
       ====================================================================== */

    /* Scope of the stagger counter:
         'row'    — group by measured vertical position (responsive grids)
         'parent' — group by the shared parent element (lists, headings)
         'index'  — one running sequence across the whole match (galleries)  */
    const REVEAL_PLAN = [
        /* Cards — the primary content objects. Row-aware cascade. */
        { selector: '.dest-card, .event-card, .news-card, .reason-card, .guide-card',
          animation: 'tt-rise', stagger: 100, scope: 'row' },

        /* Statistics pop rather than rise; they read as discrete figures. */
        { selector: '.stat-card', animation: 'tt-pop', stagger: 100, scope: 'row' },

        /* Gallery tiles scale up in a single running sequence, capped so the
           last tile never feels abandoned. */
        { selector: '.masonry__item', animation: 'tt-zoom', stagger: 50,
          scope: 'index', maxDelay: 500 },

        /* Split layouts converge toward the centre of the viewport. */
        { selector: '.map-frame, .contact-form-card', animation: 'tt-in-right' },
        { selector: '.about__gallery .about__img--tall', animation: 'tt-mask' },
        { selector: '.about__img--small', animation: 'tt-zoom', delay: 250 },
        { selector: '.about__badge',      animation: 'tt-pop',  delay: 400 },

        /* The testimonial block pulls into focus — used exactly once. */
        { selector: '#testimonialCarousel', animation: 'tt-blur' },

        /* Headings and body copy. Grouped by parent so a section heading
           cascades eyebrow → title → subtitle, and the same rule serves the
           loose headings inside the map and about columns. */
        { selector: '.eyebrow, .section-title, .section-sub, .about__lead',
          animation: 'tt-rise-sm', stagger: 100, scope: 'parent' },

        /* Row-level detail: legend entries, contact rows, mission/vision. */
        { selector: '.map-legend li',  animation: 'tt-rise-sm', stagger: 50,  scope: 'parent' },
        { selector: '.contact-list li',animation: 'tt-rise-sm', stagger: 50,  scope: 'parent' },
        { selector: '.mv-card',        animation: 'tt-rise-sm', stagger: 150, scope: 'parent' },
        { selector: '.contact-map',    animation: 'tt-rise-sm', delay: 200 },

        /* Standalone calls to action. Opacity-only: a button's transform is
           already carrying its hover lift and magnetic pull (module 11). */
        { selector: '.section > .container > .text-center > .btn', animation: 'tt-fade' },

        /* Footer columns settle in sequence. */
        { selector: '.footer__top [class*="col-"]',
          animation: 'tt-rise-sm', stagger: 100, scope: 'parent' }
    ];

    /**
     * AOS v2 does not write inline styles — it ships pre-generated CSS rules
     * for durations and delays in 50ms steps from 0 to 3000. A delay of, say,
     * 110ms matches no rule at all and is silently ignored, so every value has
     * to be snapped onto that grid and clamped to the top of the range.
     */
    function snapDelay(ms) {
        return Math.min(Math.round(ms / 50) * 50, 3000);
    }

    /**
     * Walks the plan and writes data-aos attributes onto the page.
     * Safe to call repeatedly — previous assignments are cleared first, which
     * is what makes the row grouping survive a resize or orientation change.
     */
    function choreograph() {
        /* Anything already revealed stays revealed. Without this, resizing the
           window would clear the attributes and replay every animation the
           visitor has already watched — re-measuring the layout should be
           invisible to them. AOS is configured `once: true`, so it never
           strips the class back off. */
        const alreadyRevealed = new Set($$('[data-tt-reveal].aos-animate'));

        // Clear the previous pass so rows can be re-measured from live layout.
        $$('[data-tt-reveal]').forEach((el) => {
            el.removeAttribute('data-aos');
            el.removeAttribute('data-aos-delay');
            el.removeAttribute('data-tt-reveal');
            el.classList.remove('aos-init', 'aos-animate');
        });

        REVEAL_PLAN.forEach((rule) => {
            const counters = new Map();   // group key → how many already placed

            $$(rule.selector).forEach((el) => {
                // Principle 1: never nest a reveal inside another reveal.
                if (el.parentElement && el.parentElement.closest('[data-aos]')) return;

                let delay = rule.delay || 0;

                if (rule.stagger) {
                    let key;

                    if (rule.scope === 'row') {
                        // Round to 8px so sub-pixel layout differences within a
                        // row don't split it into separate groups.
                        const top = Math.round(
                            (el.getBoundingClientRect().top + window.pageYOffset) / 8
                        );
                        key = 'row:' + top;
                    } else if (rule.scope === 'parent') {
                        key = el.parentElement;
                    } else {
                        key = 'all';
                    }

                    const position = counters.get(key) || 0;
                    counters.set(key, position + 1);
                    delay += position * rule.stagger;
                }

                if (rule.maxDelay) delay = Math.min(delay, rule.maxDelay);
                delay = snapDelay(delay);

                el.setAttribute('data-aos', rule.animation);
                if (delay) el.setAttribute('data-aos-delay', String(delay));
                el.setAttribute('data-tt-reveal', '');   // marks it as ours

                // Restore anything that had already finished revealing.
                if (alreadyRevealed.has(el)) el.classList.add('aos-init', 'aos-animate');
            });
        });
    }

    function initScrollReveal() {
        if (typeof AOS === 'undefined') return;

        // Reduced motion: never write the attributes at all, so none of the
        // entrance CSS can match and the page renders plainly.
        if (prefersReducedMotion) {
            AOS.init({ disable: true });
            return;
        }

        choreograph();

        AOS.init({
            duration: 850,
            easing: 'ease-out-cubic',
            once: true,        // a section animates the first time only
            offset: 80,        // trigger slightly before the element is centred
            /* Hold every reveal until the preloader has cleared, so the first
               screen animates for the visitor instead of behind the overlay. */
            startEvent: 'tt:reveal-start'
        });

        // Late-loading imagery shifts the page; re-measure trigger points.
        window.addEventListener('load', () => AOS.refresh());

        /* A breakpoint change reflows the grids, so the row groupings computed
           above are no longer correct — recompute them, then have AOS re-read
           the attributes. Only on a real width change: mobile browsers fire
           resize constantly as the address bar hides. */
        let lastWidth = window.innerWidth;
        let resizeTimer;

        window.addEventListener('resize', () => {
            if (window.innerWidth === lastWidth) return;
            lastWidth = window.innerWidth;

            window.clearTimeout(resizeTimer);
            resizeTimer = window.setTimeout(() => {
                choreograph();
                AOS.refreshHard();   // re-read the attributes we just rewrote
            }, 250);
        }, { passive: true });
    }


    /* =========================================================================
       03. STICKY NAVBAR, SCROLL PROGRESS & BACK-TO-TOP
       All three read the same scroll position inside one rAF-throttled handler.
       ====================================================================== */
    function initScrollUI() {
        const nav        = $('#mainNav');
        const progress   = $('#scrollProgress');
        const backToTop  = $('#backToTop');
        let ticking      = false;

        const update = () => {
            const scrollY = window.pageYOffset || document.documentElement.scrollTop;

            // Solid navbar once the user leaves the very top of the page
            if (nav) nav.classList.toggle('is-scrolled', scrollY > 60);

            // Reading progress
            if (progress) {
                const height = document.documentElement.scrollHeight - window.innerHeight;
                const pct = height > 0 ? (scrollY / height) * 100 : 0;
                progress.style.width = Math.min(pct, 100) + '%';
            }

            // Back-to-top appears after roughly one viewport
            if (backToTop) backToTop.classList.toggle('is-visible', scrollY > 500);

            ticking = false;
        };

        const onScroll = () => {
            if (ticking) return;
            ticking = true;
            window.requestAnimationFrame(update);
        };

        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll, { passive: true });
        update();   // set the correct state on first paint (e.g. after a reload)
    }


    /* =========================================================================
       04. SCROLL SPY — highlight the nav link for the section in view
       ====================================================================== */
    function initScrollSpy() {
        const sections = $$('section[id]');
        const navLinks = $$('.main-nav .nav-link[href^="#"]');
        if (!sections.length || !navLinks.length) return;

        const setActive = (id) => {
            navLinks.forEach((link) => {
                link.classList.toggle('active', link.getAttribute('href') === '#' + id);
            });
        };

        const observer = new IntersectionObserver(
            (entries) => {
                // Choose the most visible intersecting section.
                const visible = entries
                    .filter((entry) => entry.isIntersecting)
                    .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];

                if (visible) setActive(visible.target.id);
            },
            {
                // Ignore the strip hidden behind the sticky navbar.
                rootMargin: '-90px 0px -55% 0px',
                threshold: [0.1, 0.25, 0.5]
            }
        );

        sections.forEach((section) => observer.observe(section));
    }


    /* =========================================================================
       05. SMOOTH ANCHOR SCROLLING
       CSS `scroll-behavior` covers most cases; this adds offset handling and
       closes the mobile drawer after a link is tapped.
       ====================================================================== */
    function initSmoothScroll() {
        const navCollapse = $('#navMenu');

        $$('a[href^="#"]').forEach((link) => {
            link.addEventListener('click', (event) => {
                const hash = link.getAttribute('href');
                if (!hash || hash === '#') return;

                const target = document.getElementById(hash.slice(1));
                if (!target) return;   // e.g. modal triggers such as #privacy

                event.preventDefault();

                const navHeight = $('#mainNav') ? $('#mainNav').offsetHeight : 0;
                const top = target.getBoundingClientRect().top + window.pageYOffset - navHeight + 1;

                window.scrollTo({
                    top: Math.max(top, 0),
                    behavior: prefersReducedMotion ? 'auto' : 'smooth'
                });

                // Collapse the mobile menu after navigating.
                if (navCollapse && navCollapse.classList.contains('show') &&
                    typeof bootstrap !== 'undefined') {
                    bootstrap.Collapse.getOrCreateInstance(navCollapse).hide();
                }
            });
        });
    }


    /* =========================================================================
       06. ANIMATED STATISTICS COUNTERS
       Each counter runs once, the first time it scrolls into view.
       ====================================================================== */
    function initCounters() {
        const counters = $$('.counter');
        if (!counters.length) return;

        const runCounter = (el) => {
            const target   = parseInt(el.dataset.target, 10) || 0;
            const duration = 2000;
            const start    = performance.now();

            if (prefersReducedMotion) {
                el.textContent = target.toLocaleString('en-US');
                return;
            }

            const tick = (now) => {
                const elapsed  = now - start;
                const progress = Math.min(elapsed / duration, 1);
                // easeOutExpo — fast start, gentle settle on the final number
                const eased = progress === 1 ? 1 : 1 - Math.pow(2, -10 * progress);

                el.textContent = Math.round(target * eased).toLocaleString('en-US');

                if (progress < 1) window.requestAnimationFrame(tick);
            };

            window.requestAnimationFrame(tick);
        };

        const observer = new IntersectionObserver(
            (entries, obs) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) return;
                    runCounter(entry.target);
                    obs.unobserve(entry.target);   // animate once only
                });
            },
            { threshold: 0.45 }
        );

        counters.forEach((counter) => observer.observe(counter));
    }


    /* =========================================================================
       07. LEAFLET TOURIST MAP
       Markers are supplied by index.php as JSON on a data attribute, so the
       map stays in sync with the PHP data source.
       ====================================================================== */
    function initMap() {
        const container = $('#touristMap');
        if (!container || typeof L === 'undefined') return;

        let markers = [];
        try {
            markers = JSON.parse(container.dataset.markers || '[]');
        } catch (error) {
            console.warn('Tourist map: could not parse marker data.', error);
        }

        const centerLat = parseFloat(container.dataset.centerLat) || 6.4333;
        const centerLng = parseFloat(container.dataset.centerLng) || 124.9167;

        const map = L.map(container, {
            center: [centerLat, centerLng],
            zoom: 12,
            scrollWheelZoom: false,   // avoid hijacking the page scroll
            zoomControl: true
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 18,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        // Marker colour + icon per destination category
        const styles = {
            'Nature':      { color: 'green', icon: 'fa-mountain-sun' },
            'Eco-Tourism': { color: 'green', icon: 'fa-seedling' },
            'Agri-Tourism':{ color: 'green', icon: 'fa-mug-hot' },
            'Waterfalls':  { color: 'blue',  icon: 'fa-water' },
            'Culture':     { color: 'amber', icon: 'fa-drum' },
            'Office':      { color: 'red',   icon: 'fa-building-columns' }
        };

        const bounds = [];

        markers.forEach((item) => {
            const style = styles[item.type] || { color: 'green', icon: 'fa-location-dot' };

            const icon = L.divIcon({
                className: '',   // suppress Leaflet's default styling
                html: `<span class="map-pin map-pin--${style.color}">
                           <i class="fa-solid ${style.icon}"></i>
                       </span>`,
                iconSize: [34, 34],
                iconAnchor: [17, 34],
                popupAnchor: [0, -32]
            });

            L.marker([item.lat, item.lng], { icon, title: item.name })
                .addTo(map)
                .bindPopup(`<strong>${item.name}</strong><em>${item.type}</em>`);

            bounds.push([item.lat, item.lng]);
        });

        if (bounds.length > 1) {
            map.fitBounds(bounds, { padding: [45, 45] });
        }

        // Enable wheel zoom only while the map itself has focus.
        map.on('focus', () => map.scrollWheelZoom.enable());
        map.on('blur',  () => map.scrollWheelZoom.disable());

        // Leaflet mis-measures containers that were hidden or animating.
        window.setTimeout(() => map.invalidateSize(), 400);
    }


    /* =========================================================================
       08. PHOTO GALLERY LIGHTBOX
       Self-contained: no third-party lightbox library required.
       ====================================================================== */
    function initLightbox() {
        const triggers = $$('[data-lightbox]');
        const lightbox = $('#lightbox');
        if (!triggers.length || !lightbox) return;

        const imgEl     = $('#lightboxImg');
        const captionEl = $('#lightboxCaption');
        const counterEl = $('#lightboxCounter');

        let currentIndex = 0;
        let lastFocused  = null;

        const items = triggers.map((trigger) => ({
            src: trigger.getAttribute('href'),
            caption: trigger.dataset.caption || ''
        }));

        const render = (index) => {
            // Wrap around at both ends
            currentIndex = (index + items.length) % items.length;
            const item = items[currentIndex];

            imgEl.src = item.src;
            imgEl.alt = item.caption;
            captionEl.textContent = item.caption;
            counterEl.textContent = `${currentIndex + 1} / ${items.length}`;
        };

        const open = (index) => {
            lastFocused = document.activeElement;
            render(index);

            lightbox.hidden = false;
            // Next frame so the opening transition can play.
            window.requestAnimationFrame(() => lightbox.classList.add('is-open'));

            document.body.style.overflow = 'hidden';   // lock background scroll
            $('[data-lb-close]', lightbox).focus();
        };

        const close = () => {
            lightbox.classList.remove('is-open');
            document.body.style.overflow = '';

            window.setTimeout(() => {
                lightbox.hidden = true;
                imgEl.src = '';
            }, 350);

            if (lastFocused) lastFocused.focus();
        };

        triggers.forEach((trigger, index) => {
            trigger.addEventListener('click', (event) => {
                event.preventDefault();
                open(index);
            });
        });

        $('[data-lb-close]', lightbox).addEventListener('click', close);
        $('[data-lb-prev]',  lightbox).addEventListener('click', () => render(currentIndex - 1));
        $('[data-lb-next]',  lightbox).addEventListener('click', () => render(currentIndex + 1));

        // Clicking the backdrop (but not the image or a button) closes the viewer.
        lightbox.addEventListener('click', (event) => {
            if (event.target === lightbox) close();
        });

        document.addEventListener('keydown', (event) => {
            if (lightbox.hidden) return;

            if (event.key === 'Escape')     close();
            if (event.key === 'ArrowLeft')  render(currentIndex - 1);
            if (event.key === 'ArrowRight') render(currentIndex + 1);
        });
    }


    /* =========================================================================
       09. CONTACT FORM VALIDATION

       Client-side validation ONLY. The form posts to api/contact/submit.php and
       the server has the final say — this exists to catch an empty required
       field before a round trip, not to decide anything.

       WHAT THIS USED TO DO: preventDefault on every submit, wait 1.2 seconds,
       and print "your message has been received". Nothing was sent and nothing
       was stored. Every enquiry made through this form since the site went up
       was discarded, and each of those people believed the office had read it
       and chosen not to reply. The fake success is gone.
       ====================================================================== */
    function initContactForm() {
        const form  = $('#contactForm');
        const alert = $('#formAlert');
        if (!form || !alert) return;

        const showAlert = (type, message) => {
            alert.className = `form-alert form-alert--${type} is-visible`;
            alert.innerHTML =
                `<i class="fa-solid ${type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'}"></i>
                 <span>${message}</span>`;
        };

        form.addEventListener('submit', (event) => {
            // Bootstrap's validation styles are driven by :invalid + .was-validated
            form.classList.add('was-validated');

            if (!form.checkValidity()) {
                // The ONLY branch that stops the submission.
                event.preventDefault();
                showAlert('error', 'Please complete all required fields before sending.');
                const firstInvalid = form.querySelector(':invalid');
                if (firstInvalid) firstInvalid.focus();
                return;
            }

            /* Valid: let the browser submit normally. The spinner is honest now
               — it runs until the page navigates, and the message the visitor
               reads afterwards comes from the server having actually stored it.

               The button is NOT disabled: a disabled submit button is omitted
               from the POST body, and disabling it here would also strand the
               visitor on a dead form if the request failed. */
            const button = form.querySelector('button[type="submit"]');

            if (button) {
                button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending&hellip;';
            }
        });

        // Clear the alert as soon as the visitor starts correcting the form.
        form.addEventListener('input', () => {
            alert.classList.remove('is-visible');
        });
    }


    /* =========================================================================
       10. IMAGE LOADING & FALLBACKS
       Native lazy loading does the heavy lifting; this adds a gentle fade-in
       and a neutral placeholder for any remote image that fails to load.
       ====================================================================== */
    function initImages() {
        /* Logos are referenced as .png (the official raster artwork) with a
           .svg placeholder named in data-fallback. Until the real files are
           dropped in, this swaps them in silently rather than showing a broken
           image — and once they exist, the handler simply never fires. */
        $$('img[data-fallback]').forEach((image) => {
            image.addEventListener('error', () => {
                const fallback = image.dataset.fallback;
                if (fallback && !image.src.endsWith(fallback)) image.src = fallback;
            }, { once: true });
        });

        $$('img[loading="lazy"]').forEach((image) => {
            // Fade in once decoded
            if (!image.complete) {
                image.style.opacity = '0';
                image.style.transition = 'opacity .5s ease';

                image.addEventListener('load', () => {
                    image.style.opacity = '1';
                }, { once: true });
            }

            // Placeholder gradient if a remote photo 404s or the network drops
            image.addEventListener('error', () => {
                image.style.opacity = '1';
                image.style.background = 'linear-gradient(135deg, #2E7D32 0%, #0288D1 100%)';
                image.style.minHeight = '180px';
                image.alt = image.alt || 'Image unavailable';
            }, { once: true });
        });
    }


    /* =========================================================================
       11. INTERACTIVE UI EFFECTS
       -------------------------------------------------------------------------
       Pointer-driven motion: card tilt, cursor spotlight, magnetic buttons,
       click ripples, and hero parallax.

       Every effect writes to a CSS custom property rather than setting
       `transform` directly — the composed transform in style.css section 24
       already reserves a slot for each one, so tilt, hover lift, entrance
       reveal, and magnetism coexist instead of overwriting one another.

       The whole module is skipped on touch and reduced-motion devices. Tilt
       and spotlight on a touchscreen leave a card stuck in its hover state
       after a tap, which is worse than having no effect at all.
       ====================================================================== */

    const TILT_MAX = 5;      // degrees — subtle enough to read as depth, not novelty
    const MAGNET_MAX = 7;    // px a button may drift toward the cursor

    /** Card tilt + cursor spotlight. */
    function initCardInteraction() {
        const cards = $$('.dest-card, .event-card, .news-card, .reason-card, .guide-card');
        if (!cards.length) return;

        cards.forEach((card) => {
            let frame = null;

            const onMove = (event) => {
                if (frame) return;               // one update per animation frame
                frame = window.requestAnimationFrame(() => {
                    frame = null;

                    const box = card.getBoundingClientRect();
                    const x = (event.clientX - box.left) / box.width;    // 0 → 1
                    const y = (event.clientY - box.top) / box.height;

                    // Tilt away from the cursor: pointer top-left lifts that corner.
                    card.style.setProperty('--tilt-y', ((x - 0.5) * 2 * TILT_MAX).toFixed(2) + 'deg');
                    card.style.setProperty('--tilt-x', ((0.5 - y) * 2 * TILT_MAX).toFixed(2) + 'deg');

                    // Spotlight follows the cursor exactly.
                    card.style.setProperty('--mx', (x * 100).toFixed(1) + '%');
                    card.style.setProperty('--my', (y * 100).toFixed(1) + '%');
                });
            };

            const reset = () => {
                if (frame) { window.cancelAnimationFrame(frame); frame = null; }
                card.style.setProperty('--tilt-x', '0deg');
                card.style.setProperty('--tilt-y', '0deg');
                card.style.setProperty('--mx', '50%');
                card.style.setProperty('--my', '50%');
            };

            card.addEventListener('pointermove', onMove);
            card.addEventListener('pointerleave', reset);
            card.addEventListener('blur', reset, true);
        });
    }

    /** Magnetic pull on the primary calls to action. */
    function initMagneticButtons() {
        const buttons = $$('.btn-primary-grad, .btn-admin, .btn-outline-brand');

        buttons.forEach((button) => {
            let frame = null;

            button.addEventListener('pointermove', (event) => {
                if (frame) return;
                frame = window.requestAnimationFrame(() => {
                    frame = null;

                    const box = button.getBoundingClientRect();
                    const dx = (event.clientX - (box.left + box.width / 2)) / (box.width / 2);
                    const dy = (event.clientY - (box.top + box.height / 2)) / (box.height / 2);

                    button.style.setProperty('--mag-x', (dx * MAGNET_MAX).toFixed(1) + 'px');
                    button.style.setProperty('--mag-y', (dy * MAGNET_MAX).toFixed(1) + 'px');
                });
            });

            button.addEventListener('pointerleave', () => {
                if (frame) { window.cancelAnimationFrame(frame); frame = null; }
                button.style.setProperty('--mag-x', '0px');
                button.style.setProperty('--mag-y', '0px');
            });
        });
    }

    /** Material-style click ripple, sized to cover the button from the click point. */
    function initRipples() {
        document.addEventListener('pointerdown', (event) => {
            const button = event.target.closest('.btn');
            if (!button) return;

            const box = button.getBoundingClientRect();

            // Radius must reach the farthest corner from where the click landed.
            const x = event.clientX - box.left;
            const y = event.clientY - box.top;
            const radius = Math.max(
                Math.hypot(x, y),
                Math.hypot(box.width - x, y),
                Math.hypot(x, box.height - y),
                Math.hypot(box.width - x, box.height - y)
            );

            const ripple = document.createElement('span');
            ripple.className = 'tt-ripple';
            ripple.style.width = ripple.style.height = radius * 2 + 'px';
            ripple.style.left = x - radius + 'px';
            ripple.style.top = y - radius + 'px';

            button.appendChild(ripple);
            ripple.addEventListener('animationend', () => ripple.remove());
        });
    }

    /**
     * Hero parallax — the background drifts at roughly a third of scroll speed
     * while the headline lifts away and fades. Reads only inside the hero's
     * own height, so it costs nothing once the visitor has scrolled past.
     */
    function initHeroParallax() {
        const hero = $('.hero');
        if (!hero) return;

        const content = $$('.hero__content');
        let frame = null;

        const update = () => {
            frame = null;

            const height = hero.offsetHeight;
            const scrollY = window.pageYOffset;
            if (scrollY > height) return;        // hero is off screen

            const progress = scrollY / height;   // 0 → 1 across the hero

            $$('.hero__bg').forEach((bg) => {
                bg.style.setProperty('--parallax', (scrollY * 0.35).toFixed(1) + 'px');
            });

            content.forEach((el) => {
                el.style.setProperty('--hero-drift', (scrollY * 0.18).toFixed(1) + 'px');
                el.style.setProperty('--hero-fade', Math.max(1 - progress * 1.5, 0).toFixed(2));
            });
        };

        window.addEventListener('scroll', () => {
            if (frame) return;
            frame = window.requestAnimationFrame(update);
        }, { passive: true });

        update();
    }

    function initInteractiveEffects() {
        // Ripples are click-driven, so they are safe on touch — only the
        // hover-dependent effects need a fine pointer.
        if (!prefersReducedMotion) initRipples();

        const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
        if (prefersReducedMotion || !finePointer) return;

        initCardInteraction();
        initMagneticButtons();
        initHeroParallax();
    }



    /* =========================================================================
       12. HERO VIDEO
       -------------------------------------------------------------------------
       The <video> ships with no src. This decides whether the visitor should
       receive it and only then attaches the sources, so nothing downloads for
       anyone who should not have it.

       Four groups are excluded, and none of them is an edge case here:

         · Reduced motion — an autoplaying video is exactly what that setting
           asks us not to do.
         · Save-Data — the visitor has explicitly asked their browser to
           conserve bandwidth.
         · Slow connections (2g/3g) — the video would arrive after they left.
         · Narrow screens — a tourist on mobile data in an upland barangay pays
           for every megabyte, and the poster frame tells the same story.

       Everyone excluded sees the poster image, which is a real destination
       photograph. Nothing looks broken.
       ====================================================================== */

    function initHeroVideo() {
        const video = $('#heroVideo');
        if (!video) return;                     // no video file installed

        /* Every skip below is deliberate, but silent skipping is impossible to
           diagnose from the outside — "the video just doesn't appear" gives no
           clue which rule fired. Each reason is logged, and ?video=force on the
           URL overrides them all for testing. */
        const forced = new URLSearchParams(location.search).get('video') === 'force';

        const skip = (reason) => {
            if (forced) {
                console.info('[hero video] would skip (' + reason + ') — overridden by ?video=force');
                return false;
            }
            console.info('[hero video] not loaded: ' + reason);
            return true;
        };

        if (prefersReducedMotion &&
            skip('the operating system is set to reduce motion')) return;

        const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        if (connection) {
            if (connection.saveData && skip('the browser has Save-Data turned on')) return;
            if (/2g|slow-2g|3g/.test(connection.effectiveType || '') &&
                skip('the connection reports ' + connection.effectiveType)) return;
        }

        // Phones stay on the poster. This is a bandwidth decision, not a
        // capability one — the video would play perfectly well.
        if (window.matchMedia('(max-width: 767.98px)').matches &&
            skip('the viewport is under 768px wide, so mobile data is assumed')) return;

        let sources;
        try {
            sources = JSON.parse(video.dataset.sources || '[]');
        } catch (e) {
            return;
        }
        if (!sources.length) return;

        /* Both hero layers are driven the same way, so the whole start-up
           sequence lives in one place and the blurred fill cannot drift out of
           step with the copy in front of it. */
        const start = (element) => {
            sources.forEach((item) => {
                const source = document.createElement('source');
                source.src = item.src;
                source.type = item.type;
                element.appendChild(source);
            });

            element.load();

            /* Autoplay can still be refused — iOS Low Power Mode does exactly
               that. The poster stays visible, so a refusal costs the visitor
               nothing and needs no message. */
            const attempt = element.play();
            if (attempt && typeof attempt.catch === 'function') {
                attempt.catch(() => element.classList.add('is-unavailable'));
            } else {
                element.classList.add('is-playing');
            }

            element.addEventListener('playing', () => {
                element.classList.add('is-playing');
            }, { once: true });

            element.addEventListener('error', () => {
                console.warn('[hero video] the file could not be loaded or decoded — the poster stays visible');
                element.classList.add('is-unavailable');
            });
        };

        /* Vertical footage cannot fill a landscape hero. Rather than assume
           either shape, the real dimensions are read off the file once the
           metadata arrives: portrait footage switches the hero to the fitted
           clip with a blurred copy of itself behind, landscape footage is left
           alone to cover the section as it always did.

           Registered before playback starts, because on a cached file the
           metadata can be ready before the next line runs. */
        video.addEventListener('loadedmetadata', () => {
            const portrait = video.videoHeight > video.videoWidth;

            console.info('[hero video] playing — ' + video.videoWidth + 'x' + video.videoHeight +
                         (portrait ? ' (portrait: fitted, with a blurred fill behind it)' : ' (landscape)'));

            if (!portrait) return;

            const hero = video.closest('.hero');
            if (hero) hero.classList.add('is-portrait-video');

            /* Same URL the main layer just fetched, so this is a cache hit
               rather than a second download. */
            const fill = hero && $('.hero__video--fill', hero);
            if (fill) start(fill);
        }, { once: true });

        start(video);

        /* A video playing behind a page nobody is looking at is wasted
           battery and wasted data. */
        document.addEventListener('visibilitychange', () => {
            $$('.hero__video').forEach((element) => {
                if (document.hidden) { element.pause(); }
                else if (element.classList.contains('is-playing')) { element.play().catch(() => {}); }
            });
        });
    }

    /* =========================================================================
       BOOTSTRAP THE PAGE
       ====================================================================== */
    /**
     * Runs one module, and keeps going if it fails.
     *
     * These used to be called in a bare sequence, which meant a single throw
     * anywhere silently killed every module after it — and the symptom was
     * always "the last thing I added doesn't work", pointing at the wrong
     * code. Now a failure is named in the console and the rest still run.
     */
    /* ---------------------------------------------------------------------
       The destination video playlist
       ---------------------------------------------------------------------
       One player, stepped through with Prev/Next or jumped into by pressing a
       year. The clips arrive as JSON on the container, already resolved by
       VideoRepository::playlist() — this never builds a URL, because the check
       that a YouTube id is a real id lives on the server and would be worth
       nothing if a script could assemble the src afterwards.

       Switching rebuilds the frame rather than swapping a src attribute: an
       uploaded file is a <video> and a linked one is an <iframe>, and stepping
       from one to the other means changing the element, not its address.
       ------------------------------------------------------------------ */
    function initVideoPlaylist() {
        var root = document.getElementById('vplay');
        var frame = document.getElementById('vplayFrame');

        if (!root || !frame) { return; }

        var clips;

        try {
            clips = JSON.parse(root.dataset.clips || '[]');
        } catch (e) {
            return;
        }

        if (!clips.length) { return; }

        var prev    = root.querySelector('[data-vplay="prev"]');
        var next    = root.querySelector('[data-vplay="next"]');
        var title   = root.querySelector('[data-vplay="title"]');
        var meta    = root.querySelector('[data-vplay="meta"]');
        var counter = root.querySelector('[data-vplay="index"]');

        var at = 0;

        function render(index, autoplay) {
            var clip = clips[index];
            if (!clip) { return; }

            at = index;

            /* Emptied first so the outgoing <video> stops. Assigning innerHTML
               alone leaves a detached element that keeps playing its audio in
               some browsers. */
            var old = frame.querySelector('video');
            if (old) { old.pause(); }
            frame.innerHTML = '';

            /* The frame is one fixed 16:9 rectangle whatever goes in it, so
               stepping between an uploaded file and a linked one swaps the
               element and nothing else. */
            var el;

            if (clip.kind === 'upload') {
                el = document.createElement('video');
                el.controls = true;
                el.playsInline = true;
                el.preload = 'metadata';
                if (clip.poster) { el.poster = clip.poster; }

                var source = document.createElement('source');
                source.src = clip.src;
                source.type = clip.mime || 'video/mp4';
                el.appendChild(source);
                el.appendChild(document.createTextNode('Your browser cannot play this video.'));
            } else {
                el = document.createElement('iframe');
                el.src = clip.src;
                el.title = clip.title;
                el.loading = 'lazy';
                el.allowFullscreen = true;
                el.referrerPolicy = 'strict-origin-when-cross-origin';
            }

            frame.appendChild(el);

            /* Only after a press, never on load — a video that starts talking
               when a page opens is the reason people keep their sound off. */
            if (autoplay && clip.kind === 'upload') {
                var playing = el.play();
                if (playing && typeof playing.catch === 'function') { playing.catch(function () {}); }
            }

            if (title)   { title.textContent = clip.title; }
            if (meta)    { meta.textContent = (clip.year ? clip.year + ' · ' : '') + (clip.label || ''); }
            if (counter) { counter.textContent = String(index + 1); }

            if (prev) { prev.disabled = index === 0; }
            if (next) { next.disabled = index === clips.length - 1; }
        }

        if (prev) { prev.addEventListener('click', function () { render(at - 1, true); }); }
        if (next) { next.addEventListener('click', function () { render(at + 1, true); }); }

        /* Arrow keys, but only while the focus is inside the playlist — the
           page is long and Left/Right elsewhere belong to the reader. */
        root.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowLeft'  && at > 0)                { render(at - 1, true); }
            if (event.key === 'ArrowRight' && at < clips.length - 1) { render(at + 1, true); }
        });
    }

    function run(name, fn) {
        try {
            fn();
        } catch (error) {
            console.error('[TourSync] module "' + name + '" failed:', error);
        }
    }

    function init() {
        // Reveal choreography is registered before the preloader can release it.
        run('scrollReveal',       initScrollReveal);
        run('preloader',          initPreloader);

        // Early, and no longer hostage to anything that runs after it.
        run('heroVideo',          initHeroVideo);

        run('scrollUI',           initScrollUI);
        run('scrollSpy',          initScrollSpy);
        run('smoothScroll',       initSmoothScroll);
        run('counters',           initCounters);
        run('map',                initMap);
        run('lightbox',           initLightbox);
        run('videoPlaylist',      initVideoPlaylist);
        run('contactForm',        initContactForm);
        run('images',             initImages);
        run('interactiveEffects', initInteractiveEffects);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();



/* =============================================================================
   12. THE RAIL — arrows, dots, and the turn between pages
   =============================================================================
   Drives any element marked data-rail. The strip itself is CSS: a grid that
   scrolls sideways and snaps. This moves it a page at a time, keeps the arrows
   honest about whether there is anywhere left to go, draws one dot per page,
   and marks a card as arrived so it can settle into place.

   THREE THINGS IT HAS TO GET RIGHT.

   The filter. Destinations and Announcements both have chips and a search box,
   and both work by setting `hidden` on the cards that no longer match — code
   this must not disturb. So it watches for that attribute changing rather than
   being told. Filter down to two results and the dots and arrows put
   themselves away; clear the filter and they come back.

   The page count. Dots are per PAGE, not per card: ten destinations at five a
   page is two dots. Per-card dots would be eleven grey specks saying nothing.

   The last page. Scrolling by a full container width lands between snap points
   when the last page is short, so every move is clamped to the scrollable
   maximum and the browser's own snapping finishes the job.
   ========================================================================== */

(function () {
    'use strict';

    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function init() {
        var rails = document.querySelectorAll('[data-rail]');

        if (rails.length === 0) { return; }

        Array.prototype.forEach.call(rails, function (rail) {
            var id   = rail.id;
            var prev = document.querySelector('[data-rail-prev="' + id + '"]');
            var next = document.querySelector('[data-rail-next="' + id + '"]');
            var dots = document.getElementById(rail.getAttribute('data-rail-dots') || '');

            /* A card's width plus one gap, read from the DOM rather than from
               the breakpoint — so the five/four/three counts live in one place,
               the stylesheet, and cannot drift out of step with this. */
            function step() {
                var card = rail.querySelector(':scope > *:not([hidden])');

                if (!card) { return rail.clientWidth || 1; }

                var gap = parseFloat(getComputedStyle(rail).columnGap) || 0;

                return card.getBoundingClientRect().width + gap;
            }

            function cards() {
                return rail.querySelectorAll(':scope > *:not([hidden])');
            }

            function perPage() {
                return Math.max(1, Math.round(rail.clientWidth / step()));
            }

            function pageCount() {
                return Math.max(1, Math.ceil(cards().length / perPage()));
            }

            function currentPage() {
                var span = perPage() * step();

                return Math.min(pageCount() - 1, Math.round(rail.scrollLeft / span));
            }

            function maxScroll() {
                return rail.scrollWidth - rail.clientWidth;
            }

            function goTo(page) {
                rail.scrollLeft = Math.max(0,
                    Math.min(maxScroll(), page * perPage() * step()));
            }

            function move(direction) {
                rail.scrollLeft = Math.max(0, Math.min(
                    maxScroll(), rail.scrollLeft + direction * perPage() * step()));
            }

            /* ---- the dots ------------------------------------------------ */

            var drawnFor = -1;

            function drawDots() {
                if (!dots) { return; }

                var pages = pageCount();

                /* Rebuilt only when the number changes. A filter that removes
                   one card of ten changes nothing here, and replacing the dots
                   on every scroll event would restart their transition. */
                if (pages === drawnFor) { return; }

                drawnFor = pages;
                dots.textContent = '';

                if (pages < 2) {
                    dots.hidden = true;
                    return;
                }

                dots.hidden = false;

                for (var i = 0; i < pages; i++) {
                    (function (page) {
                        var dot = document.createElement('button');

                        dot.type = 'button';
                        dot.className = 'rail-dot';
                        dot.setAttribute('role', 'tab');
                        dot.setAttribute('aria-label', 'Page ' + (page + 1) + ' of ' + pages);
                        dot.addEventListener('click', function () { goTo(page); });

                        dots.appendChild(dot);
                    })(i);
                }
            }

            function markDots() {
                if (!dots || dots.hidden) { return; }

                var here = currentPage();

                Array.prototype.forEach.call(dots.children, function (dot, i) {
                    dot.classList.toggle('is-active', i === here);
                    dot.setAttribute('aria-selected', i === here ? 'true' : 'false');
                });
            }

            /* ---- the turn -------------------------------------------------
               A card is "in" once it is genuinely inside the strip's own box.
               Measured rather than watched with IntersectionObserver: the
               scroller is the root here and its own left edge is what moves, so
               reading the rectangles directly is both shorter and exactly right
               during a smooth scroll.
               -------------------------------------------------------------- */

            function settle() {
                if (reduced) { return; }

                var box = rail.getBoundingClientRect();

                Array.prototype.forEach.call(cards(), function (card) {
                    var r = card.getBoundingClientRect();

                    card.classList.toggle('is-in',
                        r.right > box.left + 8 && r.left < box.right - 8);
                });
            }

            /* ---- keeping everything honest --------------------------------- */

            function update() {
                var scrolls = maxScroll() > 1;

                if (prev) { prev.hidden = !scrolls; }
                if (next) { next.hidden = !scrolls; }

                if (!scrolls) {
                    if (dots) { dots.hidden = true; drawnFor = -1; }
                    settle();
                    return;
                }

                /* A few pixels of tolerance, not one. Snap settles the strip
                   against a card's edge rather than the container's, and the
                   rail carries a little side padding so a shadow is not
                   clipped — so "at the start" measured 2, and Previous stayed
                   lit at the start of the strip with nowhere to go. */
                if (prev) { prev.disabled = rail.scrollLeft <= 4; }
                if (next) { next.disabled = rail.scrollLeft >= maxScroll() - 4; }

                drawDots();
                markDots();
                settle();
            }

            if (prev) { prev.addEventListener('click', function () { move(-1); }); }
            if (next) { next.addEventListener('click', function () { move(1); }); }

            /* Fires throughout a smooth scroll, so the arrows, the dots and the
               settling all resolve as the strip travels rather than after it. */
            rail.addEventListener('scroll', update, { passive: true });

            window.addEventListener('resize', function () { drawnFor = -1; update(); });

            /* The chips and the search box set `hidden` on the cards. Watching
               for it means the filters needed no change at all — and a filter
               that leaves one page has to put the arrows and dots away, or Next
               scrolls a strip that no longer has a second page. */
            new MutationObserver(function () {
                rail.scrollLeft = 0;
                drawnFor = -1;
                update();
            }).observe(rail, {
                attributes: true,
                attributeFilter: ['hidden'],
                subtree: true,
            });

            /* Photographs land after this runs and can change the row height, so
               a late layout must not leave the arrows describing the old one. */
            window.addEventListener('load', function () { drawnFor = -1; update(); });

            update();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
