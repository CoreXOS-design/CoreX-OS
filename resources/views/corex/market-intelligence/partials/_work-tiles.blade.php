{{-- MIC Work tab (mic-work-tab.md 2.1 A+B): the 11-tile row and the tile 10 / 11 panels. Tiles come from MicWorkTiles; a tile whose service has not landed says "Coming" - no number. --}}
@php
    $activeTile = request('tile');
    $activePanel = (int) request('panel');
    $tileUrl = function (array $params) {
        // panel-specific params never survive a panel change or close (months / type / trigger; its suburb filter too)
        $drop = ['page', 'tile', 'panel', 'trigger', 'months', 'type'] + (request('panel') ? [6 => 'suburb'] : []);
        $merged = array_merge(request()->except($drop), $params);
        foreach ($merged as $k => $v) if ($v === null || $v === '') unset($merged[$k]);
        return route('market-intelligence.work', $merged);
    };
@endphp
<div data-qa="work-tiles" style="display: grid; grid-template-columns: repeat({{ max(1, count($workTiles)) }}, minmax(0, 1fr)); gap: 6px; margin-bottom: 10px; align-items: stretch;">
    @foreach($workTiles as $t)
        @php
            $isActive = $t['kind'] === 'panel' ? $activePanel === $t['number'] : $activeTile === $t['key'];
            $clickable = $t['ready'];
            $href = $t['kind'] === 'panel'
                ? $tileUrl($isActive ? [] : ['panel' => $t['number']])
                : $tileUrl($isActive ? [] : ['tile' => $t['key']]);
            $box = 'display:block; padding: 6px 8px; border: 1px solid ' . ($isActive ? 'var(--brand-icon)' : 'var(--border)') . '; border-radius: 6px; background: var(--surface); text-decoration: none; min-width: 0;'
                . ($clickable ? '' : ' opacity: .6;');
        @endphp
        @if($clickable)<a href="{{ $href }}" data-qa="tile-{{ $t['number'] }}" style="{{ $box }}" title="{{ $t['title'] }}">@else<div data-qa="tile-{{ $t['number'] }}" style="{{ $box }}" title="{{ $t['title'] }} - data coming">@endif
            <div style="font-size: 0.625rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: var(--text-muted); line-height: 1.2;">{{ $t['short'] }}</div>
            @if(!empty($t['display']))
                <div style="font-size: .9375rem; font-weight: 700; line-height: 1.4; color: {{ $isActive ? 'var(--brand-icon)' : 'var(--text-primary)' }};">{{ $t['display'] }}</div>
                <div style="font-size: 0.6875rem; line-height: 1.25; color: var(--text-muted);">{{ $t['note'] ?? $t['reason'] }}</div>
            @elseif($t['count'] !== null)
                <div style="font-size: 1.0625rem; font-weight: 700; line-height: 1.3; color: {{ $isActive ? 'var(--brand-icon)' : 'var(--text-primary)' }};">{{ number_format($t['count']) }}</div>
                <div style="font-size: 0.6875rem; line-height: 1.25; color: var(--text-muted);">{{ $t['note'] ?? $t['reason'] }}</div>
            @elseif($t['ready'] && $t['kind'] === 'panel')
                <div style="font-size: .8125rem; font-weight: 700; line-height: 1.6; color: {{ $isActive ? 'var(--brand-icon)' : 'var(--text-primary)' }};">Open ▸</div>
                <div style="font-size: 0.6875rem; color: var(--text-muted);">&nbsp;</div>
            @elseif($t['ready'])
                <div style="font-size: .8125rem; line-height: 1.6; color: var(--text-muted);">{{ $t['note'] ?? $t['reason'] }}</div>
                <div style="font-size: 0.6875rem;">&nbsp;</div>
            @else
                <div style="font-size: .8125rem; line-height: 1.6; color: var(--text-muted);">Coming</div>
                <div style="font-size: 0.6875rem;">&nbsp;</div>
            @endif
        @if($clickable)</a>@else</div>@endif
    @endforeach
