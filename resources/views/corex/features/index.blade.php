{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Features page — per-agency module on/off. Specs: .ai/specs/features-page.md (style 1, "Clean list"),
     corex-feature-registry.md §6.4. Moved out of Settings 2026-10-12.

     Layout: a fixed-height column — header, search + filter, the LIST (the only thing that scrolls), save bar.

     SAVER SAFETY (spec §6.1 — do not break): EVERY row stays in the form. Search and the All/On/Off filter only
     hide rows with x-show; they never remove them, so a toggle changed and then filtered out is still posted.
     A module row posts a "0"/"1" for its key; a row blocked by a parent that is off posts NOTHING, so its stored
     preference is left untouched. Core and switchboard rows are display-only (no input). --}}
@extends('layouts.corex-app')

@section('corex-content')
<form method="POST" action="{{ route('corex.settings.features.update') }}"
      x-data="featuresPage({{ \Illuminate\Support\Js::from($items) }})" x-cloak
      class="flex flex-col gap-4" style="height: calc(100vh - 8rem); min-height: 480px;">
    @csrf

    {{-- Header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner flex-shrink-0">
        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Features</h1>
                <p class="text-xs mt-1 max-w-2xl" style="color: var(--text-muted);">
                    Turn whole modules on or off for your agency. Turning one off hides it for everyone, including in Role Manager,
                    and skips it in onboarding. Nothing is deleted, and you can turn it back on any time. Access still depends on each
                    person's role: a feature being on doesn't grant a permission.
                </p>
            </div>
            <div class="text-sm whitespace-nowrap" style="color: var(--text-secondary);">
                <strong style="color: var(--text-primary);" x-text="onCount"></strong> of <span x-text="total"></span> on
            </div>
        </div>
    </div>

    {{-- What the agency does. A separate form (id below) so saving it never posts, or depends on, the switches. --}}
    <div class="flex-shrink-0 rounded-md px-4 py-3 flex flex-wrap items-center gap-3" style="background: var(--surface); border:1px solid var(--border);" data-test="agency-type-card">
        <div class="flex-1" style="min-width:260px;">
            <label for="agency_type" class="text-sm font-semibold" style="color: var(--text-primary);">What does your agency do?</label>
            <p class="text-xs mt-0.5" style="color: var(--text-muted);">Changing this switches the matching tools on or off: <strong>Rentals only</strong> turns the sales tools off, <strong>Sales only</strong> turns the Rentals area off. Anything else you have switched stays as you set it, and you can still change any switch below.</p>
        </div>
        <select id="agency_type" name="agency_type" form="agency-type-form" class="rounded-md text-sm"
                style="height:40px; padding:0 12px; color:var(--text-primary); background:var(--surface-2); border:1px solid var(--border);">
            @foreach (\App\Services\Features\AgencyTypeFeatureSeeder::options() as $typeValue => $typeLabel)
                <option value="{{ $typeValue }}" @selected($agencyType === $typeValue)>{{ $typeLabel }}</option>
            @endforeach
        </select>
        <button type="submit" form="agency-type-form" class="corex-btn-outline text-xs" data-test="agency-type-save">Save type</button>
    </div>

    @if (session('success'))
        <div class="rounded-md px-3 py-2 text-sm flex-shrink-0"
             style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 30%, transparent); color:var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif

    {{-- Search + filter --}}
    <div class="flex-shrink-0" style="display:flex; flex-wrap:wrap; align-items:center; gap:12px;">
        <label class="block" style="position:relative; flex:1 1 320px;">
            <span class="sr-only">Search features</span>
            <span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); display:flex; color: var(--text-faint);" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
            </span>
            {{-- Enter must not submit the form (it would save every pending switch). --}}
            <input type="search" x-model="q" @keydown.enter.prevent autocomplete="off"
                   placeholder="Search features, e.g. payroll, rentals, documents"
                   class="w-full rounded-md text-sm outline-none"
                   style="height:44px; padding:0 14px 0 40px; color:var(--text-primary); background:var(--surface); border:1px solid var(--border);">
        </label>
        <div class="flex rounded-md flex-shrink-0" style="padding:3px; background: var(--surface-2); border:1px solid var(--border);" role="group" aria-label="Show">
            <template x-for="opt in [['all','All'],['on','On'],['off','Off']]" :key="opt[0]">
                <button type="button" @click="filter = opt[0]" x-text="opt[1]"
                        class="px-4 text-[13px] font-semibold rounded transition-colors" style="height:32px;"
                        :style="filter === opt[0] ? 'background:var(--surface); color:var(--text-primary); box-shadow:0 1px 2px rgba(15,23,42,.15);' : 'background:transparent; color:var(--text-muted);'"
                        :aria-pressed="filter === opt[0] ? 'true' : 'false'"></button>
            </template>
        </div>
    </div>
    <p class="sr-only" aria-live="polite" x-text="visibleCount() + ' features shown'"></p>

    {{-- The list: the ONLY scrolling region on the page --}}
    <div class="flex-1 min-h-0 overflow-y-auto rounded-md" style="background: var(--surface); border:1px solid var(--border);">
        <div x-show="visibleCount() === 0" class="px-6 py-16 text-center text-sm" style="color: var(--text-muted);">
            No features match <span x-text="q ? '&quot;' + q + '&quot;' : 'this filter'"></span>. Try a shorter word, or choose All.
        </div>

        @foreach ($grouped as $category => $rows)
            <div x-show="catVisible({{ \Illuminate\Support\Js::from(array_column($rows, 'key')) }})">
                <div class="font-semibold uppercase"
                     style="position:sticky; top:0; z-index:10; padding:8px 20px; font-size:11px; letter-spacing:.06em; background: var(--surface-2); color: var(--text-muted); border-bottom:1px solid var(--border);">
                    {{ $category }}
                </div>

                @foreach ($rows as $row)
                    @php $k = $row['key']; $isModule = $row['kind'] === 'module'; @endphp
                    <div x-show="matches('{{ $k }}')"
                         style="display:flex; align-items:flex-start; justify-content:space-between; gap:24px; padding:14px 20px; border-bottom:1px solid var(--border);">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 text-sm font-semibold" style="color: var(--text-primary);">
                                <span>{{ $row['label'] }}</span>
                                @if ($row['kind'] === 'core')
                                    <span class="ds-badge ds-badge-pill ds-badge-default">Always on</span>
                                @elseif ($row['kind'] === 'switchboard')
                                    <span class="ds-badge ds-badge-pill ds-badge-default">Managed in its own settings</span>
                                @endif
                            </div>
                            @if (!empty($row['explain']))
                                <p class="text-[13px] mt-0.5 leading-snug" style="color: var(--text-secondary, var(--text-muted));">{{ $row['explain'] }}</p>
                            @endif
                            @if (!empty($row['affects']))
                                <p class="text-xs mt-1 leading-snug" style="color: var(--text-faint);">
                                    <span class="font-semibold">What this changes:</span> {{ $row['affects'] }}
                                </p>
                            @endif
                            @if ($isModule && !empty($row['depends_on']))
                                <p x-show="blocked('{{ $k }}')" class="text-xs mt-1" style="color: var(--ds-crimson,#e11d48);">
                                    Turn on <strong>{{ collect($row['depends_on'])->map(fn ($p) => $catalogue[$p]['label'] ?? $p)->implode(' and ') }}</strong> first — this depends on it.
                                </p>
                            @endif
                        </div>

                        <div class="flex-shrink-0 mt-0.5">
                            @if ($isModule)
                                {{-- Posts "1"/"0"; disabled (so posts nothing) while a parent is off. --}}
                                <input type="hidden" name="{{ $k }}" value="{{ $row['enabled'] ? 1 : 0 }}"
                                       :value="on['{{ $k }}'] ? 1 : 0" :disabled="blocked('{{ $k }}')" @disabled((bool) $row['blocked_by'])>
                            @endif
                            <button type="button" role="switch" aria-label="{{ $row['label'] }}"
                                    :aria-checked="eff('{{ $k }}') ? 'true' : 'false'"
                                    :disabled="isLocked('{{ $k }}')" @click="toggle('{{ $k }}')"
                                    class="relative w-11 h-6 rounded-full transition-colors"
                                    :class="isLocked('{{ $k }}') ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer'"
                                    :style="'background:' + (eff('{{ $k }}') ? 'var(--brand-button,#0ea5e9)' : 'var(--border-hover)')">
                                <span class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform"
                                      :class="eff('{{ $k }}') ? 'translate-x-5' : ''"></span>
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    {{-- Save bar --}}
    <div class="flex items-center justify-between gap-4 flex-shrink-0 pt-3" style="border-top:1px solid var(--border);">
        <div class="text-[13px]" style="color: var(--text-muted);" x-text="changesText"></div>
        <div class="flex gap-2.5">
            <button type="button" @click="discard()" :disabled="changes === 0"
                    class="px-4 text-sm font-medium rounded-md disabled:opacity-50" style="height:40px; color:var(--text-secondary); background:var(--surface); border:1px solid var(--border);">
                Discard
            </button>
            <button type="submit" :disabled="changes === 0" class="corex-btn-primary text-sm px-5 disabled:opacity-50" style="height:40px;">
                Save features
            </button>
        </div>
    </div>
