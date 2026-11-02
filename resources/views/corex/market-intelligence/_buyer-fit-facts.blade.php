{{-- mic-work-tab.md 13.18 (rework 2, then 3: fits one row pair in a 236px column - icon + figure only for erf/floor, 3px cell padding, "Jan" for "Jan wants" when the column is narrow, chips wrap onto their own line): the facts line and the buyer's wants line as ONE two-row grid, one column per fact (beds, baths, garages, parking, erf, floor).
     Row 1 = the property's figure (the facts line, unchanged look); row 2 = the buyer's figure directly under it, coloured by state ("–" grey when not stated). The filled "Jan wants" pill is
     a narrow first column on row 2 (row 1 has nothing there); row 2 carries a faint tint of the buyer colour across the column; the buyer's must-haves / deal-breakers trail as chips.
     A column is as wide as the wider of its two cells, so nothing shifts. Inputs: $fit (buyer_fit), $factKeys, $factsMt; reads $f / $adv / $fc / $fcv from the card. Nothing is judged here. --}}
@php
    $B = \App\Services\Prospecting\MicWork\BuyerFitLabel::class;
    $row = $B::wantsRow($fit);
    $first = $fit['first_name'] ?? 'Buyer';
    $has = fn (string $k) => match ($k) {
        'beds' => !empty($f['beds']), 'baths' => !empty($f['baths']), 'garages' => !empty($f['garages']),
        'parking' => !empty($adv['parking']), 'erf' => !empty($adv['erf_size_m2']), 'floor' => !empty($adv['floor_size_m2']), default => false,
    };
    $cols = array_values(array_filter($factKeys, fn ($k) => $has($k) || isset($row['figures'][$k])));
    $tint = 'background: color-mix(in srgb, var(--brand-icon) 13%, transparent);';
    $cell = 'min-height: 1.5rem; display:flex; align-items:center; white-space:nowrap;';
@endphp
<div data-qa="advert-icons" class="mi-fit-grid" style="display:flex; flex-wrap:wrap; align-items:stretch; margin-top:{{ $factsMt }}; color: var(--text-primary);">
    <div style="display:flex; flex-direction:column;">
        <div class="mi-fc" style="{{ $cell }} padding-left: 0 !important;" aria-hidden="true"></div>
        <div class="mi-fc" style="{{ $cell }} {{ $tint }} border-radius: 4px 0 0 4px; padding-left: 3px !important;"><span data-qa="fit-pill" title="{{ $first }} wants" style="font-size:.6875rem; font-weight:700; line-height:1.5; padding:0 6px; border-radius:9px; color:#fff; background: color-mix(in srgb, var(--brand-icon) 65%, black);">{{ $first }}<span class="mi-wants-word"> wants</span></span></div>
    </div>
    @foreach($cols as $k)
        @php $g = $row['figures'][$k] ?? null; @endphp
        <div style="display:flex; flex-direction:column; align-items:stretch;" data-col="{{ $k }}">
            <div class="mi-fc" style="{{ $cell }}">@include('corex.market-intelligence._advert-fact', ['k' => $k, 'compact' => true])</div>
            <div class="mi-fc" style="{{ $cell }} {{ $tint }}" data-qa="fit-fig-{{ $k }}">
                @if($g)<span data-state="{{ $g['state'] }}" title="{{ $g['title'] }}" style="font-weight:700; color: {{ $g['color'] }};">{{ $g['text'] }}</span>@else<span style="color: var(--text-secondary);">–</span>@endif
            </div>
        </div>
    @endforeach
    @if($row['chips'])
        <div class="mi-fc" style="{{ $cell }} {{ $tint }} border-radius: 0 4px 4px 0; align-self:flex-end; flex: 1 1 auto; flex-wrap: wrap; gap: 3px 6px; white-space: normal;" data-qa="fit-chips">
            @foreach($row['chips'] as $c)
                <span data-qa="fit-chip" data-state="{{ $c['state'] }}" title="{{ $c['title'] }}" style="font-size:.6875rem; font-weight:700; line-height:1.4; padding:0 6px; border:1px solid currentColor; border-radius:8px; white-space:nowrap; color: {{ $c['color'] }};">{{ $c['text'] }}</span>
            @endforeach
        </div>
    @else
        <div class="mi-fc" style="{{ $cell }} {{ $tint }} border-radius: 0 4px 4px 0; align-self:flex-end; flex: 1 1 0; min-width: 0;" data-qa="fit-chips" aria-hidden="true"></div>
    @endif
</div>
