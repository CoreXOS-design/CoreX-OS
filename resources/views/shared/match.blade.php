<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Property Matches — Home Finders Coastal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background:#f0f4f8; color:#1a1a2e; margin:0; }
        input, select { outline: none; }
        input:focus, select:focus { border-color: #00b4d8 !important; }
        .card { background:#fff; border:1px solid #e2e8f0; border-radius:16px; }
        .btn-primary { background:#00b4d8; color:#fff; border:none; border-radius:10px; padding:10px 20px; font-size:13px; font-weight:600; cursor:pointer; transition:opacity .15s; }
        .btn-primary:hover { opacity:.85; }
        .field-label { display:block; font-size:11px; font-weight:600; color:#64748b; margin-bottom:4px; text-transform:uppercase; letter-spacing:.04em; }
        .field-input { width:100%; border:1px solid #e2e8f0; border-radius:8px; padding:8px 12px; font-size:13px; color:#1a1a2e; background:#f8fafc; }
    </style>
</head>
<body>

    {{-- Nav --}}
    <nav style="background:#0b2a4a; border-bottom:3px solid #00b4d8;">
        <div class="max-w-5xl mx-auto px-5 py-4 flex items-center justify-between">
            <div class="font-extrabold text-xl tracking-tight text-white">
                Home Finders <span style="color:#00b4d8;">Coastal</span>
            </div>
            <div class="text-xs font-semibold px-3 py-1.5 rounded-full" style="background:rgba(0,180,216,0.2); color:#00b4d8; border:1px solid rgba(0,180,216,0.3);">
                Property Matches
            </div>
        </div>
    </nav>

    <div class="max-w-5xl mx-auto px-4 py-8 space-y-6">

        {{-- ① Contact details --}}
        <div class="card p-6">
            <div class="flex items-start gap-5 flex-wrap">
                {{-- Avatar --}}
                <div class="w-16 h-16 rounded-2xl flex items-center justify-center flex-shrink-0 text-xl font-bold text-white"
                     style="background:linear-gradient(135deg,#0b2a4a,#00b4d8);">
                    {{ strtoupper(substr($contact->first_name,0,1).substr($contact->last_name,0,1)) }}
                </div>
                {{-- Info --}}
                <div class="flex-1 min-w-0">
                    <h1 class="text-xl font-extrabold" style="color:#0b2a4a;">{{ $contact->full_name }}</h1>
                    <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1.5 text-sm" style="color:#64748b;">
                        @if($contact->phone)
                        <a href="tel:{{ $contact->phone }}" class="flex items-center gap-1.5 no-underline hover:underline" style="color:inherit;">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                            {{ $contact->phone }}
                        </a>
                        @endif
                        @if($contact->email)
                        <a href="mailto:{{ $contact->email }}" class="flex items-center gap-1.5 no-underline hover:underline" style="color:inherit;">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                            {{ $contact->email }}
                        </a>
                        @endif
                    </div>
                </div>
                {{-- Agent contact (who created the match) --}}
                @if($match->createdBy)
                <div class="flex-shrink-0 text-right">
                    <div class="text-xs font-semibold" style="color:#94a3b8;">Your Agent</div>
                    <div class="text-sm font-bold mt-0.5" style="color:#0b2a4a;">{{ $match->createdBy->name }}</div>
                    @if($match->createdBy->cell || $match->createdBy->phone)
                    <a href="tel:{{ $match->createdBy->cell ?? $match->createdBy->phone }}"
                       class="text-xs no-underline hover:underline mt-0.5 block" style="color:#00b4d8;">
                        {{ $match->createdBy->cell ?? $match->createdBy->phone }}
                    </a>
                    @endif
                </div>
                @endif
            </div>
        </div>

        {{-- ② Property results --}}
        <div>
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-base font-extrabold" style="color:#0b2a4a;">
                    Properties Found
                    <span class="ml-2 text-sm font-semibold px-2 py-0.5 rounded-full"
                          style="background:rgba(0,180,216,0.12); color:#00b4d8;">
                        {{ $properties->total() }}
                    </span>
                </h2>
                @if($properties->total() > 0)
                <span class="text-xs" style="color:#94a3b8;">
                    Page {{ $properties->currentPage() }} of {{ $properties->lastPage() }}
                </span>
                @endif
            </div>

            @if($properties->isEmpty())
            <div class="card py-14 text-center">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-12 h-12 mx-auto mb-3 opacity-20" style="color:#0b2a4a;"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 15.803a7.5 7.5 0 0 0 10.607 0Z" /></svg>
                <p class="font-bold text-base" style="color:#64748b;">No properties match your current filters.</p>
                <p class="text-sm mt-1" style="color:#94a3b8;">Try adjusting the search criteria below.</p>
            </div>
            @else
            <div class="space-y-4">
                @foreach($properties as $property)
                @php
                    $thumb = $property->gallery_images_json[0]
                        ?? $property->dawn_images_json[0]
                        ?? $property->noon_images_json[0]
                        ?? $property->dusk_images_json[0]
                        ?? null;
                @endphp
                <a href="{{ route('corex.properties.preview', $property) }}?agent=listing"
                   target="_blank"
                   data-record-view="{{ route('shared.match.view', [$token, $property->id]) }}"
                   class="card flex gap-0 overflow-hidden no-underline group transition-all duration-200 property-card-link"
                   style="display:flex;"
                   onmouseover="this.style.borderColor='#00b4d8'; this.style.boxShadow='0 4px 20px rgba(0,180,216,0.12)'"
                   onmouseout="this.style.borderColor='#e2e8f0'; this.style.boxShadow=''">

                    {{-- Image --}}
                    <div class="flex-shrink-0 relative overflow-hidden" style="width:200px; min-height:140px; background:#f1f5f9;">
                        @if($thumb)
                        <img src="{{ $thumb }}" alt="{{ $property->title }}"
                             class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105"
                             style="position:absolute; inset:0;">
                        @else
                        <div class="w-full h-full flex items-center justify-center" style="position:absolute; inset:0;">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-10 h-10 opacity-20" style="color:#0b2a4a;"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" /></svg>
                        </div>
                        @endif
                    </div>

                    {{-- Content --}}
                    <div class="flex-1 min-w-0 p-4 flex flex-col justify-between">
                        <div class="space-y-1.5">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex-1 min-w-0">
                                    <div class="text-base font-extrabold truncate" style="color:#0b2a4a;">{{ $property->formattedPrice() }}</div>
                                    <div class="text-sm font-semibold truncate mt-0.5" style="color:#1e293b;">{{ $property->title ?: 'Property Listing' }}</div>
                                </div>
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full flex-shrink-0 text-white"
                                      style="background:{{ ['active'=>'#22c55e','draft'=>'#94a3b8','sold'=>'#3b82f6'][$property->status] ?? '#94a3b8' }};">
                                    {{ $property->status === 'active' ? 'For Sale' : ucfirst($property->status) }}
                                </span>
                            </div>

                            @if($property->suburb)
                            <div class="flex items-center gap-1 text-xs" style="color:#64748b;">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" /></svg>
                                {{ $property->suburb }}{{ $property->city ? ', '.$property->city : '' }}
                            </div>
                            @endif
                        </div>

                        {{-- Stats --}}
                        <div class="flex items-center gap-4 mt-3 flex-wrap">
                            @foreach([[$property->beds,'Beds'],[$property->baths,'Baths'],[$property->garages,'Gar']] as [$v,$l])
                            @if($v)
                            <div class="text-center">
                                <div class="text-sm font-bold" style="color:#0b2a4a;">{{ $v }}</div>
                                <div class="text-[10px]" style="color:#94a3b8;">{{ $l }}</div>
                            </div>
                            @endif
                            @endforeach
                            @if($property->size_m2)
                            <div class="text-center">
                                <div class="text-sm font-bold" style="color:#0b2a4a;">{{ number_format($property->size_m2) }}</div>
                                <div class="text-[10px]" style="color:#94a3b8;">m²</div>
                            </div>
                            @endif
                            <div class="ml-auto flex items-center gap-1 text-xs font-semibold" style="color:#00b4d8;">
                                View listing
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                            </div>
                        </div>
                    </div>
                </a>
                @endforeach
            </div>

            {{-- Pagination --}}
            @if($properties->hasPages())
            <div class="flex items-center justify-center gap-3 mt-6">
                @if($properties->onFirstPage())
                <span class="px-4 py-2 rounded-lg text-sm font-semibold" style="background:#f1f5f9; color:#cbd5e1;">← Previous</span>
                @else
                <a href="{{ $properties->previousPageUrl() }}"
                   class="px-4 py-2 rounded-lg text-sm font-semibold no-underline"
                   style="background:#fff; border:1px solid #e2e8f0; color:#0b2a4a;"
                   onmouseover="this.style.borderColor='#00b4d8'" onmouseout="this.style.borderColor='#e2e8f0'">
                    ← Previous
                </a>
                @endif

                <span class="text-sm font-semibold px-4 py-2 rounded-lg" style="background:rgba(0,180,216,0.1); color:#00b4d8;">
                    {{ $properties->currentPage() }} / {{ $properties->lastPage() }}
                </span>

                @if($properties->hasMorePages())
                <a href="{{ $properties->nextPageUrl() }}"
                   class="px-4 py-2 rounded-lg text-sm font-semibold no-underline"
                   style="background:#00b4d8; color:#fff;"
                   onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                    Next →
                </a>
                @else
                <span class="px-4 py-2 rounded-lg text-sm font-semibold" style="background:#f1f5f9; color:#cbd5e1;">Next →</span>
                @endif
            </div>
            @endif
        @endif
        </div>

        {{-- ③ Adjust filters --}}
        <div class="card p-6">
            <h2 class="text-base font-extrabold mb-5" style="color:#0b2a4a;">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 inline mr-1.5 mb-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" /></svg>
                Adjust Your Search
            </h2>

            <form method="GET" action="{{ route('shared.match', $token) }}" class="space-y-5">

                {{-- Price range --}}
                <div>
                    <label class="field-label">Price Range (R)</label>
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
                    <label class="field-label">Minimum Rooms</label>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-[10px] mb-1" style="color:#94a3b8;">Bedrooms</label>
                            <input type="number" name="beds_min" value="{{ old('beds_min', $filters['bedsMin']) }}"
                                   placeholder="Any" min="0" max="20" class="field-input">
                        </div>
                        <div>
                            <label class="block text-[10px] mb-1" style="color:#94a3b8;">Bathrooms</label>
                            <input type="number" name="baths_min" value="{{ old('baths_min', $filters['bathsMin']) }}"
                                   placeholder="Any" min="0" max="20" class="field-input">
                        </div>
                        <div>
                            <label class="block text-[10px] mb-1" style="color:#94a3b8;">Garages</label>
                            <input type="number" name="garages_min" value="{{ old('garages_min', $filters['garagesMin']) }}"
                                   placeholder="Any" min="0" max="20" class="field-input">
                        </div>
                    </div>
                </div>

                {{-- Floor + Erf --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="field-label">Floor Size (m²)</label>
                        <div class="grid grid-cols-2 gap-2">
                            <input type="number" name="floor_size_min" value="{{ old('floor_size_min', $filters['floorMin']) }}"
                                   placeholder="Min" min="0" class="field-input">
                            <input type="number" name="floor_size_max" value="{{ old('floor_size_max', $filters['floorMax']) }}"
                                   placeholder="Max" min="0" class="field-input">
                        </div>
                    </div>
                    <div>
                        <label class="field-label">Erf Size (m²)</label>
                        <div class="grid grid-cols-2 gap-2">
                            <input type="number" name="erf_size_min" value="{{ old('erf_size_min', $filters['erfMin']) }}"
                                   placeholder="Min" min="0" class="field-input">
                            <input type="number" name="erf_size_max" value="{{ old('erf_size_max', $filters['erfMax']) }}"
                                   placeholder="Max" min="0" class="field-input">
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3 pt-1">
                    <button type="submit" class="btn-primary">
                        Update Results
                    </button>
                    <a href="{{ route('shared.match', $token) }}"
                       class="text-sm font-semibold no-underline" style="color:#94a3b8;"
                       onmouseover="this.style.color='#0b2a4a'" onmouseout="this.style.color='#94a3b8'">
                        Reset to defaults
                    </a>
                </div>
            </form>
        </div>

    </div>

    {{-- Footer --}}
    <div class="mt-8 py-5 text-center text-xs" style="background:#0b2a4a; color:rgba(255,255,255,0.4);">
        Home Finders Coastal · Shelly Beach, KZN South Coast
        @if($match->createdBy) · {{ $match->createdBy->name }} @endif
    </div>

    <script>
        document.querySelectorAll('.property-card-link').forEach(function(link) {
            link.addEventListener('click', function() {
                var url = this.dataset.recordView;
                if (url) fetch(url, {keepalive: true});
            });
        });
    </script>
</body>
</html>
