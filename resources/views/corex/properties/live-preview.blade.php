<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $property->title }} — Live Preview</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f6f7; color: #1a1a1a; margin: 0; }
        .hero-slide { display: none; }
        .hero-slide.active { display: block; }
        .dot { width:8px; height:8px; border-radius:50%; background:rgba(255,255,255,0.5); border:none; cursor:pointer; padding:0; }
        .dot.active { background:#fff; }
    </style>
</head>
<body>


    {{-- Site nav --}}
    <nav style="background:#fff; border-bottom:1px solid #e5e7eb;">
        <div class="max-w-6xl mx-auto px-5 py-3 flex items-center justify-between">
            @if($property->agency && $property->agency->logo_path)
            <img src="{{ asset('storage/'.$property->agency->logo_path) }}"
                 alt="{{ $property->agency->name ?? 'Agency' }}"
                 style="max-height:48px; max-width:180px; object-fit:contain;">
            @else
            <div class="font-extrabold text-lg tracking-tight" style="color:#0b2a4a;">
                Home Finders <span style="color:#00b4d8;">Coastal</span>
            </div>
            @endif
        </div>
    </nav>

    <div class="max-w-6xl mx-auto px-4 py-6">
        <div class="flex gap-6 items-start" style="flex-wrap:wrap;">

            {{-- LEFT: main content --}}
            <div class="flex-1" style="min-width:0;">

                {{-- Hero image carousel --}}
                @php
                    $allImages = array_values(array_filter(array_merge(
                        $property->gallery_images_json ?? [],
                        $property->dawn_images_json    ?? [],
                        $property->noon_images_json    ?? [],
                        $property->dusk_images_json    ?? [],
                    )));
                @endphp

                <div class="relative rounded-2xl overflow-hidden mb-4"
                     style="background:#1a1a2e; aspect-ratio:16/9;"
                     x-data="{ slide: 0, total: {{ count($allImages) }} }">

                    @if(count($allImages) > 0)
                        @foreach($allImages as $i => $img)
                        <div x-show="slide === {{ $i }}"
                             x-transition:enter="transition ease-out duration-300"
                             x-transition:enter-start="opacity-0"
                             x-transition:enter-end="opacity-100"
                             class="absolute inset-0">
                            <img src="{{ $img }}" alt="" class="w-full h-full object-cover">
                        </div>
                        @endforeach

                        {{-- Price + status overlay --}}
                        <div class="absolute bottom-0 left-0 right-0 px-5 py-4"
                             style="background:linear-gradient(to top, rgba(0,0,0,0.75) 0%, transparent 100%);">
                            <div class="text-white text-2xl font-extrabold">{{ $property->formattedPrice() }}</div>
                            <div class="text-white text-sm font-medium mt-0.5" style="opacity:.85;">
                                {{ $property->title }}
                            </div>
                        </div>

                        {{-- For Sale badge --}}
                        <div class="absolute top-4 left-4">
                            @php
                                $badgeColors = ['active'=>'#22c55e','draft'=>'#94a3b8','sold'=>'#3b82f6','withdrawn'=>'#f59e0b'];
                                $badgeLabels = ['active'=>'For Sale','draft'=>'Draft','sold'=>'Sold','withdrawn'=>'Withdrawn'];
                                $bc = $badgeColors[$property->status] ?? '#94a3b8';
                                $bl = $badgeLabels[$property->status] ?? ucfirst($property->status);
                            @endphp
                            <span class="text-xs font-bold px-3 py-1 rounded-full text-white"
                                  style="background:{{ $bc }};">{{ $bl }}</span>
                        </div>

                        {{-- Nav arrows --}}
                        @if(count($allImages) > 1)
                        <button type="button"
                                @click="slide = (slide - 1 + total) % total"
                                class="absolute left-3 top-1/2 -translate-y-1/2 w-9 h-9 rounded-full flex items-center justify-center"
                                style="background:rgba(0,0,0,0.45); color:#fff; border:none;">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                        </button>
                        <button type="button"
                                @click="slide = (slide + 1) % total"
                                class="absolute right-3 top-1/2 -translate-y-1/2 w-9 h-9 rounded-full flex items-center justify-center"
                                style="background:rgba(0,0,0,0.45); color:#fff; border:none;">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                        </button>

                        {{-- Dots --}}
                        <div class="absolute bottom-4 right-4 flex gap-1.5">
                            @foreach($allImages as $i => $img)
                            <button type="button" @click="slide = {{ $i }}"
                                    :class="slide === {{ $i }} ? 'opacity-100' : 'opacity-50'"
                                    class="w-2 h-2 rounded-full transition-opacity"
                                    style="background:#fff; border:none; padding:0;"></button>
                            @endforeach
                        </div>
                        @endif

                    @else
                        {{-- No images placeholder --}}
                        <div class="w-full h-full flex flex-col items-center justify-center" style="color:#4b5563;">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-16 h-16 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" /></svg>
                            <span class="text-sm">No images uploaded</span>
                        </div>
                    @endif
                </div>

                {{-- Stats bar --}}
                <div class="grid grid-cols-4 gap-3 mb-5"
                     x-data>
                    @foreach([
                        [$property->beds,    'Bedrooms',   'M3 12l2-8a4 4 0 0 1 4-4h0a4 4 0 0 1 4 4l2 8M5 12h14M9 20h6'],
                        [$property->baths,   'Bathrooms',  'M6 6h12M6 6v12M6 6l6-3 6 3M18 6v12M6 18h12'],
                        [$property->garages, 'Garages',    'M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12'],
                        [$property->size_m2 ? number_format($property->size_m2).' m²' : ($property->erf_size_m2 ? number_format($property->erf_size_m2).' m²' : '—'), 'Floor Size', 'M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15'],
                    ] as [$val, $label, $path])
                    <div class="rounded-xl p-3 text-center" style="background:#fff; border:1px solid #e5e7eb;">
                        <div class="text-lg font-extrabold" style="color:#0b2a4a;">{{ $val ?: '—' }}</div>
                        <div class="text-xs mt-0.5" style="color:#6b7280;">{{ $label }}</div>
                    </div>
                    @endforeach
                </div>

                {{-- Property details card --}}
                <div class="rounded-2xl p-5 mb-5" style="background:#fff; border:1px solid #e5e7eb;">
                    <h2 class="text-base font-bold mb-3" style="color:#0b2a4a;">Property Details</h2>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
                        @if($property->property_type)
                        <div>
                            <div class="text-xs font-medium mb-0.5" style="color:#9ca3af;">Type</div>
                            <div class="font-semibold" style="color:#1a1a1a;">{{ ucwords(str_replace('_',' ',$property->property_type)) }}</div>
                        </div>
                        @endif
                        @if($property->category)
                        <div>
                            <div class="text-xs font-medium mb-0.5" style="color:#9ca3af;">Category</div>
                            <div class="font-semibold" style="color:#1a1a1a;">{{ $property->category }}</div>
                        </div>
                        @endif
                        @if($property->suburb)
                        <div>
                            <div class="text-xs font-medium mb-0.5" style="color:#9ca3af;">Suburb</div>
                            <div class="font-semibold" style="color:#1a1a1a;">{{ $property->suburb }}</div>
                        </div>
                        @endif
                        @if($property->city)
                        <div>
                            <div class="text-xs font-medium mb-0.5" style="color:#9ca3af;">City</div>
                            <div class="font-semibold" style="color:#1a1a1a;">{{ $property->city }}</div>
                        </div>
                        @endif
                        @if($property->erf_size_m2)
                        <div>
                            <div class="text-xs font-medium mb-0.5" style="color:#9ca3af;">Erf Size</div>
                            <div class="font-semibold" style="color:#1a1a1a;">{{ number_format($property->erf_size_m2) }} m²</div>
                        </div>
                        @endif
                        @if($property->rates_taxes)
                        <div>
                            <div class="text-xs font-medium mb-0.5" style="color:#9ca3af;">Rates & Taxes</div>
                            <div class="font-semibold" style="color:#1a1a1a;">R {{ number_format($property->rates_taxes) }}/mo</div>
                        </div>
                        @endif
                        @if($property->levy)
                        <div>
                            <div class="text-xs font-medium mb-0.5" style="color:#9ca3af;">Levy</div>
                            <div class="font-semibold" style="color:#1a1a1a;">R {{ number_format($property->levy) }}/mo</div>
                        </div>
                        @endif
                        @if($property->mandate_type)
                        <div>
                            <div class="text-xs font-medium mb-0.5" style="color:#9ca3af;">Mandate</div>
                            <div class="font-semibold" style="color:#1a1a1a;">{{ ucfirst($property->mandate_type) }}</div>
                        </div>
                        @endif
                        @if($property->listed_date)
                        <div>
                            <div class="text-xs font-medium mb-0.5" style="color:#9ca3af;">Listed</div>
                            <div class="font-semibold" style="color:#1a1a1a;">{{ $property->listed_date->format('d M Y') }}</div>
                        </div>
                        @endif
                    </div>
                </div>

                {{-- Description --}}
                @if($property->description || $property->excerpt)
                <div class="rounded-2xl p-5 mb-5" style="background:#fff; border:1px solid #e5e7eb;">
                    <h2 class="text-base font-bold mb-3" style="color:#0b2a4a;">About this Property</h2>
                    @if($property->excerpt)
                    <p class="text-sm font-semibold mb-2" style="color:#374151;">{{ $property->excerpt }}</p>
                    @endif
                    @if($property->description)
                    <p class="text-sm leading-relaxed whitespace-pre-line" style="color:#6b7280;">{{ $property->description }}</p>
                    @endif
                </div>
                @endif

                {{-- Features --}}
                @php $features = $property->features_json ?? []; @endphp
                @if(count($features) > 0)
                <div class="rounded-2xl p-5 mb-5" style="background:#fff; border:1px solid #e5e7eb;">
                    <h2 class="text-base font-bold mb-3" style="color:#0b2a4a;">Features & Amenities</h2>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-y-2 gap-x-4">
                        @foreach($features as $feature)
                        <div class="flex items-center gap-2 text-sm" style="color:#374151;">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="color:#22c55e;"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            {{ is_array($feature) ? ($feature['name'] ?? '') : $feature }}
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- Spaces --}}
                @php $spaces = $property->spaces_json ?? []; @endphp
                @if(count($spaces) > 0)
                <div class="rounded-2xl p-5 mb-5" style="background:#fff; border:1px solid #e5e7eb;">
                    <h2 class="text-base font-bold mb-3" style="color:#0b2a4a;">Rooms & Spaces</h2>
                    <div class="space-y-1">
                        @foreach($spaces as $space)
                        @php
                            $sName = is_array($space) ? ($space['name'] ?? '') : $space;
                            $sSize = is_array($space) ? ($space['size'] ?? null) : null;
                        @endphp
                        <div class="flex items-center justify-between text-sm py-1.5" style="border-bottom:1px solid #f3f4f6;">
                            <span style="color:#374151;">{{ $sName }}</span>
                            @if($sSize)
                            <span class="font-medium" style="color:#6b7280;">{{ $sSize }}</span>
                            @endif
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif

            </div>{{-- /left --}}

            {{-- RIGHT: Agent card + enquiry --}}
            <div class="flex-shrink-0" style="width:280px;">
                <div class="rounded-2xl overflow-hidden sticky top-4" style="background:#fff; border:1px solid #e5e7eb;">

                    {{-- Price header --}}
                    <div class="px-5 py-4" style="background:#0b2a4a;">
                        <div class="text-2xl font-extrabold text-white">{{ $property->formattedPrice() }}</div>
                        <div class="text-xs mt-1 text-white" style="opacity:.7;">
                            {{ $property->suburb }}{{ $property->city ? ', '.$property->city : '' }}
                        </div>
                    </div>

                    {{-- Agent info --}}
                    <div class="p-5">
                        <p class="text-xs font-bold uppercase tracking-wider mb-3" style="color:#9ca3af;">Listed by</p>
                        <div class="flex items-center gap-3 mb-4">
                            @if($displayAgent->profilePhotoUrl())
                            <img src="{{ $displayAgent->profilePhotoUrl() }}"
                                 alt="{{ $displayAgent->name }}"
                                 class="w-14 h-14 rounded-full object-cover flex-shrink-0"
                                 style="border:2px solid #e5e7eb;">
                            @else
                            <div class="w-14 h-14 rounded-full flex items-center justify-center flex-shrink-0 text-lg font-bold text-white"
                                 style="background:#00b4d8;">
                                {{ $displayAgent->initials() }}
                            </div>
                            @endif
                            <div class="min-w-0">
                                <div class="font-bold text-sm leading-tight" style="color:#0b2a4a;">{{ $displayAgent->name }}</div>
                                @if($displayAgent->designation)
                                <div class="text-xs mt-0.5" style="color:#6b7280;">{{ $displayAgent->designation }}</div>
                                @endif
                                @if($property->branch)
                                <div class="text-xs mt-0.5" style="color:#9ca3af;">{{ $property->branch->name }}</div>
                                @endif
                            </div>
                        </div>

                        {{-- Contact buttons --}}
                        <div class="space-y-2">
                            @if($displayAgent->cell)
                            <a href="tel:{{ $displayAgent->cell }}"
                               class="flex items-center justify-center gap-2 w-full px-4 py-2.5 rounded-xl text-sm font-semibold text-white no-underline"
                               style="background:#00b4d8;">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                                Call: {{ $displayAgent->cell }}
                            </a>
                            @elseif($displayAgent->phone)
                            <a href="tel:{{ $displayAgent->phone }}"
                               class="flex items-center justify-center gap-2 w-full px-4 py-2.5 rounded-xl text-sm font-semibold text-white no-underline"
                               style="background:#00b4d8;">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                                Call: {{ $displayAgent->phone }}
                            </a>
                            @endif

                            <a href="mailto:{{ $displayAgent->email }}"
                               class="flex items-center justify-center gap-2 w-full px-4 py-2.5 rounded-xl text-sm font-semibold no-underline"
                               style="background:#f0f9ff; color:#0b2a4a; border:1px solid #bae6fd;">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                                Email Agent
                            </a>

                            @if($displayAgent->cell)
                            <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $displayAgent->cell) }}"
                               target="_blank"
                               class="flex items-center justify-center gap-2 w-full px-4 py-2.5 rounded-xl text-sm font-semibold no-underline"
                               style="background:#dcfce7; color:#15803d; border:1px solid #86efac;">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.12.554 4.122 1.524 5.859L.057 23.25l5.54-1.453A11.93 11.93 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.86 0-3.601-.5-5.098-1.372l-.365-.216-3.788.994.996-3.71-.237-.374A9.96 9.96 0 0 1 2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg>
                                WhatsApp
                            </a>
                            @endif
                        </div>

                        <div class="mt-4 pt-4" style="border-top:1px solid #f3f4f6;">
                            <div class="text-xs text-center" style="color:#9ca3af;">Home Finders Coastal</div>
                            @if($property->branch)
                            <div class="text-xs text-center mt-0.5" style="color:#9ca3af;">{{ $property->branch->name }}</div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    {{-- Footer --}}
    <div class="mt-8 py-6 text-center text-xs" style="background:#0b2a4a; color:rgba(255,255,255,0.5);">
        Home Finders Coastal · Shelly Beach, KZN South Coast · This is a live preview for internal use only.
    </div>

    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</body>
</html>
