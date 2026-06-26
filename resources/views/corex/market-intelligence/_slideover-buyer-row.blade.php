{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    F.4 — A single buyer row used by both the Overview top-5 list and the
    full Buyers tab list.

    Input: $b — stdClass from BuyerMatchTierService::buyersForListing()
--}}
@php
    $score = (int) ($b->score ?? 0);
    $tier = $b->tier ?? 'weak';
    $tierColor = match($tier) {
        'strong' => 'var(--ds-green, #10b981)',
        'mid'    => 'var(--ds-amber, #f59e0b)',
        default  => 'var(--text-muted)',
    };
    $phoneRaw = $b->phone ?? '';
    $digits = preg_replace('/\D/', '', (string) $phoneRaw);
    if ($digits !== '' && str_starts_with($digits, '0')) $digits = '27' . substr($digits, 1);
    $telHref = $phoneRaw !== '' ? 'tel:' . $phoneRaw : null;
    $waHref = $digits !== '' ? 'https://wa.me/' . $digits : null;

    $wishlistBits = [];
    if ($b->wishlist_name) $wishlistBits[] = $b->wishlist_name;
    if ($b->price_min || $b->price_max) {
        $lo = $b->price_min ? 'R ' . number_format($b->price_min / 1000) . 'k' : 'R 0';
        $hi = $b->price_max ? 'R ' . number_format($b->price_max / 1000) . 'k' : '∞';
        $wishlistBits[] = $lo . '–' . $hi;
    }
    if ($b->beds_min || $b->bedrooms_max) {
        $bedsLo = $b->beds_min ?? '?';
        $bedsHi = $b->bedrooms_max ?? '?';
        $wishlistBits[] = $bedsLo === $bedsHi ? "{$bedsLo} bed" : "{$bedsLo}-{$bedsHi} bed";
    }

    $iconBtn = 'display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 4px; background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary); cursor: pointer; text-decoration: none;';
    $iconBtnDisabled = 'display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 4px; background: var(--surface); border: 1px dashed var(--border); color: var(--text-muted); opacity: 0.5;';
@endphp

<div style="display: grid; grid-template-columns: 1fr auto; gap: 10px; padding: 8px 10px;
            background: var(--surface); border: 1px solid var(--border); border-radius: 4px; align-items: center;">
    <div style="min-width: 0;">
        <div style="display: flex; align-items: baseline; gap: 8px; min-width: 0;">
            <a href="{{ route('corex.contacts.show', $b->contact_id) }}"
               style="font-size: 0.8125rem; font-weight: 600; color: var(--text-primary); text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                {{ $b->display_name ?: 'Unnamed' }}
            </a>
            <span style="font-size: 0.625rem; font-weight: 700; color: {{ $tierColor }}; padding: 1px 5px; border-radius: 4px; background: color-mix(in srgb, {{ $tierColor }} 12%, transparent);">
                {{ $score }}%
            </span>
            {{-- Part 6 — demand source tag (portal-lead buyers stay visibly distinct). --}}
            @if(!empty($b->source_label))
            <span style="font-size: 0.5625rem; font-weight: 600; color: var(--brand-icon, #0ea5e9); padding: 1px 5px; border-radius: 4px; background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 10%, transparent); white-space: nowrap;"
                  title="How this buyer's demand entered CoreX. Portal-lead demand is counted separately from other sources, never blended.">
                {{ $b->source_label }}
            </span>
            @endif
        </div>
        @if(!empty($wishlistBits))
        <div style="font-size: 0.6875rem; color: var(--text-muted); margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
            {{ implode(' · ', $wishlistBits) }}
        </div>
        @endif
    </div>

    <div style="display: flex; align-items: center; gap: 4px;">
        @if($telHref)
            <a href="{{ $telHref }}" aria-label="Call {{ $b->display_name }}" title="Call" style="{{ $iconBtn }}">
                <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
            </a>
        @else
            <span aria-label="No phone available" title="No phone on file" style="{{ $iconBtnDisabled }}">
                <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
            </span>
        @endif

        @if($waHref)
            <a href="{{ $waHref }}" target="_blank" rel="noopener" aria-label="WhatsApp" title="Open WhatsApp" style="{{ $iconBtn }}">
                <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.71.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413"/></svg>
            </a>
        @else
            <span style="{{ $iconBtnDisabled }}">
                <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.71.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413"/></svg>
            </span>
        @endif

        <a href="{{ route('corex.contacts.show', $b->contact_id) }}"
           aria-label="View contact profile" title="View contact profile"
           style="{{ $iconBtn }}">→</a>
    </div>
</div>
