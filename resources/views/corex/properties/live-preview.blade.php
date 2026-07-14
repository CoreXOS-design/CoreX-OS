@php
    use Illuminate\Support\Str;

    $allImages = array_values(array_filter(array_merge(
        $property->gallery_images_json ?? [],
        $property->dawn_images_json    ?? [],
        $property->noon_images_json    ?? [],
        $property->dusk_images_json    ?? [],
    )));

    $agency   = $property->agency;
    $showAgent = $showAgent ?? ($displayAgent !== null);

    // Status → over-gallery badge. Concluded/dead states use the loud brand-red;
    // everything else uses the translucent ink glass badge.
    //
    // Keyed off normalizedStatus(), NEVER the raw column: `status` is mixed-case in
    // production (P24 writes 'Active' — 444 rows — and 'Sold'), so a raw lookup here
    // missed the map entirely and printed the internal value back at the client.
    //
    // The LIVE states are the only ones that depend on listing type — an active
    // rental reads "To Let", never "For Sale". Concluded and interim states mean the
    // same thing on both sides of the sale/rental line, so they ignore listing type.
    $isRental = $property->isRental();
    $liveLabel = $isRental ? 'To Let' : 'For Sale';

    $statusMap = [
        // Live — advertised as available.
        'active'        => [$liveLabel,   false],
        'for_sale'      => [$liveLabel,   false],
        'to_let'        => [$liveLabel,   false],
        'sales_listing' => [$liveLabel,   false],
        'pending'       => ['Pending',    false],
        'draft'         => ['Draft',      false],
        'under_offer'   => ['Under Offer', false],

        // Concluded — the deal is done. Never advertise these as available.
        'sold'          => ['Sold',       true],
        'transferred'   => ['Sold',       true],
        'rented'        => ['Rented',     true],
        'let_out'       => ['Let Out',    true],

        // Off-market.
        'withdrawn'     => ['Withdrawn',  true],
        'cancelled'     => ['Cancelled',  true],
        'expired'       => ['Expired',    true],
        'unavailable'   => ['Unavailable', true],
        'archived'      => ['Archived',   true],
    ];

    // Fallback title-cases and de-underscores, so an unmapped status can never show
    // a client a raw DB value like "Let_out".
    $normStatus = $property->normalizedStatus();
    [$statusLabel, $statusLoud] = $statusMap[$normStatus]
        ?? [ucwords(str_replace('_', ' ', $normStatus)) ?: $liveLabel, false];

    $features  = $property->features_json ?? [];
    $spaces    = $property->spaces_json   ?? [];
    $listedAgo = $property->listed_date ? $property->listed_date->diffForHumans() : null;
    $waNumber  = ($showAgent && $displayAgent && $displayAgent->cell)
        ? preg_replace('/[^0-9]/', '', $displayAgent->cell) : null;
    $agentPhone = ($showAgent && $displayAgent) ? ($displayAgent->cell ?: $displayAgent->phone) : null;

    $locationQuery = trim(collect([
        $property->street_address ?? null,
        $property->suburb,
        $property->city,
        'South Africa',
    ])->filter()->implode(', '));

    // Costs / key facts definition list
    $facts = collect([
        ['Type',     $property->property_type ? ucwords(str_replace('_', ' ', $property->property_type)) : null],
        ['Category',  $property->category ?? null],
        ['Suburb',    $property->suburb ?? null],
        ['City',      $property->city ?? null],
        ['Erf size',  $property->erf_size_m2 ? number_format($property->erf_size_m2) . ' m²' : null],
        ['Floor size',$property->size_m2 ? number_format($property->size_m2) . ' m²' : null],
        ['Rates & taxes', $property->rates_taxes ? 'R ' . number_format($property->rates_taxes) . ' / mo' : null],
        ['Levy',      $property->levy ? 'R ' . number_format($property->levy) . ' / mo' : null],
        ['Mandate',   $property->mandate_type ? ucfirst($property->mandate_type) : null],
        ['Listed',    $property->listed_date ? $property->listed_date->format('d M Y') : null],
    ])->filter(fn ($d) => !empty($d[1]));

    // Spec strip icons (inline lucide-style paths, stroked)
    $specs = collect([
        $property->beds    ? ['bed',    $property->beds,    'Beds']    : null,
        $property->baths   ? ['bath',   $property->baths,   'Baths']   : null,
        $property->garages ? ['car',    $property->garages, 'Garages'] : null,
        $property->size_m2 ? ['ruler',  number_format($property->size_m2) . ' m²', 'Floor'] : null,
        $property->erf_size_m2 ? ['land', number_format($property->erf_size_m2) . ' m²', 'Erf'] : null,
    ])->filter();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $property->title }} — {{ $agency->name ?? 'Home Finders Coastal' }}</title>
    <meta name="description" content="{{ Str::limit($property->excerpt ?? $property->description ?? $property->title, 160) }}">

    {{-- Inter (incl. 300 for the light headings) --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:300,400,500,600,700|jetbrains-mono:400,500,600&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        // Custom colour tokens — the CDN equivalent of the requested Tailwind v4
        // @theme block. Registering them here makes text-navy, bg-marine,
        // text-brand-red, bg-ink/50 … resolve on this standalone page.
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        ink:                '#060a1c',
                        'ink-soft':         '#0d1430',
                        navy:               '#141a4d',
                        marine:             '#3ba1e6',
                        'brand-red':        '#df1f2c',
                        'brand-red-bright': '#f5404d',
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                        mono: ['JetBrains Mono', 'ui-monospace', 'monospace'],
                    },
                },
            },
        };
    </script>
    <style>
        html { scroll-behavior: smooth; }
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }
        .num { font-family: 'JetBrains Mono', ui-monospace, monospace; font-variant-numeric: tabular-nums; }
        /* Marine range slider for the bond calculator */
        input[type=range] { -webkit-appearance:none; appearance:none; width:100%; height:3px; border-radius:9999px; background:#e2e8f0; outline:none; }
        input[type=range]::-webkit-slider-thumb { -webkit-appearance:none; width:15px; height:15px; border-radius:9999px; background:#3ba1e6; cursor:pointer; }
        input[type=range]::-moz-range-thumb { width:15px; height:15px; border-radius:9999px; background:#3ba1e6; cursor:pointer; border:0; }
        .lightbox { position:fixed; inset:0; background:rgba(6,10,28,.95); z-index:80; display:none; align-items:center; justify-content:center; }
        .lightbox.open { display:flex; }
    </style>
</head>
<body class="bg-white text-neutral-800"
      x-data="propertyPreview({{ json_encode($allImages) }})" x-init="init()">

<div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">

    {{-- Back link + brand + share row --}}
    <div class="flex items-center justify-between gap-4">
        <button type="button" onclick="if(history.length>1){history.back()}else{window.close()}"
                class="inline-flex items-center gap-2 text-sm font-medium tracking-wide text-neutral-600 hover:text-marine transition-colors">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
            Back
        </button>

        @if($agency && $agency->logo_path)
            <img src="{{ asset('storage/'.$agency->logo_path) }}" alt="{{ $agency->name }}" class="h-8 max-w-[150px] object-contain">
        @else
            <span class="text-navy text-lg font-light tracking-tight">{{ $agency->name ?? 'Home Finders Coastal' }}</span>
        @endif

        <button type="button" @click="share()"
                class="inline-flex items-center gap-2 text-sm font-medium tracking-wide text-neutral-600 hover:text-marine transition-colors">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z"/></svg>
            <span x-text="shared ? 'Copied' : 'Share'"></span>
        </button>
    </div>

    {{-- GALLERY --}}
    <div class="mt-6">
        <div class="relative aspect-video w-full overflow-hidden rounded-sm border border-slate-200 bg-ink">
            @if(count($allImages) > 0)
                <template x-for="(img, i) in images" :key="i">
                    <img :src="img" alt=""
                         class="absolute inset-0 h-full w-full object-cover transition-opacity duration-700"
                         :class="slide === i ? 'opacity-100' : 'opacity-0 pointer-events-none'"
                         @click="openLightbox(i)" :loading="i === 0 ? 'eager' : 'lazy'">
                </template>
            @else
                <div class="absolute inset-0 flex items-center justify-center text-sm text-white/40">No images uploaded</div>
            @endif

            {{-- Status badge --}}
            <span class="absolute left-4 top-4 rounded-full border px-3 py-1 text-[11px] tracking-[0.2em] uppercase backdrop-blur
                         {{ $statusLoud ? 'bg-brand-red border-brand-red text-white' : 'bg-ink/50 border-white/40 text-white' }}">
                {{ $statusLabel }}
            </span>

            @if(count($allImages) > 1)
                {{-- Prev / next --}}
                <button type="button" @click.stop="prev()" class="absolute left-4 top-1/2 -translate-y-1/2 flex h-10 w-10 items-center justify-center rounded-full bg-ink/40 text-white backdrop-blur transition-colors hover:bg-ink/70">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
                </button>
                <button type="button" @click.stop="next()" class="absolute right-4 top-1/2 -translate-y-1/2 flex h-10 w-10 items-center justify-center rounded-full bg-ink/40 text-white backdrop-blur transition-colors hover:bg-ink/70">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                </button>
                {{-- Counter --}}
                <span class="num absolute bottom-4 right-4 rounded-full bg-ink/50 px-3 py-1 text-[11px] tracking-widest text-white backdrop-blur">
                    <span x-text="slide + 1"></span> / {{ count($allImages) }}
                </span>
            @endif
        </div>

        {{-- Thumbnail strip --}}
        @if(count($allImages) > 1)
            <div class="mt-3 flex gap-2 overflow-x-auto pb-1">
                <template x-for="(img, i) in images" :key="'t'+i">
                    <button type="button" @click="slide = i"
                            class="h-16 w-24 flex-shrink-0 overflow-hidden rounded-sm border transition-all"
                            :class="slide === i ? 'border-marine ring-1 ring-marine' : 'border-slate-200 opacity-60 hover:opacity-100'">
                        <img :src="img" alt="" class="h-full w-full object-cover" loading="lazy">
                    </button>
                </template>
            </div>
        @endif
    </div>

    {{-- TITLE + PRICE --}}
    <div class="mt-8">
        <p class="text-marine text-xs font-semibold tracking-[0.2em] uppercase">
            {{ collect([$property->suburb, $property->city, $property->property_type ? ucwords(str_replace('_',' ',$property->property_type)) : null])->filter()->implode(' · ') ?: 'Property' }}
        </p>
        <h1 class="text-navy mt-2 text-3xl font-light tracking-tight sm:text-4xl">{{ $property->title }}</h1>
        <p class="text-brand-red mt-3 text-2xl font-semibold num">{{ $property->formattedPrice() }}</p>
    </div>

    {{-- SPEC STRIP --}}
    @if($specs->isNotEmpty())
        <div class="mt-8 flex flex-wrap justify-center gap-x-10 gap-y-6 rounded-sm border border-slate-200 bg-slate-50 p-6">
            @foreach($specs as [$icon, $value, $label])
                <div class="flex w-16 flex-col items-center gap-1.5 text-center">
                    <span class="text-marine">@include('corex.properties.partials.spec-icon', ['icon' => $icon])</span>
                    <span class="text-navy text-sm font-medium">{{ $value }}</span>
                    <span class="text-xs tracking-wide text-neutral-500 uppercase">{{ $label }}</span>
                </div>
            @endforeach
        </div>
    @endif

    {{-- TWO-COLUMN LAYOUT --}}
    <div class="mt-10 grid gap-10 lg:grid-cols-[2fr_1fr]">

        {{-- MAIN COLUMN --}}
        <div>

            {{-- About --}}
            @if($property->description || $property->excerpt)
                <section>
                    <h2 class="text-navy text-2xl font-light">About this home</h2>
                    @if($property->excerpt)
                        <p class="mt-4 text-neutral-600 leading-relaxed">{{ $property->excerpt }}</p>
                    @endif
                    @if($property->description)
                        <p class="mt-3 text-neutral-600 leading-relaxed" style="white-space:pre-line;">{{ $property->description }}</p>
                    @endif
                </section>
            @endif

            {{-- Features --}}
            @if(count($features) > 0)
                <section class="mt-10">
                    <h2 class="text-navy text-2xl font-light">Features &amp; amenities</h2>
                    <ul class="mt-4 grid grid-cols-2 gap-2.5 sm:grid-cols-3">
                        @foreach($features as $feature)
                            <li class="flex items-center gap-2 text-sm text-neutral-600">
                                <span class="bg-marine h-1.5 w-1.5 rounded-full flex-shrink-0"></span>
                                {{ is_array($feature) ? ($feature['name'] ?? '') : $feature }}
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Rooms & spaces --}}
            @if(count($spaces) > 0)
                <section class="mt-10">
                    <h2 class="text-navy text-2xl font-light">Rooms &amp; spaces</h2>
                    <ul class="mt-4 grid grid-cols-2 gap-2.5 sm:grid-cols-3">
                        @foreach($spaces as $space)
                            @php
                                $sName = is_array($space) ? ($space['name'] ?? '') : $space;
                                $sSize = is_array($space) ? ($space['size'] ?? null) : null;
                            @endphp
                            <li class="flex items-center gap-2 text-sm text-neutral-600">
                                <span class="bg-marine h-1.5 w-1.5 rounded-full flex-shrink-0"></span>
                                <span>{{ $sName }}</span>
                                @if($sSize)<span class="num text-xs text-neutral-400">· {{ $sSize }}</span>@endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Property details & costs (definition list) --}}
            @if($facts->isNotEmpty())
                <section class="mt-10">
                    <h2 class="text-navy text-2xl font-light">Property details</h2>
                    <dl class="mt-4 divide-y divide-slate-200 rounded-sm border border-slate-200 bg-slate-50">
                        @foreach($facts as [$label, $value])
                            <div class="flex justify-between px-5 py-3.5 text-sm">
                                <dt class="text-neutral-600">{{ $label }}</dt>
                                <dd class="text-navy font-medium num">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            @endif

            {{-- Bond calculator — sales only. A tenant takes no bond out on a rental,
                 and the sale `price` column is 0/null on a rental anyway, so on a
                 rental this section rendered a repayment schedule for a purchase
                 that will never happen. --}}
            @unless($isRental)
            <section class="mt-10" x-data="mortgageCalc({{ (int) $property->effectivePrice() }})">
                <h2 class="text-navy text-2xl font-light">Bond calculator</h2>
                <p class="mt-1 text-sm text-neutral-500">Estimate your monthly repayment. Indicative only.</p>
                <div class="mt-4 rounded-sm border border-slate-200 bg-slate-50 p-6">
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-3">
                        <div>
                            <div class="mb-2 flex items-center justify-between">
                                <label class="text-xs tracking-wide text-neutral-500 uppercase">Deposit</label>
                                <span class="num text-xs text-marine"><span x-text="depositPct"></span>%</span>
                            </div>
                            <input type="range" min="0" max="50" step="1" x-model.number="depositPct">
                            <div class="num mt-1 text-xs text-neutral-500">R <span x-text="fmt(deposit)"></span></div>
                        </div>
                        <div>
                            <div class="mb-2 flex items-center justify-between">
                                <label class="text-xs tracking-wide text-neutral-500 uppercase">Term</label>
                                <span class="num text-xs text-marine"><span x-text="years"></span> yrs</span>
                            </div>
                            <input type="range" min="5" max="30" step="1" x-model.number="years">
                        </div>
                        <div>
                            <div class="mb-2 flex items-center justify-between">
                                <label class="text-xs tracking-wide text-neutral-500 uppercase">Rate</label>
                                <span class="num text-xs text-marine"><span x-text="rate.toFixed(2)"></span>%</span>
                            </div>
                            <input type="range" min="7" max="15" step="0.25" x-model.number="rate">
                        </div>
                    </div>
                    <div class="mt-6 flex flex-wrap items-end justify-between gap-3 border-t border-slate-200 pt-5">
                        <div>
                            <div class="text-xs tracking-wide text-neutral-500 uppercase">Estimated monthly</div>
                            <div class="num text-navy text-2xl font-semibold">R <span x-text="fmt(monthly)"></span></div>
                        </div>
                        <div class="num text-right text-xs leading-relaxed text-neutral-500">
                            Loan: R <span x-text="fmt(loan)"></span><br>
                            Total interest: R <span x-text="fmt(totalInterest)"></span>
                        </div>
                    </div>
                </div>
            </section>
            @endunless

            {{-- Virtual tour --}}
            @if($property->virtual_tour_url)
                <section class="mt-10">
                    <h2 class="text-navy text-2xl font-light">Virtual tour</h2>
                    <p class="mt-1 text-sm text-neutral-500">Interactive 360° tour of the property.</p>
                    <div class="mt-4 aspect-video w-full overflow-hidden rounded-sm border border-slate-200 bg-black">
                        <iframe src="{{ $property->virtual_tour_url }}" class="h-full w-full" style="border:0;"
                                allow="fullscreen; xr-spatial-tracking; gyroscope; accelerometer; vr"
                                allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                    </div>
                </section>
            @endif

            {{-- Location --}}
            @if($locationQuery)
                <section class="mt-10">
                    <h2 class="text-navy text-2xl font-light">Location</h2>
                    <p class="mt-1 text-sm text-neutral-500">{{ $locationQuery }}</p>
                    <div class="mt-4 aspect-video w-full overflow-hidden rounded-sm border border-slate-200 bg-black">
                        <iframe src="https://maps.google.com/maps?q={{ urlencode($locationQuery) }}&z=14&output=embed"
                                class="h-full w-full" style="border:0;" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                    </div>
                </section>
            @endif
        </div>

        {{-- STICKY SIDEBAR --}}
        <aside>
            <div class="sticky top-24 space-y-6">

                {{-- Price card --}}
                <div class="rounded-sm border border-slate-200 bg-slate-50 p-6" id="enquire">
                    <p class="text-marine text-xs font-semibold tracking-[0.2em] uppercase">{{ $isRental ? 'Monthly rental' : 'Asking price' }}</p>
                    <p class="text-brand-red mt-2 text-2xl font-semibold num">
                        {{ $property->formattedPrice() }}{{ $isRental ? ' / month' : '' }}
                    </p>
                    @if($property->suburb || $property->city)
                        <p class="mt-1 text-sm text-neutral-500">{{ $property->suburb }}{{ $property->city ? ', '.$property->city : '' }}</p>
                    @endif
                    @if($listedAgo)
                        <p class="mt-1 text-xs text-neutral-400">Listed {{ $listedAgo }}</p>
                    @endif
                </div>

                @if($showAgent && $displayAgent)
                    {{-- Agent card --}}
                    <div class="rounded-sm border border-slate-200 bg-slate-50 p-6">
                        <p class="text-marine text-xs font-semibold tracking-[0.2em] uppercase">Listed by</p>
                        <div class="mt-4 flex items-center gap-4">
                            @if($displayAgent->profilePhotoUrl())
                                <img src="{{ $displayAgent->profilePhotoUrl() }}" alt="{{ $displayAgent->name }}"
                                     class="h-16 w-16 rounded-full object-cover">
                            @else
                                <div class="flex h-16 w-16 items-center justify-center rounded-full bg-navy text-lg font-light text-white">{{ $displayAgent->initials() }}</div>
                            @endif
                            <div class="min-w-0">
                                <p class="text-navy text-lg font-light">{{ $displayAgent->name }}</p>
                                @if($displayAgent->designation)
                                    <p class="text-marine text-xs font-semibold tracking-[0.15em] uppercase">{{ $displayAgent->designation }}</p>
                                @endif
                                @if($property->branch)
                                    <p class="mt-0.5 text-xs text-neutral-500">{{ $property->branch->name }}</p>
                                @endif
                            </div>
                        </div>

                        <div class="mt-5 space-y-3">
                            @if($agentPhone)
                                <a href="tel:{{ $agentPhone }}" class="flex items-center gap-2 text-neutral-600 hover:text-marine transition-colors">
                                    <svg class="h-4 w-4 text-neutral-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z"/></svg>
                                    <span class="num text-sm">{{ $agentPhone }}</span>
                                </a>
                            @endif
                            @if($waNumber)
                                <a href="https://wa.me/{{ $waNumber }}?text={{ urlencode('Hi '.$displayAgent->name.', I’m interested in '.$property->title) }}" target="_blank"
                                   class="flex items-center gap-2 text-neutral-600 hover:text-marine transition-colors">
                                    <svg class="h-4 w-4 text-neutral-400" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.12.554 4.122 1.524 5.859L.057 23.25l5.54-1.453A11.93 11.93 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.86 0-3.601-.5-5.098-1.372l-.365-.216-3.788.994.996-3.71-.237-.374A9.96 9.96 0 0 1 2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg>
                                    <span class="text-sm">WhatsApp</span>
                                </a>
                            @endif
                            @if($displayAgent->outward_email)
                                <a href="mailto:{{ $displayAgent->outward_email }}?subject={{ urlencode('Enquiry: '.$property->title) }}"
                                   class="flex items-center gap-2 text-neutral-600 hover:text-marine transition-colors">
                                    <svg class="h-4 w-4 text-neutral-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/></svg>
                                    <span class="text-sm">Email agent</span>
                                </a>
                            @endif
                        </div>

                        {{-- Quick enquiry --}}
                        @if($displayAgent->outward_email)
                            <form class="mt-5 space-y-2.5 border-t border-slate-200 pt-5"
                                  onsubmit="event.preventDefault(); const f=event.target; const body='Name: '+f.n.value+'%0AContact: '+f.c.value+'%0A%0A'+encodeURIComponent(f.m.value); window.location.href='mailto:{{ $displayAgent->outward_email }}?subject={{ urlencode('Enquiry: '.$property->title) }}&body='+body;">
                                <p class="text-marine text-xs font-semibold tracking-[0.2em] uppercase">Quick enquiry</p>
                                <input name="n" placeholder="Your name" required class="w-full rounded-sm border border-slate-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-marine focus:outline-none focus:ring-1 focus:ring-marine">
                                <input name="c" placeholder="Phone or email" required class="w-full rounded-sm border border-slate-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-marine focus:outline-none focus:ring-1 focus:ring-marine">
                                <textarea name="m" rows="3" class="w-full resize-none rounded-sm border border-slate-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-marine focus:outline-none focus:ring-1 focus:ring-marine">I'd like more information about {{ $property->title }}.</textarea>
                                <button type="submit" class="w-full rounded-sm bg-brand-red px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-brand-red-bright">Send enquiry</button>
                            </form>
                        @endif
                    </div>
                @endif

                {{-- Agency card --}}
                <div class="rounded-sm border border-slate-200 bg-slate-50 p-6 text-center">
                    @if($agency && $agency->logo_path)
                        <img src="{{ asset('storage/'.$agency->logo_path) }}" alt="{{ $agency->name }}" class="mx-auto h-8 max-w-[150px] object-contain">
                    @else
                        <p class="text-navy text-lg font-light">{{ $agency->name ?? 'Home Finders Coastal' }}</p>
                    @endif
                    @if($property->branch)
                        <p class="mt-1 text-xs text-neutral-500">{{ $property->branch->name }}</p>
                    @endif
                </div>
            </div>
        </aside>
    </div>
