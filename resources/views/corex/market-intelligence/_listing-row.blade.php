{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    F.3 — Market Intelligence listing row.

    Inputs:
      $listing    — ProspectingListing model
      $state      — slice from $listingStates (defensive defaults applied)
      $tiers      — slice from $buyerTiers   (defensive defaults applied)
      $suggested  — SuggestedAction DTO from E.1, or null
      $isManager  — bool
      $viewerId   — auth()->id() at controller resolution time

    Rules:
      * Zero references to $listing->activeClaim or $listing->activeClaim->user
        (claim state comes entirely from $state['claim'] — F.3 fix 5.4 closure)
      * Zero diffInHours calculations (hours_left comes from the enricher;
        F.3 fix 5.2)
      * Every listing renders a TP → chip (F.3 fix 5.3)
      * The CTA is the suggested-action chip — the legacy state-aware Pitch /
        Pitch (stock) / View pitch ladder is gone (F.3 fix 5.1)

    Right-hand grid column is 260px (2026-08-21, was 200px) — Claim shares
    its line with the icon group AND matches Pitch Now's ~160px min-width
    (_suggested-action-chip.blade.php); 200px could no longer fit both
    without crowding them together, which Johan has explicitly said not to
    do. The middle (1fr) column absorbs the 60px difference; it already
    truncates long addresses/agency names with ellipsis.

    Spec: build-f-market-intelligence-redesign-spec.md §8.4.
--}}

