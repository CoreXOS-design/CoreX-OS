{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Merged Daily Activities Setup — replaces admin.targets.activity.definitions
     ("Activity Definitions") + admin.activity-mappings.index ("Activity Scoring")
     with one two-tab screen: Manual Daily Activities / Auto Daily Activities. --}}
@extends('layouts.corex-app')

@push('head')
    <style>
        .acty-input {
            width: 100%;
            padding: 0.5rem 0.75rem;
            font-size: 0.8125rem;
            border-radius: 6px;
            background: var(--surface-2);
            border: 1px solid var(--border);
            color: var(--text-primary);
            transition: border-color 150ms ease, box-shadow 150ms ease, background-color 150ms ease;
        }
        .acty-input:focus {
            outline: none;
            border-color: var(--brand-icon);
            box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand-icon) 15%, transparent);
        }
        .acty-input-sm { padding: 0.375rem 0.625rem; }
        .acty-num { text-align: right; }
        .acty-check { width: 1rem; height: 1rem; accent-color: var(--brand-icon); cursor: pointer; }
        .acty-defs-table tbody tr { transition: background-color 150ms ease; }
        .acty-defs-table tbody tr:hover td { background: var(--surface-2); }
        .acty-defs-table td .acty-num { width: 6rem; }
        .acty-defs-table td select.acty-input { width: 8rem; }

        .das-tab-btn {
            padding: 0.5rem 1rem;
            font-size: 0.8125rem;
            font-weight: 600;
            border-radius: 6px;
            color: var(--text-secondary);
            transition: background-color 150ms ease, color 150ms ease;
        }
        .das-tab-btn.active {
            background: var(--brand-icon);
            color: #fff;
        }
    </style>
@endpush

@section('corex-content')
@php
    $updateMappingUrlTpl = $canAuto ? route('admin.daily-activities.setup.update-mapping', ['id' => 0]) : null;
    $toggleMappingUrlTpl = $canAuto ? route('admin.daily-activities.setup.toggle-mapping', ['id' => 0]) : null;
