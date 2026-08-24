@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page Header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <div class="text-xs uppercase tracking-wide" style="color: var(--text-faint);">Agent</div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $user->name }}</h1>
                <div class="text-xs" style="color: var(--text-muted);">{{ $user->email }}</div>
            </div>

            <div class="flex flex-wrap gap-2 items-end">
                <a href="{{ route('admin.listings.agents', ['status' => $status ?? 'active', 'source' => $source ?? 'propcon']) }}"
                   class="corex-btn-outline text-xs">&larr; Back</a>

                <form method="get" class="flex flex-wrap gap-2 items-end">
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Status</label>
                        <select name="status" class="rounded-md text-xs px-3 py-1.5"
                                style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                            @foreach(['active'=>'Active (contains active/for sale)','all'=>'All'] as $k=>$label)
                                <option value="{{ $k }}" @selected(($status ?? 'active') === $k)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Source</label>
                        <input name="source" value="{{ $source ?? 'propcon' }}"
                               class="w-40 rounded-md text-xs px-3 py-1.5 placeholder:opacity-50"
                               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);" />
                    </div>
                    <button class="corex-btn-primary text-xs">Apply</button>
                </form>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="ds-status-card" style="border-left-color: var(--border);">
            <div class="ds-label">Active listings</div>
            <div class="ds-value-xl">{{ number_format((int)($summary->listing_count ?? 0)) }}</div>
        </div>
        <div class="ds-status-card" style="border-left-color: var(--border);">
            <div class="ds-label">Total asking value</div>
            <div class="ds-value-lg">R {{ number_format(((int)($summary->total_value_cents ?? 0))/100, 0) }}</div>
        </div>
    </div>

    <div class="rounded-md overflow-hidden" style="background:var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
            <div class="text-sm font-semibold" style="color:var(--text-primary)">Listings</div>
            <div class="text-xs" style="color:var(--text-muted)">Showing {{ $listings->firstItem() ?? 0 }}–{{ $listings->lastItem() ?? 0 }} of {{ $listings->total() }}</div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr>
                        <th class="text-left px-4 py-3">Property</th>
                        <th class="text-left px-4 py-3">Status</th>
                        <th class="text-left px-4 py-3">Type</th>
                        <th class="text-left px-4 py-3">Mandate</th>
                        <th class="text-right px-4 py-3">Price</th>
                        <th class="text-left px-4 py-3">Modified</th>
                        <th class="text-left px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($listings as $l)
                        <tr>
                            <td class="px-4 py-3">
                                <div class="font-medium" style="color:var(--text-primary)">{{ $l->property ?? '(no address)' }}</div>
                                <div class="text-xs" style="color:var(--text-muted)">
                                    {{ $l->external_ref ?? $l->external_id ?? '' }}
                                    @if($l->region) · {{ $l->region }} @endif
                                </div>
                            </td>
                            <td class="px-4 py-3" style="color:var(--text-primary)">{{ $l->status ?? '' }}</td>
                            <td class="px-4 py-3" style="color:var(--text-primary)">{{ $l->type ?? '' }}</td>
                            <td class="px-4 py-3" style="color:var(--text-primary)">{{ $l->mandate ?? '' }}</td>
                            <td class="px-4 py-3 text-right" style="color:var(--text-primary)">
                                @if(!is_null($l->price_cents))
                                    R {{ number_format($l->price_cents/100, 0) }}
                                @endif
                            </td>
                            <td class="px-4 py-3" style="color:var(--text-secondary)">
                                @if($l->modified_at) {{ $l->modified_at->format('Y-m-d') }} @endif
                            </td>
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.listings.stock.agents.edit', $l) }}"
                                   class="inline-flex items-center px-3 py-1.5 rounded-md border text-xs font-semibold hover:bg-[var(--surface-2)]"
                                   style="border-color:var(--border); color:var(--text-primary)">
                                    Edit Agents
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-6 text-center" style="color:var(--text-muted)" colspan="6">
                                No listings found for this agent/filter.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-4 py-3 border-t" style="border-color:var(--border)">
            {{ $listings->links() }}
        </div>
    </div>

</div>
@endsection
