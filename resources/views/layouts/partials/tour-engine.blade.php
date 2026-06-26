{{-- ════════════════════════════════════════════════════════════════════════
     COREX TOUR ENGINE — interactive, spotlight help tours (driver.js).

     Self-contained: the ONLY central wiring is a single @include of this
     partial in the app layouts. It loads its own vendored driver.js assets
     (public/vendor/driverjs/*), renders a "?" launcher for the current page's
     tour, auto-runs the tour once per user, and persists progress via
     /api/v1/tours/{key}/{seen|dismiss}.

     A tour is pure data — see App\Support\Tours\TourRegistry. Add an entry
     there and it lights up here automatically. No edit to this file needed.

     Library choice: driver.js (1.3.6) over Shepherd.js — driver.js is ~21 KB
     (IIFE) with zero runtime deps; Shepherd pulls in @floating-ui and is
     ~3-4× larger. For a lightweight, always-loaded helper, lighter wins.
     ════════════════════════════════════════════════════════════════════════ --}}
@auth
@php
    $__tourRouteName = \Illuminate\Support\Facades\Route::currentRouteName();
    $__tour          = \App\Support\Tours\TourRegistry::forRoute($__tourRouteName);
    // Respect optional per-tour role-gating (defaults to "inherit the route's gate").
    if ($__tour && ! \App\Support\Tours\TourRegistry::visibleTo($__tour, auth()->user())) {
        $__tour = null;
    }
@endphp
@if($__tour)
    @php
        $__tourProgress = \App\Models\UserTourProgress::where('user_id', auth()->id())
            ->where('tour_key', $__tour['key'])
            ->first();
        // Force-start when arrived from the Guided Tours directory (?tour=<key>),
        // even if the user has seen/dismissed it before. Otherwise honour progress.
        $__tourForced   = request()->query('tour') === $__tour['key'];
        $__tourAutoStart = $__tourForced || ! ($__tourProgress && $__tourProgress->suppressesAutoStart());
    @endphp

    {{-- Vendored assets — loaded once per page regardless of include count. --}}
    @once
        {{-- Assets are emitted inline here (body-level): this partial is included
             AFTER the layout's <head> @stack('head') has already rendered, so a
             @push('head') would arrive too late. Inline <link>/<style>/<script>
             in the body are valid and load deterministically. --}}
        <link rel="stylesheet" href="{{ asset('vendor/driverjs/driver.css') }}">
        <style>
            /* Theme driver.js to CoreX (brand-aware, dark-friendly). */
            .driver-popover.corex-tour {
                background: var(--surface, #fff);
                color: var(--text-primary, #111827);
                border: 1px solid var(--border, rgba(0,0,0,0.08));
                border-radius: 12px;
                box-shadow: 0 18px 50px rgba(0,0,0,0.35);
                min-width: 380px;
                max-width: 440px;
                padding: 18px 20px;
            }
            .driver-popover.corex-tour .driver-popover-title {
                font-size: 1rem; font-weight: 700;
                color: var(--text-primary, #111827);
            }
            .driver-popover.corex-tour .driver-popover-description {
                font-size: 0.85rem; line-height: 1.5;
                color: var(--text-secondary, #4b5563);
            }
            /* ── Two-row footer ─────────────────────────────────────────────
               driver.js stuffs progress, prev/next AND our injected controls
               ("Don't show again", "Close tour") into one flex row, which
               overlaps at narrow widths. A 2-col / 2-row grid gives a clean,
               deterministic layout that never overlaps:
                 Row 1 (controls): [Don't show again]            [Close tour]
                 Row 2 (nav):      [progress]               [← Back] [Next →]
               "Don't show again" only exists on step 1; on later steps row 1
               holds just the top-right Close tour, which is fine. */
            .driver-popover.corex-tour .driver-popover-footer {
                display: grid;
                grid-template-columns: 1fr auto;
                align-items: center;
                gap: 12px 12px;
                margin-top: 16px;
                padding-top: 14px;
                border-top: 1px solid var(--border, rgba(0,0,0,0.08));
            }
            .driver-popover.corex-tour .corex-tour-dsa            { grid-column: 1; grid-row: 1; }
            .driver-popover.corex-tour .corex-tour-close          { grid-column: 2; grid-row: 1; justify-self: end; }
            .driver-popover.corex-tour .driver-popover-progress-text   { grid-column: 1; grid-row: 2; justify-self: start; }
            .driver-popover.corex-tour .driver-popover-navigation-btns { grid-column: 2; grid-row: 2; justify-self: end; }

            .driver-popover.corex-tour .driver-popover-progress-text {
                font-size: 0.7rem; color: var(--text-muted, #9ca3af);
            }
            /* Uniform footer buttons — same height/radius/font for nav + close. */
            .driver-popover.corex-tour .driver-popover-navigation-btns button,
            .driver-popover.corex-tour .corex-tour-close {
                box-sizing: border-box;
                height: 28px; padding: 0 12px;
                display: inline-flex; align-items: center; justify-content: center;
                font-size: 0.72rem; font-weight: 600; line-height: 1;
                border-radius: 6px; cursor: pointer;
            }
            .driver-popover.corex-tour .driver-popover-navigation-btns button {
                background: var(--brand-button, #0ea5e9);
                color: #fff; text-shadow: none; border: 0;
            }
            .driver-popover.corex-tour .driver-popover-navigation-btns button.driver-popover-prev-btn {
                background: var(--surface-2, #f1f5f9);
                color: var(--text-secondary, #475569);
            }
            .driver-popover.corex-tour .driver-popover-navigation-btns button + button { margin-left: 6px; }
            .driver-popover.corex-tour .driver-popover-close-btn {
                color: var(--text-muted, #9ca3af);
            }
            .driver-popover.corex-tour .corex-tour-dsa {
                display: inline-flex; align-items: center; gap: 6px;
                font-size: 0.7rem; color: var(--text-muted, #9ca3af);
                cursor: pointer; user-select: none;
            }
            .driver-popover.corex-tour .corex-tour-dsa input { accent-color: var(--brand-button, #0ea5e9); }
            /* AT-41: explicit "Close tour" control (overlay/X/ESC close disabled).
               Styled identical to the Back button (surface-2 chip); resets
               driver.js's default text-shadow:1px 1px 0 #fff, which otherwise
               paints a white halo on the text in dark mode. Height/padding/radius/
               font come from the shared button rule above. */
            .driver-popover.corex-tour .corex-tour-close,
            .driver-popover.corex-tour .corex-tour-close:hover {
                background: var(--surface-2, #f1f5f9);
                color: var(--text-secondary, #475569);
                border: 0;
                text-shadow: none;
                text-decoration: none;
            }

            /* ── Header launcher button ─────────────────────────────────────
               The "?" lives ONLY in a page header's action group, dropped into
               #tour-launcher-slot by the engine. It never floats: it stays
               hidden until it is relocated into a slot, and sizes to match the
               other header icon/action buttons.

               Two header backdrops exist, so the slot carries a variant class
               and the button adapts:
                 • navy banner  (background:var(--brand-default)) → white-on-navy,
                   matching the translucent-white action buttons in those headers.
                 • surface bar  (x-page-header / x-list-header, var(--surface)) →
                   a muted outline icon button, matching the surface header chrome. */
            .corex-tour-launcher-btn {
                display: inline-flex; align-items: center; justify-content: center;
                width: 2rem; height: 2rem; padding: 0;
                border-radius: 6px; cursor: pointer;
                transition: background 0.3s ease, border-color 0.3s ease, color 0.3s ease;
            }
            .corex-tour-launcher-btn svg { width: 1.1rem; height: 1.1rem; }

            /* Navy banner (default) */
            .corex-tour-launcher-btn,
            .tour-slot-navy .corex-tour-launcher-btn {
                background: rgba(255,255,255,0.08); color: #fff;
                border: 1px solid rgba(255,255,255,0.18);
            }
            .corex-tour-launcher-btn:hover,
            .tour-slot-navy .corex-tour-launcher-btn:hover {
                background: rgba(255,255,255,0.18);
                border-color: rgba(255,255,255,0.30);
            }
            /* Surface bar */
            .tour-slot-surface .corex-tour-launcher-btn {
                background: transparent;
                color: var(--text-muted, #6b7280);
                border: 1px solid var(--border, #e5e7eb);
            }
            .tour-slot-surface .corex-tour-launcher-btn:hover {
                color: var(--brand-button, #0ea5e9);
                border-color: var(--brand-button, #0ea5e9);
                background: color-mix(in srgb, var(--brand-button, #0ea5e9) 10%, transparent);
            }
        </style>
        <script src="{{ asset('vendor/driverjs/driver.js.iife.js') }}"></script>
    @endonce

    <div id="corex-tour-root"
         x-data="coreXTour({ tour: {{ \Illuminate\Support\Js::from($__tour) }}, autoStart: {{ $__tourAutoStart ? 'true' : 'false' }}, csrf: '{{ csrf_token() }}' })"
         x-init="init()">
        {{-- Launcher button. Rendered hidden here at body level; init() relocates
             it into the page-header slot (#tour-launcher-slot) and reveals it.
             It NEVER floats — if a tour-bearing page is missing the header slot
             the button simply stays hidden (the tour still auto-starts and is
             launchable from the Guided Tours directory). --}}
        <button type="button" class="corex-tour-launcher-btn" @click="start()"
                x-ref="launcher" style="display:none;"
                :title="'Take a tour: ' + tour.title" aria-label="Take a tour of this page">
            <svg fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
            </svg>
        </button>
    </div>

    @once
    <script>
    function coreXTour(cfg) {
        return {
            tour: cfg.tour,
            autoStart: cfg.autoStart,
            csrf: cfg.csrf,
            _driver: null,
            _finished: false,
            _suppressWritten: false,

            init() {
                // Relocate the launcher into the page-header slot if the host page
                // provides one, and reveal it there. With no slot the button stays
                // hidden — it never floats.
                this.$nextTick(() => {
                    const slot = document.getElementById('tour-launcher-slot');
                    const btn  = this.$refs.launcher;
                    if (slot && btn) {
                        slot.appendChild(btn);
                        btn.style.display = '';
                    }
                });

                if (this.autoStart) {
                    // Let Alpine, maps and async widgets settle before spotlighting.
                    window.setTimeout(() => this.start(), 900);
                }
            },

            // Theme-aware spotlight overlay: a dark veil reads well over the light
            // UI, but over the dark theme it hides the highlighted boxes — so in
            // dark mode flip to a grey overlay (the highlighted element becomes a
            // dark island on a lighter field).
            _overlayColor() {
                const dark = document.documentElement.classList.contains('dark');
                return dark ? 'rgba(149,151,153,0.96)' : 'rgba(3,6,12,0.93)';
            },

            _runSetup() {
                (this.tour.setup || []).forEach((s) => {
                    try {
                        if (s.action === 'alpineSet') {
                            const el = document.querySelector(s.selector);
                            if (el && window.Alpine && Alpine.$data) {
                                const data = Alpine.$data(el);
                                if (data && (s.prop in data)) data[s.prop] = s.value;
                            }
                        } else if (s.action === 'click') {
                            document.querySelector(s.selector)?.click();
                        } else if (s.action === 'scrollTop') {
                            const main = document.querySelector('main');
                            if (main) main.scrollTop = 0;
                            window.scrollTo(0, 0);
                        }
                    } catch (e) { /* setup is best-effort */ }
                });
            },

            _buildSteps() {
                // Skip any step whose anchor isn't on the page right now — a tour
                // should never dead-end on a missing element.
                return (this.tour.steps || [])
                    .filter((s) => document.querySelector(s.element))
                    .map((s) => ({
                        element: s.element,
                        popover: { title: s.title, description: s.body, popoverClass: 'corex-tour' },
                    }));
            },

            start() {
                if (!window.driver || !window.driver.js) {
                    console.warn('[corex-tour] driver.js not loaded');
                    return;
                }
                this._runSetup();
                // setup may reveal elements (Alpine x-show) — wait for the DOM to update.
                this.$nextTick(() => window.setTimeout(() => this._drive(), 60));
            },

            _drive() {
                const steps = this._buildSteps();
                if (!steps.length) { console.warn('[corex-tour] no visible anchors'); return; }

                this._finished = false;
                const factory = window.driver.js.driver;
                const self = this;

                this._driver = factory({
                    showProgress: true,
                    // AT-41 UX fix (Andre): a first-day agent must not lose the tour
                    // by an accidental outside click. allowClose:false disables the
                    // overlay-click close, the ESC close AND the popover X — so the
                    // ONLY ways a tour ends are the explicit "Close tour" button
                    // (injected below) or completing the last step.
                    allowClose: false,
                    animate: true,
                    overlayColor: this._overlayColor(),
                    stagePadding: 6,
                    stageRadius: 8,
                    popoverClass: 'corex-tour',
                    nextBtnText: 'Next →',
                    prevBtnText: '← Back',
                    doneBtnText: 'Done',
                    steps,
                    onPopoverRender: (popover, opts) => self._decoratePopover(popover, opts),
                    onNextClick: () => {
                        if (!self._driver.hasNextStep()) { self._finished = true; self._driver.destroy(); }
                        else self._driver.moveNext();
                    },
                    onPrevClick: () => self._driver.movePrevious(),
                    // Both "Close tour" and completing the last step end the tour
                    // and mark it SEEN (completed_at) so it won't nag on next login.
                    // "Don't show again" is handled separately (writes the suppress
                    // flag without ending the tour) in _decoratePopover.
                    onDestroyed: () => {
                        self._record('seen');
                    },
                });

                this._driver.drive();
            },

            _decoratePopover(popover, opts) {
                try {
                    if (!popover || !popover.footer) return;
                    const self = this;
                    const idx = (opts && opts.state && typeof opts.state.activeIndex === 'number')
                        ? opts.state.activeIndex : 0;

                    // (3) Explicit "Close tour" button — on EVERY step. The only
                    //     manual way to end the tour now that the overlay/X/ESC are
                    //     disabled. Ends the tour (→ onDestroyed marks it seen).
                    //     For READ-ONLY tours (e-sign) this does nothing beyond
                    //     ending the tour — it never touches the underlying page.
                    if (!popover.footer.querySelector('[data-tour-close]')) {
                        const closeBtn = document.createElement('button');
                        closeBtn.type = 'button';
                        closeBtn.setAttribute('data-tour-close', '');
                        closeBtn.className = 'corex-tour-close';
                        closeBtn.textContent = 'Close tour';
                        closeBtn.addEventListener('click', () => {
                            self._finished = true;       // close == seen (won't nag)
                            self._driver.destroy();
                        });
                        // Sits at the start of the footer, left of Back/Next.
                        popover.footer.insertBefore(closeBtn, popover.footer.firstChild);
                    }

                    // (2) "Don't show again" — first step only. Ticking it records
                    //     the suppress preference (dismissed_at) so the tour won't
                    //     AUTO-launch next session — but it does NOT end the current
                    //     tour. The agent keeps stepping through.
                    if (idx === 0 && !popover.footer.querySelector('[data-tour-dsa]')) {
                        const label = document.createElement('label');
                        label.setAttribute('data-tour-dsa', '');
                        label.className = 'corex-tour-dsa';
                        const cb = document.createElement('input');
                        cb.type = 'checkbox';
                        const txt = document.createTextNode("Don't show again");
                        label.appendChild(cb);
                        label.appendChild(txt);
                        cb.addEventListener('change', () => {
                            if (cb.checked && !self._suppressWritten) {
                                self._suppressWritten = true;     // write once
                                self._record('dismiss');          // flag only — no destroy
                                txt.textContent = "Won't show again";
                            }
                        });
                        popover.footer.insertBefore(label, popover.footer.firstChild);
                    }
                } catch (e) { /* decoration is optional */ }
            },

            async _record(kind) {
                const endpoint = kind === 'seen' ? 'seen' : 'dismiss';
                const url = '/api/v1/tours/' + encodeURIComponent(this.tour.key) + '/' + endpoint;
                try {
                    await fetch(url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': this.csrf,
                        },
                    });
                } catch (e) { /* progress write is best-effort */ }
            },
        };
    }
    </script>
    @endonce
@endif
@endauth
{{-- /COREX TOUR ENGINE --}}
