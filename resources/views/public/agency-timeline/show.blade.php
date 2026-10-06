{{-- AT-447 — public, read-only agency timeline. Receives a pre-rendered whitelisted payload only (no models). --}}
@extends('layouts.public')
@section('title', $agencyName . ' — onboarding timeline · CoreX')
@push('head')
<meta property="og:type" content="website">
<meta property="og:title" content="{{ $agencyName }} — onboarding timeline">
<meta property="og:description" content="{{ $isLive ? 'Live on CoreX.' : ($expected ? 'Go live ' . $expected->format('j F Y') . ' · ' . $percent . '% complete' : $percent . '% complete') }} — follow the plan step by step.">
<meta name="description" content="{{ $agencyName }} — onboarding timeline on CoreX">
<style>
    /* This page is a dashboard, not a column: use the screen. */
    .container-public { max-width: 1480px; padding-left: 1rem; padding-right: 1rem; }
    @media (min-width: 768px) { .container-public { padding: 1.75rem 2rem 3rem; } }
    :root { --tl-done:#16a34a; --tl-next:#0ea5e9; --tl-up:#94a3b8; --tl-late:#dc2626; --tl-skip:#d97706; --tl-ink:#0b2a4a; --tl-teal:#00b4d8; }
    [x-cloak] { display:none !important; }
    .tl-card { background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:1.4rem 1.6rem; box-shadow:0 1px 2px rgba(16,24,40,.04), 0 8px 24px rgba(16,24,40,.05); }

    /* ── Hero ─────────────────────────────────────────── */
    .tl-hero { position:relative; overflow:hidden; border-radius:20px; color:#fff; padding:1.75rem 1.75rem;
        background: radial-gradient(900px 340px at 85% -20%, rgba(0,180,216,.55), transparent 60%), radial-gradient(600px 300px at -10% 120%, rgba(51,196,224,.30), transparent 60%), linear-gradient(135deg, #0b2a4a 0%, #0d3b66 55%, #0a5a82 100%);
        box-shadow:0 18px 50px rgba(11,42,74,.35); }
    .tl-hero.is-live { background: radial-gradient(900px 340px at 85% -20%, rgba(74,222,128,.5), transparent 60%), linear-gradient(135deg, #064e3b 0%, #047857 60%, #10b981 130%); }
    .tl-hero-grid { position:relative; display:grid; gap:1.5rem; align-items:center; }
    @media (min-width: 980px) { .tl-hero-grid { grid-template-columns: 1fr auto auto; gap:2.25rem; } }
    .tl-logo { background:#fff; border-radius:12px; padding:.5rem .75rem; display:inline-flex; margin-bottom:.9rem; }
    .tl-eyebrow { font-size:.72rem; letter-spacing:.14em; text-transform:uppercase; color:rgba(255,255,255,.7); font-weight:600; }
    .tl-hero h1 { font-size:clamp(1.6rem, 3.4vw, 2.6rem); line-height:1.1; font-weight:800; margin-top:.35rem; letter-spacing:-.01em; }
    .tl-hero h1 small { display:block; font-size:.95rem; font-weight:500; opacity:.8; margin-top:.4rem; letter-spacing:0; }
    .tl-slip { margin-top:.6rem; display:inline-block; background:rgba(220,38,38,.22); border:1px solid rgba(252,165,165,.5); color:#fecaca; border-radius:9999px; padding:.2rem .8rem; font-size:.8rem; font-weight:600; }
    .tl-chip { margin-top:1rem; display:inline-flex; align-items:center; gap:.55rem; background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.22); backdrop-filter: blur(6px); border-radius:9999px; padding:.4rem .95rem .4rem .5rem; font-size:.85rem; }
    .tl-chip i { width:1.5rem; height:1.5rem; border-radius:9999px; background:var(--tl-teal); display:inline-flex; align-items:center; justify-content:center; font-style:normal; font-size:.75rem; box-shadow:0 0 0 0 rgba(0,180,216,.7); animation: tl-pulse-teal 2s infinite; }
    .tl-ring { position:relative; width:150px; height:150px; justify-self:center; }
    .tl-ring svg { transform:rotate(-90deg); width:100%; height:100%; }
    .tl-ring .bg { stroke:rgba(255,255,255,.16); }
    .tl-ring .fg { stroke:url(#tlg); stroke-linecap:round; stroke-dasharray:339.292; stroke-dashoffset:339.292; animation: tl-ring 1.6s .25s cubic-bezier(.22,.9,.3,1) forwards; }
    .tl-ring .mid { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; }
    .tl-ring .mid b { font-size:2.3rem; line-height:1; font-weight:800; }
    .tl-ring .mid span { font-size:.68rem; letter-spacing:.12em; text-transform:uppercase; opacity:.75; margin-top:.2rem; }
    .tl-count { text-align:center; min-width:150px; }
    .tl-count b { display:block; font-size:clamp(2.6rem, 5vw, 3.8rem); line-height:1; font-weight:800; background:linear-gradient(180deg,#fff,#a5f3fc); -webkit-background-clip:text; background-clip:text; color:transparent; }
    .tl-count span { font-size:.72rem; letter-spacing:.14em; text-transform:uppercase; opacity:.75; }
    .tl-count em { display:block; font-style:normal; font-size:.78rem; opacity:.7; margin-top:.3rem; }

    /* ── Interactive timeline (fits the width — no sideways scroll) ─────── */
    .tl-track { display:flex; align-items:flex-start; padding:3.4rem 1rem 0 3.5rem; position:relative; }
    .tl-node { position:relative; flex:1 1 0; min-width:0; text-align:center; background:none; border:0; cursor:pointer; padding:0 .4rem; font:inherit; color:inherit; opacity:0; animation: tl-pop .55s cubic-bezier(.2,.9,.3,1.2) forwards; animation-delay: calc(var(--i) * 90ms + 200ms); }
    /* connector runs from the edge of the previous dot to the edge of this one (never under a dot) */
    .tl-node::before { content:""; position:absolute; top:calc(1.25rem - 2.5px); left:calc(-50% + 1.55rem); width:calc(100% - 3.1rem); height:5px; background:#e5e7eb; border-radius:5px; z-index:0; }
    button.tl-node:first-of-type::before { display:none; }
    .tl-node.is-done::before { background:linear-gradient(90deg, #22c55e, var(--tl-done)); transform-origin:left; animation: tl-draw .7s ease-out backwards; animation-delay: calc(var(--i) * 90ms + 350ms); }
    .tl-dot { position:relative; z-index:1; margin:0 auto; width:2.5rem; height:2.5rem; border-radius:9999px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:1rem; font-weight:800; border:4px solid #fff; background:radial-gradient(circle at 30% 25%, color-mix(in srgb, var(--c) 70%, #fff), var(--c)); box-shadow:0 0 0 2px var(--c), 0 6px 14px color-mix(in srgb, var(--c) 40%, transparent); transition:transform .18s, box-shadow .18s; }
    .tl-node:hover .tl-dot, .tl-node:focus-visible .tl-dot { transform:translateY(-3px) scale(1.1); }
    .tl-node.is-sel .tl-dot { transform:scale(1.2); box-shadow:0 0 0 2px var(--c), 0 0 0 7px color-mix(in srgb, var(--c) 28%, transparent), 0 10px 22px color-mix(in srgb, var(--c) 45%, transparent); }
    .tl-node.is-now .tl-dot { animation: tl-glow 2.2s ease-in-out infinite; }
    .tl-node.is-go .tl-dot { width:3.1rem; height:3.1rem; font-size:1.5rem; margin-top:-.3rem; }
    .tl-node .d { margin-top:.65rem; font-size:.74rem; font-weight:800; color:var(--c); text-transform:uppercase; letter-spacing:.05em; }
    .tl-node .t { margin-top:.2rem; font-size:.84rem; line-height:1.3; color:#4b5563; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
    .tl-node.is-sel .t { color:#0f172a; font-weight:700; }
    .tl-node:focus-visible { outline:2px solid var(--tl-next); outline-offset:3px; border-radius:10px; }
    .tl-today { flex:0 0 0; position:relative; width:0; z-index:2; }
    .tl-today span { position:absolute; top:-2.5rem; left:50%; transform:translateX(-50%); background:linear-gradient(135deg,#0b2a4a,#0e5a8a); color:#fff; font-size:.7rem; font-weight:800; padding:.22rem .7rem; border-radius:9999px; white-space:nowrap; box-shadow:0 6px 14px rgba(11,42,74,.35); animation: tl-bob 2.4s ease-in-out infinite; }
    .tl-today span::after { content:""; position:absolute; left:50%; bottom:-5px; transform:translateX(-50%); border:5px solid transparent; border-top-color:#0e5a8a; border-bottom:0; }
    .tl-today i { position:absolute; top:-.2rem; left:-1px; width:2px; height:4rem; background:linear-gradient(#0e5a8a, transparent); opacity:.45; }

    /* detail panel */
    .tl-detail { margin-top:1.4rem; border-radius:14px; padding:1.1rem 1.25rem; display:flex; gap:1rem; align-items:flex-start; justify-content:space-between; background:linear-gradient(135deg, color-mix(in srgb, var(--dc) 9%, #fff), #fff 70%); border:1px solid color-mix(in srgb, var(--dc) 28%, #e5e7eb); border-left:5px solid var(--dc); }
    .tl-nav { display:flex; gap:.45rem; flex:0 0 auto; }
    .tl-nav button { width:2.4rem; height:2.4rem; border-radius:9999px; border:1px solid #d1d5db; background:#fff; cursor:pointer; font-size:1.2rem; line-height:1; color:#374151; transition:transform .15s, box-shadow .15s; }
    .tl-nav button:hover:not(:disabled) { transform:translateY(-1px); box-shadow:0 4px 10px rgba(0,0,0,.12); }
    .tl-nav button:disabled { opacity:.35; cursor:not-allowed; }
    .tl-pill { display:inline-block; border-radius:9999px; padding:.12rem .65rem; font-size:.7rem; font-weight:800; color:#fff; vertical-align:middle; letter-spacing:.03em; }

    /* plan list + info */
    .tl-main { display:grid; gap:1.25rem; }
    @media (min-width: 1000px) { .tl-main { grid-template-columns: 1.6fr 1fr; align-items:start; } .tl-aside { position:sticky; top:1rem; } }
    .tl-step { display:flex; gap:.95rem; padding:.9rem .85rem; border-radius:12px; border:1px solid transparent; cursor:pointer; transition:background .15s, transform .15s; }
    .tl-step + .tl-step { margin-top:.2rem; }
    .tl-step:hover { background:#f8fafc; transform:translateX(3px); }
    .tl-step.is-sel { background:#f0f9ff; border-color:#bae6fd; }
    .tl-step .dot { flex:0 0 auto; width:1.7rem; height:1.7rem; border-radius:9999px; display:flex; align-items:center; justify-content:center; font-size:.8rem; font-weight:800; color:#fff; margin-top:.05rem; background:var(--c); box-shadow:0 3px 8px color-mix(in srgb, var(--c) 40%, transparent); }
    .tl-body h3, .tl-body h4 { margin:.5rem 0 .25rem; font-weight:700; } .tl-body p { margin:.35rem 0; } .tl-body ul { margin:.35rem 0 .35rem 1.2rem; list-style:disc; }
    .tl-live { display:inline-block; background:#dcfce7; color:#166534; border-radius:9999px; font-size:.65rem; font-weight:800; padding:.05rem .55rem; margin-left:.4rem; text-transform:uppercase; letter-spacing:.04em; }


    /* agency actions */
    .tl-ok { background:linear-gradient(135deg,#ecfdf5,#d1fae5); border:1px solid #6ee7b7; color:#065f46; border-radius:14px; padding:.8rem 1.1rem; font-weight:600; display:flex; align-items:center; gap:.6rem; animation: tl-pop .5s ease-out both; }
    .tl-ok i { width:1.5rem; height:1.5rem; border-radius:9999px; background:#10b981; color:#fff; display:inline-flex; align-items:center; justify-content:center; font-style:normal; font-size:.85rem; flex:0 0 auto; }
    .tl-btn { display:inline-flex; align-items:center; gap:.5rem; border:0; cursor:pointer; font:inherit; font-weight:700; font-size:.9rem; color:#fff; padding:.6rem 1.15rem; border-radius:9999px; background:linear-gradient(135deg,#0ea5e9,#00b4d8 60%,#14b8a6); box-shadow:0 8px 20px rgba(14,165,233,.35); transition:transform .15s, box-shadow .15s, filter .15s; }
    .tl-btn:hover { transform:translateY(-2px); box-shadow:0 12px 26px rgba(14,165,233,.45); filter:brightness(1.05); }
    .tl-btn.sm { font-size:.75rem; padding:.3rem .8rem; box-shadow:0 4px 10px rgba(14,165,233,.3); }
    .tl-undo { background:none; border:0; cursor:pointer; font:inherit; font-size:.78rem; text-decoration:underline; color:#6b7280; padding:.2rem .3rem; }
    .tl-yours { display:inline-block; background:#e0f2fe; color:#075985; border-radius:9999px; font-size:.65rem; font-weight:800; padding:.05rem .55rem; margin-left:.4rem; text-transform:uppercase; letter-spacing:.04em; }
    /* ── Phones & small tablets: vertical timeline instead of sideways scrolling ── */
    @media (max-width: 880px) {
        .tl-track { flex-direction:column; padding:.25rem 0 0; }
        .tl-node { flex:none; display:grid; grid-template-columns:2.6rem 1fr; column-gap:.9rem; text-align:left; padding:0 0 1.35rem; }
        .tl-node::before { display:none !important; }
        .tl-node::after { content:""; position:absolute; left:1.3rem; top:2.6rem; bottom:-.1rem; width:4px; margin-left:-2px; background:#e5e7eb; border-radius:4px; }
        .tl-node:last-child::after { display:none; }
        .tl-node.is-done::after { background:var(--tl-done); }
        .tl-node .tl-dot { margin:0; grid-row:1 / span 2; }
        .tl-node.is-go .tl-dot { margin-top:0; }
        .tl-node .d { margin-top:.1rem; } .tl-node .t { -webkit-line-clamp:unset; display:block; }
        .tl-today { width:auto; flex:none; padding:0 0 1.1rem 3.5rem; }
        .tl-today span { position:static; transform:none; display:inline-block; } .tl-today span::after, .tl-today i { display:none; }
        .tl-detail { flex-direction:column; } .tl-nav { align-self:flex-end; }
        .tl-ring { width:130px; height:130px; }
    }

    @keyframes tl-ring { to { stroke-dashoffset: var(--ring-to); } }
    @keyframes tl-pop { from { opacity:0; transform:translateY(14px) scale(.9); } to { opacity:1; transform:none; } }
    @keyframes tl-draw { from { transform:scaleX(0); } to { transform:scaleX(1); } }
    @keyframes tl-glow { 0%,100% { box-shadow:0 0 0 2px var(--c), 0 0 0 0 color-mix(in srgb, var(--c) 55%, transparent); } 50% { box-shadow:0 0 0 2px var(--c), 0 0 0 12px color-mix(in srgb, var(--c) 0%, transparent); } }
    @keyframes tl-bob { 0%,100% { transform:translateX(-50%) translateY(0); } 50% { transform:translateX(-50%) translateY(-4px); } }
    @keyframes tl-pulse-teal { 0% { box-shadow:0 0 0 0 rgba(0,180,216,.7); } 70% { box-shadow:0 0 0 9px rgba(0,180,216,0); } 100% { box-shadow:0 0 0 0 rgba(0,180,216,0); } }
    @media (prefers-reduced-motion: reduce) { .tl-node, .tl-node.is-done::before, .tl-ring .fg, .tl-chip i, .tl-today span, .tl-node.is-now .tl-dot { animation:none !important; opacity:1; } .tl-ring .fg { stroke-dashoffset: var(--ring-to); } }
</style>
@endpush
@section('public-content')
@php
    $tone = ['done' => ['#16a34a', 'Done', '✓'], 'next' => ['#0ea5e9', 'Next up', ''], 'upcoming' => ['#94a3b8', 'Upcoming', ''], 'overdue' => ['#dc2626', 'Overdue', '!'], 'skipped' => ['#d97706', 'Skipped', '–']];
    $steps = $rows->map(function ($r) use ($tone) {
        [$c, $label, $glyph] = $tone[$r['state']] ?? $tone['upcoming'];
        $when = $r['state'] === 'done' && $r['done_on'] ? 'Done ' . $r['done_on']->format('j M Y')
            : ($r['due'] ? 'Due ' . $r['due']->format('D j M Y') : '');
        if ($r['state'] === 'overdue' && $r['late']) { $when .= ' · ' . $r['late'] . ' day' . ($r['late'] === 1 ? '' : 's') . ' late'; }

        return ['key' => $r['key'], 'title' => $r['title'], 'html' => $r['html'], 'state' => $r['state'], 'label' => $label, 'color' => $c,
                'glyph' => $glyph, 'when' => $when, 'short' => $r['due'] ? $r['due']->format('j M') : '—', 'live' => (bool) $r['live'],
                'action' => $r['action'], 'yours' => $r['yours'], 'canComplete' => $r['can_complete'], 'canUndo' => $r['can_undo']];
    })->values();
    $focus = $steps->first(fn ($s) => $s['state'] === 'overdue') ?? $steps->first(fn ($s) => $s['state'] === 'next');
    $ringTo = round(339.292 * (1 - max(0, min(100, $percent)) / 100), 2);
@endphp
<div class="space-y-5"
     x-data="{
        sel: @js($selected),
        steps: @js($steps),
        fade: true,
        go(i) { if (i < 0 || i >= this.steps.length) return; this.fade = false; this.sel = i; setTimeout(() => this.fade = true, 40); },
        get cur() { return this.steps[this.sel]; },
     }">

    @if(session('tl_ok'))<div class="tl-ok" role="status"><i>✓</i><span>{{ session('tl_ok') }}</span></div>@endif

    {{-- Hero --}}
    <div class="tl-hero {{ $isLive ? 'is-live' : '' }}">
        <div class="tl-hero-grid">
            <div>
                @if($logoUrl)<div class="tl-logo"><img src="{{ $logoUrl }}" alt="{{ $agencyName }}" style="max-height:52px; max-width:170px; object-fit:contain;"></div>@endif
                <div class="tl-eyebrow">Getting {{ $agencyName }} live on CoreX</div>
                @if($isLive)
                    <h1>You are live on CoreX</h1>
                @elseif($expected)
                    <h1>Go live {{ $expected->format('j F Y') }}<small>{{ $agencyName }} · {{ $doneCount }} of {{ $totalCount }} steps done</small></h1>
                    @if($slip)<span class="tl-slip">Moved from {{ $planned->format('j F') }} — {{ $slip }} day{{ $slip === 1 ? '' : 's' }} of steps are overdue</span>@endif
                @else
                    <h1>{{ $agencyName }}<small>{{ $doneCount }} of {{ $totalCount }} steps done</small></h1>
                @endif
                @if($focus && !$isLive)
                    <div class="tl-chip"><i>{{ $focus['state'] === 'overdue' ? '!' : '→' }}</i><span><strong>{{ $focus['state'] === 'overdue' ? 'Needs attention:' : 'Next up:' }}</strong> {{ \Illuminate\Support\Str::limit($focus['title'], 70) }} · {{ $focus['short'] }}</span></div>
                @endif
            </div>
            <div class="tl-ring" style="--ring-to: {{ $ringTo }};" role="img" aria-label="{{ $percent }} percent complete">
                <svg viewBox="0 0 120 120">
                    <defs><linearGradient id="tlg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#67e8f9"/><stop offset="1" stop-color="#34d399"/></linearGradient></defs>
                    <circle class="bg" cx="60" cy="60" r="54" fill="none" stroke-width="10"/>
                    <circle class="fg" cx="60" cy="60" r="54" fill="none" stroke-width="10"/>
                </svg>
                <div class="mid"><b>{{ $percent }}%</b><span>Complete</span></div>
            </div>
            <div class="tl-count">
                @if($isLive)
                    <b>✓</b><span>Live</span>
                @elseif($daysToLive === null)
                    <b>—</b><span>No go-live date</span>
                @elseif($daysToLive < 0)
                    <b>{{ abs($daysToLive) }}</b><span>Days past go-live</span>
                @elseif($daysToLive === 0)
                    <b>Today</b><span>is go-live day</span>
                @else
                    <b>{{ $daysToLive }}</b><span>Day{{ $daysToLive === 1 ? '' : 's' }} to go live</span>
                @endif
                @if(!$isLive && $expected)<em>{{ $expected->format('l j F Y') }}</em>@endif
            </div>
        </div>
    </div>

    {{-- Interactive timeline --}}
    @if($rows->isNotEmpty())
    <div class="tl-card" aria-label="Timeline">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-lg font-bold" style="color:var(--tl-ink);">Your journey to go-live</h2>
            <div class="text-xs" style="color:#6b7280;">Tap a step for the detail</div>
        </div>
        <div class="tl-track" x-ref="track">
            @foreach($steps as $i => $s)
                @if($i === $todayAt)
                    <div class="tl-today" aria-hidden="true"><span>Today · {{ $today->format('j M') }}</span><i></i></div>
                @endif
                <button type="button" @click="go({{ $i }})"
                        class="tl-node {{ $s['state'] === 'done' ? 'is-done' : '' }} {{ in_array($s['state'], ['next', 'overdue'], true) ? 'is-now' : '' }} {{ $s['live'] ? 'is-go' : '' }}"
                        :class="{ 'is-sel': sel === {{ $i }} }" style="--c: {{ $s['color'] }}; --i: {{ $i }};"
                        aria-label="{{ $s['title'] }}, {{ $s['label'] }}">
                    <div class="tl-dot">{{ $s['glyph'] }}</div>
                    <div class="d">{{ $s['short'] }}</div>
                    <div class="t">{{ $s['title'] }}</div>
                </button>
            @endforeach
            @if($todayAt >= $steps->count())
                <div class="tl-today" aria-hidden="true"><span>Today · {{ $today->format('j M') }}</span><i></i></div>
            @endif
        </div>
        <div class="tl-detail" x-show="cur && fade" x-cloak x-transition.opacity.duration.200ms :style="'--dc:' + cur.color">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-lg font-bold" style="color:#0f172a;" x-text="cur.title"></h3>
                    <span class="tl-pill" :style="'background:' + cur.color" x-text="cur.label"></span>
                    <span class="tl-live" x-show="cur.live">Go live</span>
                    <span class="tl-yours" x-show="cur.yours">Your step</span>
                </div>
                <div class="text-sm mt-1 font-medium" style="color:#6b7280;" x-text="cur.when"></div>
                <div class="tl-body text-sm mt-2" style="color:#374151;" x-html="cur.html"></div>
                <div class="mt-3 flex flex-wrap items-center gap-3" x-show="cur.canComplete || cur.canUndo">
                    <form method="POST" :action="cur.action" x-show="cur.canComplete" @submit="if (!confirm('Mark &quot;' + cur.title + '&quot; as completed? CoreX will see it straight away.')) $event.preventDefault()">
                        @csrf<input type="hidden" name="status" value="done">
                        <button type="submit" class="tl-btn"><span aria-hidden="true">✓</span> Mark as completed</button>
                    </form>
                    <form method="POST" :action="cur.action" x-show="cur.canUndo" @submit="if (!confirm('Put &quot;' + cur.title + '&quot; back on your list?')) $event.preventDefault()">
                        @csrf<input type="hidden" name="status" value="pending">
                        <button type="submit" class="tl-undo">Undo — not completed yet</button>
                    </form>
                </div>
            </div>
            <div class="tl-nav">
                <button type="button" @click="go(sel - 1)" :disabled="sel === 0" aria-label="Previous step">‹</button>
                <button type="button" @click="go(sel + 1)" :disabled="sel === steps.length - 1" aria-label="Next step">›</button>
            </div>
        </div>
    </div>
    @endif

    {{-- Plan + information --}}
    <div class="tl-main">
        @if($rows->isNotEmpty())
        <div class="tl-card">
            <h2 class="text-lg font-bold mb-2" style="color:var(--tl-ink);">The plan</h2>
            @foreach($steps as $i => $s)
                <div class="tl-step" :class="{ 'is-sel': sel === {{ $i }} }" style="--c: {{ $s['color'] }};" @click="go({{ $i }}); $refs.track.scrollIntoView({ behavior: 'smooth', block: 'center' })">
                    <div class="dot">{{ $s['glyph'] }}</div>
                    <div class="tl-body flex-1" style="min-width:0;">
                        <div class="text-sm font-semibold" style="color:#111827;">{{ $s['title'] }}@if($s['live'])<span class="tl-live">Go live</span>@endif @if($s['yours'] && $s['state'] !== 'done')<span class="tl-yours">Your step</span>@endif</div>
                        <div class="text-xs mt-0.5" style="color:{{ $s['color'] }}; font-weight:700;">{{ $s['label'] }} · {{ $s['when'] }}</div>
                        @if($s['html'])<div class="text-sm" style="color:#6b7280;">{!! $s['html'] !!}</div>@endif
                        @if($s['canComplete'])
                            <form method="POST" action="{{ $s['action'] }}" class="mt-2" @click.stop onsubmit="return confirm('Mark this step as completed? CoreX will see it straight away.');">@csrf<input type="hidden" name="status" value="done"><button type="submit" class="tl-btn sm"><span aria-hidden="true">✓</span> Mark as completed</button></form>
                        @elseif($s['canUndo'])
                            <form method="POST" action="{{ $s['action'] }}" class="mt-1" @click.stop onsubmit="return confirm('Put this step back on your list?');">@csrf<input type="hidden" name="status" value="pending"><button type="submit" class="tl-undo">Undo — not completed yet</button></form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        @endif

        @if($blocks->isNotEmpty())
        <div class="tl-aside space-y-4">
            @foreach($blocks as $b)
                <div class="tl-card"><h2 class="text-lg font-bold mb-1" style="color:var(--tl-ink);">{{ $b['title'] }}</h2><div class="tl-body text-sm" style="color:#374151;">{!! $b['html'] !!}</div></div>
            @endforeach
        </div>
        @endif
    </div>

    <p class="text-center text-xs" style="color:#9ca3af;">Updated {{ $today->format('j F Y') }} · CoreX OS</p>
</div>
@endsection
