{{-- Template Manager — two tabs: property templates (ad-manager.md §19) and company ads (.ai/specs/company-ads.md §3).
     Shown to anyone with access_ad_manager; what each tab lets you DO is gated on its own page. --}}
@php
    $tabs = [
        ['route' => 'tools.ad-manager.templates',         'label' => 'Property templates'],
        ['route' => 'tools.ad-manager.templates.company', 'label' => 'Company ads'],
    ];
@endphp
<nav class="flex items-center gap-1 border-b" style="border-color:var(--border);" aria-label="Template type">
    @foreach($tabs as $tab)
        @php $active = request()->routeIs($tab['route']); @endphp
        <a href="{{ route($tab['route']) }}" @if($active) aria-current="page" @endif
           class="px-4 py-2 text-sm font-semibold -mb-px border-b-2 transition-colors duration-150"
           style="{{ $active ? 'border-color:var(--brand-icon,#0ea5e9); color:var(--brand-icon,#0ea5e9);' : 'border-color:transparent; color:var(--text-secondary);' }}">
            {{ $tab['label'] }}
        </a>
    @endforeach
</nav>
