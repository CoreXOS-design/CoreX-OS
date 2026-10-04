{{--
    Shared print-list header strip: active filters + scope, so a printed
    list is self-describing (the reader can't see the on-screen filter
    form). $printFilters is a flat ['Label' => 'value', ...] array built
    by the calling controller — only non-empty entries render.
--}}
<div style="font-size: 11px; color: #555; margin-bottom: 10px;">
    Printed {{ now()->format('Y-m-d H:i') }}
    @if(!empty($printFilters))
        &mdash;
        @foreach($printFilters as $label => $value)
            {{ $label }}: <strong>{{ $value }}</strong>@if(!$loop->last), @endif
        @endforeach
    @else
        &mdash; no filters applied
    @endif
</div>
