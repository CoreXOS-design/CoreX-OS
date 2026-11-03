{{--
    A belt tile's list (spec 23.13 / 23.24): the agent's properties at one station, each in the Loaded list's row layout - WHY, address, price / days on market / agency,
    owner, last outcome - plus WHEN IT IS DUE (red when late) and the ONE next-action button for the station (Loaded / Claimed: Find owner; Owner found: Log contact;
    Contacted: Book evaluation; Booked / Prepared: Evaluation feedback; Held: Result) and View. Late first, then the oldest due date. Search, sort, a filter (To do /
    Moved on today / Late / All; Won, Draft, Active and Advertised read Reached this month / Reached today / At this station now / All), 25 a page, a real empty state.
    The Held station keeps one extra view: the evaluations whose time has passed with no feedback.
    Inputs: $stationList, $stationKey, $stationFilters, $viewingOwn, $agentId, $track.
--}}
@php
    $L = $stationList;
    $rowsP = $L['rows'];
    $f = $stationFilters;
    $isOwed = (bool) $L['owed'];
    $keep = array_filter(['block' => 'station', 'station' => $stationKey, 'agent' => $viewingOwn ? null : $agentId] + \App\Services\RoadToSold\Track::params($track));
    $inputStyle = 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);';
    $money = $money ?? fn ($v) => $v ? 'R ' . number_format((int) $v, 0, '.', ',') : '';
    $intakeProspects = $isOwed ? [] : collect($rowsP->items())->pluck('prospect_id', 'tracked_property_id')->all();
    $__pitchCards = ($viewingOwn && ! $isOwed) ? app(\App\Services\Prospecting\MicWork\PitchCardResolver::class)->trackedWithCards((int) auth()->user()->effectiveAgencyId(), collect($rowsP->items())->pluck('tracked_property_id')) : [];
    $resultOptions = (! $isOwed && $stationKey === 'held') ? collect(app(\App\Services\RoadToSold\ResultOptionService::class)->list((int) auth()->user()->effectiveAgencyId(), $track))->map(fn ($o) => [
        'key' => $o['key'], 'label' => $o['label'], 'class' => $o['class'], 'days' => $o['revisit_days'],
        'hint' => match ($o['class']) { 'won' => 'the property becomes a Draft', 'pending' => 'it comes back to you', 'lost' => 'set aside, the claim is given back', default => 'the property is marked Not selling' },
    ])->values()->all() : [];
    $pageQuery = array_filter(['s_q' => $f['q'], 's_sort' => $f['sort'] !== 'due' ? $f['sort'] : null, 'filter' => $isOwed ? null : $L['filter'], 'owed' => $isOwed ? 1 : null]) + $keep;
@endphp
<div class="rts-col" x-data="{ id: 0, address: '', resultOpen: false, resultKey: '', resultDate: '', resultOptions: @js($resultOptions),
        get resultNeedsDate() { var o = this.resultOptions.find(x => x.key === this.resultKey); return !!o && o.class !== 'won'; },
        openResult(id, address) { this.id = id; this.address = address; this.resultKey = ''; this.resultDate = ''; this.resultOpen = true; },
        pickResult() { var o = this.resultOptions.find(x => x.key === this.resultKey); if (o && o.days) { var d = new Date(); d.setDate(d.getDate() + o.days); this.resultDate = d.toISOString().slice(0, 10); } } }" data-qa="block-station">
