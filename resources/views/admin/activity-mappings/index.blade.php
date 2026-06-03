@extends('layouts.corex-app')

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
<div class="max-w-6xl mx-auto px-4 sm:px-6 py-6 space-y-5"
     x-data="spineSettings({
        csrf: '{{ csrf_token() }}',
        updateUrlTpl: '{{ $updateUrlTpl }}',
        toggleUrlTpl: '{{ $toggleUrlTpl }}',
     })">

    {{-- Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Activity Scoring</h1>
                <p class="text-sm text-white/60 mt-1">
                    Configure how much each agent action is worth, and switch actions on or off for your agency. Changes here only affect <span class="font-semibold">{{ $agencyName ?? 'your agency' }}</span> — system defaults are preserved.
                </p>
            </div>
            <div class="text-right shrink-0">
                <div class="text-2xl font-bold text-white">{{ $totalActions }}</div>
                <div class="text-xs uppercase tracking-wider text-white/60">configurable actions</div>
            </div>
        </div>
    </div>

    {{-- Inline status bar (saving / saved / error) --}}
    <div x-cloak x-show="status.message"
         class="rounded-md px-4 py-2 text-sm font-medium transition-opacity"
         :style="status.kind === 'error'
            ? 'background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--ds-crimson);'
            : 'background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--ds-green);'"
         x-text="status.message"></div>

    {{-- Groups --}}
    @foreach($catalogue as $groupName => $rows)
        <div class="rounded-md" style="background:var(--surface); border:1px solid var(--border);">
            <div class="px-4 py-3 flex items-baseline justify-between"
                 style="border-bottom:1px solid var(--border); background:var(--surface-2);">
                <div class="text-sm font-semibold" style="color:var(--text-primary);">{{ $groupName }}</div>
                <div class="text-xs" style="color:var(--text-muted);">{{ count($rows) }} {{ count($rows) === 1 ? 'action' : 'actions' }}</div>
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
                            class="w-24 px-2 py-1 text-sm rounded text-right"
                            style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                        >
                        <span x-cloak x-show="rowState[{{ $row['id'] }}].savingValue" class="text-xs" style="color:var(--text-muted);">saving…</span>
                        <span x-cloak x-show="rowState[{{ $row['id'] }}].savedValueAt" class="text-xs" style="color:var(--ds-green);">saved</span>
                    </div>

                    {{-- Active toggle --}}
                    <button
                        type="button"
                        @click="toggleActive({{ $row['id'] }})"
                        class="inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded transition-colors"
                        :style="rowState[{{ $row['id'] }}].is_active
                            ? 'background: color-mix(in srgb, var(--ds-green) 15%, transparent); color: var(--ds-green); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);'
                            : 'background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border);'"
                        x-text="rowState[{{ $row['id'] }}].is_active ? 'Active' : 'Inactive'"
                    ></button>
                </div>
            @endforeach
        </div>
    @endforeach

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
