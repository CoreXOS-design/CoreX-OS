<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Daily Activity Print Sheet</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        /* Simple, dependency-free printable layout */
        @page { size: A4 portrait; margin: 8mm; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10px;
            color: #111;
        }
        .header{
            display:flex;
            justify-content:space-between;
            gap:10px;
            align-items:center;
            border-bottom:2px solid #111;
            padding-bottom:4px;
            margin-bottom:6px;
        }
        .title{font-size:12px;font-weight:700;margin:0;}
        .meta {
            line-height: 1.45;
        }
        .meta strong { font-weight: 700; }
        .right-meta {
            text-align: right;
            line-height: 1.45;
            white-space: nowrap;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            border: 1px solid #111;
            padding: 5px 6px;
            vertical-align: middle;
        }
        th {
            background: #f2f2f2;
            text-align: left;
            font-weight: 700;
        }

        .col-activity { width: 60%; }
        .col-weight   { width: 10%; text-align: center; }
        .col-boxes    { width: 30%; }

        .box-row {
            display: flex;
            gap: 6px;
            align-items: center;
            justify-content: flex-start;
        }

        /* Once: single tick box */
        .tick {
            width: 13px;
            height: 13px;
            border: 2px solid #111;
            display: inline-block;
        }

        /* Count: a row of write boxes */
        .write-box {
            width: 18px;
            height: 14px;
            border: 2px solid #111;
            display: inline-block;
        }

        .hint {
            font-size: 10px;
            color: #333;
            margin-top: 8px;
        }

        .footer {
            margin-top: 14px;
            font-size: 10px;
            color: #333;
        }

        @media print {
            a { text-decoration: none; color: inherit; }
            tr { page-break-inside: avoid; }
        }
    
        /* Best-effort: if the table is long, reduce overall scale slightly */
        .shrink-if-needed { transform-origin: top left; }
        @media print {
            .shrink-if-needed { transform: scale(0.95); }
        }
    
        .meta-line{
            font-size:10px;
            line-height:1.2;
            white-space:nowrap;
        }
        .meta-line span{margin-right:10px;}
        .meta-line strong{font-weight:700;}
        .hint{display:none;}
    </style>
</head>
<body>

<div class="shrink-if-needed">

<div class="header">
    <div class="title">Daily Activity Print Sheet</div>
    <div class="meta-line">
        <span><strong>Date:</strong> {{ $selectedDate }}</span>
        <span><strong>Agent:</strong> {{ $user->name ?? ($user->email ?? 'Agent') }}</span>
        <span><strong>Branch:</strong> {{ $branchName ?? ('#' . ($user->branch_id ?? '—')) }}</span>
        <span><strong>Period:</strong> {{ $period }}</span>
        <span><strong>Target:</strong> {{ (int)($monthlyTarget ?? 0) }}</span>
        <span><strong>Remaining:</strong> {{ (int)($remainingPoints ?? 0) }}</span>
    </div>
</div>


<table>
    <thead>
        <tr>
            <th class="col-activity">Activity</th>
            <th class="col-weight">Weight</th>
            <th class="col-boxes">Capture</th>
        </tr>
    </thead>
    <tbody>
        @foreach($definitions as $def)
            <tr>
                <td class="col-activity">
                    <div style="font-weight:700;">{{ $def->label ?? $def->name ?? ('Activity #' . $def->id) }}</div>
                    @if(!empty($def->description))
                        <div style="font-size:10px;color:#333;margin-top:2px;">{{ $def->description }}</div>
                    @endif
                </td>

                <td class="col-weight">{{ (int)($def->weight ?? 0) }}</td>

                <td class="col-boxes">
                    <div class="box-row">
                        @if(($def->scoring_mode ?? 'count') === 'once')
                            <span class="tick"></span>
                        @else
                            {{-- 5 write boxes for count capture --}}
                            <span class="write-box"></span>
                            <span class="write-box"></span>
                            <span class="write-box"></span>
                            <span class="write-box"></span>
                            <span class="write-box"></span>
                        @endif
                    </div>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="footer">
    Printed from Agent Targets — Daily Activity
</div>
</div>

<script>
window.onload = () => setTimeout(() => window.print(), 300);
</script>

</body>
</html>
