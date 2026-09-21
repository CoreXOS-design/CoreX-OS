{{-- Ad Manager pill switcher — same look as the PDF Suite switcher
     (tools/pdf-suite/_switcher.blade.php). Spec: ad-manager.md §19 / §20.
     Pass ['onBanner' => true] to render it on the branded page header. --}}
@php
    $onBanner = $onBanner ?? false;
    $pills = [
        ['route' => 'tools.ad-manager',           'label' => 'Ad Manager',       'match' => 'tools.ad-manager'],
        ['route' => 'tools.ad-manager.templates', 'label' => 'Template Manager', 'match' => 'tools.ad-manager.templates*'],
    ];
    $wrapStyle = $onBanner
        ? 'background: rgba(255,255,255,.10); border: 1px solid rgba(255,255,255,.18);'
        : 'background: var(--surface); border: 1px solid var(--border);';
@endphp
<div class="rounded-md p-1.5 flex flex-wrap items-center justify-center gap-1 overflow-x-auto {{ $onBanner ? 'self-start md:self-auto' : '' }}"
     style="{{ $wrapStyle }}">
    @foreach($pills as $p)
        @if(\Illuminate\Support\Facades\Route::has($p['route']))
            @php
                $active = request()->routeIs($p['match']);
                if ($onBanner) {
                    $on  = 'background: #ffffff; color: #0b2a4a;';
                    $off = 'background: transparent; color: rgba(255,255,255,.85);';
                    $hoverIn  = "this.style.background='rgba(255,255,255,.14)'; this.style.color='#ffffff';";
                    $hoverOut = "this.style.background='transparent'; this.style.color='rgba(255,255,255,.85)';";
                } else {
                    $on  = 'background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9); box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--brand-icon, #0ea5e9) 40%, transparent);';
                    $off = 'background: transparent; color: var(--text-secondary);';
                    $hoverIn  = "this.style.background='var(--surface-2)'; this.style.color='var(--text-primary)';";
                    $hoverOut = "this.style.background='transparent'; this.style.color='var(--text-secondary)';";
                }
            @endphp
            <a href="{{ route($p['route']) }}"
               class="text-xs font-semibold px-3.5 py-1.5 rounded-md transition-all duration-150 whitespace-nowrap"
               style="{{ $active ? $on : $off }}"
               @if(!$active) onmouseover="{{ $hoverIn }}" onmouseout="{{ $hoverOut }}" @endif>
                {{ $p['label'] }}
            </a>
        @endif
    @endforeach
</div>
