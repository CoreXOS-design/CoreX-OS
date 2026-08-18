<!DOCTYPE html>
<html lang="en">
@php
    use Illuminate\Contracts\Pagination\Paginator;

    // Agency brand colours (Company Settings → Design). Fall back to CoreX defaults.
    $brandDefault = optional($agency)->default_color ?: '#0b2a4a';
    $brandIcon    = optional($agency)->icon_color    ?: '#00b4d8';
    $brandButton  = optional($agency)->button_color  ?: '#00b4d8';
    $brandSidebar = optional($agency)->sidebar_color ?: $brandIcon;

    // Client-side refine bar data (all results arrive in one collection).
    $items      = $properties instanceof Paginator ? collect($properties->items()) : collect($properties);
    $priceVals  = $items->pluck('price')->filter(fn ($p) => (int) $p > 0)->map(fn ($p) => (int) $p)->values();
    $priceFloor = $priceVals->min();
    $priceCeil  = $priceVals->max();
    if ($priceFloor !== null && $priceCeil !== null && $priceCeil <= $priceFloor) {
        $priceCeil = $priceFloor + 1;
    }
    $priceStep  = ($priceFloor !== null && $priceCeil !== null)
        ? max(10000, (int) (round((($priceCeil - $priceFloor) / 100) / 1000) * 1000))
        : 10000;
    $suburbList = $items->pluck('suburb')->filter()->unique()->sort()->values();
    $hasScores  = $items->contains(fn ($p) => (int) ($p->match_score ?? 0) > 0);
