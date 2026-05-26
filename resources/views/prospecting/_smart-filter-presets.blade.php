{{--
    Smart Filter Presets row — sits at the top of the Market Intelligence page.
    Each card is a one-click filter that scopes the listings table + aggregate tiles
    via a ?preset=X URL param. Mutually exclusive within the slot.

    Inputs:
      $presets               — array from SmartFilterPresetService::presetsFor()
      $activePreset          — current ?preset URL value (null if none)
      $isProspectingManager  — bool; gates the 'stale-claims' card
--}}

@php
    /** @var array $presets */
    /** @var ?string $activePreset */
    /** @var bool $isProspectingManager */

    // Preserve all non-preset query params when building the click URL,
    // and toggle the preset off if the same one is re-clicked.
    $existing = collect(request()->query())->except('preset')->all();
@endphp

<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
    @foreach($presets as $key => $preset)
        @if(($preset['visible_to'] ?? 'all') === 'manager' && !$isProspectingManager)
            @continue
        @endif

        @php
            $isActive = $activePreset === $key;
            $url = $isActive
                ? route('prospecting.index', $existing)
                : route('prospecting.index', array_merge($existing, ['preset' => $key]));
            $count = (int) ($preset['count'] ?? 0);
        @endphp

        <a href="{{ $url }}"
           class="block p-4 rounded-md transition no-underline"
           style="background: {{ $isActive ? 'var(--brand-button)' : 'var(--surface)' }};
                  color: {{ $isActive ? '#fff' : 'var(--text-primary)' }};
                  border: 1px solid {{ $isActive ? 'var(--brand-button)' : 'var(--border)' }};">

            <div class="flex items-baseline justify-between mb-1">
                <span class="text-2xl">{{ $preset['icon'] }}</span>
                <span class="text-2xl font-bold">{{ number_format($count) }}</span>
            </div>
            <div class="text-sm font-semibold mb-0.5">
                {{ $preset['label'] }}
                @if($isActive)
                    <span class="text-[10px] uppercase tracking-wider opacity-80 ml-1">· active · click to clear</span>
                @endif
            </div>
            <div class="text-xs leading-snug"
                 style="color: {{ $isActive ? 'rgba(255,255,255,0.85)' : 'var(--text-secondary)' }};">
                {{ $preset['description'] }}
            </div>

            @if($isActive && $count === 0)
                <div class="text-xs mt-2 pt-2"
                     style="color: rgba(255,255,255,0.85); border-top: 1px solid rgba(255,255,255,0.25);">
                    Nothing matches this preset right now — keep working, the list will populate as activity accumulates.
                </div>
            @endif
        </a>
    @endforeach
</div>