<form method="GET" class="flex items-end gap-3 flex-wrap" data-qa="station-filters">
    @foreach($keep as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
    @if($isOwed)<input type="hidden" name="owed" value="1">@endif
    <div class="flex flex-col"><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="s-q">Search address</label>
        <input id="s-q" type="text" name="s_q" value="{{ $f['q'] }}" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }} width: 14rem;"></div>
    @unless($isOwed)
        <div class="flex flex-col" data-qa="station-filter"><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="s-filter">Show</label>
            <select id="s-filter" name="filter" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                @foreach($L['filters'] as $k => $o)
                    <option value="{{ $k }}" @selected($L['filter'] === $k)>{{ $o['label'] }}@if($o['n'] !== null) ({{ $o['n'] }})@endif</option>
                @endforeach
            </select></div>
    @endunless
    <div class="flex flex-col"><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="s-sort">Sort</label>
        <select id="s-sort" name="s_sort" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
            @unless($isOwed)<option value="due" @selected($f['sort'] === 'due')>Late first, then oldest due</option>@endunless
            <option value="age" @selected($f['sort'] === 'age' || ($isOwed && $f['sort'] === 'due'))>Oldest first</option><option value="address" @selected($f['sort'] === 'address')>Address</option>
        </select></div>
    <button type="submit" class="corex-btn-outline corex-btn-xs">Search</button>
    @if($f['q'] !== '')<a href="{{ route('road-to-sold.index', array_filter(['filter' => $isOwed ? null : $L['filter'], 'owed' => $isOwed ? 1 : null]) + $keep) }}" class="text-xs underline" style="color: var(--text-secondary);">Clear</a>@endif
    @if($stationKey === 'held')
        @if($isOwed)
            <a href="{{ route('road-to-sold.index', $keep) }}" class="text-xs underline ml-auto" style="color: var(--brand-icon);" data-qa="held-back">Back to the Held list</a>
        @elseif(($L['owed_count'] ?? 0) > 0)
            <a href="{{ route('road-to-sold.index', $keep + ['owed' => 1]) }}" class="text-xs underline ml-auto" style="color: var(--ds-red, #f87171);" data-qa="held-owed">{{ $L['owed_count'] }} evaluation{{ $L['owed_count'] === 1 ? '' : 's' }} owed feedback - have you given it?</a>
        @endif
    @endif
</form>

<style>
    /* 23.24: the same row as the Loaded list, kept inside the box at 1366 wide: a narrower WHY, headings that wrap, the buttons together */
    .rts-stbl th { white-space: normal !important; line-height: 1.15; vertical-align: bottom; }
    .rts-stbl td[data-qa="intake-why"] { min-width: 0 !important; max-width: 10rem; }
    .rts-stbl td[data-qa="station-due"] { max-width: 11rem; }