</div>

@if(in_array($activePanel, [10, 11], true))
<div data-qa="work-panel-{{ $activePanel }}" style="margin-bottom: 10px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; overflow: hidden;">
    <div style="display:flex; align-items:center; justify-content:space-between; gap: 8px; padding: 6px 10px; border-bottom: 1px solid var(--border); background: var(--surface-2);">
        <strong style="font-size: .8125rem; color: var(--text-primary);">{{ $activePanel === 10 ? 'Neighbours of a sale' : 'Market data last refreshed' }}</strong>
        <a href="{{ $tileUrl([]) }}" style="font-size: .75rem; color: var(--text-muted); text-decoration: none;" title="Close">✕ Close</a>
    </div>
    @if(empty($workPanel['ready']))
        <div data-qa="panel-coming" style="padding: 14px 12px; font-size: .8125rem; color: var(--text-muted);">
            {{ $activePanel === 10 ? 'The neighbour list is coming - it needs the deeds, sale and under-offer triggers to be wired in.' : 'The refresh list is coming - it needs the suburb crawl record.' }} Nothing is shown until real data is there.
        </div>
    @elseif($activePanel === 10)
        <form method="GET" action="{{ route('market-intelligence.work') }}" style="display:flex; flex-wrap:wrap; gap: 8px; padding: 6px 10px; border-bottom: 1px solid var(--border); font-size: .75rem;">
            <input type="hidden" name="panel" value="10">
            <label>Time
                <select name="months" onchange="this.form.submit()" style="font-size:.75rem;">
                    @foreach([0 => 'This month', 1 => '1 month', 2 => '2 months', 3 => '3 months', 4 => '4 months', 5 => '5 months', 6 => '6 months'] as $v => $l)
                        <option value="{{ $v }}" @selected((int) request('months', 0) === $v)>{{ $l }}</option>
                    @endforeach
                </select></label>
            <label>Type
                <select name="type" onchange="this.form.submit()" style="font-size:.75rem;">
                    <option value="">All</option>
                    @foreach(['registered' => 'Registered', 'sold' => 'Sold', 'under_offer' => 'Under offer', 'our_sale' => 'Our sale'] as $v => $l)
                        <option value="{{ $v }}" @selected(request('type') === $v)>{{ $l }}</option>
                    @endforeach
                </select></label>
            <label>Suburb <input type="text" name="suburb" value="{{ request('suburb') }}" style="font-size:.75rem; width: 130px;"></label>
        </form>
        @forelse($workPanel['rows'] as $r)
            @php $openTrigger = (string) request('trigger') === (string) $r['id']; @endphp
            <div data-qa="trigger-row" style="border-top: 1px solid var(--border);">
                <a href="{{ $openTrigger ? $tileUrl(request()->only(['months', 'type', 'suburb']) + ['panel' => 10]) : $tileUrl(request()->only(['months', 'type', 'suburb']) + ['panel' => 10, 'trigger' => $r['id']]) }}"
                   style="display:grid; grid-template-columns: 110px 1fr 140px 110px 120px 90px; gap: 8px; padding: 5px 10px; font-size: .8125rem; text-decoration: none; color: var(--text-secondary); {{ $openTrigger ? 'background: var(--surface-2);' : '' }}">
                    <span>{{ ['registered' => 'Registered', 'sold' => 'Sold', 'under_offer' => 'Under offer', 'our_sale' => 'Our sale'][$r['type']] ?? $r['type'] }}</span>
                    <span style="color: var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ \App\Services\Prospecting\MicWork\MicWorkList::tidyAddress($r['address']) }}</span>
                    <span>{{ \App\Services\Prospecting\MicWork\MicWorkList::titleSuburb($r['suburb']) }}</span><span>{{ $r['property_type'] ?? '' }}</span>
                    <span>{{ !empty($r['price']) ? 'R ' . number_format($r['price']) : '' }}</span><span>{{ $r['date'] ?? '' }}</span>
                </a>
                @if($openTrigger)
                    @forelse($workPanel['around'][$r['id']] ?? [] as $h)
                        <div data-qa="neighbour-row" style="display:grid; grid-template-columns: 1fr 160px 90px 140px 190px; gap: 8px; padding: 4px 10px 4px 24px; font-size: .75rem; color: var(--text-secondary); border-top: 1px dotted var(--border);">
                            <span style="color: var(--text-primary);">{{ \App\Services\Prospecting\MicWork\MicWorkList::tidyAddress($h['address']) }}</span><span>{{ $h['owner'] ?? '' }}</span>
                            <span>{{ ($h['on_market'] ?? false) ? 'On market' : 'Not listed' }}</span><span>{{ $h['last_contact'] ?? '' }}</span>
                            <span>@if(!empty($h['property_id']))<a href="{{ route('market-intelligence.opportunities.show', ['tp' => $h['property_id']]) }}" style="color: var(--brand-icon); text-decoration: none;">Open / Claim</a>@endif</span>
                        </div>
                    @empty
                        <div style="padding: 6px 24px; font-size: .75rem; color: var(--text-muted);">No neighbours found for this one.</div>
                    @endforelse
                @endif
            </div>
        @empty
            <div style="padding: 14px 12px; font-size: .8125rem; color: var(--text-muted);">No triggers in this window. Widen the time or clear the filters.</div>
        @endforelse
    @else
        <div style="display:grid; grid-template-columns: 1fr 150px 150px 80px 200px; gap: 8px; padding: 4px 10px; font-size: .6875rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); background: var(--surface-2);">
            <span>Suburb</span><span>Last full P24 read</span><span>Last full PP read</span><span>Age</span><span></span>
        </div>
        @forelse($workPanel['rows'] as $r)
            <div data-qa="refresh-row" style="display:grid; grid-template-columns: 1fr 150px 150px 80px 200px; gap: 8px; padding: 5px 10px; font-size: .8125rem; border-top: 1px solid var(--border); color: var(--text-secondary);">
                <span style="color: var(--text-primary);">{{ \App\Services\Prospecting\MicWork\MicWorkList::titleSuburb($r['suburb']) }}</span>
                <span>{{ !empty($r['p24_read_at']) ? \Carbon\Carbon::parse($r['p24_read_at'])->diffForHumans() : 'never' }}</span>
                <span>{{ !empty($r['pp_read_at']) ? \Carbon\Carbon::parse($r['pp_read_at'])->diffForHumans() : 'never' }}</span>
                <span style="color: {{ !empty($r['overdue']) ? 'var(--ds-crimson, #dc2626)' : 'inherit' }};">{{ $r['age_days'] !== null ? $r['age_days'] . ' d' : '-' }}</span>
                <span>
                    @if(!empty($r['p24_url']))<a href="{{ $r['p24_url'] }}" target="_blank" rel="noopener" style="color: var(--brand-icon); text-decoration:none;">Open in P24</a>@endif
                    @if(!empty($r['pp_url']))&nbsp;<a href="{{ $r['pp_url'] }}" target="_blank" rel="noopener" style="color: var(--brand-icon); text-decoration:none;">Open in PP</a>@endif
                </span>
            </div>
        @empty
            <div style="padding: 14px 12px; font-size: .8125rem; color: var(--text-muted);">No areas set up yet. Pick your areas in MIC Settings.</div>
        @endforelse
        <div style="padding: 6px 10px; border-top: 1px solid var(--border); font-size: .75rem; color: var(--text-muted);">
            <button type="button" disabled title="Needs the area-pages reader in the browser extension" style="font-size:.75rem; opacity:.55;">Read area stats</button>
            <span>Coming with the extension's area-pages reader.</span>
        </div>
    @endif
</div>
@endif
