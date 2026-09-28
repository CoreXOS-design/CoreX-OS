{{-- ════════════════════════════════════════════════════════════════════════
     COREX TOUR ENGINE — spotlight help tours + Advanced Guiding + Spot Help.

     Self-contained: the ONLY central wiring is a single include of this
     partial in the app layouts. It loads its own vendored driver.js assets
     (public/vendor/driverjs/*), renders the "?" launcher (a menu: Guided Tour ·
     Advanced Guide · Spot Help) for the current page's tour, auto-runs the
     Guided Tour once per user, and persists progress via
     /api/v1/tours/{key}/{seen|dismiss|step}.

     A tour is pure data — see App\Support\Tours\TourRegistry (its docblock
     documents the Advanced/Spot step fields: section, do, advanced_only,
     skip_if, prep, pick_from). Add an entry there and it lights up here
     automatically. No edit to this file needed.

     Three run modes, one step runner:
       tour     — explanation: Next → Next → Done (the original behaviour).
       advanced — hands-on: every step waits for the agent to DO it, then moves
                  on by itself. The page stays fully usable under a light veil.
       spot     — advanced, limited to one `section`.
     Spec: .ai/specs/advanced-guiding.md

     Library choice: driver.js (1.3.6) over Shepherd.js — driver.js is ~21 KB
     (IIFE) with zero runtime deps; Shepherd pulls in @floating-ui and is
     ~3-4× larger. For a lightweight, always-loaded helper, lighter wins.
     ════════════════════════════════════════════════════════════════════════ --}}
@auth
@php
    // The agency's Guided Tours switch governs ALL of it — the "?" menu, the
    // first-visit tour, Advanced Guide / Spot Help and guide handoffs. Off means
    // no help surface at all (config/corex-features.php 'guided-tours' → affects).
    $__guiding       = app(\App\Services\Features\AgencyFeatureService::class)->enabled('guided-tours');
    $__tourRouteName = \Illuminate\Support\Facades\Route::currentRouteName();
    $__tour          = $__guiding ? \App\Support\Tours\TourRegistry::forRoute($__tourRouteName) : null;
    // Respect optional per-tour role-gating (defaults to "inherit the route's gate").
    if ($__tour && ! \App\Support\Tours\TourRegistry::visibleTo($__tour, auth()->user())) {
        $__tour = null;
    }

    // A guide requested for a record page (e.g. Spot Help on one property) lands
    // on its pick list first; resolve that tour's note + label server-side so
    // the URL never carries display text.
    $__guideReqKey = request()->query('guide') ?: request()->query('tour');
    $__guidePick   = null;
    if ($__guiding && request()->boolean('pick') && is_string($__guideReqKey)) {
        $__pickTour = \App\Support\Tours\TourRegistry::find($__guideReqKey);
        if ($__pickTour && ! empty($__pickTour['pick_from'])
            && \App\Support\Tours\TourRegistry::canOpen($__pickTour, auth()->user())) {
            $__guidePick = [
                'key'   => $__pickTour['key'],
                'title' => (string) ($__pickTour['title'] ?? $__pickTour['key']),
                'note'  => (string) ($__pickTour['pick_note'] ?? 'Open the record you want to work on and the guide will pick up from there.'),
            ];
        }
    }
@endphp

@if($__guiding)
{{-- ── Guide handoff (every page) ──────────────────────────────────────────
     Keeps a guide alive across page loads without the server: a PENDING guide
     (asked for from Ellie/the directory, waiting for the agent to open the right
     record) and an ACTIVE guide (mid-run, e.g. a Save that reloads the page).
     PENDING lives in localStorage because lists open records in a NEW tab
     (target=_blank rel=noopener — sessionStorage does not travel there); ACTIVE
     stays in this tab's sessionStorage. Both lapse after the TTL. --}}
<script>
(function () {
    const PENDING = 'corex.guide.pending';
    const ACTIVE  = 'corex.guide.active';
    const TTL_MS  = {{ \App\Support\Tours\TourRegistry::GUIDE_TTL_MINUTES }} * 60 * 1000;
    const pageTourKey = @json($__tour['key'] ?? null);
    const pick = @json($__guidePick);

    const store = (k) => (k === PENDING ? window.localStorage : window.sessionStorage);
    const read = (k) => {
        try {
            const v = JSON.parse(store(k).getItem(k) || 'null');
            if (v && v.key && (Date.now() - (v.ts || 0)) < TTL_MS) return v;
        } catch (_) {}
        try { store(k).removeItem(k); } catch (_) {}
        return null;
    };
    const write = (k, v) => { try { v ? store(k).setItem(k, JSON.stringify({ ...v, ts: Date.now() })) : store(k).removeItem(k); } catch (_) {} };

    // An active guide only survives a reload of ITS OWN page. Landing anywhere
    // else means the agent walked away from it — drop it silently.
    const active = read(ACTIVE);
    if (active && active.key !== pageTourKey) write(ACTIVE, null);

    // Arrived on a pick list with a guide request → park it as pending.
    if (pick) {
        const p = new URLSearchParams(window.location.search);
        const mode = p.get('guide') ? (p.get('mode') === 'spot' ? 'spot' : 'advanced') : 'tour';
        write(PENDING, {
            key: pick.key, mode, section: mode === 'spot' ? (p.get('section') || null) : null,
            label: mode === 'spot' ? ('Spot Help: ' + (p.get('section') || pick.title))
                 : mode === 'advanced' ? ('Advanced Guide: ' + pick.title) : pick.title,
            note: pick.note,
        });
    }

    const banner = () => {
        const pending = read(PENDING);
        document.getElementById('corex-guide-pending')?.remove();
        if (!pending || pending.key === pageTourKey) return;
        const el = document.createElement('div');
        el.id = 'corex-guide-pending';
        el.className = 'corex-guide-pending';
        el.setAttribute('role', 'status');
        const text = document.createElement('div');
        const strong = document.createElement('strong');
        strong.textContent = pending.label || 'Guide';
        const note = document.createElement('span');
        note.textContent = pending.note || '';
        text.appendChild(strong); text.appendChild(note);
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.textContent = 'Cancel';
        cancel.addEventListener('click', () => { write(PENDING, null); el.remove(); });
        el.appendChild(text); el.appendChild(cancel);
        document.body.appendChild(el);
    };

    window.CoreXGuide = Object.assign(window.CoreXGuide || {}, {
        pageTourKey,
        readPending: () => read(PENDING),
        clearPending: () => write(PENDING, null),
        readActive: () => read(ACTIVE),
        writeActive: (v) => write(ACTIVE, v),
        // An Ellie/directory guide button. Starts in place when this page runs
        // that tour, otherwise navigates to the button's launch URL.
        launch(g) {
            if (!g || !g.key) return;
            window.dispatchEvent(new CustomEvent('corex-guide:launching', { detail: g }));
            if (g.key === pageTourKey && typeof window.CoreXGuide.startHere === 'function') {
                window.CoreXGuide.startHere(g.mode || 'advanced', g.section || null);
                return;
            }
            if (g.url) window.location.href = g.url;
        },
    });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', banner);
    else banner();
})();
</script>
<style>
    .corex-guide-pending {
        position: fixed; left: 50%; bottom: 20px; transform: translateX(-50%);
        z-index: 9990; display: flex; align-items: center; gap: 14px;
        max-width: min(560px, calc(100vw - 32px));
        padding: 12px 14px 12px 16px; border-radius: 10px;
        background: var(--surface, #fff); color: var(--text-primary, #111827);
        border: 1px solid color-mix(in srgb, var(--brand-button, #0ea5e9) 45%, var(--border, #e5e7eb));
        box-shadow: 0 12px 34px rgba(0,0,0,0.25);
        font-size: 0.8rem; line-height: 1.4;
    }
    .corex-guide-pending strong { display: block; font-size: 0.8rem; margin-bottom: 2px; }
    .corex-guide-pending span { color: var(--text-secondary, #4b5563); }
    .corex-guide-pending button {
        flex-shrink: 0; height: 28px; padding: 0 12px; border-radius: 6px; border: 0; cursor: pointer;
        font-size: 0.72rem; font-weight: 600;
        background: var(--surface-2, #f1f5f9); color: var(--text-secondary, #475569);
    }
</style>
@endif

@if($__tour)
    @php
        $__tourProgress = \App\Models\UserTourProgress::where('user_id', auth()->id())
            ->where('tour_key', $__tour['key'])
            ->first();
        // Force-start when arrived from the Guided Tours directory (?tour=<key>),
        // even if the user has seen/dismissed it before. Otherwise honour progress.
        $__tourForced    = request()->query('tour') === $__tour['key'];
        $__tourAutoStart = $__tourForced || ! ($__tourProgress && $__tourProgress->suppressesAutoStart());

        // Explicit Advanced/Spot request for THIS page (?guide=<key>&mode=…&section=…).
        $__guideRequest = null;
        if (request()->query('guide') === $__tour['key'] && ! request()->boolean('pick')) {
            $__guideRequest = [
                'mode'    => request()->query('mode') === 'spot' ? 'spot' : 'advanced',
                'section' => request()->query('section'),
            ];
        }

        $__tourClient = \App\Support\Tours\TourRegistry::forClient($__tour);
    @endphp

    {{-- Vendored assets — loaded once per page regardless of include count. --}}
    @once
        {{-- Assets are emitted inline here (body-level): this partial is included
             AFTER the layout's <head> stack('head') has already rendered, so a
             push('head') would arrive too late. Inline <link>/<style>/<script>
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
            @media (max-width: 480px) {
                .driver-popover.corex-tour { min-width: 0; max-width: calc(100vw - 24px); }
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
                display: block !important;
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
            .driver-popover.corex-tour .driver-popover-navigation-btns button.corex-guide-skip {
                background: transparent;
                color: var(--text-muted, #6b7280);
                border: 1px solid var(--border, #e5e7eb);
            }
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

            /* ── Advanced Guide / Spot Help ─────────────────────────────────
               The agent must be able to TYPE and CLICK on the real page while a
               step is highlighted — including dropdowns/pickers that render
               outside the highlighted box. driver.js blocks pointer events on
               everything but the active element; while a hands-on guide runs we
               lift that block and use a light veil + a brand ring instead. */
            body.corex-guide-live.driver-active *,
            body.corex-guide-live.driver-active .driver-overlay,
            body.corex-guide-live.driver-active .driver-overlay path { pointer-events: auto; }
            body.corex-guide-live.driver-active .driver-overlay,
            body.corex-guide-live.driver-active .driver-overlay path { pointer-events: none !important; }
            body.corex-guide-live .driver-active-element {
                outline: 3px solid var(--brand-button, #0ea5e9) !important;
                outline-offset: 3px;
                border-radius: 6px;
            }
            .driver-popover.corex-tour .corex-guide-do {
                display: flex; align-items: flex-start; gap: 8px;
                margin-top: 10px; padding: 9px 11px; border-radius: 8px;
                font-weight: 600; font-size: 0.82rem; line-height: 1.4;
                color: var(--text-primary, #111827);
                background: color-mix(in srgb, var(--brand-button, #0ea5e9) 12%, transparent);
                border: 1px solid color-mix(in srgb, var(--brand-button, #0ea5e9) 35%, transparent);
            }
            .driver-popover.corex-tour .corex-guide-do::before {
                content: '→'; color: var(--brand-button, #0ea5e9); font-weight: 800;
            }
            .driver-popover.corex-tour .corex-guide-do.is-done {
                color: #047857;
                background: color-mix(in srgb, #10b981 14%, transparent);
                border-color: color-mix(in srgb, #10b981 40%, transparent);
            }
            .driver-popover.corex-tour .corex-guide-do.is-done::before { content: '✓'; color: #10b981; }
            html.dark .driver-popover.corex-tour .corex-guide-do.is-done { color: #6ee7b7; }
            .driver-popover.corex-tour .corex-guide-hint {
                margin-top: 8px; font-size: 0.75rem; line-height: 1.4;
                color: #b45309;
            }
            html.dark .driver-popover.corex-tour .corex-guide-hint { color: #fbbf24; }
            .driver-popover.corex-tour .corex-guide-section {
                display: inline-block; margin-bottom: 6px;
                font-size: 0.66rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase;
                color: var(--brand-button, #0ea5e9);
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

            /* ── "?" menu ───────────────────────────────────────────────────
               Fixed-positioned from the button's rect so no header's overflow
               can clip it. */
            .corex-tour-menu {
                position: fixed; z-index: 10050;
                width: 280px; max-width: calc(100vw - 24px);
                max-height: calc(100vh - 80px); overflow-y: auto;
                padding: 6px; border-radius: 10px;
                background: var(--surface, #fff);
                border: 1px solid var(--border, #e5e7eb);
                box-shadow: 0 16px 40px rgba(0,0,0,0.28);
                text-align: left;
            }
            .corex-tour-menu-item {
                display: block; width: 100%; padding: 8px 10px; border-radius: 7px;
                border: 0; background: transparent; cursor: pointer; text-align: left;
                transition: background 0.2s ease;
            }
            .corex-tour-menu-item:hover,
            .corex-tour-menu-item:focus-visible {
                background: color-mix(in srgb, var(--brand-button, #0ea5e9) 10%, transparent);
                outline: none;
            }
            .corex-tour-menu-item strong {
                display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-primary, #111827);
            }
            .corex-tour-menu-item span {
                display: block; font-size: 0.7rem; line-height: 1.35; color: var(--text-muted, #6b7280);
            }
            .corex-tour-menu-head {
                padding: 8px 10px 4px; margin-top: 4px;
                border-top: 1px solid var(--border, #e5e7eb);
                font-size: 0.66rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase;
                color: var(--text-muted, #6b7280);
            }
            .corex-tour-menu-section {
                display: block; width: 100%; padding: 6px 10px 6px 18px; border-radius: 6px;
                border: 0; background: transparent; cursor: pointer; text-align: left;
                font-size: 0.78rem; color: var(--text-secondary, #4b5563);
            }
            .corex-tour-menu-section:hover,
            .corex-tour-menu-section:focus-visible {
                background: color-mix(in srgb, var(--brand-button, #0ea5e9) 10%, transparent);
                color: var(--text-primary, #111827); outline: none;
            }
        </style>
        <script src="{{ asset('vendor/driverjs/driver.js.iife.js') }}"></script>
    @endonce

    <div id="corex-tour-root"
         x-data="coreXTour({ tour: {{ \Illuminate\Support\Js::from($__tourClient) }}, autoStart: {{ $__tourAutoStart ? 'true' : 'false' }}, forced: {{ $__tourForced ? 'true' : 'false' }}, request: {{ \Illuminate\Support\Js::from($__guideRequest) }}, csrf: '{{ csrf_token() }}', completedSteps: {{ \Illuminate\Support\Js::from($__tourProgress?->completed_steps ?? []) }} })"
         @keydown.escape.window="menuOpen = false"
         @corex-guide:start.window="start($event.detail.mode || 'advanced', $event.detail.section || null, true)">
        {{-- Launcher button + its menu. Rendered hidden here at body level; init()
             relocates the wrapper into the page-header slot (#tour-launcher-slot)
             and reveals it. It NEVER floats — if a tour-bearing page is missing
             the header slot the button simply stays hidden (the tour still
             auto-starts and is launchable from the Guided Tours directory). --}}
        <span x-ref="launcher" style="display:none;">
            <button type="button" class="corex-tour-launcher-btn" x-ref="launcherBtn"
                    @click="onLauncher()"
                    :aria-expanded="menuOpen ? 'true' : 'false'"
                    :title="hasMenu() ? 'Help with this page' : ('Take a tour: ' + tour.title)"
                    aria-label="Help with this page">
                <svg fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
                </svg>
            </button>
        </span>

        <template x-teleport="body">
            <div x-show="menuOpen" x-cloak class="corex-tour-menu" role="menu"
                 :style="menuStyle" @click.outside="if (!_btn || !_btn.contains($event.target)) menuOpen = false">
                <button type="button" class="corex-tour-menu-item" role="menuitem" @click="pick('tour')">
                    <strong>Guided Tour</strong>
                    <span>A quick explanation of this page, one box at a time.</span>
                </button>
                <template x-if="tour.advanced">
                    <button type="button" class="corex-tour-menu-item" role="menuitem" @click="pick('advanced')">
                        <strong>Advanced Guide</strong>
                        <span>Walks you through actually doing it — step by step, moving on as you go.</span>
                    </button>
                </template>
                <template x-if="tour.spot">
                    <div>
                        <div class="corex-tour-menu-head">Spot Help — just one part</div>
                        <template x-for="name in tour.sections" :key="name">
                            <button type="button" class="corex-tour-menu-section" role="menuitem"
                                    @click="pick('spot', name)" x-text="name"></button>
                        </template>
                    </div>
                </template>
            </div>
        </template>
    </div>

    @once
    <script>
    function coreXTour(cfg) {
        const VIS_WAIT_TOUR = 450;       // ms to wait for a step's box in the explanation tour
        const VIS_WAIT_GUIDE = 6000;     // …and in a hands-on guide (screens load/animate in)
        const ABSENT_WAIT = 1500;        // a box not in the page AT ALL (e.g. an admin-only field) — skip fast
        const UNTIL_WAIT = 12000;        // how long a click's `until` result may take to show
        const TYPE_PAUSE = 1200;         // quiet time after typing before a fill step counts
        const DONE_FLASH = 700;          // "✓ Done" dwell before moving on

        return {
            tour: cfg.tour,
            autoStart: cfg.autoStart,
            forced: cfg.forced,
            request: cfg.request,
            csrf: cfg.csrf,
            completedSteps: cfg.completedSteps || [],   // AT-371 (#18) — stable step keys already seen

            menuOpen: false,
            menuStyle: '',

            // Current run.
            _driver: null,
            _mode: 'tour',
            _section: null,
            _steps: [],
            _i: 0,
            _explicit: false,
            _suppressWritten: false,
            _watch: null,        // { timer, listeners[] } for the live step
            _runId: 0,           // bumps on every step change; stale async work checks it
            _engaged: false,     // the agent has acted on the live step (see _arm)
            _btn: null,          // the "?" button — kept directly: once it moves into the
                                 // page header it is outside this component, so $refs can't reach it

            // Alpine calls init() itself — it must NOT also be wired as x-init, or
            // everything here runs twice (a second auto-start overrides a guide
            // the agent asked for).
            init() {
                // Relocate the launcher into the page-header slot if the host page
                // provides one, and reveal it there. With no slot the button stays
                // hidden — it never floats.
                this._btn = this.$refs.launcherBtn;
                this.$nextTick(() => {
                    const slot = document.getElementById('tour-launcher-slot');
                    const wrap = this.$refs.launcher;
                    if (slot && wrap) {
                        slot.appendChild(wrap);
                        wrap.style.display = '';
                    }
                });

                window.CoreXGuide = window.CoreXGuide || {};
                window.CoreXGuide.startHere = (mode, section) => this.start(mode, section, true);

                // Many screens reload the moment the agent acts — a filter select
                // that submits on change, Enter in a search box, Apply after a
                // choice. If that happens mid-step, resume on the NEXT step, not
                // this one again.
                window.addEventListener('pagehide', () => {
                    if (!this._driver || !this._guiding() || !this._engaged) return;
                    if (window.CoreXGuide && window.CoreXGuide.writeActive) {
                        window.CoreXGuide.writeActive({ key: this.tour.key, mode: this._mode, section: this._section, index: this._i + 1 });
                    }
                });

                const G = window.CoreXGuide;
                const later = (fn) => window.setTimeout(fn, 900); // let Alpine, maps and async widgets settle

                // 1. Explicit request in the URL (?guide=… from Ellie / the directory).
                if (this.request) {
                    later(() => this.start(this.request.mode, this.request.section, true));
                    return;
                }
                // 2. A guide the agent asked for on another page, waiting for this one.
                const pending = G.readPending ? G.readPending() : null;
                if (pending && pending.key === this.tour.key) {
                    G.clearPending();
                    later(() => this.start(pending.mode, pending.section, true));
                    return;
                }
                // 3. A guide that was running when this page reloaded (e.g. after Save).
                const active = G.readActive ? G.readActive() : null;
                if (active && active.key === this.tour.key) {
                    later(() => this.start(active.mode, active.section, true, active.index || 0));
                    return;
                }
                // Parked on a pick list for another tour's guide — don't pop this page's tour on top.
                if (pending) return;

                if (this.autoStart) {
                    // A tour's setup can switch tabs/sections to stage its first
                    // spotlight (e.g. outreach-composer clicks the Outreach tab
                    // open). If the user arrived with an explicit ?tab= request —
                    // a deep link, a reload, a toggle that re-navigates with a
                    // query param — that is deliberate navigation. Silently
                    // auto-starting and yanking them onto a different tab a
                    // moment later is worse than not running the tour at all:
                    // it reads as "the page flickers, then the thing I asked
                    // for disappears." Skip the silent auto-start in that case;
                    // a forced start from the Guided Tours directory (?tour=)
                    // or the manual launcher button is unaffected.
                    const params = new URLSearchParams(window.location.search);
                    if (this.forced || !params.has('tab')) {
                        later(() => this.start('tour', null, this.forced));
                    }
                }
            },

            // ── "?" launcher ────────────────────────────────────────────────
            hasMenu() { return !!(this.tour.advanced || this.tour.spot); },

            onLauncher() {
                // Only the explanation tour exists here → keep the one-click behaviour.
                if (!this.hasMenu()) { this.start('tour', null, true); return; }
                if (this.menuOpen) { this.menuOpen = false; return; }
                const r = this._btn.getBoundingClientRect();
                const width = Math.min(280, window.innerWidth - 24);
                const left = Math.max(12, Math.min(r.right - width, window.innerWidth - width - 12));
                this.menuStyle = 'top:' + Math.round(r.bottom + 6) + 'px; left:' + Math.round(left) + 'px;';
                this.menuOpen = true;
            },

            pick(mode, section = null) {
                this.menuOpen = false;
                this.start(mode, section, true);
            },

            // ── Runner ──────────────────────────────────────────────────────
            _stepKey(s) { return (s && (s.key || s.element)) || null; },

            _isVisible(el) {
                if (!el || !el.isConnected) return false;
                const rects = el.getClientRects();
                if (rects.length) {
                    const r = el.getBoundingClientRect();
                    if (r.width > 0 || r.height > 0) return true;
                }
                // display:contents wrappers have no box of their own — look one level in.
                return Array.from(el.children || []).some((c) => {
                    const r = c.getBoundingClientRect();
                    return c.getClientRects().length && (r.width > 0 || r.height > 0);
                });
            },

            _q(sel) { try { return sel ? document.querySelector(sel) : null; } catch (e) { return null; } },
            _qa(sel) { try { return sel ? Array.from(document.querySelectorAll(sel)) : []; } catch (e) { return []; } },
            _visible(sel) { return this._qa(sel).some((el) => this._isVisible(el)); },

            _runActions(actions) {
                (actions || []).forEach((s) => {
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
                        } else if (s.action === 'dispatch' && s.event) {
                            window.dispatchEvent(new CustomEvent(s.event, { detail: s.detail }));
                        }
                    } catch (e) { /* setup is best-effort */ }
                });
            },

            _sleep(ms) { return new Promise((r) => window.setTimeout(r, ms)); },

            async _waitFor(test, ms, runId) {
                const end = Date.now() + ms;
                while (Date.now() < end) {
                    if (runId !== this._runId) return false;
                    if (test()) return true;
                    await this._sleep(120);
                }
                return test();
            },

            _guiding() { return this._mode === 'advanced' || this._mode === 'spot'; },

            /**
             * Start a run. `explicit` = the agent asked for it (menu, directory,
             * Ellie) — an explicit run shows every step, even ones already seen;
             * only the silent auto-start skips seen steps (AT-371).
             */
            start(mode = 'tour', section = null, explicit = false, fromIndex = 0) {
                if (!window.driver || !window.driver.js) {
                    console.warn('[corex-tour] driver.js not loaded');
                    return;
                }
                if (mode !== 'tour' && !this.tour.advanced) mode = 'tour';
                if (mode === 'spot' && !(this.tour.sections || []).includes(section)) mode = 'advanced';

                this.menuOpen = false;
                this._teardown(false);
                this._mode = mode;
                this._section = mode === 'spot' ? section : null;
                this._explicit = explicit;

                const all = this.tour.steps || [];
                const done = this.completedSteps || [];
                this._steps = all.filter((s) => {
                    if (mode === 'tour') {
                        if (s.advanced_only) return false;
                        if (!explicit && done.includes(this._stepKey(s))) return false;
                        return true;
                    }
                    return mode === 'spot' ? s.section === section : true;
                });
                if (!this._steps.length) { console.warn('[corex-tour] no steps to show'); return; }

                // A guide started from Ellie closes her panel so it can't sit on the page.
                window.dispatchEvent(new CustomEvent('corex-guide:started', { detail: { key: this.tour.key, mode } }));

                this._runActions(this.tour.setup);
                document.body.classList.toggle('corex-guide-live', this._guiding());

                const self = this;
                this._driver = window.driver.js.driver({
                    // AT-41 UX fix (Andre): a first-day agent must not lose the tour
                    // by an accidental outside click. allowClose:false disables the
                    // overlay-click close, the ESC close AND the popover X — so the
                    // ONLY ways a run ends are the explicit "Close tour"/"Stop guide"
                    // button (injected below) or completing the last step.
                    allowClose: false,
                    // Arrow keys belong to the form while the agent is typing.
                    allowKeyboardControl: !this._guiding(),
                    animate: true,
                    overlayColor: this._overlayColor(),
                    overlayOpacity: this._guiding() ? 0.35 : 0.7,
                    stagePadding: 6,
                    stageRadius: 8,
                    popoverClass: 'corex-tour',
                    onPopoverRender: (popover) => self._decoratePopover(popover),
                });

                // Resumed past the last step: the agent's final action (a Save, a link)
                // reloaded the page — the job is done, so finish rather than re-show it.
                if (fromIndex >= this._steps.length) { this._finish(true); return; }

                // setup may reveal elements (Alpine x-show) — wait for the DOM to update.
                this.$nextTick(() => window.setTimeout(() => this._show(fromIndex, 1), 60));
            },

            // Theme-aware spotlight overlay: a dark veil reads well over the light
            // UI, but over the dark theme it hides the highlighted boxes — so in
            // dark mode flip to a grey overlay (the highlighted element becomes a
            // dark island on a lighter field).
            _overlayColor() {
                const dark = document.documentElement.classList.contains('dark');
                return dark ? 'rgba(149,151,153,0.96)' : 'rgba(3,6,12,0.93)';
            },

            /** Show step i, walking in `dir` (+1/-1) past steps that can't be shown. */
            async _show(i, dir = 1) {
                this._clearWatch();
                this._engaged = false;
                const runId = ++this._runId;
                const guiding = this._guiding();

                while (i >= 0 && i < this._steps.length) {
                    const step = this._steps[i];
                    this._runActions(step.prep);
                    if (step.prep && step.prep.length) await this._sleep(80);

                    // Already done / not applicable → skip (hands-on only).
                    if (guiding && step.skip_if && this._visible(step.skip_if)) { i += dir; continue; }

                    // A box that exists but is hidden may be about to show (a screen
                    // animating in) — wait for it. One that isn't in the page at all
                    // (role-gated, not applicable) gets a short grace, then is skipped.
                    const started = Date.now();
                    const ok = await this._waitFor(
                        () => this._isVisible(this._q(step.element))
                            || (guiding && !this._q(step.element) && Date.now() - started > ABSENT_WAIT),
                        guiding ? VIS_WAIT_GUIDE : VIS_WAIT_TOUR, runId
                    ) && this._isVisible(this._q(step.element));
                    if (runId !== this._runId) return;
                    if (ok) break;
                    // Never dead-end on a box that isn't on this screen.
                    i += dir;
                }

                if (i < 0) i = 0;
                if (i >= this._steps.length) { this._finish(true); return; }

                this._i = i;
                const step = this._steps[i];
                const el = this._q(step.element);

                if (guiding) {
                    if (window.CoreXGuide && window.CoreXGuide.writeActive) {
                        window.CoreXGuide.writeActive({ key: this.tour.key, mode: this._mode, section: this._section, index: i });
                    }
                } else {
                    this._recordStep(this._stepKey(step));
                }

                this._driver.highlight({
                    element: el,
                    popover: this._popoverFor(step, i),
                });

                if (guiding && step.do) this._arm(step, el, runId);
            },

            _escape(s) {
                return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
            },

            _popoverFor(step, i) {
                const guiding = this._guiding();
                const last = i === this._steps.length - 1;
                const self = this;
                let description = step.body || '';

                if (guiding) {
                    const section = step.section ? '<span class="corex-guide-section">' + this._escape(step.section) + '</span><br>' : '';
                    const say = step.do ? (step.do.say || '') : '';
                    description = section + this._escape(step.body || '')
                        + (say ? '<div class="corex-guide-do" data-guide-do>' + this._escape(say) + '</div>' : '')
                        + '<div class="corex-guide-hint" data-guide-hint style="display:none;"></div>';
                }

                let nextText;
                if (!guiding) nextText = last ? 'Done' : 'Next →';
                else if (!step.do) nextText = last ? 'Finish' : 'Got it →';
                else nextText = 'Skip this step';

                return {
                    title: step.title || '',
                    description,
                    popoverClass: 'corex-tour',
                    showButtons: i > 0 ? ['next', 'previous'] : ['next'],
                    nextBtnText: nextText,
                    prevBtnText: '← Back',
                    showProgress: false,
                    onNextClick: () => self._advance(1),
                    onPrevClick: () => self._advance(-1),
                };
            },

            _advance(dir) {
                const next = this._i + dir;
                if (dir > 0 && next >= this._steps.length) { this._finish(true); return; }
                this._show(Math.max(0, next), dir);
            },

            // ── Hands-on: wait for the agent to DO the step ─────────────────
            _controlIn(el) {
                if (!el) return null;
                if (el.matches && el.matches('input, select, textarea')) return el;
                const visible = Array.from(el.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit]), select, textarea'))
                    .find((c) => this._isVisible(c));
                return visible || el.querySelector('input, select, textarea');
            },

            _valid(c) {
                try { return typeof c.checkValidity === 'function' ? c.checkValidity() : true; } catch (e) { return true; }
            },

            _hint(msg) {
                const h = document.querySelector('.driver-popover [data-guide-hint]');
                if (!h) return;
                h.textContent = msg || '';
                h.style.display = msg ? '' : 'none';
            },

            _arm(step, el, runId) {
                const d = step.do || {};
                const watch = { timer: null, listeners: [] };
                this._watch = watch;
                const on = (target, type, fn, capture = true) => {
                    target.addEventListener(type, fn, capture);
                    watch.listeners.push(() => target.removeEventListener(type, fn, capture));
                };
                const root = () => {
                    const e = this._q(step.element) || el;
                    return d.target ? (e && e.querySelector(d.target)) || e : e;
                };
                let completing = false;
                // navigating: the click is about to reload/leave the page.
                // withUntil:  wait for `until` first (appear steps already watched it).
                const complete = async (navigating = false, withUntil = true) => {
                    if (completing || runId !== this._runId) return;
                    completing = true;
                    this._hint('');
                    if (navigating && window.CoreXGuide && window.CoreXGuide.writeActive) {
                        // The page is about to reload — resume on the NEXT step.
                        window.CoreXGuide.writeActive({ key: this.tour.key, mode: this._mode, section: this._section, index: this._i + 1 });
                    }
                    if (withUntil && d.until) {
                        const shown = await this._waitFor(() => this._visible(d.until), UNTIL_WAIT, runId);
                        if (runId !== this._runId) return;
                        if (!shown) {
                            completing = false;
                            this._hint('Something on the page still needs your attention — check it, then try again.');
                            return;
                        }
                    }
                    const box = document.querySelector('.driver-popover [data-guide-do]');
                    if (box) { box.classList.add('is-done'); box.textContent = 'Done'; }
                    await this._sleep(DONE_FLASH);
                    if (runId !== this._runId) return;
                    this._advance(1);
                };

                if (d.action === 'fill') {
                    const start = this._controlIn(root());
                    const initial = start ? String(start.value ?? '') : '';
                    let lastInput = 0;
                    on(document, 'input', (e) => { const r = root(); if (r && r.contains(e.target)) lastInput = Date.now(); });
                    const check = (force) => {
                        const c = this._controlIn(root());
                        if (!c) return;
                        const v = String(c.value ?? '');
                        if (v.trim() === '' || v === initial || !this._valid(c)) return;
                        this._engaged = true;
                        const settled = force || c.tagName === 'SELECT' || document.activeElement !== c || (Date.now() - lastInput) > TYPE_PAUSE;
                        if (settled) complete();
                    };
                    on(document, 'change', (e) => { const r = root(); if (r && r.contains(e.target)) check(true); });
                    on(document, 'keydown', (e) => { if (e.key === 'Enter') { const r = root(); if (r && r.contains(e.target)) check(true); } });
                    watch.timer = window.setInterval(() => check(false), 300);
                } else if (d.action === 'choose') {
                    on(document, 'change', (e) => { const r = root(); if (r && r.contains(e.target)) { this._engaged = true; complete(); } });
                    on(document, 'click', (e) => {
                        const r = root();
                        const b = e.target.closest && e.target.closest('button, [role=button], [role=radio], [role=option], label');
                        if (r && b && r.contains(b)) { this._engaged = true; window.setTimeout(() => complete(), 250); }
                    });
                } else if (d.action === 'click') {
                    on(document, 'click', (e) => {
                        const r = root();
                        if (!r || !(r === e.target || r.contains(e.target))) return;
                        const btn = e.target.closest ? e.target.closest('button, input[type=submit], a') : null;
                        const form = btn && btn.form ? btn.form : null;
                        const submits = form && (btn.type === 'submit' || (btn.tagName === 'BUTTON' && !btn.getAttribute('type')));
                        if (submits && typeof form.checkValidity === 'function' && !form.checkValidity()) {
                            this._hint('A required box is still empty — fill it in, then click again.');
                            return;
                        }
                        this._engaged = true;
                        const link = btn && btn.tagName === 'A' && btn.getAttribute('href') && !btn.getAttribute('href').startsWith('#') && btn.target !== '_blank';
                        complete(!!(submits || link));
                    });
                } else if (d.action === 'appear' && d.until) {
                    const count = () => this._qa(d.until).filter((x) => this._isVisible(x)).length;
                    const base = count();
                    watch.timer = window.setInterval(() => {
                        const n = count();
                        if (d.more ? n > base : n > 0) complete(false, false);
                    }, 300);
                }

                // Keep the spotlight glued to the box while the page shifts under it.
                const refresh = window.setInterval(() => { try { this._driver && this._driver.refresh(); } catch (e) {} }, 700);
                watch.listeners.push(() => window.clearInterval(refresh));
            },

            _clearWatch() {
                if (!this._watch) return;
                if (this._watch.timer) window.clearInterval(this._watch.timer);
                this._watch.listeners.forEach((off) => { try { off(); } catch (e) {} });
                this._watch = null;
            },

            // ── Ending a run ───────────────────────────────────────────────
            _teardown(record) {
                this._clearWatch();
                this._runId++;
                if (this._driver) {
                    const d = this._driver;
                    this._driver = null;
                    try { d.destroy(); } catch (e) {}
                }
                document.body.classList.remove('corex-guide-live');
                if (record) this._record('seen');
            },

            /** completed = the agent reached the end (vs. closing/stopping early). */
            _finish(completed) {
                const guiding = this._guiding();
                const mode = this._mode;
                if (guiding && window.CoreXGuide && window.CoreXGuide.writeActive) window.CoreXGuide.writeActive(null);
                // Closing or finishing the explanation tour marks it seen (won't nag
                // on next login). A full Advanced Guide covers the tour too; a single
                // Spot Help section doesn't.
                this._teardown(mode === 'tour' || mode === 'advanced');
                if (guiding && completed) {
                    window.dispatchEvent(new CustomEvent('toast', { detail: {
                        message: mode === 'spot' ? 'Spot Help done — nice work.' : 'Guide complete — nice work.',
                        type: 'success',
                    } }));
                }
            },

            _decoratePopover(popover) {
                try {
                    if (!popover || !popover.footer) return;
                    const self = this;
                    const guiding = this._guiding();

                    // A click on the guide's own card is never a click on the page: an open
                    // form or menu that closes on "click outside" (Alpine @click.outside)
                    // must survive the agent pressing Skip or Back.
                    if (popover.wrapper && !popover.wrapper._corexIsolated) {
                        popover.wrapper._corexIsolated = true;
                        ['click', 'mousedown', 'pointerdown'].forEach((t) => popover.wrapper.addEventListener(t, (e) => e.stopPropagation()));
                    }

                    // Hands-on "do" steps: Next is a quiet escape hatch, not the main action.
                    // A box that's already correctly filled (editing an existing record)
                    // offers to move on instead of waiting for a change that won't come.
                    const step = this._steps[this._i] || {};
                    if (guiding && step.do && popover.nextButton) {
                        popover.nextButton.classList.add('corex-guide-skip');
                        if (step.do.action === 'fill') {
                            const r = this._q(step.element);
                            const c = this._controlIn(r && step.do.target ? (r.querySelector(step.do.target) || r) : r);
                            if (c && String(c.value ?? '').trim() !== '' && this._valid(c)) {
                                popover.nextButton.textContent = 'Looks good — next →';
                                popover.nextButton.classList.remove('corex-guide-skip');
                            }
                        }
                    }

                    // Progress — "Step 3 of 9" (driver's own counter needs a step list).
                    if (popover.progress) {
                        popover.progress.textContent = 'Step ' + (this._i + 1) + ' of ' + this._steps.length;
                        popover.progress.style.display = 'block';
                    }

                    // (3) Explicit "Close tour" / "Stop guide" button — on EVERY step.
                    //     The only manual way to end a run now that the overlay/X/ESC
                    //     are disabled. Stopping leaves everything the agent typed as is.
                    if (!popover.footer.querySelector('[data-tour-close]')) {
                        const closeBtn = document.createElement('button');
                        closeBtn.type = 'button';
                        closeBtn.setAttribute('data-tour-close', '');
                        closeBtn.className = 'corex-tour-close';
                        closeBtn.textContent = guiding ? 'Stop guide' : 'Close tour';
                        closeBtn.addEventListener('click', () => self._finish(false));
                        // Sits at the start of the footer, left of Back/Next.
                        popover.footer.insertBefore(closeBtn, popover.footer.firstChild);
                    }

                    // (2) "Don't show again" — first step of the explanation tour only.
                    //     Ticking it records the suppress preference (dismissed_at) so
                    //     the tour won't AUTO-launch next session — but it does NOT end
                    //     the current tour. The agent keeps stepping through.
                    if (!guiding && this._i === 0 && !popover.footer.querySelector('[data-tour-dsa]')) {
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

            // AT-371 (#18) — persist ONE seen step (by its stable key). Optimistic local update so a
            // page/section update mid-tour doesn't re-show it; the POST is best-effort (the server is
            // the source of truth on next load). Idempotent both sides.
            _recordStep(key) {
                if (!key || (this.completedSteps || []).includes(key)) return;
                this.completedSteps.push(key);
                const url = '/api/v1/tours/' + encodeURIComponent(this.tour.key) + '/step';
                try {
                    fetch(url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': this.csrf,
                        },
                        body: JSON.stringify({ step: key }),
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