</style>
<div class="rounded-md rts-grow" style="background: var(--surface); border: 1px solid var(--border);" data-qa="station-list" data-filter="{{ $L['filter'] }}">
    <div class="px-3 py-2 text-xs font-semibold" style="color: var(--text-primary); border-bottom: 1px solid var(--border);">{{ $L['heading'] }} <span style="color: var(--text-muted); font-weight: 400;">· {{ number_format($rowsP->total()) }}</span></div>
    @if($rowsP->total() === 0)
        <div class="px-4 py-8 text-center text-sm" style="color: var(--text-secondary);" data-qa="station-empty">{{ $f['q'] !== '' ? 'Nothing matches that search.' : $L['empty'] }}</div>
    @elseif(! empty($L['simple']))
        {{-- 23.31: a Maintain-stock list - the agent's own properties, one line each (the Sell-stock lists have no record to show yet and open on their empty state above) --}}
        <table class="w-full text-xs ds-table" data-qa="station-simple">
            <thead><tr style="color: var(--text-muted);"><th class="px-3 py-2 text-left">Property</th><th class="px-3 py-2 text-left">Suburb</th><th class="px-3 py-2 text-left">Reached</th><th class="px-3 py-2 text-right"></th></tr></thead>
            <tbody>
            @foreach($rowsP as $r)
                <tr style="border-top: 1px solid var(--border);" data-qa="station-row">
                    <td class="px-3 py-2 font-semibold rts-nw" style="color: var(--text-primary);">@if($loop->first && $L['filter'] === 'todo' && $rowsP->currentPage() === 1)<span class="rts-next" data-qa="next-marker">Next</span> @endif{{ $r['title'] ?: 'Property ' . $r['id'] }}</td>
                    <td class="px-3 py-2 rts-nw">{{ $r['suburb'] ?: '-' }}</td>
                    <td class="px-3 py-2">{{ $r['reached'] ?? '-' }}</td>
                    <td class="px-3 py-2 text-right">@if($r['url'])<a href="{{ $r['url'] }}" class="corex-btn-outline corex-btn-xs" data-qa="station-view">View</a>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @elseif($isOwed)
        <table class="w-full text-xs ds-table">
            <thead><tr><th class="text-left">Evaluation</th><th class="text-left">Station · next step</th><th class="text-left">Evaluation date</th><th class="text-left">Days</th><th></th></tr></thead>
            <tbody>
            @foreach($rowsP as $r)
                <tr data-qa="station-row">
                    <td class="rts-nw" title="{{ $r['title'] }}"><a href="{{ $r['url'] }}" class="underline" style="color: var(--text-primary);">{{ $r['title'] }}</a></td>
                    <td class="rts-nw" style="max-width: 26rem;" title="{{ $r['where'] ?? '' }}" data-qa="station-where">{{ $r['where'] ?? '-' }}</td>
                    <td>{{ $r['since_label'] ?? '-' }}</td>
                    <td style="font-variant-numeric: tabular-nums;">{{ $r['days'] ?? '-' }}</td>
                    <td class="text-right"><a href="{{ $r['url'] }}" class="corex-btn-primary corex-btn-xs">Give feedback</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        <table class="w-full text-xs ds-table rts-stbl">
            <thead>
                <tr style="color: var(--text-muted);">
                    <th class="px-3 py-2 text-left">Why - start here</th>
                    <th class="px-3 py-2 text-left">Address</th>
                    <th class="px-3 py-2 text-left">Price · days on market · agency</th>
                    <th class="px-3 py-2 text-left">Owner</th>
                    <th class="px-3 py-2 text-left rts-col-last">Last outcome</th>
                    <th class="px-3 py-2 text-left">Due</th>
                    <th class="px-3 py-2 text-right"></th>
                </tr>
            </thead>
            <tbody>
            @foreach($rowsP as $r)
                @php $nextRow = $loop->first && $L['filter'] === 'todo' && $rowsP->currentPage() === 1; @endphp
                <tr style="border-top: 1px solid var(--border);" data-qa="station-row" data-station="{{ $r['station'] }}" data-late="{{ $r['late'] ? 1 : 0 }}">
                    @include('road-to-sold._row-cells')
                    <td class="px-3 py-2" data-qa="station-due">
                        @if($r['due_label'])<div class="font-semibold" @if($r['late']) style="color: var(--ds-red, #f87171);" @endif title="{{ $r['late'] ? 'Late' : 'Due' }}">{{ $r['due_label'] }}@if($r['late']) <span class="text-xs">late</span>@endif</div>@else<div style="color: var(--text-muted);">No date</div>@endif
                        @if(! $r['at_station'])<div class="rts-nw" style="color: var(--text-muted);" data-qa="station-now">Now: {{ $r['live'] ? $r['station_label'] : 'set aside / closed' }}</div>@endif
                    </td>
                    <td class="px-3 py-2 text-right">
                        <div class="flex items-center justify-end gap-2">
                            @php $act = $viewingOwn ? ($r['action'] ?? null) : null; @endphp
                            @if($act && $act['kind'] === 'step')
                                <button type="button" class="corex-btn-primary corex-btn-xs" data-qa="station-action" data-action="{{ $act['label'] }}" data-tracked="{{ isset($__pitchCards[(int) $r['tracked_property_id']]) ? $r['tracked_property_id'] : '' }}"
                                        data-step-url="{{ $act['step_url'] }}" data-step-label="{{ $act['step_label'] }} - {{ $r['address'] }}"
                                        @click="$dispatch('rts-step', $el.dataset)">{{ $act['label'] }}</button>
                            @elseif($act && $act['kind'] === 'log_contact' && ! ($ownerBlocked[(int) $r['tracked_property_id']] ?? null))
                                <button type="button" class="corex-btn-primary corex-btn-xs" data-qa="station-action" data-action="Log contact" data-tp="{{ $r['tracked_property_id'] }}" data-item="" data-address="{{ $r['address'] }}"
                                        @click="openContact($el.dataset)">Log contact</button>
                            @elseif($act && $act['kind'] === 'result')
                                <button type="button" class="corex-btn-primary corex-btn-xs" data-qa="station-action" data-action="Record result" @click="openResult({{ (int) $r['prospect_id'] }}, @js($r['address']))">Record result</button>
                            @endif
                            <a href="{{ $r['view_url'] }}" class="corex-btn-outline corex-btn-xs" data-qa="station-view">View</a>
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
@if($rowsP->hasPages())<div class="text-xs" data-qa="station-pages">{{ $rowsP->appends($pageQuery)->links() }}</div>@endif
@if($resultOptions !== [])@include('road-to-sold._result-modal')@endif
</div>
