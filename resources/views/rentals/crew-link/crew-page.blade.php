{{--
    .ai/specs/rental-work-orders.md §14.29 — the crew's general page. No login: the
    token in the URL IS the access control, and it IS the crew. Mobile-first, one
    thumb: every tap target >= 56px. Plain arrays only ($page comes from
    RentalCrewScheduleService) — nothing the crew must not see can reach this file.
--}}
@php
    $brand = $page['agency']['colors']['default'] ?? '#0b2a4a';
    $button = $page['agency']['colors']['button'] ?? '#00b4d8';
    $total = count($page['today']) + count($page['upcoming']) + count($page['waiting']) + count($page['in_progress']);
    $jobUrl = fn ($id) => route('rentals.crew-page.job', [$token, $id]);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $crew?->name ?? 'Jobs' }} — jobs</title>
    <style>
        :root { --bg:#f4f6fb; --surface:#fff; --border:rgba(0,0,0,.09); --text:#141821; --muted:#5a6472; --brand:{{ $brand }}; --accent:{{ $button }}; --ok:#1f8a4c; --warn:#b45309; }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--text); font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; -webkit-font-smoothing:antialiased; }
        header.top { background:var(--brand); color:#fff; padding:16px; }
        header.top .agency { display:flex; align-items:center; gap:10px; font-size:13px; opacity:.9; }
        header.top img { height:28px; width:auto; max-width:120px; object-fit:contain; background:#fff; border-radius:6px; padding:2px; }
        header.top h1 { font-size:20px; margin:6px 0 2px; font-weight:800; }
        header.top .date { font-size:14px; opacity:.85; }
        .wrap { max-width: 520px; margin: 0 auto; padding: 14px 14px 56px; }
        h2.sec { font-size:13px; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); margin:20px 4px 8px; font-weight:800; display:flex; justify-content:space-between; align-items:center; }
        h2.sec .n { background:#e8edf2; color:#26435f; border-radius:999px; padding:1px 9px; font-size:12px; letter-spacing:0; }
        .job { display:block; background:var(--surface); border:1px solid var(--border); border-radius:14px; margin-bottom:9px; overflow:hidden; }
        .job a.main { display:block; padding:13px 14px; color:inherit; text-decoration:none; min-height:56px; }
        .job a.main:active { background:#eef3f8; }
        .job .when { font-size:13px; font-weight:800; color:var(--brand); }
        .job .ttl { font-size:16px; font-weight:700; margin:2px 0; line-height:1.3; }
        .job .addr { font-size:14px; color:var(--muted); }
        .job .contacts { border-top:1px solid var(--border); padding:8px 14px 12px; background:#fafbfd; }
        .job .who { font-size:14px; padding:5px 0; display:flex; flex-wrap:wrap; gap:4px 10px; align-items:baseline; }
        .job .who .lbl { font-size:11px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; color:var(--muted); min-width:52px; }
        .job .who .nm { font-weight:600; }
        .job .who a.tel { color:var(--brand); font-weight:700; text-decoration:none; padding:8px 0; }
        .job .note-line { flex-basis:100%; font-size:13px; padding:6px 9px; background:#fff8e6; border:1px solid #f1dfa6; border-radius:8px; }
        .job a.maps { display:flex; align-items:center; justify-content:center; min-height:48px; margin-top:6px; border-radius:10px; background:var(--accent); color:#fff; font-weight:800; font-size:15px; text-decoration:none; }
        .chip { display:inline-block; font-size:11px; font-weight:800; padding:2px 9px; border-radius:999px; background:#eef3f8; color:#26435f; margin-right:4px; }
        .chip.warn { background:#fff1e0; color:var(--warn); }
        .chip.ok { background:#eafaf0; color:var(--ok); }
        .card { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:6px 14px; margin-bottom:9px; }
        .row { padding:11px 0; border-top:1px solid var(--border); }
        .row:first-child { border-top:0; }
        .row .top { display:flex; justify-content:space-between; gap:10px; align-items:baseline; }
        .row .qty { font-weight:800; white-space:nowrap; font-size:16px; }
        .row .desc { font-size:15px; font-weight:600; }
        .muted { color:var(--muted); font-size:13px; }
        details summary { cursor:pointer; list-style:none; min-height:44px; display:flex; align-items:center; }
        details summary::-webkit-details-marker { display:none; }
        details.card > summary { font-weight:800; font-size:14px; justify-content:space-between; }
        .empty { background:var(--surface); border:1px dashed #b7c2cd; border-radius:14px; padding:26px 16px; text-align:center; color:var(--muted); font-size:15px; line-height:1.5; }
        .jobs-list { margin:6px 0 2px; padding:0; list-style:none; font-size:13px; color:var(--muted); }
        .jobs-list li { padding:3px 0; display:flex; justify-content:space-between; gap:10px; }
        .note { font-size:12px; color:var(--muted); margin:8px 4px 0; }
    </style>
</head>
<body>
    <header class="top">
        <div class="agency">
            @if($page['agency']['logoUrl'])<img src="{{ $page['agency']['logoUrl'] }}" alt="">@endif
            <span>{{ $page['agency']['name'] }}</span>
        </div>
        <h1>{{ $crew?->name }}</h1>
        <div class="date">{{ $page['now']->format('l j F Y') }}</div>
    </header>

    <div class="wrap" data-crew-page>
        @if($total === 0)
            <div class="empty" style="margin-top:18px;" data-crew-page-empty>
                Nothing is booked for your team right now.<br>When the office books a job for you it will show up here.
            </div>
        @endif

        {{-- Spec §17.42 — Today, Scheduled, Waiting for you, In progress (open work), then Done this month (read-only). --}}
        @foreach([
            ['today', 'Today', 'today'],
            ['upcoming', 'Scheduled — next ' . $page['upcoming_days'] . ' days', 'upcoming'],
            ['waiting', 'Waiting for you', 'waiting'],
            ['in_progress', 'In progress', 'in_progress'],
        ] as [$key, $heading, $group])
            @if(count($page[$key]))
                <h2 class="sec" data-group-heading="{{ $group }}">{{ $heading }} <span class="n">{{ count($page[$key]) }}</span></h2>
                @foreach($page[$key] as $j)
                    @include('rentals.crew-link._crew-job-row', ['j' => $j, 'group' => $group, 'jobUrl' => $jobUrl])
                @endforeach
            @endif
        @endforeach


        @if(count($page['materials']))
            <h2 class="sec" id="what-to-load">What to load <span class="n">{{ count($page['materials']) }}</span></h2>
            <div class="muted" style="margin:-2px 4px 8px;">Parts for today and the next {{ $page['upcoming_days'] }} days, added up.</div>
            <div class="card" data-materials>
                @foreach($page['materials'] as $m)
                    <div class="row" data-material-row>
                        <details>
                            <summary>
                                <div style="flex:1;">
                                    <div class="top">
                                        <span class="desc">{{ $m['description'] }}</span>
                                        <span class="qty" data-qty>{{ $m['quantity'] }}@if($m['unit']) {{ $m['unit'] }}@endif</span>
                                    </div>
                                    <div class="muted">
                                        @if($m['code']){{ $m['code'] }} · @endif
                                        @if($m['first_date'])needed from {{ $m['first_date'] }} · @endif{{ $m['job_count'] }} {{ $m['job_count'] === 1 ? 'job' : 'jobs' }}
                                        @if($page['show_costs'] && $m['total_value'] !== null) · R {{ $m['total_value'] }}@endif
                                    </div>
                                </div>
                            </summary>
                            <ul class="jobs-list">
                                @foreach($m['jobs'] as $mj)
                                    <li><span>{{ $mj['title'] }}@if($mj['date']) · {{ $mj['date'] }}@endif</span><span>{{ $mj['quantity'] }}</span></li>
                                @endforeach
                            </ul>
                        </details>
                    </div>
                @endforeach
            </div>
        @endif

        @if(count($page['done']))
            <details class="card" style="margin-top:20px;" data-done>
                <summary>Done this month <span class="chip">{{ count($page['done']) }}</span></summary>
                @foreach($page['done'] as $r)
                    <div class="row">
                        <div class="desc">{{ $r['title'] }}</div>
                        @if($r['address'] !== '')<div class="muted">{{ $r['address'] }}</div>@endif
                        <div class="muted">Completed {{ $r['completed_at'] }}</div>
                    </div>
                @endforeach
            </details>
        @endif

        <p class="note">Refresh this page to see the latest. A job drops off this list once the office closes it.</p>
    </div>
@include('partials.corex-confirm')
</body>
</html>
