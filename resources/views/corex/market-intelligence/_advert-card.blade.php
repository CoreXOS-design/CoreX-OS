{{-- MIC Work tab, Adverts view (mic-work-tab.md 13.3 / 13.3a / 13.3b / 13.4 / 13.4a): one row per property file, two blocks side by side.
     LEFT = the advert, built like a P24 result card. RIGHT = our intelligence, with the same buttons the List row has.
     Rendered by _listing-row.blade.php when $rowMode === 'advert', so it reads the row's own variables ($listing, $suggested, $isUnclaimed, $claimedByMe, $displayAddress, ...)
     plus $adv = this file's MicWorkList::forFiles() record. --}}
@php
    $money = fn ($v) => 'R ' . number_format((float) $v, 0, '.', ',');
    $f = $adv['facts'] ?? [];
    $type = trim((string) ($f['type'] ?? ''));
    $heading = trim((string) ($adv['card_heading'] ?? '')) !== ''
        ? trim((string) $adv['card_heading'])
        : trim(($f['beds'] ? $f['beds'] . ' Bedroom ' : '') . ($type !== '' ? $type : 'Property'));
    $thumbSrc = !empty($adv['thumbnail_path']) ? route('market-intelligence.thumbnail', $adv['thumbnail_advert_id'] ?? $listing->id) : ($adv['thumbnail_source_url'] ?? null);
    $placeholder = asset('images/mic-thumbnail-placeholder.svg');
    $text = trim((string) ($adv['description'] ?? '')) !== '' ? trim((string) $adv['description']) : trim((string) ($adv['description_snippet'] ?? ''));
    $textIsFull = trim((string) ($adv['description'] ?? '')) !== '';
    $adverts = $adv['adverts'] ?? [];
    $lead = $adverts[0] ?? null;
    $ownerSale = !empty($adv['is_owner']);
    $status = $adv['portal_status'] ?? null;
    // ribbons on the photo, in the spec's order
    $ribbons = [];
    if (!empty($adv['is_reduced'])) { $ribbons[] = ['Reduced', 'var(--ds-crimson, #dc2626)', null]; }
    if ($status === 'under_offer') { $ribbons[] = ['Under offer', 'var(--ds-amber, #f59e0b)', null]; }
    if ($status === 'sold') { $ribbons[] = ['Sold', '#475569', null]; }
    if ($ownerSale) { $ribbons[] = ['Owner selling', 'var(--brand-icon, #0ea5e9)', null]; }
    if (!empty($adv['is_sole_mandate'])) { $ribbons[] = ['Sole mandate', '#7c3aed', ($adv['sole_mandate_source'] ?? '') === 'alert' ? 'Sole mandate (from a P24 alert)' : 'Sole mandate (from the advert wording)']; }
    // dates (13.3a): both when held, and the time on market by the section 0 rule
    $dateBits = [];
    if (!empty($adv['listed_date'])) { $dateBits[] = 'Listed ' . \Carbon\Carbon::parse($adv['listed_date'])->format('j M'); }
    if (!empty($adv['first_seen'])) { $dateBits[] = 'First seen ' . \Carbon\Carbon::parse($adv['first_seen'])->format('j M'); }
    $domLabel = (string) ($adv['dom']['label'] ?? '-');
    if ($domLabel !== '-' && $domLabel !== '') { $dateBits[] = $domLabel === 'new' ? 'new' : (str_contains($domLabel, '~') || str_ends_with($domLabel, '+') ? $domLabel : '~' . $domLabel) . ($domLabel === 'new' ? '' : ' days'); }
    $who = $ownerSale ? 'Owner (private)' : ($adv['agencies'][0] ?? null);
    $cardToggle = "card = !card; if (card && !loaded) { loaded = true; fetch(location.pathname + location.search + (location.search ? '&' : '?') + '_card=" . (int) ($adv['tp_id'] ?? 0) . "', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(r => r.ok ? r.text() : Promise.reject()).then(h => html = h).catch(() => { html = '<div style=&quot;padding:10px 14px;font-size:.75rem;color:var(--ds-crimson,#dc2626);&quot;>The record card could not load - try again.</div>'; loaded = false; }); }";
    $btn = 'display:inline-flex; align-items:center; justify-content:center; gap:4px; height:28px; padding:0 10px; font-size:.75rem; font-weight:600; border-radius:6px; background: var(--surface); border:1px solid var(--border); color: var(--text-secondary); cursor:pointer; text-decoration:none; white-space:nowrap;';
    $line = 'font-size:.75rem; color: var(--text-secondary); line-height:1.35; overflow:hidden; text-overflow:ellipsis;';
    $lab = 'font-weight:700; color: var(--text-primary);';
    $iconStyle = 'width:14px; height:14px; flex-shrink:0; color: var(--text-muted);';
    $otherAdverts = array_slice($adverts, 1);
    $lastMove = $lead && !empty($lead['changes']) ? end($lead['changes']) : null;
    $cx = $adv['context'] ?? null;
@endphp

<article class="mi-advert" data-qa="advert-card" data-listing-id="{{ $listing->id }}">

    {{-- LEFT: the advert --}}
    <div data-qa="advert-left" class="mi-advert-left">
        <div class="mi-advert-photo">
            @if(!empty($adv['portal_url']))<a href="{{ $adv['portal_url'] }}" target="_blank" rel="noopener" data-qa="advert-photo-link" title="Open this advert on the portal" style="display:block; width:100%; height:100%;">@endif
                <img src="{{ $thumbSrc ?: $placeholder }}" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src='{{ $placeholder }}';" style="width:100%; height:100%; object-fit:cover; display:block;">
            @if(!empty($adv['portal_url']))</a>@endif
            <div style="position:absolute; top:8px; left:8px; display:flex; flex-direction:column; align-items:flex-start; gap:4px; pointer-events:none;">
                @foreach($ribbons as [$rt, $rc, $rtitle])
                    <span data-qa="ribbon" title="{{ $rtitle ?? $rt }}" style="padding:2px 8px; font-size:.6875rem; font-weight:700; color:#fff; background: {{ $rc }}; border-radius:3px; box-shadow:0 1px 3px rgba(0,0,0,.3);">{{ $rt }}</span>
                @endforeach
            </div>
            @if((int) ($adv['group_member_count'] ?? 0) >= 2)
                <span data-qa="listed-by" style="position:absolute; bottom:8px; left:8px; padding:2px 8px; font-size:.6875rem; font-weight:600; color:#fff; background: rgba(15,23,42,.78); border-radius:3px;">Listed by {{ (int) $adv['group_member_count'] }} agencies</span>
            @endif
            <span style="position:absolute; bottom:8px; right:8px; display:flex; gap:4px; align-items:center;">
                @if(!empty($adv['alert']))
                    <span data-qa="envelope" title="{{ $adv['alert_tip'] ?? 'In a P24 alert email' }}" style="display:inline-flex; padding:2px 5px; background: rgba(15,23,42,.78); color:#fff; border-radius:3px;"><svg viewBox="0 0 16 12" width="14" height="10" fill="none" stroke="currentColor" stroke-width="1.4"><rect x="1" y="1" width="14" height="10" rx="1.5"/><path d="M1.5 2.2 8 7l6.5-4.8"/></svg></span>
                @endif
                @if(!empty($adv['p24']))<span style="padding:1px 5px; font-size:.625rem; font-weight:700; background: rgba(15,23,42,.78); color:#fff; border-radius:3px;">P24</span>@endif
                @if(!empty($adv['pp']))<span style="padding:1px 5px; font-size:.625rem; font-weight:700; background: rgba(15,23,42,.78); color:#fff; border-radius:3px;">PP</span>@endif
            </span>
        </div>

        <div class="mi-advert-body" x-data="{ more: false }">
            <div style="display:flex; align-items:baseline; gap:8px; flex-wrap:wrap;" data-qa="advert-price">
                <span style="font-size:1.375rem; font-weight:800; color: var(--text-primary);">{{ !empty($adv['price']) ? $money($adv['price']) : '—' }}</span>
                @if(!empty($adv['old_price']) && !empty($adv['cut_amount']))
                    <span style="font-size:.875rem; color: var(--text-muted); text-decoration: line-through;">{{ $money($adv['old_price']) }}</span>
                    <span style="font-size:.75rem; font-weight:600; color: var(--ds-crimson, #dc2626);">cut {{ $money($adv['cut_amount']) }}{{ !empty($adv['cut_date']) ? ' · ' . \Carbon\Carbon::parse($adv['cut_date'])->format('j M') : '' }}</span>
                @endif
            </div>
            <div style="font-size:.9375rem; font-weight:700; color: var(--text-primary);" data-qa="advert-heading">{{ $heading }}</div>
            <div style="font-size:.8125rem; color: var(--text-secondary);" data-qa="advert-place">{{ $adv['suburb'] ?? $listing->suburb }}@if($hasAddress) · {{ \Illuminate\Support\Str::limit($displayAddress, 70) }}@endif</div>

            @if($text !== '')
                <div style="font-size:.8125rem; color: var(--text-secondary); line-height:1.4;" data-qa="advert-description">
                    <div :style="more ? '' : 'display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden;'">{{ $text }}</div>
                    @if($textIsFull || mb_strlen($text) > 150)<button type="button" @click.stop="more = !more" style="background:none; border:none; padding:0; font-size:.75rem; color: var(--brand-icon); cursor:pointer;"><span x-text="more ? 'less' : 'more'">more</span></button>@endif
                </div>
            @endif

            <div style="display:flex; flex-wrap:wrap; align-items:center; gap:4px 12px; margin-top:2px; font-size:.75rem; color: var(--text-secondary);" data-qa="advert-icons">
                @if(!empty($f['beds']))<span title="Bedrooms" style="display:inline-flex; align-items:center; gap:4px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="{{ $iconStyle }}"><path d="M3 18v-7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v7M3 14h18M3 18v2M21 18v2M7 9V6h10v3"/></svg>{{ $f['beds'] }}</span>@endif
                @if(!empty($f['baths']))<span title="Bathrooms" style="display:inline-flex; align-items:center; gap:4px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="{{ $iconStyle }}"><path d="M4 12h16v3a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4v-3zM6 12V6a2 2 0 0 1 4 0M7 19l-1 2M17 19l1 2"/></svg>{{ $f['baths'] }}</span>@endif
                @if(!empty($f['garages']))<span title="Garages" style="display:inline-flex; align-items:center; gap:4px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="{{ $iconStyle }}"><path d="M5 17h14M6 17l1.5-6h9L18 17M7 17v2M17 17v2"/></svg>{{ $f['garages'] }}</span>@endif
                @if(!empty($adv['parking']))<span title="Parking bays" style="display:inline-flex; align-items:center; gap:4px;"><span style="font-weight:800; color: var(--text-muted);">P</span>{{ $adv['parking'] }}</span>@endif
                @if(!empty($adv['erf_size_m2']))<span title="Erf size">Erf {{ number_format((float) $adv['erf_size_m2']) }} m²</span>@endif
                @if(!empty($adv['floor_size_m2']))<span title="Floor size">Floor {{ number_format((float) $adv['floor_size_m2']) }} m²</span>@endif
            </div>

            <div style="{{ $line }} " data-qa="advert-source">
                @if($who){{ $who }}@endif @if(!empty($adv['agent_name'])){{ $who ? ' · ' : '' }}{{ $adv['agent_name'] }}@endif @if($lead && !empty($lead['ref'])) · <span style="color: var(--text-muted);">{{ $lead['ref'] }}</span>@endif
            </div>
            <div style="{{ $line }} " data-qa="advert-dates">
                {{ implode(' · ', $dateBits) }}
                @if(!empty($adv['is_stale']) && $adv['last_seen_days'] !== null)<span style="color: var(--text-muted);" data-qa="advert-stale"> · not seen since {{ $adv['last_seen_days'] }} d</span>@endif
            </div>
        </div>
    </div>

    {{-- RIGHT: our intelligence --}}
    <div data-qa="advert-right" class="mi-advert-right">
        <div style="display:flex; flex-wrap:wrap; align-items:center; gap:4px 8px;" data-qa="advert-score">
            @if($adv['score'] !== null)
                <span style="font-size:.875rem; font-weight:800; color: var(--text-primary);">Score {{ number_format($adv['score'], 0) }}</span>
                @foreach(array_slice($adv['reasons'] ?? [], 0, 2) as $rc)<span style="font-size:.6875rem; color: var(--text-muted);">· {{ \App\Services\Prospecting\MicWork\MicWorkList::REASON_LABELS[$rc] ?? ucfirst(str_replace('_', ' ', $rc)) }}</span>@endforeach
            @endif
            @if(!empty($adv['is_sole_mandate']))<span style="font-size:.6875rem; font-weight:600; color:#7c3aed;">Sole mandate ({{ ($adv['sole_mandate_source'] ?? '') === 'alert' ? 'from a P24 alert' : 'from the advert wording' }})</span>@endif
            @if(!empty($adv['pair_ids']))<span style="font-size:.625rem; font-weight:700; padding:0 5px; border:1px solid var(--ds-amber, #f59e0b); border-radius:8px; color: var(--ds-amber, #f59e0b);">Possible match</span>@endif
        </div>

        <div style="display:flex; flex-wrap:wrap; align-items:center; gap:6px;" data-qa="advert-buttons" @click.stop>
            @if($isCompanyStock)
                <span style="{{ $tagTeal }}" title="This is our agency's own listing - no pitch needed.">{{ $agencyLogoUrl ? 'Our listing' : 'OUR LISTING' }}</span>
            @else
                @include('corex.market-intelligence._suggested-action-chip', ['suggested' => $suggested, 'listing' => $listing])
                @if($isUnclaimed)
                    <form method="POST" action="{{ route('market-intelligence.claim', $listing->id) }}" data-tour="mic-claim" style="margin:0;">@csrf
                        <button type="submit" style="{{ $btn }} color: var(--brand-icon); border-color: var(--brand-icon);" title="Claim - reserve this listing for yourself. It leaves the canvass list and moves to My Claims.">Claim</button>
                    </form>
                @elseif($claimedByMe)
                    <form method="POST" action="{{ route('market-intelligence.release', $listing->id) }}" style="margin:0;">@csrf
                        <button type="submit" style="{{ $btn }}" title="Give this listing back to the pool.">Unclaim</button>
                    </form>
                @endif
            @endif
            @if($listing->tracked_property_id && ($canViewComments ?? false))
                @php $commentCount = (int) (($commentCounts ?? [])[$listing->tracked_property_id] ?? 0); @endphp
                <button type="button" @click.stop="openCommentsModal({{ $listing->tracked_property_id }})" style="{{ $btn }}" title="Comments on this property - view and add">Comment<span data-tp-comment-count="{{ $listing->tracked_property_id }}" style="font-weight:700;">{{ $commentCount > 0 ? ' ' . $commentCount : '' }}</span></button>
            @endif
            @if(!empty($adv['tp_id']))
                <a href="{{ route('market-intelligence.opportunities.show', ['tp' => $adv['tp_id']]) }}" style="{{ $btn }}" title="Open the property file to link the owner or a contact to it">Link contact</a>
            @endif
            @unless($isPropertyStock)
                <button type="button" @click.stop="$dispatch('open-slideover', { listingId: {{ $listing->id }}, trigger: $el })" style="{{ $btn }}" title="Property intel: the full detail of this advert">Property intel</button>
            @endunless
        </div>

        <div style="display:flex; flex-direction:column; gap:3px; flex:1; min-height:0;" data-qa="advert-lines">
            @if(($adv['buyers_strong'] ?? $adv['buyers'] ?? 0) > 0 || ($adv['buyers_mid'] ?? 0) > 0)
                <div style="{{ $line }}" data-qa="line-buyers"><span style="{{ $lab }}">Buyers</span> {{ $adv['buyers_strong'] ?? $adv['buyers'] }} strong · {{ $adv['buyers_mid'] ?? 0 }} mid @foreach($adv['buyer_names'] as $b) · <a href="{{ route('corex.contacts.show', $b['id']) }}" style="color: var(--brand-icon); text-decoration:none;">{{ $b['name'] }}</a>@endforeach</div>
            @endif
            @if($lastMove)
                <div style="{{ $line }}" data-qa="line-price-history"><span style="{{ $lab }}">Price history</span> {{ $money($lastMove['old']) }} → {{ $money($lastMove['new']) }} · {{ $lastMove['date'] }}</div>
            @endif
            @if($otherAdverts)
                <div style="{{ $line }}" data-qa="line-other-adverts"><span style="{{ $lab }}">Other adverts</span>
                    @foreach(array_slice($otherAdverts, 0, 3) as $oa){{ $loop->first ? '' : ' | ' }}{{ $oa['portal'] }} · {{ $oa['owner'] ? 'Owner' : ($oa['agency'] ?: '-') }} · {{ $oa['price'] ? $money($oa['price']) : '-' }}{{ !$oa['active'] ? ' · gone' : (($oa['status'] && $oa['status'] !== 'active') ? ' · ' . str_replace('_', ' ', $oa['status']) : '') }}@endforeach
                    @if(count($otherAdverts) > 3) | +{{ count($otherAdverts) - 3 }}@endif
                </div>
            @endif
            @if(($adv['alert_count'] ?? 0) > 0)
                <div style="{{ $line }}" data-qa="line-alerts"><span style="{{ $lab }}">P24 alerts</span>
                    <button type="button" @click.stop="{{ $cardToggle }}" style="background:none; border:none; padding:0; font-size:.75rem; color: var(--brand-icon); cursor:pointer;" title="Open the alert details">{{ $adv['alert_count'] }}@if(!empty($adv['alert_last_date'])) · last {{ \Carbon\Carbon::parse($adv['alert_last_date'])->format('j M') }}@endif @if(!empty($adv['alert_last_asking'])) · asking {{ $money($adv['alert_last_asking']) }}@endif</button>
                </div>
            @endif
            <div style="{{ $line }}" data-qa="line-deeds"><span style="{{ $lab }}">Deeds</span> @if($adv['deeds_info'] ?? null)registered {{ $adv['deeds_info']['registered'] ?? '-' }}@if(!empty($adv['deeds_info']['price'])) · last sale {{ $money($adv['deeds_info']['price']) }}@endif @else<span style="color: var(--text-muted);">Not captured yet</span>@endif</div>
            @if($cx && !empty($cx['nearby_sales']))
                <div style="{{ $line }}" data-qa="line-nearby"><span style="{{ $lab }}">Nearby sales</span>
                    @foreach(array_slice($cx['nearby_sales'], 0, 2) as $ns){{ $loop->first ? '' : ' | ' }}{{ $money($ns['price']) }}@if(!empty($ns['r_per_m2'])) (R {{ number_format($ns['r_per_m2']) }}/m²)@endif @if(!empty($ns['sold_on'])) · {{ \Carbon\Carbon::parse($ns['sold_on'])->format('M Y') }}@endif @endforeach
                </div>
            @endif
            @if($cx && !empty($cx['suburb_avg_sold_12m']))
                <div style="{{ $line }}" data-qa="line-suburb"><span style="{{ $lab }}">This suburb</span> avg sold {{ $money($cx['suburb_avg_sold_12m']) }}@if(isset($cx['asking_vs_avg_sold_pct']) && $cx['asking_vs_avg_sold_pct'] !== null) · asking {{ abs($cx['asking_vs_avg_sold_pct']) }}% {{ $cx['asking_vs_avg_sold_pct'] >= 0 ? 'above' : 'below' }} avg @endif</div>
            @endif
            @php $hist = []; foreach (array_slice($adv['claims'] ?? [], 0, 1) as $c) { $hist[] = 'claimed by ' . ($c['who'] ?? 'agent') . ' ' . ($c['at'] ?? ''); } if (!empty($adv['claimed_by']) && !$hist) { $hist[] = 'claimed by ' . $adv['claimed_by']; } if (!empty($adv['work_status'])) { $hist[] = ucfirst(str_replace('_', ' ', $adv['work_status'])); } @endphp
            @if($hist)
                <div style="{{ $line }}" data-qa="line-history"><span style="{{ $lab }}">Our history</span> {{ implode(' · ', $hist) }}</div>
            @endif
        </div>

        @if(!empty($adv['tp_id']))
            <div @click.stop><button type="button" data-qa="card-toggle" @click.stop="{{ $cardToggle }}" style="background:none; border:none; padding:0; font-size:.75rem; font-weight:600; color: var(--brand-icon); cursor:pointer;"><span x-text="card ? 'Full card ▴' : 'Full card ▾'">Full card ▾</span></button></div>
        @endif
    </div>
</article>

<style>
    /* The two blocks are a grid that stacks: nothing here assumes desktop width (mic-work-tab.md 13.10, phone layout). */
    .mi-advert { display: grid; grid-template-columns: minmax(0, 62fr) minmax(0, 38fr); min-height: 16rem; }
    .mi-advert-left { display: flex; flex-wrap: wrap; gap: 14px; padding: 12px 14px; min-width: 0; }
    .mi-advert-photo { position: relative; flex: 0 1 18.75rem; width: 100%; max-width: 18.75rem; aspect-ratio: 4 / 3; border-radius: 6px; overflow: hidden; background: var(--surface-2); align-self: flex-start; }
    .mi-advert-body { flex: 1 1 14rem; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
    .mi-advert-right { padding: 12px 14px; border-left: 1px solid var(--border); background: var(--surface-2); min-width: 0; display: flex; flex-direction: column; gap: 6px; }
    .mi-advert + .mi-advert, [data-qa="row-wrap"] + [data-qa="row-wrap"] > .mi-advert { border-top: 1px solid var(--border); }
    @media (max-width: 56rem) {
        .mi-advert { grid-template-columns: minmax(0, 1fr); }
        .mi-advert-right { border-left: 0; border-top: 1px solid var(--border); }
        .mi-advert-photo { max-width: none; flex-basis: 100%; }
    }
</style>
