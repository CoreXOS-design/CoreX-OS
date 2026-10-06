{{-- Platform E-Sign shared banner + module sidebar. $title, $sub (optional), $actions (optional HTML), $tab.
     This partial OPENS .pe-shell and .pe-main; every page closes them with @include('platform-esign._end')
     just before its own closing </div>. Spec: .ai/specs/agency-timeline-and-platform-esign.md §3A. --}}
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
    $peIcons = [
        'home'  => '<path d="M3 11l9-8 9 8M5 10v10h14V10"/>',
        'doc'   => '<path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        'tpl'   => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
        'send'  => '<path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"/>',
        'pen'   => '<path d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z"/>',
        'book'  => '<path d="M4 4h12a3 3 0 013 3v13H7a3 3 0 01-3-3z"/><path d="M4 17a3 3 0 013-3h12"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    ];
    // key => [label, url, icon]. Keys match the $tab each page passes.
    $peNav = [
        'Contracts' => [
            'hub'       => ['Overview', route('platform-esign.hub'), 'home'],
            'documents' => ['Documents', route('platform-esign.documents.index'), 'doc'],
            'templates' => ['Templates', route('platform-esign.templates.index'), 'tpl'],
        ],
        'Send' => [
            'agreement' => ['Subscription Agreement', route('platform-esign.agreements.create'), 'send'],
            'send'      => ['Another contract', route('platform-esign.documents.create'), 'pen'],
        ],
        'Setup' => [
            'wording'  => ['Agreement wording', route('platform-esign.wording.index'), 'book'],
            'timeline' => ['Agency Timeline', route('admin.agency-timelines.index'), 'clock'],
        ],
    ];
@endphp
<style>
    .pe-shell { display: grid; grid-template-columns: 14.5rem minmax(0, 1fr); gap: 1.25rem; align-items: start; }
    .pe-nav { position: sticky; top: 1rem; display: flex; flex-direction: column; gap: 2px; padding: 6px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; }
    .pe-nav a { display: flex; align-items: center; gap: .6rem; padding: .5rem .7rem; border-radius: 6px; border-left: 3px solid transparent; font-size: .8125rem; font-weight: 500; color: var(--text-secondary); text-decoration: none; white-space: nowrap; }
    .pe-nav a:hover { background: var(--surface-2); color: var(--text-primary); }
    .pe-nav a.pe-on { background: color-mix(in srgb, var(--brand-icon) 12%, transparent); border-left-color: var(--brand-icon); color: var(--text-primary); font-weight: 600; }
    .pe-nav svg { width: 16px; height: 16px; flex: none; stroke: var(--brand-icon); fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .pe-nav .pe-grp { font-size: .65625rem; text-transform: uppercase; letter-spacing: .08em; font-weight: 600; color: var(--text-muted); padding: .75rem .7rem .25rem; }
    .pe-nav .pe-grp:first-child { padding-top: .35rem; }
    .pe-main { min-width: 0; }
    .pe-tiles { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; }
    .pe-tile { display: flex; flex-direction: column; gap: 4px; padding: .9rem 1rem; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; text-decoration: none; transition: border-color 150ms; }
    .pe-tile:hover { border-color: var(--border-hover); }
    .pe-num { font-size: 1.625rem; font-weight: 700; line-height: 1.1; font-variant-numeric: tabular-nums; color: var(--text-primary); }
    .pe-lab { display: flex; align-items: center; font-size: .75rem; color: var(--text-muted); }
    .pe-dot { width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; flex: none; }
    .pe-row { display: flex; align-items: center; gap: .75rem; padding: .75rem 1.1rem; border-top: 1px solid var(--border); text-decoration: none; }
    .pe-row:hover { background: var(--surface-2); }
    .pe-row .pe-col { flex: 1; min-width: 0; }
    .pe-end { display: flex; flex-direction: column; align-items: flex-end; gap: 5px; flex: none; }
    .pe-pill { display: inline-flex; align-items: center; gap: 5px; font-size: .6875rem; font-weight: 600; border-radius: 999px; padding: 2px 9px; white-space: nowrap; }
    .pe-pill::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
    .pe-pill.pe-neutral { background: var(--surface-2); color: var(--text-secondary); }
    .pe-pill.pe-info { background: color-mix(in srgb, var(--brand-icon) 14%, transparent); color: var(--text-primary); }
    .pe-pill.pe-warn { background: color-mix(in srgb, var(--ds-amber) 16%, transparent); color: var(--ds-amber); }
    .pe-pill.pe-act { background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); color: var(--ds-crimson); }
    .pe-pill.pe-ok { background: color-mix(in srgb, var(--ds-green) 14%, transparent); color: var(--ds-green); }
    .pe-prog { width: 4.5rem; height: 5px; border-radius: 3px; background: var(--surface-2); overflow: hidden; }
    .pe-prog i { display: block; height: 100%; background: var(--ds-green); border-radius: 3px; }
    /* The sidebar takes ~15rem of the content width, so it only shows from 1280px up; below that it becomes a row above
       the content. Between 1280 and 1535 a page's own xl (1280) three-column layout would be squeezed, so it stacks. */
    @media (min-width: 1280px) and (max-width: 1535px) {
        .pe-main .xl\:grid-cols-3 { grid-template-columns: minmax(0, 1fr); }
        .pe-main .xl\:col-span-2 { grid-column: auto; }
    }
    @media (max-width: 1279px) {
        .pe-shell { grid-template-columns: minmax(0, 1fr); }
        .pe-nav { top: 0; z-index: 6; flex-direction: row; overflow-x: auto; }
        .pe-nav .pe-grp { display: none; }
    }
    @media (max-width: 1023px) {
        .pe-tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
</style>
<div class="pe-shell">
    <nav class="pe-nav" aria-label="Platform E-Sign">
        @foreach($peNav as $group => $items)
            <div class="pe-grp">{{ $group }}</div>
            @foreach($items as $key => [$label, $href, $icon])
                <a href="{{ $href }}" class="{{ ($tab ?? '') === $key ? 'pe-on' : '' }}" @if(($tab ?? '') === $key) aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" aria-hidden="true">{!! $peIcons[$icon] !!}</svg>{{ $label }}
                </a>
            @endforeach
        @endforeach
    </nav>
    <div class="pe-main space-y-5">
        @include('admin.partials.platform-flash')
