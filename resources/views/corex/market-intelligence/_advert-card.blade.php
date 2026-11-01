{{-- MIC Work tab, Adverts view (mic-work-tab.md 13.3): one row per property file. THREE columns - photo | the advert's text | our intelligence - and the row is exactly
     as tall as the photo (4:3, about 300 x 225). The intelligence block is ONE line per fact (ellipsis, never wrapping); each fact line opens a popover (<x-dropdown>, the
     shared CoreX popover) with the detail. Rendered by _listing-row.blade.php when $rowMode === 'advert', so it reads the row's own variables ($listing, $suggested,
     $isUnclaimed, $claimedByMe, $displayAddress, ...) plus $adv = this file's MicWorkList::forFiles() record. --}}
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
    // the photo's P24 / PP marks are the links to the adverts themselves (the live one of that portal first)
    $portalUrl = function (string $portal) use ($adverts) {
        $mine = array_values(array_filter($adverts, fn ($a) => $a['portal'] === $portal && !empty($a['url'])));
        usort($mine, fn ($a, $b) => (int) $b['active'] <=> (int) $a['active']);

        return $mine[0]['url'] ?? null;
    };
    $p24Url = $portalUrl('P24');
    $ppUrl = $portalUrl('PP');
    // dates (13.3a): both when held, and the time on market by the section 0 rule
    $dateBits = [];
    if (!empty($adv['listed_date'])) { $dateBits[] = 'Listed ' . \Carbon\Carbon::parse($adv['listed_date'])->format('j M'); }
    if (!empty($adv['first_seen'])) { $dateBits[] = 'First seen ' . \Carbon\Carbon::parse($adv['first_seen'])->format('j M'); }
    $domLabel = (string) ($adv['dom']['label'] ?? '-');
    if ($domLabel !== '-' && $domLabel !== '') { $dateBits[] = $domLabel === 'new' ? 'new' : (str_contains($domLabel, '~') || str_ends_with($domLabel, '+') ? $domLabel : '~' . $domLabel) . ' days'; }
    $who = $ownerSale ? 'Owner (private)' : ($adv['agencies'][0] ?? null);
    $cardToggle = "card = !card; if (card && !loaded) { loaded = true; fetch(location.pathname + location.search + (location.search ? '&' : '?') + '_card=" . (int) ($adv['tp_id'] ?? 0) . "', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(r => r.ok ? r.text() : Promise.reject()).then(h => html = h).catch(() => { html = '<div style=&quot;padding:10px 14px;font-size:.75rem;color:var(--ds-crimson,#dc2626);&quot;>The record card could not load - try again.</div>'; loaded = false; }); }";

    // ---- the intelligence block: one line per fact ----
    $cx = $adv['context'] ?? null;
    $btn = 'display:flex; align-items:center; justify-content:center; width:100%; height:26px; padding:0 4px; font-size:.75rem; font-weight:600; line-height:1; border-radius:5px; background: var(--surface); border:1px solid var(--border); color: var(--text-secondary); cursor:pointer; text-decoration:none; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; box-sizing:border-box;';
    $btnOff = $btn . ' border-style:dashed; color: var(--text-muted); cursor:default; opacity:.7;';
    $lineBtn = 'display:block; width:100%; text-align:left; background:none; border:none; padding:0 2px; margin:0; font-size:.75rem; line-height:1.5rem; color: var(--text-secondary); cursor:pointer; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;';
    $lab = 'font-weight:700; color: var(--text-primary); display:inline-block; min-width:3.4rem;';
    $muted = 'color: var(--text-muted);';

    // claim state, far right of line 1
    $claimState = $isCompanyStock ? 'Our stock' : ($claimedByMe ? 'Mine' : ($claimedByOther ? ($adv['claimed_by'] ?? 'Claimed') : 'Unclaimed'));

    // Buyers
    $strong = (int) ($adv['buyers_strong'] ?? $adv['buyers'] ?? 0);
    $mid = (int) ($adv['buyers_mid'] ?? 0);
    $names = array_column($adv['buyer_names'] ?? [], 'name');
    $buyersLine = ($strong + $mid) > 0
        ? $strong . ' strong · ' . $mid . ' mid' . ($names ? ' · ' . implode(', ', array_slice($names, 0, 2)) . (count($names) > 2 ? ' +' . (count($names) - 2) : '') : '')
        : 'No matched buyers';
    // Price: only a cut we are sure of (13.4a)
    if (!empty($adv['old_price']) && !empty($adv['cut_amount'])) {
        $priceLine = $money($adv['old_price']) . ' → ' . $money($adv['price']) . ' · cut ' . $money($adv['cut_amount']) . (!empty($adv['cut_date']) ? ' · ' . \Carbon\Carbon::parse($adv['cut_date'])->format('j M') : '');
    } elseif (!empty($adv['is_reduced'])) {
        $priceLine = 'Reduced on the portal';
    } else {
        $priceLine = 'No price moves';
    }
    // Adverts
    $liveAdverts = array_values(array_filter($adverts, fn ($a) => $a['active']));
    $advList = $liveAdverts ?: $adverts;
    $nAd = count($advList);
    $nAg = count(array_unique(array_map(fn ($a) => $a['owner'] ? 'owner' : mb_strtolower(trim((string) $a['agency'])), $advList)));
    $portals = array_values(array_unique(array_map(fn ($a) => $a['portal'], $advList)));
    $advertsLine = $nAd . ' advert' . ($nAd === 1 ? '' : 's') . ' · ' . $nAg . ' agenc' . ($nAg === 1 ? 'y' : 'ies') . ' · ' . implode(', ', $portals);
    // Alerts
    $alertsLine = ($adv['alert_count'] ?? 0) > 0
        ? $adv['alert_count'] . (!empty($adv['alert_last_date']) ? ' · last ' . \Carbon\Carbon::parse($adv['alert_last_date'])->format('j M') : '') . (!empty($adv['alert_last_asking']) ? ' · asking ' . $money($adv['alert_last_asking']) : '')
        : 'No alerts';
    // Market
    $mk = [];
    $firstSale = $cx['nearby_sales'][0] ?? null;
    if ($firstSale && !empty($firstSale['price'])) { $mk[] = 'Sold nearby ' . $money($firstSale['price']); }
    if (!empty($cx['suburb_avg_sold_12m'])) { $mk[] = 'suburb avg ' . $money($cx['suburb_avg_sold_12m']); }
    if (($cx['asking_vs_avg_sold_pct'] ?? null) !== null && !empty($cx['suburb_avg_sold_12m'])) { $mk[] = 'asking ' . round(abs($cx['asking_vs_avg_sold_pct'])) . '% ' . ($cx['asking_vs_avg_sold_pct'] >= 0 ? 'above' : 'below'); }
    $marketLine = $mk ? implode(' · ', $mk) : 'No market figures yet';

    // Link contact: the property file's contacts section when we hold a Property, else the file page
    $propertyId = $companyStockPropertyId ?? ($listing->matched_property_id ?: null);
    $linkHref = $propertyId ? route('corex.properties.show', $propertyId) . '#linked-contacts-list' : (!empty($adv['tp_id']) ? route('market-intelligence.opportunities.show', ['tp' => $adv['tp_id']]) : null);
    $commentCount = (int) (($commentCounts ?? [])[$listing->tracked_property_id] ?? 0);

    $fact = [
        ['buyers', 'Buyers', $buyersLine],
        ['price', 'Price', $priceLine],
        ['adverts', 'Adverts', $advertsLine],
        ['alerts', 'Alerts', $alertsLine],
        ['market', 'Market', $marketLine],
    ];
    $markStyle = 'padding:1px 6px; font-size:.6875rem; font-weight:700; background: rgba(15,23,42,.82); color:#fff; border-radius:3px; text-decoration:none; line-height:1.4;';
@endphp

<article class="mi-advert" data-qa="advert-card" data-listing-id="{{ $listing->id }}">

    {{-- Column 1: the photo (4:3). The marks on it are links. --}}
    <div class="mi-advert-photo" data-qa="advert-photo">
        @if(!empty($adv['portal_url']))<a href="{{ $adv['portal_url'] }}" target="_blank" rel="noopener" data-qa="advert-photo-link" title="Open this advert on the portal" style="display:block; width:100%; height:100%;">@endif
            <img src="{{ $thumbSrc ?: $placeholder }}" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src='{{ $placeholder }}';" style="width:100%; height:100%; object-fit:cover; display:block; border-radius:6px; background: var(--surface-2);">
        @if(!empty($adv['portal_url']))</a>@endif
        <div style="position:absolute; top:8px; left:8px; display:flex; flex-direction:column; align-items:flex-start; gap:4px; pointer-events:none;">
            @foreach($ribbons as [$rt, $rc, $rtitle])
                <span data-qa="ribbon" title="{{ $rtitle ?? $rt }}" style="padding:2px 8px; font-size:.6875rem; font-weight:700; color:#fff; background: {{ $rc }}; border-radius:3px; box-shadow:0 1px 3px rgba(0,0,0,.3);">{{ $rt }}</span>
            @endforeach
        </div>
        @if((int) ($adv['group_member_count'] ?? 0) >= 2)
            <div style="position:absolute; bottom:8px; left:8px;" data-qa="listed-by">
                <x-dropdown align="left" width="w-96 max-w-[90vw]" content-classes="p-1">
                    <x-slot name="trigger"><button type="button" title="Every advert on this property" style="{{ $markStyle }} cursor:pointer; border:none;">Listed by {{ (int) $adv['group_member_count'] }} agencies</button></x-slot>
                    <x-slot name="content">@include('corex.market-intelligence._advert-pop', ['kind' => 'adverts'])</x-slot>
                </x-dropdown>
            </div>
        @endif
        <div style="position:absolute; bottom:8px; right:8px; display:flex; gap:4px; align-items:center;" data-qa="photo-marks">
            @if(!empty($adv['p24']))
                @if($p24Url)<a href="{{ $p24Url }}" target="_blank" rel="noopener" data-qa="mark-p24" title="Open the P24 advert" style="{{ $markStyle }}">P24</a>@else<span data-qa="mark-p24" style="{{ $markStyle }}">P24</span>@endif
            @endif
            @if(!empty($adv['pp']))
                @if($ppUrl)<a href="{{ $ppUrl }}" target="_blank" rel="noopener" data-qa="mark-pp" title="Open the PP advert" style="{{ $markStyle }}">PP</a>@else<span data-qa="mark-pp" style="{{ $markStyle }}">PP</span>@endif
            @endif
            @if(!empty($adv['alert']))
                <x-dropdown align="right" width="w-96 max-w-[90vw]" content-classes="p-1">
                    <x-slot name="trigger"><button type="button" data-qa="envelope" title="{{ $adv['alert_tip'] ?? 'In a P24 alert email' }} - open the alert details" style="{{ $markStyle }} cursor:pointer; border:none; display:inline-flex;"><svg viewBox="0 0 16 12" width="14" height="10" fill="none" stroke="currentColor" stroke-width="1.4"><rect x="1" y="1" width="14" height="10" rx="1.5"/><path d="M1.5 2.2 8 7l6.5-4.8"/></svg></button></x-slot>
                    <x-slot name="content">@include('corex.market-intelligence._advert-pop', ['kind' => 'alerts'])</x-slot>
                </x-dropdown>
            @endif
        </div>
    </div>

    {{-- Column 2: the advert's text, sized like a P24 result card --}}
    <div class="mi-advert-body" data-qa="advert-left" x-data="{ more: false }" :class="more ? 'mi-advert-body-open' : ''">
        <div style="display:flex; align-items:baseline; gap:8px; flex-wrap:wrap;" data-qa="advert-price">
            <span style="font-size:1.5rem; line-height:1.2; font-weight:800; color: var(--text-primary);">{{ !empty($adv['price']) ? $money($adv['price']) : '—' }}</span>
            @if(!empty($adv['old_price']) && !empty($adv['cut_amount']))
                <span style="font-size:.9375rem; color: var(--text-muted); text-decoration: line-through;">{{ $money($adv['old_price']) }}</span>
                <span style="font-size:.8125rem; font-weight:600; color: var(--ds-crimson, #dc2626);">cut {{ $money($adv['cut_amount']) }}{{ !empty($adv['cut_date']) ? ' · ' . \Carbon\Carbon::parse($adv['cut_date'])->format('j M') : '' }}</span>
            @endif
        </div>
        <div style="font-size:1rem; line-height:1.3; font-weight:600; color: var(--text-primary);" data-qa="advert-heading">{{ $heading }}</div>
        <div style="font-size:.875rem; line-height:1.3; font-weight:700; color: var(--text-primary);" data-qa="advert-place">{{ $adv['suburb'] ?? $listing->suburb }}@if($hasAddress) · {{ \Illuminate\Support\Str::limit($displayAddress, 80) }}@endif</div>

        @if($text !== '')
            <div style="font-size:.875rem; line-height:1.4; color: var(--text-secondary);" data-qa="advert-description">
                <div :style="more ? '' : 'display:-webkit-box; -webkit-line-clamp:4; -webkit-box-orient:vertical; overflow:hidden;'">{{ $text }}</div>
                @if(mb_strlen($text) > 160)<button type="button" @click.stop="more = !more" style="background:none; border:none; padding:0; font-size:.8125rem; color: var(--brand-icon); cursor:pointer;"><span x-text="more ? 'less' : 'more'">more</span></button>@endif
            </div>
        @endif

        <div style="display:flex; align-items:center; gap:2px 14px; margin-top:auto; font-size:.875rem; color: var(--text-secondary);" data-qa="advert-icons">
            @if(!empty($f['beds']))<span title="Bedrooms" style="display:inline-flex; align-items:center; gap:4px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px; height:15px; color: var(--text-muted);"><path d="M3 18v-7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v7M3 14h18M3 18v2M21 18v2M7 9V6h10v3"/></svg>{{ $f['beds'] }}</span>@endif
            @if(!empty($f['baths']))<span title="Bathrooms" style="display:inline-flex; align-items:center; gap:4px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px; height:15px; color: var(--text-muted);"><path d="M4 12h16v3a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4v-3zM6 12V6a2 2 0 0 1 4 0M7 19l-1 2M17 19l1 2"/></svg>{{ $f['baths'] }}</span>@endif
            @if(!empty($f['garages']))<span title="Garages" style="display:inline-flex; align-items:center; gap:4px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px; height:15px; color: var(--text-muted);"><path d="M5 17h14M6 17l1.5-6h9L18 17M7 17v2M17 17v2"/></svg>{{ $f['garages'] }}</span>@endif
            @if(!empty($adv['parking']))<span title="Parking bays" style="display:inline-flex; align-items:center; gap:4px;"><span style="font-weight:800; color: var(--text-muted);">P</span>{{ $adv['parking'] }}</span>@endif
            @if(!empty($adv['erf_size_m2']))<span title="Erf size">Erf {{ number_format((float) $adv['erf_size_m2']) }} m²</span>@endif
            @if(!empty($adv['floor_size_m2']))<span title="Floor size">Floor {{ number_format((float) $adv['floor_size_m2']) }} m²</span>@endif
        </div>
        <div style="font-size:.8125rem; line-height:1.3; color: var(--text-secondary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" data-qa="advert-source">
            {{ implode(' · ', array_filter([$who, $adv['agent_name'] ?? null])) }}@if($lead && !empty($lead['ref']))@if($who || !empty($adv['agent_name'])) · @endif<span style="{{ $muted }}">{{ $lead['ref'] }}</span>@endif
        </div>
        <div style="font-size:.8125rem; line-height:1.3; color: var(--text-secondary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" data-qa="advert-dates">
            {{ implode(' · ', $dateBits) }}@if(!empty($adv['is_stale']) && $adv['last_seen_days'] !== null)<span style="{{ $muted }}" data-qa="advert-stale"> · not seen since {{ $adv['last_seen_days'] }} d</span>@endif
        </div>
    </div>

    {{-- Column 3: our intelligence - seven single lines inside the photo's height --}}
    <div class="mi-advert-right" data-qa="advert-right">
        <div data-qa="line-score" style="display:flex; align-items:center; gap:8px; white-space:nowrap; overflow:hidden; height:1.5rem; font-size:.75rem;">
            @if($adv['score'] !== null)
                <span style="font-size:.8125rem; font-weight:800; color: var(--text-primary); flex-shrink:0;">Score {{ number_format($adv['score'], 0) }}</span>
                <span style="display:flex; gap:6px; min-width:0; overflow:hidden; text-overflow:ellipsis; {{ $muted }}">@foreach(array_slice($adv['reasons'] ?? [], 0, 3) as $rc)<span style="white-space:nowrap;">· {{ \App\Services\Prospecting\MicWork\MicWorkList::REASON_LABELS[$rc] ?? ucfirst(str_replace('_', ' ', $rc)) }}</span>@endforeach</span>
            @else
                <span style="{{ $muted }}">No score yet</span>
            @endif
            @if(!empty($adv['pair_ids']))<span style="font-size:.625rem; font-weight:700; padding:0 5px; border:1px solid var(--ds-amber, #f59e0b); border-radius:8px; color: var(--ds-amber, #f59e0b); flex-shrink:0;">Possible match</span>@endif
            <span data-qa="claim-state" style="margin-left:auto; flex-shrink:0; font-weight:600; {{ $claimedByMe ? 'color: var(--brand-icon);' : $muted }}">{{ $claimState }}</span>
        </div>

        <div data-qa="line-buttons" style="display:grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap:4px; height:26px;">
            @if($isCompanyStock)
                <span style="{{ $btnOff }}" title="This is our agency's own listing - no pitch needed.">Our stock</span>
            @else
                @include('corex.market-intelligence._suggested-action-chip', ['suggested' => $suggested, 'listing' => $listing, 'compact' => true])
            @endif
            @if($isUnclaimed)
                <form method="POST" action="{{ route('market-intelligence.claim', $listing->id) }}" data-tour="mic-claim" style="margin:0;">@csrf
                    <button type="submit" style="{{ $btn }} color: var(--brand-icon); border-color: var(--brand-icon);" title="Claim - reserve this listing for yourself. It leaves the canvass list and moves to My Claims.">Claim</button>
                </form>
            @elseif($claimedByMe)
                <form method="POST" action="{{ route('market-intelligence.release', $listing->id) }}" style="margin:0;">@csrf
                    <button type="submit" style="{{ $btn }}" title="Give this listing back to the pool.">Unclaim</button>
                </form>
            @else
                <span style="{{ $btnOff }}" title="{{ $claimedByOther ? 'Already claimed by ' . ($adv['claimed_by'] ?? 'a colleague') : 'Not claimable' }}">Claim</span>
            @endif
            @if($listing->tracked_property_id && ($canViewComments ?? false))
                <button type="button" @click.stop="openCommentsModal({{ $listing->tracked_property_id }})" style="{{ $btn }}" title="{{ $commentCount > 0 ? $commentCount . ' comments on this property - view and add' : 'Comments on this property - view and add' }}">Comment<span data-tp-comment-count="{{ $listing->tracked_property_id }}" style="font-weight:700;">{{ $commentCount > 0 ? ' ' . $commentCount : '' }}</span></button>
            @else
                <span style="{{ $btnOff }}">Comment</span>
            @endif
            @if($linkHref)
                <a href="{{ $linkHref }}" style="{{ $btn }}" title="Open the property file at its contacts, to link the owner or a contact">Link contact</a>
            @else
                <span style="{{ $btnOff }}">Link contact</span>
            @endif
            @unless($isPropertyStock)
                <button type="button" @click.stop="$dispatch('open-slideover', { listingId: {{ $listing->id }}, trigger: $el })" style="{{ $btn }}" title="Property intel: the full detail of this advert">Intel</button>
            @else
                <span style="{{ $btnOff }}">Intel</span>
            @endunless
        </div>

        @foreach($fact as [$kind, $label, $face])
            <div data-qa="line-{{ $kind }}" style="height:1.5rem; min-width:0;">
                <x-dropdown align="right" width="w-96 max-w-[90vw]" content-classes="p-1">
                    <x-slot name="trigger"><button type="button" style="{{ $lineBtn }}" title="{{ $label }}: {{ $face }}"><span style="{{ $lab }}">{{ $label }}</span> <span style="{{ str_starts_with($face, 'No ') ? $muted : '' }}">{{ $face }}</span></button></x-slot>
                    <x-slot name="content">@include('corex.market-intelligence._advert-pop', ['kind' => $kind])</x-slot>
                </x-dropdown>
            </div>
        @endforeach

        @if(!empty($adv['tp_id']))
            <div data-qa="full-card" style="margin-top:auto; text-align:right;"><button type="button" data-qa="card-toggle" @click.stop="{{ $cardToggle }}" style="background:none; border:none; padding:0; font-size:.75rem; font-weight:600; color: var(--brand-icon); cursor:pointer;"><span x-text="card ? 'Full card ▴' : 'Full card ▾'">Full card ▾</span></button></div>
        @endif
    </div>
</article>

<style>
    /* Three columns - photo | the advert's text | our intelligence - and the row is as tall as the photo (4:3, 300 x 225). Below 64rem they stack: nothing here assumes
       desktop width (mic-work-tab.md 13.10). The popovers must not be clipped, so the stack's overflow is released in this view. */
    .mi-row-stack:has(.mi-advert) { overflow: visible !important; }
    .mi-advert { display: grid; grid-template-columns: 18.75rem minmax(0, 1fr) minmax(24rem, 28rem); gap: 16px; padding: 12px 14px; align-items: start; }
    .mi-advert-photo { position: relative; width: 18.75rem; max-width: 100%; aspect-ratio: 4 / 3; }
    .mi-advert-body { display: flex; flex-direction: column; gap: 3px; min-width: 0; height: 14.0625rem; overflow: hidden; }
    .mi-advert-body.mi-advert-body-open { height: auto; min-height: 14.0625rem; overflow: visible; }
    .mi-advert-right { display: flex; flex-direction: column; gap: 4px; min-width: 0; height: 14.0625rem; padding-left: 14px; border-left: 1px solid var(--border); }
    [data-qa="row-wrap"] + [data-qa="row-wrap"] > .mi-advert { border-top: 1px solid var(--border); }
    @media (max-width: 64rem) {
        .mi-advert { grid-template-columns: minmax(0, 1fr); }
        .mi-advert-photo { width: 100%; }
        .mi-advert-body, .mi-advert-right { height: auto; }
        .mi-advert-right { padding-left: 0; border-left: 0; border-top: 1px solid var(--border); padding-top: 8px; }
    }
</style>
