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
    // v4 (road-to-sold.md 23.5): my claim is on a hold in working days; the state shows how long is left and the pop-up extends it once
    $myHold = ($claimedByMe && !$isCompanyStock) ? ($adv['hold'] ?? null) : null;
    if ($myHold && $myHold['days_left'] !== null) {
        $claimState = 'Mine · ' . max(0, (int) $myHold['days_left']) . ' day' . ((int) $myHold['days_left'] === 1 ? '' : 's') . ' left';
    }

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

    @php
        // 13.18: in buyer mode ONE extra row under the Score line: the fit chip (left) and cc1's "Not for <buyer>" (right)
        $fit = !empty($adv['buyer_fit']) ? $adv['buyer_fit'] : null;
        $fitRow = $fit || !empty($adv['buyer_id']);
        $fs = $fit ? \App\Services\Prospecting\MicWork\BuyerFitLabel::summary($fit) : null;
    @endphp
    <div class="mi-advert-right{{ $fitRow ? ' mi-advert-right-fit' : '' }}" data-qa="advert-right">
        <div data-qa="line-score" style="display:flex; align-items:center; gap:8px; white-space:nowrap; overflow:hidden; height:1.5rem; font-size:.75rem;">
            @if($adv['score'] !== null)
                <span style="font-size:.8125rem; font-weight:800; color: var(--text-primary); flex-shrink:0;">Score {{ number_format($adv['score'], 0) }}</span>
                <span style="display:flex; gap:6px; min-width:0; overflow:hidden; text-overflow:ellipsis; {{ $muted }}">@foreach(array_slice($adv['reasons'] ?? [], 0, 3) as $rc)<span style="white-space:nowrap;">· {{ \App\Services\Prospecting\MicWork\MicWorkList::REASON_LABELS[$rc] ?? ucfirst(str_replace('_', ' ', $rc)) }}</span>@endforeach</span>
            @else
                <span style="{{ $muted }}">No score yet</span>
            @endif
            @if(!empty($adv['pair_ids']))<span style="font-size:.625rem; font-weight:700; padding:0 5px; border:1px solid var(--ds-amber, #f59e0b); border-radius:8px; color: var(--text-primary); flex-shrink:0;">Possible match</span>@endif
            @if($myHold)
                <span style="margin-left:auto; flex-shrink:0;">
                    <x-mic-pop align="right" content-classes="p-1">
                        <x-slot name="trigger"><button type="button" data-qa="claim-state" title="Your hold on this property: {{ $myHold['days_left'] }} working days left, ends {{ $myHold['until'] }}. Click for details" style="background:none; border:none; padding:0; cursor:pointer; font-size:.75rem; font-weight:600; color: var(--text-primary); {{ ($myHold['days_left'] ?? 99) <= 5 ? 'text-decoration: underline; text-decoration-color: var(--ds-amber, #f59e0b);' : '' }}">{{ $claimState }}</button></x-slot>
                        <x-slot name="content">
                            <div data-qa="hold-pop" style="padding:6px 10px; width:17rem; font-size:.75rem; color: var(--text-primary);" @click.stop>
                                <div style="font-weight:700; padding-bottom:4px;">Your hold on this property</div>
                                <div style="{{ $muted }}">You keep it for {{ $myHold['days_left'] }} more working days (to {{ $myHold['until'] }}) unless you work it - every call, booking or step restarts the count.</div>
                                @if($myHold['find_owner'])
                                    <div data-qa="hold-find-owner" style="margin-top:6px; padding:4px 6px; border:1px dashed var(--ds-amber, #f59e0b); border-radius:5px;">Find the owner first: still needed - {{ $myHold['missing'] }}.</div>
                                @endif
                                @if($myHold['can_extend'])
                                    <form method="POST" action="{{ route('market-intelligence.claims.extend-hold', $myHold['claim_id']) }}" style="margin:8px 0 0;">@csrf
                                        <button type="submit" data-qa="hold-extend" style="{{ $btn }} color: var(--text-primary); border-color: var(--brand-icon);" title="Add working days to this hold. You can do this once; your manager can grant more.">Extend the hold</button>
                                    </form>
                                @else
                                    <div style="margin-top:6px; {{ $muted }}">Already extended once. Ask your manager if it needs longer.</div>
                                @endif
                            </div>
                        </x-slot>
                    </x-mic-pop>
                </span>
            @else
            <span data-qa="claim-state" style="margin-left:auto; flex-shrink:0; font-weight:600; {{ $claimedByMe ? 'color: var(--text-primary);' : $muted }}">{{ $claimState }}</span>
            @endif
        </div>

        @if($fitRow)
            <div data-qa="line-fit" style="display:flex; align-items:center; justify-content:space-between; gap:8px; height:1.5rem; font-size:.75rem;">
                <div style="min-width:0; flex:0 1 auto;" data-qa="fit-wrap">
                    @if($fs)
                        <x-mic-pop align="left" content-classes="p-1">
                            <x-slot name="trigger"><button type="button" data-qa="fit-badge" title="{{ $fs['text'] }} - click for what {{ $fit['first_name'] ?? 'the buyer' }} wants against what this has" style="display:block; max-width:100%; font-size:.6875rem; font-weight:700; line-height:1.4; padding:0 7px; border:1px solid currentColor; border-radius:9px; background:none; cursor:pointer; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color: {{ \App\Services\Prospecting\MicWork\BuyerFitLabel::COLORS[$fs['tone']] }};">{{ $fs['text'] }}</button></x-slot>
                            <x-slot name="content">@include('corex.market-intelligence._advert-pop', ['kind' => 'fit'])</x-slot>
                        </x-mic-pop>
                    @endif
                </div>
                @include('corex.market-intelligence._advert-buyer-hide')
            </div>
        @endif

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
                <x-mic-pop align="right" content-classes="p-1">
                    <x-slot name="trigger"><button type="button" data-qa="comment-btn" @click="if (!open) loadComments({{ $listing->tracked_property_id }})" style="{{ $btn }}" title="{{ $commentCount > 0 ? $commentCount . ' comments on this property - view and add' : 'Comments on this property - view and add' }}">Comment<span data-tp-comment-count="{{ $listing->tracked_property_id }}" style="font-weight:700;">{{ $commentCount > 0 ? ' ' . $commentCount : '' }}</span></button></x-slot>
                    <x-slot name="content">
                        <div data-qa="comment-pop" style="padding:4px 8px; max-height: min(24rem, var(--pop-max, 24rem)); overflow-y:auto; font-size:.75rem; color: var(--text-primary);" @click.stop @keydown.escape.window="$dispatch('close')">
                            <div style="font-weight:700; font-size:.75rem; padding: 2px 0 6px;">Property comments</div>
                            <template x-if="commentsModalLoading"><div style="padding:12px 0; color: var(--text-secondary);">Loading…</div></template>
                            <div x-show="!commentsModalLoading" x-html="commentsModalHtml"></div>
                        </div>
                    </x-slot>
                </x-mic-pop>
            @else
                <span style="{{ $btnOff }}">Comment</span>
            @endif
            @if($linkHref)
                <a href="{{ $linkHref }}" style="{{ $btn }}{{ $myHold && $myHold['find_owner'] ? ' border-color: var(--ds-amber, #f59e0b);' : '' }}" title="{{ $myHold && $myHold['find_owner'] ? 'Find the owner first - still needed: ' . $myHold['missing'] . '. Opens the property file at its contacts.' : 'Open the property file at its contacts, to link the owner or a contact' }}" @if($myHold && $myHold['find_owner']) data-qa="find-owner" @endif>{{ $myHold && $myHold['find_owner'] ? 'Find owner' : 'Link contact' }}</a>
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
    .mi-advert-right.mi-advert-right-fit { height: calc(14.0625rem + 1.75rem); } /* buyer mode: one more row, so the panel stays level with the middle column */
    .mi-advert-right { display: flex; flex-direction: column; gap: 4px; min-width: 0; height: 14.0625rem; padding-left: 14px; border-left: 1px solid var(--border); }
    @media (max-width: 64rem) {
        .mi-advert-right, .mi-advert-right.mi-advert-right-fit { height: auto; padding-left: 0; border-left: 0; border-top: 1px solid var(--border); padding-top: 8px; }
    }
</style>
@endonce
