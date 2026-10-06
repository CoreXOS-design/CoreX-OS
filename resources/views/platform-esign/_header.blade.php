{{-- Platform E-Sign shared banner + tab strip. $title, $sub (optional), $actions (optional HTML), $tab. --}}
<div class="rounded-md px-6 py-5 corex-page-banner">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-white leading-tight">{{ $title }}</h1>
            @isset($sub)<p class="text-sm text-white/60">{{ $sub }}</p>@endisset
        </div>
        @isset($actions)<div class="flex flex-wrap items-center gap-2">{!! $actions !!}</div>@endisset
    </div>
</div>
@php
    $tabs = [
        'hub'       => ['Overview',  route('platform-esign.hub')],
        'documents' => ['Documents', route('platform-esign.documents.index')],
        'templates' => ['Templates', route('platform-esign.templates.index')],
        'send'      => ['Send a contract', route('platform-esign.documents.create')],
    ];
@endphp
<div class="flex flex-wrap gap-1" style="border-bottom: 1px solid var(--border);">
    @foreach($tabs as $k => [$label, $href])
        <a href="{{ $href }}" class="px-4 py-2 text-sm font-medium -mb-px"
           style="{{ ($tab ?? '') === $k ? 'color: var(--brand-icon); border-bottom: 2px solid var(--brand-icon);' : 'color: var(--text-muted); border-bottom: 2px solid transparent;' }}">{{ $label }}</a>
    @endforeach
</div>
@include('admin.partials.platform-flash')
