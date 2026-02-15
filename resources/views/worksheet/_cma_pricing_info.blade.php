@if(($cmaCount ?? 0) > 0)
    @php
        $cp = (int)($cmaCorrectlyPricedPercent ?? 0);
        $op = (int)($cmaOverpricedPercent ?? 0);
    @endphp

    <div class="mt-2 text-xs text-gray-600 bg-gray-50 border rounded p-2">
        CMA Coverage: {{ $cmaCount }} listings |
        Overpriced: {{ $op }}% |
        Correctly Priced: {{ $cp }}%
    </div>
@endif
