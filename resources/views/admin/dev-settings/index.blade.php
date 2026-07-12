@extends('layouts.corex')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20

     Hub shell mirrors /corex/settings (resources/views/corex/settings.blade.php):
     dark page header, searchable left rail of grouped sections, right pane whose
     panes are toggled by activeSection. Same tokens, same rail markup, same
     ?s=<section> deep-link contract.

     One <form> wraps the whole right pane on purpose: the update() endpoint
     writes BOTH dev keys on every submit (an absent checkbox is coerced to 0 by
     its hidden sibling). Panes are hidden with x-show, not removed from the DOM,
     so a save from the Demo pane still carries the Compliance value and vice
     versa — splitting this into per-pane forms would silently reset the pane you
     were not looking at. --}}

@section('corex-content')
<div class="w-full space-y-5"
     x-data="devSettingsHub('{{ $activeSection }}')"
     x-init="$watch('activeSection', v => { const u = new URL(window.location); u.searchParams.set('s', v); window.history.replaceState({}, '', u); })">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Dev Settings</h1>
                <p class="text-sm text-white/60">System-wide developer overrides. Use with care — these affect production behaviour.</p>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif
    @if(session('warning') || $errors->has('demo_toggle_password'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); color: var(--text-primary);">
            {{ $errors->first('demo_toggle_password') ?: session('warning') }}
        </div>
    @endif

    {{-- Hub shell: left rail + right pane --}}
    @php
        $railGroups = [
            [
                'label' => 'Overrides',
                'items' => [
                    ['key'=>'compliance', 'label'=>'Compliance Overrides', 'type'=>'section', 'keywords'=>'property compliance checks gates mandate fica photos marketing readiness syndication bulk import'],
                ],
            ],
            [
                'label' => 'Demo',
                'items' => [
                    ['key'=>'demo', 'label'=>'Demo Mode', 'type'=>'section', 'keywords'=>'login bypass role buttons admin branch manager agent viewer password authentication'],
                    ['key'=>'demo-sidebar', 'label'=>'Demo Sidebar', 'type'=>'link', 'href'=>route('admin.dev-settings.demo-sidebar'), 'keywords'=>'navigation curation hide items sub-pages demo agency'],
                ],
            ],
        ];
    @endphp

    <div class="flex flex-col lg:flex-row gap-5">

        {{-- Left rail --}}
        <aside class="w-full lg:w-72 flex-shrink-0">
            <div class="rounded-md sticky top-4" style="background:var(--surface); border:1px solid var(--border);">
                <div class="p-3" style="border-bottom:1px solid var(--border);">
                    <div class="relative">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" class="w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2" style="color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                        <input type="text" x-model="search" placeholder="Search dev settings…"
                               class="w-full rounded-md pl-8 pr-3 py-2 text-sm outline-none"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                </div>
                <nav class="p-2 max-h-[70vh] overflow-y-auto" aria-label="Dev settings sections">
                    @foreach($railGroups as $group)
                        <div class="mt-2 first:mt-0" x-show="anyVisible(@js($group['items']))">
                            <div class="px-2 pt-2 pb-1 text-[10px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">
                                {{ $group['label'] }}
                            </div>
                            @foreach($group['items'] as $item)
                                @php
                                    $matchExpr = "matchesSearch(" . json_encode(strtolower($item['label'] . ' ' . ($item['keywords'] ?? ''))) . ")";
                                @endphp
                                @if($item['type'] === 'section')
                                    <button type="button"
                                            @click="activeSection = '{{ $item['key'] }}'; $nextTick(() => window.scrollTo({top:0, behavior:'smooth'}))"
                                            x-show="{{ $matchExpr }}"
                                            :class="activeSection === '{{ $item['key'] }}' ? 'font-semibold' : ''"
                                            :style="activeSection === '{{ $item['key'] }}' ? 'background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color:var(--brand-icon, #0ea5e9);' : 'color:var(--text-secondary);'"
                                            class="w-full text-left px-3 py-2 rounded-md text-sm transition-colors duration-150 hover:bg-white/5 outline-none focus:outline-none">
                                        {{ $item['label'] }}
                                    </button>
                                @else
                                    <a href="{{ $item['href'] }}"
                                       x-show="{{ $matchExpr }}"
                                       class="flex items-center justify-between gap-2 px-3 py-2 rounded-md text-sm no-underline transition-colors duration-150 hover:bg-white/5"
                                       style="color:var(--text-secondary);">
                                        <span>{{ $item['label'] }}</span>
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" class="w-3.5 h-3.5 flex-shrink-0" style="color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    @endforeach
                </nav>
            </div>
        </aside>

        {{-- Right pane --}}
        <div class="flex-1 min-w-0" style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <form method="POST" action="{{ route('admin.dev-settings.update') }}"
                  x-data="{ demoInit: {{ $demoModeEnabled ? 'true' : 'false' }}, demoNow: {{ $demoModeEnabled ? 'true' : 'false' }} }">
                @csrf
                @method('PUT')

                {{-- ============================================================
                     COMPLIANCE OVERRIDES
                     ============================================================ --}}
                <div x-show="activeSection === 'compliance'" x-cloak class="p-6 space-y-6">
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Property Compliance</h3>

                        <div class="flex items-start justify-between gap-6 p-4 rounded-md"
                             style="background:var(--surface-2); border:1px solid var(--border);">
                            <div class="flex-1">
                                <label for="compliance_checks_disabled" class="block text-sm font-semibold" style="color: var(--text-primary);">
                                    Disable property compliance checks
                                </label>
                                <p class="text-sm mt-1" style="color: var(--text-secondary);">
                                    When enabled, properties bypass the marketing readiness gates (mandate / FICA / photos / details)
                                    and can be uploaded and syndicated without compliance being completed.
                                </p>
                                <p class="text-xs mt-2" style="color: var(--ds-amber, #f59e0b);">
                                    Warning: this is a global override intended for development and bulk imports. Disable as soon as you are done.
                                </p>
                            </div>
                            <div class="pt-1">
                                <label class="inline-flex items-center cursor-pointer">
                                    <input type="hidden" name="compliance_checks_disabled" value="0">
                                    <input type="checkbox"
                                           id="compliance_checks_disabled"
                                           name="compliance_checks_disabled"
                                           value="1"
                                           {{ $complianceChecksDisabled ? 'checked' : '' }}
                                           class="w-5 h-5 rounded" style="accent-color: var(--brand-button, #0ea5e9);">
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end pt-4" style="border-top: 1px solid var(--border);">
                        <button type="submit" class="corex-btn-primary">Save Settings</button>
                    </div>
                </div>

                {{-- ============================================================
                     DEMO MODE
                     ============================================================ --}}
                <div x-show="activeSection === 'demo'" x-cloak class="p-6 space-y-6">
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Demo Mode</h3>

                        <div class="flex items-start justify-between gap-6 p-4 rounded-md"
                             style="background:var(--surface-2); border:1px solid var(--border);">
                            <div class="flex-1">
                                <label for="demo_mode_enabled" class="block text-sm font-semibold" style="color: var(--text-primary);">
                                    Enable demo mode (bypass login)
                                </label>
                                <p class="text-sm mt-1" style="color: var(--text-secondary);">
                                    When enabled, the login screen is replaced with four role buttons (Admin, Branch Manager, Agent, Viewer).
                                    Clicking a button signs the visitor in as a demo user with that role — no password required.
                                    For use on demo.corexos.co.za only.
                                </p>
                                <p class="text-xs mt-2" style="color: var(--ds-crimson, #c41e3a);">
                                    DANGER: This is an authentication bypass. It is hard-disabled on production (APP_ENV=production) regardless of this toggle.
                                    @if($isProduction)
                                        <strong>This server is running APP_ENV=production — the toggle has no effect here.</strong>
                                    @endif
                                </p>
                            </div>
                            <div class="pt-1">
                                <label class="inline-flex items-center cursor-pointer">
                                    <input type="hidden" name="demo_mode_enabled" value="0">
                                    <input type="checkbox"
                                           id="demo_mode_enabled"
                                           name="demo_mode_enabled"
                                           value="1"
                                           x-model="demoNow"
                                           class="w-5 h-5 rounded" style="accent-color: var(--brand-button, #0ea5e9);">
                                </label>
                            </div>
                        </div>

                        {{-- Password gate — revealed only when the demo-mode toggle is being
                             changed (on OR off). Verified server-side in update(). --}}
                        <div x-show="demoNow !== demoInit" x-cloak class="mt-4 p-4 rounded-md"
                             style="background:var(--surface-2); border:1px solid var(--border);">
                            <label for="demo_toggle_password" class="block text-sm font-semibold" style="color: var(--text-primary);">
                                Confirm password to change demo mode
                            </label>
                            <p class="text-sm mt-1" style="color: var(--text-secondary);">
                                Turning demo mode on or off requires the demo control password.
                            </p>
                            <input type="password"
                                   id="demo_toggle_password"
                                   name="demo_toggle_password"
                                   autocomplete="off"
                                   placeholder="Demo control password"
                                   class="mt-2 w-full max-w-sm rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid {{ $errors->has('demo_toggle_password') ? 'var(--ds-crimson, #c41e3a)' : 'var(--border)' }}; color: var(--text-primary);">
                            @error('demo_toggle_password')
                                <p class="text-xs mt-1" style="color: var(--ds-crimson, #c41e3a);">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    {{-- Demo sidebar curation lives on its own page. Also in the rail.
                         See .ai/specs/demo-sidebar-curation.md --}}
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Demo Presentation</h3>
                        <a href="{{ route('admin.dev-settings.demo-sidebar') }}"
                           class="flex items-center gap-3 p-3 rounded-md transition-all duration-300 no-underline group hover:bg-white/5"
                           style="border:1px solid var(--border);">
                            <div class="w-9 h-9 rounded-md flex items-center justify-center flex-shrink-0" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent);">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color: var(--brand-icon, #0ea5e9);" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
                            </div>
                            <div class="flex-1">
                                <div class="text-sm font-semibold" style="color:var(--text-primary);">Demo sidebar</div>
                                <div class="text-xs" style="color:var(--text-secondary);">Choose which sidebar items and sub-pages a demo agency shows. Affects demo-agency members only — System Owners and real users always see the full sidebar.</div>
                            </div>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" class="w-4 h-4 flex-shrink-0" style="color:var(--border-hover);"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                        </a>
                    </div>

                    <div class="flex justify-end pt-4" style="border-top: 1px solid var(--border);">
                        <button type="submit" class="corex-btn-primary">Save Settings</button>
                    </div>
                </div>

            </form>
        </div>{{-- /right pane --}}
    </div>{{-- /hub flex --}}

</div>

<script>
function devSettingsHub(initial) {
    return {
        activeSection: initial || 'compliance',
        search: '',
        matchesSearch(haystack) {
            const q = (this.search || '').trim().toLowerCase();
            if (!q) return true;
            return (haystack || '').indexOf(q) !== -1;
        },
        anyVisible(items) {
            if (!items || !items.length) return false;
            const q = (this.search || '').trim().toLowerCase();
            if (!q) return true;
            return items.some(it => {
                const hay = ((it.label || '') + ' ' + (it.keywords || '')).toLowerCase();
                return hay.indexOf(q) !== -1;
            });
        },
    };
}
</script>
@endsection
