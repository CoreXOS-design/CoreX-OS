{{-- Content of one popover of the Adverts card (mic-work-tab.md 13.3). Used inside <x-dropdown> (the shared CoreX popover), so Esc / click-outside / one-open-at-a-time come from there.
     Inputs: $kind = buyers | price | adverts | alerts | market ; $adv = the file record (MicWorkList::forFiles) ; $listing ; $money. --}}
@php
    $pt = 'font-size:.75rem; color: var(--text-primary); line-height:1.5;';
    $ph = 'font-weight:700; color: var(--text-primary); font-size:.75rem; margin-bottom:2px;';
    $split = !empty($split);
@endphp
{{-- With the split button the popover stays open on a click inside it (the shared dropdown would close it); a click that is not on a split button turns an armed one back to normal --}}
<div style="padding:4px 6px; max-height:22rem; overflow-y:auto; {{ $pt }}" @keydown.escape.window="$dispatch('close')" @if($split) @click.stop="if ($event.splitBtn === undefined) disarm()" @endif>
@if($kind === 'buyers')
    <div style="{{ $ph }}">Matched buyers · {{ $adv['buyers_strong'] ?? $adv['buyers'] ?? 0 }} strong · {{ $adv['buyers_mid'] ?? 0 }} mid</div>
    @forelse($adv['buyer_names'] ?? [] as $b)
        <div><a href="{{ route('corex.contacts.show', $b['id']) }}" style="color: var(--text-primary); text-decoration: underline; text-decoration-color: var(--brand-icon);">{{ $b['name'] }}</a> <span style="color: var(--text-secondary);">· {{ $b['score'] }}% match</span></div>
    @empty
        <div style="color: var(--text-secondary);">No matched buyers.</div>
    @endforelse
    <div style="margin-top:4px;"><button type="button" @click="openBuyerPanel({{ $listing->id }})" style="background:none; border:none; padding:0; font-size:.75rem; color: var(--text-primary); text-decoration: underline; text-decoration-color: var(--brand-icon); cursor:pointer;">All matched buyers for this advert →</button></div>
@elseif($kind === 'price')
    @php $allChanges = collect($adv['adverts'] ?? [])->flatMap(fn ($a) => collect($a['changes'])->map(fn ($c) => $c + ['ref' => $a['ref'], 'portal' => $a['portal']]))->sortBy('day')->values(); @endphp
    <div style="{{ $ph }}">Price history</div>
    <div>Now {{ !empty($adv['price']) ? $money($adv['price']) : '-' }}</div>
    @forelse($allChanges as $c)
        <div>{{ $c['date'] }} · {{ $c['portal'] }} {{ $c['ref'] }} · {{ $money($c['old']) }} → {{ $money($c['new']) }}</div>
    @empty
        <div style="color: var(--text-secondary);">No price changes recorded.</div>
    @endforelse
    <div style="margin-top:4px; color: var(--text-secondary);">A cut counts only when the same advert was read at two prices, or the portal says Reduced.</div>
