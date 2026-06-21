@extends('layouts.corex')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@section('corex-content')
<div class="w-full space-y-5">

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
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color: var(--ds-green, #059669);">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <div class="flex-1">{{ session('success') }}</div>
        </div>
    @endif

    @if(session('warning') || $errors->has('demo_toggle_password'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color: var(--ds-amber, #f59e0b);">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
            </svg>
            <div class="flex-1">{{ $errors->first('demo_toggle_password') ?: session('warning') }}</div>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.dev-settings.update') }}"
          class="rounded-md p-6 space-y-6"
          style="background: var(--surface); border: 1px solid var(--border);"
          x-data="{ demoInit: {{ $demoModeEnabled ? 'true' : 'false' }}, demoNow: {{ $demoModeEnabled ? 'true' : 'false' }} }">
        @csrf
        @method('PUT')

        <div class="flex items-start justify-between gap-6">
            <div class="flex-1">
                <label for="compliance_checks_disabled" class="block font-semibold" style="color: var(--text-primary);">
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

        <div class="flex items-start justify-between gap-6 pt-6" style="border-top: 1px solid var(--border);">
            <div class="flex-1">
                <label for="demo_mode_enabled" class="block font-semibold" style="color: var(--text-primary);">
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
        <div x-show="demoNow !== demoInit" x-cloak class="pt-4" style="border-top: 1px solid var(--border);">
            <label for="demo_toggle_password" class="block font-semibold" style="color: var(--text-primary);">
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
                   style="background: var(--surface-2); border: 1px solid {{ $errors->has('demo_toggle_password') ? 'var(--ds-crimson, #c41e3a)' : 'var(--border)' }}; color: var(--text-primary);">
            @error('demo_toggle_password')
                <p class="text-xs mt-1" style="color: var(--ds-crimson, #c41e3a);">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex justify-end pt-4" style="border-top: 1px solid var(--border);">
            <button type="submit" class="corex-btn-primary">Save Settings</button>
        </div>
    </form>

    {{-- ═══════════════════════════════════════════════════════════════
         DEMO SIDEBAR CURATION
         Hide sidebar items / sub-pages for demo-agency members only.
         The checklist is built client-side from the live sidebar
         (window.CorexNavSearch) so it always mirrors what renders —
         no hand-maintained registry. Checked = hidden.
         See .ai/specs/demo-sidebar-curation.md
         ═══════════════════════════════════════════════════════════════ --}}
    <form method="POST" action="{{ route('admin.dev-settings.demo-sidebar') }}"
          class="rounded-md p-6 space-y-5"
          style="background: var(--surface); border: 1px solid var(--border);"
          x-data="demoSidebarCurator(@js($demoHiddenNav))" x-init="init()">
        @csrf
        @method('PUT')

        <div>
            <h2 class="font-semibold" style="color: var(--text-primary);">Demo sidebar</h2>
            <p class="text-sm mt-1" style="color: var(--text-secondary);">
                Choose which sidebar items and sub-pages a <strong>demo agency</strong> shows. Ticked items are
                <strong>hidden</strong> from users whose agency is flagged as a demo agency. System Owners and all
                real users always see the full sidebar — this never affects production accounts.
            </p>
            <p class="text-xs mt-2" style="color: var(--text-muted);">
                The list is read live from your own sidebar, so it always matches what renders. Hiding a whole
                section also hides its sub-pages; hiding every sub-page collapses the section.
            </p>
        </div>

        <div class="flex items-center gap-3 text-xs">
            <button type="button" @click="setAll(true)"
                    class="px-3 py-1.5 rounded-md" style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">Hide all</button>
            <button type="button" @click="setAll(false)"
                    class="px-3 py-1.5 rounded-md" style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">Show all</button>
            <span class="ml-auto" style="color: var(--text-muted);"><span x-text="hiddenCount()"></span> hidden</span>
        </div>

        {{-- JS-rendered checklist --}}
        <div id="demo-sidebar-list" class="space-y-4">
            <template x-if="!ready">
                <p class="text-sm" style="color: var(--text-muted);">Reading sidebar…</p>
            </template>

            {{-- Standalone top-level pages --}}
            <template x-if="ready && standalone.length">
                <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                    <div class="text-[0.6875rem] font-semibold uppercase tracking-wider mb-2" style="color: var(--text-muted);">Pages</div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                        <template x-for="entry in standalone" :key="entry.navKey">
                            <label class="flex items-center gap-2 cursor-pointer text-sm py-0.5" style="color: var(--text-primary);">
                                <input type="checkbox" name="keys[]" :value="entry.navKey"
                                       x-model="hidden[entry.navKey]"
                                       class="h-4 w-4 rounded" style="accent-color: var(--brand-button, #0ea5e9);">
                                <span x-text="entry.label"></span>
                            </label>
                        </template>
                    </div>
                </div>
            </template>

            {{-- Expandable sections + their sub-pages --}}
            <template x-for="section in sections" :key="section.navKey">
                <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                    <label class="flex items-center gap-2 cursor-pointer font-semibold text-sm" style="color: var(--text-primary);">
                        <input type="checkbox" name="keys[]" :value="section.navKey"
                               x-model="hidden[section.navKey]"
                               class="h-4 w-4 rounded" style="accent-color: var(--brand-button, #0ea5e9);">
                        <span x-text="section.label"></span>
                        <span class="text-[0.6875rem] font-normal" style="color: var(--text-muted);">(whole section)</span>
                    </label>
                    <div class="mt-2 pl-6 grid grid-cols-1 sm:grid-cols-2 gap-1.5" x-show="section.children.length">
                        <template x-for="child in section.children" :key="child.navKey">
                            <label class="flex items-center gap-2 cursor-pointer text-sm py-0.5"
                                   :style="hidden[section.navKey] ? 'color: var(--text-muted); opacity:0.55;' : 'color: var(--text-secondary);'">
                                <input type="checkbox" name="keys[]" :value="child.navKey"
                                       x-model="hidden[child.navKey]" :disabled="hidden[section.navKey]"
                                       class="h-4 w-4 rounded" style="accent-color: var(--brand-button, #0ea5e9);">
                                <span x-text="child.label"></span>
                            </label>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        <div class="flex justify-end pt-4" style="border-top: 1px solid var(--border);">
            <button type="submit" class="corex-btn-primary">Save Demo Sidebar</button>
        </div>
    </form>

</div>

<script>
function demoSidebarCurator(savedKeys) {
    return {
        ready: false,
        hidden: {},          // navKey -> bool (true = hidden/checked)
        standalone: [],      // top-level page links
        sections: [],        // { navKey, label, children: [{navKey,label}] }

        init() {
            (savedKeys || []).forEach(k => { this.hidden[k] = true; });
            const run = () => this.build();
            if (window.CorexNavSearch) run();
            else if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
            else run();
        },

        navKeyFor(entry) {
            if (entry.group) return 'g:' + entry.group;
            if (entry.href) {
                try { return 'p:' + new URL(entry.href, location.origin).pathname; }
                catch (e) { return null; }
            }
            return null;
        },

        build() {
            if (!window.CorexNavSearch) return;
            const entries = window.CorexNavSearch.build();
            const sectionMap = {};   // label -> section object
            const standalone = [];
            const sections = [];

            // First pass — expandable group toggles become sections.
            entries.forEach(e => {
                if (!e.group) return;
                const navKey = this.navKeyFor(e);
                if (!navKey) return;
                const sec = { navKey, label: e.label, children: [] };
                sectionMap[e.label] = sec;
                sections.push(sec);
                if (!(navKey in this.hidden)) this.hidden[navKey] = false;
            });

            // Second pass — links: sub-items nest under their parent section,
            // parent-less links are standalone pages.
            entries.forEach(e => {
                if (e.group) return;
                const navKey = this.navKeyFor(e);
                if (!navKey) return;
                if (!(navKey in this.hidden)) this.hidden[navKey] = false;
                const target = { navKey, label: e.label };
                if (e.parent && sectionMap[e.parent]) {
                    if (!sectionMap[e.parent].children.some(c => c.navKey === navKey && c.label === e.label)) {
                        sectionMap[e.parent].children.push(target);
                    }
                } else if (!e.parent) {
                    if (!standalone.some(s => s.navKey === navKey)) standalone.push(target);
                }
            });

            standalone.sort((a, b) => a.label.localeCompare(b.label));
            sections.sort((a, b) => a.label.localeCompare(b.label));
            sections.forEach(s => s.children.sort((a, b) => a.label.localeCompare(b.label)));

            this.standalone = standalone;
            this.sections = sections;
            this.ready = true;
        },

        setAll(state) {
            Object.keys(this.hidden).forEach(k => { this.hidden[k] = state; });
        },

        hiddenCount() {
            return Object.values(this.hidden).filter(Boolean).length;
        },
    };
}
</script>
@endsection
