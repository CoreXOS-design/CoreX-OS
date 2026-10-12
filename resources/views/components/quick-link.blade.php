{{-- Quick Link — a status pill plus (optionally) the one action that moves it forward. Spec: .ai/specs/quick-links.md §3.
     The look is the shared <x-pill> (same size/font/colour everywhere); the state is carried by the pill's small dot:
     success = green, warning = amber, danger = red, info = navy, default = grey. The slot is where the caller puts its
     button/pop-up trigger (already permission-gated).
     Params: tone (success|warning|danger|info|default), label, title (hover text), href (optional: makes the pill a link). --}}
@props(['tone' => 'default', 'label', 'title' => null, 'href' => null])
@php
    $__dot = match ($tone) {
        'success' => 'var(--ds-green)',
        'warning' => 'var(--ds-amber)',
        'danger'  => 'var(--ds-crimson)',
        'info'    => 'var(--ds-navy)',
        default   => 'var(--text-muted)',
    };
@endphp
<span {{ $attributes->only('class')->class(['inline-flex items-center gap-1.5 flex-wrap align-middle']) }}>
    <x-pill :label="$label" :title="$title" :href="$href" :dot="$__dot" {{ $attributes->except('class') }} />
    {{ $slot }}
</span>