@elseif($kind === 'adverts')
    {{-- 13.12: one entry per advert, newest listed first, a hairline between entries. `$split` (the "Listed by N agencies" pill only) adds the "Not the same property" button; the Alpine scope `miSplit` is on the pill's wrapper. --}}
    @php
        $entries = collect($adv['adverts'] ?? [])->values();
        $mark = 'padding:0 6px; font-size:.6875rem; font-weight:700; background: rgba(15,23,42,.82); color:#fff; border-radius:3px; text-decoration:none; line-height:1.5;';
    @endphp
    <div style="{{ $ph }} padding: 0 8px 4px;">Adverts on this property</div>
    @if($split)
        <div x-show="note" style="display:none; padding: 4px 8px; margin-bottom: 2px; background: var(--surface-2); border-radius: 4px;" data-qa="split-note" role="status">Split off as its own entry · <button type="button" @click="undo()" data-qa="split-undo" style="background:none; border:none; padding:0; font-size:.75rem; font-weight:700; color: var(--text-primary); text-decoration: underline; text-decoration-color: var(--brand-icon); cursor:pointer;">Undo</button></div>
    @endif
    @foreach($entries as $i => $a)
        @php
            $state = !$a['active'] ? 'Not seen since ' . ($a['last_seen'] ?? '-') : (($a['status'] && $a['status'] !== 'active') ? ucfirst(str_replace('_', ' ', $a['status'])) : 'Active');
            $aid = (int) ($a['id'] ?? 0);
        @endphp
        <div data-qa="pop-advert" data-advert-id="{{ $aid }}" style="padding: 7px 8px; {{ $i > 0 ? 'border-top: 1px solid var(--border);' : '' }}"
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
@elseif($kind === 'alerts')
    <div style="{{ $ph }}">Portal alert emails for this property</div>
    @forelse($adv['alerts'] ?? [] as $al)
        <div>
            {{ $al['portal'] }} alert {{ $al['number'] }} · first {{ $al['first'] ?? '-' }} · last {{ $al['last'] ?? '-' }} · asking {{ $money($al['price']) }}@if($al['original'] && $al['original'] !== $al['price']) (was {{ $money($al['original']) }})@endif @if($al['mandated']) · <span style="color: var(--text-primary);">flagged mandated</span>@endif
            @foreach($al['changes'] as $c)<div style="color: var(--text-secondary); padding-left:10px;">{{ $c['date'] }}: {{ $money($c['old']) }} → {{ $money($c['new']) }}</div>@endforeach
        </div>
    @empty
        <div style="color: var(--text-secondary);">No alerts.</div>
    @endforelse
@elseif($kind === 'market')
    @php $cx = $adv['context'] ?? null; @endphp
    <div style="{{ $ph }}">Sold nearby</div>
    @forelse(array_slice($cx['nearby_sales'] ?? [], 0, 5) as $ns)
        <div>{{ \App\Services\Prospecting\MicWork\MicWorkList::tidyLine($ns['address'] ?? '') ?: 'Address not shown' }} · {{ !empty($ns['sold_on']) ? \Carbon\Carbon::parse($ns['sold_on'])->format('j M Y') : '-' }} · {{ $money($ns['price']) }}@if(!empty($ns['r_per_m2'])) · R {{ number_format($ns['r_per_m2']) }}/m²@endif</div>
    @empty
        <div style="color: var(--text-secondary);">{{ $cx ? 'No recent sales found nearby.' : 'Market context is still being built for this property.' }}</div>
    @endforelse
    @if($cx)
        <div style="{{ $ph }} margin-top:6px;">This suburb</div>
        @if(!empty($cx['suburb_avg_sold_12m']))
            <div>Average sold ({{ $cx['sale_class'] ?? 'all types' }}, last 12 months): <strong>{{ $money($cx['suburb_avg_sold_12m']) }}</strong> from {{ $cx['suburb_avg_sold_n'] }} sales @if(($cx['asking_vs_avg_sold_pct'] ?? null) !== null) · asking is {{ abs($cx['asking_vs_avg_sold_pct']) }}% {{ $cx['asking_vs_avg_sold_pct'] >= 0 ? 'above' : 'below' }} the average @endif</div>
        @else
            <div style="color: var(--text-secondary);">Not enough sales in this suburb to compare.</div>
        @endif
        @if(!empty($cx['suburb_r_per_m2']) && ($cx['own_vs_suburb_pct'] ?? null) !== null)
            <div>Asking R {{ number_format($cx['own_r_per_m2']) }}/m² on {{ $cx['own_size_basis'] ?? 'erf' }} size is {{ abs($cx['own_vs_suburb_pct']) }}% {{ $cx['own_vs_suburb_pct'] >= 0 ? 'above' : 'below' }} the suburb R {{ number_format($cx['suburb_r_per_m2']) }}/m².</div>
        @endif
    @endif
    <div style="{{ $ph }} margin-top:6px;">Deeds</div>
    @if($adv['deeds_info'] ?? null)
        <div>Registered {{ $adv['deeds_info']['registered'] ?? '-' }} · last sale {{ !empty($adv['deeds_info']['price']) ? $money($adv['deeds_info']['price']) : '-' }} · bond {{ !empty($adv['deeds_info']['bond']) ? $money($adv['deeds_info']['bond']) : '-' }}</div>
    @else
        <div style="color: var(--text-secondary);">Deeds: not captured yet.</div>
    @endif
@endif
</div>
