{{-- AT-451 — the one-line warning bar for a staff user whose in-app, email AND push alerts are all switched off.
     "Turn alerts on" puts in-app alerts back on (one click); "Keep them off" records the decision and hides the bar for
     that user until their notification settings are next changed. Both act on the logged-in user only. Where the agency
     sets the switches for everyone, only "Keep them off" is offered (a per-person switch would do nothing). --}}
@auth
@php
    $__alertBar = app(\App\Services\CommandCenter\AlertSwitchService::class)->barStateFor(auth()->user());
@endphp
@if($__alertBar)
    <div role="alert" data-alerts-off-bar class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-sm"
         style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 16%, var(--surface, #fff)); border-bottom: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 55%, transparent); color: var(--text-primary);">
        <span class="flex-1 min-w-0 font-medium">Your alerts are switched off - you will not be told about new faults, leads or approvals.</span>
        @unless($__alertBar['locked'])
            <form method="POST" action="{{ route('corex.alerts.bar.turn-on') }}" class="m-0">
                @csrf
                <button type="submit" class="corex-btn-primary text-xs">Turn alerts on</button>
            </form>
        @endunless
        <form method="POST" action="{{ route('corex.alerts.bar.keep-off') }}" class="m-0">
            @csrf
            <button type="submit" class="corex-btn-outline text-xs">Keep them off</button>
        </form>
    </div>
@endif
@endauth
