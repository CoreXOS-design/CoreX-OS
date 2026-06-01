{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    F.4 — Slide-over header with action bar.

    Renders: 96×96 thumb, address+meta+price, source badges, action-bar
    buttons (state-aware). Includes the inline Add-note modal at the
    bottom of this partial.
--}}

@php
    $h = $header;
    $viewer = $viewer ?? [];
    $isManager = (bool) ($viewer['is_manager'] ?? false);
    $canPitch  = (bool) ($viewer['can_pitch']  ?? false);
    $viewerId  = $viewer['id'] ?? null;
    $claim = $state['claim'] ?? null;
    $pitch = $state['pitch'] ?? null;
    $claimedByMe = $claim && $viewerId && (int) ($claim['user_id'] ?? 0) === (int) $viewerId;

    // Phone normalisation for tel: + wa.me — same convention as the F.3 row.
    $rawPhone = $pitch && !empty($pitch['recipient_phone']) ? (string) $pitch['recipient_phone'] : '';
    $digits = preg_replace('/\D/', '', $rawPhone);
    if ($digits !== '' && str_starts_with($digits, '0')) {
        $digits = '27' . substr($digits, 1);
    }
    $telHref = $rawPhone !== '' ? 'tel:' . $rawPhone : null;
    $waHref = $digits !== '' ? 'https://wa.me/' . $digits : null;

    $actionBtn = 'display: inline-flex; align-items: center; gap: 5px; padding: 6px 11px; font-size: 0.75rem; font-weight: 600; border-radius: 4px; cursor: pointer; text-decoration: none; white-space: nowrap;';
    $actionPrimary = $actionBtn . 'background: var(--brand-default, #0b2a4a); color: #fff; border: 1px solid var(--brand-default, #0b2a4a);';
    $actionSecondary = $actionBtn . 'background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);';
    $actionDisabled = $actionBtn . 'background: var(--surface); color: var(--text-muted); border: 1px dashed var(--border); cursor: not-allowed; opacity: 0.55;';
@endphp

