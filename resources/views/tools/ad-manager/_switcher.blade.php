{{-- Ad Manager pill switcher — same look as the PDF Suite switcher
     (tools/pdf-suite/_switcher.blade.php). Spec: ad-manager.md §19 / §20.

     Deliberately theme-variable styled (surface / border / brand-icon), NEVER white-on-navy:
     page headers are a flat neutral bar (`.corex-page-banner`, corex.css §8) and that CSS
     force-restyles any inline rgba(255,255,255,…) inside it, which is what broke the tabs
     when they were first put in the header. Pass ['inHeader' => true] to top-align it there. --}}
@php
    $inHeader = $inHeader ?? false;
    $pills = [
        ['route' => 'tools.ad-manager',           'label' => 'Ad Manager',       'match' => 'tools.ad-manager'],
        ['route' => 'tools.ad-manager.templates', 'label' => 'Template Manager', 'match' => 'tools.ad-manager.templates*'],
    ];
@endphp
<div class="rounded-md p-1.5 flex flex-wrap items-center justify-center gap-1 overflow-x-auto {{ $inHeader ? 'self-start md:self-auto' : '' }}"
     style="background: var(--surface); border: 1px solid var(--border);">
    @foreach($pills as $p)
        @if(\Illuminate\Support\Facades\Route::has($p['route']))
            @php $active = request()->routeIs($p['match']); @endphp
            <a href="{{ route($p['route']) }}"
               @if($active) aria-current="page" @endif
               class="text-xs font-semibold px-3.5 py-1.5 rounded-md transition-all duration-150 whitespace-nowrap"
               style="@if($active) background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9); box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--brand-icon, #0ea5e9) 40%, transparent); @else background: transparent; color: var(--text-secondary); @endif"
               @if(!$active) onmouseover="this.style.background='var(--surface-2)'; this.style.color='var(--text-primary)';"
                             onmouseout="this.style.background='transparent'; this.style.color='var(--text-secondary)';" @endif>
                {{ $p['label'] }}
            </a>
        @endif
    @endforeach
</div>
