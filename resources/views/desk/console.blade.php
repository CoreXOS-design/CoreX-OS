{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- CoreX Desk - the support console. Spec .ai/specs/corex-desk.md sections 4, 4a, 4b, 4d. The page's JS is its own Vite entry (desk/console.js).
     Two states: the LIST (this standard page: join by code, sessions open now) and the VIEWER (#cdc-live) - the whole browser window, shown the moment a
     session is joined. The viewer is moved to <body> by the JS so nothing in the page layout (menu, header, test-site banner) can sit over it. --}}
@extends('layouts.corex')

@section('corex-content')
<style>
    /* The corex-btn-* classes set display:inline-flex, which would beat the hidden attribute - keep hidden hidden. */
    #cdc-root [hidden], #cdc-live [hidden] { display: none !important; }

    /* ── Viewer: a pinned toolbar on top, the agent's window below it filling the rest of the browser window; letterbox only where the aspect differs ─────────── */
    #cdc-live { position: fixed; inset: 0; z-index: 2000000; background: #0b0f14; overflow: hidden; display: flex; flex-direction: column; }
    #cdc-pane { position: relative; flex: 1 1 auto; min-height: 0; }
    #cdc-replay { position: absolute; top: 0; left: 0; }
    #cdc-replay .cdc-tab { position: relative; }
    #cdc-wait { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; margin: 0; color: #9ca3af; font-size: .875rem; }
    /* the amber frame is drawn OVER the picture (a parent's own inset shadow would sit behind the mirror) */
    #cdc-pane::after { content: ''; display: none; position: absolute; inset: 0; z-index: 4; pointer-events: none; box-shadow: inset 0 0 0 3px #f59e0b; }
    #cdc-live.cdc-controlling #cdc-pane::after { display: block; }

    /* ── The toolbar: pinned, always visible (one row wherever there is room, wrapping on a narrow window) ───────── */
    #cdc-bar { position: relative; flex: 0 0 auto; z-index: 5; width: 100%;
        display: flex; align-items: center; gap: .3rem .75rem; flex-wrap: wrap; padding: .25rem .6rem; font-size: .72rem; white-space: nowrap;
        background: var(--surface, #fff); color: var(--text-primary, #111827); border-bottom: 1px solid var(--border, #e5e7eb); box-shadow: 0 2px 10px rgba(0, 0, 0, .25); }
    @media (min-width: 1240px) { #cdc-bar { flex-wrap: nowrap; } }   /* one row wherever there is room */
    #cdc-bar #cdc-support { display: inline-block; max-width: 15rem; overflow: hidden; text-overflow: ellipsis; vertical-align: bottom; }
    #cdc-bar #cdc-page { display: inline-block; max-width: 9rem; overflow: hidden; text-overflow: ellipsis; vertical-align: bottom; }
    #cdc-bar .cdc-k { color: var(--text-muted, #6b7280); }
    #cdc-bar strong { color: var(--text-primary, #111827); }
    #cdc-bar .cdc-grp { display: flex; align-items: center; gap: .35rem; }
    #cdc-bar .corex-btn-primary, #cdc-bar .corex-btn-outline { padding: .15rem .55rem; font-size: .72rem; line-height: 1.2; }
    #cdc-bar .list-header-filter { padding-top: .15rem; padding-bottom: .15rem; font-size: .72rem; }
    .cdc-chip { display: inline-flex; align-items: center; padding: 0 .55rem; height: 1.25rem; border-radius: 9999px; font-size: .7rem; font-weight: 700; }
    #cdc-toast { position: absolute; bottom: 18px; left: 50%; transform: translateX(-50%); z-index: 6; max-width: 80vw; padding: .4rem .8rem; border-radius: .5rem;
        font-size: .8rem; background: rgba(17, 24, 39, .92); color: #f9fafb; box-shadow: 0 6px 22px rgba(0, 0, 0, .4); }
    #cdc-toast.cdc-bad { background: #b91c1c; }
    #cdc-tabs { flex-wrap: wrap; min-width: 0; }
    #cdc-tabs button { max-width: 14rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    /* Timing panel (only when desk.timing_overlay is on) */
    #cdc-timing { position: absolute; left: 8px; bottom: 8px; z-index: 7; max-width: min(46rem, 96vw); max-height: 60%; overflow: auto; padding: .4rem .55rem; border-radius: .4rem;
        background: rgba(11, 15, 20, .88); color: #d1fae5; font: 11px/1.35 ui-monospace, SFMono-Regular, Menlo, monospace; white-space: pre; pointer-events: none; }
</style>

<div id="cdc-root" class="w-full">

    <x-page-header title="CoreX Desk">
        <x-slot:actions>
            {{-- Join by code: one line --}}
            <form id="cdc-form" class="flex items-center gap-2" autocomplete="off">
                <label for="cdc-code" class="text-xs font-semibold" style="color:var(--text-secondary);">Agent's code</label>
                <input id="cdc-code" type="text" inputmode="numeric" maxlength="7" placeholder="000 000" class="list-header-filter" style="width:8rem; text-align:center; letter-spacing:.15em;">
                <button type="submit" class="corex-btn-primary">Join</button>
            </form>
        </x-slot:actions>
    </x-page-header>

    <p id="cdc-msg" class="text-xs" style="min-height:1rem; margin:0 0 .5rem; color:var(--text-muted);" role="status"></p>

    {{-- Sessions open now: a live one opens (again), a waiting one is joined by its code --}}
    <div id="cdc-sessions" class="rounded-md px-4 py-3" style="background:var(--surface); border:1px solid var(--border);">
        <h2 class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-muted); margin:0 0 .25rem;">Sessions open now</h2>
        <ul id="cdc-list" style="list-style:none; margin:0; padding:0;">
            @foreach($sessions as $s)
                <li class="cdc-row" data-id="{{ $s->id }}" data-status="{{ $s->status }}" style="display:flex; gap:.75rem; align-items:center; padding:.35rem 0; border-top:1px solid var(--border); font-size:.8rem;">
                    <span style="flex:1; min-width:0;">{{ $s->agent?->name ?? 'Agent' }}@if($s->agency) · {{ $s->agency->name }}@endif —
                    {{ $s->status === 'live' ? 'live with ' . ($s->support_display ?: ($s->supportUser?->name ?? 'support')) : 'waiting for a support person' }}</span>
                    @if($s->status === 'live')<button type="button" class="corex-btn-outline" data-open="{{ $s->id }}">Open</button>@endif
                </li>
            @endforeach
        </ul>
        <p id="cdc-list-empty" class="text-xs" style="color:var(--text-muted); margin:0;" @if($sessions->isNotEmpty()) hidden @endif>No sessions open.</p>
        <p class="text-xs" style="margin:.5rem 0 0;"><a href="{{ route('corex.desk.log.index') }}" class="font-semibold" style="color:var(--brand-icon);">All past Desk sessions →</a></p>
    </div>
</div>

{{-- The viewer --}}
<div id="cdc-live" hidden>
    <div id="cdc-bar">
        <div><span class="cdc-k">Agent</span> <strong id="cdc-agent"></strong><span id="cdc-agency" hidden></span></div>
        <div style="min-width:0;"><span class="cdc-k">Support</span> <strong id="cdc-support"></strong></div>
        <div style="min-width:0;"><span class="cdc-k">Page</span> <strong id="cdc-page"></strong></div>
        <div><span class="cdc-k">Elapsed</span> <strong id="cdc-elapsed">0:00</strong></div>
        <span id="cdc-control-status" class="cdc-chip">Viewing</span>
        <div class="cdc-grp">
            <button type="button" id="cdc-control" class="corex-btn-primary" hidden>Request control</button>
        </div>
        <div class="cdc-grp">
            <button type="button" id="cdc-hl" class="corex-btn-outline" aria-pressed="true" title="Click the window to point something out to the agent">Highlight</button>
            <input id="cdc-note" type="text" maxlength="{{ (int) config('desk.note_max', 200) }}" placeholder="Note" class="list-header-filter" style="width:7rem;">
            <button type="button" id="cdc-clear" class="corex-btn-outline">Clear</button>
        </div>
        {{-- One button per tab: each CoreX tab the agent has open (title + page) and any Chrome tab they share. Shown when there is more than one. --}}
        <div id="cdc-tabs" hidden class="cdc-grp" role="tablist" aria-label="Shared tabs"></div>
        <div class="cdc-grp" style="margin-left:auto;">
            <button type="button" id="cdc-full" class="corex-btn-outline" title="Full screen">Full screen</button>
            <button type="button" id="cdc-back" class="corex-btn-outline" title="Leave the window and keep the session open">Back</button>
            <button type="button" id="cdc-end" class="corex-btn-outline">End session</button>
        </div>
    </div>
    <div id="cdc-pane">
        <div id="cdc-replay"></div>
        <p id="cdc-wait">Waiting for the agent's window…</p>
        <pre id="cdc-timing" hidden></pre>
    </div>
    <div id="cdc-toast" hidden role="status"></div>
</div>

<script>
    window.CorexDeskConsole = {!! json_encode($consoleConfig) !!};
</script>
@vite(['resources/js/desk/console.js'])
@endsection
