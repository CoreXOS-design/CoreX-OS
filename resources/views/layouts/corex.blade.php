<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="corex-auth" content="{{ auth()->check() ? '1' : '0' }}">

        {{-- AT-251 — non-production tab-title prefix ([QA]/[STAGING]); live sets no label → clean. --}}
        <title>{{ (filled(config('app.env_label')) ? '['.config('app.env_label').'] ' : '') . config('app.name', 'Laravel') }}</title>

        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon.png') }}?v=4">
        <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=4">
        <link rel="shortcut icon" href="{{ asset('favicon.ico') }}?v=4">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

        <!-- Theme init: apply dark class before paint to prevent flash -->
        <script>
            (function(){
                var authed = {{ auth()->check() ? 'true' : 'false' }};
                var dbTheme = '{{ auth()->check() ? (auth()->user()->theme ?? 'dark') : 'dark' }}';
                // When authenticated, the user's DB record is authoritative — never let a
                // previous user's localStorage value bleed into this session.
                var theme = authed ? dbTheme : (localStorage.getItem('corex-theme') || dbTheme);
                if(theme === 'dark'){
                    document.documentElement.classList.add('dark');
                }
                localStorage.setItem('corex-theme', theme);
            })();
        </script>

        <!-- x-cloak: inline so it works before Vite CSS loads -->
        <style>[x-cloak] { display: none !important; }</style>
        <!-- html2canvas-pro: modern CSS color function support (color(), oklch(), color-mix()) -->
        <script src="https://cdn.jsdelivr.net/npm/html2canvas-pro@1.5.8/dist/html2canvas-pro.min.js" defer></script>
        <!-- Scripts & Styles (Alpine.js bundled via Vite — no external CDN) -->
        @vite(['resources/css/app.css', 'resources/css/corex.css', 'resources/js/app.js'])
        <link rel="stylesheet" href="/css/paye-fix.css">

        @stack('head')

        {{-- Agency brand colours — MUST be last in <head> to override any bundled CSS --}}
        @auth
        @php
            $_agencyId = auth()->user()?->effectiveAgencyId();
            $_agency   = $_agencyId ? \App\Models\Agency::find($_agencyId) : \App\Models\Agency::first();
        @endphp
        <!-- DEBUG: agencyId={{ $_agencyId ?? 'NULL' }} agencyName={{ $_agency->name ?? 'NONE' }} sidebar={{ $_agency->sidebar_color ?? 'NULL' }} icon={{ $_agency->icon_color ?? 'NULL' }} default={{ $_agency->default_color ?? 'NULL' }} button={{ $_agency->button_color ?? 'NULL' }} -->
        @if($_agency)
        <style id="agency-brand">
            :root,
            :root[data-theme="dark"],
            :root[data-theme="light"],
            html,
            html.dark {
                --brand-sidebar: {{ $_agency->sidebar_color ?? '#0ea5e9' }} !important;
                --brand-icon:    {{ $_agency->icon_color    ?? '#0ea5e9' }} !important;
                --brand-default: {{ $_agency->default_color ?? '#0b2a4a' }} !important;
                --brand-button:  {{ $_agency->button_color  ?? '#0ea5e9' }} !important;
            }
        </style>
        @endif
        @endauth
    </head>
    <body class="font-sans antialiased corex-ui-v2">
        {{-- AT-230 — per-company demo watermark + page-view beacon. Renders
             NOTHING unless this is a demo instance with a resolved grant.
             This layout backs ~231 views — MORE than corex-app.blade.php. Leaving
             it out here would leave the majority of CoreX unmarked.
             Spec: .ai/specs/demo-access-control.md §6.5 --}}
        @include('partials._demo-watermark')

        {{-- Environment column: thin env banner (or nothing on live) above the
             full app. Banner is flex:0 0 auto; the app fills the rest — content
             is pushed down, never overlapped. Empty banner = single flex child
             = exactly 100vh = identical to before (zero layout shift on live). --}}
        <div class="h-screen flex flex-col overflow-hidden">
        @include('partials._env-banner')
        <x-outbound-mail-banner />
        {{-- Mobile sidebar toggle.

             Hover-fold, 2026-09-11 — Johan, rental-applications mark-up
             screen: "make BOTH the CoreX app sidebar and the Documents
             column fold away to a thin strip, and reveal on hover... same
             ~560px handed back to the document, far less restructuring,
             and it is reversible." `markupModeActive` is a plain, inert-
             by-default flag: no page dispatches `rental-markup-view-
             toggled` except the rental-applications review screen, so
             every other screen's sidebar is 100% unchanged. Deliberately
             NOT a genuine "shrink to a 44px icon strip" — corex-
             sidebar.blade.php is a large, business-critical nav partial
             (agency switcher, branch switcher, impersonation, admin
             multi-branch manager) never audited for how it degrades at
             44px, and hand-clipping it under time pressure risked shipping
             something cosmetically broken. Reuses the EXISTING mobile
             off-canvas mechanism instead (already exactly "hidden by
             default, slides in as a z-50 overlay, closes on
             mouseleave/click-away") at desktop widths too, when
             markupModeActive — same aside, same markup, zero changes to
             corex-sidebar.blade.php's own content. Pin/hover/drag-
             suppression logic lives here since it also drives the reveal-
             zone strip and pin button below, both new to this file only. --}}
        <div x-data="{
                sidebarOpen: false,
                markupModeActive: false,
                markupSidebarPinned: false,
                revealTimer: null,
                closeTimer: null,
                // Rule 3b (Johan): suppressed while the mouse is down,
                // re-enable on mouseup — see review.blade.php's matching
                // isOverDocsPanel comment for why mousedown-suppression
                // alone isn't enough; the mouseup listener below re-runs
                // the enter check the instant the button lifts, for a
                // cursor that never actually left this zone.
                isOverSidebarZone: false,
                initHoverFold() {
                    try { this.markupSidebarPinned = localStorage.getItem('rentalMarkupSidebarPinned') === '1'; } catch (_) {}
                    // Shared with the Documents-column fold panel inside the
                    // rental review screen (review.blade.php) — tracked once,
                    // globally, so a drag started on the document also
                    // suppresses THIS panel's reveal, and vice versa.
                    if (!window.__rentalMouseDownTracked) {
                        window.__rentalMouseDownTracked = true;
                        window.__rentalMouseDown = false;
                        window.addEventListener('mousedown', () => { window.__rentalMouseDown = true; });
                        window.addEventListener('mouseup', () => { window.__rentalMouseDown = false; });
                    }
                    window.addEventListener('mouseup', () => { if (this.isOverSidebarZone) this.onSidebarZoneEnter(); });
                },
                onSidebarZoneEnter() {
                    this.isOverSidebarZone = true;
                    if (!this.markupModeActive || this.markupSidebarPinned || window.__rentalMouseDown) return;
                    clearTimeout(this.closeTimer);
                    this.revealTimer = setTimeout(() => { if (!window.__rentalMouseDown) this.sidebarOpen = true; }, 180);
                },
                onSidebarZoneLeave() {
                    this.isOverSidebarZone = false;
                    clearTimeout(this.revealTimer);
                    if (!this.markupModeActive || this.markupSidebarPinned) return;
                    this.closeTimer = setTimeout(() => { this.sidebarOpen = false; }, 250);
                },
                toggleSidebarPin() {
                    this.markupSidebarPinned = !this.markupSidebarPinned;
                    try { localStorage.setItem('rentalMarkupSidebarPinned', this.markupSidebarPinned ? '1' : '0'); } catch (_) {}
                    if (this.markupSidebarPinned) this.sidebarOpen = true;
                },
             }"
             x-init="initHoverFold()"
             @rental-markup-view-toggled.window="markupModeActive = $event.detail.open; if (!$event.detail.open) sidebarOpen = false"
             class="flex flex-1 min-h-0 overflow-hidden" style="background:var(--bg)">

            {{-- Mobile overlay — unchanged for the plain mobile case (dark,
                 click anywhere to close). Skipped entirely in markup-fold
                 mode: a dark 50%-opacity scrim over the document would
                 defeat the entire point of reclaiming the space — closing
                 there is mouseleave (onSidebarZoneLeave, above) or the pin
                 toggle, never a click-away backdrop. --}}
            <div x-show="sidebarOpen && !markupModeActive" x-transition:enter="transition-opacity ease-linear duration-200"
                 x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition-opacity ease-linear duration-200"
                 x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 @click="sidebarOpen = false"
                 class="fixed inset-0 bg-black/50 z-40 lg:hidden" x-cloak></div>

            {{-- Reveal zone — a slim, invisible hover target pinned to the
                 left edge. Only present in markup-fold mode; the sidebar's
                 own normal lg:flex-shrink-0 behaviour is completely
                 untouched otherwise. --}}
            <div x-show="markupModeActive && !sidebarOpen" x-cloak
                 @mouseenter="onSidebarZoneEnter()" @mouseleave="onSidebarZoneLeave()"
                 class="hidden lg:block fixed inset-y-0 left-0 z-40" style="width: 10px;"></div>

            {{-- Sidebar — fixed 240px (w-60) to match layouts.corex-app. NOTE: an
                 element may only carry ONE :class attribute; a second is dropped by
                 the HTML parser. The previous markup had two, so the width binding
                 (lg:w-60) was silently discarded and the sidebar rendered at content
                 width (wider). Width is now in the static class so it always applies.

                 Hover-fold — lg:relative/lg:flex-shrink-0/lg:translate-x-0 (which
                 force the sidebar to always occupy real flex space and stay
                 visible at desktop widths) are now CONDITIONAL on
                 markupModeActive: present exactly as before for every other
                 screen; omitted while marking up (unless pinned), so the
                 EXISTING mobile off-canvas behaviour — fixed position, zero
                 reserved space, transform-controlled visibility — applies at
                 desktop widths too. Nothing here changes corex-sidebar's own
                 markup or CSS. --}}
            <aside :class="[
                       sidebarOpen ? 'translate-x-0' : '-translate-x-full',
                       (markupModeActive && !markupSidebarPinned) ? '' : 'lg:relative lg:translate-x-0 lg:flex-shrink-0',
                   ]"
                   @mouseenter="markupModeActive ? onSidebarZoneEnter() : null" @mouseleave="onSidebarZoneLeave()"
                   class="fixed inset-y-0 left-0 z-50 w-60 transform transition-transform duration-200 ease-in-out">
                @include('layouts.corex-sidebar')
            </aside>

            {{-- Pin — the one control Johan asked for explicitly ("give
                 each panel a PIN control... remember their choice for the
                 session"). Floats over the sidebar's own top-right corner
                 rather than living inside corex-sidebar.blade.php, for the
                 same "don't touch that partial" reason as above. --}}
            <button type="button" x-show="markupModeActive && sidebarOpen" x-cloak
                    @click="toggleSidebarPin()"
                    class="hidden lg:flex fixed z-[60] items-center justify-center rounded-md"
                    style="left: 220px; top: 8px; width: 22px; height: 22px; background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);"
                    :title="markupSidebarPinned ? 'Unpin sidebar — back to hover-to-reveal' : 'Pin sidebar open'">
                <span x-text="markupSidebarPinned ? '📌' : '📍'" style="font-size: 11px;"></span>
            </button>

            {{-- Main area --}}
            <div class="flex-1 flex flex-col overflow-hidden min-w-0">
                {{-- Mobile header --}}
                <div class="corex-mobile-bar flex items-center lg:hidden px-4 py-2">
                    <button @click="sidebarOpen = true" type="button" style="color:var(--text-secondary)">
                        <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                    </button>
                    <span class="ml-3 text-sm font-bold" style="color:var(--text-primary)">CoreX <span style="color:var(--accent)">Os</span></span>
                </div>

                {{-- Content --}}
                <main id="appScroll" class="flex-1 overflow-y-auto p-4 lg:p-6" style="background:var(--bg)">
                    @hasSection('corex-content')
                        @yield('corex-content')
                    @else
                        {{-- Agency Tracker / legacy views: header slot + hfc-card wrapper --}}
                        @isset($header)
                            <div class="mb-4">
                                {{ $header }}
                            </div>
                        @endisset
                        @hasSection('content')
                            <div class="hfc-card p-4 sm:p-6">
                                @yield('content')
                            </div>
                        @else
                            <div class="hfc-card p-4 sm:p-6">
                                {{ $slot ?? '' }}
                            </div>
                        @endif
                    @endif
                </main>
            </div>
        </div>
        </div>{{-- /environment column --}}

        {{-- Global toast notifications --}}
        <x-toast-notifications />

        {{-- Combined Help Widget (Ellie + Feedback) — header-mounted panel --}}
        @auth
            @include('layouts.partials.help-widget')
        @endauth

        {{-- Interactive help-tour engine (driver.js) — renders only on pages
             with a registered tour. See App\Support\Tours\TourRegistry. --}}
        @include('layouts.partials.tour-engine')

        {{-- System Updates — the "what's new in CoreX" pop-up. Emits nothing (and
             issues zero DB queries) when the user has nothing pending.
             Spec: .ai/specs/system-updates.md --}}
        @include('layouts.partials.system-update-modal')

        {{-- Welcome pop-up — new agency Admin's first successful login.
             Spec: .ai/specs/agency-admin-rule.md §R1b --}}
        @include('layouts.partials.welcome-onboarding-modal')

        {{-- Portal Leads real-time toast (P24 + PP). Spec: .ai/specs/portal-leads.md --}}
        @include('components.portal-lead-toast')
        @include('components.reminder-toast')

        {{-- AT-220 — global session armour + persistent connection indicator on
             every long-lived authenticated screen (spec: .ai/specs/session-armour.md). --}}
        @include('layouts.partials._session-guard')

        {{-- Global container-scroll preserve/restore across full-page reloads. --}}
        @include('layouts._scroll-restore')

        @stack('scripts')
    </body>
</html>

