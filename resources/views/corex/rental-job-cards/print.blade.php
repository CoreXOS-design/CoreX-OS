<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    {{-- AT-442 req #6 — printable job card: address, access notes, tenant
         contact, tasks with tick boxes, parts/labour list, sign-off lines.
         Same dompdf A4 convention as corex.rental-work-orders.pdf. --}}
    @php $fontDir = str_replace('\\', '/', base_path('resources/fonts/inter')); @endphp
    <style>
        @font-face { font-family:'Inter'; font-weight:400; font-style:normal; src:url('{{ $fontDir }}/Inter-400.ttf') format('truetype'); }
        @font-face { font-family:'Inter'; font-weight:500; font-style:normal; src:url('{{ $fontDir }}/Inter-500.ttf') format('truetype'); }
        @font-face { font-family:'Inter'; font-weight:600; font-style:normal; src:url('{{ $fontDir }}/Inter-600.ttf') format('truetype'); }
        @font-face { font-family:'Inter'; font-weight:700; font-style:normal; src:url('{{ $fontDir }}/Inter-700.ttf') format('truetype'); }
        @page { margin: 24px 32px; }
        /* §14.22 — NO margin/padding reset here: dompdf lets the html/body box win over
           @page, so "margin:0" silently removed the 24px/32px page margins and the header
           and wide tables ran to / past the paper edge. */
        html, body { background: #ffffff; color: #0b2a4a; }
        * { box-sizing: border-box; }
        body { font-family: 'Inter', 'DejaVu Sans', sans-serif; font-size: 11px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        h2 { font-size: 12px; margin: 16px 0 6px; text-transform: uppercase; letter-spacing: .04em; color: #4b5563; }
        p { margin: 0 0 4px; }
        p, td, th { word-wrap: break-word; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 3px 0; vertical-align: top; }
        td.label { width: 160px; color: #4b5563; }
        .header { display: table; width: 100%; margin-bottom: 18px; }
        .header .logo-cell { display: table-cell; vertical-align: middle; }
        .header .title-cell { display: table-cell; vertical-align: middle; text-align: right; }
        .box { border: 1px solid #d1d5db; border-radius: 4px; padding: 10px 12px; margin-bottom: 10px; }
        .note { white-space: pre-wrap; }
        .muted { color: #6b7280; }
        .lines td, .lines th { border-bottom: 1px solid #e5e7eb; padding: 4px 6px; text-align: left; }
        .lines th { color: #4b5563; font-weight: 600; }
        .total-row td { font-weight: 700; border-bottom: none; }
        /* §14.23 — the grand-totals box is never split across a page break (some rows on one page, the grand total on the next); a line is never split mid-row; the lines header repeats on page 2+ (thead). */
        .totals-box { page-break-inside: avoid; }
        .lines tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        .checkbox { display: inline-block; width: 10px; height: 10px; border: 1px solid #0b2a4a; margin-right: 6px; }
        .task-row { padding: 3px 0; }
        .signoff { display: table; width: 100%; margin-top: 8px; }
        .signoff .cell { display: table-cell; width: 33%; padding-right: 12px; }
        .signoff .line { border-bottom: 1px solid #0b2a4a; height: 28px; }
        .signoff .cap { color: #6b7280; margin-top: 2px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo-cell">
            @if($logo)
                <img src="{{ $logo }}" alt="" style="height:48px;max-width:280px;">
            @else
                <span style="font-weight:700;font-size:18px;">{{ $agencyName }}</span>
            @endif
        </div>
        <div class="title-cell">
            <h1>Job Card</h1>
            <p class="muted">#{{ $jobCard->id }} &middot; {{ $jobCard->created_at?->format('Y-m-d') }}</p>
        </div>
    </div>

    <div class="box">
        <table>
            <tr><td class="label">Property</td><td>{{ $jobCard->property?->buildDisplayAddress() ?? '—' }}</td></tr>
            <tr><td class="label">Tenant contact</td><td>{{ $jobCard->lease?->tenantNames() ?? '—' }}</td></tr>
            <tr><td class="label">Access notes</td><td class="note">{{ $jobCard->access_notes ?? '—' }}</td></tr>
            <tr><td class="label">Crew</td><td>{{ $jobCard->crew?->name ?? ($jobCard->assigned_user_id ? 'Previously assigned: ' . ($jobCard->assignedUser?->name ?? '—') : '—') }}</td></tr>
            @if($jobCard->crew && $jobCard->crew->members->isNotEmpty())
            <tr><td class="label">Crew members</td><td>{{ $jobCard->crew->members->pluck('name')->implode(', ') }}</td></tr>
            @endif
            <tr><td class="label">Scheduled</td><td>{{ $jobCard->scheduled_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
            <tr><td class="label">Due</td><td>{{ $jobCard->due_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
        </table>
    </div>

    <h2>Job</h2>
    <div class="box"><p><strong>{{ $jobCard->title }}</strong></p></div>

    @include('corex.rental-job-cards._pdf-source')

    {{-- 2026-10-05 rebuild (req #6) — tasks and their own parts & labour
         lines, same layout the show screen uses, not two separate sections. --}}
    <h2>Tasks</h2>
    @forelse($jobCard->tasks as $task)
        <div class="box">
            <p class="task-row"><span class="checkbox"></span><strong>{{ $loop->iteration }} - {{ $task->description }}</strong></p>
            @include('corex.rental-job-cards._pdf-cost-lines-table', ['lines' => $task->lines, 'costsOn' => $costsOn])
        </div>
    @empty
        <div class="box"><p class="muted">No tasks listed.</p></div>
    @endforelse

    @php $generalLines = $jobCard->lines->whereNull('rental_job_card_task_id'); @endphp
    <h2>General</h2>
    <div class="box">
        @include('corex.rental-job-cards._pdf-cost-lines-table', ['lines' => $generalLines, 'costsOn' => $costsOn])
    </div>

    {{-- §17.4.7 — the worker copy shows the COST total only (VAT on cost is Build 1); never the selling total. --}}
    @if($costsOn)
    <div class="box totals-box">
        <table>
            <tr class="total-row">
                <td colspan="2">Total cost</td>
                <td>R{{ number_format((float) $jobCard->lines->sum('cost_total'), 2) }}</td>
            </tr>
        </table>
    </div>
    @endif

    @if(!empty($crewLinkQr))
    <div class="box" style="display: table; width: 100%;">
        <div style="display: table-cell; width: 120px; vertical-align: middle;"><img src="{{ $crewLinkQr }}" alt="" style="width: 110px; height: 110px;"></div>
        <div style="display: table-cell; vertical-align: middle;">
            <p><strong>Open this job on your phone</strong></p>
            <p class="muted">Scan the code to see the tasks, add photos and mark the work completed.@if(!empty($crewLinkExpires)) Works until {{ $crewLinkExpires }}, or until the job is closed.@endif Keep this paper private — the code is the key.</p>
        </div>
    </div>
    @endif

    <h2>Sign-off</h2>
    <div class="box">
        <div class="signoff">
            <div class="cell"><div class="line"></div><p class="cap">Worker — done</p></div>
            <div class="cell"><div class="line"></div><p class="cap">Agent — checked</p></div>
            <div class="cell"><div class="line"></div><p class="cap">Tenant — confirmed fixed</p></div>
        </div>
    </div>
</body>
</html>
