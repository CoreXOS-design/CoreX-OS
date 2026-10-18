{{-- AT-451 — the compact "N users have all alerts off" line, shown to people with manage_users on the Rental Command Centre
     and the user list. Renders nothing when the count is zero or the viewer may not manage users. Counts only the users
     the viewer may see (own / branch / agency, scoped in AlertSwitchService::scopedUsers). --}}
@php
    $__alertsViewer = auth()->user();
    $__alertsOffCount = ($__alertsViewer && $__alertsViewer->hasPermission('manage_users'))
        ? app(\App\Services\CommandCenter\AlertSwitchService::class)->allOffCount($__alertsViewer)
        : 0;
@endphp
@if($__alertsOffCount > 0)
    <div data-alerts-off-line>
        <a href="{{ route('corex.alerts-off.index') }}"
           class="inline-flex items-center gap-2 text-xs font-medium rounded-md px-3 py-1.5"
           style="border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 45%, transparent); background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, transparent); color: var(--text-primary);">
            <span>{{ number_format($__alertsOffCount) }} {{ $__alertsOffCount === 1 ? 'user has' : 'users have' }} all alerts off</span>
            <span aria-hidden="true">&rarr;</span>
        </a>
    </div>
@endif