</form>
<form id="agency-type-form" method="POST" action="{{ route('corex.settings.agency-type.update') }}">@csrf</form>

<script>
/* Features page state. `items` = key => {kind, enabled, depends_on, blocked_by, search}; kinds: core | switchboard | module. */
function featuresPage(items) {
    const on = {}, initial = {};
    Object.keys(items).forEach(function (k) { on[k] = !!items[k].enabled; initial[k] = on[k]; });
    return {
        items: items, on: on, initial: initial, q: '', filter: 'all',

        /* Effective state: core always on; switchboard as saved; module = its own switch AND every parent effective. */
        eff: function (k) {
            var i = this.items[k]; if (!i) return false;
            if (i.kind === 'core') return true;
            if (i.kind === 'switchboard') return !!i.enabled;
            var self = this;
            return !!self.on[k] && i.depends_on.every(function (p) { return self.eff(p); });
        },
        /* A module is blocked while any parent is off (saved OR switched off here) — it then posts nothing. */
        blocked: function (k) {
            var i = this.items[k], self = this;
            return !!i && i.kind === 'module' && (!!i.blocked_by || i.depends_on.some(function (p) { return !self.eff(p); }));
        },
        isLocked: function (k) { return this.items[k].kind !== 'module' || this.blocked(k); },
        toggle: function (k) { if (!this.isLocked(k)) this.on[k] = !this.on[k]; },

        matches: function (k) {
            var i = this.items[k], q = this.q.trim().toLowerCase();
            if (q && i.search.indexOf(q) === -1) return false;
            if (this.filter === 'on') return this.eff(k);
            if (this.filter === 'off') return !this.eff(k);
            return true;
        },
        catVisible: function (keys) { var self = this; return keys.some(function (k) { return self.matches(k); }); },
        visibleCount: function () { var self = this; return Object.keys(this.items).filter(function (k) { return self.matches(k); }).length; },

        get total() { return Object.keys(this.items).length; },
        get onCount() { var self = this; return Object.keys(this.items).filter(function (k) { return self.eff(k); }).length; },
        get changes() {
            var self = this;
            return Object.keys(this.items).filter(function (k) { return self.items[k].kind === 'module' && !self.blocked(k) && self.on[k] !== self.initial[k]; }).length;
        },
        get changesText() { var n = this.changes; return n === 0 ? 'No unsaved changes' : n + (n === 1 ? ' unsaved change' : ' unsaved changes'); },
        discard: function () { this.on = Object.assign({}, this.initial); }
    };
}
</script>
@endsection
