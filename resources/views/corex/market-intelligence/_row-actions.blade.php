{{-- The row's right action zone (suggested-action chip, icon group, Claim). Extracted verbatim from _listing-row so the Adverts view (mic-work-tab.md 13.3)
     can show the same buttons: it is @include'd and so reads the row's own variables ($listing, $isUnclaimed, $claimedByOther, $iconBtnBase, ...). --}}
    {{-- Right action zone: chip on top, 3 inline icon buttons below.
         Company stock → no pitch: the agency's own logo replaces the whole
         pitch CTA + action-icon cluster (Johan's model). --}}
    <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 6px;">

        @if($isCompanyStock)
        <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 3px;"
             title="This is our agency's own listing (portal reference matches our stock) — no pitch needed.">
            @if($agencyLogoUrl)
            <img src="{{ $agencyLogoUrl }}" alt="Company stock"
                 style="max-height: 30px; max-width: 130px; object-fit: contain; display: block;">
            @else
            <span style="{{ $tagTeal }}">OUR LISTING</span>
            @endif
            <span style="font-size: 0.625rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em;">Company stock</span>
        </div>
        @else

        @include('corex.market-intelligence._suggested-action-chip', ['suggested' => $suggested, 'listing' => $listing])

        {{-- Icon group + Claim share ONE line (Johan 2026-08-21 — the icon
             row previously sat alone with nothing but 1-2 small glyphs on
             it, wasted vertical space across 1,769 rows). align-self:stretch
             makes this row take the full 200px column width (its parent is
             align-items:flex-end, which would otherwise shrink-wrap it);
             justify-content:space-between then pushes the icon group flush
             left and Claim flush right — real, non-fixed space between them
             by construction, so they read as two separate controls, not one
             cluster, no matter how many icons are showing (Johan has flagged
             bunched controls twice today). Claim only ever renders when
             $isUnclaimed, and every icon that can show alongside it then
             (comment, buyers) requires !$isUnclaimed to be false for eye/
             phone/whatsapp — so at most 2 small icons ever share this line
             with Claim, never crowding the fixed 200px column. --}}
        <div style="display: flex; align-items: center; justify-content: space-between; width: 100%; align-self: stretch;">
        <div style="display: flex; align-items: center; gap: 4px;">
            @php
                $showEye      = $claimedByOther;             // colleague's claim → view detail (F.4 wires)
                $showPhone    = !$isUnclaimed && !$showEye;  // claimed-by-me, pitched, or pitched-recent
                $showWhatsapp = $showPhone;
                $showUsers    = ($tiers['strong'] + $tiers['mid']) > 0;  // always if there are buyers
            @endphp

            {{-- Comment (relocated here from the middle chip row, same
                 behaviour/modal/count badge — .ai/specs/mic-property-row-comments.md) --}}
            @if($listing->tracked_property_id && ($canViewComments ?? false))
                @php $commentCount = (int) (($commentCounts ?? [])[$listing->tracked_property_id] ?? 0); @endphp
                <button type="button"
                        @click.stop="openCommentsModal({{ $listing->tracked_property_id }})"
                        style="{{ $iconBtnBase }}"
                        title="{{ $commentCount > 0 ? ($commentCount . ' comment' . ($commentCount === 1 ? '' : 's') . ' on this property — click to view and add.') : 'No comments yet on this property — click to add the first one for other agents.' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0;">
                        <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
                    </svg><span data-tp-comment-count="{{ $listing->tracked_property_id }}" style="font-weight: 700; margin-left: {{ $commentCount > 0 ? '3px' : '0' }};">{{ $commentCount > 0 ? $commentCount : '' }}</span>
                </button>
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

        {{-- Claim button (Johan 2026-08-19: "pitch now means NOW, claim means
             I want it but I can't work on it right now") — a real, comfortably
             sized button on the same line as the icon group, flush right
             (justify-content:space-between on the wrapper above), clearly a
             different colour/shape/weight so it can't be hit by mistake next
             to the icons beside it (outline blue vs Pitch Now's solid green
             chip above). SAME action the property detail panel's Claim
             already uses — same route, same controller method, same form —
             a second, more visible entry point onto it, not a parallel
             implementation. Gated on $isUnclaimed, the exact same condition
             the "unclaimed" chip above uses, so this button and that label
             can never disagree about whether claiming makes sense here
             (already claimed, already promoted-via-tracked-property-with-a-
             claim, mid-pitch-lock, prospected/worked history, or company stock
             all correctly hide it, same as they already hide "unclaimed"). --}}
        @if($isUnclaimed)
        <form method="POST" action="{{ route('market-intelligence.claim', $listing->id) }}"
              data-tour="mic-claim"
              onclick="event.stopPropagation();"
              style="margin: 0; flex-shrink: 0;">
            @csrf
            {{-- Sizing MUST match _suggested-action-chip.blade.php's $baseChipStyle
                 exactly (padding, radius, min-width, box-sizing) — Johan
                 2026-08-21: "make the buttons the same size... it just looks
                 such a lot better when things square up." min-width fits
                 "PITCH NOW · HIGH" (the widest label an ordinary agent sees),
                 so Claim grows to match rather than Pitch Now shrinking; same
                 geometry, different weight (solid green vs this outline blue)
                 keeps them square without making them look identical. --}}
            <button type="submit"
                    aria-label="Claim this listing — reserve it for yourself without pitching yet"
                    title="Claim — reserve this listing for yourself. It leaves the canvass list and moves to My Claims; releases automatically if you don't pitch it."
                    style="display:inline-flex; align-items:center; justify-content:center; gap:5px; padding:6px 12px; font-size:0.75rem; font-weight:600; border-radius:6px; background:var(--surface); border:1px solid var(--border-hover, var(--border)); color:var(--text-secondary); cursor:pointer; white-space:nowrap; min-width:150px; box-sizing:border-box;">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z"/>
                </svg>
                Claim
            </button>
        </form>
        @endif
        </div>

        @endif {{-- /isCompanyStock --}}
    </div>
