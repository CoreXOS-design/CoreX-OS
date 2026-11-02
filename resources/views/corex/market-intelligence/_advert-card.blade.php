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
    // 13.18 Buyer wants vs property has: only when a buyer is selected (the row then carries buyer_fit); no buyer, nothing changes
    $fit = !empty($adv['buyer_fit']) ? $adv['buyer_fit'] : null;
    $fitChips = $fit && (count($fit['must_haves'] ?? []) + count($fit['deal_breakers'] ?? [])) > 0;
    $fitChip = fn (string $key) => $fit ? \App\Services\Prospecting\MicWork\BuyerFitLabel::chip($fit, $key) : null;
    $chipStyle = 'font-size:.6875rem; font-weight:700; line-height:1.4; padding:0 6px; border:1px solid currentColor; border-radius:8px; white-space:nowrap; vertical-align:middle;';
    $ownerSale = !empty($adv['is_owner']);
    $status = $adv['portal_status'] ?? null;
    // ribbons on the photo, in the spec's order
    $ribbons = [];
    if (!empty($adv['is_reduced'])) { $ribbons[] = ['Reduced', 'var(--ds-crimson, #dc2626)', null]; }
    if ($status === 'under_offer') { $ribbons[] = ['Under offer', 'var(--ds-amber, #f59e0b)', null]; }
    if ($status === 'sold') { $ribbons[] = ['Sold', '#475569', null]; }
    if ($ownerSale) { $ribbons[] = ['Owner selling', 'var(--brand-icon, #0ea5e9)', null]; }
    if ($mandate = \App\Services\Prospecting\MicWork\SoleMandateLabel::for($adv)) { $ribbons[] = [$mandate['text'], $mandate['ending'] ? 'var(--ds-amber, #f59e0b)' : '#7c3aed', $mandate['title']]; }
    // the photo's P24 / PP marks are the links to the adverts themselves (the live one of that portal first)
    $portalAdvert = function (string $portal) use ($adverts) {
        $mine = array_values(array_filter($adverts, fn ($a) => $a['portal'] === $portal && !empty($a['url'])));
        usort($mine, fn ($a, $b) => (int) $b['active'] <=> (int) $a['active']);

        return $mine[0] ?? null;
    };
    $p24Ad = $portalAdvert('P24');
    $ppAd = $portalAdvert('PP');
    $p24Url = $p24Ad['url'] ?? null;
    $ppUrl = $ppAd['url'] ?? null;
    // a mark whose advert is no longer seen is greyed (still a working link) and says since when
    $markOff = 'background: rgba(100,116,139,.85); text-decoration: line-through;';
    // dates (13.3a): both when held, and the time on market by the section 0 rule
    $dateBits = [];
    if (!empty($adv['listed_date'])) { $dateBits[] = 'Listed ' . \Carbon\Carbon::parse($adv['listed_date'])->format('j M'); }
    if (!empty($adv['first_seen'])) { $dateBits[] = 'First seen ' . \Carbon\Carbon::parse($adv['first_seen'])->format('j M'); }
    $domLabel = (string) ($adv['dom']['label'] ?? '-');
    if ($domLabel !== '-' && $domLabel !== '') { $dateBits[] = $domLabel === 'new' ? 'new' : (str_contains($domLabel, '~') || str_ends_with($domLabel, '+') ? $domLabel : '~' . $domLabel) . ' days'; }
    $who = $ownerSale ? 'Owner (private)' : ($adv['agencies'][0] ?? null);

    $muted = 'color: var(--text-secondary);';
    $markStyle = 'padding:1px 6px; font-size:.6875rem; font-weight:700; background: rgba(15,23,42,.82); color:#fff; border-radius:3px; text-decoration:none; line-height:1.4;';
@endphp

