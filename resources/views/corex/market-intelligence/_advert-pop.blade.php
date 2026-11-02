{{-- Content of one popover of the Adverts card (mic-work-tab.md 13.3, layout 13.12b). Used inside <x-mic-pop> (opens down, or up when there is no room), so Esc / click-outside come from there.
     One layout for every kind: a short bold heading, one entry per line with a hairline between entries, the main fact bold and the detail muted on the line under. Only what the row gives.
     Inputs: $kind = buyers | price | adverts | alerts | market ; $adv = the file record (MicWorkList::forFiles) ; $listing ; $money. --}}
@php
    $pt = 'font-size:.75rem; color: var(--text-primary); line-height:1.5;';
    $ph = 'font-weight:700; color: var(--text-primary); font-size:.75rem; padding: 2px 8px 4px;';
    $row = fn (int $i) => 'padding: 7px 8px;' . ($i > 0 ? ' border-top: 1px solid var(--border);' : '');
    $bold = 'font-weight:700; color: var(--text-primary);';
    $dim = 'color: var(--text-secondary);';
    $link = 'color: var(--text-primary); font-weight:700; text-decoration: underline; text-decoration-color: var(--brand-icon);';
    $split = !empty($split);
    $B = \App\Services\Prospecting\MicWork\BuyerFitLabel::class;
@endphp
{{-- With the split button the popover stays open on a click inside it (the dropdown would close it); a click that is not on a split button turns an armed one back to normal --}}
<div style="padding:4px 0; max-height: min(22rem, var(--pop-max, 22rem)); overflow-y:auto; {{ $pt }}" @keydown.escape.window="$dispatch('close')" @if($split) @click.stop="if ($event.splitBtn === undefined) disarm()" @endif>
@if($kind === 'buyers')
    <div style="{{ $ph }}">Matched buyers · {{ $adv['buyers_strong'] ?? $adv['buyers'] ?? 0 }} strong · {{ $adv['buyers_mid'] ?? 0 }} mid</div>
    @php $bi = 0; @endphp
    @foreach($adv['buyer_names'] ?? [] as $b)
        <div data-qa="pop-entry" style="{{ $row($bi++) }}">
            <div><a href="{{ route('corex.contacts.show', $b['id']) }}" style="{{ $link }}">{{ $b['name'] }}</a></div>
            <div style="{{ $dim }}">{{ $b['score'] }}% match</div>
        </div>
    @endforeach
    @if($bi === 0)<div data-qa="pop-entry" style="{{ $row(0) }} {{ $dim }}">No matched buyers.</div>@endif
    <div data-qa="pop-entry" style="{{ $row(1) }}"><button type="button" @click="openBuyerPanel({{ $listing->id }})" style="background:none; border:none; padding:0; font-size:.75rem; cursor:pointer; {{ $link }}">All matched buyers for this advert →</button></div>
@elseif($kind === 'price')
    <div style="{{ $ph }}">Price history</div>
    <div data-qa="pop-entry" style="{{ $row(0) }}"><div style="{{ $bold }}">Now {{ !empty($adv['price']) ? $money($adv['price']) : '-' }}</div></div>
    @php $pi = 1; @endphp
    @foreach($adv['adverts'] ?? [] as $a)
        @foreach($a['changes'] ?? [] as $c)
            <div data-qa="pop-entry" style="{{ $row($pi++) }}">
                <div style="{{ $bold }}">{{ $money($c['old']) }} → {{ $money($c['new']) }}</div>
                <div style="{{ $dim }}">{{ $c['date'] }} · {{ $a['portal'] }} {{ $a['ref'] }}</div>
            </div>
        @endforeach
    @endforeach
    @if($pi === 1)<div data-qa="pop-entry" style="{{ $row(1) }} {{ $dim }}">No price changes recorded.</div>@endif
