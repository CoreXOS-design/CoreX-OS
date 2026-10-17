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
    $statusTone = fn ($status) => match ($status) {
        'active' => '--ds-green',
        'draft' => '--text-muted',
        'cancelled' => '--ds-crimson',
        default => '--brand-icon',
    };
    $badge = fn (string $tone) => match ($tone) {
        '--ds-green' => 'ds-badge-success',
        '--ds-amber' => 'ds-badge-warning',
        '--ds-crimson' => 'ds-badge-danger',
        '--brand-icon' => 'ds-badge-info',
        default => 'ds-badge-muted',
    };
@endphp

@section('corex-content')
<div class="w-full space-y-4" x-data="leasesPropertyFilter({{ Js::from([
    'propertyId' => $filteredProperty?->id ?? ($filters['property_id'] ?? ''),
    'propertyLabel' => $filteredProperty?->buildDisplayAddress() ?? '',
]) }})">
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Leases</h1>
                <p class="text-xs" style="color: var(--text-muted);">Every rental tenancy, soonest to expire first, and where each stands.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @permission('leases.create')
                <a href="{{ route('corex.leases.create') }}" class="corex-btn-primary text-xs">New Lease</a>
                @endpermission
            </div>
        </div>
    </div>

    {{-- AT-439 Part 3 — shared rental list standard (status tiles + toolbar):
         .ai/specs/rentals-rebuild.md §1.1. First tile = Total. --}}
    @php
        $currentTile = null;
        if (($filters['status'] ?? '') === 'draft') { $currentTile = 'draft'; }
        elseif (($filters['status'] ?? '') === 'signed_awaiting_start') { $currentTile = 'signed_awaiting_start'; }
        elseif (($filters['status'] ?? '') === 'active') { $currentTile = 'active'; }
        elseif (($filters['status'] ?? '') === 'expired') { $currentTile = 'expired'; }
        elseif (($filters['status'] ?? '') === 'cancelled') { $currentTile = 'cancelled'; }
        elseif ($filters['expiring_soon'] ?? false) { $currentTile = 'expiring_soon'; }
        elseif (!($filters['status'] ?? null)) { $currentTile = 'total'; }

        $tileDefs = [
            'total' => ['label' => 'Total', 'params' => []],
            'draft' => ['label' => 'Draft', 'params' => ['status' => 'draft']],
            // a signed renewal waiting for its start date is NOT a draft (its page says "Signed - starts <date>") - the tile counts it on its own
            'signed_awaiting_start' => ['label' => 'Signed - starts later', 'params' => ['status' => 'signed_awaiting_start']],
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

    <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
    <form method="GET" action="{{ route('corex.leases.index') }}" class="flex flex-wrap items-end gap-3">
        @if($showArchived)
            <input type="hidden" name="archived" value="1">
        @endif
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Property address or tenant name" class="list-header-filter text-sm {{ filled($filters['q'] ?? '') ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Status</label>
            <select name="status" class="list-header-filter text-sm {{ filled($filters['status'] ?? null) ? 'is-active' : '' }}">
                <option value="">All</option>
                @foreach(['draft', 'signed_awaiting_start', 'active', 'expired', 'cancelled'] as $s)
                    <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ $s === 'signed_awaiting_start' ? 'Signed - starts later' : ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Agreement</label>
            <select name="agreement" class="list-header-filter text-sm {{ filled($filters['agreement'] ?? null) ? 'is-active' : '' }}">
                <option value="">All</option>
                @foreach(\App\Models\Lease::SIGNING_LABELS as $signingKey => $signingLabel)
                    <option value="{{ $signingKey }}" @selected(($filters['agreement'] ?? '') === $signingKey)>{{ $signingLabel }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Notice terms</label>
            <select name="notice_terms" class="list-header-filter text-sm {{ filled($filters['notice_terms'] ?? null) ? 'is-active' : '' }}" data-qa="lease-filter-notice-terms">
                <option value="">All</option>
                <option value="unconfirmed" @selected(($filters['notice_terms'] ?? '') === 'unconfirmed')>To check — not confirmed</option>
            </select>
        </div>
        <div class="relative flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Property</label>
            {{-- Only properties with a lease visible to this user — never
                 every rental property (that's the create form's own job,
                 unaffected). --}}
            <input type="text" x-model="propertyQuery" @input.debounce.300ms="searchProperties()"
                   placeholder="Search properties by address…"
                   class="list-header-filter text-sm" :class="{ 'is-active': propertyQuery }">
            <input type="hidden" name="property_id" x-model="selectedPropertyId">
            <div class="absolute z-10 mt-1 rounded-md max-h-72 overflow-y-auto" style="top:100%; background: var(--surface); border: 1px solid var(--border);" x-show="propertyResults.length" x-cloak>
                <template x-for="p in propertyResults" :key="p.id">
                    <button type="button" @click="selectProperty(p)" class="list-row-hover block w-full text-left px-3 py-2 text-xs" style="color:var(--text-primary);">
                        <span x-text="p.label"></span>
                    </button>
                </template>
            </div>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">End date from</label>
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="list-header-filter text-sm {{ filled($filters['date_from'] ?? '') ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">End date to</label>
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="list-header-filter text-sm {{ filled($filters['date_to'] ?? '') ? 'is-active' : '' }}">
        </div>
        <button type="submit" class="corex-btn-primary text-sm" style="height:2.25rem;">Filter</button>
        @if(request()->hasAny(['q', 'status', 'agreement', 'notice_terms', 'property_id', 'date_from', 'date_to']))
            <a href="{{ route('corex.leases.index') }}" class="corex-btn-outline text-sm" style="height:2.25rem;">Clear</a>
        @endif
    </form>
    </div>

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

    {{-- .ai/specs/rental-notices.md §6 - tick leases and issue breach notices for all of them from one screen --}}
    @feature('rental-notices')
    @permission('rental_breaches.manage')
        <form id="leases-bulk-form" method="GET" action="{{ route('corex.breaches.bulk') }}" class="flex items-center gap-3 text-xs" data-qa="leases-bulk-bar">
            <button type="submit" class="corex-btn-outline text-xs">Breach notice for ticked leases</button>
            <span style="color: var(--text-muted);">Tick the leases (running leases only), then choose this. You fill in the amount owing on the next screen.</span>
        </form>
    @endpermission
    @endfeature

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2); color: var(--text-muted); border-bottom: 1px solid var(--border);">
                    @permission('rental_breaches.manage')<th class="px-3 py-3 w-8"><input type="checkbox" aria-label="Tick all running leases" onclick="document.querySelectorAll('input[name=&quot;leases[]&quot;]').forEach(c => c.checked = this.checked)"></th>@endpermission
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('property') }}">Property{{ $sortIndicator('property') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Tenant(s)</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Agents</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('status') }}">Status{{ $sortIndicator('status') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('start_date') }}">Start{{ $sortIndicator('start_date') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('end_date') }}">End{{ $sortIndicator('end_date') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Rent</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($leases as $lease)
                <tr style="border-bottom: 1px solid var(--border); color: var(--text-secondary);" data-qa="lease-row-{{ $lease->id }}">
                    @permission('rental_breaches.manage')<td class="px-3 py-3">@if($lease->status === 'active' && !$lease->trashed())<input type="checkbox" name="leases[]" value="{{ $lease->id }}" form="leases-bulk-form" aria-label="Tick this lease">@endif</td>@endpermission
                    <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $lease->property?->buildDisplayAddress() ?? 'Unknown property' }}{{ $lease->property?->trashed() ? ' (archived)' : '' }}</td>
                    <td class="px-4 py-3">
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
                    <td class="px-4 py-3" data-qa="lease-agents-cell">
                        <div class="text-xs"><span style="color: var(--text-muted);">Owner:</span> {{ $lease->ownerAgent?->name ?: '—' }}</div>
                        <div class="text-xs"><span style="color: var(--text-muted);">Tenant:</span> {{ $lease->tenantAgent?->name ?: '—' }}</div>
                    </td>
                    <td class="px-4 py-3">
                        @if($showArchived)
                            <span class="ds-badge {{ $badge('--text-muted') }}">Archived {{ $lease->deleted_at?->format('Y-m-d') }}</span>
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                Was {{ $lease->archived_from_status ?: $lease->status }}@if($lease->archive_reason) — {{ \Illuminate\Support\Str::limit($lease->archive_reason, 80) }}@endif
                            </div>
                        @else
                            <span class="ds-badge {{ $badge($statusTone($lease->status)) }}">{{ $lease->displayStatus() }}</span>
                            @if($lease->signingStatusLabel())
                                <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $lease->signingStatusLabel() }}</div>
                            @endif
                        @endif
                    </td>
                    <td class="px-4 py-3">{{ $lease->start_date?->format('Y-m-d') }}</td>
                    <td class="px-4 py-3">{{ $lease->end_date?->format('Y-m-d') ?? ($lease->is_month_to_month ? 'Month-to-month' : '—') }}</td>
                    <td class="px-4 py-3">R{{ number_format((float) $lease->rental_amount, 2) }}</td>
                    <td class="px-4 py-3 text-right">
                        @if($showArchived)
                            {{-- Bringing a draft/active tenancy back needs the cancel permission (leases.md §3.8). --}}
                            @if(auth()->user()->hasPermission('leases.create') && (!in_array($lease->archived_from_status ?: $lease->status, ['draft', 'active'], true) || auth()->user()->hasPermission('leases.cancel')))
                            <form method="POST" action="{{ route('corex.leases.restore', $lease->id) }}" class="inline" data-confirm="Restore this lease?">
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
                <tr><td colspan="9" class="px-6 py-12 text-center text-sm" style="color: var(--text-muted);">
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
        @if($leases->hasPages())
        <div class="px-5 py-4" style="border-top:1px solid var(--border);">
            {{ $leases->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
