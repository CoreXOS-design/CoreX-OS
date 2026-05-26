@php
    $pills = [
        ['route' => 'tools.pdf_suite.hub',          'label' => 'Hub'],
        ['route' => 'tools.pdf_splitter.index',     'label' => 'Split'],
        ['route' => 'tools.pdf_suite.compress',     'label' => 'Compress'],
        ['route' => 'tools.pdf_suite.merge',        'label' => 'Merge'],
        ['route' => 'tools.pdf_suite.image-to-pdf', 'label' => 'Image → PDF'],
        ['route' => 'tools.pdf_suite.rotate',       'label' => 'Rotate'],
        ['route' => 'tools.pdf_suite.reorder',      'label' => 'Reorder'],
        ['route' => 'tools.pdf_suite.protect',      'label' => 'Protect'],
        ['route' => 'tools.pdf_suite.redact',       'label' => 'Redact'],
    ];
@endphp
<div class="rounded-md p-1.5 flex flex-wrap items-center justify-center gap-1 overflow-x-auto"
     style="background: var(--surface); border: 1px solid var(--border);">
    @foreach($pills as $p)
        @if(\Illuminate\Support\Facades\Route::has($p['route']))
            @php
                $active = request()->routeIs($p['route']);
            @endphp
            <a href="{{ route($p['route']) }}"
               class="text-xs font-semibold px-3.5 py-1.5 rounded-md transition-all duration-150 whitespace-nowrap"
               style="@if($active) background: var(--brand-button, #0ea5e9); color: #fff; @else background: transparent; color: var(--text-secondary); @endif"
               @if(!$active) onmouseover="this.style.background='var(--surface-2)'; this.style.color='var(--text-primary)';"
                             onmouseout="this.style.background='transparent'; this.style.color='var(--text-secondary)';" @endif>
                {{ $p['label'] }}
            </a>
        @endif
    @endforeach
</div>
