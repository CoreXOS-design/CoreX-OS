{{-- Pill — THE one small status/label pill used across the contact page header (and by <x-quick-link>), so every pill is the
     same size, font and colour. Neutral surface; the only colour is an optional dot that carries the state.
     Johan 2026-10-12: "all the pills need to be the same size and font size… keep them all the same colours".
     Params: label, title (hover), href (optional — makes it a link), dot (optional CSS colour for the state dot). --}}
@props(['label', 'title' => null, 'href' => null, 'dot' => null])
@php
    $__pillStyle = 'display:inline-flex; align-items:center; gap:0.375rem; padding:0 0.625rem; height:1.5rem; border-radius:9999px;'
        . ' font-size:0.75rem; font-weight:600; line-height:1; white-space:nowrap; text-decoration:none;'
        . ' background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);';
@endphp
@if($href)
<a href="{{ $href }}" title="{{ $title }}" {{ $attributes }} style="{{ $__pillStyle }}">
@else
<span title="{{ $title }}" {{ $attributes }} style="{{ $__pillStyle }}">
@endif
    @if($dot)<span aria-hidden="true" style="width:0.5rem; height:0.5rem; border-radius:9999px; flex-shrink:0; background:{{ $dot }};"></span>@endif
    {{ $label }}
@if($href)
</a>
@else
</span>
@endif
