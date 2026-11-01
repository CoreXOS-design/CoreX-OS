{{-- Content of one popover of the Adverts card (mic-work-tab.md 13.3). Used inside <x-dropdown> (the shared CoreX popover), so Esc / click-outside / one-open-at-a-time come from there.
     Inputs: $kind = buyers | price | adverts | alerts | market ; $adv = the file record (MicWorkList::forFiles) ; $listing ; $money. --}}
@php
    $pt = 'font-size:.75rem; color: var(--text-primary); line-height:1.5;';
    $ph = 'font-weight:700; color: var(--text-primary); font-size:.75rem; margin-bottom:2px;';
@endphp
<div style="padding:4px 6px; max-height:22rem; overflow-y:auto; {{ $pt }}" @keydown.escape.window="$dispatch('close')">
@if($kind === 'buyers')
    <div style="{{ $ph }}">Matched buyers · {{ $adv['buyers_strong'] ?? $adv['buyers'] ?? 0 }} strong · {{ $adv['buyers_mid'] ?? 0 }} mid</div>
    @forelse($adv['buyer_names'] ?? [] as $b)
        <div><a href="{{ route('corex.contacts.show', $b['id']) }}" style="color: var(--brand-icon); text-decoration:none;">{{ $b['name'] }}</a> <span style="color: var(--text-secondary);">· {{ $b['score'] }}% match</span></div>
    @empty
        <div style="color: var(--text-secondary);">No matched buyers.</div>
    @endforelse
    <div style="margin-top:4px;"><button type="button" @click="openBuyerPanel({{ $listing->id }})" style="background:none; border:none; padding:0; font-size:.75rem; color: var(--brand-icon); cursor:pointer;">All matched buyers for this advert →</button></div>
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
    <div style="{{ $ph }}">Adverts on this property</div>
    @foreach($adv['adverts'] ?? [] as $a)
        <div>
            {{ $a['portal'] }} · @if($a['url'])<a href="{{ $a['url'] }}" target="_blank" rel="noopener" title="{{ $a['active'] ? 'Open the advert' : 'Not seen since ' . ($a['last_seen'] ?? '-') . ' - the link may no longer work' }}" style="color: {{ $a['active'] ? 'var(--brand-icon)' : 'var(--text-secondary)' }}; text-decoration: {{ $a['active'] ? 'none' : 'underline dotted' }};">{{ $a['ref'] }}</a>@else{{ $a['ref'] }}@endif
            · {{ $a['owner'] ? 'Owner (private)' : ($a['agency'] ?: '-') }}@if($a['agent']) · {{ $a['agent'] }}@endif
            · {{ $a['price'] ? $money($a['price']) : '-' }}
            · {{ !$a['active'] ? 'Not seen since ' . ($a['last_seen'] ?? '-') : (($a['status'] && $a['status'] !== 'active') ? ucfirst(str_replace('_', ' ', $a['status'])) : 'Active') }}
            · first seen {{ $a['seen'] ?? '-' }}
        </div>
    @endforeach
@elseif($kind === 'alerts')
    <div style="{{ $ph }}">Portal alert emails for this property</div>
    @forelse($adv['alerts'] ?? [] as $al)
        <div>
            {{ $al['portal'] }} alert {{ $al['number'] }} · first {{ $al['first'] ?? '-' }} · last {{ $al['last'] ?? '-' }} · asking {{ $money($al['price']) }}@if($al['original'] && $al['original'] !== $al['price']) (was {{ $money($al['original']) }})@endif @if($al['mandated']) · <span style="color:#7c3aed;">flagged mandated</span>@endif
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
