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
                border-radius: 10px;
                box-shadow: 0 18px 50px rgba(0,0,0,0.35);
                max-width: 340px;
            }
            .driver-popover.corex-tour .driver-popover-title {
                font-size: 0.95rem; font-weight: 700;
                color: var(--text-primary, #111827);
            }
            .driver-popover.corex-tour .driver-popover-description {
                font-size: 0.8125rem; line-height: 1.5;
                color: var(--text-secondary, #4b5563);
            }
            .driver-popover.corex-tour .driver-popover-progress-text {
                font-size: 0.7rem; color: var(--text-muted, #9ca3af);
            }
            /* Footer is rebuilt by _decoratePopover() into two stacked rows so the
               injected "Close tour" / "Don't show again" controls never collide
               with the progress text or the Back/Next buttons (driver sets the
               footer's inline display:flex per render — flex-direction:column here
               keeps the two rows stacked). */
            .driver-popover.corex-tour .driver-popover-footer {
                margin-top: 16px;
                display: flex;
                flex-direction: column;
                align-items: stretch;
                gap: 0;
            }
            .driver-popover.corex-tour .corex-tour-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 8px;
                width: 100%;
            }
            /* Controls row (Don't show again + Close tour) sits above the nav row,
               separated by a hairline. */
            .driver-popover.corex-tour .corex-tour-row-controls {
                padding-bottom: 10px;
                margin-bottom: 10px;
                border-bottom: 1px solid var(--border, rgba(0,0,0,0.08));
            }
            .driver-popover.corex-tour .driver-popover-navigation-btns {
                display: flex; gap: 8px; align-items: center;
            }
            /* Uniform buttons across Back / Next/Done / Close tour. driver toggles
               next/prev display between block/none per step, so we use inline-block
               + text-align (compatible with both) rather than flex centering. */
            .driver-popover.corex-tour button.driver-popover-prev-btn,
            .driver-popover.corex-tour button.driver-popover-next-btn,
            .driver-popover.corex-tour .corex-tour-close {
                all: unset;
                box-sizing: border-box;
                display: inline-block;
                text-align: center;
                padding: 7px 16px;
                border-radius: 6px;
                font-size: 0.75rem;
                font-weight: 600;
                line-height: 1.2;
                cursor: pointer;
                text-shadow: none;
                transition: background-color .15s ease, border-color .15s ease, color .15s ease, filter .15s ease;
            }
            /* Primary action — Next / Done. */
            .driver-popover.corex-tour button.driver-popover-next-btn {
                background: var(--brand-button, #0ea5e9);
                color: #fff;
                border: 1px solid var(--brand-button, #0ea5e9);
            }
            .driver-popover.corex-tour button.driver-popover-next-btn:hover {
                filter: brightness(1.06);
            }
            /* Secondary actions — Back / Close tour share one look. */
            .driver-popover.corex-tour button.driver-popover-prev-btn,
            .driver-popover.corex-tour .corex-tour-close {
                background: var(--surface-2, #f1f5f9);
                color: var(--text-secondary, #475569);
                border: 1px solid var(--border, rgba(0,0,0,0.12));
            }
            .driver-popover.corex-tour button.driver-popover-prev-btn:hover,
            .driver-popover.corex-tour .corex-tour-close:hover {
                color: var(--text-primary, #1e293b);
                border-color: var(--text-muted, #94a3b8);
            }
            /* driver disables (not hides) Back on the first step — keep it muted. */
            .driver-popover.corex-tour button.driver-popover-prev-btn.driver-popover-btn-disabled {
                opacity: .45; cursor: default;
            }
            .driver-popover.corex-tour .driver-popover-close-btn { color: var(--text-muted, #9ca3af); }
            .driver-popover.corex-tour .corex-tour-dsa {
                display: flex; align-items: center; gap: 6px;
                font-size: 0.72rem; color: var(--text-muted, #9ca3af);
                cursor: pointer; user-select: none;
            }
            .driver-popover.corex-tour .corex-tour-dsa input { accent-color: var(--brand-button, #0ea5e9); }
            /* Floating fallback launcher (used only when no sidebar slot exists). */
            #corex-tour-launcher-floating {
                position: fixed; right: 18px; bottom: 18px; z-index: 9990;
            }
            .corex-tour-launcher-btn {
                display: inline-flex; align-items: center; gap: 6px;
                background: var(--brand-button, #0ea5e9); color: #fff;
                border: 0; border-radius: 999px; cursor: pointer;
                padding: 8px 14px; font-size: 0.75rem; font-weight: 600;
                box-shadow: 0 6px 18px rgba(0,0,0,0.18);
            }
            .corex-tour-launcher-btn.in-slot {
                padding: 0; width: 2.25rem; height: 2.25rem;
                justify-content: center; border-radius: 8px; box-shadow: none;
            }
            .corex-tour-launcher-btn svg { width: 1rem; height: 1rem; }
        </style>
        <script src="{{ asset('vendor/driverjs/driver.js.iife.js') }}"></script>
    @endonce

    <div id="corex-tour-root"
         x-data="coreXTour({ tour: {{ \Illuminate\Support\Js::from($__tour) }}, autoStart: {{ $__tourAutoStart ? 'true' : 'false' }}, csrf: '{{ csrf_token() }}' })"
         x-init="init()">
        {{-- Launcher button. Lives in a floating wrapper by default; init() relocates
             it into the sidebar slot (#tour-launcher-slot) when that slot is present,
             so a lost/merged-away sidebar edit degrades gracefully to a floating button. --}}
        <div id="corex-tour-launcher-floating">
            <button type="button" class="corex-tour-launcher-btn" @click="start()"
                    x-ref="launcher"
                    :title="'Take a tour: ' + tour.title" aria-label="Take a tour of this page">
                <svg fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
                </svg>
                <span x-show="!inSlot" x-cloak>Tour</span>
            </button>
        </div>
    </div>

    @once
    <script>
    function coreXTour(cfg) {
        return {
            tour: cfg.tour,
            autoStart: cfg.autoStart,
            csrf: cfg.csrf,
            inSlot: false,
            _driver: null,
            _finished: false,
            _suppressWritten: false,

            init() {
                // Relocate the launcher into the sidebar slot if the host page provides one.
                this.$nextTick(() => {
                    const slot = document.getElementById('tour-launcher-slot');
                    const btn  = this.$refs.launcher;
                    if (slot && btn) {
                        btn.classList.add('in-slot');
                        slot.appendChild(btn);
                        const floatWrap = document.getElementById('corex-tour-launcher-floating');
                        if (floatWrap) floatWrap.remove();
                        this.inSlot = true;
                    }
                });

                if (this.autoStart) {
                    // Let Alpine, maps and async widgets settle before spotlighting.
                    window.setTimeout(() => this.start(), 900);
                }
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
                    overlayColor: 'rgba(11,42,74,0.65)',
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
                    const footer = popover.footer;
                    const idx = (opts && opts.state && typeof opts.state.activeIndex === 'number')
                        ? opts.state.activeIndex : 0;

                    // (1) Build the two-row scaffold once per popover element. driver
                    //     reuses the same popover/footer DOM across steps (it only
                    //     rewrites button text + toggles display), so moving its
                    //     native progress text + nav buttons into a dedicated nav row
                    //     is stable — they keep updating in place by reference.
                    let rowControls = footer.querySelector('.corex-tour-row-controls');
                    if (!rowControls) {
                        rowControls = document.createElement('div');
                        rowControls.className = 'corex-tour-row corex-tour-row-controls';
                        const rowNav = document.createElement('div');
                        rowNav.className = 'corex-tour-row corex-tour-row-nav';

                        const progress = footer.querySelector('.driver-popover-progress-text');
                        const navBtns  = footer.querySelector('.driver-popover-navigation-btns');
                        // Spacer keeps the buttons right-aligned even if progress is
                        // hidden for a given tour.
                        rowNav.appendChild(progress || document.createElement('span'));
                        if (navBtns) rowNav.appendChild(navBtns);

                        footer.appendChild(rowControls);   // controls row on top
                        footer.appendChild(rowNav);        // nav row below
                    }

                    // (2) Explicit "Close tour" button — on EVERY step. The only
                    //     manual way to end the tour now that the overlay/X/ESC are
                    //     disabled. Ends the tour (→ onDestroyed marks it seen).
                    //     For READ-ONLY tours (e-sign) this does nothing beyond
                    //     ending the tour — it never touches the underlying page.
                    //     Lives at the right of the controls row.
                    if (!rowControls.querySelector('[data-tour-close]')) {
                        const closeBtn = document.createElement('button');
                        closeBtn.type = 'button';
                        closeBtn.setAttribute('data-tour-close', '');
                        closeBtn.className = 'corex-tour-close';
                        closeBtn.textContent = 'Close tour';
                        closeBtn.addEventListener('click', () => {
                            self._finished = true;       // close == seen (won't nag)
                            self._driver.destroy();
                        });
                        rowControls.appendChild(closeBtn);
                    }

                    // (3) "Don't show again" — first step only. Ticking it records
                    //     the suppress preference (dismissed_at) so the tour won't
                    //     AUTO-launch next session — but it does NOT end the current
                    //     tour. The agent keeps stepping through. Sits at the left of
                    //     the controls row.
                    if (idx === 0 && !rowControls.querySelector('[data-tour-dsa]')) {
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
                        rowControls.insertBefore(label, rowControls.firstChild);
                    }

                    // When only the Close button is present (steps after the first),
                    // push it to the right; with the checkbox present, space them out.
                    rowControls.style.justifyContent =
                        rowControls.querySelector('[data-tour-dsa]') ? 'space-between' : 'flex-end';
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
