@php
$r = $rollup;

$targets = $r['totals']['targets'] ?? [];
$actuals = $r['totals']['actuals'] ?? [];

$valueTarget = (float)($targets['value'] ?? 0);
$valueActual = (float)($actuals['value'] ?? 0);
$valuePct = $valueTarget > 0 ? min(100, ($valueActual / $valueTarget) * 100) : 0;
$valueRemaining = max($valueTarget - $valueActual, 0);

$dealsTarget = (int)($targets['deals'] ?? 0);
$dealsActual = (int)($actuals['deals'] ?? 0);

$pointsActual = (float)($actuals['points'] ?? 0);
$pointsTarget = (float)($targets['points'] ?? 0);
$pointsPct = $pointsTarget > 0 ? min(100, ($pointsActual / $pointsTarget) * 100) : 0;

// DB-driven TV messages — split by display_area
$tvRows = collect($tvMessages ?? []);

$heroMessages = $tvRows
    ->filter(fn($m) => in_array(($m['display_area'] ?? 'both'), ['hero','both'], true))
    ->pluck('message')
    ->map(fn($x) => trim((string)$x))
    ->filter(fn($x) => $x !== '')
    ->values()
    ->all();

$tickerMessages = $tvRows
    ->filter(fn($m) => in_array(($m['display_area'] ?? 'both'), ['ticker','both'], true))
    ->pluck('message')
    ->map(fn($x) => trim((string)$x))
    ->filter(fn($x) => $x !== '')
    ->values()
    ->all();

$messages = $heroMessages;
$tickerMessagesFinal = $tickerMessages;

// Clean up any accidental backslashes from stored messages
$messages = array_map(function($x){
    $x = (string)$x;
    $x = preg_replace('/\\\\([!?,.])/', '$1', $x);
    return $x;
}, $messages);

if (count($messages) === 0) {
    $dealsRemaining = max(0, $dealsTarget - $dealsActual);
    $messages = ["HOME FINDERS COASTAL: Only {$dealsRemaining} deals to go this month!"];
}
if (count($tickerMessagesFinal) === 0) {
    $tickerMessagesFinal = [];
}

$hasHero = count($messages) > 0;
$hasTicker = count($tickerMessagesFinal) > 0;

// Status summary
$ss = $statusSummary ?? [];
$pending = (int)($ss['pending_period'] ?? 0);
$granted = (int)($ss['granted_period'] ?? 0);
$registered = (int)($ss['registered_period'] ?? 0);
$declined = (int)($ss['declined_period'] ?? 0);

// Listing stats
$ls = $listingStats ?? [];
$lsTotal = (int)($ls['total'] ?? 0);
$lsAvgDom = (int)($ls['avg_days_on_market'] ?? 0);
$lsStale = (int)($ls['stale'] ?? 0);
$lsExpiring = (int)($ls['expiring_soon'] ?? 0);
$lsExpired = (int)($ls['expired'] ?? 0);

// Leaderboard — cap at 15 for company
$leaderboard = array_slice($agentLeaderboard ?? [], 0, 15);

// Branch breakdown
$branchCards = $branchBreakdown ?? [];
@endphp

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
@if(!empty($autoRefresh))
<meta http-equiv="refresh" content="300">
@endif
<title>TV — {{ $companyName }}</title>

<style>
/* ── RESET ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;overflow:hidden;font-family:'Segoe UI',system-ui,-apple-system,sans-serif;background:#0f1923;color:#fff}

/* ── SCREEN ── */
.tv-screen{height:100vh;display:flex;flex-direction:column}

/* ── ZONE 1: HEADER ── */
.tv-header{
    display:flex;justify-content:space-between;align-items:center;
    padding:.6rem 2rem;flex-shrink:0;
    border-bottom:3px solid #00b4d8;
}
.tv-branch{font-size:3rem;font-weight:800;letter-spacing:.02em}
.tv-clock{font-size:3rem;font-weight:700;opacity:.95;font-variant-numeric:tabular-nums}

/* ── ZONE 2: HERO MESSAGE ── */
.tv-hero{
    background:#0b2a4a;text-align:center;
    padding:.5rem 2rem;flex-shrink:0;
    font-size:2rem;font-weight:700;
    border-bottom:1px solid rgba(0,180,216,.3);
    transition:opacity .4s ease;
}
.tv-hero.hidden{display:none}

/* ── ZONE 3: MAIN 3-COL GRID ── */
.tv-main{
    flex:1;min-height:0;
    display:grid;grid-template-columns:25% 35% 40%;
    padding:.8rem 2rem;gap:.8rem;
}

/* ── LEFT COLUMN: Deals + Listings + Branch Breakdown ── */
.tv-left{display:flex;flex-direction:column;justify-content:space-between;gap:.4rem}

