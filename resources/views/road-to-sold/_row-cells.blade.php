{{--
    The five data cells of a Road to Sold list row (road-to-sold.md 23.24): WHY - start here, ADDRESS, PRICE / DAYS ON MARKET / AGENCY, OWNER, LAST OUTCOME.
    ONE markup for the home list (Stock loaded) and every station list, so the rows read the same everywhere.
    Inputs: $r (a CallListService row), $money; optional $intakeProspects (tracked property id => prospect) for the owner cell.
--}}
@php $lo = $r['last_outcome'] ?? null; @endphp
<style>.rts-next { display: inline-block; margin-right: 4px; padding: 0 5px; border-radius: 3px; background: var(--brand-button, #0ea5e9); color: #fff; font-size: .5625rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; vertical-align: 1px; }</style>
<td class="px-3 py-2 font-semibold" style="color: var(--text-primary); min-width: 12rem;@if($nextRow ?? false) box-shadow: inset 3px 0 0 var(--brand-button, #0ea5e9);@endif" data-qa="intake-why">
    <div class="rts-clamp2" title="{{ $r['why'] }}">@if($nextRow ?? false)<span class="rts-next" data-qa="next-marker" title="This is the next thing to do">Next</span> @endif @if(($r['origin'] ?? null) === 'follow_up')<span class="rts-chip">Follow-up</span> @endif{{ $r['why'] ?: '-' }}</div>
</td>
<td class="px-3 py-2" style="color: var(--text-primary);">
    <div class="font-semibold rts-nw" title="{{ $r['address'] }}">{{ $r['street'] !== '' ? $r['street'] : $r['address'] }}</div>
    @if($r['street'] !== '' && $r['suburb'])<div class="rts-nw" style="color: var(--text-muted);">{{ $r['suburb'] }}</div>@endif
</td>
{{-- price, days on market and the listing agency share one cell (22.22): the row's actions stay on screen at 1366 wide --}}
<td class="px-3 py-2" style="color: var(--text-primary);" data-qa="intake-market">
    <div class="font-semibold rts-nw" title="Price">{{ $money($r['price']) ?: '-' }}</div>
    <div class="rts-nw" style="color: var(--text-muted);" title="{{ $r['dom'] !== null ? $r['dom'] . ' days on market' : 'Days on market not known' }}{{ $r['agency_name'] ? ' - listed with ' . $r['agency_name'] : '' }}">{{ $r['dom'] !== null ? $r['dom'] . ' days' : '-' }}@if($r['agency_name']) · {{ $r['agency_name'] }}@endif</div>
</td>
<td class="px-3 py-2" style="color: var(--text-primary);">
    @if($r['owner_name'])
        <div class="font-semibold">{{ $r['owner_name'] }}</div>
        @if($r['owner_phone'])<div style="color: var(--text-muted);">{{ $r['owner_phone'] }}</div>@endif
    @elseif($r['find_owner_url'] && ! empty($intakeProspects[$r['tracked_property_id']]))
        <span style="color: var(--text-muted);">Not known yet</span>
    @elseif($r['find_owner_url'])
        <a href="{{ $r['find_owner_url'] }}" class="corex-btn-outline corex-btn-xs" data-rts-popup data-step-label="Find the owner - {{ $r['address'] }}">Find the owner</a>
    @else
        -
    @endif
</td>
<td class="px-3 py-2 rts-col-last" style="color: var(--text-primary);">
    @if($lo)
        <div title="{{ $lo['note'] }}">{{ $lo['label'] }}@if($lo['streak'] > 1) <span style="color: var(--text-muted);">({{ $lo['streak'] }} in a row)</span>@endif</div>
        <div style="color: var(--text-muted);">{{ $lo['when']->format('j M') }}@if($lo['by']) · {{ $lo['by'] }}@endif</div>
    @else
        -
    @endif
</td>
