{{-- Quick Link — a status chip plus (optionally) the one action that moves it forward. Spec: .ai/specs/quick-links.md §3.
     The chip colour is the state; the slot is where the caller puts its button/pop-up trigger (already permission-gated).
     Params: tone (success|warning|danger|info|default), label, title (hover text), href (optional: makes the chip itself a link). --}}
@props(['tone' => 'default', 'label', 'title' => null, 'href' => null])
<span {{ $attributes->class(['inline-flex items-center gap-1.5 flex-wrap align-middle']) }}>
    @if($href)
        <a href="{{ $href }}" class="ds-badge ds-badge-{{ $tone }} no-underline" title="{{ $title }}" style="text-transform:none; letter-spacing:0; font-size:0.75rem;">{{ $label }}</a>
    @else
        <span class="ds-badge ds-badge-{{ $tone }}" title="{{ $title }}" style="text-transform:none; letter-spacing:0; font-size:0.75rem;">{{ $label }}</span>
    @endif
    {{ $slot }}
</span>
