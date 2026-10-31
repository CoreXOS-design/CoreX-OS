{{-- AT-478 §12.6 — under a switched-on feature: how many of the people with access have opened it and taken its
     guided tour, and who has not yet. Expects $s = FeatureRolloutUsage::stats()[feature]. --}}
@php
    $people = $s['access'];
    $notOpened = $people->reject(fn ($u) => in_array($u->id, $s['opened'], true));
    $notToured = $s['toured'] === null ? collect() : $people->reject(fn ($u) => in_array($u->id, $s['toured'], true));
@endphp
@if ($people->isNotEmpty())
    <details class="w-full text-xs" style="color: var(--text-secondary);" data-usage>
        <summary class="cursor-pointer">
            Opened by {{ count($s['opened']) }} of {{ $people->count() }}
            @if ($s['toured'] !== null) · Tour taken by {{ count($s['toured']) }} of {{ $people->count() }} @endif
        </summary>
        <div class="mt-1 space-y-0.5 pl-3">
            <div>Not opened yet: {{ $notOpened->isEmpty() ? 'everyone has' : $notOpened->map(fn ($u) => $u->name ?: $u->email)->implode(', ') }}</div>
            @if ($s['toured'] !== null)
                <div>Tour not taken yet: {{ $notToured->isEmpty() ? 'everyone has' : $notToured->map(fn ($u) => $u->name ?: $u->email)->implode(', ') }}</div>
            @endif
        </div>
    </details>
@endif
