<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Road to Sold report - {{ $period->label }}</title>
    <style>
        @page { size: A4 landscape; margin: 12mm; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 8px; color: #1f2937; margin: 0; }
        h1 { font-size: 14px; margin: 0 0 2px 0; }
        .meta { color: #6b7280; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 3px 4px; text-align: right; }
        th { background: #f3f4f6; }
        th:first-child, td:first-child { text-align: left; }
        tfoot td { font-weight: bold; background: #f9fafb; }
        .prev { color: #6b7280; font-size: 7px; }
        .noprint { margin: 8px 0; }
        @media print { .noprint { display: none; } }
    </style>
</head>
<body @if(! $forPdf) onload="window.print()" @endif>
    <h1>Road to Sold report</h1>
    <div class="meta">
        {{ $period->label }}
        @if($compared) · compared with {{ $compare->label }} @endif
        · generated {{ $generatedAt->format('j M Y H:i') }}
    </div>
    <table>
        <thead>
            <tr>
                <th>Agent</th>
                @foreach($columns as $c)<th>{{ $c['label'] }}</th>@endforeach
            </tr>
        </thead>
        <tbody>
        @forelse($rows as $r)
            <tr>
                <td>{{ $r['name'] }}</td>
                @foreach($columns as $c)
                    <td>{{ number_format($r['cells'][$c['key']]['value']) }}@if($compared)<br><span class="prev">was {{ number_format((int) $r['cells'][$c['key']]['previous']) }}</span>@endif</td>
                @endforeach
            </tr>
        @empty
            <tr><td colspan="{{ count($columns) + 1 }}" style="text-align:center;">No agents to report on.</td></tr>
        @endforelse
        </tbody>
        @if(count($rows) > 1)
        <tfoot>
            <tr>
                <td>Total</td>
                @foreach($columns as $c)
                    <td>{{ number_format($totals['cells'][$c['key']]['value']) }}@if($compared)<br><span class="prev">was {{ number_format((int) $totals['cells'][$c['key']]['previous']) }}</span>@endif</td>
                @endforeach
            </tr>
        </tfoot>
        @endif
    </table>
</body>
</html>
