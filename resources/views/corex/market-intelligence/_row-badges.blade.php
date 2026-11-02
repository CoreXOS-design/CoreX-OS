{{-- mic-work-tab.md 13.4: the small flags after the price on a List row. Same facts as the ribbons on the Adverts card (one source: the file record $adv). Included by _listing-row. --}}
@if(!empty($adv))
    @php
        $bd = [];
        if (!empty($adv['is_reduced'])) {
            $bd[] = ['Reduced', 'var(--ds-crimson, #dc2626)', !empty($adv['old_price']) ? 'Was R ' . number_format($adv['old_price']) . ' - cut R ' . number_format((int) ($adv['cut_amount'] ?? 0)) : 'Reduced on the portal'];
        }
        if (($adv['portal_status'] ?? null) === 'under_offer') { $bd[] = ['Under offer', 'var(--ds-amber, #f59e0b)', 'The portal shows this advert as under offer']; }
        if (($adv['portal_status'] ?? null) === 'sold') { $bd[] = ['Sold', 'var(--text-secondary)', 'The portal shows this advert as sold']; }
        if (!empty($adv['is_owner'])) { $bd[] = ['Owner selling', 'var(--brand-icon, #0ea5e9)', 'Advertised by the owner']; }
        if ($mandate = \App\Services\Prospecting\MicWork\SoleMandateLabel::for($adv)) { $bd[] = [$mandate['text'], $mandate['ending'] ? '#b45309' : '#7c3aed', $mandate['title']]; }
        if (($adv['details']['level'] ?? 'search') === 'full') { $bd[] = ['✓ Full', 'var(--ds-green, #059669)', 'Full details read' . (!empty($adv['details']['read_on']) ? ' ' . $adv['details']['read_on'] : '') . (!empty($adv['details']['stale']) ? ' - the advert has changed since' : '')]; }
        if ((int) ($adv['agencies_total'] ?? 0) >= 2) { $bd[] = ['Listed by ' . (int) $adv['agencies_total'] . ' agencies', 'var(--text-secondary)', 'More than one agency has this property listed']; }
    @endphp
    @foreach($bd as [$bt, $bc, $btitle])<span data-qa="row-badge" title="{{ $btitle }}" style="font-size: .625rem; font-weight: 700; line-height: 1.4; padding: 0 5px; border: 1px solid {{ $bc }}; border-radius: 8px; color: {{ $bc }}; white-space: nowrap;">{{ $bt }}</span>@endforeach
@endif
