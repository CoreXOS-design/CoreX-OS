@extends('layouts.corex')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Demo Sidebar</h1>
                <p class="text-sm text-white/60">Choose which sidebar items and sub-pages a demo agency shows.</p>
            </div>
            <a href="{{ route('admin.dev-settings.index') }}"
               class="corex-btn-outline text-sm self-start"
               style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
                Back to Dev Settings
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color: var(--ds-green, #059669);">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <div class="flex-1">{{ session('success') }}</div>
        </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════════
         DEMO SIDEBAR CURATION
         Hide sidebar items / sub-pages for demo-agency members only.
         The checklist is built client-side from the live sidebar
         (window.CorexNavSearch) so it always mirrors what renders —
         no hand-maintained registry. Checked = hidden.
         See .ai/specs/demo-sidebar-curation.md
         ═══════════════════════════════════════════════════════════════ --}}
    <form method="POST" action="{{ route('admin.dev-settings.demo-sidebar.update') }}"
          class="rounded-md p-6 space-y-5"
          style="background: var(--surface); border: 1px solid var(--border);"
          x-data="demoSidebarCurator(@js($demoHiddenNav))" x-init="init()">
        @csrf
        @method('PUT')

        <div>
            <h2 class="font-semibold" style="color: var(--text-primary);">Demo sidebar visibility</h2>
            <p class="text-sm mt-1" style="color: var(--text-secondary);">
                Ticked items are <strong>hidden</strong> from users whose agency is flagged as a demo agency.
                System Owners and all real users always see the full sidebar — this never affects production accounts.
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