@endphp
<div class="w-full space-y-5"
     x-data="dailyActivitiesSetup({
        activeTab: '{{ $activeTab }}',
        csrf: '{{ csrf_token() }}',
        updateMappingUrlTpl: {{ $updateMappingUrlTpl ? "'".$updateMappingUrlTpl."'" : 'null' }},
        toggleMappingUrlTpl: {{ $toggleMappingUrlTpl ? "'".$toggleMappingUrlTpl."'" : 'null' }},
     })">

    {{-- Page Header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Daily Activities Setup</h1>
                <p class="text-xs" style="color: var(--text-muted);">Configure the manual activity catalogue and the auto-credit engine's scoring, in one place.</p>
            </div>

            @if($canManual && $canAuto)
            <div class="flex items-center gap-1 rounded-md p-1" style="background: var(--surface-2); border: 1px solid var(--border); width: fit-content;">
                <button type="button" class="das-tab-btn" :class="activeTab === 'manual' ? 'active' : ''" @click="activeTab = 'manual'">Manual Daily Activities</button>
                <button type="button" class="das-tab-btn" :class="activeTab === 'auto' ? 'active' : ''" @click="activeTab = 'auto'">Auto Daily Activities</button>
            </div>
            @endif
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            <div class="flex-1">{{ $errors->first() }}</div>
        </div>
    @endif

    {{-- ═══════════════════ MANUAL DAILY ACTIVITIES ═══════════════════ --}}
    @if($canManual)
    <div @if($canAuto) x-show="activeTab === 'manual'" x-cloak @endif class="space-y-5">

        {{-- Add New Activity --}}
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
                <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Add New Activity</h2>
            </div>

            <div class="px-5 py-4">
                <form method="POST" action="{{ route('admin.daily-activities.setup.store-definition') }}" class="space-y-4">
                    @csrf

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                        <div class="sm:col-span-2">
                            <label for="acty-name" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Name</label>
                            <input id="acty-name" name="name" required placeholder="Appointments" class="acty-input" />
                        </div>

                        <div>
                            <label for="acty-weight" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Weight</label>
                            <input id="acty-weight" name="weight" type="number" step="0.01" min="0" value="1" class="acty-input acty-num" />
                        </div>

                        <div>
                            <label for="acty-order" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Order</label>
                            <input id="acty-order" name="sort_order" type="number" min="0" value="100" class="acty-input acty-num" />
                        </div>

                        <div>
                            <label for="acty-scoring" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Scoring</label>
                            <select id="acty-scoring" name="scoring_mode" class="acty-input">
                                <option value="count" selected>Per action</option>
                                <option value="once">Once (tick)</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-4 pt-1">
                        <label class="inline-flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                            <input type="checkbox" name="is_enabled" value="1" class="acty-check" checked>
                            Active
                        </label>
                        <button class="corex-btn-primary">Add Activity</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Existing Definitions --}}
        {{-- Per-row forms live outside the table; inputs associate via the HTML5 form= attribute.
             A <form> cannot be a valid child of <tr> — the parser foster-parents it out of the table. --}}
        @foreach($definitions as $d)
            <form id="acty-def-{{ $d->id }}" method="POST" action="{{ route('admin.daily-activities.setup.update-definition', ['id' => $d->id]) }}" class="hidden">
                @csrf
                @method('PUT')
            </form>
        @endforeach

        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
                <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Existing Definitions</h2>
                <p class="text-xs mt-1" style="color: var(--text-muted);">Activities used only as auto-credit targets ([Auto] rows) are hidden here — edit their scoring on the Auto tab.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table acty-defs-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Name</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-28" style="color: var(--text-muted);">Weight</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-28" style="color: var(--text-muted);">Order</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-36" style="color: var(--text-muted);">Scoring</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-24" style="color: var(--text-muted);">Enabled</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-24" style="color: var(--text-muted);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($definitions as $d)
                            <tr style="border-top: 1px solid var(--border);">
                                <td class="px-4 py-3">
                                    <input form="acty-def-{{ $d->id }}" name="name" value="{{ $d->name }}" class="acty-input acty-input-sm">
                                </td>

                                <td class="px-4 py-3">
                                    <input form="acty-def-{{ $d->id }}" name="weight" type="number" step="0.01" min="0"
                                           value="{{ number_format((float)$d->weight, 2, '.', '') }}"
                                           class="acty-input acty-input-sm acty-num">
                                </td>

                                <td class="px-4 py-3">
                                    <input form="acty-def-{{ $d->id }}" name="sort_order" type="number" min="0"
                                           value="{{ (int)$d->sort_order }}"
                                           class="acty-input acty-input-sm acty-num">
                                </td>

                                <td class="px-4 py-3">
                                    @php($sm = (string)($d->scoring_mode ?? 'count'))
                                    <select form="acty-def-{{ $d->id }}" name="scoring_mode" class="acty-input acty-input-sm">
                                        <option value="count" @selected($sm === 'count')>Per action</option>
                                        <option value="once" @selected($sm === 'once')>Once (tick)</option>
                                    </select>
                                </td>

                                <td class="px-4 py-3">
                                    <input form="acty-def-{{ $d->id }}" type="checkbox" name="is_enabled" value="1" class="acty-check" @checked((int)$d->is_enabled === 1)>
                                </td>

                                <td class="px-4 py-3 text-right">
                                    <button form="acty-def-{{ $d->id }}" class="corex-btn-primary text-xs">Save</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                    No activity definitions yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif

    {{-- ═══════════════════ AUTO DAILY ACTIVITIES ═══════════════════ --}}
    @if($canAuto)
    <div @if($canManual) x-show="activeTab === 'auto'" x-cloak @endif class="space-y-5">

        <div class="rounded-md px-5 py-4" style="background: var(--surface); border: 1px solid var(--border);">
            <p class="text-xs" style="color: var(--text-muted);">
                Configure how much each agent action is worth, and switch actions on or off for your agency. Changes here only affect <span class="font-semibold" style="color: var(--text-secondary);">{{ $agencyName ?? 'your agency' }}</span> — system defaults are preserved.
            </p>
        </div>

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
            <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border:1px solid var(--border);">
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No scoring actions configured yet</h3>
                <p class="text-sm" style="color: var(--text-muted);">Activity actions are seeded per-agency. If this list is empty, the catalogue seed has not run for your agency yet — re-run the activity seed or contact support.</p>
            </div>
        @endforelse

        <div class="text-xs px-1" style="color:var(--text-muted);">
            Edits save automatically. Points are awarded the moment an action happens — outcomes (won/lost, approved/rejected) never change a point that's already been earned. Reversals (an un-registered deal, a deleted record) reverse the matching credit.
        </div>
    </div>
    @endif

</div>

<script>
function dailyActivitiesSetup(config) {
    @php
        $rowStateInit = [];
        if ($canAuto) {
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
        }
    @endphp
    const initial = {!! json_encode($rowStateInit, JSON_FORCE_OBJECT) !!};

    return {
        activeTab: config.activeTab,
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
                const url = config.updateMappingUrlTpl.replace(/\/0(\?|$)/, '/' + id + '$1');
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
            try {
                const url = config.toggleMappingUrlTpl.replace('/0/toggle-active', '/' + id + '/toggle-active');
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
                row.is_active = !!data.is_active;
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
