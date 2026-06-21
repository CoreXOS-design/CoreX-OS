@php
    use Illuminate\Support\Str;

    $allImages = array_values(array_filter(array_merge(
        $property->gallery_images_json ?? [],
        $property->dawn_images_json    ?? [],
        $property->noon_images_json    ?? [],
        $property->dusk_images_json    ?? [],
    )));

    $agency        = $property->agency;
    $brandDefault  = $agency->default_color ?? '#0b2a4a';
    $brandButton   = $agency->button_color  ?? '#0ea5e9';
    $brandIcon     = $agency->icon_color    ?? '#0ea5e9';

    // Status → ds-badge variant per design system §3.4
    $statusBadge = [
        'active'    => ['ds-badge-success', 'For Sale'],
        'draft'     => ['ds-badge-default', 'Draft'],
        'sold'      => ['ds-badge-info',    'Sold'],
        'withdrawn' => ['ds-badge-warning', 'Withdrawn'],
        'pending'   => ['ds-badge-warning', 'Pending'],
    ][$property->status] ?? ['ds-badge-default', ucfirst((string)$property->status)];

    $features      = $property->features_json ?? [];
    $spaces        = $property->spaces_json   ?? [];
    $listedAgo     = $property->listed_date ? $property->listed_date->diffForHumans() : null;
    $waNumber      = $displayAgent->cell ? preg_replace('/[^0-9]/', '', $displayAgent->cell) : null;
    $locationQuery = trim(collect([
        $property->street_address ?? null,
        $property->suburb,
        $property->city,
        'South Africa',
    ])->filter()->implode(', '));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $property->title }} — {{ $agency->name ?? 'Home Finders Coastal' }}</title>
    <meta name="description" content="{{ Str::limit($property->excerpt ?? $property->description ?? $property->title, 160) }}">

    {{-- Inter + JetBrains Mono — matches corex.css body font --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800|jetbrains-mono:400,500,600&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: { fontFamily: {
                sans: ['Inter', 'system-ui', 'sans-serif'],
                mono: ['JetBrains Mono', 'ui-monospace', 'monospace'],
            }}}
        }
    </script>
    <style>
        /* Design tokens (design system §1) — agency runtime-injected --brand-* */
        :root {
            --brand-default: {{ $brandDefault }};
            --brand-button:  {{ $brandButton }};
            --brand-icon:    {{ $brandIcon }};
            --bg:            #f4f6fb;
            --surface:       #ffffff;
            --surface-2:     #f0f2f8;
            --border:        rgba(0,0,0,0.07);
            --border-hover:  rgba(0,0,0,0.14);
            --text-primary:  #111827;
            --text-secondary:#4b5563;
            --text-muted:    #9ca3af;
            --ds-green:      #059669;
            --ds-amber:      #f59e0b;
            --ds-crimson:    #c41e3a;
            --ds-navy:       #0b2a4a;
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text-primary);
            margin: 0;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            font-feature-settings: 'cv02', 'cv03', 'cv04', 'cv11';
            font-size: 0.875rem;
        }
        .num { font-family: 'JetBrains Mono', ui-monospace, monospace; font-variant-numeric: tabular-nums; font-weight: 600; }

        /* ds-badge per §3.4 — pill, uppercase, nowrap */
        .ds-badge {
            display:inline-flex; align-items:center; gap:.375rem;
            padding:.25rem .625rem; border-radius:9999px;
            font-size:.6875rem; font-weight:600; text-transform:uppercase;
            letter-spacing:.04em; white-space:nowrap;
        }
        .ds-badge-success { background: color-mix(in srgb, var(--ds-green) 14%, transparent); color: var(--ds-green); }
        .ds-badge-warning { background: color-mix(in srgb, var(--ds-amber) 16%, transparent); color: #b45309; }
        .ds-badge-info    { background: color-mix(in srgb, var(--ds-navy) 14%, transparent); color: var(--ds-navy); }
        .ds-badge-default { background: var(--surface-2); color: var(--text-secondary); }

        /* corex-btn-* per §3.5 */
        .corex-btn-primary {
            display:inline-flex; align-items:center; justify-content:center; gap:.5rem;
            padding:.625rem 1rem; border-radius:6px;
            background: var(--brand-button); color:#fff; font-weight:600; font-size:.875rem;
            text-decoration:none; border:0; cursor:pointer;
            box-shadow: 0 4px 12px color-mix(in srgb, var(--brand-button) 25%, transparent);
            transition: all 300ms ease;
        }
        .corex-btn-primary:hover { filter: brightness(1.06); box-shadow: 0 6px 16px color-mix(in srgb, var(--brand-button) 35%, transparent); }
        .corex-btn-outline {
            display:inline-flex; align-items:center; justify-content:center; gap:.5rem;
            padding:.625rem 1rem; border-radius:6px;
            background: var(--surface); color: var(--text-primary); font-weight:600; font-size:.875rem;
            text-decoration:none; border:1px solid var(--border); cursor:pointer;
            transition: all 300ms ease;
        }
        .corex-btn-outline:hover { border-color: var(--border-hover); background: var(--surface-2); }
        .btn-block { width:100%; }
        .btn-wa { background:#25d366; box-shadow: 0 4px 12px color-mix(in srgb, #25d366 25%, transparent); }
        .btn-wa:hover { box-shadow: 0 6px 16px color-mix(in srgb, #25d366 35%, transparent); }

        /* Card per §3.3 — rounded-md, surface, border */
        .card { background: var(--surface); border: 1px solid var(--border); border-radius:6px; }
        .card-pad { padding: 1.25rem; }
        .panel-pad { padding: 1.5rem; }

        /* Hero */
        .hero { position:relative; height: 64vh; min-height:460px; max-height:720px; overflow:hidden; background:#0a0a0a; }
        .hero-bg {
            position:absolute; inset:-24px; width:calc(100% + 48px); height:calc(100% + 48px);
            object-fit:cover; filter: blur(28px) brightness(.6); transform: scale(1.08) translateZ(0);
            transition: opacity 1400ms cubic-bezier(0.4, 0, 0.2, 1);
            will-change: opacity; opacity:0;
        }
        .hero-img {
            position:absolute; inset:0; width:100%; height:100%; object-fit:cover;
            transition: opacity 1400ms cubic-bezier(0.4, 0, 0.2, 1);
            transform: translateZ(0);
            backface-visibility: hidden;
            will-change: opacity; opacity:0;
        }
        .hero-img.active, .hero-bg.active { opacity:1; }
        .hero-img.idle,   .hero-bg.idle   { opacity:0; }
        /* If the source image is small, fall back to contained rendering on top of the blurred backdrop */
        .hero-img.contain { object-fit: contain; }
        .hero-scrim { position:absolute; inset:0; background:
            linear-gradient(180deg, rgba(0,0,0,.5) 0%, rgba(0,0,0,0) 28%, rgba(0,0,0,0) 55%, rgba(0,0,0,.85) 100%); }
        .glass-pill {
            display:inline-flex; align-items:center; gap:.5rem;
            padding:.375rem .75rem; border-radius:9999px;
            background:rgba(255,255,255,.1); color:#fff;
            backdrop-filter: blur(10px);
            border:1px solid rgba(255,255,255,.18);
            font-size:.75rem; font-weight:500;
        }

        /* Sticky condensed header on scroll */
        .sticky-bar { transition: transform 300ms ease, opacity 300ms ease; }
        .sticky-bar.hidden-bar { transform: translateY(-100%); opacity:0; pointer-events:none; }

        /* Stat strip */
        .stat { display:flex; flex-direction:column; align-items:center; padding:1.25rem .75rem; }
        .stat .v { font-family:'JetBrains Mono', ui-monospace, monospace; font-variant-numeric: tabular-nums; font-size:1.625rem; font-weight:600; color: var(--brand-default); line-height:1; }
        .stat .l { font-size:.6875rem; text-transform:uppercase; letter-spacing:.06em; color: var(--text-muted); margin-top:.5rem; font-weight:600; }

        /* Gallery mosaic */
        .gallery-grid {
            display:grid; grid-template-columns: 2fr 1fr 1fr; grid-template-rows: 1fr 1fr;
            gap:.5rem; height: 460px; border-radius:6px; overflow:hidden;
        }
        .gallery-grid > div { background:#0a0a0a; cursor:pointer; overflow:hidden; position:relative; }
        .gallery-grid > div:nth-child(1) { grid-row: 1 / span 2; }
        .gallery-grid img { width:100%; height:100%; object-fit:cover; transition: transform 300ms ease; }
        .gallery-grid > div:hover img { transform: scale(1.05); }
        .view-all-btn {
            position:absolute; bottom:14px; right:14px;
            background: var(--surface); color: var(--text-primary);
            padding:.5rem 1rem; border-radius:6px;
            font-size:.75rem; font-weight:600;
            box-shadow: 0 8px 24px rgba(0,0,0,.18);
            display:inline-flex; align-items:center; gap:.4rem; cursor:pointer; border:0;
        }

        /* Lightbox */
        .lightbox { position:fixed; inset:0; background:rgba(0,0,0,.94); z-index:80; display:none; align-items:center; justify-content:center; }
        .lightbox.open { display:flex; }
        .lightbox img { max-width:92vw; max-height:88vh; object-fit:contain; }
        .lb-btn { position:absolute; background:rgba(255,255,255,.12); color:#fff; border:0; width:48px; height:48px; border-radius:9999px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition: background 150ms ease; }
        .lb-btn:hover { background:rgba(255,255,255,.22); }
        .lb-close { top:24px; right:24px; }
        .lb-prev  { left:24px; top:50%; transform:translateY(-50%); }
        .lb-next  { right:24px; top:50%; transform:translateY(-50%); }

        /* Details list */
        .detail-label { font-size:.6875rem; text-transform:uppercase; letter-spacing:.06em; color: var(--text-muted); font-weight:600; margin-bottom:.25rem; }
        .detail-value { font-size:.875rem; font-weight:500; color: var(--text-primary); }

        /* Feature/space rows */
        .feature-row { display:flex; align-items:center; gap:.75rem; padding:.625rem 0; border-bottom:1px solid var(--border); font-size:.875rem; color: var(--text-secondary); }
        .feature-row:last-child { border-bottom:0; }
        .check {
            width:20px; height:20px; border-radius:9999px;
            background: color-mix(in srgb, var(--brand-icon) 14%, transparent);
            color: var(--brand-icon);
            display:inline-flex; align-items:center; justify-content:center; flex-shrink:0;
        }

        /* Section heading */
        .section-h {
            font-size:1.125rem; font-weight:700; color: var(--text-primary);
            margin-bottom:1rem; letter-spacing:-0.01em;
        }

        /* Inputs (forms) per §3.6 */
        .ds-input {
            width:100%; padding:.625rem .75rem; border-radius:6px;
            background: var(--surface-2); border:1px solid var(--border);
            color: var(--text-primary); font-size:.8125rem; font-family:inherit;
            transition: border-color 150ms ease, box-shadow 150ms ease;
        }
        .ds-input:focus {
            outline:none; border-color: var(--brand-button);
            box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand-button) 15%, transparent);
        }
        textarea.ds-input { resize:none; }

        /* Range slider */
        input[type=range] { -webkit-appearance:none; appearance:none; width:100%; height:4px; border-radius:9999px; background: var(--surface-2); outline:none; }
        input[type=range]::-webkit-slider-thumb { -webkit-appearance:none; width:16px; height:16px; border-radius:9999px; background: var(--brand-button); cursor:pointer; box-shadow: 0 2px 6px rgba(0,0,0,.2); }
        input[type=range]::-moz-range-thumb { width:16px; height:16px; border-radius:9999px; background: var(--brand-button); cursor:pointer; border:0; }

        @media (max-width: 1023px) {
            .hero { height: 56vh; min-height: 380px; }
            .gallery-grid { grid-template-columns: 1fr 1fr; height:340px; }
            .gallery-grid > div:nth-child(n+5) { display:none; }
            .layout-grid { grid-template-columns: 1fr !important; }
            .agent-sticky { position: relative !important; top:0 !important; }
        }
    </style>
</head>
<body x-data="propertyPreview({{ json_encode($allImages) }})" x-init="init()">

{{-- Sticky condensed header (appears on scroll) --}}
<div class="sticky-bar fixed top-0 left-0 right-0 z-40 hidden-bar"
     :class="{ 'hidden-bar': !scrolled }"
     style="background:rgba(255,255,255,.94); backdrop-filter:saturate(180%) blur(14px); border-bottom:1px solid var(--border);">
    <div class="max-w-7xl mx-auto px-6 py-3 flex items-center justify-between gap-4">
        <div class="flex items-center gap-3 min-w-0">
            @if($agency && $agency->logo_path)
                <img src="{{ asset('storage/'.$agency->logo_path) }}" alt="" style="max-height:32px; max-width:120px; object-fit:contain;">
            @else
                <span class="font-bold text-base" style="color:var(--brand-default);">{{ $agency->name ?? 'Home Finders Coastal' }}</span>
            @endif
            <span class="hidden sm:inline" style="color:var(--text-muted);">·</span>
            <span class="hidden sm:inline truncate font-semibold" style="color:var(--text-primary);">{{ $property->title }}</span>
        </div>
        <div class="flex items-center gap-3">
            <div class="hidden sm:block num text-base" style="color:var(--brand-default);">{{ $property->formattedPrice() }}</div>
            <a href="#enquire" class="corex-btn-primary" style="padding:.5rem 1rem;">Enquire</a>
        </div>
    </div>
</div>

{{-- Top transparent nav over hero --}}
<nav class="absolute top-0 left-0 right-0 z-30">
    <div class="max-w-7xl mx-auto px-6 py-5 flex items-center justify-between">
        <div>
            @if($agency && $agency->logo_path)
                <img src="{{ asset('storage/'.$agency->logo_path) }}" alt="{{ $agency->name }}" style="max-height:42px; max-width:160px; object-fit:contain; filter: drop-shadow(0 2px 8px rgba(0,0,0,.4));">
            @else
                <div class="font-bold text-lg text-white" style="text-shadow:0 2px 12px rgba(0,0,0,.4);">
                    {{ $agency->name ?? 'Home Finders Coastal' }}
                </div>
            @endif
        </div>
        <div class="flex items-center gap-2">
            <span class="ds-badge {{ $statusBadge[0] }}" style="background:rgba(255,255,255,.92);">
                <span style="width:6px;height:6px;border-radius:9999px;background:currentColor;"></span>
                {{ $statusBadge[1] }}
            </span>
            @if($listedAgo)
                <span class="glass-pill hidden sm:inline-flex">Listed {{ $listedAgo }}</span>
            @endif
        </div>
    </div>
</nav>

{{-- HERO --}}
<section class="hero">
    @if(count($allImages) > 0)
        @foreach($allImages as $i => $img)
            <img :class="slide === {{ $i }} ? 'hero-bg active' : 'hero-bg idle'"
                 src="{{ $img }}" alt="" aria-hidden="true" loading="{{ $i === 0 ? 'eager' : 'lazy' }}">
            <img :class="slide === {{ $i }} ? 'hero-img active' : 'hero-img idle'"
                 :style="(slide === {{ $i }} && imgSmall[{{ $i }}]) ? 'object-fit:contain' : ''"
                 src="{{ $img }}" alt=""
                 @load="checkSize($event, {{ $i }})"
                 loading="{{ $i === 0 ? 'eager' : 'lazy' }}">
        @endforeach
    @else
        <div class="absolute inset-0 flex items-center justify-center" style="color:rgba(255,255,255,.4);">
            <span class="text-sm">No images uploaded</span>
        </div>
    @endif
    <div class="hero-scrim"></div>

    <div class="absolute bottom-0 left-0 right-0">
        <div class="max-w-7xl mx-auto px-6 pb-12">
            <div class="flex items-center gap-2 flex-wrap mb-3" style="color:rgba(255,255,255,.85); font-size:.8125rem;">
                @if($property->suburb)<span>{{ $property->suburb }}</span>@endif
                @if($property->suburb && $property->city)<span style="opacity:.5;">·</span>@endif
                @if($property->city)<span>{{ $property->city }}</span>@endif
                @if($property->property_type)
                    <span style="opacity:.5;">·</span>
                    <span>{{ ucwords(str_replace('_',' ',$property->property_type)) }}</span>
                @endif
            </div>
            <h1 class="text-white font-bold mb-4 max-w-4xl"
                style="text-shadow:0 4px 28px rgba(0,0,0,.45); font-size:clamp(1.75rem, 4.5vw, 3rem); letter-spacing:-0.02em; line-height:1.1;">
                {{ $property->title }}
            </h1>
            <div class="flex items-end justify-between gap-6 flex-wrap">
                <div class="num text-white" style="text-shadow:0 4px 28px rgba(0,0,0,.45); font-size:clamp(1.5rem, 3vw, 2.25rem);">
                    {{ $property->formattedPrice() }}
                </div>
                @if(count($allImages) > 1)
                <div class="flex items-center gap-2" style="color:rgba(255,255,255,.85);">
                    <button type="button" @click="prev()" class="lb-btn" style="position:relative; width:36px; height:36px; top:auto; left:auto; transform:none;">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
                    </button>
                    <span class="num text-xs px-1"><span x-text="slide + 1"></span> / {{ count($allImages) }}</span>
                    <button type="button" @click="next()" class="lb-btn" style="position:relative; width:36px; height:36px; top:auto; right:auto; transform:none;">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                    </button>
                </div>
                @endif
            </div>
        </div>
    </div>
</section>

{{-- QUICK STATS BAR --}}
<section class="max-w-7xl mx-auto px-6 -mt-10 relative z-10">
    <div class="card" style="box-shadow: 0 1px 3px rgba(0,0,0,.06), 0 24px 60px -28px rgba(15,23,42,.18);">
        <div class="grid grid-cols-2 sm:grid-cols-5" style="divide-color:var(--border);">
            @php
                $stats = [
                    ['v' => $property->beds ?: '—', 'l' => 'Bedrooms'],
                    ['v' => $property->baths ?: '—', 'l' => 'Bathrooms'],
                    ['v' => $property->garages ?: '—', 'l' => 'Garages'],
                    ['v' => $property->size_m2 ? number_format($property->size_m2).' m²' : '—', 'l' => 'Floor'],
                    ['v' => $property->erf_size_m2 ? number_format($property->erf_size_m2).' m²' : '—', 'l' => 'Erf'],
                ];
            @endphp
            @foreach($stats as $i => $s)
                <div class="stat" @if($i > 0) style="border-left:1px solid var(--border);" @endif>
                    <span class="v">{{ $s['v'] }}</span>
                    <span class="l">{{ $s['l'] }}</span>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- GALLERY MOSAIC --}}
@if(count($allImages) > 1)
<section class="max-w-7xl mx-auto px-6 mt-10">
    <div class="gallery-grid">
        @foreach(array_slice($allImages, 0, 5) as $i => $img)
            <div @click="openLightbox({{ $i }})">
                <img src="{{ $img }}" alt="" loading="lazy">
                @if($loop->last && count($allImages) > 5)
                    <button class="view-all-btn" @click.stop="openLightbox({{ $i }})">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z"/></svg>
                        View all {{ count($allImages) }} photos
                    </button>
                @endif
            </div>
        @endforeach
    </div>
</section>
@endif

{{-- MAIN CONTENT --}}
<section class="max-w-7xl mx-auto px-6 mt-10 pb-20">
    <div class="grid layout-grid gap-6" style="grid-template-columns: 1fr 360px;">

        {{-- LEFT --}}
        <div class="min-w-0 space-y-6">

            {{-- About --}}
            @if($property->description || $property->excerpt)
            <div class="card panel-pad">
                <h2 class="section-h">About this home</h2>
                @if($property->excerpt)
                    <p style="font-size:1rem; line-height:1.6; color:var(--text-primary); font-weight:500; margin-bottom:.75rem;">{{ $property->excerpt }}</p>
                @endif
                @if($property->description)
                    <p style="line-height:1.65; color:var(--text-secondary); white-space:pre-line;">{{ $property->description }}</p>
                @endif
            </div>
            @endif

            {{-- Property details --}}
            <div class="card panel-pad">
                <h2 class="section-h">Property details</h2>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-x-6 gap-y-4">
                    @php
                        $details = collect([
                            ['Type',     $property->property_type ? ucwords(str_replace('_',' ',$property->property_type)) : null],
                            ['Category', $property->category ?? null],
                            ['Suburb',   $property->suburb ?? null],
                            ['City',     $property->city ?? null],
                            ['Erf size', $property->erf_size_m2 ? number_format($property->erf_size_m2).' m²' : null],
                            ['Floor',    $property->size_m2 ? number_format($property->size_m2).' m²' : null],
                            ['Rates',    $property->rates_taxes ? 'R '.number_format($property->rates_taxes).' / mo' : null],
                            ['Levy',     $property->levy ? 'R '.number_format($property->levy).' / mo' : null],
                            ['Mandate',  $property->mandate_type ? ucfirst($property->mandate_type) : null],
                            ['Listed',   $property->listed_date ? $property->listed_date->format('d M Y') : null],
                        ])->filter(fn($d) => !empty($d[1]));
                    @endphp
                    @foreach($details as [$label, $value])
                        <div>
                            <div class="detail-label">{{ $label }}</div>
                            <div class="detail-value">{{ $value }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Features --}}
            @if(count($features) > 0)
            <div class="card panel-pad">
                <h2 class="section-h">Features &amp; amenities</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8">
                    @foreach($features as $feature)
                        <div class="feature-row">
                            <span class="check">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                            </span>
                            <span>{{ is_array($feature) ? ($feature['name'] ?? '') : $feature }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Spaces --}}
            @if(count($spaces) > 0)
            <div class="card panel-pad">
                <h2 class="section-h">Rooms &amp; spaces</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8">
                    @foreach($spaces as $space)
                        @php
                            $sName = is_array($space) ? ($space['name'] ?? '') : $space;
                            $sSize = is_array($space) ? ($space['size'] ?? null) : null;
                        @endphp
                        <div class="feature-row" style="justify-content:space-between;">
                            <span>{{ $sName }}</span>
                            @if($sSize)<span class="num text-xs" style="color:var(--text-muted);">{{ $sSize }}</span>@endif
                        </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Bond calculator --}}
            <div class="card panel-pad" x-data="mortgageCalc({{ (int)($property->price ?? 0) }})">
                <h2 class="section-h" style="margin-bottom:.25rem;">Bond calculator</h2>
                <p style="font-size:.8125rem; color:var(--text-muted); margin-bottom:1.25rem;">Estimate your monthly repayment. Indicative only.</p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label class="detail-label" style="margin:0;">Deposit</label>
                            <span class="num text-xs" style="color:var(--brand-default);"><span x-text="depositPct"></span>%</span>
                        </div>
                        <input type="range" min="0" max="50" step="1" x-model.number="depositPct">
                        <div class="num text-xs mt-1" style="color:var(--text-muted);">R <span x-text="fmt(deposit)"></span></div>
                    </div>
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label class="detail-label" style="margin:0;">Term</label>
                            <span class="num text-xs" style="color:var(--brand-default);"><span x-text="years"></span> yrs</span>
                        </div>
                        <input type="range" min="5" max="30" step="1" x-model.number="years">
                    </div>
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label class="detail-label" style="margin:0;">Rate</label>
                            <span class="num text-xs" style="color:var(--brand-default);"><span x-text="rate.toFixed(2)"></span>%</span>
                        </div>
                        <input type="range" min="7" max="15" step="0.25" x-model.number="rate">
                    </div>
                </div>
                <div class="mt-6 pt-5 flex items-end justify-between flex-wrap gap-3" style="border-top:1px solid var(--border);">
                    <div>
                        <div class="detail-label">Estimated monthly</div>
                        <div class="num" style="font-size:1.75rem; color:var(--brand-default);">R <span x-text="fmt(monthly)"></span></div>
                    </div>
                    <div class="num text-xs text-right" style="color:var(--text-muted); line-height:1.6;">
                        Loan: R <span x-text="fmt(loan)"></span><br>
                        Total interest: R <span x-text="fmt(totalInterest)"></span>
                    </div>
                </div>
            </div>

            {{-- Other Virtual Tour (iPanorama / Kuula / custom) --}}
            @if($property->virtual_tour_url)
            <div class="card overflow-hidden">
                <div class="panel-pad" style="padding-bottom:1rem;">
                    <h2 class="section-h" style="margin-bottom:.25rem;">Virtual Tour</h2>
                    <p style="font-size:.8125rem; color:var(--text-muted);">Interactive 360° tour of the property.</p>
                </div>
                <iframe
                    src="{{ $property->virtual_tour_url }}"
                    width="100%" height="520"
                    style="border:0; display:block;"
                    allow="fullscreen; xr-spatial-tracking; gyroscope; accelerometer; vr"
                    allowfullscreen
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade"></iframe>
            </div>
            @endif

            {{-- Map --}}
            @if($locationQuery)
            <div class="card overflow-hidden">
                <div class="panel-pad" style="padding-bottom:1rem;">
                    <h2 class="section-h" style="margin-bottom:.25rem;">Location</h2>
                    <p style="font-size:.8125rem; color:var(--text-muted);">{{ $locationQuery }}</p>
                </div>
                <iframe
                    src="https://maps.google.com/maps?q={{ urlencode($locationQuery) }}&z=14&output=embed"
                    width="100%" height="380" style="border:0; display:block;" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
            </div>
            @endif

        </div>

        {{-- RIGHT: sticky agent card --}}
        <aside>
            <div class="agent-sticky" style="position:sticky; top:1.5rem;">
                <div class="card overflow-hidden" id="enquire">

                    {{-- Price header --}}
                    <div class="px-5 py-5" style="background: var(--brand-default); color:#fff;">
                        <div style="font-size:.6875rem; text-transform:uppercase; letter-spacing:.06em; color:rgba(255,255,255,.7); font-weight:600; margin-bottom:.25rem;">Asking price</div>
                        <div class="num" style="font-size:1.625rem;">{{ $property->formattedPrice() }}</div>
                        @if($property->suburb || $property->city)
                            <div style="font-size:.8125rem; color:rgba(255,255,255,.7); margin-top:.25rem;">{{ $property->suburb }}{{ $property->city ? ', '.$property->city : '' }}</div>
                        @endif
                    </div>

                    {{-- Agent --}}
                    <div class="card-pad">
                        <div class="detail-label">Listed by</div>
                        <div class="flex items-center gap-3 mb-4 mt-2">
                            @if($displayAgent->profilePhotoUrl())
                                <img src="{{ $displayAgent->profilePhotoUrl() }}" alt="{{ $displayAgent->name }}"
                                     class="rounded-full object-cover" style="width:56px; height:56px; border:3px solid color-mix(in srgb, var(--brand-button) 18%, var(--surface));">
                            @else
                                <div class="rounded-full flex items-center justify-center text-white font-bold"
                                     style="width:56px; height:56px; background:var(--brand-button); font-size:1rem;">{{ $displayAgent->initials() }}</div>
                            @endif
                            <div class="min-w-0 flex-1">
                                <div class="font-bold text-base leading-tight" style="color:var(--brand-default);">{{ $displayAgent->name }}</div>
                                @if($displayAgent->designation)
                                    <span class="ds-badge" style="background:color-mix(in srgb, var(--brand-icon) 12%, transparent); color:var(--brand-icon); margin-top:.375rem;">{{ $displayAgent->designation }}</span>
                                @endif
                                @if($property->branch)
                                    <div class="text-xs mt-1" style="color:var(--text-muted);">{{ $property->branch->name }}</div>
                                @endif
                            </div>
                        </div>

                        <div class="space-y-2">
                            @if($displayAgent->cell || $displayAgent->phone)
                                @php $phone = $displayAgent->cell ?: $displayAgent->phone; @endphp
                                <a href="tel:{{ $phone }}" class="corex-btn-primary btn-block">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z"/></svg>
                                    Call <span class="num">{{ $phone }}</span>
                                </a>
                            @endif
                            @if($waNumber)
                                <a href="https://wa.me/{{ $waNumber }}?text={{ urlencode('Hi '.$displayAgent->name.', I’m interested in '.$property->title) }}" target="_blank" class="corex-btn-primary btn-block btn-wa">
                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.12.554 4.122 1.524 5.859L.057 23.25l5.54-1.453A11.93 11.93 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.86 0-3.601-.5-5.098-1.372l-.365-.216-3.788.994.996-3.71-.237-.374A9.96 9.96 0 0 1 2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg>
                                    WhatsApp
                                </a>
                            @endif
                            <a href="mailto:{{ $displayAgent->outward_email }}?subject={{ urlencode('Enquiry: '.$property->title) }}" class="corex-btn-outline btn-block">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--brand-icon);"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/></svg>
                                Email agent
                            </a>
                        </div>

                        {{-- Quick enquiry --}}
                        <form class="mt-5 pt-5 space-y-2.5" style="border-top:1px solid var(--border);"
                              onsubmit="event.preventDefault(); const f=event.target; const body='Name: '+f.n.value+'%0AContact: '+f.c.value+'%0A%0A'+encodeURIComponent(f.m.value); window.location.href='mailto:{{ $displayAgent->outward_email }}?subject={{ urlencode('Enquiry: '.$property->title) }}&body='+body;">
                            <div class="detail-label">Quick enquiry</div>
                            <input name="n" placeholder="Your name" required class="ds-input">
                            <input name="c" placeholder="Phone or email" required class="ds-input">
                            <textarea name="m" rows="3" class="ds-input">I'd like more information about {{ $property->title }}.</textarea>
                            <button type="submit" class="corex-btn-primary btn-block">Send enquiry</button>
                        </form>

                        <div class="mt-5 pt-4 text-center" style="border-top:1px solid var(--border);">
                            <div class="font-bold text-xs" style="color:var(--brand-default);">{{ $agency->name ?? 'Home Finders Coastal' }}</div>
                            @if($property->branch)
                                <div class="text-xs mt-0.5" style="color:var(--text-muted);">{{ $property->branch->name }}</div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</section>

