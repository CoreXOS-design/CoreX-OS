@extends('layouts.corex')

{{--
    .ai/specs/leases.md §7 — the Leases list screen. CRUD/list-screen floor
    (BUILD_STANDARD §1a-§1d): search (property address, tenant name), sort
    (default: end date ascending — soonest to expire first), filter (status,
    date range, property, branch), pagination, real empty state,
    OWN/BRANCH/AGENCY scoping enforced at the query layer (Lease::scopeVisibleTo()).
--}}

@php
    $sortLink = fn ($col) => route('corex.leases.index', array_merge(
        request()->except('page'),
        ['sort' => $col, 'direction' => ($sort === $col && $direction === 'asc') ? 'desc' : 'asc']
    ));
    $sortIndicator = fn ($col) => $sort === $col ? ($direction === 'asc' ? ' ▲' : ' ▼') : '';
    $statusBadgeClass = fn ($status) => match ($status) {
        'active' => 'ds-badge-success',
        'draft' => 'ds-badge-muted',
        'cancelled' => 'ds-badge-danger',
        default => 'ds-badge-info',
    };
@endphp

@section('content')
<div class="p-6 space-y-4" x-data="leasesPropertyFilter({{ Js::from([
    'propertyId' => $filteredProperty?->id ?? ($filters['property_id'] ?? ''),
    'propertyLabel' => $filteredProperty?->buildDisplayAddress() ?? '',
]) }})">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Leases</h1>
        @permission('leases.create')
        <a href="{{ route('corex.leases.create') }}" class="corex-btn-primary text-xs">New Lease</a>
        @endpermission
    </div>

    {{-- AT-439 Part 3 — shared rental list standard (status tiles + toolbar):
         .ai/specs/rentals-rebuild.md §1.1. First tile = Total. --}}
    @php
        $currentTile = null;
        if (($filters['status'] ?? '') === 'draft') { $currentTile = 'draft'; }
        elseif (($filters['status'] ?? '') === 'active') { $currentTile = 'active'; }
        elseif (($filters['status'] ?? '') === 'expired') { $currentTile = 'expired'; }
        elseif (($filters['status'] ?? '') === 'cancelled') { $currentTile = 'cancelled'; }
        elseif ($filters['expiring_soon'] ?? false) { $currentTile = 'expiring_soon'; }
        elseif (!($filters['status'] ?? null)) { $currentTile = 'total'; }

        $tileDefs = [
            'total' => ['label' => 'Total', 'params' => []],
            'draft' => ['label' => 'Draft', 'params' => ['status' => 'draft']],
            'active' => ['label' => 'Active', 'params' => ['status' => 'active']],
            'expired' => ['label' => 'Expired', 'params' => ['status' => 'expired']],
            'cancelled' => ['label' => 'Cancelled', 'params' => ['status' => 'cancelled']],
            'expiring_soon' => ['label' => 'Expiring soon', 'params' => ['expiring_soon' => 1]],
        ];
        $tileClearParams = ['status' => null, 'expiring_soon' => null, 'page' => null];
        $tileHref = fn ($key, $def) => route('corex.leases.index', array_merge(
            request()->except(array_keys($tileClearParams)),
            $key === 'total' || $currentTile === $key ? $tileClearParams : array_merge($tileClearParams, $def['params'])
        ));
        $tiles = collect($tileDefs)->map(fn ($def, $key) => [
            'key' => $key,
            'label' => $def['label'],
            'count' => $tileCounts[$key],
            'href' => $tileHref($key, $def),
            'active' => $currentTile === $key,
        ])->values()->all();
    @endphp
    <x-rental-list-controls
        :tiles="$tiles"
        :scope-options="$scopeOptions"
        :resolved-scope="$resolvedScope"
        route-name="corex.leases.index"
        :per-page="$perPage"
        :per-page-options="$perPageOptions"
        :archivable="true"
        :archived="$showArchived"
        :print-url="route('corex.leases.print-list', request()->query())"
        :export-xlsx-url="route('corex.leases.export', array_merge(request()->query(), ['format' => 'xlsx']))"
        :export-csv-url="route('corex.leases.export', array_merge(request()->query(), ['format' => 'csv']))"
    />

    <form method="GET" action="{{ route('corex.leases.index') }}" class="flex flex-wrap items-end gap-3">
        @if($showArchived)
            <input type="hidden" name="archived" value="1">
        @endif
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Search</label><br>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Property address or tenant name" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Status</label><br>
            <select name="status" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach(['draft', 'active', 'expired', 'cancelled'] as $s)
                    <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Agreement</label><br>
            <select name="agreement" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach(\App\Models\Lease::SIGNING_LABELS as $signingKey => $signingLabel)
                    <option value="{{ $signingKey }}" @selected(($filters['agreement'] ?? '') === $signingKey)>{{ $signingLabel }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Notice terms</label><br>
            <select name="notice_terms" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);" data-qa="lease-filter-notice-terms">
                <option value="">All</option>
                <option value="unconfirmed" @selected(($filters['notice_terms'] ?? '') === 'unconfirmed')>To check — not confirmed</option>
            </select>
        </div>
        <div class="relative">
            <label class="text-xs" style="color: var(--text-muted);">Property</label><br>
            {{-- Only properties with a lease visible to this user — never
                 every rental property (that's the create form's own job,
                 unaffected). --}}
            <input type="text" x-model="propertyQuery" @input.debounce.300ms="searchProperties()"
                   placeholder="Search properties by address…"
                   class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
            <input type="hidden" name="property_id" x-model="selectedPropertyId">
            <div class="absolute z-10 mt-1 rounded-md max-h-72 overflow-y-auto bg-white" style="border: 1px solid var(--border);" x-show="propertyResults.length" x-cloak>
                <template x-for="p in propertyResults" :key="p.id">
                    <button type="button" @click="selectProperty(p)" class="block w-full text-left px-3 py-2 text-xs hover:bg-slate-50">
                        <span x-text="p.label"></span>
                    </button>
                </template>
            </div>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">End date from</label><br>
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">End date to</label><br>
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <button type="submit" class="corex-btn-outline text-xs">Filter</button>
        @if(request()->hasAny(['q', 'status', 'agreement', 'notice_terms', 'property_id', 'date_from', 'date_to']))
            <a href="{{ route('corex.leases.index') }}" class="corex-btn-outline text-xs">Clear</a>
        @endif
    </form>

    <script>
    function leasesPropertyFilter(old) {
        old = old || {};
        return {
            propertyQuery: old.propertyLabel || '', propertyResults: [],
            selectedPropertyId: old.propertyId || '',
            async searchProperties() {
                if (this.propertyQuery.length < 2) { this.propertyResults = []; return; }
                const res = await fetch('{{ route('corex.leases.search-properties', ['archived' => $showArchived ? 1 : 0]) }}&q=' + encodeURIComponent(this.propertyQuery));
                this.propertyResults = await res.json();
            },
            selectProperty(p) {
                this.selectedPropertyId = p.id;
                this.propertyResults = [];
                this.propertyQuery = p.label;
            },
        };
    }
    </script>

    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('property') }}" style="color: var(--text-muted);">Property{{ $sortIndicator('property') }}</a></th>
                    <th class="text-left px-4 py-2">Tenant(s)</th>
                    <th class="text-left px-4 py-2">Agents</th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('status') }}" style="color: var(--text-muted);">Status{{ $sortIndicator('status') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('start_date') }}" style="color: var(--text-muted);">Start{{ $sortIndicator('start_date') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('end_date') }}" style="color: var(--text-muted);">End{{ $sortIndicator('end_date') }}</a></th>
                    <th class="text-left px-4 py-2">Rent</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($leases as $lease)
                <tr style="border-bottom: 1px solid var(--border);" data-qa="lease-row-{{ $lease->id }}">
                    <td class="px-4 py-2">{{ $lease->property?->buildDisplayAddress() ?? 'Unknown property' }}{{ $lease->property?->trashed() ? ' (archived)' : '' }}</td>
                    <td class="px-4 py-2">
                        <div>{{ $lease->tenantNames() }}</div>
                        @if($lease->landlordContacts()->isNotEmpty())
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $lease->landlordNames() }}</div>
                        @elseif($lease->property && !$lease->property->trashed())
                            {{-- A trashed property's own show route 404s under default route-model
                                 binding — never offer a dead-end "No landlord linked" link. --}}
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                <a href="{{ route('corex.properties.show', ['property' => $lease->property_id, 'tab' => 'contacts']) }}" class="underline">No landlord linked</a>
                            </div>
                        @else
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">No landlord linked</div>
                        @endif
                    </td>
                    {{-- leases.md §17 — the lease's own owner's agent / tenant's agent (a lease not yet back-filled shows a dash). --}}
                    <td class="px-4 py-2" data-qa="lease-agents-cell">
                        <div class="text-xs"><span style="color: var(--text-muted);">Owner:</span> {{ $lease->ownerAgent?->name ?: '—' }}</div>
                        <div class="text-xs"><span style="color: var(--text-muted);">Tenant:</span> {{ $lease->tenantAgent?->name ?: '—' }}</div>
                    </td>
                    <td class="px-4 py-2">
                        @if($showArchived)
                            <span class="ds-badge ds-badge-muted">Archived {{ $lease->deleted_at?->format('Y-m-d') }}</span>
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                Was {{ $lease->archived_from_status ?: $lease->status }}@if($lease->archive_reason) — {{ \Illuminate\Support\Str::limit($lease->archive_reason, 80) }}@endif
                            </div>
                        @else
                            <span class="ds-badge {{ $statusBadgeClass($lease->status) }}">{{ ucfirst($lease->status) }}</span>
                            @if($lease->signingStatusLabel())
                                <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $lease->signingStatusLabel() }}</div>
                            @endif
                        @endif
                    </td>
                    <td class="px-4 py-2">{{ $lease->start_date?->format('Y-m-d') }}</td>
                    <td class="px-4 py-2">{{ $lease->end_date?->format('Y-m-d') ?? ($lease->is_month_to_month ? 'Month-to-month' : '—') }}</td>
                    <td class="px-4 py-2">R{{ number_format((float) $lease->rental_amount, 2) }}</td>
                    <td class="px-4 py-2 text-right">
                        @if($showArchived)
                            {{-- Bringing a draft/active tenancy back needs the cancel permission (leases.md §3.8). --}}
                            @if(auth()->user()->hasPermission('leases.create') && (!in_array($lease->archived_from_status ?: $lease->status, ['draft', 'active'], true) || auth()->user()->hasPermission('leases.cancel')))
                            <form method="POST" action="{{ route('corex.leases.restore', $lease->id) }}" class="inline" onsubmit="return confirm('Restore this lease?');">
                                @csrf
                                <button type="submit" class="corex-btn-outline text-xs" data-test="lease-restore-button">Restore</button>
                            </form>
                            @endif
                        @else
                            <a href="{{ route('corex.leases.show', $lease) }}" class="corex-btn-outline text-xs">View</a>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
                    @if($showArchived)
                        No archived leases on this agency.
                    @elseif(!$hasAnyLeases)
                        No leases yet on this agency. Every rental tenancy starts here — click "New Lease" to add the first one.
                    @else
                        No leases match this search or filter. Try clearing a filter.
                    @endif
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $leases->links() }}
</div>
@endsection
