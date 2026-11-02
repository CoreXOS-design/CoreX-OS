{{-- MIC Work tab: the intelligence block of a property file (mic-work-tab.md 13.3 / 13.3r) - seven single lines inside the photo's height. ONE partial, two places: the third column of
     the Adverts card, and what the List row's Card button opens. Reads the row's own variables ($listing, $suggested, $isUnclaimed, $claimedByMe, ...) plus $adv = this file's
     MicWorkList::forFiles() record. --}}
@php
    $money = fn ($v) => 'R ' . number_format((float) $v, 0, '.', ',');
    $adverts = $adv['adverts'] ?? [];
    $muted = 'color: var(--text-secondary);';
    $cardToggle = "card = !card; if (card && !loaded) { loaded = true; fetch(location.pathname + location.search + (location.search ? '&' : '?') + '_card=" . (int) ($adv['tp_id'] ?? 0) . "', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(r => r.ok ? r.text() : Promise.reject()).then(h => html = h).catch(() => { html = '<div style=&quot;padding:10px 14px;font-size:.75rem;color:var(--ds-crimson,#dc2626);&quot;>The record card could not load - try again.</div>'; loaded = false; }); }";
    // ---- the intelligence block: one line per fact ----
    $cx = $adv['context'] ?? null;
    $btn = 'display:flex; align-items:center; justify-content:center; width:100%; height:26px; padding:0 4px; font-size:.75rem; font-weight:600; line-height:1; border-radius:5px; background: var(--surface); border:1px solid var(--border); color: var(--text-secondary); cursor:pointer; text-decoration:none; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; box-sizing:border-box;';
    $btnOff = $btn . ' border-style:dashed; color: var(--text-secondary); cursor:default; opacity:.7;';
    $lineBtn = 'display:block; width:100%; text-align:left; background:none; border:none; padding:0 2px; margin:0; font-size:.75rem; line-height:1.5rem; color: var(--text-primary); cursor:pointer; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;';
    $lab = 'font-weight:700; color: var(--text-secondary); display:inline-block; min-width:3.4rem;';
    $muted = 'color: var(--text-secondary);';

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
    // Adverts: everything on the file, live or gone (the photo's "Listed by N agencies" and the popover list the same set)
    $nAd = (int) ($adv['adverts_total'] ?? count($adverts));
    $nLive = count(array_filter($adverts, fn ($a) => $a['active']));
    $nAg = (int) ($adv['agencies_total'] ?? count(array_unique(array_map(fn ($a) => $a['owner'] ? 'owner' : mb_strtolower(trim((string) $a['agency'])), $adverts))));
    $portals = array_values(array_unique(array_map(fn ($a) => $a['portal'], $adverts)));
    $advertsLine = $nAd . ' advert' . ($nAd === 1 ? '' : 's') . ' · ' . $nAg . ' agenc' . ($nAg === 1 ? 'y' : 'ies') . ' · ' . $nLive . ' live · ' . implode(', ', $portals);
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
@endphp

    <div class="mi-advert-right" data-qa="advert-right">
        <div data-qa="line-score" style="display:flex; align-items:center; gap:8px; white-space:nowrap; overflow:hidden; height:1.5rem; font-size:.75rem;">
            @if($adv['score'] !== null)
                <span style="font-size:.8125rem; font-weight:800; color: var(--text-primary); flex-shrink:0;">Score {{ number_format($adv['score'], 0) }}</span>
                <span style="display:flex; gap:6px; min-width:0; overflow:hidden; text-overflow:ellipsis; {{ $muted }}">@foreach(array_slice($adv['reasons'] ?? [], 0, 3) as $rc)<span style="white-space:nowrap;">· {{ \App\Services\Prospecting\MicWork\MicWorkList::REASON_LABELS[$rc] ?? ucfirst(str_replace('_', ' ', $rc)) }}</span>@endforeach</span>
            @else
                <span style="{{ $muted }}">No score yet</span>
            @endif
            @if(!empty($adv['pair_ids']))<span style="font-size:.625rem; font-weight:700; padding:0 5px; border:1px solid var(--ds-amber, #f59e0b); border-radius:8px; color: var(--text-primary); flex-shrink:0;">Possible match</span>@endif
            <span data-qa="claim-state" style="margin-left:auto; flex-shrink:0; font-weight:600; {{ $claimedByMe ? 'color: var(--text-primary);' : $muted }}">{{ $claimState }}</span>
        </div>

        <div data-qa="line-buttons" style="display:grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap:4px; height:26px;">
            @if($isCompanyStock)
                <span style="{{ $btnOff }}" title="This is our agency's own listing - no pitch needed.">Our stock</span>
            @else
                @include('corex.market-intelligence._suggested-action-chip', ['suggested' => $suggested, 'listing' => $listing, 'compact' => true])
            @endif
            @if($isUnclaimed)
                <form method="POST" action="{{ route('market-intelligence.claim', $listing->id) }}" data-tour="mic-claim" style="margin:0;">@csrf
                    <button type="submit" style="{{ $btn }} color: var(--text-primary); border-color: var(--brand-icon);" title="Claim - reserve this listing for yourself. It leaves the canvass list and moves to My Claims.">Claim</button>
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
                <x-mic-pop align="right" content-classes="p-1">
                    <x-slot name="trigger"><button type="button" style="{{ $lineBtn }}" title="{{ $label }}: {{ $face }}"><span style="{{ $lab }}">{{ $label }}</span> <span style="{{ str_starts_with($face, 'No ') ? $muted : '' }}">{{ $face }}</span></button></x-slot>
                    <x-slot name="content">@include('corex.market-intelligence._advert-pop', ['kind' => $kind])</x-slot>
                </x-mic-pop>
            </div>
        @endforeach

        @if(!empty($adv['tp_id']))
            <div data-qa="full-card" style="margin-top:auto; text-align:right;"><button type="button" data-qa="card-toggle" @click.stop="{{ $cardToggle }}" style="background:none; border:none; padding:0; font-size:.75rem; font-weight:600; color: var(--text-primary); text-decoration: underline; text-decoration-color: var(--brand-icon); cursor:pointer;"><span x-text="card ? 'Full card ▴' : 'Full card ▾'">Full card ▾</span></button></div>
        @endif
    </div>
@once
<style>
    .mi-advert-right { display: flex; flex-direction: column; gap: 4px; min-width: 0; height: 14.0625rem; padding-left: 14px; border-left: 1px solid var(--border); }
    @media (max-width: 64rem) {
        .mi-advert-right { height: auto; padding-left: 0; border-left: 0; border-top: 1px solid var(--border); padding-top: 8px; }
    }
</style>
@endonce
