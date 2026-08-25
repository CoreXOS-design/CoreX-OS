{{-- Horizontal bar chart — plain HTML/CSS, no SVG, no JS. Deliberately built
     this way so the exact same markup renders identically on screen AND
     inside the print/PDF view (dompdf cannot execute JS or reliably scale
     SVG viewBoxes, but it renders a div with an inline-style width fine).

     Props:
       bars  — list of ['label' => string, 'value' => number, 'display' => string]
       color — literal hex/rgb string (never a CSS var — the print view has
               no access to the app's token stylesheet)
       track — literal hex/rgb for the empty-bar track background --}}
@php
    $color = $color ?? '#0d6e68';
    $track = $track ?? '#e7e2d3';
    $max = collect($bars)->max('value') ?: 1;
@endphp
<div style="display:flex; flex-direction:column; gap:0.55rem;">
    @foreach($bars as $b)
    @php $pct = $max > 0 ? max(2, round(($b['value'] / $max) * 100)) : 0; @endphp
    <div style="display:flex; align-items:center; gap:0.6rem;">
        <div style="flex:0 0 34%; font-size:0.8rem; color:#4a5555;">{{ $b['label'] }}</div>
        <div style="flex:1; background:{{ $track }}; border-radius:4px; height:16px; position:relative;">
            <div style="width:{{ $pct }}%; background:{{ $color }}; height:100%; border-radius:4px;"></div>
        </div>
        <div style="flex:0 0 auto; font-size:0.8rem; font-weight:600; color:#1b2a2c; min-width:5.5rem; text-align:right;">{{ $b['display'] }}</div>
    </div>
    @endforeach
</div>
