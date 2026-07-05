{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
{{-- SPINE-UI-FIX: the JS URL builders previously used `url('admin/
     activity-mappings')` to build the toggle/update endpoints. That
     drops the outer `corex/` route prefix the admin route group lives
     under -- generating `/admin/activity-mappings/{id}/toggle-active`
     instead of the actual `/corex/admin/activity-mappings/{id}/toggle-
     active`. Real browsers hit a 404 (no log line; no controller
     execution; just framework not-found), the JS fetch rejected with
     "HTTP 404", and rowState surfaced the canned "Could not toggle"
     flash. Tinker testing hadn't caught this because tinker called
     the controller method directly, bypassing routing.
     The fix is to use the NAMED ROUTE helper with a numeric sentinel
     (0) and substitute the real id client-side. This is prefix-
     independent and survives any future route refactor. --}}
@php
    $updateUrlTpl  = route('admin.activity-mappings.update',         ['id' => 0]);
    $toggleUrlTpl  = route('admin.activity-mappings.toggle-active',  ['id' => 0]);
@endphp
<div class="w-full space-y-5"
     x-data="spineSettings({
        csrf: '{{ csrf_token() }}',
        updateUrlTpl: '{{ $updateUrlTpl }}',
        toggleUrlTpl: '{{ $toggleUrlTpl }}',
     })">

    {{-- Header (Pattern A — branded, full-width) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Activity Scoring</h1>
                <p class="text-sm text-white/60">
                    Configure how much each agent action is worth, and switch actions on or off for your agency. Changes here only affect <span class="font-semibold text-white/80">{{ $agencyName ?? 'your agency' }}</span> — system defaults are preserved.
                </p>
            </div>
        </div>
    </div>

    {{-- Stat grid — count lives below the header, never inside it (§3.1) --}}
    @if($totalActions > 0)
    <div class="corex-kpi-grid">
        <x-corex-kpi-card title="Configurable actions" :value="number_format($totalActions)" />
    </div>
    @endif

    {{-- Inline status bar (saving / saved / error) --}}
    <div x-cloak x-show="status.message"
         class="rounded-md px-4 py-2 text-sm font-medium transition-opacity"
         :style="status.kind === 'error'
            ? 'background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--ds-crimson);'
            : 'background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--ds-green);'"
         x-text="status.message"></div>

    {{-- Groups --}}
    @forelse($catalogue as $groupName => $rows)
        <div class="rounded-md" style="background:var(--surface); border:1px solid var(--border);">
            <div class="px-4 py-3 flex items-baseline justify-between"
                 style="border-bottom:1px solid var(--border); background:var(--surface-2);">
                <div class="text-sm font-semibold" style="color:var(--text-primary);">{{ $groupName }}</div>
                <div class="text-xs" style="color:var(--text-muted);">{{ number_format(count($rows)) }} {{ count($rows) === 1 ? 'action' : 'actions' }}</div>
            </div>

            @foreach($rows as $row)
                <div class="px-4 py-3 flex flex-wrap items-center gap-3"
                     style="border-top:1px solid var(--border);"
                     :class="!rowState[{{ $row['id'] }}].is_active ? 'opacity-60' : ''">
                    {{-- Left: label + meta --}}
                    <div class="flex-1 min-w-[260px]">
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">{{ $row['label'] }}</div>
                        <div class="text-xs mt-0.5" style="color:var(--text-muted);">
                            @if($row['kind'] === 'calendar')
                                Calendar event ·
                                {{ $row['requires_feedback'] ? 'requires feedback' : 'instant confirm' }}
                                @if($row['daily_cap']) · cap {{ $row['daily_cap'] }}/day @endif
                                @if($row['back_date_limit_hours'] !== null) · back-date {{ $row['back_date_limit_hours'] }}h @endif
                            @else
                                Instant action
                                @if($row['daily_cap']) · cap {{ $row['daily_cap'] }}/day @endif
                                @if($row['subject_type'])
                                    · {{ class_basename($row['subject_type']) }}
                                @endif
                            @endif
                        </div>
                    </div>

                    {{-- Weight input --}}
                    <div class="flex items-center gap-2">
                        <label class="text-xs font-semibold" style="color:var(--text-secondary);">Points</label>
                        <input
                            type="number"
                            min="0"
                            max="10000"
                            step="1"
                            x-model.number="rowState[{{ $row['id'] }}].value_per_event"
                            @change="saveValue({{ $row['id'] }})"
                            class="w-24 px-2 py-1 text-sm rounded-md text-right"
                            style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                        >
                        <span x-cloak x-show="rowState[{{ $row['id'] }}].savingValue" class="text-xs" style="color:var(--text-muted);">saving…</span>
                        <span x-cloak x-show="rowState[{{ $row['id'] }}].savedValueAt" class="text-xs" style="color:var(--ds-green);">saved</span>
                    </div>

                    {{-- Active toggle --}}
                    <button
                        type="button"
                        @click="toggleActive({{ $row['id'] }})"
                        class="inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-md transition-colors"
                        :style="rowState[{{ $row['id'] }}].is_active
                            ? 'background: color-mix(in srgb, var(--ds-green) 15%, transparent); color: var(--ds-green); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);'
                            : 'background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border);'"
                        x-text="rowState[{{ $row['id'] }}].is_active ? 'Active' : 'Inactive'"
                    ></button>
                </div>
            @endforeach
        </div>
    @empty
        {{-- Empty state (§3.10) — no create path: scoring rows are seeded per-agency --}}
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border:1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.397-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No scoring actions configured yet</h3>
            <p class="text-sm" style="color: var(--text-muted);">Activity actions are seeded per-agency. If this list is empty, the catalogue seed has not run for your agency yet — re-run the activity seed or contact support.</p>
        </div>
    @endforelse

    {{-- Footer note --}}
    <div class="text-xs px-1" style="color:var(--text-muted);">
        Edits save automatically. Points are awarded the moment an action happens — outcomes (won/lost, approved/rejected) never change a point that's already been earned. Reversals (an un-registered deal, a deleted record) reverse the matching credit.
    </div>
</div>

<script>
function spineSettings(config) {
    // SPINE-SETTINGS-FIX: build a flat id-keyed associative array in PHP,
    // then JSON encode with JSON_FORCE_OBJECT.
    //
    // The earlier flatMap+mapWithKeys path produced a JSON ARRAY because
    // Laravel's Collection::flatMap calls Arr::collapse -> array_merge,
    // and array_merge REINDEXES integer keys 0,1,2,... -- so the JS
    // received `[{...}, {...}, ...]` instead of `{ "31": {...}, ... }`.
    // Result: rowState[31] returned undefined for every row whose true
    // id wasn't a small array index, and every binding crashed with
    // "Cannot read properties of undefined". Low-id calendar rows
    // accidentally aligned to early array indices and SEEMED to work
    // (often showing the wrong row's data); instant-action rows with
    // ids > catalogue size failed outright. Inline PHP build + force-
    // object on encode is the fix that preserves the id-keyed map.
    @php
        $rowStateInit = [];
        foreach ($catalogue as $rows) {
            foreach ($rows as $r) {
                $rowStateInit[(int) $r['id']] = [
                    'value_per_event' => (int) $r['value_per_event'],
                    'is_active'       => (bool) $r['is_active'],
                    'savingValue'     => false,
                    'savedValueAt'    => null,
                    'savingActive'    => false,
                ];
            }
        }
    @endphp
    const initial = {!! json_encode($rowStateInit, JSON_FORCE_OBJECT) !!};

    return {
        rowState: initial,
        status: { kind: 'ok', message: '' },
        flash(kind, msg, ms = 2200) {
            this.status = { kind, message: msg };
            clearTimeout(this._flashT);
            this._flashT = setTimeout(() => { this.status = { kind: 'ok', message: '' }; }, ms);
        },
        async saveValue(id) {
            const row = this.rowState[id];
            const v   = Number.isInteger(row.value_per_event) ? row.value_per_event : parseInt(row.value_per_event, 10);
            if (!Number.isFinite(v) || v < 0 || v > 10000) {
                this.flash('error', 'Points must be 0–10000.');
                return;
            }
            row.savingValue  = true;
            row.savedValueAt = null;
            try {
                // SPINE-UI-FIX: substitute the sentinel id (0) at click time.
                const url = config.updateUrlTpl.replace(/\/0(\?|$)/, '/' + id + '$1');
                const r = await fetch(url, {
                    method: 'PUT',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': config.csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ value_per_event: v, is_active: row.is_active ? 1 : 0 }),
                });
                if (!r.ok) throw new Error('HTTP ' + r.status);
                row.savedValueAt = Date.now();
                this.flash('ok', 'Saved.');
                setTimeout(() => { if (row.savedValueAt && (Date.now() - row.savedValueAt) >= 1800) row.savedValueAt = null; }, 2000);
            } catch (e) {
                this.flash('error', 'Save failed — try again.');
            } finally {
                row.savingValue = false;
            }
        },
        async toggleActive(id) {
            const row = this.rowState[id];
            if (row.savingActive) return;
            row.savingActive = true;
            const next = !row.is_active;
            try {
                // SPINE-UI-FIX: substitute the sentinel id (0) at click time.
                // The toggle URL template has /0/toggle-active at the tail, so
                // we replace /0/ before the trailing segment.
                const url = config.toggleUrlTpl.replace('/0/toggle-active', '/' + id + '/toggle-active');
                const r = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': config.csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const data = await r.json();
                row.is_active = !! data.is_active;
                this.flash('ok', row.is_active ? 'Activated.' : 'Deactivated.');
            } catch (e) {
                this.flash('error', 'Could not toggle — try again.');
            } finally {
                row.savingActive = false;
            }
        },
    };
}
</script>
@endsection