@elseif($kind === 'adverts')
    {{-- 13.12: one entry per advert, newest listed first, a hairline between entries. `$split` (the "Listed by N agencies" pill only) adds the "Not the same property" button; the Alpine scope `miSplit` is on the pill's wrapper. --}}
    @php
        $entries = collect($adv['adverts'] ?? [])->values();
        $mark = 'padding:0 6px; font-size:.6875rem; font-weight:700; background: rgba(15,23,42,.82); color:#fff; border-radius:3px; text-decoration:none; line-height:1.5;';
    @endphp
    <div style="{{ $ph }}">Adverts on this property</div>
    @if($split)
        <div x-show="note" style="display:none; padding: 4px 8px; margin-bottom: 2px; background: var(--surface-2); border-radius: 4px;" data-qa="split-note" role="status">Split off as its own entry · <a x-show="viewHref" :href="viewHref" target="_blank" rel="noopener" data-qa="split-view" style="color: var(--text-primary); font-weight:700; text-decoration: underline; text-decoration-color: var(--brand-icon);">View</a><span x-show="viewHref"> · </span><button type="button" @click="undo()" data-qa="split-undo" style="background:none; border:none; padding:0; font-size:.75rem; font-weight:700; color: var(--text-primary); text-decoration: underline; text-decoration-color: var(--brand-icon); cursor:pointer;">Undo</button></div>
    @endif
    @foreach($entries as $i => $a)
        @php
            $state = !$a['active'] ? 'Not seen since ' . ($a['last_seen'] ?? '-') : (($a['status'] && $a['status'] !== 'active') ? ucfirst(str_replace('_', ' ', $a['status'])) : 'Active');
            $aid = (int) ($a['id'] ?? 0);
        @endphp
        <div data-qa="pop-advert" data-advert-id="{{ $aid }}" style="{{ $row($i) }}"
             @if($split && $aid) x-show="!st[{{ $aid }}].gone" :style="{ borderTop: rule({{ $aid }}) ? '1px solid var(--border)' : '0', opacity: st[{{ $aid }}].busy ? .45 : 1 }" @endif>
            @php
                $who = $a['owner'] ? 'Owner (private)' : ($a['agency'] ?: null);
                $head = collect([$who ? '<strong>' . e($who) . '</strong>' : null, !empty($a['agent']) ? e($a['agent']) : null])->filter()->implode(' · ');
                $head .= $head !== '' ? ' · ' : '';
                $sole = $a['sole_mandate'] ?? null;
                $line2 = array_values(array_filter([
                    $a['price'] ? $money($a['price']) : null,
                    $a['listed'] ? 'listed ' . $a['listed'] : ($a['seen'] ? 'first seen ' . $a['seen'] : null),
                    $state,
                    $sole ? (is_string($sole) ? $sole : 'Sole mandate') : null,
                ]));
            @endphp
            <div>
                {!! $head !!}
                @if($a['url'])<a href="{{ $a['url'] }}" target="_blank" rel="noopener" data-qa="pop-mark" title="{{ $a['active'] ? 'Open the advert' : 'Not seen since ' . ($a['last_seen'] ?? '-') . ' - the link may no longer work' }}" style="{{ $mark }}{{ $a['active'] ? '' : ' background: rgba(100,116,139,.85); text-decoration: line-through;' }}">{{ $a['portal'] }}</a>@else<span style="{{ $mark }}">{{ $a['portal'] }}</span>@endif
            </div>
            <div style="color: var(--text-secondary);">{{ implode(' · ', $line2) }}</div>
            @if($split && $aid && ($a['can_split'] ?? true))
                <div style="text-align: right; margin-top: 3px;">
                    <button type="button" data-qa="not-same" @click="press({{ $aid }}, $event)" :disabled="st[{{ $aid }}].busy"
                            :style="{ background: st[{{ $aid }}].armed ? 'var(--ds-amber, #f59e0b)' : 'none', color: st[{{ $aid }}].armed ? '#111827' : 'var(--text-primary)', padding: st[{{ $aid }}].armed ? '1px 6px' : '0', borderRadius: '3px' }"
                            style="border:none; font-size:.75rem; font-weight:600; text-decoration: underline; text-decoration-color: var(--brand-icon); cursor:pointer;"
                            x-text="label({{ $aid }})">Not the same property</button>
                </div>
            @endif
        </div>
    @endforeach
@elseif($kind === 'fit')
    @php $fit = $adv['buyer_fit'] ?? []; $fs = $B::summary($fit); $fi = 0; @endphp
    <div style="{{ $ph }}">{{ $fit['first_name'] ?? 'Buyer' }} · {{ $fs['ok'] }} of {{ $fs['total'] }} fit @if(isset($fit['score'])) · {{ $fit['score'] }}% match @endif</div>
    @foreach($fit['fields'] ?? [] as $f)
        @php $parts = array_values(array_filter([trim((string) ($f['wants'] ?? '')) !== '' ? 'wants ' . $f['wants'] : null, trim((string) ($f['has'] ?? '')) !== '' ? 'has ' . $f['has'] : null])); @endphp
        <div data-qa="pop-entry" style="{{ $row($fi++) }}">
            <div style="{{ $bold }}">{{ $f['label'] ?? ucfirst($f['key'] ?? '') }} <span style="color: {{ $B::color($f['state'] ?? null) }};">{{ $B::mark($f['state'] ?? null) }}</span></div>
            <div style="{{ $dim }}">{{ $parts ? implode(' · ', $parts) : ($B::state($f['state'] ?? null) === 'na' ? 'not asked for' : 'nothing recorded') }}</div>
        </div>
    @endforeach
    @foreach($fit['must_haves'] ?? [] as $m)
        <div data-qa="pop-entry" style="{{ $row($fi++) }}">
            <div style="{{ $bold }}">Must-have: {{ $m['label'] }} <span style="color: {{ $B::color($m['state'] ?? null) }};">{{ $B::mark($m['state'] ?? null) }}</span></div>
        </div>
    @endforeach
    @foreach($fit['deal_breakers'] ?? [] as $d)
        <div data-qa="pop-entry" style="{{ $row($fi++) }}">
            <div style="{{ $bold }}">Deal-breaker: {{ $d['label'] }} <span style="color: {{ $B::color($d['state'] ?? null) }};">{{ $B::mark($d['state'] ?? null) }}</span></div>
        </div>
    @endforeach
@elseif($kind === 'alerts')
    <div style="{{ $ph }}">Portal alert emails</div>
    @php $ai = 0; @endphp
    @foreach($adv['alerts'] ?? [] as $al)
        <div data-qa="pop-entry" style="{{ $row($ai++) }}">
            <div style="{{ $bold }}">{{ $al['portal'] }} alert {{ $al['number'] }} · asking {{ $money($al['price']) }}@if($al['original'] && $al['original'] !== $al['price']) (was {{ $money($al['original']) }})@endif</div>
            <div style="{{ $dim }}">first {{ $al['first'] ?? '-' }} · last {{ $al['last'] ?? '-' }}@if($al['mandated']) · flagged mandated @endif</div>
            @foreach($al['changes'] as $c)<div style="{{ $dim }}">{{ $c['date'] }}: {{ $money($c['old']) }} → {{ $money($c['new']) }}</div>@endforeach
        </div>
    @endforeach
    @if($ai === 0)<div data-qa="pop-entry" style="{{ $row(0) }} {{ $dim }}">No alerts.</div>@endif
@elseif($kind === 'market')
    @php $cx = $adv['context'] ?? null; $mi = 0; @endphp
    <div style="{{ $ph }}">Sold nearby</div>
    @foreach(array_slice($cx['nearby_sales'] ?? [], 0, 5) as $ns)
        <div data-qa="pop-entry" style="{{ $row($mi++) }}">
            <div style="{{ $bold }}">{{ \App\Services\Prospecting\MicWork\MicWorkList::tidyLine($ns['address'] ?? '') ?: 'Address not shown' }}</div>
            <div style="{{ $dim }}">{{ !empty($ns['sold_on']) ? \Carbon\Carbon::parse($ns['sold_on'])->format('j M Y') . ' · ' : '' }}{{ $money($ns['price']) }}@if(!empty($ns['r_per_m2'])) · R {{ number_format($ns['r_per_m2']) }}/m²@endif</div>
        </div>
    @endforeach
    @if($mi === 0)<div data-qa="pop-entry" style="{{ $row(0) }} {{ $dim }}">{{ $cx ? 'No recent sales found nearby.' : 'Market context is still being built for this property.' }}</div>@endif
    @if($cx)
        <div style="{{ $ph }} margin-top:6px; border-top: 1px solid var(--border); padding-top: 8px;">This suburb</div>
        @if(!empty($cx['suburb_avg_sold_12m']))
            <div data-qa="pop-entry" style="{{ $row(0) }}">
                <div style="{{ $bold }}">Average sold {{ $money($cx['suburb_avg_sold_12m']) }}</div>
                <div style="{{ $dim }}">{{ $cx['suburb_avg_sold_n'] }} sales · {{ $cx['sale_class'] ?? 'all types' }} · last 12 months @if(($cx['asking_vs_avg_sold_pct'] ?? null) !== null) · asking is {{ abs($cx['asking_vs_avg_sold_pct']) }}% {{ $cx['asking_vs_avg_sold_pct'] >= 0 ? 'above' : 'below' }} the average @endif</div>
            </div>
        @else
            <div data-qa="pop-entry" style="{{ $row(0) }} {{ $dim }}">Not enough sales in this suburb to compare.</div>
        @endif
        @if(!empty($cx['suburb_r_per_m2']) && ($cx['own_vs_suburb_pct'] ?? null) !== null)
            <div data-qa="pop-entry" style="{{ $row(1) }}">
                <div style="{{ $bold }}">Asking R {{ number_format($cx['own_r_per_m2']) }}/m²</div>
                <div style="{{ $dim }}">{{ abs($cx['own_vs_suburb_pct']) }}% {{ $cx['own_vs_suburb_pct'] >= 0 ? 'above' : 'below' }} the suburb R {{ number_format($cx['suburb_r_per_m2']) }}/m² · on {{ $cx['own_size_basis'] ?? 'erf' }} size</div>
            </div>
        @endif
    @endif
    <div style="{{ $ph }} margin-top:6px; border-top: 1px solid var(--border); padding-top: 8px;">Deeds</div>
    @if($adv['deeds_info'] ?? null)
        <div data-qa="pop-entry" style="{{ $row(0) }}">
            <div style="{{ $bold }}">Registered {{ $adv['deeds_info']['registered'] ?? '-' }}</div>
            <div style="{{ $dim }}">last sale {{ !empty($adv['deeds_info']['price']) ? $money($adv['deeds_info']['price']) : '-' }} · bond {{ !empty($adv['deeds_info']['bond']) ? $money($adv['deeds_info']['bond']) : '-' }}</div>
        </div>
    @else
        <div data-qa="pop-entry" style="{{ $row(0) }} {{ $dim }}">Deeds: not captured yet.</div>
    @endif
@endif
</div>
