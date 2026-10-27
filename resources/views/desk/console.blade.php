{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- CoreX Desk - the support console. Spec .ai/specs/corex-desk.md section 4. The page's JS is its own Vite entry (desk/console.js). --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">CoreX Desk</h1>
        <p class="text-xs" style="color: var(--text-muted);">See an agent's CoreX window live, point at things and highlight them. The agent reads you a 6-digit code from <strong>Get help</strong>; you only ever see their CoreX window, and the agency can see afterwards that you did.</p>
    </div>

    {{-- Join by code --}}
    <div id="cdc-join" class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
        <form id="cdc-form" class="flex items-center gap-3 flex-wrap" autocomplete="off">
            <label for="cdc-code" class="text-sm font-semibold" style="color:var(--text-primary);">Agent's code</label>
            <input id="cdc-code" type="text" inputmode="numeric" maxlength="7" placeholder="000 000" class="list-header-filter" style="width:9rem; font-size:1.1rem; letter-spacing:.2em; text-align:center;">
            <button type="submit" class="ds-btn ds-btn-primary">Join</button>
        </form>
    </div>
    <p id="cdc-msg" class="text-xs" style="min-height:1rem; color:var(--text-muted);" role="status"></p>

    {{-- A joined session: panel + the live view --}}
    <div id="cdc-live" hidden class="grid gap-4" style="grid-template-columns: minmax(0, 1fr);">
        <div class="rounded-md px-5 py-4 flex items-center gap-x-6 gap-y-2 flex-wrap text-xs" style="background:var(--surface); border:1px solid var(--border);">
            <div><span style="color:var(--text-muted);">Agent</span> <strong id="cdc-agent" style="color:var(--text-primary);"></strong></div>
            <div><span style="color:var(--text-muted);">Agency</span> <strong id="cdc-agency" style="color:var(--text-primary);"></strong></div>
            <div><span style="color:var(--text-muted);">Page</span> <strong id="cdc-page" style="color:var(--text-primary);"></strong></div>
            <div><span style="color:var(--text-muted);">Elapsed</span> <strong id="cdc-elapsed" style="color:var(--text-primary);">0:00</strong></div>
            <div class="flex items-center gap-2 ml-auto flex-wrap">
                <input id="cdc-note" type="text" maxlength="{{ (int) config('desk.note_max', 200) }}" placeholder="Note for the highlight (optional)" class="list-header-filter" style="min-width:16rem;">
                <button type="button" id="cdc-clear" class="ds-btn">Clear highlight</button>
                <button type="button" id="cdc-end" class="ds-btn">End session</button>
            </div>
        </div>
        <p class="text-xs" style="color:var(--text-muted); margin:0;">Move your mouse over the window below to point (the agent sees a “Support” cursor). Click anything to highlight it on their screen. The agent does the clicking.</p>
        <p id="cdc-wait" class="text-sm" style="color:var(--text-muted); margin:0;">Waiting for the agent's window…</p>
        <div style="overflow:auto; background:var(--surface-2); border:1px solid var(--border); border-radius:.375rem;">
            <div id="cdc-replay"></div>
        </div>
    </div>

    {{-- Live sessions --}}
    <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
        <h2 class="text-sm font-bold" style="color:var(--text-primary); margin:0 0 .5rem;">Sessions open now</h2>
        <ul id="cdc-list" style="list-style:none; margin:0; padding:0;">
            @foreach($sessions as $s)
                <li style="display:flex; gap:.75rem; align-items:center; padding:.5rem 0; border-top:1px solid var(--border); font-size:.8rem;">
                    {{ $s->agent?->name ?? 'Agent' }}@if($s->agency) · {{ $s->agency->name }}@endif —
                    {{ $s->status === 'live' ? 'live with ' . ($s->supportUser?->name ?? 'support') : 'waiting for a support person' }}
                </li>
            @endforeach
        </ul>
        <p id="cdc-list-empty" class="text-xs" style="color:var(--text-muted); margin:0;" @if($sessions->isNotEmpty()) hidden @endif>No sessions open.</p>
        @if(auth()->user()->hasPermission('manage_users'))
            <p class="text-xs" style="margin:.75rem 0 0;"><a href="{{ route('compliance.desk-sessions.index') }}" class="font-semibold" style="color:var(--brand-icon);">All past Desk sessions →</a></p>
        @endif
    </div>
</div>

<script>
    window.CorexDeskConsole = {!! json_encode($consoleConfig) !!};
</script>
@vite(['resources/js/desk/console.js'])
@endsection
