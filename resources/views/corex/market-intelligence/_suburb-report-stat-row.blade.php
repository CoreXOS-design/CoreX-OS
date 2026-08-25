{{-- Big-number stat row — for "N vs M" comparisons a seller reads at a
     glance (stock vs sold vs under offer, buyer demand vs stock, CMA vs
     CoreX). Plain HTML/CSS, no JS, dompdf-safe.

     Props:
       stats — list of ['label' => string, 'value' => string, 'color' => hex (optional), 'sub' => string (optional)] --}}
<div style="display:flex; gap:1.4rem; flex-wrap:wrap;">
    @foreach($stats as $s)
    <div style="flex:1; min-width:6.5rem;">
        <div style="font-size:1.7rem; font-weight:700; color:{{ $s['color'] ?? '#1b2a2c' }}; line-height:1.1;">{{ $s['value'] }}</div>
        <div style="font-size:0.78rem; color:#4a5555; margin-top:0.2rem;">{{ $s['label'] }}</div>
        @if(!empty($s['sub']))
        <div style="font-size:0.72rem; color:#8a9697; margin-top:0.1rem;">{{ $s['sub'] }}</div>
        @endif
    </div>
    @endforeach
</div>