<div style="padding: 14px; border-bottom: 1px solid var(--border); flex-shrink: 0;">
    <div style="display: grid; grid-template-columns: 96px 1fr auto; gap: 12px; align-items: start;">
        {{-- Large thumbnail --}}
        <div style="width: 96px; height: 96px; border-radius: 6px; overflow: hidden;
                    background: var(--surface-2); border: 1px solid var(--border); flex-shrink: 0;">
            @if($h['photo_url'])
                <img src="{{ $h['photo_url'] }}" alt="" loading="lazy"
                     style="width: 100%; height: 100%; object-fit: cover; display: block;">
            @else
                <div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--text-muted);">
                        <path d="M3 9.5L12 4l9 5.5"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/>
                    </svg>
                </div>
            @endif
        </div>

        {{-- Address + meta --}}
        <div style="min-width: 0;">
            <div style="font-size: 1rem; font-weight: 600; color: var(--text-primary); line-height: 1.3; overflow: hidden; text-overflow: ellipsis;">
                {{ $h['address'] }}
            </div>
            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">
                {{ $h['suburb'] }} ·
                {{ $h['beds'] ?? '-' }}|{{ $h['baths'] ?? '-' }}|{{ $h['garages'] ?? '-' }} ·
                {{ $h['property_type'] ?: '—' }} ·
                {{ $h['agency'] ? \Illuminate\Support\Str::limit($h['agency'], 24) : '—' }} ·
                {{ $h['portal_ref'] ?: '—' }}
            </div>
            <div style="margin-top: 8px; display: flex; flex-wrap: wrap; gap: 4px;">
                @if($h['in_stock'])
                <a href="{{ route('corex.properties.show', $h['matched_property_id']) }}"
                   style="display: inline-flex; align-items: center; padding: 2px 7px; font-size: 0.625rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em; border-radius: 4px; background: var(--brand-default); color: #fff; text-decoration: none;"
                   title="This property is in our agency stock — we already hold the mandate. Click to open the Property record.">
                    IN STOCK
                </a>
                @endif
                @if($h['tracked_property_id'])
                <a href="{{ route('corex.tracked-properties.show', $h['tracked_property_id']) }}"
                   style="display: inline-flex; align-items: center; padding: 2px 7px; font-size: 0.625rem; font-weight: 600; border-radius: 4px; background: transparent; color: var(--text-secondary); border: 1px solid var(--border); text-decoration: none;"
                   title="Open the full Property intel record — every source for this property (P24, PP, CMA, captures), every buyer match, every action history.">
                    Property intel →
                </a>
                @endif
                @if($listing->portal_source === 'p24' || $listing->portal_source === 'pp')
                {{-- Portal brand pill — external brand colours, var(--token, #fallback)
                     pattern per UI_DESIGN_SYSTEM.md §5.10. --}}
                <span style="display: inline-flex; align-items: center; padding: 2px 7px; font-size: 0.625rem; font-weight: 700; letter-spacing: 0.02em; border-radius: 4px;
                             background: {{ $listing->portal_source === 'p24' ? 'var(--portal-p24, #1e40af)' : 'var(--portal-pp, #059669)' }}; color: #fff;"
                      title="{{ $listing->portal_source === 'p24' ? 'Property24 portal listing — captured from property24.com' : 'Private Property portal listing — captured from privateproperty.co.za' }}">
                    {{ strtoupper($listing->portal_source) }}
                </span>
                @endif
            </div>
        </div>

        {{-- Price --}}
        <div style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary); white-space: nowrap;">
            @if($h['price'])R {{ number_format($h['price']) }}@else—@endif
        </div>
    </div>

    {{-- Action bar --}}
    <div x-data="{ noteOpen: false }" style="margin-top: 12px; display: flex; flex-wrap: wrap; gap: 6px;">
        @if($canPitch)
            @if($h['in_stock'])
                <a href="{{ route('seller-outreach.entry.from-property', $h['matched_property_id']) }}"
                   style="{{ $actionPrimary }}">
                    💬 Pitch
                </a>
            @else
                <a href="{{ route('seller-outreach.entry.from-prospecting', ['prospectingListingId' => $listing->id]) }}"
                   style="{{ $actionPrimary }}">
                    💬 Pitch
                </a>
            @endif
        @endif

        @if($telHref)
            <a href="{{ $telHref }}" style="{{ $actionSecondary }}" title="Call {{ $rawPhone }}">📞 Call</a>
            <a href="{{ $waHref }}" target="_blank" rel="noopener" style="{{ $actionSecondary }}" title="Open WhatsApp">💬 WhatsApp</a>
        @else
            <span style="{{ $actionDisabled }}" title="No linked contact — pitch first">📞 Call</span>
            <span style="{{ $actionDisabled }}" title="No linked contact — pitch first">💬 WhatsApp</span>
        @endif

        @if($claim)
            @if($claimedByMe)
                <form method="POST" action="{{ route('market-intelligence.release', $listing->id) }}" style="display: inline; margin: 0;">
                    @csrf
                    <button type="submit" style="{{ $actionSecondary }}">↩ Release claim</button>
                </form>
            @elseif($isManager)
                <button type="button"
                        onclick="document.getElementById('mi-release-modal-trigger').click()"
                        style="{{ $actionSecondary }}"
                        title="Release a colleague's claim (manager-only)">
                    ↩ Release (BM)
                </button>
            @endif
        @else
            <form method="POST" action="{{ route('market-intelligence.claim', $listing->id) }}" style="display: inline; margin: 0;">
                @csrf
                <button type="submit" style="{{ $actionSecondary }}">🔒 Claim</button>
            </form>
        @endif

        {{-- Add note — claim owner OR manager only --}}
        @if($claim && ($claimedByMe || $isManager))
        <button type="button"
                @click="noteOpen = true"
                style="{{ $actionSecondary }}">
            ✎ Add note
        </button>
        @endif

        @if($h['tracked_property_id'])
            <a href="{{ route('corex.tracked-properties.show', $h['tracked_property_id']) }}"
               style="{{ $actionSecondary }}">
                View TP →
            </a>
        @endif

        @if($isManager && $h['tracked_property_id'] && !$h['in_stock'])
            <form method="POST" action="{{ route('corex.tracked-properties.promote', $h['tracked_property_id']) }}"
                  onsubmit="return confirm('Promote this Tracked Property to agency stock?');"
                  style="display: inline; margin: 0;">
                @csrf
                <button type="submit" style="{{ $actionSecondary }}">⬆ Promote</button>
            </form>
        @endif

        {{-- Inline Add-note modal --}}
        <div x-show="noteOpen"
             x-cloak
             @keydown.escape.window="noteOpen = false"
             @click.self="noteOpen = false"
             style="position: fixed; inset: 0; z-index: 70; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center;">
            <div @click.stop style="background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 16px; width: 90%; max-width: 420px;">
                <h3 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 8px; color: var(--text-primary);">Add note to claim</h3>
                <form
                    x-data="{ submitting: false, error: null, value: '' }"
                    @submit.prevent="
                        submitting = true; error = null;
                        try {
                            const res = await fetch('{{ route('market-intelligence.add-note', $listing->id) }}', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''
                                },
                                credentials: 'same-origin',
                                body: JSON.stringify({ note: value })
                            });
                            const data = await res.json();
                            if (!res.ok) throw new Error(data.error || ('HTTP ' + res.status));
                            // Prepend the rendered entry to the Activity timeline if present.
                            const tl = document.querySelector('[data-panel=&quot;activity&quot;] .mi-activity-timeline');
                            if (tl && data.entry_html) tl.insertAdjacentHTML('afterbegin', data.entry_html);
                            noteOpen = false; value = '';
                        } catch (e) {
                            error = e.message;
                        } finally {
                            submitting = false;
                        }
                    ">
                    <textarea x-model="value"
                              required
                              minlength="3"
                              maxlength="1000"
                              rows="3"
                              placeholder="Add context, next steps, conversation notes…"
                              style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px; background: var(--surface-2); color: var(--text-primary); font-size: 0.8125rem; resize: vertical;"></textarea>
                    <div x-show="error" x-text="error" style="color: var(--ds-crimson, #dc2626); font-size: 0.75rem; margin-top: 6px;"></div>
                    <div style="display: flex; justify-content: flex-end; gap: 6px; margin-top: 10px;">
                        <button type="button" @click="noteOpen = false" style="{{ $actionSecondary }}">Cancel</button>
                        <button type="submit" :disabled="submitting" style="{{ $actionPrimary }}">
                            <span x-show="!submitting">Save note</span>
                            <span x-show="submitting">Saving…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
