@extends('layouts.corex')

{{--
    .ai/specs/rental-work-orders.md §14 (AT-442) — Job Cards list. CRUD/
    list-screen floor (BUILD_STANDARD §1a-§1d): search (property, tenant,
    crew member, title), sort (due_at default ascending; scheduled_at,
    status, property), filter (crew member, status, date range), own/
    branch/all scope switch (default widest permitted), pagination, real
    empty state, print list.
--}}

@section('content')
<div class="p-6 space-y-4" x-data="rentalJobCardsPropertyFilter({{ Js::from([
    'propertyId' => $filteredProperty?->id ?? ($filters['property_id'] ?? ''),
    'propertyLabel' => $filteredProperty?->buildDisplayAddress() ?? '',
]) }})">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Job Cards</h1>
        <div class="flex items-center gap-2">
            @permission('rental_job_cards.create')
            <a href="{{ route('corex.rental-job-cards.create', request()->only(['property_id', 'lease_id'])) }}" class="corex-btn-primary text-xs">New Job Card</a>
            @endpermission
            <a href="{{ route('corex.rental-job-cards.print-list', request()->query()) }}" target="_blank" class="corex-btn-outline text-xs">Print list</a>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46;">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    @if(count($scopeOptions) > 1)
    <div class="flex items-center gap-2">
        <span class="text-xs font-medium" style="color: var(--text-secondary);">Showing:</span>
        <div class="inline-flex rounded-md overflow-hidden" style="border: 1px solid var(--border);">
            @foreach($scopeOptions as $i => $sc)
            <a href="{{ route('corex.rental-job-cards.index', array_merge(request()->except(['scope', 'page']), ['scope' => $sc])) }}"
               class="px-3 py-1.5 text-xs font-semibold"
               style="{{ $i > 0 ? 'border-left: 1px solid var(--border);' : '' }} {{ $resolvedScope === $sc ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">{{ ucfirst($sc) }}</a>
            @endforeach
        </div>
    </div>
    @endif

    <div class="grid grid-cols-7 gap-2">
        @foreach([
            ['key' => null, 'label' => 'Total', 'value' => $tileCounts['total']],
            ['key' => 'draft', 'label' => 'Draft', 'value' => $tileCounts['draft']],
            ['key' => 'quoted', 'label' => 'Quoted', 'value' => $tileCounts['quoted']],
            ['key' => 'scheduled', 'label' => 'Scheduled', 'value' => $tileCounts['scheduled']],
            ['key' => 'in_progress', 'label' => 'In progress', 'value' => $tileCounts['in_progress']],
            ['key' => 'overdue', 'label' => 'Overdue', 'value' => $tileCounts['overdue']],
            ['key' => 'completed', 'label' => 'Completed', 'value' => $tileCounts['completed']],
        ] as $tile)
            @php
                $tileParams = $tile['key'] === 'overdue'
                    ? array_merge(request()->except(['page', 'status', 'overdue']), ['overdue' => 1])
                    : array_merge(request()->except(['page', 'status', 'overdue']), $tile['key'] ? ['status' => $tile['key']] : []);
                $isActive = $tile['key'] === 'overdue' ? request()->boolean('overdue') : (request('status') === $tile['key'] && !request()->boolean('overdue'));
            @endphp
            <a href="{{ route('corex.rental-job-cards.index', $tileParams) }}"
               class="pstat-v2 px-3.5 py-2 flex items-center justify-between gap-3 no-underline cursor-pointer"
               style="{{ $isActive ? 'border-color:color-mix(in srgb, var(--brand-icon,#6366f1) 40%, transparent);background:color-mix(in srgb, var(--brand-icon,#6366f1) 10%, var(--surface));' : '' }}">
                <div class="min-w-0">
                    <div class="text-lg font-bold leading-none tabular-nums" style="color:var(--text-primary);">{{ number_format((int) $tile['value']) }}</div>
                    <div class="text-[0.6875rem] font-medium mt-0.5 uppercase tracking-wider" style="color:var(--text-muted);">{{ $tile['label'] }}</div>
                </div>
            </a>
        @endforeach
    </div>

    <form method="GET" action="{{ route('corex.rental-job-cards.index') }}" class="flex flex-wrap items-end gap-3">
        <input type="hidden" name="scope" value="{{ $resolvedScope }}">
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Search</label><br>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Property, tenant, crew, title" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Crew member</label><br>
            <select name="assigned_user_id" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach($crew as $c)
                    <option value="{{ $c->id }}" @selected(($filters['assigned_user_id'] ?? null) == $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="relative">
            <label class="text-xs" style="color: var(--text-muted);">Property</label><br>
            {{-- Only properties with a job card visible to this user — never
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
            <label class="text-xs" style="color: var(--text-muted);">From</label><br>
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">To</label><br>
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <label class="flex items-center gap-2 text-xs">
            <input type="checkbox" name="archived" value="1" @checked($showArchived)> Show archived
        </label>
        <button type="submit" class="corex-btn-outline text-xs">Filter</button>
        @if(request()->hasAny(['q', 'status', 'assigned_user_id', 'property_id', 'date_from', 'date_to', 'overdue']))
            <a href="{{ route('corex.rental-job-cards.index') }}" class="corex-btn-outline text-xs">Clear</a>
        @endif
    </form>

    <script>
    function rentalJobCardsPropertyFilter(old) {
        old = old || {};
        return {
            propertyQuery: old.propertyLabel || '', propertyResults: [],
            selectedPropertyId: old.propertyId || '',
            async searchProperties() {
                if (this.propertyQuery.length < 2) { this.propertyResults = []; return; }
                const res = await fetch('{{ route('corex.rental-job-cards.search-properties', ['archived' => $showArchived ? 1 : 0]) }}&q=' + encodeURIComponent(this.propertyQuery));
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

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2); color: var(--text-muted);">
                    <th class="text-left px-4 py-2 font-medium"><a href="{{ route('corex.rental-job-cards.index', array_merge(request()->except('page'), ['sort' => 'property'])) }}">Property</a></th>
                    <th class="text-left px-4 py-2 font-medium">Title</th>
                    <th class="text-left px-4 py-2 font-medium">Tenant</th>
                    <th class="text-left px-4 py-2 font-medium">Crew</th>
                    <th class="text-left px-4 py-2 font-medium"><a href="{{ route('corex.rental-job-cards.index', array_merge(request()->except('page'), ['sort' => 'status'])) }}">Status</a></th>
                    <th class="text-left px-4 py-2 font-medium"><a href="{{ route('corex.rental-job-cards.index', array_merge(request()->except('page'), ['sort' => 'due_at'])) }}">Due</a></th>
                    <th class="text-right px-4 py-2 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($jobCards as $jc)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-2">{{ $jc->property?->buildDisplayAddress() ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $jc->title }}</td>
                        <td class="px-4 py-2">{{ $jc->lease?->tenantNames() ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $jc->assignedUser?->name ?? '—' }}</td>
                        <td class="px-4 py-2"><span class="ds-badge ds-badge-info">{{ ucfirst(str_replace('_', ' ', $jc->status)) }}</span></td>
                        <td class="px-4 py-2">{{ $jc->due_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td class="px-4 py-2 text-right">
                            <a href="{{ route('corex.rental-job-cards.show', $jc->id) }}" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">Open</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
                            @if(!$hasAny)
                                No job cards yet — raise one from a property's Work Order button or from an approved fault report.
                            @else
                                No job cards match this filter.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $jobCards->links() }}
</div>
@endsection
