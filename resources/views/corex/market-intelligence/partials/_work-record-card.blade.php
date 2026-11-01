{{-- MIC Work tab record card (mic-work-tab.md 2.1 E): one file's adverts, price history, alerts, deeds, nearby sales, suburb line, matched buyers, our history.
     Input: $r = one MicWorkList::forFiles() record. Used under a row of the classic list (fetched on first expand) and in the property-per-row view. --}}
@php
    $money = $money ?? fn ($v) => 'R ' . number_format((float) $v, 0, '.', ',');
@endphp
<div data-qa="record-card-body" style="padding: 6px 10px 10px 14px; background: var(--surface-2); font-size: .75rem; color: var(--text-secondary);">
                    <div style="font-weight: 700; margin: 2px 0;">Adverts</div>
@foreach($r['adverts'] as $a)
    <div style="display:grid; grid-template-columns: 40px 140px 1fr 110px 100px 100px 90px; gap: 8px; padding: 1px 0; {{ $a['active'] ? '' : 'opacity:.6;' }}">
        <span>{{ $a['portal'] }}</span>
        <span>@if($a['url'])<a href="{{ $a['url'] }}" target="_blank" rel="noopener" style="color: var(--brand-icon); text-decoration:none;">{{ $a['ref'] }}</a>@else{{ $a['ref'] }}@endif</span>
        <span>{{ $a['owner'] ? 'Owner (private)' . ($a['agent'] ? ' · ' . $a['agent'] : '') : trim(($a['agency'] ?: '-') . ($a['agent'] ? ' · ' . $a['agent'] : '')) }}</span>
        <span>{{ $a['price'] ? $money($a['price']) : '-' }}</span>
        <span>{{ $a['listed'] ? 'listed ' . $a['listed'] : 'first seen ' . ($a['seen'] ?? '-') }}</span><span></span>
        <span>{{ $a['active'] ? ($a['status'] && $a['status'] !== 'active' ? ucfirst(str_replace('_', ' ', $a['status'])) : 'Active') : 'Not seen since ' . ($a['last_seen'] ?? '-') }}</span>
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
    <div data-qa="alert-line"><svg viewBox="0 0 16 12" width="12" height="9" fill="none" stroke="currentColor" stroke-width="1.4" style="display:inline-block;"><rect x="1" y="1" width="14" height="10" rx="1.5"/><path d="M1.5 2.2 8 7l6.5-4.8"/></svg> {{ $al['portal'] }} {{ $al['number'] }} · first {{ $al['first'] ?? '-' }} · last {{ $al['last'] ?? '-' }} · asking {{ $money($al['price']) }}@if($al['original'] && $al['original'] !== $al['price']) · was {{ $money($al['original']) }}@endif
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
