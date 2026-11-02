{{--
    A belt tile's list (spec 23.13): the properties at one station that has no prospect stage yet (claims without an owner, owners not yet contacted, presentations
    waiting for an answer, evaluations owed feedback, draft / active / live stock). Search (address), sort (oldest first, or address), 25 a page, a real empty state.
    Inputs: $stationList, $stationKey, $stationFilters, $viewingOwn, $agentId, $track.
--}}
@php
    $L = $stationList;
    $rowsP = $L['rows'];
    $f = $stationFilters;
    $keep = array_filter(['block' => 'station', 'station' => $stationKey, 'agent' => $viewingOwn ? null : $agentId] + \App\Services\RoadToSold\Track::params($track));
    $stationMeta = \App\Services\RoadToSold\BeltService::station($stationKey);
    $stageKey = (string) ($stationMeta['stages'][0] ?? '');
    $inputStyle = 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);';
@endphp
<form method="GET" class="flex items-end gap-3 flex-wrap" data-qa="station-filters">
    @foreach($keep as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
    <div class="flex flex-col"><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="s-q">Search address</label>
        <input id="s-q" type="text" name="s_q" value="{{ $f['q'] }}" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }} width: 14rem;"></div>
    <div class="flex flex-col"><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="s-sort">Sort</label>
        <select id="s-sort" name="s_sort" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
            <option value="age" @selected($f['sort'] === 'age')>Oldest first</option><option value="address" @selected($f['sort'] === 'address')>Address</option>
        </select></div>
    <button type="submit" class="corex-btn-outline corex-btn-xs">Search</button>
    @if($f['q'] !== '')<a href="{{ route('road-to-sold.index', $keep) }}" class="text-xs underline" style="color: var(--text-secondary);">Clear</a>@endif
    @if($stageKey !== '' && \App\Services\RoadToSold\ProspectStages::has($stageKey))
        <a href="{{ route('road-to-sold.index', array_filter(['block' => 'prospects', 'p_stage' => $stageKey, 'agent' => $viewingOwn ? null : $agentId] + \App\Services\RoadToSold\Track::params($track))) }}" class="text-xs underline ml-auto" style="color: var(--brand-icon);">Properties at this station</a>
    @endif
</form>

<div class="rounded-md rts-grow" style="background: var(--surface); border: 1px solid var(--border);" data-qa="station-list">
    <div class="px-3 py-2 text-xs font-semibold" style="color: var(--text-primary); border-bottom: 1px solid var(--border);">{{ $L['heading'] }} <span style="color: var(--text-muted); font-weight: 400;">· {{ number_format($rowsP->total()) }}</span></div>
    @if($rowsP->total() === 0)
        <div class="px-4 py-8 text-center text-sm" style="color: var(--text-secondary);" data-qa="station-empty">{{ $f['q'] !== '' ? 'Nothing matches that search.' : $L['empty'] }}</div>
    @else
        <table class="w-full text-xs ds-table">
            <thead><tr><th class="text-left">Property</th><th class="text-left">Suburb</th><th class="text-left">Owner</th><th class="text-left">Station · next step</th><th class="text-left">{{ $L['owed'] ? 'Evaluation date' : 'Since' }}</th><th class="text-left">Days</th><th></th></tr></thead>
            <tbody>
            @foreach($rowsP as $r)
                <tr data-qa="station-row">
                    <td class="rts-nw" title="{{ $r['title'] }}"><a href="{{ $r['url'] }}" class="underline" style="color: var(--text-primary);">{{ $r['title'] }}</a></td>
                    <td class="rts-nw">{{ \Illuminate\Support\Str::title(mb_strtolower((string) ($r['suburb'] ?? ''))) }}</td>
                    <td class="rts-nw">{{ $r['owner'] ?: '-' }}</td>
                    <td class="rts-nw" style="max-width: 26rem;" title="{{ $r['where'] ?? '' }}" data-qa="station-where">{{ $r['where'] ?? '-' }}</td>
                    <td>{{ $r['since_label'] ?? '-' }}</td>
                    <td style="font-variant-numeric: tabular-nums;">{{ $r['days'] ?? '-' }}</td>
                    <td class="text-right"><a href="{{ $r['url'] }}" class="corex-btn-outline corex-btn-xs">{{ $L['owed'] ? 'Give feedback' : 'View' }}</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
@if($rowsP->hasPages())<div class="text-xs" data-qa="station-pages">{{ $rowsP->appends(['s_q' => $f['q'], 's_sort' => $f['sort']] + $keep)->links() }}</div>@endif
