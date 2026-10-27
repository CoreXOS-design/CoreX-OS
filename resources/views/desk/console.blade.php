{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- CoreX Desk - the support console. Spec .ai/specs/corex-desk.md sections 4, 4a, 4b. The page's JS is its own Vite entry (desk/console.js).
     Standard page: left menu + the sticky page header, content only below it - no helper paragraphs, labels carry the meaning. --}}
@extends('layouts.corex')

@section('corex-content')
<style>
    /* The corex-btn-* classes set display:inline-flex, which would beat the hidden attribute - keep hidden hidden. */
    #cdc-root [hidden] { display: none !important; }
    #cdc-pane { position: relative; overflow: hidden; background: var(--surface-2); border: 1px solid var(--border); border-radius: .375rem; }
    #cdc-replay { position: absolute; top: 0; }
    #cdc-replay .cdc-tab { position: relative; }
    #cdc-bar { position: sticky; top: 3.5rem; z-index: 20; }
    .cdc-chip { display: inline-flex; align-items: center; padding: 0 .5rem; height: 1.25rem; border-radius: 9999px; font-size: .7rem; font-weight: 700; }
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

    {{-- A joined session: sticky session bar, tab bar (extension tabs), and the mirror filling the rest of the height --}}
    <div id="cdc-live" hidden>
        <div id="cdc-bar" class="rounded-md px-4 py-2 flex items-center gap-x-5 gap-y-2 flex-wrap text-xs" style="background:var(--surface); border:1px solid var(--border); margin-bottom:.5rem;">
            <div><span style="color:var(--text-muted);">Agent</span> <strong id="cdc-agent" style="color:var(--text-primary);"></strong></div>
            <div><span style="color:var(--text-muted);">Agency</span> <strong id="cdc-agency" style="color:var(--text-primary);"></strong></div>
            <div style="min-width:0;"><span style="color:var(--text-muted);">Page</span> <strong id="cdc-page" style="color:var(--text-primary); overflow-wrap:anywhere;"></strong></div>
            <div><span style="color:var(--text-muted);">Elapsed</span> <strong id="cdc-elapsed" style="color:var(--text-primary);">0:00</strong></div>
            <span id="cdc-control-status" class="cdc-chip" hidden></span>
            <div class="flex items-center gap-2 ml-auto flex-wrap">
                <input id="cdc-note" type="text" maxlength="{{ (int) config('desk.note_max', 200) }}" placeholder="Note for the highlight" class="list-header-filter" style="min-width:13rem;">
                <button type="button" id="cdc-control" class="corex-btn-primary" hidden>Request control</button>
                <button type="button" id="cdc-clear" class="corex-btn-outline">Clear highlight</button>
                <button type="button" id="cdc-end" class="corex-btn-outline">End session</button>
            </div>
        </div>
        {{-- Phase 3: one button per shared Chrome tab (title + site) when the agent has shared any --}}
        <div id="cdc-tabs" hidden class="flex flex-wrap gap-2" style="margin-bottom:.5rem;" role="tablist" aria-label="Shared tabs"></div>
        {{-- The pane has a definite size (set from the window); the mirror is scaled to fit inside it - never scrolled, never wider than it --}}
        <div id="cdc-pane">
            <div id="cdc-replay"></div>
            <p id="cdc-wait" class="text-sm" style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center; margin:0; color:var(--text-muted);">Waiting for the agent's window…</p>
        </div>
    </div>

    {{-- Sessions open now: a live one opens (again), a waiting one is joined by its code --}}
    <div id="cdc-sessions" class="rounded-md px-4 py-3" style="background:var(--surface); border:1px solid var(--border); margin-top:.75rem;">
        <h2 class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-muted); margin:0 0 .25rem;">Sessions open now</h2>
        <ul id="cdc-list" style="list-style:none; margin:0; padding:0; max-height:6.5rem; overflow:auto;">
            @foreach($sessions as $s)
                <li class="cdc-row" data-id="{{ $s->id }}" data-status="{{ $s->status }}" style="display:flex; gap:.75rem; align-items:center; padding:.35rem 0; border-top:1px solid var(--border); font-size:.8rem;">
                    <span style="flex:1; min-width:0;">{{ $s->agent?->name ?? 'Agent' }}@if($s->agency) · {{ $s->agency->name }}@endif —
                    {{ $s->status === 'live' ? 'live with ' . ($s->supportUser?->name ?? 'support') : 'waiting for a support person' }}</span>
                    @if($s->status === 'live')<button type="button" class="corex-btn-outline" data-open="{{ $s->id }}">Open</button>@endif
                </li>
            @endforeach
        </ul>
        <p id="cdc-list-empty" class="text-xs" style="color:var(--text-muted); margin:0;" @if($sessions->isNotEmpty()) hidden @endif>No sessions open.</p>
        @if(auth()->user()->hasPermission('manage_users'))
            <p class="text-xs" style="margin:.5rem 0 0;"><a href="{{ route('compliance.desk-sessions.index') }}" class="font-semibold" style="color:var(--brand-icon);">All past Desk sessions →</a></p>
        @endif
    </div>
</div>

<script>
    window.CorexDeskConsole = {!! json_encode($consoleConfig) !!};
</script>
@vite(['resources/js/desk/console.js'])
@endsection