<article class="mi-advert" data-qa="advert-card" data-listing-id="{{ $listing->id }}">

    {{-- Column 1: the photo (4:3). The marks on it are links. --}}
    <div class="mi-advert-photo" data-qa="advert-photo">
        @if(!empty($adv['portal_url']))<a href="{{ $adv['portal_url'] }}" target="_blank" rel="noopener" data-qa="advert-photo-link" title="Open this advert on the portal" style="display:block; width:100%; height:100%;">@endif
            <img src="{{ $thumbSrc ?: $placeholder }}" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src='{{ $placeholder }}';" style="width:100%; height:100%; object-fit:cover; display:block; border-radius:6px; background: var(--surface-2);">
        @if(!empty($adv['portal_url']))</a>@endif
        @include('corex.market-intelligence._advert-gallery')
        <div style="position:absolute; top:8px; left:8px; display:flex; flex-direction:column; align-items:flex-start; gap:4px; pointer-events:none;">
            @foreach($ribbons as [$rt, $rc, $rtitle])
                <span data-qa="ribbon" title="{{ $rtitle ?? $rt }}" style="padding:2px 8px; font-size:.6875rem; font-weight:700; color: {{ str_contains($rc, 'amber') ? '#111827' : '#fff' }}; background: {{ $rc }}; border-radius:3px; box-shadow:0 1px 3px rgba(0,0,0,.3);">{{ $rt }}</span>
            @endforeach
        </div>
        @if((int) ($adv['agencies_total'] ?? 0) >= 2)
            <div style="position:absolute; bottom:8px; left:8px;" data-qa="listed-by" x-data="miSplit({{ $listing->id }}, {{ (int) $adv['agencies_total'] }}, @js(collect($adv['adverts'] ?? [])->pluck('id')->filter()->values()->all()), '{{ url('/corex/market-intelligence/adverts') }}', '{{ route('market-intelligence.opportunities.show', ['tp' => '__ID__']) }}')" x-show="n >= 2 || note" @click.outside="disarm()">
                <x-mic-pop align="left" content-classes="p-1">
                    <x-slot name="trigger"><button type="button" title="Every advert on this property" style="{{ $markStyle }} cursor:pointer; border:none;" x-text="'Listed by ' + n + ' agencies'">Listed by {{ (int) $adv['agencies_total'] }} agencies</button></x-slot>
                    <x-slot name="content">@include('corex.market-intelligence._advert-pop', ['kind' => 'adverts', 'split' => true])</x-slot>
                </x-mic-pop>
            </div>
        @endif
        <div style="position:absolute; bottom:8px; right:8px; display:flex; gap:4px; align-items:center;" data-qa="photo-marks">
            @if(!empty($adv['p24']))
                @if($p24Url)<a href="{{ $p24Url }}" target="_blank" rel="noopener" data-qa="mark-p24" title="{{ !empty($p24Ad['active']) ? 'Open the P24 advert' : 'Not seen since ' . ($p24Ad['last_seen'] ?? '-') . ' - open the P24 advert anyway' }}" style="{{ $markStyle }} {{ !empty($p24Ad['active']) ? '' : $markOff }}">P24</a>@else<span data-qa="mark-p24" style="{{ $markStyle }}">P24</span>@endif
            @endif
            @if(!empty($adv['pp']))
                @if($ppUrl)<a href="{{ $ppUrl }}" target="_blank" rel="noopener" data-qa="mark-pp" title="{{ !empty($ppAd['active']) ? 'Open the PP advert' : 'Not seen since ' . ($ppAd['last_seen'] ?? '-') . ' - open the PP advert anyway' }}" style="{{ $markStyle }} {{ !empty($ppAd['active']) ? '' : $markOff }}">PP</a>@else<span data-qa="mark-pp" style="{{ $markStyle }}">PP</span>@endif
            @endif
            @if(!empty($adv['alert']))
                <x-mic-pop align="right" content-classes="p-1">
                    <x-slot name="trigger"><button type="button" data-qa="envelope" title="{{ $adv['alert_tip'] ?? 'In a P24 alert email' }} - open the alert details" style="{{ $markStyle }} cursor:pointer; border:none; display:inline-flex;"><svg viewBox="0 0 16 12" width="14" height="10" fill="none" stroke="currentColor" stroke-width="1.4"><rect x="1" y="1" width="14" height="10" rx="1.5"/><path d="M1.5 2.2 8 7l6.5-4.8"/></svg></button></x-slot>
                    <x-slot name="content">@include('corex.market-intelligence._advert-pop', ['kind' => 'alerts'])</x-slot>
                </x-mic-pop>
            @endif
        </div>
    </div>

    {{-- Column 2: the advert's text, sized like a P24 result card --}}
    <div class="mi-advert-body {{ $fit ? 'mi-advert-body-fit' : '' }}" data-qa="advert-left" x-data="{ more: false }" :class="more ? 'mi-advert-body-open' : ''">
        <div style="display:flex; align-items:baseline; gap:8px; flex-wrap:wrap;" data-qa="advert-price">
            <span style="font-size:1.5rem; line-height:1.2; font-weight:800; color: var(--text-primary);">{{ !empty($adv['price']) ? $money($adv['price']) : '—' }}</span>
            @if($pc = $fitChip('price'))<span data-qa="fit-chip-price" title="{{ $pc['title'] }}" style="{{ $chipStyle }} color: {{ $pc['color'] }};">{{ $pc['text'] }}</span>@endif
            @if(!empty($adv['old_price']) && !empty($adv['cut_amount']))
                <span style="font-size:.9375rem; color: var(--text-secondary); text-decoration: line-through;">{{ $money($adv['old_price']) }}</span>
                <span style="font-size:.8125rem; font-weight:600; color: var(--ds-crimson, #dc2626);">cut {{ $money($adv['cut_amount']) }}{{ !empty($adv['cut_date']) ? ' · ' . \Carbon\Carbon::parse($adv['cut_date'])->format('j M') : '' }}</span>
            @endif
        </div>
        <div style="font-size:1rem; line-height:1.3; font-weight:600; color: var(--text-primary);" data-qa="advert-heading">{{ $heading }}@if($tc = $fitChip('type')) <span data-qa="fit-chip-type" title="{{ $tc['title'] }}" style="{{ $chipStyle }} color: {{ $tc['color'] }};">{{ $tc['text'] }}</span>@endif</div>
        <div style="font-size:.875rem; line-height:1.3; font-weight:700; color: var(--text-primary);" data-qa="advert-place">{{ $adv['suburb'] ?? $listing->suburb }}@if($hasAddress) · {{ \Illuminate\Support\Str::limit($displayAddress, 80) }}@endif</div>

        @php
            // No wording captured: the listing page's feature rows and rates / levies fill the description space (nothing when there are none).
            $skipKey = '/address|listing (date|number|ref)|erf|floor|bedroom|bathroom|garage|parking|rates|levies|agent|agency/i';
            $featBits = [];
            foreach ((is_array($adv['features'] ?? null) ? $adv['features'] : []) as $fk => $fv) {
                $fk = trim((string) $fk); $fvs = trim(is_scalar($fv) ? (string) $fv : '');
                if ($fk === '' || preg_match($skipKey, $fk) || $fvs === '' || in_array(mb_strtolower($fvs), ['no', '0', 'false', 'none'], true)) { continue; }
                $featBits[] = in_array(mb_strtolower($fvs), ['yes', '1', 'true'], true) ? $fk : $fk . ' ' . $fvs;
            }
            $featBits = array_slice($featBits, 0, 8);
            $costBits = array_values(array_filter([!empty($adv['rates_amount']) ? 'Rates ' . $money($adv['rates_amount']) . ' pm' : null, !empty($adv['levies_amount']) ? 'Levies ' . $money($adv['levies_amount']) . ' pm' : null]));
        @endphp
        @if($text !== '')
            <div style="font-size:.875rem; line-height:1.4; color: var(--text-primary);" data-qa="advert-description">
                <div :style="more ? '' : 'display:-webkit-box; -webkit-line-clamp:{{ $fitChips ? 3 : 4 }}; -webkit-box-orient:vertical; overflow:hidden;'">{{ $text }}</div>
                @if(mb_strlen($text) > 160)
                    @if(($adv['details']['level'] ?? 'search') === 'full')
                    <button type="button" data-advert-open data-qa="read-more" style="background:none; border:none; padding:0; font-size:.8125rem; color: var(--text-primary); text-decoration: underline; text-decoration-color: var(--brand-icon); cursor:pointer;">Read more</button>
                    @else
                    <button type="button" @click.stop="more = !more" style="background:none; border:none; padding:0; font-size:.8125rem; color: var(--text-primary); text-decoration: underline; text-decoration-color: var(--brand-icon); cursor:pointer;"><span x-text="more ? 'less' : 'more'">more</span></button>
                    @endif
                @endif
            </div>
        @endif
        @if($text === '' && ($featBits || $costBits))
            <div style="font-size:.875rem; line-height:1.4; color: var(--text-primary);" data-qa="advert-features">
                @if($featBits)<div>{{ implode(' · ', $featBits) }}</div>@endif
                @if($costBits)<div>{{ implode(' · ', $costBits) }}</div>@endif
            </div>
        @endif

        @if($fitChips)@include('corex.market-intelligence._buyer-fit-chips', ['fit' => $fit])@endif

        <div style="display:flex; align-items:center; gap:2px 14px; margin-top:{{ ($text !== '' || $featBits || $costBits || $fitChips) ? 'auto' : '4px' }}; font-size:.875rem; color: var(--text-primary);" data-qa="advert-icons">
            @if(!empty($f['beds']))<span title="Bedrooms" style="display:inline-flex; align-items:center; gap:4px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px; height:15px; color: var(--text-secondary);"><path d="M3 18v-7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v7M3 14h18M3 18v2M21 18v2M7 9V6h10v3"/></svg>{{ $f['beds'] }}</span>@endif
            @if(!empty($f['baths']))<span title="Bathrooms" style="display:inline-flex; align-items:center; gap:4px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px; height:15px; color: var(--text-secondary);"><path d="M4 12h16v3a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4v-3zM6 12V6a2 2 0 0 1 4 0M7 19l-1 2M17 19l1 2"/></svg>{{ $f['baths'] }}</span>@endif
            @if(!empty($f['garages']))<span title="Garages" style="display:inline-flex; align-items:center; gap:4px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px; height:15px; color: var(--text-secondary);"><path d="M5 17h14M6 17l1.5-6h9L18 17M7 17v2M17 17v2"/></svg>{{ $f['garages'] }}</span>@endif
            @if(!empty($adv['parking']))<span title="Parking bays" style="display:inline-flex; align-items:center; gap:4px;"><span style="font-weight:800; color: var(--text-secondary);">P</span>{{ $adv['parking'] }}</span>@endif
            @if(!empty($adv['erf_size_m2']))<span title="Erf size">Erf {{ number_format((float) $adv['erf_size_m2']) }} m²</span>@endif
            @if(!empty($adv['floor_size_m2']))<span title="Floor size">Floor {{ number_format((float) $adv['floor_size_m2']) }} m²</span>@endif
        </div>
        @if($fit)@include('corex.market-intelligence._buyer-fit-line', ['fit' => $fit])@endif
        <div style="font-size:.8125rem; line-height:1.3; color: var(--text-primary); display:flex; align-items:baseline; gap:6px; min-width:0;" data-qa="advert-source">
            <span style="min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ implode(' · ', array_filter([$who, $adv['agent_name'] ?? null])) }}@if($lead && !empty($lead['ref']))@if($who || !empty($adv['agent_name'])) · @endif<span style="{{ $muted }}">{{ $lead['ref'] }}</span>@endif</span>
            @include('corex.market-intelligence._advert-fetch')
        </div>
        <div style="font-size:.8125rem; line-height:1.3; color: var(--text-secondary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" data-qa="advert-dates">
            {{ implode(' · ', $dateBits) }}@if(!empty($adv['is_stale']) && $adv['last_seen_days'] !== null)<span style="{{ $muted }}" data-qa="advert-stale"> · not seen since {{ $adv['last_seen_days'] }} d</span>@endif
        </div>
    </div>

    @include('corex.market-intelligence._advert-intel')
</article>

<style>
    /* Three columns - photo | the advert's text | our intelligence - and the row is as tall as the photo (4:3, 300 x 225). Below 64rem they stack: nothing here assumes
       desktop width (mic-work-tab.md 13.10). The popovers must not be clipped, so the stack's overflow is released in this view. */
    .mi-row-stack:has(.mi-advert) { overflow: visible !important; }
    .mi-advert { display: grid; grid-template-columns: 18.75rem minmax(0, 1fr) minmax(24rem, 28rem); gap: 16px; padding: 12px 14px; align-items: start; }
    .mi-advert-photo { position: relative; width: 18.75rem; max-width: 100%; aspect-ratio: 4 / 3; }
    .mi-advert-body { display: flex; flex-direction: column; gap: 3px; min-width: 0; height: 14.0625rem; overflow: hidden; }
    .mi-advert-body.mi-advert-body-fit { height: calc(14.0625rem + 1.5rem); }
    .mi-advert-body.mi-advert-body-open { height: auto; min-height: 14.0625rem; overflow: visible; }
    [data-qa="row-wrap"] + [data-qa="row-wrap"] > .mi-advert { border-top: 1px solid var(--border); }
    @media (max-width: 64rem) {
        .mi-advert { grid-template-columns: minmax(0, 1fr); }
        .mi-advert-photo { width: 100%; }
        .mi-advert-body { height: auto; }
    }
</style>
