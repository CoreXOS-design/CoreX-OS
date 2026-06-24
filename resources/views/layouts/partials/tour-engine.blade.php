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
@endphp
@if($__tour)
    @php
        $__tourProgress = \App\Models\UserTourProgress::where('user_id', auth()->id())
            ->where('tour_key', $__tour['key'])
            ->first();
        $__tourAutoStart = ! ($__tourProgress && $__tourProgress->suppressesAutoStart());
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
            .driver-popover.corex-tour .driver-popover-navigation-buttons button {
                background: var(--brand-button, #0ea5e9);
                color: #fff; text-shadow: none; border: 0;
                border-radius: 6px; padding: 5px 12px; font-size: 0.75rem; font-weight: 600;
            }
            .driver-popover.corex-tour .driver-popover-navigation-buttons button.driver-popover-prev-btn {
                background: var(--surface-2, #f1f5f9);
                color: var(--text-secondary, #475569);
            }
            .driver-popover.corex-tour .driver-popover-close-btn {
                color: var(--text-muted, #9ca3af);
            }
            .driver-popover.corex-tour .corex-tour-dsa {
                display: flex; align-items: center; gap: 6px;
                font-size: 0.7rem; color: var(--text-muted, #9ca3af);
                margin-right: auto; cursor: pointer; user-select: none;
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
                    allowClose: true,
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
                    onCloseClick: () => self._driver.destroy(),
                    onDestroyed: () => {
                        self._record(self._finished ? 'seen' : 'dismiss');
                    },
                });

                this._driver.drive();
            },

            _decoratePopover(popover, opts) {
                // "Don't show again" on the first step only.
                try {
                    const idx = (opts && opts.state && typeof opts.state.activeIndex === 'number')
                        ? opts.state.activeIndex : 0;
                    if (idx !== 0 || !popover || !popover.footer) return;
                    if (popover.footer.querySelector('[data-tour-dsa]')) return;

                    const label = document.createElement('label');
                    label.setAttribute('data-tour-dsa', '');
                    label.className = 'corex-tour-dsa';
                    const cb = document.createElement('input');
                    cb.type = 'checkbox';
                    label.appendChild(cb);
                    label.appendChild(document.createTextNode("Don't show again"));
                    cb.addEventListener('change', () => {
                        if (cb.checked) { this._finished = false; this._driver.destroy(); }
                    });
                    popover.footer.insertBefore(label, popover.footer.firstChild);
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