/* Deal rows */
.tv-deal-row{display:flex;align-items:center;gap:.7rem;padding:.15rem 0}
.tv-deal-dot{width:16px;height:16px;border-radius:50%;flex-shrink:0}
.tv-deal-dot.pending{background:#f59e0b}
.tv-deal-dot.granted{background:#059669}
.tv-deal-dot.registered{background:#0b2a4a;border:3px solid #00b4d8}
.tv-deal-dot.declined{background:#c41e3a}
.tv-deal-label{font-size:1.25rem;color:#94a3b8;flex:1}
.tv-deal-val{font-size:1.75rem;font-weight:800}

.tv-left-divider{border:none;border-top:1px solid rgba(0,180,216,.25);margin:.3rem 0}

/* Listing rows */
.tv-listing-row{display:flex;justify-content:space-between;align-items:baseline;padding:.15rem 0}
.tv-listing-label{font-size:1.25rem;color:#94a3b8}
.tv-listing-val{font-size:1.75rem;font-weight:800}
.tv-listing-val.warn{color:#f59e0b}
.tv-listing-val.danger{color:#c41e3a}

/* Branch breakdown */
.tv-branch-row{display:flex;justify-content:space-between;align-items:baseline;padding:.1rem 0}
.tv-branch-name{font-size:1rem;color:#94a3b8;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tv-branch-stat{font-size:1rem;font-weight:700;text-align:right;flex-shrink:0;margin-left:.5rem;font-variant-numeric:tabular-nums}
.tv-branch-stat-pts{font-size:.85rem;color:#00b4d8;text-align:right;flex-shrink:0;margin-left:.5rem;font-variant-numeric:tabular-nums}

/* ── CENTRE COLUMN: Value + Points cards ── */
.tv-centre{display:flex;flex-direction:column;gap:.8rem;justify-content:center}
.tv-vp-card{
    background:#1a2937;border-radius:14px;padding:.8rem 1.2rem;
    border:1px solid rgba(255,255,255,.06);
}
.tv-vp-label{font-size:1.5rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.15rem}
.tv-vp-big{font-size:4rem;font-weight:900;line-height:1.05}
.tv-vp-sub{font-size:1.25rem;color:#94a3b8;margin-top:.15rem}
.tv-vp-remaining{font-size:1.25rem;color:#00b4d8;margin-top:.3rem}
.tv-bar{height:14px;background:#334155;border-radius:999px;overflow:hidden;margin-top:.4rem}
.tv-bar-fill{height:100%;border-radius:999px;transition:width 1s ease}
.tv-bar-fill.green{background:linear-gradient(90deg,#059669,#34d399)}
.tv-bar-fill.amber{background:linear-gradient(90deg,#f59e0b,#fbbf24)}
.tv-bar-fill.crimson{background:linear-gradient(90deg,#c41e3a,#ef4444)}

/* ── RIGHT COLUMN: Agent Leaderboard ── */
.tv-right{display:flex;flex-direction:column;min-height:0;overflow:hidden}
.tv-lb-title{
    font-size:1.25rem;text-transform:uppercase;letter-spacing:.12em;
    color:#00b4d8;padding:0 0 .3rem;flex-shrink:0;font-weight:700;
}
.tv-lb-list{flex:1;overflow:hidden;display:flex;flex-direction:column;justify-content:space-between}

/* Agent row — 2 lines */
.tv-agent{padding:.2rem 0;border-left:3px solid transparent}
.tv-agent:nth-child(odd){background:rgba(255,255,255,.02)}
.tv-agent:nth-child(even){background:transparent}
.tv-agent.top{border-left-color:#00b4d8;background:rgba(0,180,216,.06)}
.tv-agent.dimmed{opacity:.5}
.tv-agent-line1{display:flex;align-items:baseline;gap:.5rem;padding:0 .5rem}
.tv-agent-rank{font-size:1.2rem;font-weight:800;width:2rem;text-align:center;flex-shrink:0}
.tv-agent-name{font-size:1.2rem;font-weight:700;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tv-agent-branch{font-size:.75rem;color:#64748b;font-weight:400;margin-left:.3rem}
.tv-agent-pts{font-size:1.2rem;font-weight:800;text-align:right;font-variant-numeric:tabular-nums;flex-shrink:0}
.tv-agent-pts-label{font-size:.8rem;color:#94a3b8;margin-left:.2rem}
.tv-agent-line2{display:flex;align-items:center;gap:.6rem;padding:0 .5rem 0 2.8rem}
.tv-agent-value{font-size:.9rem;color:#94a3b8;flex-shrink:0;font-variant-numeric:tabular-nums}
.tv-agent-bar-wrap{flex:1;height:6px;background:#334155;border-radius:999px;overflow:hidden}
.tv-agent-bar-fill{height:100%;border-radius:999px}

/* ── ZONE 5: TICKER ── */
.tv-ticker{
    height:55px;background:rgba(0,0,0,.6);
    display:flex;align-items:center;overflow:hidden;flex-shrink:0;
    border-top:1px solid rgba(0,180,216,.2);
}
.tv-ticker.hidden{display:none}
.tv-ticker-inner{
    display:inline-block;white-space:nowrap;
    animation:tv-marquee var(--ticker-duration, 40s) linear infinite;
    font-size:1.5rem;font-weight:500;color:#00b4d8;
    padding-left:100%;
}
@keyframes tv-marquee{
    0%{transform:translateX(0)}
    100%{transform:translateX(-100%)}
}
</style>
</head>
<body>
<div class="tv-screen">

    {{-- ── ZONE 1: HEADER ── --}}
    <div class="tv-header">
        <div class="tv-branch">{{ $companyName }}</div>
        <div class="tv-clock" id="clock"></div>
    </div>

    {{-- ── ZONE 2: HERO MESSAGE ── --}}
    <div class="tv-hero {{ $hasHero ? '' : 'hidden' }}" id="hero">{{ $messages[0] ?? '' }}</div>

    {{-- ── ZONE 3: MAIN 3-COL GRID ── --}}
    <div class="tv-main">

        {{-- LEFT: Deals + Listings + Branch Breakdown --}}
        <div class="tv-left">
            <div>
                <div class="tv-lb-title">Deals</div>
                <div class="tv-deal-row">
                    <div class="tv-deal-dot pending"></div>
                    <div class="tv-deal-label">Pending</div>
                    <div class="tv-deal-val">{{ $pending }}</div>
                </div>
                <div class="tv-deal-row">
                    <div class="tv-deal-dot granted"></div>
                    <div class="tv-deal-label">Granted</div>
                    <div class="tv-deal-val">{{ $granted }}</div>
                </div>
                <div class="tv-deal-row">
                    <div class="tv-deal-dot registered"></div>
                    <div class="tv-deal-label">Registered</div>
                    <div class="tv-deal-val">{{ $registered }}</div>
                </div>
                <div class="tv-deal-row">
                    <div class="tv-deal-dot declined"></div>
                    <div class="tv-deal-label">Declined</div>
                    <div class="tv-deal-val">{{ $declined }}</div>
                </div>
            </div>

            <hr class="tv-left-divider">

            <div>
                <div class="tv-lb-title">Listings</div>
                <div class="tv-listing-row">
                    <div class="tv-listing-label">Active</div>
                    <div class="tv-listing-val">{{ $lsTotal }}</div>
                </div>
                <div class="tv-listing-row">
                    <div class="tv-listing-label">Avg DOM</div>
                    <div class="tv-listing-val">{{ $lsAvgDom }}</div>
                </div>
                <div class="tv-listing-row">
                    <div class="tv-listing-label">Stale</div>
                    <div class="tv-listing-val {{ $lsStale > 0 ? 'warn' : '' }}">{{ $lsStale }}</div>
                </div>
                <div class="tv-listing-row">
                    <div class="tv-listing-label">Expiring</div>
                    <div class="tv-listing-val {{ $lsExpiring > 0 ? 'warn' : '' }}">{{ $lsExpiring }}</div>
                </div>
                <div class="tv-listing-row">
                    <div class="tv-listing-label">Expired</div>
                    <div class="tv-listing-val {{ $lsExpired > 0 ? 'danger' : '' }}">{{ $lsExpired }}</div>
                </div>
            </div>

            @if(count($branchCards) > 0)
            <hr class="tv-left-divider">

            <div>
                <div class="tv-lb-title">Branches</div>
                @foreach($branchCards as $bc)
                <div class="tv-branch-row">
                    <div class="tv-branch-name">{{ $bc['name'] }}</div>
                    <div class="tv-branch-stat">R {{ number_format($bc['sales_value'], 0, '.', ',') }}</div>
                    <div class="tv-branch-stat-pts">{{ number_format($bc['points'], 0, '.', ',') }} pts</div>
                </div>
                @endforeach
            </div>
            @endif
        </div>

        {{-- CENTRE: Value + Points --}}
        <div class="tv-centre">
            <div class="tv-vp-card">
                <div class="tv-vp-label">Sales Value</div>
                <div class="tv-vp-big">R {{ number_format($valueActual, 0, '.', ',') }}</div>
                <div class="tv-vp-sub">/ R {{ number_format($valueTarget, 0, '.', ',') }}</div>
                <div class="tv-bar">
                    <div class="tv-bar-fill {{ $valuePct >= 50 ? 'green' : ($valuePct >= 25 ? 'amber' : 'crimson') }}" style="width:{{ round($valuePct, 1) }}%"></div>
                </div>
                @if($valueRemaining > 0)
                <div class="tv-vp-remaining">Still to target: R {{ number_format($valueRemaining, 0, '.', ',') }}</div>
                @endif
            </div>
            <div class="tv-vp-card">
                <div class="tv-vp-label">Points</div>
                <div class="tv-vp-big">{{ number_format($pointsActual, 0, '.', ',') }}</div>
                <div class="tv-vp-sub">/ {{ number_format($pointsTarget, 0, '.', ',') }}</div>
                <div class="tv-bar">
                    <div class="tv-bar-fill {{ $pointsPct >= 50 ? 'green' : ($pointsPct >= 25 ? 'amber' : 'crimson') }}" style="width:{{ round($pointsPct, 1) }}%"></div>
                </div>
                @if($pointsTarget - $pointsActual > 0)
                <div class="tv-vp-remaining">Still to target: {{ number_format(max(0, $pointsTarget - $pointsActual), 0, '.', ',') }}</div>
                @endif
            </div>
        </div>

        {{-- RIGHT: Agent Leaderboard --}}
        <div class="tv-right">
            <div class="tv-lb-title">Company Leaderboard</div>
            <div class="tv-lb-list">
                @forelse($leaderboard as $idx => $agent)
                @php
                    $rank = $idx + 1;
                    $medal = match($rank) { 1 => "\u{1F947}", 2 => "\u{1F948}", 3 => "\u{1F949}", default => $rank };
                    $isTop = $rank === 1;
                    $isDimmed = ((int)$agent['deals'] === 0 && (float)$agent['points'] === 0);
                    $agentPtsPct = ($agent['points_target'] > 0) ? min(100, ($agent['points'] / $agent['points_target']) * 100) : 0;
                    $barColor = $agentPtsPct >= 50 ? '#059669' : ($agentPtsPct >= 25 ? '#f59e0b' : '#c41e3a');
                @endphp
                <div class="tv-agent {{ $isTop ? 'top' : '' }} {{ $isDimmed ? 'dimmed' : '' }}">
                    <div class="tv-agent-line1">
                        <span class="tv-agent-rank">{{ $medal }}</span>
                        <span class="tv-agent-name">{{ $agent['name'] }}<span class="tv-agent-branch">{{ $agent['branch_name'] ?? '' }}</span></span>
                        <span class="tv-agent-pts">{{ number_format($agent['points'], 0, '.', ',') }}<span class="tv-agent-pts-label">pts</span></span>
                    </div>
                    <div class="tv-agent-line2">
                        <span class="tv-agent-value">R {{ number_format($agent['sales_value'], 0, '.', ',') }} &middot; {{ $agent['deals'] }} deal{{ $agent['deals'] !== 1 ? 's' : '' }}</span>
                        <div class="tv-agent-bar-wrap">
                            <div class="tv-agent-bar-fill" style="width:{{ round($agentPtsPct, 1) }}%;background:{{ $barColor }}"></div>
                        </div>
                    </div>
                </div>
                @empty
                <div style="text-align:center;color:#94a3b8;padding:2rem;font-size:1.25rem">No agent data available</div>
                @endforelse
            </div>
        </div>

    </div>

    {{-- ── ZONE 5: TICKER ── --}}
    <div class="tv-ticker {{ $hasTicker ? '' : 'hidden' }}" id="tickerZone">
        <span class="tv-ticker-inner" id="tickerText">{{ implode('  ★  ', $tickerMessagesFinal) }}  ★  {{ implode('  ★  ', $tickerMessagesFinal) }}</span>
    </div>

</div>

<script>
/* ── Live Clock ── */
function clock(){
    var d = new Date();
    document.getElementById('clock').textContent =
        d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
}
setInterval(clock, 1000);
clock();

/* ── Hero Message Rotation (8s) ── */
var msgs = @json($messages);
if (msgs.length > 0) {
    var i = 0;
    var hero = document.getElementById('hero');
    hero.textContent = msgs[0];
    if (msgs.length > 1) {
        setInterval(function(){
            i = (i + 1) % msgs.length;
            hero.style.opacity = '0';
            setTimeout(function(){
                hero.textContent = msgs[i];
                hero.style.opacity = '1';
            }, 400);
        }, 8000);
    }
}

/* ── Ticker speed based on content length ── */
var tickerText = document.getElementById('tickerText');
if (tickerText) {
    var len = tickerText.textContent.length;
    var duration = Math.max(20, Math.min(80, len * 0.4));
    tickerText.style.setProperty('--ticker-duration', duration + 's');
    tickerText.parentElement.style.setProperty('--ticker-duration', duration + 's');
}
</script>
</body>
</html>