@php
    $state = $state ?? [];
    $selectedScore = $selectedScore ?? null;
    $tiers = array_merge(['strong'=>0,'mid'=>0,'weak'=>0,'total'=>0,'top_score'=>null,'sources'=>['portal_lead'=>0,'other'=>0]], $tiers ?? []);
    $pitch = $state['pitch'] ?? null;
    $claim = $state['claim'] ?? null;
    $prospected = $state['prospected'] ?? null;
    $presentation = $state['presentation'] ?? null;
    $tempLock = $state['temp_lock'] ?? null;
    $showProspectedBadge = $showProspectedBadge ?? true;

    $claimedByMe = $claim && $viewerId && (int)($claim['user_id'] ?? 0) === (int)$viewerId;
    $claimedByOther = $claim && !$claimedByMe;
    $isPitchedRecent = $pitch && ($pitch['is_recent'] ?? false);
    $hasContactPhone = $pitch && !empty($pitch['recipient_phone']);

    // Normalise phone for tel: + wa.me. South African convention: leading 0 → 27.
    $rawPhone = $hasContactPhone ? (string) $pitch['recipient_phone'] : '';
    $digits = preg_replace('/\D/', '', $rawPhone);
    if ($digits !== '' && str_starts_with($digits, '0')) {
        $digits = '27' . substr($digits, 1);
    }
    $telHref = $rawPhone !== '' ? 'tel:' . $rawPhone : null;
    $waHref = $digits !== '' ? 'https://wa.me/' . $digits : null;

    $thumbUrl = $listing->thumbnail_path
        ? route('market-intelligence.thumbnail', $listing)
        : null;

    // Address truncation — keep it tight on the primary line. Johan, 2026-08-27 —
    // a deed-linked listing's own address stays blank by design; $state['display_address']
    // (set only when one was actually resolved from the linked property) is display-only
    // and never written back onto $listing — see MarketIntelligenceController::work().
    $displayAddress = $state['display_address'] ?? $listing->address ?? null;
    // AT-455 (Andre, 2026-10-14): a listing without an address leads with what
    // it is and where ("Apartment in Sea Park"); "No address yet" becomes a
    // small note on the facts line instead of the row's title.
    $hasAddress = (bool) $displayAddress;
    $sizeBits = [];
    if ($listing->bedrooms)  { $sizeBits[] = $listing->bedrooms . ' bed'; }
    if ($listing->bathrooms) { $sizeBits[] = $listing->bathrooms . ' bath'; }
    if ($listing->garages)   { $sizeBits[] = $listing->garages . ' garage'; }
    if ($hasAddress) {
        $addressShort = \Illuminate\Support\Str::limit($displayAddress, 50);
    } else {
        $typeWord = $listing->property_type ?: 'Property';
        $addressShort = $listing->suburb ? ($typeWord . ' in ' . $listing->suburb) : $typeWord;
    }
    $priceLabel = $listing->price ? ('R ' . number_format($listing->price)) : '—';

    $metaParts = array_filter([
        $hasAddress ? null : 'No address yet',
        $hasAddress ? ($listing->suburb ?? null) : null,
        $sizeBits ? implode(' · ', $sizeBits) : null,
        $hasAddress ? ($listing->property_type ?? null) : null,
        $listing->agency_name ? \Illuminate\Support\Str::limit($listing->agency_name, 20) : null,
        $listing->portal_ref ?? null,
        // AT-454 — the portal's real listed date when we have it, else the day CoreX first saw the advert.
        $listing->listedOn()?->format('j M'),
    ]);

    // Chip style helpers. AT-455 (Andre, 2026-10-14): no filled colour boxes on
    // the row. A state is plain text in its own colour (amber for something
    // that needs attention, accent for "yours"); outline only where it is a
    // click target.
    $tagBase = 'display: inline-flex; align-items: center; gap: 3px; padding: 2px 0; font-size: 0.6875rem; font-weight: 500; line-height: 1.4; white-space: nowrap;';
    $tagAmber = $tagBase . 'color: var(--ds-amber, #f59e0b);';
    $tagTeal = $tagBase . 'color: var(--brand-icon, #0ea5e9); font-weight: 600;';
    $tagRed = $tagBase . 'color: var(--ds-crimson, #dc2626);';
    $tagNeutral = $tagBase . 'color: var(--text-muted);';
    $tagOutline = $tagBase . 'color: var(--text-secondary); padding: 2px 7px; border: 1px solid var(--border); border-radius: 6px; background: transparent;';

    $iconBtnBase = 'display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 4px; background: var(--surface-2); border: 1px solid var(--border); color: var(--text-secondary); cursor: pointer; text-decoration: none;';
    $iconBtnDisabled = 'display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 4px; background: var(--surface); border: 1px dashed var(--border); color: var(--text-muted); cursor: not-allowed; opacity: 0.55;';

    // Company stock (Johan's model): this scraped listing is the agency's OWN
    // portal listing (exact portal_ref match) → we already hold it, so there is
    // nothing to pitch. companyStockMap maps listing id → our Property id.
    $companyStockPropertyId = ($companyStockMap ?? [])[$listing->id] ?? null;
    $isCompanyStock = $companyStockPropertyId !== null;
    $agencyLogoUrl = $agencyLogoUrl ?? null;
    // #2 — synthetic property-backed in-stock row (no scraped listing behind it):
    // render read-only. There is no listing record, so the slideover fetch would
    // 404; the IN STOCK badge + address both link to the Property instead.
    $isPropertyStock = (bool) ($listing->is_property_stock ?? false);

    // SINGLE source of truth for "nothing about this row makes claiming
    // senseless" (Johan 2026-08-19: "use the same source of truth, not a new
    // one") — identical to the "unclaimed" chip's own condition below, so the
    // row-level Claim button and that chip can never disagree. Already
    // excludes claimed-by-anyone, mid-pitch temp lock, a durable "prospected"
    // history badge, and company stock (own listing, never claimable). Placed
    // here, after $isCompanyStock above, so it isn't read before it's set.
    $isUnclaimed = !$claim && !$tempLock && !($prospected && $showProspectedBadge) && !$isCompanyStock;
@endphp

@if(($rowMode ?? 'row') === 'advert' && !empty($adv))
    @include('corex.market-intelligence._advert-card')
