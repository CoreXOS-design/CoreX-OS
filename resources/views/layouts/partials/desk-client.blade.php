{{-- ════════════════════════════════════════════════════════════════════════
     COREX DESK — the agent-side client (spec .ai/specs/corex-desk.md sections 3 and 7.5).

     Included by BOTH layouts (corex.blade.php, corex-app.blade.php) right under the environment banners. It renders:
       · the red "CoreX Desk — {name} is viewing this window" bar (a flex child of the environment column, like the env banner, so
         it pushes the page down instead of covering it — hidden, and therefore zero layout shift, until support has joined);
       · the Get help screen (6-digit code) — opened by any [data-desk-open] button (the sidebar's top bar carries one);
       · support's labelled "Support" cursor;
       · window.CorexDesk — the config the Desk JS needs (Reverb key/host from config, never from a build-time variable).
     Everything Desk draws carries .rr-block so it is never part of what support sees. The JS (resources/js/desk/index.js) is a tiny
     loader; Echo and rrweb are only fetched once a session exists in this tab.
     ════════════════════════════════════════════════════════════════════════ --}}
@auth
@php
    $__deskUser = auth()->user();
    $__deskCanRequest = $__deskUser->hasPermission('desk.request_help');
    $__deskCanSupport = $__deskUser->hasPermission('desk.support');
@endphp
@if($__deskCanRequest || $__deskCanSupport)
@php
    $__deskSvc = app(\App\Services\Desk\DeskSessionService::class);
    $__deskReverbKey = config('reverb.apps.apps.0.key');
    $__deskPublic = config('reverb.public');
    // Desk needs the agency switch ON *and* a live-connection server configured on this box; otherwise Get help shows the phone line.
    $__deskEnabled = $__deskSvc->agencyAllowsDesk($__deskUser->effectiveAgencyId())
        && filled($__deskReverbKey) && filled($__deskPublic['host'] ?? null);
    $__deskCurrent = ($__deskCanRequest && $__deskEnabled) ? $__deskSvc->openFor($__deskUser)?->load('agent', 'agency', 'supportUser') : null;
    $__deskConfig = [
        'canRequest' => $__deskCanRequest,
        'enabled' => $__deskEnabled,
        'phone' => \App\Http\Controllers\Api\V1\DeskSessionController::supportPhone(),
        'csrf' => csrf_token(),
        'userId' => $__deskUser->id,
        'heartbeatSeconds' => (int) config('desk.heartbeat_seconds', 30),
        'reverb' => $__deskEnabled ? [
            'key' => $__deskReverbKey,
            'host' => $__deskPublic['host'],
            'port' => $__deskPublic['port'],
            'scheme' => $__deskPublic['scheme'],
        ] : null,
        'urls' => ['sessions' => url('api/v1/desk/sessions')],
        'current' => $__deskCurrent?->toClientArray(),
    ];
@endphp
<script>window.CorexDesk = @json($__deskConfig);</script>
@if($__deskCanRequest)
<style>
    #corex-desk-bar { flex: 0 0 auto; display: flex; align-items: center; justify-content: center; gap: .75rem; padding: .25rem .75rem; background: #b91c1c; color: #fff; font-size: .75rem; font-weight: 600; line-height: 1.25rem; }
    #corex-desk-bar[hidden], #corex-desk-modal[hidden], #corex-desk-pointer[hidden] { display: none; }
    #corex-desk-bar button { background: rgba(255,255,255,.18); color: #fff; border: 1px solid rgba(255,255,255,.55); border-radius: .25rem; padding: 0 .6rem; font-size: .7rem; font-weight: 700; cursor: pointer; }
    #corex-desk-bar button:hover { background: rgba(255,255,255,.3); }
    #corex-desk-modal { position: fixed; inset: 0; z-index: 100001; display: flex; align-items: center; justify-content: center; background: rgba(11,42,74,.55); }
    #corex-desk-modal .cd-card { width: min(26rem, calc(100vw - 2rem)); background: var(--surface, #fff); color: var(--text-primary, #111827); border: 1px solid var(--border, rgba(0,0,0,.1)); border-radius: .5rem; padding: 1.5rem; box-shadow: 0 20px 50px -12px rgba(0,0,0,.35); text-align: center; }
    #corex-desk-modal .cd-code { font-size: 2.5rem; font-weight: 800; letter-spacing: .25em; font-variant-numeric: tabular-nums; margin: .75rem 0 .25rem; color: var(--brand-icon, #0ea5e9); }
    #corex-desk-modal .cd-btn { margin-top: 1rem; padding: .45rem 1rem; border-radius: .375rem; border: 1px solid var(--border, rgba(0,0,0,.15)); background: var(--surface-2, #f3f4f6); color: var(--text-primary, #111827); font-size: .8rem; font-weight: 600; cursor: pointer; }
    #corex-desk-modal [data-state-only] { display: none; }
    #corex-desk-modal[data-state="waiting"] [data-state-only~="waiting"],
    #corex-desk-modal[data-state="off"] [data-state-only~="off"],
    #corex-desk-modal[data-state="error"] [data-state-only~="error"],
    #corex-desk-modal[data-state="creating"] [data-state-only~="creating"] { display: block; }
    #corex-desk-pointer { position: fixed; z-index: 100002; pointer-events: none; transform: translate(-2px, -2px); }
    #corex-desk-pointer span { display: inline-block; margin: 0 0 0 14px; padding: 0 .4rem; border-radius: .25rem; background: #b91c1c; color: #fff; font-size: .65rem; font-weight: 700; }
    .corex-desk-popover { max-width: 18rem; }
</style>

<div id="corex-desk-bar" class="rr-block" role="status" hidden>
    <span>CoreX Desk — <span id="cd-bar-name">CoreX support</span> is viewing this window</span>
    <button type="button" data-desk-end>End session</button>
</div>

<div id="corex-desk-modal" class="rr-block" role="dialog" aria-modal="true" aria-labelledby="cd-title" data-state="creating" hidden>
    <div class="cd-card">
        <h2 id="cd-title" class="text-base font-bold" style="margin:0;">CoreX Desk</h2>
        <div data-state-only="waiting">
            <div id="cd-code" class="cd-code" aria-live="polite"></div>
            <p class="text-sm" style="margin:.25rem 0 0; color:var(--text-secondary,#4b5563);">Read this code to CoreX support. They will see only this CoreX window.</p>
            <p id="cd-expires" class="text-xs" style="margin:.5rem 0 0; color:var(--text-muted,#6b7280);"></p>
            <button type="button" class="cd-btn" data-desk-cancel>Cancel</button>
        </div>
        <div data-state-only="creating">
            <p id="cd-creating" class="text-sm" style="margin:1rem 0 0; color:var(--text-secondary,#4b5563);">Getting your code…</p>
        </div>
        <div data-state-only="off error">
            <p id="cd-message" class="text-sm" style="margin:1rem 0 0; color:var(--text-primary,#111827);"></p>
            <button type="button" class="cd-btn" data-desk-close>Close</button>
        </div>
    </div>
</div>

<div id="corex-desk-pointer" class="rr-block" hidden>
    <svg width="18" height="18" viewBox="0 0 24 24" fill="#b91c1c" stroke="#fff" stroke-width="1.5" aria-hidden="true" style="position:absolute;left:0;top:0;"><path d="M4 3l16 7-7 2-2 7z"/></svg><span>Support</span>
</div>

{{-- The Guided Tour's own driver.js stylesheet + CoreX popover theme, so support's highlight looks exactly like a tour step on every page. --}}
@include('layouts.partials.tour-theme')

@vite(['resources/js/desk/index.js'])
@endif
@endif
@endauth
