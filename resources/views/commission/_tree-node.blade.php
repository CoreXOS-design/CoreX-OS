@php
    $nodeId = 'tree-' . $node['id'];
    $hasChildren = !empty($node['children']);
    $indent = $depth * 24;
@endphp

<div>
    <div class="flex items-center gap-2 py-1.5 px-2 rounded-md transition-colors hover:bg-white/5"
         style="padding-left:{{ $indent + 8 }}px;">

        @if($hasChildren)
        <button type="button"
                @click="expandedNodes['{{ $nodeId }}'] = !expandedNodes['{{ $nodeId }}']"
                class="flex-shrink-0 w-5 h-5 flex items-center justify-center rounded transition-colors"
                style="color:var(--text-muted);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                 class="w-3 h-3 transition-transform duration-150"
                 :class="expandedNodes['{{ $nodeId }}'] && 'rotate-90'">
                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
            </svg>
        </button>
        @else
        <span class="flex-shrink-0 w-5 h-5 flex items-center justify-center">
            <span class="w-1.5 h-1.5 rounded-full" style="background:var(--border);"></span>
        </span>
        @endif

        <div class="w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold flex-shrink-0"
             style="background:rgba(14,165,233,0.12); color:#0ea5e9;">
            {{ collect(explode(' ', $node['name']))->map(fn($w) => strtoupper(substr($w, 0, 1)))->take(2)->join('') }}
        </div>

        <div class="flex-1 min-w-0">
            <span class="text-sm font-medium" style="color:var(--text-primary);">{{ $node['name'] }}</span>
        </div>

        <div class="flex items-center gap-3 flex-shrink-0">
            <span class="text-xs" style="color:var(--text-secondary);">R {{ number_format($node['gci_month'], 2) }}</span>
            @if($node['is_capped'])
                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold" style="background:rgba(245,158,11,0.15); color:#f59e0b;">CAPPED</span>
            @endif
        </div>
    </div>

    @if($hasChildren)
    <div x-show="expandedNodes['{{ $nodeId }}']" x-cloak
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
        @foreach($node['children'] as $child)
            @include('commission._tree-node', ['node' => $child, 'depth' => $depth + 1])
        @endforeach
    </div>
    @endif
</div>
