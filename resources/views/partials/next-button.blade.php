{{--
    .ai/specs/rentals-faults-work-orders.md section 31 - ONE Next-bar button, drawn from an App\Services\Rentals\NextAction descriptor.
    $b (descriptor|null), $primary (bool, default true). The button IS the action: a real submit, a real link to another record, or a dialog opened where the agent is.
    There is no scroll-to-another-panel and no hidden-control press any more.
--}}
@php
    $nbPrimary = $primary ?? true;
    $nbClass = $nbPrimary ? 'corex-btn-primary text-xs' : 'corex-btn-outline text-xs';
    $nbAttr = $nbPrimary ? 'data-next-button' : 'data-next-secondary';
@endphp
@if($b)
    @switch($b['kind'])
        @case('note')
            <span class="text-xs font-semibold" style="color: var(--text-muted);" data-ask-branch-manager>{{ $b['label'] }}</span>
            @break
        @case('link')
            <a href="{{ $b['href'] }}" class="{{ $nbClass }}" {{ $nbAttr }}>{{ $b['label'] }}</a>
            @break
        @case('form')
            <button type="submit" form="{{ $b['form'] }}" class="{{ $nbClass }}" {{ $nbAttr }}>{{ $b['label'] }}</button>
            @break
        @case('submit')
            <button type="submit" form="{{ $b['form'] }}" formaction="{{ $b['action'] }}" class="{{ $nbClass }}" {{ $nbAttr }}>{{ $b['label'] }}</button>
            @break
        @case('post')
            <form method="POST" action="{{ $b['action'] }}" class="inline" @if(! empty($b['confirm'])) data-confirm="{{ $b['confirm'] }}" @endif>
                @csrf
                @foreach($b['fields'] as $nbName => $nbValue)<input type="hidden" name="{{ $nbName }}" value="{{ $nbValue }}">@endforeach
                <button type="submit" class="{{ $nbClass }}" {{ $nbAttr }}>{{ $b['label'] }}</button>
            </form>
            @break
        @case('focus')
            <a href="{{ $b['target'] }}" class="{{ $nbClass }}" {{ $nbAttr }} data-jc-go @if(! empty($b['focus'])) data-jc-focus="{{ $b['focus'] }}" @endif>{{ $b['label'] }}</a>
            @break
        @case('popup')
            <button type="button" class="{{ $nbClass }}" {{ $nbAttr }} @foreach($b['attrs'] as $nbA => $nbV) {{ $nbA }}="{{ $nbV }}" @endforeach>{{ $b['label'] }}</button>
            @break
        @case('open')
            <button type="button" class="{{ $nbClass }}" {{ $nbAttr }} data-next-open="{{ $b['dialog'] }}"
                    onclick="window.dispatchEvent(new CustomEvent('corex-open', { detail: '{{ $b['dialog'] }}' }))">{{ $b['label'] }}</button>
            @break
    @endswitch
@endif