</div>

{{-- Footer --}}
<footer class="border-t border-slate-200 py-8 text-center text-xs text-neutral-400">
    <p class="text-navy text-sm font-light">{{ $agency->name ?? 'Home Finders Coastal' }}</p>
    <p class="mt-1">Shelly Beach · KZN South Coast · This is a live preview for internal use only.</p>
</footer>

{{-- LIGHTBOX --}}
<div class="lightbox" :class="{ 'open': lightboxOpen }" @keydown.escape.window="lightboxOpen=false" @click.self="lightboxOpen=false">
    <button type="button" class="absolute right-6 top-6 flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20" @click="lightboxOpen=false">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
    </button>
    @if(count($allImages) > 1)
        <button type="button" class="absolute left-6 top-1/2 -translate-y-1/2 flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20" @click="prev()">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
        </button>
        <button type="button" class="absolute right-6 top-1/2 -translate-y-1/2 flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20" @click="next()">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
        </button>
    @endif
    <template x-if="lightboxOpen">
        <img :src="images[slide]" alt="" style="max-width:92vw; max-height:88vh; object-fit:contain;">
    </template>
    <div class="num absolute bottom-6 left-0 right-0 text-center text-sm text-white/70">
        <span x-text="slide + 1"></span> / <span x-text="images.length"></span>
    </div>
</div>

<script>
    function propertyPreview(images) {
        return {
            images: images || [],
            slide: 0,
            lightboxOpen: false,
            shared: false,
            init() {
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
            openLightbox(i) { this.slide = i; this.lightboxOpen = true; },
            share() {
                const url = window.location.href;
                const done = () => { this.shared = true; setTimeout(() => this.shared = false, 2000); };
                if (navigator.share) { navigator.share({ title: document.title, url }).catch(() => {}); return; }
                if (navigator.clipboard) { navigator.clipboard.writeText(url).then(done).catch(done); }
                else { done(); }
            },
        };
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
                const m = this.loan * (r * Math.pow(1 + r, n)) / (Math.pow(1 + r, n) - 1);
                return Math.round(m || 0);
            },
            get totalInterest() { return Math.max(0, this.monthly * this.years * 12 - this.loan); },
            fmt(n) { return new Intl.NumberFormat('en-ZA').format(n || 0); },
        };
    }
</script>
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</body>
</html>
