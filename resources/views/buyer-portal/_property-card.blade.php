{{--
    Buyer Portal — property match card (AT-204, Core Match client-page style)
    Vars: $prop (Property), $match (property_buyer_matches row: ->score, ->tier),
          $resp (string|null response), $token, $basisText (string), $scoreWord (closure)
--}}
@php
    $score = (int) ($match->score ?? 0);
    $thumb = $prop->thumbFor(
        ($prop->gallery_images_json[0] ?? null)
        ?? ($prop->dawn_images_json[0] ?? null)
        ?? ($prop->noon_images_json[0] ?? null)
        ?? ($prop->dusk_images_json[0] ?? null)
        ?? ($prop->images_json[0] ?? null)
    );
    $thumb = \App\Models\Property::publicImageUrl($thumb);

    $facts = collect([
        [$prop->beds, 'bed'], [$prop->baths, 'bath'], [$prop->garages, 'garage'],
    ])->filter(fn ($f) => !empty($f[0]));

    // Public preview with the listing agent hidden (agent=none) — the buyer keeps
    // their own agent as point of contact.
    $previewUrl = route('corex.properties.preview', [$prop, \Illuminate\Support\Str::slug($prop->title ?? 'property')]) . '?agent=none';
@endphp

<article class="surface-card overflow-hidden">
    {{-- Clickable listing area (agent hidden on the preview) --}}
    <a href="{{ $previewUrl }}" target="_blank" rel="noopener" class="block group" style="color: inherit;">
        <div class="flex flex-col sm:flex-row">
            {{-- Image + honest match chip --}}
            <div class="relative flex-shrink-0 overflow-hidden sm:w-[180px] sm:min-h-[132px]" style="background: var(--surface-2); aspect-ratio: 16/10;">
                @if($thumb)
                    <img src="{{ $thumb }}" alt="{{ $prop->title ?? 'Property' }}" loading="lazy"
                         class="absolute inset-0 w-full h-full object-cover transition-transform duration-300 group-hover:scale-105">
                @else
                    <div class="absolute inset-0 flex items-center justify-center" style="color: var(--text-muted); opacity: 0.4;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-9 h-9"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z"/></svg>
                    </div>
                @endif
                <div class="absolute top-2 left-2 ds-badge" style="background: rgba(17,24,39,0.82); color: #fff; border-color: rgba(255,255,255,0.18); backdrop-filter: blur(6px);">
                    {{ $scoreWord($score) }} · {{ $score }}%
                </div>
            </div>

            {{-- Content --}}
            <div class="flex-1 min-w-0 p-3.5">
                <div class="flex items-start justify-between gap-3">
                    <div class="text-lg font-extrabold leading-tight" style="color: var(--brand-default);">{{ $prop->formattedPrice() }}</div>
                    <span class="inline-flex items-center gap-1 text-xs font-semibold flex-shrink-0 mt-0.5" style="color: var(--brand-icon);">
                        View
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                    </span>
                </div>
                <div class="text-sm font-medium leading-snug mt-0.5" style="color: var(--text-primary);">{{ $prop->title ?: 'Property listing' }}</div>
                @if($prop->suburb)
                <div class="flex items-center gap-1 text-xs mt-1" style="color: var(--text-muted);">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                    {{ $prop->suburb }}{{ $prop->city ? ', ' . $prop->city : '' }}
                </div>
                @endif

                @if($facts->isNotEmpty())
                <div class="flex items-center gap-3.5 text-xs mt-2" style="color: var(--text-secondary);">
                    @foreach($facts as [$v, $l])
                    <div class="flex items-baseline gap-1">
                        <span class="text-sm font-semibold" style="color: var(--text-primary);">{{ $v }}</span>
                        <span class="text-[0.6875rem]" style="color: var(--text-muted);">{{ $v == 1 ? $l : \Illuminate\Support\Str::plural($l) }}</span>
                    </div>
                    @endforeach
                </div>
                @endif

                @if($basisText)
                <div class="text-[0.6875rem] mt-2" style="color: var(--text-muted);">Matched on {{ $basisText }}</div>
                @endif
            </div>
        </div>
    </a>

    {{-- Action row (buyer-loop heartbeat, preserved exactly) — outside the link --}}
    <div class="px-3.5 py-2.5" style="border-top: 1px solid var(--border); background: var(--surface-2);">
        @if($resp === 'interested')
            <div class="flex items-center gap-2 text-sm font-semibold" style="color: var(--brand-default);">
                You're interested — your agent has been notified
            </div>
        @elseif($resp === 'viewing_requested')
            <div class="flex items-center gap-2 text-sm font-semibold" style="color: var(--brand-default);">
                Viewing requested — your agent will be in touch to arrange it
            </div>
        @elseif($resp === 'not_interested')
            <div class="flex items-center gap-2 text-sm font-medium" style="color: var(--text-muted);">
                You passed on this one — we'll keep it out of your feed
            </div>
        @else
            <div class="grid grid-cols-2 gap-2">
                <form method="POST" action="{{ route('buyer-portal.respond', $token) }}">
                    @csrf
                    <input type="hidden" name="property_id" value="{{ $prop->id }}">
                    <input type="hidden" name="response" value="interested">
                    <button type="submit" class="btn-primary w-full" style="min-height:42px;">Interested</button>
                </form>
                <form method="POST" action="{{ route('buyer-portal.respond', $token) }}">
                    @csrf
                    <input type="hidden" name="property_id" value="{{ $prop->id }}">
                    <input type="hidden" name="response" value="viewing_requested">
                    <button type="submit" class="btn-outline w-full" style="min-height:42px; border-color: color-mix(in srgb, var(--brand-button) 45%, transparent); color: var(--brand-button);">Request a viewing</button>
                </form>
                <form method="POST" action="{{ route('buyer-portal.respond', $token) }}" class="col-span-2">
                    @csrf
                    <input type="hidden" name="property_id" value="{{ $prop->id }}">
                    <input type="hidden" name="response" value="not_interested">
                    <button type="submit" class="btn-outline w-full" style="min-height:38px;">Not for me</button>
                </form>
            </div>
        @endif
    </div>
</article>
