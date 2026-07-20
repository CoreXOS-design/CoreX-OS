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

    // Address truncation — keep it tight on the primary line.
    $addressShort = \Illuminate\Support\Str::limit($listing->address ?? '—', 50);
    $priceLabel = $listing->price ? ('R ' . number_format($listing->price)) : '—';

    $metaParts = array_filter([
        $listing->suburb ?? null,
        ($listing->bedrooms ?? '-') . '|' . ($listing->bathrooms ?? '-') . '|' . ($listing->garages ?? '-'),
        $listing->property_type ?? null,
        $listing->agency_name ? \Illuminate\Support\Str::limit($listing->agency_name, 20) : null,
        $listing->portal_ref ?? null,
        $listing->first_seen_at ? $listing->first_seen_at->format('j M') : null,
    ]);

    // Common chip style helpers
    $tagBase = 'display: inline-flex; align-items: center; gap: 3px; padding: 2px 7px; font-size: 0.625rem; font-weight: 600; border-radius: 999px; line-height: 1.4; white-space: nowrap;';
    $tagAmber = $tagBase . 'background: color-mix(in srgb, var(--ds-amber, #f59e0b) 14%, transparent); color: var(--ds-amber, #f59e0b); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 35%, transparent);';
    $tagTeal = $tagBase . 'background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 14%, transparent); color: var(--brand-icon, #0ea5e9); border: 1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 35%, transparent);';
    $tagRed = $tagBase . 'background: color-mix(in srgb, var(--ds-crimson, #dc2626) 14%, transparent); color: var(--ds-crimson, #dc2626); border: 1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 35%, transparent);';
    $tagNeutral = $tagBase . 'background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border);';
    $tagOutline = $tagBase . 'background: transparent; color: var(--text-secondary); border: 1px solid var(--border);';

    $iconBtnBase = 'display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 4px; background: var(--surface-2); border: 1px solid var(--border); color: var(--text-secondary); cursor: pointer; text-decoration: none;';
    $iconBtnDisabled = 'display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 4px; background: var(--surface); border: 1px dashed var(--border); color: var(--text-muted); cursor: not-allowed; opacity: 0.55;';
@endphp

