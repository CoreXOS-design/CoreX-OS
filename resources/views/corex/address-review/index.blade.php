{{-- Structured address matching — the admin-only "could not read this address" list. Spec: .ai/specs/structured-address-matching.md §10. No Alpine. --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $f = $filters;
    $sortLink = function (string $key, string $label) use ($f) {
        $dir = ($f['sort'] === $key && $f['dir'] === 'asc') ? 'desc' : 'asc';
        $arrow = $f['sort'] === $key ? ($f['dir'] === 'asc' ? ' ▲' : ' ▼') : '';
        return '<a href="' . e(request()->fullUrlWithQuery(['sort' => $key, 'dir' => $dir, 'page' => null])) . '" class="no-underline" style="color:var(--text-muted);">' . e($label) . $arrow . '</a>';
    };
    $statusLabel = ['review' => 'Needs a look', 'unparseable' => 'Nothing readable', 'dismissed' => 'Dismissed', 'manual' => 'Fixed by an admin'];
    $statusTone = ['review' => 'ds-badge-warning', 'unparseable' => 'ds-badge-danger', 'dismissed' => 'ds-badge-muted', 'manual' => 'ds-badge-success'];
@endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Address Review</h1>
                <p class="text-xs" style="color: var(--text-muted);">Addresses CoreX could not read with confidence — fix them so they match the right property. Nothing here is ever deleted.</p>
            </div>
            @if($canSettings)
                <a href="{{ route('settings.prospecting.address-matching.edit') }}" class="corex-btn-outline text-xs">Address matching settings →</a>
            @endif
        </div>
    </div>

    {{-- .ai/specs/real-estate-menu-tabs.md - Address review is a tab of Real Estate > Market intelligence --}}
    @include('corex.partials._hub-tabs', ['hub' => \App\Support\Navigation\Hubs\MarketIntelligenceTabs::class])

    @if(session('status'))
        <div class="rounded-md px-3 py-2 text-sm" style="background:#f0fdf4; color:#166534;">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-3 py-2 text-sm" style="background:#fef2f2; color:#991b1b;">{{ session('error') }}</div>
    @endif

    <form method="GET" action="{{ route('corex.address-review.index') }}" class="props-filter-panel rounded-md p-4 grid gap-3 md:grid-cols-6" style="background:var(--surface); border:1px solid var(--border);">
        <div class="md:col-span-2">
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Search</label>
            <input class="list-header-filter {{ (!empty($f['q'])) ? 'is-active' : '' }} w-full" type="text" name="q" value="{{ $f['q'] }}" placeholder="Street, number, suburb, complex, erf, record number"
                  >
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Show</label>
            <select class="list-header-filter {{ (($f['status'] ?? 'open') !== 'open') ? 'is-active' : '' }} w-full" name="status">
                @foreach(['open' => 'Needs attention', 'review' => 'Needs a look', 'unparseable' => 'Nothing readable', 'manual' => 'Fixed by an admin', 'dismissed' => 'Dismissed', 'all' => 'Everything'] as $k => $label)
                    <option value="{{ $k }}" @selected($f['status'] === $k)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Reason</label>
            <select class="list-header-filter {{ (!empty($f['reason'])) ? 'is-active' : '' }} w-full" name="reason">
                <option value="">All reasons</option>
                @foreach($reasons as $k => $label)
                    <option value="{{ $k }}" @selected($f['reason'] === $k)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Record type</label>
            <select class="list-header-filter {{ (!empty($f['kind'])) ? 'is-active' : '' }} w-full" name="kind">
                <option value="">All record types</option>
                <option value="property" @selected($f['kind'] === 'property')>Properties</option>
                <option value="tracked" @selected($f['kind'] === 'tracked')>Captured / tracked</option>
            </select>
        </div>
        <div class="md:col-span-3 flex items-end gap-2">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Updated from</label>
                <input class="list-header-filter {{ (!empty($f['from'])) ? 'is-active' : '' }}" type="date" name="from" value="{{ $f['from'] }}">
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">to</label>
                <input class="list-header-filter {{ (!empty($f['to'])) ? 'is-active' : '' }}" type="date" name="to" value="{{ $f['to'] }}">
            </div>
        </div>
        <div class="md:col-span-3 flex items-end justify-end gap-2">
            <button type="submit" class="text-sm font-semibold px-5 py-2 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Apply</button>
            <a href="{{ route('corex.address-review.index') }}" class="text-sm px-4 py-2 rounded-md no-underline" style="border:1px solid var(--border); color:var(--text-primary);">Clear</a>
        </div>
    </form>

    <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wide" style="background:var(--surface-2); color:var(--text-muted); border-bottom:1px solid var(--border);">
                    <th class="px-4 py-3 font-semibold">{!! $sortLink('kind', 'Record') !!}</th>
                    <th class="px-4 py-3 font-semibold">{!! $sortLink('address', 'Address as held') !!}</th>
                    <th class="px-4 py-3 font-semibold">{!! $sortLink('suburb', 'Suburb') !!}</th>
                    <th class="px-4 py-3 font-semibold">{!! $sortLink('status', 'Status') !!}</th>
                    <th class="px-4 py-3 font-semibold">Why</th>
                    <th class="px-4 py-3 font-semibold">{!! $sortLink('updated', 'Last updated') !!}</th>
                    <th class="px-4 py-3 font-semibold">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $r)
                    @php
                        $line = trim(trim((string) $r->street_number) . ' ' . trim((string) $r->street_name));
                        $line = $line !== '' ? $line : (trim((string) $r->complex_name) !== '' ? $r->complex_name : ($r->erf_number ? 'Erf ' . $r->erf_number : '(no address held)'));
                        $open = $r->kind === 'property' ? route('corex.properties.show', $r->id) : route('corex.tracked-properties.show', $r->id);
                    @endphp
                    <tr style="border-top:1px solid var(--border);">
                        <td class="px-4 py-3 align-top whitespace-nowrap" style="color:var(--text-secondary);">{{ $r->kind === 'property' ? 'Property' : 'Captured' }} #{{ $r->id }}</td>
                        <td class="px-4 py-3 align-top" style="color:var(--text-primary);">{{ \Illuminate\Support\Str::limit($line, 80) }}</td>
                        <td class="px-4 py-3 align-top" style="color:var(--text-secondary);">{{ $r->suburb ?: '—' }}</td>
                        <td class="px-4 py-3 align-top whitespace-nowrap"><span class="ds-badge {{ $statusTone[$r->status] ?? 'ds-badge-muted' }}">{{ $statusLabel[$r->status] ?? $r->status }}</span></td>
                        <td class="px-4 py-3 align-top text-xs" style="color:var(--text-muted); max-width:22rem;">{{ $r->note ?: '—' }}</td>
                        <td class="px-4 py-3 align-top whitespace-nowrap text-xs" style="color:var(--text-muted);">{{ $r->updated_at ? \Illuminate\Support\Carbon::parse($r->updated_at)->format('j M Y') : '—' }}</td>
                        <td class="px-4 py-3 align-top whitespace-nowrap">
                            <a href="{{ $open }}" target="_blank" rel="noopener" class="text-xs no-underline mr-2" style="color:var(--brand-icon,#2563eb);">Open</a>
                            <a href="{{ route('corex.address-review.edit', ['kind' => $r->kind, 'id' => $r->id]) }}" class="text-xs font-semibold no-underline mr-2" style="color:var(--brand-icon,#2563eb);">Fix</a>
                            @if($r->status === 'dismissed')
                                <form method="POST" action="{{ route('corex.address-review.restore', ['kind' => $r->kind, 'id' => $r->id]) }}" class="inline">
                                    @csrf <button type="submit" class="text-xs" style="color:var(--text-secondary); background:none; border:0; cursor:pointer;">Restore</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('corex.address-review.dismiss', ['kind' => $r->kind, 'id' => $r->id]) }}" class="inline">
                                    @csrf <button type="submit" class="text-xs" style="color:var(--text-secondary); background:none; border:0; cursor:pointer;">Dismiss</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-sm" style="color:var(--text-muted);">
                            @if($anyFilter)
                                Nothing matches these filters. Try a different search, or press Clear.
                            @else
                                Nothing needs a look — every address CoreX holds could be read. Addresses it cannot read will appear here.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $rows->links() }}</div>
</div>
@endsection