@endphp
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ 'Your Property Matches' . (!empty($agency) ? ' — ' . $agency->name : '') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --bg: #f4f6fb;
            --surface: #ffffff;
            --surface-2: #f0f2f8;
            --border: rgba(0,0,0,0.07);
            --border-hover: rgba(0,0,0,0.14);
            --text-primary: #111827;
            --text-secondary: #4b5563;
            --text-muted: #9ca3af;
            /* Agency brand colours — injected from Company Settings */
            --brand-default: {{ $brandDefault }};
            --brand-icon: {{ $brandIcon }};
            --brand-button: {{ $brandButton }};
            --brand-sidebar: {{ $brandSidebar }};
            --ds-green: #059669;
            --ds-amber: #f59e0b;
            --ds-crimson: #c41e3a;
            --ds-navy: #0b2a4a;
        }
        * { box-sizing: border-box; }
        html, body { font-family: 'Figtree', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text-primary); margin: 0; }
        a { text-decoration: none; }
        input, select, textarea { outline: none; font-family: inherit; }
        input:focus, select:focus, textarea:focus {
            border-color: var(--brand-button) !important;
            box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand-button) 15%, transparent);
        }
        .surface-card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; }
        .btn-primary {
            display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
            background: var(--brand-button); color: #fff; border: 1px solid var(--brand-button);
            border-radius: 8px; padding: 0.5rem 0.875rem; font-size: 0.8125rem; font-weight: 600;
            cursor: pointer; transition: all 200ms ease;
            box-shadow: 0 4px 12px color-mix(in srgb, var(--brand-button) 25%, transparent);
        }
        .btn-primary:hover { box-shadow: 0 6px 16px color-mix(in srgb, var(--brand-button) 35%, transparent); transform: translateY(-1px); }
        .btn-outline {
            display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
            background: var(--surface); color: var(--text-secondary);
            border: 1px solid var(--border); border-radius: 8px;
            padding: 0.5rem 0.875rem; font-size: 0.8125rem; font-weight: 600;
            cursor: pointer; transition: all 200ms ease;
        }
        .btn-outline:hover { border-color: var(--brand-button); color: var(--brand-button); }
        .btn-outline[aria-disabled="true"] { opacity: 0.4; cursor: not-allowed; pointer-events: none; }
        .field-label { display: block; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.375rem; }
        .field-helper { display: block; font-size: 0.6875rem; color: var(--text-muted); margin-bottom: 0.25rem; }
        .field-input {
            width: 100%; border: 1px solid var(--border); border-radius: 8px;
            padding: 0.5rem 0.75rem; font-size: 0.8125rem; color: var(--text-primary);
            background: var(--surface); transition: all 200ms ease;
        }
        .field-input::placeholder { color: var(--text-muted); }
        .ds-badge {
            display: inline-flex; align-items: center; white-space: nowrap;
            border-radius: 9999px; padding: 0.125rem 0.5rem;
            font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em;
            border: 1px solid transparent;
        }
        .ds-badge-success { background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green); border-color: color-mix(in srgb, var(--ds-green) 28%, transparent); }
        .ds-badge-warning { background: color-mix(in srgb, var(--ds-amber) 12%, transparent); color: var(--ds-amber); border-color: color-mix(in srgb, var(--ds-amber) 28%, transparent); }
        .ds-badge-info    { background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon); border-color: color-mix(in srgb, var(--brand-icon) 28%, transparent); }
        .ds-badge-default { background: var(--surface-2); color: var(--text-secondary); border-color: var(--border); }
        .feedback-btn {
            display: inline-flex; align-items: center; gap: 0.25rem;
            font-size: 0.6875rem; font-weight: 600;
            padding: 0.375rem 0.625rem; border-radius: 8px;
            background: var(--surface); color: var(--text-secondary);
            border: 1px solid var(--border); cursor: pointer; transition: all 200ms ease;
        }
        .feedback-btn:hover { border-color: var(--border-hover); }
        .feedback-btn.is-active { color: #fff; }

        /* Range sliders (refine bar) — branded thumb + filled track */
        .range { -webkit-appearance: none; appearance: none; width: 100%; height: 4px; border-radius: 9999px;
                 background: var(--surface-2); outline: none; margin: 0; }
        .range::-webkit-slider-thumb { -webkit-appearance: none; appearance: none; width: 16px; height: 16px; border-radius: 9999px;
                 background: var(--brand-button); cursor: pointer; box-shadow: 0 1px 4px rgba(0,0,0,.25); border: 2px solid #fff; }
        .range::-moz-range-thumb { width: 16px; height: 16px; border-radius: 9999px; background: var(--brand-button);
                 cursor: pointer; border: 2px solid #fff; box-shadow: 0 1px 4px rgba(0,0,0,.25); }
        .refine-select {
            width: 100%; border: 1px solid var(--border); border-radius: 8px; background: var(--surface);
            padding: 0.5rem 0.75rem; font-size: 0.8125rem; font-weight: 500; color: var(--text-primary); cursor: pointer;
        }
        .match-card { transition: opacity 200ms ease; }
    </style>
</head>
<body>

    {{-- Top bar --}}
    <header class="sticky top-0 z-30" style="background: var(--brand-default); border-bottom: 3px solid var(--brand-icon);">
        <div class="max-w-5xl mx-auto px-4 lg:px-6 py-3.5 flex items-center justify-between gap-3">
            @if(!empty($agency) && $agency->logo_path)
                <img src="{{ asset('storage/' . $agency->logo_path) }}" alt="{{ $agency->name }}"
                     style="max-height: 38px; max-width: 190px; object-fit: contain;">
            @else
                <div class="text-lg font-bold tracking-tight text-white">{{ $agency->name ?? 'Property Matches' }}</div>
            @endif
            <span class="ds-badge" style="background: rgba(255,255,255,0.1); color: #fff; border-color: rgba(255,255,255,0.2);">
                Property Matches
            </span>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-4 lg:px-6 py-6 space-y-6">

        {{-- Hero — personalised greeting + agent --}}
        <section class="rounded-xl px-6 py-6 relative overflow-hidden"
                 style="background: linear-gradient(135deg, var(--brand-default) 0%, color-mix(in srgb, var(--brand-default) 82%, #000) 100%);">
            <div class="absolute -top-16 -right-16 w-56 h-56 rounded-full opacity-20"
                 style="background: radial-gradient(circle, var(--brand-icon) 0%, transparent 70%);"></div>
            <div class="relative flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="flex items-center gap-4 min-w-0">
                    <div class="w-14 h-14 rounded-full flex items-center justify-center flex-shrink-0 text-lg font-bold text-white shadow-lg"
                         style="background: var(--brand-icon);">
                        {{ strtoupper(substr($contact->first_name,0,1).substr($contact->last_name,0,1)) }}
                    </div>
                    <div class="min-w-0">
                        <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.15em]" style="color: color-mix(in srgb, var(--brand-icon) 85%, #fff);">Handpicked for you</p>
                        <h1 class="text-2xl font-extrabold leading-tight text-white mt-0.5">{{ $contact->full_name }}</h1>
                        <p class="text-sm" style="color: rgba(255,255,255,0.65);">A personalised property selection from your agent.</p>
                    </div>
                </div>

                @if($match->createdBy)
                <div class="flex items-center gap-3 flex-shrink-0 rounded-lg px-3.5 py-2.5"
                     style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.14);">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
                         style="background: var(--brand-icon);">
                        {{ strtoupper(substr($match->createdBy->name, 0, 2)) }}
                    </div>
                    <div class="text-left">
                        <div class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: rgba(255,255,255,0.55);">Your Agent</div>
                        <div class="text-sm font-semibold text-white leading-tight">{{ $match->createdBy->name }}</div>
                        @if($match->createdBy->cell || $match->createdBy->phone)
                        <a href="tel:{{ $match->createdBy->cell ?? $match->createdBy->phone }}"
                           class="text-xs font-medium" style="color: color-mix(in srgb, var(--brand-icon) 85%, #fff);">
                            {{ $match->createdBy->cell ?? $match->createdBy->phone }}
                        </a>
                        @endif
                    </div>
                </div>
                @endif
            </div>

            @if($contact->phone || $contact->email)
            <div class="relative flex items-center gap-4 mt-4 pt-4 flex-wrap text-sm" style="border-top: 1px solid rgba(255,255,255,0.12); color: rgba(255,255,255,0.7);">
                @if($contact->phone)
                <a href="tel:{{ $contact->phone }}" class="inline-flex items-center gap-1.5" style="color: rgba(255,255,255,0.7);">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                    {{ $contact->phone }}
                </a>
                @endif
                @if($contact->email)
                <a href="mailto:{{ $contact->email }}" class="inline-flex items-center gap-1.5" style="color: rgba(255,255,255,0.7);">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                    {{ $contact->email }}
                </a>
                @endif
            </div>
            @endif
        </section>

        {{-- Property results --}}
        @php
            $totalCount = $properties instanceof Paginator ? $properties->total() : $properties->count();
        @endphp

        <section>
            <div class="flex items-end justify-between gap-3 mb-3 flex-wrap">
                <div>
                    <h2 class="text-lg font-bold" style="color: var(--text-primary);">
                        Properties found
                        <span class="ds-badge ds-badge-info ml-1.5" style="vertical-align: middle;">{{ number_format($totalCount) }}</span>
                    </h2>
                    <p class="text-xs mt-0.5" style="color: var(--text-muted);">Tap a card to see full listing details. Tell us what you think with the reactions below each one.</p>
                </div>
                @if($totalCount > 0)
                <span id="shownCount" class="text-xs font-semibold" style="color: var(--text-muted);"></span>
                @endif
            </div>

            {{-- Refine filter bar (client-side, instant) --}}
            @if($totalCount > 0)
            <div id="refineBar" class="surface-card p-4 lg:p-5 mb-4" style="display:none;">
                <div class="flex items-center justify-between gap-2 mb-4">
                    <div class="flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color: var(--brand-icon);"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 0 1-.659 1.591l-5.432 5.432a2.25 2.25 0 0 0-.659 1.591v2.927a2.25 2.25 0 0 1-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 0 0-.659-1.591L3.659 7.409A2.25 2.25 0 0 1 3 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0 1 12 3Z" /></svg>
                        <h3 class="text-sm font-bold" style="color: var(--text-primary);">Refine these results</h3>
                    </div>
                    <button type="button" id="refineClear" class="text-xs font-semibold" style="color: var(--brand-icon);">Clear</button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">

                    {{-- Location --}}
                    @if($suburbList->isNotEmpty())
                    <div>
                        <label class="field-label">Location</label>
                        <select id="fLocation" class="refine-select">
                            <option value="">All areas</option>
                            @foreach($suburbList as $sub)
                                <option value="{{ $sub }}">{{ $sub }}</option>
                            @endforeach
                        </select>
                    </div>
                    @endif

                    {{-- Bedrooms --}}
                    <div>
                        <label class="field-label">Bedrooms</label>
                        <select id="fBeds" class="refine-select">
                            <option value="0">Any</option>
                            @foreach([1,2,3,4,5] as $b)
                                <option value="{{ $b }}">{{ $b }}+ beds</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Minimum match % --}}
                    @if($hasScores)
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="field-label" style="margin:0;">Minimum match</label>
                            <span class="text-xs font-bold" style="color: var(--brand-icon);"><span id="fMatchVal">0</span>%</span>
                        </div>
                        <input type="range" id="fMatch" class="range" min="0" max="100" step="5" value="0">
                    </div>
                    @endif

                    {{-- Price range --}}
                    @if($priceFloor !== null)
                    <div class="{{ $hasScores ? '' : 'sm:col-span-2' }}">
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="field-label" style="margin:0;">Price range</label>
                            <span class="text-xs font-semibold" style="color: var(--text-secondary);">
                                R <span id="fPriceMinVal"></span> – R <span id="fPriceMaxVal"></span>
                            </span>
                        </div>
                        <div class="space-y-2 pt-1">
                            <input type="range" id="fPriceMin" class="range" min="{{ $priceFloor }}" max="{{ $priceCeil }}" step="{{ $priceStep }}" value="{{ $priceFloor }}">
                            <input type="range" id="fPriceMax" class="range" min="{{ $priceFloor }}" max="{{ $priceCeil }}" step="{{ $priceStep }}" value="{{ $priceCeil }}">
                        </div>
                    </div>
                    @endif
                </div>
            </div>
            @endif

            @if($properties->isEmpty())
            <div class="rounded-xl py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 15.803a7.5 7.5 0 0 0 10.607 0Z" /></svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No properties match your current filters</h3>
                <p class="text-sm" style="color: var(--text-muted);">Try adjusting the search criteria below — broaden the price range, suburb, or rooms.</p>
            </div>
            @else
            <div id="matchList" class="space-y-3">
                @foreach($properties as $property)
                @php
                    $thumb = $property->thumbFor(
                        $property->gallery_images_json[0]
                        ?? $property->dawn_images_json[0]
                        ?? $property->noon_images_json[0]
                        ?? $property->dusk_images_json[0]
                        ?? null
                    );
                    $reaction = $feedback[$property->id]->reaction ?? null;
                    $score = (int) ($property->match_score ?? 0);
                    $scoreVariant = $score >= 80 ? 'ds-badge-success' : ($score >= 60 ? 'ds-badge-info' : 'ds-badge-warning');
                    $statusVariant = match($property->status) {
                        'active'    => 'ds-badge-success',
                        'sold'      => 'ds-badge-info',
                        'withdrawn' => 'ds-badge-warning',
                        default     => 'ds-badge-default',
                    };
                    $statusLabel = $property->status === 'active' ? 'For Sale' : ucfirst($property->status);
                @endphp
                <article class="match-card surface-card overflow-hidden"
                         data-price="{{ (int) $property->price > 0 ? (int) $property->price : '' }}"
                         data-score="{{ $score }}"
                         data-suburb="{{ $property->suburb }}"
                         data-beds="{{ (int) $property->beds }}">
                    {{-- agent=none: the client already has their own agent, so the
                         listing agent's identity/contact is hidden on this preview. --}}
                    <a href="{{ route('corex.properties.preview', [$property, \Illuminate\Support\Str::slug($property->title)]) }}?agent=none"
                       target="_blank"
                       data-record-view="{{ route('shared.match.view', [$token, $property->id]) }}"
                       class="property-card-link flex flex-col sm:flex-row gap-0 group"
                       style="color: inherit;">

                        {{-- Image --}}
                        <div class="relative flex-shrink-0 overflow-hidden sm:w-[220px] sm:min-h-[160px]"
                             style="background: var(--surface-2); aspect-ratio: 16/10;">
                            @if($thumb)
                            <img src="{{ $thumb }}" alt="{{ $property->title }}"
                                 class="absolute inset-0 w-full h-full object-cover transition-transform duration-300 group-hover:scale-105">
                            @else
                            <div class="absolute inset-0 flex items-center justify-center">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-10 h-10" style="color: var(--text-muted); opacity: 0.4;"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z" /></svg>
                            </div>
                            @endif
                            @if($score > 0)
                            <div class="absolute top-2 left-2 ds-badge {{ $scoreVariant }}" style="backdrop-filter: blur(6px);">{{ $score }}% match</div>
                            @endif
                        </div>

                        {{-- Content --}}
                        <div class="flex-1 min-w-0 p-4 flex flex-col justify-between gap-3">
                            <div>
                                <div class="flex items-start justify-between gap-3 mb-1.5">
                                    <span class="ds-badge {{ $statusVariant }}">{{ $statusLabel }}</span>
                                </div>
                                <div class="text-xl font-extrabold leading-tight" style="color: var(--brand-default);">
                                    {{ $property->formattedPrice() }}
                                </div>
                                <div class="text-sm font-medium leading-snug mt-0.5" style="color: var(--text-primary);">
                                    {{ $property->title ?: 'Property Listing' }}
                                </div>
                                @if($property->suburb)
                                <div class="flex items-center gap-1 text-xs mt-1.5" style="color: var(--text-muted);">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" /></svg>
                                    {{ $property->suburb }}{{ $property->city ? ', '.$property->city : '' }}
                                </div>
                                @endif
                            </div>

                            <div class="flex items-center justify-between gap-3 flex-wrap">
                                <div class="flex items-center gap-4 text-xs" style="color: var(--text-secondary);">
                                    @foreach([[$property->beds,'Beds'],[$property->baths,'Baths'],[$property->garages,'Gar']] as [$v,$l])
                                    @if($v)
                                    <div class="flex items-baseline gap-1">
                                        <span class="text-sm font-semibold" style="color: var(--text-primary);">{{ $v }}</span>
                                        <span class="text-[0.6875rem]" style="color: var(--text-muted);">{{ $l }}</span>
                                    </div>
                                    @endif
                                    @endforeach
                                    @if($property->size_m2)
                                    <div class="flex items-baseline gap-1">
                                        <span class="text-sm font-semibold" style="color: var(--text-primary);">{{ number_format($property->size_m2) }}</span>
                                        <span class="text-[0.6875rem]" style="color: var(--text-muted);">m²</span>
                                    </div>
                                    @endif
                                </div>
                                <span class="inline-flex items-center gap-1 text-xs font-semibold" style="color: var(--brand-icon);">
                                    View listing
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                                </span>
                            </div>
                        </div>
                    </a>

                    {{-- Feedback row --}}
                    <div class="flex items-center justify-between gap-2 px-4 py-2.5 flex-wrap"
                         style="border-top: 1px solid var(--border); background: var(--surface-2);">
                        <div class="text-xs font-medium" style="color: var(--text-secondary);">What do you think of this one?</div>
                        <div class="flex items-center gap-1.5"
                             data-feedback-url="{{ route('shared.match.feedback', [$token, $property->id]) }}"
                             data-property-id="{{ $property->id }}">
                            @foreach([
                                ['interested',     'Interested', 'var(--ds-green)'],
                                ['not_interested', 'Not for me', 'var(--text-muted)'],
                            ] as [$key,$label,$colour])
                            <button type="button"
                                    class="feedback-btn {{ $reaction === $key ? 'is-active' : '' }}"
                                    data-reaction="{{ $key }}"
                                    data-colour="{{ $colour }}"
                                    @if($reaction === $key) style="background: {{ $colour }}; border-color: {{ $colour }}; color: #fff;" @endif>
                                {{ $label }}
                            </button>
                            @endforeach
                        </div>
                    </div>
                </article>
                @endforeach
            </div>

            {{-- Load more (client-side — reveals 20 more of the already-loaded, score-ranked results) --}}
            <div id="loadMoreWrap" class="flex justify-center mt-5" style="display:none;">
                <button type="button" id="loadMoreBtn" class="btn-outline" style="padding:0.625rem 1.5rem;">
                    Load more <span id="loadMoreRemain" class="font-normal" style="color: var(--text-muted);"></span>
                </button>
            </div>

            {{-- Filtered-empty state (shown by JS when the refine bar hides every card) --}}
            <div id="filteredEmpty" class="rounded-xl py-10 px-6 text-center mt-3" style="display:none; background: var(--surface); border: 1px dashed var(--border);">
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No matches for these filters</h3>
                <p class="text-sm" style="color: var(--text-muted);">Loosen the price range or match minimum, or <button type="button" id="filteredClear" class="font-semibold" style="color: var(--brand-icon);">clear the filters</button>.</p>
            </div>

            {{-- Pagination --}}
            @if($properties instanceof Paginator && $properties->hasPages())
            <div class="flex items-center justify-center gap-3 mt-6">
                @if($properties->onFirstPage())
                <span class="btn-outline" aria-disabled="true">← Previous</span>
                @else
                <a href="{{ $properties->previousPageUrl() }}" class="btn-outline">← Previous</a>
                @endif

                <span class="text-xs font-semibold px-3 py-2 rounded-md"
                      style="background: color-mix(in srgb, var(--brand-icon) 10%, transparent); color: var(--brand-icon); border: 1px solid color-mix(in srgb, var(--brand-icon) 25%, transparent);">
                    {{ number_format($properties->currentPage()) }} / {{ number_format($properties->lastPage()) }}
                </span>

                @if($properties->hasMorePages())
                <a href="{{ $properties->nextPageUrl() }}" class="btn-primary">Next →</a>
                @else
                <span class="btn-outline" aria-disabled="true">Next →</span>
                @endif
            </div>
            @endif
            @endif
        </section>

        {{-- Change search criteria (server-side — re-runs the match with wider bounds) --}}
        <details class="surface-card overflow-hidden">
            <summary class="flex items-center gap-2 px-5 lg:px-6 py-4 cursor-pointer select-none" style="list-style:none;">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color: var(--brand-icon);"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" /></svg>
                <div>
                    <h2 class="text-base font-bold" style="color: var(--text-primary);">Change your search criteria</h2>
                    <p class="text-xs" style="color: var(--text-muted);">Broaden the underlying search — this re-runs your matches from scratch.</p>
                </div>
            </summary>

            <form method="GET" action="{{ route('shared.match', $token) }}" class="space-y-5 px-5 lg:px-6 pb-6 pt-1">

                {{-- Price range --}}
                <div>
                    <label class="field-label">Price range (R)</label>
                    <div class="grid grid-cols-2 gap-3">
                        <input type="number" name="price_min" value="{{ old('price_min', $filters['priceMin']) }}"
                               placeholder="Min price" min="0" step="50000" class="field-input">
                        <input type="number" name="price_max" value="{{ old('price_max', $filters['priceMax']) }}"
                               placeholder="Max price" min="0" step="50000" class="field-input">
                    </div>
                </div>

                {{-- Suburb --}}
                <div>
                    <label class="field-label">Suburb</label>
                    <input type="text" name="suburb" value="{{ old('suburb', $filters['suburb']) }}"
                           placeholder="e.g. Uvongo, Margate, Shelly Beach" class="field-input">
                </div>

                {{-- Rooms --}}
                <div>
                    <label class="field-label">Minimum rooms</label>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <span class="field-helper">Bedrooms</span>
                            <input type="number" name="beds_min" value="{{ old('beds_min', $filters['bedsMin']) }}"
                                   placeholder="Any" min="0" max="20" class="field-input">
                        </div>
                        <div>
                            <span class="field-helper">Bathrooms</span>
                            <input type="number" name="baths_min" value="{{ old('baths_min', $filters['bathsMin']) }}"
                                   placeholder="Any" min="0" max="20" class="field-input">
                        </div>
                        <div>
                            <span class="field-helper">Garages</span>
                            <input type="number" name="garages_min" value="{{ old('garages_min', $filters['garagesMin']) }}"
                                   placeholder="Any" min="0" max="20" class="field-input">
                        </div>
                    </div>
                </div>

                {{-- Floor + Erf --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="field-label">Floor size (m²)</label>
                        <div class="grid grid-cols-2 gap-2">
                            <input type="number" name="floor_size_min" value="{{ old('floor_size_min', $filters['floorMin']) }}"
                                   placeholder="Min" min="0" class="field-input">
                            <input type="number" name="floor_size_max" value="{{ old('floor_size_max', $filters['floorMax']) }}"
                                   placeholder="Max" min="0" class="field-input">
                        </div>
                    </div>
                    <div>
                        <label class="field-label">Erf size (m²)</label>
                        <div class="grid grid-cols-2 gap-2">
                            <input type="number" name="erf_size_min" value="{{ old('erf_size_min', $filters['erfMin']) }}"
                                   placeholder="Min" min="0" class="field-input">
                            <input type="number" name="erf_size_max" value="{{ old('erf_size_max', $filters['erfMax']) }}"
                                   placeholder="Max" min="0" class="field-input">
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="btn-primary">Update results</button>
                    <a href="{{ route('shared.match', $token) }}" class="btn-outline">Reset to defaults</a>
                </div>
            </form>
        </details>

    </main>

    {{-- "Not for me" reason modal --}}
    <div id="reasonModal" class="fixed inset-0 z-50 items-center justify-center p-4" style="display:none; background: rgba(0,0,0,0.5);">
        <div class="w-full max-w-md rounded-xl overflow-hidden"
             style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 10px 30px rgba(0,0,0,0.18);"
             onclick="event.stopPropagation()">
            <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
                <div class="text-base font-semibold" style="color: var(--text-primary);">Quick feedback (optional)</div>
                <div class="text-xs mt-0.5" style="color: var(--text-muted);">Tell your agent why this property isn't for you — it helps us show you better matches. Or skip and close.</div>
            </div>
            <div class="px-5 py-4">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Reason</label>
                <textarea id="reasonText" rows="4" placeholder="e.g. Too far from town, no garden, kitchen feels small…"
                          class="field-input" style="resize: vertical; line-height: 1.5;"></textarea>
            </div>
            <div class="px-5 pb-4 flex items-center justify-end gap-2">
                <button type="button" id="reasonSkip" class="btn-outline">Skip</button>
                <button type="button" id="reasonSubmit" class="btn-primary">Submit feedback</button>
            </div>
        </div>
    </div>

    {{-- Footer --}}
    <footer class="mt-8 py-5 text-center text-xs" style="background: var(--brand-default); color: rgba(255,255,255,0.55);">
        <div class="font-semibold text-white">
            {{ $agency->name ?? 'Property Matches' }}
        </div>
        <div class="mt-0.5">Registered with the PPRA</div>
        <div class="mt-1">
            @if(!empty($agency) && $agency->city){{ $agency->city }}@endif
            @if($match->createdBy) · {{ $match->createdBy->name }} @endif
        </div>
    </footer>

    <script>
        /* ---- Record view + feedback (unchanged behaviour) ---- */
        document.querySelectorAll('.property-card-link').forEach(function(link) {
            link.addEventListener('click', function() {
                var url = this.dataset.recordView;
                if (url) fetch(url, {keepalive: true});
            });
        });

        var reasonModal   = document.getElementById('reasonModal');
        var reasonText    = document.getElementById('reasonText');
        var reasonSkip    = document.getElementById('reasonSkip');
        var reasonSubmit  = document.getElementById('reasonSubmit');
        var pendingCtx    = null;

        function openReasonModal(ctx) {
            pendingCtx = ctx;
            reasonText.value = '';
            reasonModal.style.display = 'flex';
            setTimeout(function () { reasonText.focus(); }, 50);
        }
        function closeReasonModal() { reasonModal.style.display = 'none'; pendingCtx = null; }
        reasonModal.addEventListener('click', function (e) {
            if (e.target === reasonModal) {
                if (pendingCtx) submitReaction(pendingCtx, null);
                closeReasonModal();
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && reasonModal.style.display === 'flex') {
                if (pendingCtx) submitReaction(pendingCtx, null);
                closeReasonModal();
            }
        });
        reasonSkip.addEventListener('click', function () {
            if (pendingCtx) submitReaction(pendingCtx, null);
            closeReasonModal();
        });
        reasonSubmit.addEventListener('click', function () {
            var note = (reasonText.value || '').trim();
            if (pendingCtx) submitReaction(pendingCtx, note || null);
            closeReasonModal();
        });

        function applyActive(wrap, clicked) {
            wrap.querySelectorAll('.feedback-btn').forEach(function (b) {
                b.classList.remove('is-active'); b.style.background = ''; b.style.borderColor = ''; b.style.color = '';
            });
            var col = clicked.dataset.colour || 'var(--brand-icon)';
            clicked.classList.add('is-active');
            clicked.style.background = col; clicked.style.borderColor = col; clicked.style.color = '#fff';
        }

        function submitReaction(ctx, note) {
            var body = { reaction: ctx.reaction };
            if (note) body.note = note;
            fetch(ctx.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                },
                body: JSON.stringify(body),
                credentials: 'same-origin'
            }).then(function (r) { if (r.ok) applyActive(ctx.wrap, ctx.clicked); });
        }

        document.querySelectorAll('.feedback-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                var wrap = this.closest('[data-feedback-url]');
                if (!wrap) return;
                var ctx = { wrap: wrap, clicked: this, url: wrap.dataset.feedbackUrl, reaction: this.dataset.reaction };
                if (ctx.reaction === 'not_interested') openReasonModal(ctx);
                else submitReaction(ctx, null);
            });
        });

        /* ---- Refine bar (instant client-side filtering) + Load more (20 at a time) ---- */
        (function () {
            var bar = document.getElementById('refineBar');
            if (!bar) return;

            var PAGE  = 10;   // initial + per-click reveal count
            var limit = PAGE; // how many eligible cards are currently shown

            // Cards are rendered in server order = match_score descending, so the
            // strongest (e.g. 100%) matches naturally come first unless filtered.
            var cards       = Array.prototype.slice.call(document.querySelectorAll('#matchList .match-card'));
            var totalCards  = cards.length;
            var shownCount  = document.getElementById('shownCount');
            var emptyState  = document.getElementById('filteredEmpty');
            var loadWrap    = document.getElementById('loadMoreWrap');
            var loadBtn     = document.getElementById('loadMoreBtn');
            var loadRemain  = document.getElementById('loadMoreRemain');

            var fLocation = document.getElementById('fLocation');
            var fBeds     = document.getElementById('fBeds');
            var fMatch    = document.getElementById('fMatch');
            var fMatchVal = document.getElementById('fMatchVal');
            var fPriceMin = document.getElementById('fPriceMin');
            var fPriceMax = document.getElementById('fPriceMax');
            var fPriceMinVal = document.getElementById('fPriceMinVal');
            var fPriceMaxVal = document.getElementById('fPriceMaxVal');

            function zar(n) { return new Intl.NumberFormat('en-ZA').format(Math.round(n || 0)); }

            function passes(c) {
                var loc  = fLocation ? fLocation.value : '';
                var beds = fBeds ? parseInt(fBeds.value, 10) : 0;
                var minS = fMatch ? parseInt(fMatch.value, 10) : 0;
                var pMin = fPriceMin ? parseInt(fPriceMin.value, 10) : null;
                var pMax = fPriceMax ? parseInt(fPriceMax.value, 10) : null;

                var price = c.dataset.price ? parseInt(c.dataset.price, 10) : null;
                var score = parseInt(c.dataset.score || '0', 10);
                var sub   = c.dataset.suburb || '';
                var b     = parseInt(c.dataset.beds || '0', 10);

                if (loc && sub !== loc) return false;
                if (beds && b < beds) return false;
                if (minS && score < minS) return false;
                // Listings without a price are never hidden by the price band.
                if (price !== null && pMin !== null && pMax !== null && (price < pMin || price > pMax)) return false;
                return true;
            }

            function render() {
                var eligible = 0, visible = 0;
                cards.forEach(function (c) {
                    if (passes(c)) {
                        eligible++;
                        if (visible < limit) { c.style.display = ''; visible++; }
                        else { c.style.display = 'none'; }
                    } else {
                        c.style.display = 'none';
                    }
                });

                if (shownCount) shownCount.textContent = 'Showing ' + visible + ' of ' + eligible;
                if (emptyState) emptyState.style.display = eligible === 0 ? 'block' : 'none';

                var remaining = eligible - visible;
                if (loadWrap) loadWrap.style.display = remaining > 0 ? 'flex' : 'none';
                if (loadRemain) loadRemain.textContent = remaining > 0 ? ('(' + remaining + ' more)') : '';
            }

            // A filter change resets paging back to the first 20.
            function refilter() { limit = PAGE; render(); }

            // Keep the two price thumbs from crossing.
            if (fPriceMin && fPriceMax) {
                var syncPrice = function () {
                    var lo = parseInt(fPriceMin.value, 10);
                    var hi = parseInt(fPriceMax.value, 10);
                    if (lo > hi) {
                        if (document.activeElement === fPriceMin) fPriceMax.value = lo;
                        else fPriceMin.value = hi;
                    }
                    fPriceMinVal.textContent = zar(fPriceMin.value);
                    fPriceMaxVal.textContent = zar(fPriceMax.value);
                };
                fPriceMin.addEventListener('input', function () { syncPrice(); refilter(); });
                fPriceMax.addEventListener('input', function () { syncPrice(); refilter(); });
                syncPrice();
            }
            if (fMatch)    fMatch.addEventListener('input', function () { fMatchVal.textContent = fMatch.value; refilter(); });
            if (fLocation) fLocation.addEventListener('change', refilter);
            if (fBeds)     fBeds.addEventListener('change', refilter);
            if (loadBtn)   loadBtn.addEventListener('click', function () { limit += PAGE; render(); });

            function clearAll() {
                if (fLocation) fLocation.value = '';
                if (fBeds)     fBeds.value = '0';
                if (fMatch)  { fMatch.value = '0'; fMatchVal.textContent = '0'; }
                if (fPriceMin && fPriceMax) {
                    fPriceMin.value = fPriceMin.min;
                    fPriceMax.value = fPriceMax.max;
                    fPriceMinVal.textContent = zar(fPriceMin.value);
                    fPriceMaxVal.textContent = zar(fPriceMax.value);
                }
                refilter();
            }
            var clearBtn = document.getElementById('refineClear');
            var clearBtn2 = document.getElementById('filteredClear');
            if (clearBtn)  clearBtn.addEventListener('click', clearAll);
            if (clearBtn2) clearBtn2.addEventListener('click', clearAll);

            bar.style.display = '';   // reveal (was hidden to avoid FOUC)
            render();
        })();
    </script>
</body>
</html>
