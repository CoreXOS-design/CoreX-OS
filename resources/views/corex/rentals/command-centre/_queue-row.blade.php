{{--
    One needs-action queue row — shared by the flat list and every group
    (RentalCommandCentreController::buildViewData() / 2026-10-05 fix round,
    B1). Kept as a single partial so the flat and grouped renderings can
    never drift apart.

    Expects: $item (one entry from RentalCommandCentreService::queueItems()),
    $index (running position across the WHOLE queue — drives the narrow-
    screen "first 5 only" clamp, same as before this fix), optional
    $hidePropertyLine (true when grouped by property — the group heading
    already names the property, so the per-row line would just repeat it).
--}}
@php($hideOnNarrow = ($index ?? 0) >= 5)
<div class="px-3 py-2 flex items-center justify-between gap-2 {{ $hideOnNarrow ? 'hidden lg:flex' : '' }}" style="border-bottom: 1px solid var(--border);{{ ($item['informational'] ?? false) ? ' opacity: .7;' : '' }}" @if($item['informational'] ?? false) data-queue-info @endif data-queue-type="{{ $item['type'] }}">
    <div class="min-w-0">
        <div class="text-xs font-medium truncate">{{ $item['detail'] }}{{ $item['age_days'] > 0 ? ' · ' . $item['age_days'] . 'd' : '' }}</div>
        @unless($hidePropertyLine ?? false)
        <div class="text-[11px] truncate" style="color: var(--text-muted);">{{ $item['property']?->buildDisplayAddress() ?? 'Unknown property' }}{{ $item['property']?->trashed() ? ' (archived)' : '' }}</div>
        @endunless
    </div>
    {{-- Gated by the same agency feature switch as the table's Actions menu, so a switched-off module
         never shows a button that can only 404. The route's own permission middleware still decides access. --}}
    @php($queueFeature = str_starts_with($item['route'], 'corex.rental-fault-reports.') ? 'rental-faults'
        : (str_starts_with($item['route'], 'corex.rental-job-cards.') ? 'rental-job-cards'
        : (str_starts_with($item['route'], 'corex.rental-work-orders.') ? 'rental-work-orders'
        : (str_starts_with($item['route'], 'corex.rental-inspections.') ? 'rental-inspections' : 'rental-leases'))))
    @feature($queueFeature)
    @if(app(\App\Services\Rentals\RentalCommandCentreService::class)->canOpenRoute(auth()->user(), $item['route']))
    <a href="{{ route($item['route'], $item['route_params']) }}" class="corex-btn-outline text-[11px] px-2 py-1 flex-shrink-0" @if($item['informational'] ?? false) style="font-style: italic;" @endif>{{ $item['label'] }}</a>
    @endif
    @endfeature
</div>
