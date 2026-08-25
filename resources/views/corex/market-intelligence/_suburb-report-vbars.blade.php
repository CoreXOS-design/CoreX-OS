{{-- Vertical bar/column chart — for a time series (price by year). Built as
     a TABLE with pixel heights, not flexbox — a real dompdf limitation
     found while proving this out: a flex column chart broke catastrophically
     across a PDF page boundary (each bar re-rendered stretched to fill a
     fresh page). Tables paginate predictably in dompdf; flexbox does not.
     Same plain-markup, no-JS discipline as _suburb-report-hbars.blade.php.

     Props:
       cols  — list of ['label' => string, 'value' => number, 'display' => string, 'partial' => bool]
       color — literal hex/rgb
       partialColor — literal hex/rgb for a partial-year column (e.g. this year, not yet complete) --}}
@php
    $color = $color ?? '#0d6e68';
    $partialColor = $partialColor ?? '#c9a24a';
    $max = collect($cols)->max('value') ?: 1;
    $maxBarPx = 110;
@endphp
<table style="width:100%; border-collapse:collapse;" cellpadding="0" cellspacing="0">
    <tr>
        @foreach($cols as $c)
        @php $px = $max > 0 ? max(4, (int) round(($c['value'] / $max) * $maxBarPx)) : 4; @endphp
        <td style="text-align:center; vertical-align:bottom; padding:0 0.25rem;">
            <div style="font-size:0.68rem; color:#4a5555; margin-bottom:0.2rem; white-space:nowrap;">{{ $c['display'] }}</div>
            <div style="width:100%; max-width:2.2rem; height:{{ $px }}px; background:{{ ($c['partial'] ?? false) ? $partialColor : $color }}; border-radius:3px 3px 0 0; margin:0 auto;"></div>
        </td>
        @endforeach
    </tr>
    <tr>
        @foreach($cols as $c)
        <td style="text-align:center; padding:0.3rem 0.25rem 0;">
            <div style="font-size:0.68rem; color:#7a8686;">{{ $c['label'] }}</div>
        </td>
        @endforeach
    </tr>
</table>
