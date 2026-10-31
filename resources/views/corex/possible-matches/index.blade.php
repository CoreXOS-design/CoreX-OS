{{-- Possible matches — one line per pair CoreX could not be sure about. Spec: .ai/specs/property-identity.md §9. No Alpine. --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $f = $filters;
    $sortLink = function (string $key, string $label) use ($f) {
        $dir = ($f['sort'] === $key && $f['dir'] === 'asc') ? 'desc' : 'asc';
        $arrow = $f['sort'] === $key ? ($f['dir'] === 'asc' ? ' ▲' : ' ▼') : '';
        return '<a href="' . e(request()->fullUrlWithQuery(['sort' => $key, 'dir' => $dir, 'page' => null])) . '" class="no-underline" style="color:var(--text-muted);">' . e($label) . $arrow . '</a>';
    };
@endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Possible matches</h1>
        <p class="text-xs" style="color: var(--text-muted);">Pairs CoreX could not be sure are the same property. Press "Same property" to join them, or "Not the same" to tell CoreX to stop suggesting the pair. Nothing is ever deleted.
            {{ number_format((int) ($counts->pending ?? 0)) }} waiting · {{ number_format((int) ($counts->confirmed ?? 0)) }} joined · {{ number_format((int) ($counts->rejected ?? 0)) }} not the same.</p>
    </div>

    @include('corex.partials._hub-tabs', ['hub' => \App\Support\Navigation\Hubs\MarketIntelligenceTabs::class])

    @if(session('status'))
        <div class="rounded-md px-3 py-2 text-sm" style="background:#f0fdf4; color:#166534;">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-3 py-2 text-sm" style="background:#fef2f2; color:#991b1b;">{{ session('error') }}</div>
    @endif

    <form method="GET" action="{{ route('corex.possible-matches.index') }}" class="props-filter-panel rounded-md p-4 grid gap-3 md:grid-cols-6" style="background:var(--surface); border:1px solid var(--border);">
        <div class="md:col-span-2">
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Search</label>
            <input class="list-header-filter {{ !empty($f['q']) ? 'is-active' : '' }} w-full" type="text" name="q" value="{{ $f['q'] }}" placeholder="Street, number, suburb, complex, erf, advert number, record number">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Show</label>
            <select class="list-header-filter {{ $f['status'] !== 'pending' ? 'is-active' : '' }} w-full" name="status">
                @foreach(['pending' => 'Waiting for a decision', 'confirmed' => 'Joined', 'rejected' => 'Not the same', 'all' => 'Everything'] as $k => $label)
                    <option value="{{ $k }}" @selected($f['status'] === $k)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Kind of pair</label>
            <select class="list-header-filter {{ !empty($f['kind']) ? 'is-active' : '' }} w-full" name="kind">
                <option value="">All kinds</option>
                @foreach($kinds as $k => $label)
                    <option value="{{ $k }}" @selected($f['kind'] === $k)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Found by</label>
            <select class="list-header-filter {{ $f['source'] !== '' ? 'is-active' : '' }} w-full" name="source">
                <option value="">Any way</option>
                @foreach($sources as $s)
                    <option value="{{ $s }}" @selected($f['source'] === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                @endforeach
            </select>
        </div>
        <div class="md:col-span-3 flex items-end gap-2">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Found from</label>
                <input class="list-header-filter {{ !empty($f['from']) ? 'is-active' : '' }}" type="date" name="from" value="{{ $f['from'] }}">
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">to</label>
                <input class="list-header-filter {{ !empty($f['to']) ? 'is-active' : '' }}" type="date" name="to" value="{{ $f['to'] }}">
            </div>
        </div>
        <div class="md:col-span-3 flex items-end justify-end gap-2">
            <button type="submit" class="text-sm font-semibold px-5 py-2 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Apply</button>
            <a href="{{ route('corex.possible-matches.index') }}" class="text-sm px-4 py-2 rounded-md no-underline" style="border:1px solid var(--border); color:var(--text-primary);">Clear</a>
        </div>
    </form>

    <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wide" style="background:var(--surface-2); color:var(--text-muted); border-bottom:1px solid var(--border);">
                    <th class="px-3 py-2 font-semibold">One side</th>
                    <th class="px-3 py-2 font-semibold">{!! $sortLink('address', 'Other side') !!}</th>
                    <th class="px-3 py-2 font-semibold">{!! $sortLink('confidence', 'How sure') !!}</th>
                    <th class="px-3 py-2 font-semibold">{!! $sortLink('source', 'Found by') !!}</th>
                    <th class="px-3 py-2 font-semibold">Why</th>
                    <th class="px-3 py-2 font-semibold">{!! $sortLink('decided', 'Found') !!}</th>
                    <th class="px-3 py-2 font-semibold">Decide</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $r)
                    @php $p = $labels($r); $decided = $r->confirmed_at || $r->rejected_at; @endphp
                    <tr style="border-top:1px solid var(--border);">
                        <td class="px-3 py-2 align-top">
                            <div class="text-xs" style="color:var(--text-muted);">{{ $p['left_tag'] }}</div>
                            @if($p['left_url'])<a href="{{ $p['left_url'] }}" target="_blank" rel="noopener" class="no-underline" style="color:var(--text-primary);">{{ \Illuminate\Support\Str::limit($p['left'], 70) }}</a>@else<span style="color:var(--text-primary);">{{ \Illuminate\Support\Str::limit($p['left'], 70) }}</span>@endif
                        </td>
                        <td class="px-3 py-2 align-top">
                            <div class="text-xs" style="color:var(--text-muted);">{{ $p['right_tag'] }}</div>
                            <a href="{{ $p['right_url'] }}" target="_blank" rel="noopener" class="no-underline" style="color:var(--text-primary);">{{ \Illuminate\Support\Str::limit($p['right'], 70) }}</a>
                        </td>
                        <td class="px-3 py-2 align-top whitespace-nowrap"><span class="ds-badge {{ in_array($p['confidence'], ['Exact', 'High'], true) ? 'ds-badge-success' : 'ds-badge-warning' }}">{{ $p['confidence'] }}</span></td>
                        <td class="px-3 py-2 align-top whitespace-nowrap text-xs" style="color:var(--text-secondary);">{{ ucfirst(str_replace('_', ' ', (string) $r->strategy)) }}</td>
                        <td class="px-3 py-2 align-top text-xs" style="color:var(--text-muted); max-width:20rem;">{{ \Illuminate\Support\Str::limit((string) $r->reason, 110) }}</td>
                        <td class="px-3 py-2 align-top whitespace-nowrap text-xs" style="color:var(--text-muted);">{{ $r->decided_at ? \Illuminate\Support\Carbon::parse($r->decided_at)->format('j M Y') : '—' }}</td>
                        <td class="px-3 py-2 align-top whitespace-nowrap">
                            @if(! $decided)
                                <form method="POST" action="{{ route('corex.possible-matches.confirm', $r->id) }}" class="inline">@csrf
                                    <button type="submit" class="text-xs font-semibold px-3 py-1 rounded-md text-white" style="background:var(--brand-button,#0ea5e9); border:0; cursor:pointer;">{{ $p['kind'] === 'unit_split' ? 'Split off as its own property' : 'Same property' }}</button></form>
                                <form method="POST" action="{{ route('corex.possible-matches.reject', $r->id) }}" class="inline">@csrf
                                    <button type="submit" class="text-xs px-3 py-1 rounded-md" style="border:1px solid var(--border); background:var(--surface); color:var(--text-primary); cursor:pointer;">{{ $p['kind'] === 'unit_split' ? 'Keep together' : 'Not the same' }}</button></form>
                            @else
                                <span class="text-xs" style="color:var(--text-muted);">{{ $r->confirmed_at ? 'Joined' : 'Not the same' }}</span>
                                <form method="POST" action="{{ route('corex.possible-matches.restore', $r->id) }}" class="inline">@csrf
                                    <button type="submit" class="text-xs ml-2" style="color:var(--text-secondary); background:none; border:0; cursor:pointer;">Undo</button></form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-sm" style="color:var(--text-muted);">
                            @if($anyFilter)
                                Nothing matches these filters. Try a different search, or press Clear.
                            @else
                                Nothing to confirm — every match was certain.
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
