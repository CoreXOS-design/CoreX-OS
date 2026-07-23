@extends('layouts.corex')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <div class="text-xs uppercase tracking-wide text-white/60">Agent</div>
                <h2 class="text-xl font-bold text-white leading-tight">{{ $user->name }}</h2>
                <div class="text-sm text-white/60">{{ $user->email }}</div>
            </div>

            <div class="flex flex-wrap gap-2 items-end">
                <a href="{{ route('admin.listings.agents', ['status' => $status ?? 'active', 'source' => $source ?? 'propcon']) }}"
                   class="corex-btn-outline text-sm">&larr; Back</a>

                <form method="get" class="flex flex-wrap gap-2 items-end">
                    <div>
                        <label class="block text-xs text-white/60 mb-1">Status</label>
                        <select name="status" class="rounded-lg border-0 bg-white/10 text-white text-sm px-3 py-1.5 [&>option]:text-slate-900">
                            @foreach(['active'=>'Active (contains active/for sale)','all'=>'All'] as $k=>$label)
                                <option value="{{ $k }}" @selected(($status ?? 'active') === $k)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-white/60 mb-1">Source</label>
                        <input name="source" value="{{ $source ?? 'propcon' }}" class="w-40 rounded-lg border-0 bg-white/10 text-white text-sm px-3 py-1.5 placeholder:text-white/40" />
                    </div>
                    <button class="corex-btn-primary text-sm">Apply</button>
                </form>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="ds-status-card">
            <div class="ds-label">Active listings</div>
            <div class="ds-value-xl">{{ number_format((int)($summary->listing_count ?? 0)) }}</div>
        </div>
        <div class="ds-status-card">
            <div class="ds-label">Total asking value</div>
            <div class="ds-value-lg">R {{ number_format(((int)($summary->total_value_cents ?? 0))/100, 0) }}</div>
        </div>
    </div>

    <div class="rounded-2xl border overflow-hidden" style="background:var(--surface); border-color:var(--border)">
        <div class="px-4 py-3 border-b flex items-center justify-between" style="border-color:var(--border)">
            <div class="text-sm font-medium" style="color:var(--text-primary)">Listings</div>
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
                                   class="inline-flex items-center px-3 py-1.5 rounded-lg border hover:bg-[var(--surface-2)]"
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
