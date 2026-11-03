{{-- AT-534 — one sortable column header. Receives: $field, $label, $sort, $direction (current), optional $align. --}}
@php
    $isOn = $sort === $field;
    $next = $isOn && $direction === 'asc' ? 'desc' : 'asc';
@endphp
<th class="cmp-th" @if(($align ?? 'left') === 'right') style="text-align:right" @endif>
    <a href="{{ request()->fullUrlWithQuery(['sort' => $field, 'direction' => $next, 'page' => null]) }}">
        {{ $label }}<span style="opacity: {{ $isOn ? 1 : .35 }};">{{ $isOn ? ($direction === 'asc' ? '▲' : '▼') : '↕' }}</span>
    </a>
</th>