@else
<article
    class="mi-row"
    data-listing-id="{{ $listing->id }}"
    @unless($isPropertyStock)
    role="button"
    tabindex="0"
    @click="$dispatch('open-slideover', { listingId: {{ $listing->id }}, trigger: $el })"
    @keydown.enter.prevent="$dispatch('open-slideover', { listingId: {{ $listing->id }}, trigger: $el })"
    @keydown.space.prevent="$dispatch('open-slideover', { listingId: {{ $listing->id }}, trigger: $el })"
    @endunless
    style="display: grid; grid-template-columns: 44px 1fr 260px; gap: 12px; align-items: center;
           padding: 10px 14px; min-height: 70px; background: var(--surface); border-bottom: 1px solid var(--border);
           cursor: {{ $isPropertyStock ? 'default' : 'pointer' }}; transition: background 120ms;"
    onmouseover="this.style.background='var(--surface-2)'"
    onmouseout="this.style.background='var(--surface)'">

    {{-- Thumbnail --}}
    <div style="width: 44px; height: 44px; border-radius: 6px; overflow: hidden; background: var(--surface-2); border: 1px solid var(--border); flex-shrink: 0;">
        @if($thumbUrl)
        <img src="{{ $thumbUrl }}" alt="" loading="lazy"
             style="width: 100%; height: 100%; object-fit: cover; display: block;">
        @else
        <div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--text-muted);">
                <path d="M3 9.5L12 4l9 5.5"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/>
            </svg>
        </div>
        @endif
    </div>

    {{-- Main content column --}}
    <div style="min-width: 0; display: flex; flex-direction: column; gap: 4px;">

        {{-- Line 1: address + price --}}
        <div style="display: flex; align-items: baseline; justify-content: space-between; gap: 12px;">
            <a href="{{ $listing->portal_url }}" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer"
               onclick="event.stopPropagation();"
               style="font-size: 0.9375rem; font-weight: 600; color: var(--text-primary); text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0;">
                {{ $addressShort }}
            </a>
            <div style="display: flex; align-items: baseline; gap: 6px; flex-shrink: 0;">
                <span style="font-size: 0.9375rem; font-weight: 600; color: var(--text-primary);">{{ $priceLabel }}</span>
                @include('corex.market-intelligence._row-badges')
            </div>
        </div>

        {{-- Line 2: meta --}}
        <div style="font-size: 0.6875rem; color: var(--text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
            {{ implode(' · ', $metaParts) }}
        </div>

        {{-- Line 3: state tags + demand microbar --}}
        <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
            {{-- mic-work-tab.md 2.1 B/C/D/E - ADDITIVE: opportunity score + reasons, source icons (with the envelope), Possible match chip, record-card toggle.
                 Nothing above or below is replaced. --}}
            @php
                $xt = $extras ?? null;
                $xs = $source ?? null;
                $iconChip = 'display:inline-block; padding: 0 4px; border: 1px solid var(--border); border-radius: 3px; font-size: .625rem; font-weight: 700; line-height: 1.4; color: var(--text-secondary);';
                $portalsHere = collect($listing->portals ?? [['source' => $listing->portal_source]])->pluck('source')->unique();
            @endphp
            <span data-qa="row-additions" @click.stop style="display: inline-flex; align-items: center; gap: 4px; flex-wrap: wrap;">
                @if($xt && $xt['score'] !== null)
                    <span data-qa="score-badge" title="Opportunity score" style="font-size: .75rem; font-weight: 700; color: var(--text-primary);">Score {{ number_format($xt['score'], 0) }}</span>
                    @foreach(array_slice($xt['reasons'], 0, 2) as $rc)<span data-qa="reason-chip" style="font-size: .6875rem; color: var(--text-muted);">· {{ \App\Services\Prospecting\MicWork\MicWorkList::REASON_LABELS[$rc] ?? $rc }}</span>@endforeach
                @endif
                <span data-qa="source-icons" style="display: inline-flex; gap: 2px;">
                    @if($portalsHere->contains('p24'))<span style="{{ $iconChip }}">P24</span>@endif
                    @if($portalsHere->contains('pp'))<span style="{{ $iconChip }}">PP</span>@endif
                    @if(!empty($xs['alert']))<span data-qa="envelope" style="{{ $iconChip }} padding: 1px 4px;" title="In a P24 alert email"><svg viewBox="0 0 16 12" width="14" height="10" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" style="display:block;"><rect x="1" y="1" width="14" height="10" rx="1.5"/><path d="M1.5 2.2 8 7l6.5-4.8"/></svg></span>@endif
                    @if($isCompanyStock)<span style="{{ $iconChip }} color: var(--brand-icon);" title="Our stock">Stock</span>@endif
                    @if(!empty($xs['deeds']))<span style="{{ $iconChip }}" title="Deeds captured">Deeds</span>@endif
                </span>
                @if($xt && !empty($xt['pairs']))
                    <a href="{{ count($xt['pairs']) === 1 ? route('corex.possible-matches.index', ['pair' => $xt['pairs'][0]]) : route('corex.possible-matches.index', ['file' => $xt['tp']]) }}" data-qa="possible-match-chip"
                       title="CoreX is not sure this is the same property as another file. Click to review." style="font-size:.625rem; font-weight:700; padding: 0 5px; border: 1px solid var(--ds-amber, #f59e0b); border-radius: 3px; color: var(--ds-amber, #f59e0b); text-decoration:none;">Possible match{{ count($xt['pairs']) > 1 ? ' (' . count($xt['pairs']) . ')' : '' }}</a>
                @endif
                @if($xt && $xt['tp'])
                    <button type="button" data-qa="card-toggle" title="Open the intelligence on this property: buyers, price, adverts, alerts, market, and the full record card"
                            @click.stop="intel = !intel"
                            style="font-size: .6875rem; padding: 0 6px; border: 1px solid var(--border); border-radius: 3px; background: transparent; color: var(--brand-icon); cursor: pointer;"><span x-text="intel ? 'Card ▴' : 'Card ▾'">Card ▾</span></button>
                @endif
            </span>

            {{-- IN STOCK badge — company stock = EXACT portal_ref match to one of
                 our own properties (Johan's model), not the fuzzy address match. --}}
            @if($isCompanyStock)
            <a href="{{ route('corex.properties.show', $companyStockPropertyId) }}"
               onclick="event.stopPropagation();"
               style="{{ $tagTeal }} text-decoration: none;"
               title="This is our agency's own listing — the portal reference matches one of our properties. Click to open the Property record.">
                IN STOCK
            </a>
            @endif

            {{-- Where we know this advert from: Email alert / Deeds / Extension, plus the last alert price change when the advert has an alert. --}}
            @if(!empty($source))
            <span data-qa="source-tag" style="{{ $source['tag'] === 'Email alert' ? $tagTeal : $tagNeutral }}"
                  title="{{ $source['tag'] === 'Email alert' ? 'This advert is also in the portal e-mail alerts your agency receives.' : ($source['tag'] === 'Deeds' ? 'The property file has a deeds capture.' : 'Captured with the browser extension only.') }}">
                {{ $source['tag'] }}
            </span>
            @if($source['alert'])
            <span data-qa="alert-marker" style="{{ $tagNeutral }}" title="Last portal e-mail alert price change for this advert.">
                alert @if($source['change'])R {{ number_format($source['change']['old'], 0, '.', ',') }} → R {{ number_format($source['change']['new'], 0, '.', ',') }} · {{ $source['change']['date'] }}@elseif($source['last_seen'])· seen {{ $source['last_seen'] }}@endif
            </span>
            @endif
            @endif

            {{-- Possible-match hint (2026-08-22, Johan — the 43 Ridge investigation:
                 "it should have been caught in mic before it was shown as pitch
                 now"). ADVISORY ONLY — never suppresses the row, never blocks a
                 pitch, exactly per Johan's instruction that MIC's cost of a miss
                 (wasted prospecting effort) is a different order of problem from
                 deeds' (double stock on a live listing). Computed asynchronously
                 (ComputePossibleStockMatchJob) — reading possible_property_id/
                 possible_match_verdict here costs nothing extra; they're already
                 columns on the row this template was handed. Never shown
                 alongside IN STOCK — a confident match already answers the
                 question. --}}
            @if(!$isCompanyStock && $listing->possible_match_verdict)
                @php
                    $possibleLabel = match($listing->possible_match_verdict) {
                        'confident_sectional_unit' => 'Might already be ours — check',
                        'possible_sectional_no_unit' => 'We hold something in this complex — check which unit',
                        'possible_freehold_single' => 'Might already be ours — check',
                        'possible_freehold_ambiguous' => 'We hold this address already — more than one record, check',
                        default => 'Might already be ours — check',
                    };
                @endphp
                <span style="{{ $tagAmber }}" title="{{ $possibleLabel }} — a nearby property in our own stock, matched by location, not confirmed by address.">
                    {{ $possibleLabel }}
                </span>
            @endif

            {{-- BUG 3 — sole/exclusive mandate badge. Only visible when the
                 agent has toggled "Show sole/exclusive mandates" on, since the
                 pool excludes these by default. --}}
            @if($listing->isSoleOrExclusiveMandate())
            <span style="{{ $tagAmber }}"
                  title="Flagged {{ strtolower($listing->mandate_type) }} mandate — another agency already holds this listing.">
                {{ ucfirst(strtolower($listing->mandate_type)) }} mandate
            </span>
            @endif

            {{-- Pitched tag --}}
            @if($pitch)
                @php
                    $pitchTagStyle = $isPitchedRecent ? $tagAmber : $tagNeutral;
                    $pitchDate = \Carbon\Carbon::parse($pitch['sent_at'])->format('j M');
                @endphp
                <span style="{{ $pitchTagStyle }}"
                      title="Last pitch sent on {{ \Carbon\Carbon::parse($pitch['sent_at'])->format('j M Y') }} by {{ $pitch['agent_name'] ?? 'someone' }}{{ $pitch['outcome'] && $pitch['outcome'] !== 'sent' ? ' — outcome: ' . str_replace('_', ' ', $pitch['outcome']) : '' }}">
                    pitched {{ $pitchDate }}
                </span>
            @endif

            {{-- Claim tag --}}
            @if($claim)
                @php
                    $hoursLeft = $claim['hours_left'] ?? null;
                    $isExpiring = (bool) ($claim['is_expiring'] ?? false);
                    // Near-deadline (claim auto-releases soon) is a warning, not a
                    // danger/fatal state — amber per UI_DESIGN_SYSTEM.md §1.5 / §3.4.
                    $claimTagStyle = $isExpiring
                        ? $tagAmber
                        : ($claimedByMe ? $tagTeal : $tagNeutral);
                    $claimLabel = $claimedByMe
                        ? ('you · ' . ($hoursLeft !== null ? round($hoursLeft) . 'h' : '—'))
                        : (\Illuminate\Support\Str::limit($claim['claimer_name'] ?? 'claimed', 12) . ' · ' . str_replace('_', ' ', $claim['status'] ?? ''));
                @endphp
                @php
                    // Plain-English tooltip explaining what the chip means and what the
                    // 48-hour expiry window does.
                    $claimTooltip = $claimedByMe
                        ? ('Your claim — expires in ' . ($hoursLeft !== null ? round($hoursLeft, 1) : '?') . ' hours unless you log feedback. The 48-hour window resets every time you update the claim.')
                        : (($claim['claimer_name'] ?? 'A colleague') . ' has claimed this listing (status: ' . str_replace('_', ' ', $claim['status'] ?? '') . '). You can still view buyers and contact details; open Property intel for the full record.');
                @endphp
                <span style="{{ $claimTagStyle }}"
                      title="{{ $claimTooltip }}">
                    {{ $claimLabel }}
                </span>
            @elseif($tempLock)
                <span style="{{ $tagAmber }}"
                      title="{{ $tempLock['user_name'] ?? 'Someone' }} is composing a pitch on this listing right now. Lock expires in {{ (int) ($tempLock['minutes_left'] ?? 0) }} minutes.">
                    pitching · {{ \Illuminate\Support\Str::limit($tempLock['user_name'] ?? '?', 10) }}
                </span>
            @elseif($prospected && $showProspectedBadge)
                {{-- Part 2 — durable "Prospected" badge. A colleague already worked this
                     listing and logged an outcome; the active-claim chip went blank when
                     the claim closed. This keeps the worked history visible so nobody
                     re-canvasses an owner we already approached. Data: most-recent
                     inactive-with-feedback claim (ProspectingListingStateEnricher::loadProspected). --}}
                @php
                    $prospName = \Illuminate\Support\Str::limit($prospected['claimer_name'] ?? 'a colleague', 12);
                    $prospOutcome = $prospected['outcome'] ?? 'Worked';
                    $prospDate = $prospected['worked_at'] ? \Carbon\Carbon::parse($prospected['worked_at'])->format('j M') : '';
                    $prospDateFull = $prospected['worked_at'] ? \Carbon\Carbon::parse($prospected['worked_at'])->format('j M Y') : '';
                @endphp
                <span style="{{ $tagOutline }}"
                      title="Already prospected by {{ $prospected['claimer_name'] ?? 'a colleague' }} — outcome: {{ $prospOutcome }}{{ $prospDateFull ? ' on ' . $prospDateFull : '' }}. Worked and closed (back in the pool), but check the history before re-canvassing the owner.">
                    Prospected · {{ $prospName }} · {{ $prospOutcome }}{{ $prospDate ? ' · ' . $prospDate : '' }}
                </span>
            @elseif($isUnclaimed)
                {{-- Company stock has no "unclaimed" state — the teal IN STOCK badge
                     above already says what this row is, and the claim button is
                     hidden in the action zone below (Johan's model: own stock is
                     never claimable, so it must never look claimable either).
                     $isUnclaimed is computed once at the top of this file and is
                     the SAME condition the Claim button in the action zone below
                     gates on — one source of truth, so the two can never disagree
                     (Johan 2026-08-19). --}}
                <span style="{{ $tagNeutral }}"
                      title="Nobody has claimed this listing yet. Click Claim on the right to reserve it for yourself.">unclaimed</span>
            @endif

            {{-- Presentation marker --}}
            @if($presentation)
            <a href="/presentations/{{ $presentation['presentation_id'] }}" target="_blank"
               onclick="event.stopPropagation();"
               style="{{ $tagOutline }} text-decoration: none;"
               title="Presentation '{{ $presentation['title'] ?? 'Untitled' }}' on {{ \Carbon\Carbon::parse($presentation['created_at'])->format('j M Y') }}">
                presented
            </a>
            @endif

            {{-- F.8 — "TP →" renamed to "Property intel" (jargon-out pass).
                 Click target unchanged: tracked-property record with the full
                 source chain across P24/PP/CMA/captures plus action history. --}}
            @if($listing->tracked_property_id)
            <a href="{{ route('corex.tracked-properties.show', $listing->tracked_property_id) }}"
               target="_blank" rel="noopener"
               onclick="event.stopPropagation();"
               style="{{ $tagOutline }} text-decoration: none;"
               title="Open this property's full intelligence record (new tab) — every source we have for it (P24, PP, CMA, captures), every buyer match, every action history.">
                Property intel →
            </a>
            @endif

            {{-- AT-242 — when prospecting for a specific buyer, show THAT buyer's
                 own Core Matches score prominently (the number the agent is
                 acting on), distinct from the blended demand microbar below. --}}
            @if(($selectedScore ?? null) !== null)
            @php
                $sc = (int) $selectedScore;
                $scColor = $sc >= 80 ? 'var(--ds-green, #10b981)' : ($sc >= 65 ? 'var(--ds-amber, #f59e0b)' : 'var(--text-secondary, #6b7280)');
            @endphp
            <span style="display:inline-flex; align-items:center; gap:4px; font-size:0.6875rem; font-weight:700; color: {{ $scColor }};"
                  title="This buyer's match score for this property, from the canonical Core Matches engine. 80+ strong, 65–79 good, below fair.">
                {{ $sc }}% buyer match
            </span>
            @endif

            {{-- Demand microbar — strong / mid counts. Phase E3: hover loads
                 a one-sentence AI tooltip explaining why the buyers match.

                 2026-08-14: the tooltip used to be a one-way latch — `load()`
                 set `tooltip` once and nothing ever cleared it, so
                 x-show="tooltip || loading" stayed permanently true after the
                 first hover, leaving a blank card stuck on top of the row(s)
                 below with no way to close it. Fixed to a proper hover-reveal:
                 `open` tracks mouseenter/mouseleave (and focusin/focusout for
                 keyboard users) and gates visibility, while the fetched text
                 is still cached per-listing so re-hovering doesn't re-fetch.
                 max-height/overflow caps it so a long AI sentence can't grow
                 into a multi-row-covering block. --}}
            @if(($tiers['strong'] + $tiers['mid']) > 0)
            <span x-data="micMatchTooltip({{ $listing->id }})"
                  @mouseenter="show()"
                  @mouseleave="hide()"
                  @focusin="show()"
                  @focusout="hide()"
                  style="position: relative; display: inline-block;">
                <button type="button"
                        @click.stop="openBuyerPanel({{ $listing->id }})"
                        style="{{ $tagOutline }} cursor: pointer;"
                        title="Buyer matches for this listing — green dot = strong-tier (high likelihood of conversion, score ≥ 80). Amber = mid-tier (50–79). Top score {{ $tiers['top_score'] ?? '?' }}%. Click for the full buyer list. Hover for Ellie's read.">
                    @if($tiers['strong'] > 0)<span style="display:inline-block; width:7px; height:7px; border-radius:50%; background: var(--ds-green, #10b981);"></span> <b style="color: var(--text-primary);">{{ $tiers['strong'] }}</b> strong @endif
                    @if($tiers['mid'] > 0)<span style="display:inline-block; width:7px; height:7px; border-radius:50%; background: var(--ds-amber, #f59e0b); margin-left: 6px;"></span> <b style="color: var(--text-primary);">{{ $tiers['mid'] }}</b> mid @endif
                    @if(($tiers['top_score'] ?? null) !== null)<span style="margin-left: 6px; color: var(--text-muted);">{{ $tiers['top_score'] }}% match</span>@endif
                </button>
                {{-- Part 6 — source-tagged demand split (portal-lead buyers vs other),
                     kept SEPARATE from the blended strong/mid figure above so you can
                     always see where the demand was generated from. --}}
                @php $srcDemand = $tiers['sources'] ?? ['portal_lead'=>0,'other'=>0]; @endphp
                @if(($srcDemand['portal_lead'] ?? 0) > 0)
                    <span style="margin-left: 5px; font-size: 0.625rem; font-weight: 600; color: var(--brand-icon, #0ea5e9);"
                          title="Of these buyers, {{ $srcDemand['portal_lead'] }} came via a portal-lead enquiry and {{ $srcDemand['other'] ?? 0 }} from other sources — counted separately, never blended into one figure.">
                        {{ $srcDemand['portal_lead'] }} via portal lead
                    </span>
                @endif
                <span x-show="open && (tooltip || loading)" x-cloak
                      style="position: absolute; top: 100%; left: 0; margin-top: 4px; z-index: 20;
                             width: 280px; max-height: 120px; overflow-y: auto; padding: 8px 10px; font-size: 0.6875rem; line-height: 1.4;
                             background: var(--surface); color: var(--text-primary);
                             border: 1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 30%, var(--border));
                             border-radius: 4px; box-shadow: 0 4px 10px rgba(0,0,0,0.12);">
                    <span x-show="loading" style="color: var(--text-muted);">Loading Ellie's read…</span>
                    <span x-show="!loading" x-text="tooltip"></span>
                </span>
            </span>
            @endif
            {{-- Comment icon relocated to the right-hand icon group below
                 (Johan 2026-08-19: "move the comments icon to join the other
                 2 icons, and move the icon group left, underneath the price" —
                 makes room for a proper Claim button under Pitch Now). Markup
                 and behaviour (openCommentsModal, the count badge) unchanged,
                 only moved — see the action zone below. --}}
        </div>
    </div>

@include('corex.market-intelligence._row-actions')
</article>
{{-- The Card button opens the same intelligence block the Adverts card carries (one partial, two places). --}}
@if(!empty($adv) && !empty($adv['tp_id']))
<div x-show="intel" x-cloak data-qa="row-intel-host" style="display:flex; justify-content:flex-end; padding: 8px 14px; background: var(--surface-2); border-bottom: 1px solid var(--border);">
    <div style="width: 100%; max-width: 28rem;">@include('corex.market-intelligence._advert-intel')</div>
</div>
@endif
@endif
