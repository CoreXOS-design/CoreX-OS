{{-- Article Library (AT-456 Part C) — owner tab strip. Same look as the Ad Manager pill switcher. --}}
@php
    $tabs = [
        ['route' => 'admin.article-library.index',    'label' => 'Sources',  'match' => 'admin.article-library.index'],
        ['route' => 'admin.article-library.articles', 'label' => 'Articles', 'match' => 'admin.article-library.articles*'],
        ['route' => 'admin.article-library.settings', 'label' => 'Settings', 'match' => 'admin.article-library.settings*'],
    ];
@endphp
<div class="rounded-md p-1.5 inline-flex flex-wrap items-center gap-1" style="background: var(--surface); border: 1px solid var(--border);">
    @foreach($tabs as $t)
        @php $active = request()->routeIs($t['match']); @endphp
        <a href="{{ route($t['route']) }}" @if($active) aria-current="page" @endif
           class="text-xs font-semibold px-3.5 py-1.5 rounded-md whitespace-nowrap"
           style="@if($active) background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9); box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--brand-icon, #0ea5e9) 40%, transparent); @else background: transparent; color: var(--text-secondary); @endif">
            {{ $t['label'] }}
        </a>
    @endforeach
</div>
