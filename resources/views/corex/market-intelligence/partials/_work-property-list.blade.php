{{-- MIC Work tab (mic-work-tab.md 2.1 C-E): control line + ONE ROW PER PROPERTY FILE, Excel-style, expand in place. Rows come from MicWorkList. --}}
@php
    $sortNow = request('sort', $defaultSortKey ?? 'last_seen');
    $dirNow = request('dir', 'desc');
    $sortUrl = function (string $key, string $defaultDir = 'desc') use ($sortNow, $dirNow) {
        $dir = $sortNow === $key ? ($dirNow === 'desc' ? 'asc' : 'desc') : $defaultDir;
        return route('market-intelligence.work', array_merge(request()->except(['page', 'sort', 'dir']), ['sort' => $key, 'dir' => $dir]));
    };
    $arrow = fn (string $key) => $sortNow === $key ? ($dirNow === 'desc' ? ' ▾' : ' ▴') : '';
    $cols = '40px 84px minmax(180px, 1.5fr) 140px 120px 130px 56px 96px 104px 112px 62px';
    $money = fn ($v) => 'R ' . number_format((float) $v, 0, '.', ',');
    $short = fn ($v) => abs($v) >= 1000000 ? 'R' . rtrim(rtrim(number_format(abs($v) / 1000000, 2), '0'), '.') . 'm' : 'R' . number_format(abs($v) / 1000) . 'k';
    $chip = 'display:inline-block; padding: 0 4px; margin-right: 2px; border: 1px solid var(--border); border-radius: 3px; font-size: .625rem; font-weight: 700; line-height: 1.4; color: var(--text-secondary);';
    $head = 'font-size: .6875rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: var(--text-muted); text-decoration: none; white-space: nowrap;';
    $reasonLabel = ['price_cuts' => 'Price cuts', 'relisted' => 'Re-listed', 'long_dom' => 'Long on market', 'owner_selling' => 'Owner selling', 'mandate_ended' => 'Mandate ended', 'strong_buyers' => 'Strong buyers',
        'buyers_waiting' => 'Buyers waiting', 'fast_pocket' => 'Fast pocket', 'lead_pocket' => 'Lead pocket', 'pocket_activity' => 'Pocket activity', 'well_known' => 'Well known',
        'deeds_owner' => 'Deeds owner', 'alert_history' => 'Alert history', 'reachable' => 'Reachable', 'back_on_market' => 'Back on the market', 'unworked' => 'Unworked'];
@endphp

