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
<div class="px-3 py-2 flex items-center justify-between gap-2 {{ $hideOnNarrow ? 'hidden lg:flex' : '' }}" style="border-bottom: 1px solid var(--border);">
    <div class="min-w-0">
        <div class="text-xs font-medium truncate">{{ $item['detail'] }}{{ $item['age_days'] > 0 ? ' · ' . $item['age_days'] . 'd' : '' }}</div>
        @unless($hidePropertyLine ?? false)
        <div class="text-[11px] truncate" style="color: var(--text-muted);">{{ $item['property']?->buildDisplayAddress() ?? 'Unknown property' }}</div>
        @endunless
    </div>
    <a href="{{ route($item['route'], $item['route_params']) }}" class="corex-btn-outline text-[11px] px-2 py-1 flex-shrink-0">{{ $item['label'] }}</a>
</div>
