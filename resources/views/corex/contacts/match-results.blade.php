@extends('layouts.corex')

@section('corex-content')
@php
    $defaultWaMsg = \App\Models\PerformanceSetting::get('matches_wa_message',
        "Hi {name}! \xf0\x9f\x91\x8b\n\nI've put together a personalised selection of properties that match your search criteria.\n\nView your property matches here:\n{link}\n\nFeel free to reach out if you'd like to arrange viewings or have any questions!"
    );
    $waPhone = preg_replace('/\D/', '', $contact->phone ?? '');
    if ($waPhone && str_starts_with($waPhone, '0')) {
        $waPhone = '27' . substr($waPhone, 1);
    }
    $renderedWaMsg = str_replace(['{name}', '{link}'], [$contact->first_name, $match->sharedUrl()], $defaultWaMsg);
    $totalViews = array_sum($match->property_view_counts ?? []);
    $hiddenCount = count($match->hidden_property_ids ?? []);
@endphp
<div class="space-y-5"
     x-data="{
         showWaModal: false,
         waMessage: {{ Js::from($renderedWaMsg) }},
         waPhone: '{{ $waPhone }}',
         sendWhatsApp() {
             if (!this.waPhone) return;
             window.open('https://wa.me/' + this.waPhone + '?text=' + encodeURIComponent(this.waMessage), '_blank');
             this.showWaModal = false;
         }
     }">

    {{-- Page header --}}
    <div class="rounded-2xl overflow-hidden"
         style="background:linear-gradient(135deg,#0b2a4a 0%,#0e3460 100%);">

        {{-- Top bar: back nav --}}
        <div class="px-6 pt-5 pb-0 flex items-center gap-2">
            <a href="{{ route('corex.contacts.show', $contact) }}?tab=matches"
               class="inline-flex items-center gap-1.5 text-xs font-semibold no-underline"
               style="color:rgba(255,255,255,0.45);"
               onmouseover="this.style.color='rgba(255,255,255,0.75)'" onmouseout="this.style.color='rgba(255,255,255,0.45)'">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                Back to {{ $contact->full_name }}
            </a>
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:rgba(255,255,255,0.2);"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            <span class="text-xs font-semibold" style="color:rgba(255,255,255,0.3);">Core Matches</span>
        </div>

        {{-- Contact + match info --}}
        <div class="px-6 pt-4 pb-5 flex items-start justify-between gap-6 flex-wrap">

            {{-- Left: contact + criteria --}}
            <div class="flex items-start gap-4 min-w-0">
                {{-- Avatar --}}
                <div class="w-12 h-12 rounded-xl flex items-center justify-center text-sm font-extrabold text-white flex-shrink-0"
                     style="background:{{ $contact->type?->color ?? '#334155' }};">
                    {{ $contact->initials }}
                </div>

                <div class="min-w-0">
                    {{-- Contact name + type --}}
                    <div class="flex items-center gap-2 flex-wrap mb-1">
                        <span class="text-base font-extrabold" style="color:#fff;">{{ $contact->full_name }}</span>
                        @if($contact->type)
                        <span class="text-[10px] px-1.5 py-0.5 rounded-full font-bold flex-shrink-0"
                              style="background:{{ $contact->type->color }}33; color:{{ $contact->type->color }}; border:1px solid {{ $contact->type->color }}55;">
                            {{ $contact->type->name }}
                        </span>
                        @endif
                        {{-- Listing type --}}
                        <span class="text-[10px] px-2 py-0.5 rounded-full font-bold flex-shrink-0"
                              style="{{ $match->listing_type === 'rental' ? 'background:rgba(168,85,247,0.15); color:#c084fc; border:1px solid rgba(168,85,247,0.30);' : 'background:rgba(0,180,216,0.15); color:#00b4d8; border:1px solid rgba(0,180,216,0.30);' }}">
                            {{ $match->listingTypeLabel() }}
                        </span>
                    </div>

                    {{-- Phone / email --}}
                    <div class="flex items-center gap-3 mb-3 flex-wrap">
                        @if($contact->phone)
                        <span class="text-xs" style="color:rgba(255,255,255,0.45);">{{ $contact->phone }}</span>
                        @endif
                        @if($contact->email)
                        <span class="text-xs" style="color:rgba(255,255,255,0.35);">{{ $contact->email }}</span>
                        @endif
                    </div>

                    {{-- Criteria chips --}}
                    <div class="flex items-center gap-1.5 flex-wrap">
                        @if($match->price_min || $match->price_max)
                        <span class="text-xs font-bold px-2.5 py-1 rounded-lg"
                              style="background:rgba(0,180,216,0.12); color:#38bdf8; border:1px solid rgba(0,180,216,0.25);">
                            {{ $match->priceRangeLabel() }}
                        </span>
                        @endif
                        @if($match->suburb)
                        <span class="text-xs font-medium px-2.5 py-1 rounded-lg"
                              style="background:rgba(255,255,255,0.06); color:rgba(255,255,255,0.6); border:1px solid rgba(255,255,255,0.12);">
                            {{ $match->suburb }}
                        </span>
                        @endif
                        @if($match->category)
                        <span class="text-xs font-medium px-2.5 py-1 rounded-lg"
                              style="background:rgba(255,255,255,0.06); color:rgba(255,255,255,0.55); border:1px solid rgba(255,255,255,0.10);">
                            {{ $match->category }}
                        </span>
                        @endif
                        @if($match->property_type)
                        <span class="text-xs font-medium px-2.5 py-1 rounded-lg"
                              style="background:rgba(255,255,255,0.06); color:rgba(255,255,255,0.55); border:1px solid rgba(255,255,255,0.10);">
                            {{ $match->property_type }}
                        </span>
                        @endif
                        @foreach([[$match->beds_min,'Beds'],[$match->baths_min,'Baths'],[$match->garages_min,'Gar']] as [$val,$lbl])
                        @if($val !== null)
                        <span class="text-xs font-medium px-2.5 py-1 rounded-lg"
                              style="background:rgba(255,255,255,0.06); color:rgba(255,255,255,0.55); border:1px solid rgba(255,255,255,0.10);">
                            {{ $val }}+ {{ $lbl }}
                        </span>
                        @endif
                        @endforeach
                        @if($match->floor_size_min || $match->floor_size_max)
                        <span class="text-xs font-medium px-2.5 py-1 rounded-lg"
                              style="background:rgba(255,255,255,0.06); color:rgba(255,255,255,0.55); border:1px solid rgba(255,255,255,0.10);">
                            {{ $match->floor_size_min ? number_format($match->floor_size_min) : '—' }}–{{ $match->floor_size_max ? number_format($match->floor_size_max) : '—' }} m²
                        </span>
                        @endif
                        @if(!$match->category && !$match->property_type && !$match->suburb && !$match->price_min && !$match->price_max && !$match->beds_min && !$match->baths_min)
                        <span class="text-xs italic" style="color:rgba(255,255,255,0.35);">Any property</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Right: stats + actions --}}
            <div class="flex flex-col items-end gap-3 flex-shrink-0">
                {{-- Stats row --}}
                <div class="flex items-center gap-4">
                    <div class="text-right">
                        <div class="text-2xl font-extrabold leading-tight" style="color:{{ $properties->count() > 0 ? '#00b4d8' : 'rgba(255,255,255,0.3)' }};">
                            {{ $properties->count() }}
                        </div>
                        <div class="text-[10px] font-semibold uppercase tracking-wider" style="color:rgba(255,255,255,0.35);">
                            {{ Str::plural('match', $properties->count()) }}
                        </div>
                    </div>
                    @if($totalViews > 0)
                    <div style="width:1px; height:32px; background:rgba(255,255,255,0.12);"></div>
                    <div class="text-right">
                        <div class="text-2xl font-extrabold leading-tight" style="color:#a78bfa;">{{ $totalViews }}</div>
                        <div class="text-[10px] font-semibold uppercase tracking-wider" style="color:rgba(255,255,255,0.35);">
                            client {{ Str::plural('view', $totalViews) }}
                        </div>
                    </div>
                    @endif
                    @if($hiddenCount > 0)
                    <div style="width:1px; height:32px; background:rgba(255,255,255,0.12);"></div>
                    <div class="text-right">
                        <div class="text-2xl font-extrabold leading-tight" style="color:rgba(255,255,255,0.3);">{{ $hiddenCount }}</div>
                        <div class="text-[10px] font-semibold uppercase tracking-wider" style="color:rgba(255,255,255,0.25);">hidden</div>
                    </div>
                    @endif
                </div>

                {{-- Action buttons --}}
                <div class="flex items-center gap-2">
                    @if($waPhone)
                    <button type="button" @click="showWaModal = true"
                            class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-semibold"
                            style="background:rgba(37,211,102,0.12); color:#4ade80; border:1px solid rgba(37,211,102,0.30);"
                            onmouseover="this.style.background='rgba(37,211,102,0.22)'" onmouseout="this.style.background='rgba(37,211,102,0.12)'">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
                            <path d="M12 0C5.373 0 0 5.373 0 12c0 2.117.554 4.103 1.523 5.824L0 24l6.335-1.509A11.945 11.945 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.854 0-3.6-.483-5.12-1.33l-.368-.214-3.76.896.952-3.656-.238-.384A10.01 10.01 0 0 1 2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/>
                        </svg>
                        WhatsApp
                    </button>
                    @endif
                    <a href="{{ $match->sharedUrl() }}" target="_blank"
                       class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-semibold no-underline"
                       style="background:rgba(255,255,255,0.08); color:rgba(255,255,255,0.65); border:1px solid rgba(255,255,255,0.15);"
                       onmouseover="this.style.background='rgba(255,255,255,0.15)'" onmouseout="this.style.background='rgba(255,255,255,0.08)'">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.641 0-8.58-3.007-9.964-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                        Client Page
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- Property list --}}
    @if($properties->isEmpty())
    <div class="rounded-2xl py-16 text-center" style="background:var(--surface); border:1px solid var(--border);">
        <div class="w-14 h-14 rounded-2xl mx-auto mb-4 flex items-center justify-center" style="background:rgba(0,180,216,0.06); border:1px solid rgba(0,180,216,0.12);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-7 h-7 opacity-30" style="color:#00b4d8;"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 15.803a7.5 7.5 0 0 0 10.607 0Z" /></svg>
        </div>
        <p class="text-base font-bold" style="color:var(--text-muted);">No active properties match these criteria.</p>
        <p class="text-sm mt-1.5 max-w-xs mx-auto" style="color:var(--text-muted); opacity:.65;">Try broadening the price range, suburb, or room requirements.</p>
        <a href="{{ route('corex.contacts.show', $contact) }}?tab=matches"
           class="inline-flex items-center gap-1.5 mt-6 px-4 py-2 rounded-lg text-sm font-semibold no-underline"
           style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);">
            ← Back to Core Matches
        </a>
    </div>
    @else
    <div class="space-y-2">
        @foreach($properties as $property)
        @php
            $isHidden = $match->isPropertyHidden($property->id);
            $views = $match->propertyViewCount($property->id);
            $thumb = $property->gallery_images_json[0]
                ?? $property->dawn_images_json[0]
                ?? $property->noon_images_json[0]
                ?? $property->dusk_images_json[0]
                ?? null;
            $statusColors = ['active'=>'#22c55e','draft'=>'#94a3b8','sold'=>'#3b82f6','withdrawn'=>'#f59e0b'];
            $sc = $statusColors[$property->status] ?? '#94a3b8';
        @endphp

        <div class="rounded-2xl overflow-hidden flex items-stretch"
             style="background:var(--surface); border:1px solid var(--border); {{ $isHidden ? 'opacity:.55;' : '' }}">

            {{-- Thumbnail --}}
            <div class="relative flex-shrink-0 overflow-hidden" style="width:140px; min-height:100px; background:var(--surface-2);">
                @if($thumb)
                <img src="{{ $thumb }}" alt="{{ $property->title }}" class="absolute inset-0 w-full h-full object-cover">
                @else
                <div class="absolute inset-0 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-8 h-8 opacity-15" style="color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z" /></svg>
                </div>
                @endif
                @if($isHidden)
                <div class="absolute inset-0 flex items-center justify-center" style="background:rgba(0,0,0,0.50);">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-white opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
                </div>
                @endif
            </div>

            {{-- Main content --}}
            <div class="flex-1 min-w-0 px-5 py-4 flex flex-col gap-2 justify-between">

                {{-- Top: status badges + title --}}
                <div>
                    <div class="flex items-center gap-2 flex-wrap mb-1.5">
                        <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full text-white flex-shrink-0"
                              style="background:{{ $sc }};">{{ ucfirst($property->status) }}</span>
                        @if($isHidden)
                        <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full flex-shrink-0"
                              style="background:rgba(239,68,68,0.10); color:#ef4444; border:1px solid rgba(239,68,68,0.22);">Hidden from client</span>
                        @endif
                    </div>
                    <div class="text-sm font-bold leading-snug mb-1" style="color:var(--text-primary);">
                        {{ $property->title ?: 'Untitled Property' }}
                    </div>
                    <div class="flex items-center gap-3 text-xs flex-wrap" style="color:var(--text-muted);">
                        <span class="font-bold text-sm" style="color:#00b4d8;">{{ $property->formattedPrice() }}</span>
                        @if($property->suburb)
                        <span>{{ $property->suburb }}</span>
                        @endif
                        @foreach([[$property->beds,'Beds'],[$property->baths,'Baths'],[$property->garages,'Gar']] as [$v,$l])
                        @if($v)
                        <span>{{ $v }} {{ $l }}</span>
                        @endif
                        @endforeach
                        @if($property->size_m2)
                        <span>{{ number_format($property->size_m2) }} m²</span>
                        @endif
                    </div>
                    @if($property->agent)
                    <div class="text-[10px] mt-1" style="color:var(--text-muted);">Agent: {{ $property->agent->name }}</div>
                    @endif
                </div>

                {{-- Bottom: client view counter --}}
                <div class="flex items-center gap-2 pt-2" style="border-top:1px solid var(--border);">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"
                         style="color:{{ $views > 0 ? '#a78bfa' : 'var(--text-muted)' }};"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.641 0-8.58-3.007-9.964-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                    <span class="text-xs" style="color:var(--text-muted);">
                        @if($views > 0)
                            Viewed by client
                            <strong style="color:#a78bfa;">{{ $views }} {{ $views === 1 ? 'time' : 'times' }}</strong>
                        @else
                            Not yet viewed by client
                        @endif
                    </span>
                </div>
            </div>

            {{-- Action buttons --}}
            <div class="flex flex-col gap-2 justify-center px-4 py-4 flex-shrink-0" style="border-left:1px solid var(--border);">
                <a href="{{ route('corex.properties.show', $property) }}"
                   class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold no-underline"
                   style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);"
                   onmouseover="this.style.borderColor='#00b4d8'; this.style.color='#00b4d8'" onmouseout="this.style.borderColor='var(--border)'; this.style.color='var(--text-secondary)'">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                    View Property
                </a>

                <form method="POST" action="{{ route('corex.contacts.matches.toggleHide', [$contact, $match, $property]) }}">
                    @csrf
                    <button type="submit"
                            class="w-full inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold"
                            style="{{ $isHidden ? 'background:rgba(34,197,94,0.08); color:#16a34a; border:1px solid rgba(34,197,94,0.22);' : 'background:rgba(239,68,68,0.06); color:#ef4444; border:1px solid rgba(239,68,68,0.18);' }}"
                            onmouseover="this.style.opacity='.7'" onmouseout="this.style.opacity='1'">
                        @if($isHidden)
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.641 0-8.58-3.007-9.964-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                        Unhide
                        @else
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
                        Hide
                        @endif
                    </button>
                </form>
            </div>
        </div>
        @endforeach
    </div>

    @endif

    {{-- WhatsApp Modal --}}
    <div x-show="showWaModal" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="background:rgba(0,0,0,0.60);"
         @keydown.escape.window="showWaModal = false">
        <div class="w-full max-w-lg rounded-2xl shadow-2xl overflow-hidden"
             style="background:var(--surface); border:1px solid var(--border);"
             @click.stop>

            {{-- Modal header --}}
            <div class="flex items-center justify-between px-6 py-4" style="border-bottom:1px solid var(--border);">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0"
                         style="background:rgba(37,211,102,0.12); border:1px solid rgba(37,211,102,0.22);">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4.5 h-4.5" viewBox="0 0 24 24" fill="#25d366" style="width:18px;height:18px;">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
                            <path d="M12 0C5.373 0 0 5.373 0 12c0 2.117.554 4.103 1.523 5.824L0 24l6.335-1.509A11.945 11.945 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.854 0-3.6-.483-5.12-1.33l-.368-.214-3.76.896.952-3.656-.238-.384A10.01 10.01 0 0 1 2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/>
                        </svg>
                    </div>
                    <div>
                        <div class="text-sm font-bold" style="color:var(--text-primary);">Send via WhatsApp</div>
                        <div class="text-xs" style="color:var(--text-muted);">{{ $contact->full_name }}
                            @if($contact->phone) · {{ $contact->phone }}@endif
                        </div>
                    </div>
                </div>
                <button type="button" @click="showWaModal = false"
                        class="w-8 h-8 flex items-center justify-center rounded-lg text-sm font-bold"
                        style="color:var(--text-muted); background:var(--surface-2); border:1px solid var(--border);"
                        onmouseover="this.style.color='var(--text-primary)'" onmouseout="this.style.color='var(--text-muted)'">✕</button>
            </div>

            {{-- Message editor --}}
            <div class="px-6 py-5 space-y-3">
                <label class="block text-xs font-semibold" style="color:var(--text-muted);">Edit message before sending</label>
                <textarea x-model="waMessage"
                          rows="10"
                          class="w-full rounded-xl px-4 py-3 text-sm"
                          style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); resize:vertical; line-height:1.65; font-family:inherit;"></textarea>
                <p class="text-[10px]" style="color:var(--text-muted);">The client's personalised link is already included in the message.</p>
            </div>

            {{-- Footer --}}
            <div class="px-6 pb-5 flex items-center justify-end gap-3">
                <button type="button" @click="showWaModal = false"
                        class="px-4 py-2 rounded-xl text-sm font-semibold"
                        style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);">
                    Cancel
                </button>
                <button type="button" @click="sendWhatsApp()"
                        class="inline-flex items-center gap-2 px-5 py-2 rounded-xl text-sm font-semibold"
                        style="background:#25d366; color:#fff;">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
                        <path d="M12 0C5.373 0 0 5.373 0 12c0 2.117.554 4.103 1.523 5.824L0 24l6.335-1.509A11.945 11.945 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.854 0-3.6-.483-5.12-1.33l-.368-.214-3.76.896.952-3.656-.238-.384A10.01 10.01 0 0 1 2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/>
                    </svg>
                    Open in WhatsApp
                </button>
            </div>
        </div>
    </div>

</div>
@endsection
