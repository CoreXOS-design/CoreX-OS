{{-- Sortable column header — produces a clickable <th> that toggles asc/desc --}}
@props([
    'field',
    'label',
    'align' => 'left',
    'currentSort' => null,
    'currentDirection' => null,
])

@php
    $sort = $currentSort ?? request('sort');
    $dir  = $currentDirection ?? request('direction', 'asc');
    $isActive = $sort === $field;
    $nextDir = ($isActive && $dir === 'asc') ? 'desc' : 'asc';
    $url = request()->fullUrlWithQuery(['sort' => $field, 'direction' => $nextDir]);
@endphp

<th class="text-{{ $align }} px-4 py-3">
    <a href="{{ $url }}" class="inline-flex items-center gap-1 text-xs font-semibold uppercase tracking-wider group" style="color:var(--text-muted)" onmouseover="this.style.color='var(--text-secondary)'" onmouseout="this.style.color='var(--text-muted)'">
        {{ $label }}
        @if($isActive)
            <svg class="w-3 h-3" style="color:var(--text-secondary)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                @if($dir === 'asc')
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>
                @else
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                @endif
            </svg>
        @else
            <svg class="w-3 h-3" style="color:var(--text-faint)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4M16 15l-4 4-4-4"/>
            </svg>
        @endif
    </a>
</th>
