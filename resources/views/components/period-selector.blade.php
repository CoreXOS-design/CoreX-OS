{{--
    Shared month picker — the ONE way a single-month screen asks "which month?".
    Class: App\View\Components\PeriodSelector (resolves ?period=, carries other query values).

    Usage:
      <x-period-selector />                                  free month input, current month default
      <x-period-selector :months="$monthsWithFigures" />     dropdown of the months that have figures
      <x-period-selector name="month" label="Statement month" />

    The page reads the month with PeriodSelector::monthFrom(request('period')); a missing or
    invalid value is the current month. No JavaScript framework: the picker submits itself on
    change, and the Show button is the no-JS fallback.
--}}
<form method="GET" action="{{ $action ?? request()->url() }}" class="inline-flex flex-wrap items-center gap-2" data-period-selector>
    @foreach($carry as $cName => $cValue)
        <input type="hidden" name="{{ $cName }}" value="{{ $cValue }}">
    @endforeach

    <label for="period-selector-{{ $name }}" class="text-xs font-medium" style="color: var(--text-secondary);">{{ $label }}</label>

    <a href="{{ $previousUrl }}" class="inline-flex items-center justify-center h-8 w-8 rounded-md text-sm"
       style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);"
       title="Previous month" aria-label="Previous month">&lsaquo;</a>

    @if(count($months))
        <select id="period-selector-{{ $name }}" name="{{ $name }}" onchange="this.form.submit()"
                class="h-8 text-sm rounded-md px-2"
                style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">
            @foreach($months as $m)
                <option value="{{ $m }}" @selected($m === $value)>{{ $monthLabel($m) }}{{ $m === $current ? ' (this month)' : '' }}</option>
            @endforeach
        </select>
    @else
        <input id="period-selector-{{ $name }}" type="month" name="{{ $name }}" value="{{ $value }}" onchange="this.form.submit()"
               class="h-8 text-sm rounded-md px-2"
               style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">
    @endif

    @if($nextUrl)
        <a href="{{ $nextUrl }}" class="inline-flex items-center justify-center h-8 w-8 rounded-md text-sm"
           style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);"
           title="Next month" aria-label="Next month">&rsaquo;</a>
    @else
        <span class="inline-flex items-center justify-center h-8 w-8 rounded-md text-sm opacity-40 cursor-not-allowed"
              style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);"
              title="This is the latest month" aria-hidden="true">&rsaquo;</span>
    @endif

    <noscript><button type="submit" class="corex-btn-primary text-sm">Show</button></noscript>
</form>