{{-- C. control line: one search box, status + source chips, clear, count --}}
<form method="GET" action="{{ route('market-intelligence.work') }}" data-qa="work-control-line"
      style="display:flex; flex-wrap:wrap; align-items:center; gap: 8px; margin-bottom: 8px;">
    @foreach(request()->except(['search', 'page']) as $k => $v)
        @if(!is_array($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
    @endforeach
    <input type="text" name="search" value="{{ request('search') }}" placeholder="Search address, suburb, agency, ref"
           style="width: 300px; padding: 4px 8px; font-size: .8125rem; border: 1px solid var(--border); border-radius: 4px; background: var(--surface); color: var(--text-primary);">
    <button type="submit" class="corex-btn-outline text-xs">Search</button>
    @php
        $chipLink = fn (string $label, array $set, bool $on) => '<a href="' . e(route('market-intelligence.work', array_filter(array_merge(request()->except(['page']), $set), fn ($v) => $v !== null && $v !== ''))) . '" style="font-size:.75rem; padding: 2px 8px; border-radius: 10px; text-decoration:none; border:1px solid ' . ($on ? 'var(--brand-icon)' : 'var(--border)') . '; color:' . ($on ? 'var(--brand-icon)' : 'var(--text-secondary)') . ';">' . e($label) . '</a>';
    @endphp
    <span style="font-size:.6875rem; color: var(--text-muted);">Status</span>
    {!! $chipLink('On market', ['is_active' => '1'], request('is_active') === '1') !!}
    {!! $chipLink('Gone', ['is_active' => '0'], request('is_active') === '0') !!}
    {!! $chipLink('All', ['is_active' => 'all'], request('is_active') === 'all') !!}
    <span style="font-size:.6875rem; color: var(--text-muted);">Source</span>
    @foreach(\App\Services\Prospecting\PropertySourceFacts::FILTERS as $v => $l)
        {!! $chipLink($l, ['source' => request('source') === $v ? null : $v], request('source') === $v) !!}
    @endforeach
    @if(!empty($isProspectingManager))
        {!! $chipLink('Show our stock too', ['include_in_stock' => request()->boolean('include_in_stock') ? null : '1'], request()->boolean('include_in_stock')) !!}
    @endif
    @if(request()->hasAny(['search', 'source', 'tile', 'is_active', 'suburb', 'address_filter', 'include_in_stock']))
        <a href="{{ route('market-intelligence.work') }}" style="font-size:.75rem; color: var(--brand-icon); text-decoration:none;">Clear</a>
    @endif
    <span data-qa="work-count" style="margin-left:auto; font-size:.75rem; color: var(--text-muted);">
        {{ $listings->total() ? number_format($listings->firstItem()) . '-' . number_format($listings->lastItem()) . ' of ' . number_format($listings->total()) : '0' }} properties
    </span>
</form>

<div data-qa="work-property-list" style="background: var(--surface); border: 1px solid var(--border); border-radius: 6px; overflow-x: auto;">
    <div style="min-width: 1240px;">
        <div style="display:grid; grid-template-columns: {{ $cols }}; gap: 8px; padding: 5px 10px; background: var(--surface-2); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 2;">
            @if(\App\Services\Prospecting\MicWork\MicWorkList::hasScores())<a href="{{ $sortUrl('score') }}" style="{{ $head }}">Score{{ $arrow('score') }}</a>@else<span style="{{ $head }}">Score</span>@endif
            <span style="{{ $head }}">Sources</span>
            <a href="{{ $sortUrl('address', 'asc') }}" style="{{ $head }}">Address{{ $arrow('address') }}</a>
            <span style="{{ $head }}">Property</span>
            <a href="{{ $sortUrl('price') }}" style="{{ $head }}">Price{{ $arrow('price') }}</a>
            <span style="{{ $head }}">Who's selling</span>
            <a href="{{ $sortUrl('time') }}" style="{{ $head }}" title="Days on the market, longest first">Time{{ $arrow('time') }}</a>
            <span style="{{ $head }}">Flags</span>
            @if(\App\Services\Prospecting\MicWork\MicWorkList::hasFileStats())<a href="{{ $sortUrl('buyers') }}" style="{{ $head }}">Buyers{{ $arrow('buyers') }}</a>@else<span style="{{ $head }}">Buyers</span>@endif
            <span style="{{ $head }}">Our work</span>
            <span style="{{ $head }}">Next</span>
        </div>

        @forelse($listings as $r)
            <div x-data="{ open: false }" data-qa="work-row" style="border-bottom: 1px solid var(--border);">
                <div @click="open = !open" style="display:grid; grid-template-columns: {{ $cols }}; gap: 8px; align-items: center; padding: 4px 10px; font-size: .8125rem; cursor: pointer; min-height: 38px; color: var(--text-secondary);">
                    <span style="font-weight: 700; color: var(--text-primary);">{{ $r['score'] !== null ? number_format($r['score'], 0) : '' }}</span>
                    <span data-qa="row-sources">
                        @if($r['p24'])<span style="{{ $chip }}">P24</span>@endif
                        @if($r['pp'])<span style="{{ $chip }}">PP</span>@endif
                        @if($r['alert'])<span style="{{ $chip }}" title="Seen in a P24 e-mail alert">✉</span>@endif
                        @if($r['own_stock'])<span style="{{ $chip }} color: var(--brand-icon);" title="Our stock">Stock</span>@endif
                        @if($r['deeds'])<span style="{{ $chip }}" title="Deeds captured">Deeds</span>@endif
                        @if($r['owner'])<span style="{{ $chip }}" title="Owner selling privately">Owner</span>@endif
                    </span>
                    <span style="min-width:0;">
                        <span style="display:block; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="{{ $r['address'] }}">
                            @if($r['tp_id'])<a href="{{ route('market-intelligence.opportunities.show', ['tp' => $r['tp_id']]) }}" @click.stop style="color: inherit; text-decoration: none;">{{ $r['address'] }}</a>@else{{ $r['address'] }}@endif
                            @if($r['suburb'] && !str_contains($r['address'], (string) $r['suburb']))<span style="color: var(--text-muted);"> · {{ $r['suburb'] }}</span>@endif
                        </span>
                        @if($r['pair_ids'])<a href="{{ count($r['pair_ids']) === 1 ? route('corex.possible-matches.index', ['pair' => $r['pair_ids'][0]]) : route('corex.possible-matches.index', ['file' => $r['tp_id']]) }}" @click.stop data-qa="possible-match-chip" title="CoreX is not sure this is the same property as another file. Click to review." style="font-size:.625rem; font-weight:700; padding: 0 5px; margin-right: 4px; border: 1px solid var(--ds-amber, #f59e0b); border-radius: 3px; color: var(--ds-amber, #f59e0b); text-decoration:none;">Possible match{{ count($r['pair_ids']) > 1 ? ' (' . count($r['pair_ids']) . ')' : '' }}</a>@endif
                        @if($r['reasons'])<span style="font-size:.6875rem; color: var(--text-muted);">{{ collect($r['reasons'])->take(2)->map(fn ($c) => $reasonLabel[$c] ?? $c)->implode(' · ') }}</span>@endif
                    </span>
                    <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                        {{ collect([$r['facts']['type'], $r['facts']['beds'] ? $r['facts']['beds'] . 'bd' : null, $r['facts']['baths'] ? $r['facts']['baths'] . 'ba' : null, $r['facts']['garages'] ? $r['facts']['garages'] . 'g' : null])->filter()->implode(' · ') }}
                        @if($r['facts']['floor'] || $r['facts']['erf'])<span style="color: var(--text-muted);"> · {{ $r['facts']['floor'] ? number_format((float) $r['facts']['floor']) . ' m²' : '' }}{{ $r['facts']['floor'] && $r['facts']['erf'] ? ' / ' : '' }}{{ $r['facts']['erf'] ? number_format((float) $r['facts']['erf']) . ' erf' : '' }}</span>@endif
                    </span>
                    <span style="min-width:0; overflow:hidden; white-space: nowrap;">
                        <span style="display:block; text-overflow: ellipsis; overflow:hidden;">@if($r['price']){{ $money($r['price']) }}@else - @endif</span>
                        @if($r['move'])<span data-qa="price-move" style="display:block; font-size:.6875rem; color: {{ $r['move'] < 0 ? 'var(--ds-crimson, #dc2626)' : 'var(--text-muted)' }};" title="Move on the lead advert since it was first seen">{{ $r['move'] < 0 ? '▼' : '▲' }} {{ $short($r['move']) }}@if($r['cuts'] > 1) · {{ $r['cuts'] }} cuts @elseif($r['cuts'] === 1) · 1 cut @endif</span>@endif
                    </span>
                    <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="{{ implode(', ', $r['agencies']) }}{{ $r['advert_count'] > 1 ? ' - ' . $r['advert_count'] . ' adverts' : '' }}">
                        @if($r['owner'])Owner @elseif(count($r['agencies']) === 0)- @else{{ $r['agencies'][0] }}{{ count($r['agencies']) > 1 ? ' +' . (count($r['agencies']) - 1) : '' }}@endif{{ $r['advert_count'] > 1 ? ' · ' . $r['advert_count'] . ' adverts' : '' }}
                    </span>
                    <span style="white-space: nowrap;" title="Days on the market. A + means at least this long: we first saw it then, and cannot tell how long it was there before.">{{ $r['dom']['label'] }}{{ is_numeric($r['dom']['label']) || str_ends_with($r['dom']['label'], '+') ? ' d' : '' }}</span>
                    <span style="font-size:.6875rem; color: var(--ds-amber, #f59e0b); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ implode(' · ', $r['flags']) }}</span>
                    <span title="Buyers matched to this property (own agency)" style="font-size:.75rem; white-space: nowrap;">@if($r['buyers_strong'] !== null){{ $r['buyers_strong'] ? $r['buyers_strong'] . ' strong' : '' }}{{ $r['buyers_strong'] && $r['buyers_mid'] ? ' · ' : '' }}{{ $r['buyers_mid'] ? $r['buyers_mid'] . ' mid' : '' }}@elseif($r['buyers']){{ $r['buyers'] }} matched @endif</span>
                    <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size:.75rem;" title="{{ $r['work_reason'] }}">{{ $r['claimed_by'] ? 'Claimed · ' . $r['claimed_by'] : ($r['work_status'] ? ucfirst(str_replace('_', ' ', $r['work_status'])) : '') }}</span>
                    <span>
                        @if($r['tp_id'])<a href="{{ route('market-intelligence.opportunities.show', ['tp' => $r['tp_id']]) }}" @click.stop class="corex-btn-outline text-xs" style="padding: 1px 8px;">{{ $r['claimed_by'] ? 'Open' : ($r['own_stock'] ? 'Open' : 'Claim') }}</a>@endif
                    </span>
                </div>

                {{-- E. expand in place --}}
                <div x-show="open" x-cloak data-qa="row-expand" style="padding: 6px 10px 10px 56px; background: var(--surface-2); font-size: .75rem; color: var(--text-secondary);">
                    <div style="font-weight: 700; margin: 2px 0;">Adverts</div>
                    @foreach($r['adverts'] as $a)
                        <div style="display:grid; grid-template-columns: 40px 140px 1fr 110px 100px 100px 90px; gap: 8px; padding: 1px 0; {{ $a['active'] ? '' : 'opacity:.6;' }}">
                            <span>{{ $a['portal'] }}</span>
                            <span>@if($a['url'])<a href="{{ $a['url'] }}" target="_blank" rel="noopener" style="color: var(--brand-icon); text-decoration:none;">{{ $a['ref'] }}</a>@else{{ $a['ref'] }}@endif</span>
                            <span>{{ $a['owner'] ? 'Owner (private)' . ($a['agent'] ? ' · ' . $a['agent'] : '') : trim(($a['agency'] ?: '-') . ($a['agent'] ? ' · ' . $a['agent'] : '')) }}</span>
                            <span>{{ $a['price'] ? $money($a['price']) : '-' }}</span>
                            <span>{{ $a['listed'] ? 'listed ' . $a['listed'] : 'first seen ' . ($a['seen'] ?? '-') }}</span><span></span>
                            <span>{{ $a['active'] ? ($a['status'] && $a['status'] !== 'active' ? ucfirst(str_replace('_', ' ', $a['status'])) : 'Active') : 'Gone' }}</span>
                        </div>
                    @endforeach
                    @php $allChanges = collect($r['adverts'])->flatMap(fn ($a) => collect($a['changes'])->map(fn ($c) => $c + ['ref' => $a['ref']]))->sortBy('date')->values(); @endphp
                    <div style="font-weight: 700; margin: 8px 0 2px;">Price history</div>
                    @forelse($allChanges as $c)
                        <div>{{ $c['date'] }} · {{ $c['ref'] }} · {{ $money($c['old']) }} → {{ $money($c['new']) }}</div>
                    @empty
                        <div style="color: var(--text-muted);">No price changes recorded.</div>
                    @endforelse
                    <div style="font-weight: 700; margin: 8px 0 2px;">P24 alerts</div>
                    @forelse($r['alerts'] as $al)
                        <div data-qa="alert-line">✉ {{ $al['portal'] }} {{ $al['number'] }} · first {{ $al['first'] ?? '-' }} · last {{ $al['last'] ?? '-' }} · asking {{ $money($al['price']) }}@if($al['original'] && $al['original'] !== $al['price']) · was {{ $money($al['original']) }}@endif
                            @foreach($al['changes'] as $c)<span style="color: var(--text-muted);"> · {{ $c['date'] }}: {{ $money($c['old']) }} → {{ $money($c['new']) }}</span>@endforeach</div>
                    @empty
                        <div style="color: var(--text-muted);">None.</div>
                    @endforelse
                    <div style="font-weight: 700; margin: 8px 0 2px;">Deeds</div>
                    @if($r['deeds_info'])
                        <div>Registered {{ $r['deeds_info']['registered'] ?? '-' }} · last sale {{ $r['deeds_info']['price'] ? $money($r['deeds_info']['price']) : '-' }} · bond {{ $r['deeds_info']['bond'] ? $money($r['deeds_info']['bond']) : '-' }}</div>
                    @else
                        <div style="color: var(--text-muted);">Not captured yet.</div>
                    @endif
                    <div data-qa="record-card" style="margin-top: 8px; padding-top: 6px; border-top: 1px solid var(--border);">
                        @php $cx = $r['context'] ?? null; @endphp
                        <div style="font-weight: 700; margin: 2px 0;">Recent sales nearby</div>
                        @if($cx && !empty($cx['nearby_sales']))
                            @foreach($cx['nearby_sales'] as $ns)
                                <div data-qa="nearby-sale">{{ \App\Services\Prospecting\MicWork\MicWorkList::tidyLine($ns['address'] ?? '') ?: 'Address not shown' }} · {{ !empty($ns['sold_on']) ? \Carbon\Carbon::parse($ns['sold_on'])->format('j M Y') : '-' }} · {{ $money($ns['price']) }}@if(!empty($ns['r_per_m2'])) · R {{ number_format($ns['r_per_m2']) }}/m²@endif <span style="color: var(--text-muted);">· same {{ $ns['match'] ?? 'suburb' }}</span></div>
                            @endforeach
                        @else
                            <div style="color: var(--text-muted);">{{ $cx ? 'No recent sales found nearby.' : 'Market context is still being built for this property.' }}</div>
                        @endif
                        @if($cx)
                            <div style="font-weight: 700; margin: 8px 0 2px;">This suburb</div>
                            <div data-qa="suburb-line">
                                @if(!empty($cx['suburb_avg_sold_12m']))Average sold ({{ $cx['sale_class'] ?? 'all types' }}, last 12 months): <strong>{{ $money($cx['suburb_avg_sold_12m']) }}</strong> from {{ $cx['suburb_avg_sold_n'] }} sales @if($cx['asking_vs_avg_sold_pct'] !== null)· asking is {{ abs($cx['asking_vs_avg_sold_pct']) }}% {{ $cx['asking_vs_avg_sold_pct'] >= 0 ? 'above' : 'below' }} the average @endif
                                @else<span style="color: var(--text-muted);">Not enough sales in this suburb to compare ({{ $cx['suburb_avg_sold_n'] ?? 0 }} found, 3 needed).</span>@endif
                                @if(!empty($cx['suburb_r_per_m2']) && $cx['own_vs_suburb_pct'] !== null)<br>Asking R {{ number_format($cx['own_r_per_m2']) }}/m² on {{ $cx['own_size_basis'] ?? 'erf' }} size is {{ abs($cx['own_vs_suburb_pct']) }}% {{ $cx['own_vs_suburb_pct'] >= 0 ? 'above' : 'below' }} the suburb R {{ number_format($cx['suburb_r_per_m2']) }}/m².@endif
                            </div>
                        @endif
                        <div style="font-weight: 700; margin: 8px 0 2px;">Matched buyers</div>
                        <div data-qa="matched-buyers">
                            @if($r['buyers_strong'] !== null || $r['buyers'])
                                {{ $r['buyers_strong'] ?? $r['buyers'] }} strong · {{ $r['buyers_mid'] ?? 0 }} mid
                                @foreach($r['buyer_names'] as $b)<span> · <a href="{{ route('corex.contacts.show', $b['id']) }}" style="color: var(--brand-icon); text-decoration:none;">{{ $b['name'] }}</a> ({{ $b['score'] }}%)</span>@endforeach
                            @else<span style="color: var(--text-muted);">None matched.</span>@endif
                        </div>
                        <div style="font-weight: 700; margin: 8px 0 2px;">Our history</div>
                        <div data-qa="our-history">
                            @forelse($r['claims'] as $c)<div>Claim{{ ($c['count'] ?? 1) > 1 ? ' ×' . $c['count'] : '' }} · {{ $c['who'] ?? 'agent' }} · {{ $c['at'] }} · {{ str_replace('_', ' ', $c['status'] ?? '') }}@if($c['released']) · released {{ $c['released'] }}@endif @if($c['reason']) ({{ $c['reason'] }})@endif</div>@empty<div style="color: var(--text-muted);">No claims yet.</div>@endforelse
                            @if($r['work_status'])<div>Status · {{ ucfirst(str_replace('_', ' ', $r['work_status'])) }}@if($r['work_reason']) · {{ $r['work_reason'] }}@endif</div>@endif
                            @foreach($r['mandates'] as $m)<div>Mandate · {{ $m['type'] ? ucfirst($m['type']) : '-' }} · {{ ucfirst(str_replace('_', ' ', (string) $m['status'])) }}@if($m['expiry']) · expires {{ $m['expiry'] }}@endif @if($m['price']) · {{ $money($m['price']) }}@endif</div>@endforeach
                        </div>
                        <div style="margin-top: 8px;">@if($r['tp_id'])<a href="{{ route('market-intelligence.opportunities.show', ['tp' => $r['tp_id']]) }}" style="color: var(--brand-icon); text-decoration:none;">Open the property page →</a>@else Not on a property file yet.@endif</div>
                    </div>
                </div>
            </div>
        @empty
            <div data-qa="work-empty" style="padding: 28px 12px; text-align: center; font-size: .875rem; color: var(--text-muted);">
                Nothing matches. <a href="{{ route('market-intelligence.work') }}" style="color: var(--brand-icon); text-decoration:none;">Clear the filters</a>.
            </div>
        @endforelse
    </div>
</div>
<div style="padding: 10px 0;">{{ $listings->withQueryString()->links() }}</div>