{{-- LIGHTBOX --}}
<div class="lightbox" :class="{ 'open': lightboxOpen }" @keydown.escape.window="lightboxOpen=false">
    <button class="lb-btn lb-close" @click="lightboxOpen=false">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
    </button>
    @if(count($allImages) > 1)
        <button class="lb-btn lb-prev" @click="prev()">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
        </button>
        <button class="lb-btn lb-next" @click="next()">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
        </button>
    @endif
    <template x-if="lightboxOpen">
        <img :src="images[slide]" alt="">
    </template>
    <div class="absolute bottom-6 left-0 right-0 text-center num text-sm" style="color:rgba(255,255,255,.7);">
        <span x-text="slide + 1"></span> / <span x-text="images.length"></span>
    </div>
</div>

{{-- Footer --}}
<footer class="py-8 text-center" style="background:var(--brand-default); color:rgba(255,255,255,.65); font-size:.75rem;">
    <div class="font-bold text-white text-sm mb-1">{{ $agency->name ?? 'Home Finders Coastal' }}</div>
    <div>Shelly Beach · KZN South Coast · This is a live preview for internal use only.</div>
</footer>

<script>
    function propertyPreview(images) {
        return {
            images: images || [],
            slide: 0,
            scrolled: false,
            lightboxOpen: false,
            imgSmall: {},
            checkSize(e, i) {
                const el = e.target;
                // If the source resolution is too small to fill the hero crisply, render contained.
                if (el.naturalWidth && el.naturalWidth < 1400) {
                    this.imgSmall[i] = true;
                }
            },
            init() {
                window.addEventListener('scroll', () => { this.scrolled = window.scrollY > 320; });
                window.addEventListener('keydown', (e) => {
                    if (e.key === 'ArrowLeft') this.prev();
                    if (e.key === 'ArrowRight') this.next();
                });
                if (this.images.length > 1) {
                    setInterval(() => { if (!this.lightboxOpen) this.next(); }, 8000);
                }
            },
            prev() { if (this.images.length) this.slide = (this.slide - 1 + this.images.length) % this.images.length; },
            next() { if (this.images.length) this.slide = (this.slide + 1) % this.images.length; },
            openLightbox(i) { this.slide = i; this.lightboxOpen = true; }
        }
    }
    function mortgageCalc(price) {
        return {
            price: price || 0,
            depositPct: 10,
            years: 20,
            rate: 11.75,
            get deposit() { return Math.round(this.price * this.depositPct / 100); },
            get loan()    { return Math.max(0, this.price - this.deposit); },
            get monthly() {
                const r = (this.rate / 100) / 12;
                const n = this.years * 12;
                if (r === 0) return Math.round(this.loan / n);
                const m = this.loan * (r * Math.pow(1+r, n)) / (Math.pow(1+r, n) - 1);
                return Math.round(m || 0);
            },
            get totalInterest() { return Math.max(0, this.monthly * this.years * 12 - this.loan); },
            fmt(n) { return new Intl.NumberFormat('en-ZA').format(n || 0); }
        }
    }
</script>
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</body>
</html>
