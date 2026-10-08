@extends('layouts.corex')

{{--
    .ai/specs/rental-inspections.md §45.7 (Build I-5) — the "Due" tab of the Rental Inspections list.

    One list, two sources side by side:
      - Move-in / Move-out inspections that are DUE, worked out from the active leases (nothing is pre-created);
      - Interim dates the AGENCY loaded itself. Johan, 6 Oct 2026: interim inspections are not scheduled automatically —
        not every agency does them — so nothing on this screen computes an interim date; an agent loads each one and
        CoreX reminds the responsible agent (never the tenant or landlord) from the dates loaded here.

    List standard (BUILD_STANDARD §1b): search (property address, tenant, agent, note), sort (due date by default),
    filter (type, status, due-date range, agent, own/branch/agency), pages of 25/50/100, real empty states, print and
    CSV/XLSX of the same scoped rows. See RentalInspectionDueController's header for the scoping rules.
--}}

@php
    $sortLink = fn ($col) => route('corex.rental-inspections.due', array_merge(
        request()->except('page'),
        ['sort' => $col, 'direction' => ($sort === $col && $direction === 'asc') ? 'desc' : 'asc']
    ));
    $sortIndicator = fn ($col) => $sort === $col ? ($direction === 'asc' ? ' ▲' : ' ▼') : '';
    $badge = fn ($state) => match ($state) {
        'overdue' => 'ds-badge-danger',
        'due' => 'ds-badge-info',
        'booked' => 'ds-badge-info',
        'done' => 'ds-badge-success',
        default => 'ds-badge-muted',
    };
    $currentStatus = $filters['status'] ?? 'open';
    $tileDefs = [
        'overdue' => ['label' => 'Overdue', 'status' => 'overdue'],
        'due' => ['label' => 'Due now', 'status' => 'due'],
        'upcoming' => ['label' => 'Upcoming', 'status' => 'upcoming'],
        'booked' => ['label' => 'Booked', 'status' => 'booked'],
    ];
    $tiles = collect($tileDefs)->map(fn ($def, $key) => [
        'key' => $key,
        'label' => $def['label'],
        'count' => $tileCounts[$key],
        'href' => route('corex.rental-inspections.due', array_merge(
            request()->except(['status', 'page']),
            $currentStatus === $def['status'] ? [] : ['status' => $def['status']]
        )),
        'active' => $currentStatus === $def['status'],
    ])->values()->all();
@endphp