<article
    class="mi-row"
    data-listing-id="{{ $listing->id }}"
    role="button"
    tabindex="0"
    @click="$dispatch('open-slideover', { listingId: {{ $listing->id }}, trigger: $el })"
    @keydown.enter.prevent="$dispatch('open-slideover', { listingId: {{ $listing->id }}, trigger: $el })"
    @keydown.space.prevent="$dispatch('open-slideover', { listingId: {{ $listing->id }}, trigger: $el })"
    style="display: grid; grid-template-columns: 44px 1fr 200px; gap: 12px; align-items: center;
           padding: 10px 14px; min-height: 70px; background: var(--surface); border-bottom: 1px solid var(--border);
           cursor: pointer; transition: background 120ms;"
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
            <div style="font-size: 0.9375rem; font-weight: 600; color: var(--text-primary); flex-shrink: 0;">{{ $priceLabel }}</div>
        </div>

        {{-- Line 2: meta --}}
        <div style="font-size: 0.6875rem; color: var(--text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
            {{ implode(' · ', $metaParts) }}
        </div>

        {{-- Line 3: state tags + demand microbar --}}
        <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
            {{-- IN STOCK badge (only when manager has audit toggle on and the listing is in stock) --}}
            @if($listing->matched_property_id)
            <a href="{{ route('corex.properties.show', $listing->matched_property_id) }}"
               onclick="event.stopPropagation();"
               style="{{ $tagTeal }} text-decoration: none;"
               title="This property is in our agency stock — we already hold the mandate. Click to open the Property record.">
                IN STOCK
            </a>
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
            @else
                <span style="{{ $tagNeutral }}"
                      title="Nobody has claimed this listing yet. Click the bookmark icon on the right to claim it for yourself.">unclaimed</span>
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
               onclick="event.stopPropagation();"
               style="{{ $tagOutline }} text-decoration: none;"
               title="Open this property's full intelligence record — every source we have for it (P24, PP, CMA, captures), every buyer match, every action history.">
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
            <span style="display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:999px; font-size:0.6875rem; font-weight:700;
                         background: color-mix(in srgb, {{ $scColor }} 14%, transparent); color: {{ $scColor }}; border:1px solid currentColor;"
                  title="This buyer's match score for this property, from the canonical Core Matches engine. 80+ strong, 65–79 good, below fair.">
                🎯 {{ $sc }}% buyer match
            </span>
            @endif

            {{-- Demand microbar — strong / mid counts. Phase E3: hover loads
                 a one-sentence AI tooltip explaining why the buyers match. --}}
            @if(($tiers['strong'] + $tiers['mid']) > 0)
            <span x-data="micMatchTooltip({{ $listing->id }})"
                  @mouseenter="load()"
                  @focusin="load()"
                  style="position: relative; display: inline-block;">
                <button type="button"
                        @click.stop="openBuyerPanel({{ $listing->id }})"
                        style="{{ $tagOutline }} cursor: pointer;"
                        title="Buyer matches for this listing — green dot = strong-tier (high likelihood of conversion, score ≥ 80). Amber = mid-tier (50–79). Top score {{ $tiers['top_score'] ?? '?' }}%. Click for the full buyer list. Hover for Ellie's read.">
                    @if($tiers['strong'] > 0)<span style="color: var(--ds-green, #10b981);">●</span> {{ $tiers['strong'] }}@endif
                    @if($tiers['mid'] > 0)<span style="color: var(--ds-amber, #f59e0b); margin-left: 4px;">●</span> {{ $tiers['mid'] }}@endif
                    @if(($tiers['top_score'] ?? null) !== null)<span style="margin-left: 5px; font-weight: 700; color: var(--text-secondary, #6b7280);">{{ $tiers['top_score'] }}%</span>@endif
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
                <span x-show="tooltip || loading" x-cloak
                      style="position: absolute; top: 100%; left: 0; margin-top: 4px; z-index: 20;
                             width: 280px; padding: 8px 10px; font-size: 0.6875rem; line-height: 1.4;
                             background: var(--surface); color: var(--text-primary);
                             border: 1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 30%, var(--border));
                             border-radius: 4px; box-shadow: 0 4px 10px rgba(0,0,0,0.12);">
                    <span x-show="loading" style="color: var(--text-muted);">Loading Ellie's read…</span>
                    <span x-show="!loading" x-text="tooltip"></span>
                </span>
            </span>
            @endif
        </div>
    </div>

    {{-- Right action zone: chip on top, 3 inline icon buttons below --}}
    <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 6px;">

        @include('corex.market-intelligence._suggested-action-chip', ['suggested' => $suggested, 'listing' => $listing])

        <div style="display: flex; align-items: center; gap: 4px;">
            @php
                // Decide which 3 icons to show based on state per spec §8.4.
                $showBookmark = !$claim && !$tempLock;       // unclaimed only
                $showEye      = $claimedByOther;             // colleague's claim → view detail (F.4 wires)
                $showPhone    = !$showBookmark && !$showEye; // claimed-by-me, pitched, or pitched-recent
                $showWhatsapp = $showPhone;
                $showUsers    = ($tiers['strong'] + $tiers['mid']) > 0;  // always if there are buyers
            @endphp

            {{-- Bookmark / Claim button --}}
            @if($showBookmark)
            <form method="POST" action="{{ route('market-intelligence.claim', $listing->id) }}"
                  data-tour="mic-claim"
                  onclick="event.stopPropagation();"
                  style="margin: 0; line-height: 0;">
                @csrf
                <button type="submit"
                        aria-label="Claim this listing"
                        title="Claim this listing"
                        style="{{ $iconBtnBase }}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z"/>
                    </svg>
                </button>
            </form>
            @endif

            {{-- Eye / view detail (F.4 will wire to slide-over) --}}
            @if($showEye)
            <button type="button"
                    @click.stop="selected = true"
                    aria-label="View detail"
                    title="View detail (slide-over arrives in F.4)"
                    style="{{ $iconBtnBase }}">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
            </button>
            @endif

            {{-- Phone (tel:) --}}
            @if($showPhone)
                @if($telHref)
                <a href="{{ $telHref }}"
                   onclick="event.stopPropagation();"
                   aria-label="Call {{ $pitch['recipient_phone'] ?? 'seller' }}"
                   title="Call {{ $pitch['recipient_phone'] ?? 'seller' }}"
                   style="{{ $iconBtnBase }}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>
                    </svg>
                </a>
                @else
                <button type="button" disabled
                        aria-label="No phone available"
                        title="No linked contact — claim and pitch first"
                        style="{{ $iconBtnDisabled }}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>
                    </svg>
                </button>
                @endif
            @endif

            {{-- WhatsApp --}}
            @if($showWhatsapp)
                @if($waHref && ($outreachWindow['allowed'] ?? true))
                <a href="{{ $waHref }}" target="_blank" rel="noopener"
                   onclick="event.stopPropagation();"
                   aria-label="Open WhatsApp"
                   title="Open WhatsApp"
                   style="{{ $iconBtnBase }}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.71.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413"/>
                    </svg>
                </a>
                @else
                {{-- AT-117 §4a — title reflects the real reason: out-of-window vs no contact. --}}
                <button type="button" disabled
                        aria-label="WhatsApp unavailable"
                        title="{{ ($waHref && !($outreachWindow['allowed'] ?? true)) ? ($outreachWindow['message'] ?? 'Outreach sending is closed right now.') : 'No linked contact — claim and pitch first' }}"
                        style="{{ $iconBtnDisabled }}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.71.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413"/>
                    </svg>
                </button>
                @endif
            @endif

            {{-- Buyers / users (opens buyer-matches panel) --}}
            @if($showUsers)
            <button type="button"
                    @click.stop="openBuyerPanel({{ $listing->id }})"
                    aria-label="View matched buyers"
                    title="View {{ $tiers['total'] }} matched buyer{{ $tiers['total'] === 1 ? '' : 's' }}"
                    style="{{ $iconBtnBase }}">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
            </button>
            @endif
        </div>
    </div>
</article>