@section('content')
<div class="p-6 space-y-4">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
            <h1 class="text-lg font-semibold">Rental Inspections</h1>
            <div class="inline-flex rounded-md overflow-hidden" style="border: 1px solid var(--border);">
                <a href="{{ route('corex.rental-inspections.index') }}" class="px-3 py-1.5 text-xs font-semibold" style="background: var(--surface); color: var(--text-muted);">All inspections</a>
                <a href="{{ route('corex.rental-inspections.due') }}" data-qa="due-tab" class="px-3 py-1.5 text-xs font-semibold" style="border-left: 1px solid var(--border); background: var(--brand-icon, #0ea5e9); color: #fff;">Due</a>
            </div>
        </div>
        <div class="flex items-center gap-2">
            @permission('rental_inspections.create')
            <a href="{{ route('corex.rental-inspections.create') }}" class="corex-btn-primary text-xs">Start Inspection</a>
            @endpermission
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium" data-qa="due-flash-success"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif
    @if(session('warning'))
        <div class="rounded-md px-4 py-3 text-sm font-medium" data-qa="due-flash-warning"
             style="background: color-mix(in srgb, var(--ds-amber) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 35%, transparent); color: var(--text-primary);">{{ session('warning') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" data-qa="due-flash-errors"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <ul class="list-disc list-inside space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <p class="text-xs" style="color: var(--text-muted);">
        Move-in and move-out inspections appear here when they fall due, worked out from each active lease. Interim
        inspections are not scheduled automatically — load the dates your agency wants and CoreX reminds the responsible
        agent {{ $plannedLead }} day{{ $plannedLead === 1 ? '' : 's' }} before, on the day and the day after. The tenant and
        landlord are only contacted when you book the inspection.
    </p>

    <x-rental-list-controls
        :tiles="$tiles"
        :scope-options="$scopeOptions"
        :resolved-scope="$resolvedScope"
        route-name="corex.rental-inspections.due"
        :per-page="$perPage"
        :per-page-options="$perPageOptions"
        :archivable="true"
        :archived="$archived"
        :print-url="auth()->user()->hasPermission('rental_inspections.export') ? route('corex.rental-inspections.due.print', request()->query()) : null"
        :export-xlsx-url="auth()->user()->hasPermission('rental_inspections.export') ? route('corex.rental-inspections.due.export', array_merge(request()->query(), ['format' => 'xlsx'])) : null"
        :export-csv-url="auth()->user()->hasPermission('rental_inspections.export') ? route('corex.rental-inspections.due.export', array_merge(request()->query(), ['format' => 'csv'])) : null"
    />

    @if($canManage && $leaseOptions->isNotEmpty() && !$archived)
    <details class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);" @if($errors->has('dates') || $errors->has('lease_id') || old('lease_id')) open @endif>
        <summary class="px-4 py-2 text-sm font-semibold cursor-pointer" data-qa="load-dates-toggle">Load interim inspection dates</summary>
        <form method="POST" action="{{ route('corex.rental-inspections.planned-dates.store') }}" class="p-4 space-y-3" data-qa="load-dates-form"
              x-data="{ dates: {{ Js::from(old('dates', [''])) }}, addDate() { if (this.dates.length < 24) this.dates.push(''); } }">
            @csrf
            <div>
                <label class="text-xs font-medium">Tenancy</label>
                <select name="lease_id" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                    <option value="">Select a tenancy…</option>
                    @foreach($leaseOptions as $lease)
                        <option value="{{ $lease->id }}" @selected(old('lease_id') == $lease->id)>{{ $lease->property?->buildDisplayAddress() ?? 'Unknown property' }} — {{ $lease->tenantNames() ?: 'no tenant recorded' }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs font-medium">Dates (add as many as you need — a past date is allowed and simply shows as overdue)</label>
                <div class="space-y-2 mt-1">
                    <template x-for="(d, i) in dates" :key="i">
                        <div class="flex items-center gap-2">
                            <input type="date" x-model="dates[i]" :name="`dates[${i}]`" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                            <button type="button" x-show="dates.length > 1" @click="dates.splice(i, 1)" class="text-xs font-semibold px-2 py-1 rounded-md" style="color: var(--ds-crimson);">Remove</button>
                        </div>
                    </template>
                </div>
                <button type="button" @click="addDate()" class="corex-btn-outline text-xs mt-2">+ Add another date</button>
            </div>
            <div>
                <label class="text-xs font-medium">Note (optional)</label>
                <input type="text" name="note" maxlength="500" value="{{ old('note') }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
            <div class="flex justify-end">
                <button type="submit" class="corex-btn-primary text-xs">Load dates</button>
            </div>
        </form>
    </details>
    @endif

    <form method="GET" action="{{ route('corex.rental-inspections.due') }}" class="flex flex-wrap items-end gap-3">
        @if($archived)<input type="hidden" name="archived" value="1">@endif
        @if(request('scope'))<input type="hidden" name="scope" value="{{ request('scope') }}">@endif
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Search</label><br>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Property, tenant, agent or note" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Type</label><br>
            <select name="type" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach($typeLabels as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Status</label><br>
            <select name="status" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="open" @selected($currentStatus === 'open')>Open (overdue, due, upcoming, booked)</option>
                @foreach(['overdue', 'due', 'upcoming', 'booked', 'done', 'skipped', 'lease_ended'] as $s)
                    <option value="{{ $s }}" @selected($currentStatus === $s)>{{ $stateLabels[$s] }}</option>
                @endforeach
                <option value="all" @selected($currentStatus === 'all')>All</option>
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Due from</label><br>
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Due to</label><br>
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Agent</label><br>
            <select name="agent_id" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach($agentOptions as $agent)
                    <option value="{{ $agent->id }}" @selected(($filters['agent_id'] ?? '') == $agent->id)>{{ $agent->name }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="corex-btn-outline text-xs">Filter</button>
        @if(request()->hasAny(['q', 'status', 'type', 'date_from', 'date_to', 'agent_id']))
            <a href="{{ route('corex.rental-inspections.due', array_filter(['archived' => $archived ? 1 : null, 'scope' => request('scope')])) }}" class="corex-btn-outline text-xs">Clear</a>
        @endif
    </form>

    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm" data-qa="due-table">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('type') }}" style="color: var(--text-muted);">Type{{ $sortIndicator('type') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('property') }}" style="color: var(--text-muted);">Property{{ $sortIndicator('property') }}</a></th>
                    <th class="text-left px-4 py-2">Tenant(s)</th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('due') }}" style="color: var(--text-muted);">Due{{ $sortIndicator('due') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('status') }}" style="color: var(--text-muted);">Status{{ $sortIndicator('status') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('overdue') }}" style="color: var(--text-muted);">Days overdue{{ $sortIndicator('overdue') }}</a></th>
                    <th class="text-left px-4 py-2">Agent</th>
                    <th class="text-left px-4 py-2">Note</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                @php $pd = $row['planned']; @endphp
                <tr style="border-bottom: 1px solid var(--border); vertical-align: top;" data-qa="due-row-{{ $row['key'] }}">
                    <td class="px-4 py-2">{{ $typeLabels[$row['type']] ?? ucfirst($row['type']) }}</td>
                    <td class="px-4 py-2">{{ $row['property_address'] }}</td>
                    <td class="px-4 py-2">{{ $row['tenants'] ?: '—' }}</td>
                    <td class="px-4 py-2">{{ $row['due_on']?->format('Y-m-d') ?? '—' }}</td>
                    <td class="px-4 py-2"><span class="ds-badge {{ $badge($row['state']) }}">{{ $stateLabels[$row['state']] ?? $row['state'] }}</span></td>
                    <td class="px-4 py-2">{{ $row['days_overdue'] > 0 ? $row['days_overdue'] : '—' }}</td>
                    <td class="px-4 py-2">{{ $row['agent_name'] ?? '—' }}</td>
                    <td class="px-4 py-2 text-xs" style="color: var(--text-secondary); max-width: 18rem;">
                        {{ $row['source'] === 'due_list' ? $row['reason'] : ($row['note'] ?: '') }}
                        @if($row['reason'] && $row['source'] === 'planned') <br><em>Skipped: {{ $row['reason'] }}</em> @endif
                        @if($pd && $pd->trashed()) <br>Archived by {{ $pd->archivedBy?->name ?? 'unknown' }} — {{ $pd->deleted_at?->format('Y-m-d') }} @endif
                    </td>
                    <td class="px-4 py-2 text-right" style="min-width: 15rem;">
                        @if(!$pd)
                            {{-- Computed In/Out item: hand off to the ordinary start/schedule form, prefilled. --}}
                            @permission('rental_inspections.create')
                            <a href="{{ route('corex.rental-inspections.create', ['property_id' => $row['property_id'], 'lease_id' => $row['lease_id'], 'type' => $row['type']]) }}" class="corex-btn-primary text-xs" data-qa="due-schedule-{{ $row['key'] }}">Schedule / Start</a>
                            @endpermission
                        @elseif($pd->trashed())
                            @if($canManage)
                            <form method="POST" action="{{ route('corex.rental-inspections.planned-dates.restore', $pd->id) }}" class="inline">@csrf<button type="submit" class="corex-btn-outline text-xs">Restore</button></form>
                            @endif
                        @else
                            @if($pd->status === 'planned' && $row['state'] !== 'lease_ended')
                                @permission('rental_inspections.create')
                                <a href="{{ route('corex.rental-inspections.create', ['property_id' => $row['property_id'], 'lease_id' => $row['lease_id'], 'type' => $pd->type, 'scheduled_for' => $pd->planned_on->toDateString(), 'planned_date_id' => $pd->id]) }}" class="corex-btn-primary text-xs" data-qa="book-{{ $pd->id }}">Book from this date</a>
                                @endpermission
                            @elseif($pd->inspection)
                                <a href="{{ route('corex.rental-inspections.show', $pd->inspection) }}" class="corex-btn-outline text-xs">View inspection</a>
                            @endif
                            @if($canManage)
                                <details class="inline-block text-left" style="position: relative;">
                                    <summary class="corex-btn-outline text-xs cursor-pointer inline-block">Manage</summary>
                                    <div class="p-3 space-y-3 rounded-md" style="position: absolute; right: 0; z-index: 20; width: 18rem; background: var(--surface); border: 1px solid var(--border); box-shadow: 0 4px 12px rgba(0,0,0,.12);">
                                        <form method="POST" action="{{ route('corex.rental-inspections.planned-dates.update', $pd->id) }}" class="space-y-2">
                                            @csrf
                                            @if($pd->status === 'planned')
                                                <label class="text-xs font-medium">Move to</label>
                                                <input type="date" name="planned_on" value="{{ $pd->planned_on->toDateString() }}" class="w-full rounded-md px-2 py-1 text-xs" style="border: 1px solid var(--border);">
                                            @endif
                                            <label class="text-xs font-medium">Note</label>
                                            <input type="text" name="note" maxlength="500" value="{{ $pd->note }}" class="w-full rounded-md px-2 py-1 text-xs" style="border: 1px solid var(--border);">
                                            <button type="submit" class="corex-btn-outline text-xs">Save</button>
                                        </form>
                                        @if($pd->status === 'planned')
                                        <form method="POST" action="{{ route('corex.rental-inspections.planned-dates.skip', $pd->id) }}" class="space-y-2" style="border-top: 1px solid var(--border); padding-top: .5rem;">
                                            @csrf
                                            <label class="text-xs font-medium">Skip this date — why?</label>
                                            <input type="text" name="skipped_reason" maxlength="500" required class="w-full rounded-md px-2 py-1 text-xs" style="border: 1px solid var(--border);">
                                            <button type="submit" class="corex-btn-outline text-xs">Skip</button>
                                        </form>
                                        @elseif($pd->status === 'skipped')
                                        <form method="POST" action="{{ route('corex.rental-inspections.planned-dates.reopen', $pd->id) }}" style="border-top: 1px solid var(--border); padding-top: .5rem;">
                                            @csrf<button type="submit" class="corex-btn-outline text-xs">Plan this date again</button>
                                        </form>
                                        @endif
                                        <form method="POST" action="{{ route('corex.rental-inspections.planned-dates.archive', $pd->id) }}" style="border-top: 1px solid var(--border); padding-top: .5rem;">
                                            @csrf<button type="submit" class="text-xs font-semibold" style="color: var(--ds-crimson);">Archive this date</button>
                                        </form>
                                    </div>
                                </details>
                            @endif
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="9" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);" data-qa="due-empty">
                    @if($archived)
                        No archived dates in this view.
                    @elseif(!$hasAnything)
                        Nothing is due, and no interim dates have been loaded yet.
                        @if($canManage) Use "Load interim inspection dates" above when your agency wants an interim inspection. @endif
                    @else
                        Nothing matches this search or filter. Try clearing a filter.
                    @endif
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $rows->links() }}
</div>
@endsection
