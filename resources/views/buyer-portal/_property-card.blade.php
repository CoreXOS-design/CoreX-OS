{{--
    Buyer Portal — property match card (AT-204)
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

    // Honest chip colour by quality — never a bare "100%".
    $chipColor = $score >= 90 ? 'var(--ds-green)' : ($score >= 80 ? 'var(--brand-icon)' : 'var(--ds-amber)');
    $facts = collect([
        [$prop->beds, 'bed'], [$prop->baths, 'bath'], [$prop->garages, 'garage'],
    ])->filter(fn ($f) => !empty($f[0]));
@endphp

<article class="surface-card" style="overflow:hidden;">
    {{-- Photo + match chip --}}
    <div style="position:relative; background:var(--surface-2); aspect-ratio:16/10;">
        @if($thumb)
            <img src="{{ $thumb }}" alt="{{ $prop->title ?? 'Property' }}" loading="lazy"
                 style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover;">
        @else
            <div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:var(--text-muted); opacity:.4;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" style="width:44px;height:44px;"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z"/></svg>
            </div>
        @endif
        {{-- Honest match chip: plain-English quality word + the % it's based on --}}
        <div style="position:absolute; top:.625rem; left:.625rem; display:inline-flex; align-items:center; gap:.375rem; background:rgba(17,24,39,.82); backdrop-filter:blur(4px); color:#fff; border-radius:9999px; padding:.25rem .6rem;">
            <span style="width:7px; height:7px; border-radius:9999px; background:{{ $chipColor }};"></span>
            <span style="font-size:.75rem; font-weight:700;">{{ $scoreWord($score) }}</span>
            <span style="font-size:.6875rem; font-weight:600; color:rgba(255,255,255,.7);">{{ $score }}%</span>
        </div>
    </div>

    {{-- Body --}}
    <div style="padding:.875rem 1rem 1rem;">
        <div style="font-size:1.125rem; font-weight:800; color:var(--brand-default); line-height:1.1;">{{ $prop->formattedPrice() }}</div>
        <div style="font-size:.9375rem; font-weight:600; color:var(--text-primary); margin-top:.2rem; line-height:1.3;">{{ $prop->title ?: 'Property listing' }}</div>
        @if($prop->suburb)
        <div style="display:flex; align-items:center; gap:.3rem; font-size:.8125rem; color:var(--text-muted); margin-top:.3rem;">
            <svg xmlns="http://www.w3.org/2000/svg" style="width:13px;height:13px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
            {{ $prop->suburb }}{{ $prop->city ? ', ' . $prop->city : '' }}
        </div>
        @endif

        {{-- Key facts --}}
        @if($facts->isNotEmpty())
        <div style="display:flex; align-items:center; gap:1rem; margin-top:.625rem;">
            @foreach($facts as [$v, $l])
            <div style="display:flex; align-items:baseline; gap:.25rem;">
                <span style="font-size:.9375rem; font-weight:700; color:var(--text-primary);">{{ $v }}</span>
                <span style="font-size:.75rem; color:var(--text-muted);">{{ $v == 1 ? $l : \Illuminate\Support\Str::plural($l) }}</span>
            </div>
            @endforeach
        </div>
        @endif

        {{-- Honesty line: what this % is based on --}}
        @if($basisText)
        <div style="font-size:.6875rem; color:var(--text-muted); margin-top:.625rem;">Matched on {{ $basisText }}</div>
        @endif

        {{-- Actions OR actioned state (buyer-loop heartbeat, preserved exactly) --}}
        @if($resp === 'interested')
            <div style="margin-top:.875rem; display:flex; align-items:center; gap:.4rem; background:color-mix(in srgb, var(--ds-green) 10%, #fff); border:1px solid color-mix(in srgb, var(--ds-green) 28%, transparent); color:var(--ds-green); border-radius:9px; padding:.6rem .75rem; font-size:.8125rem; font-weight:600;">
                ✓ You're interested — your agent has been notified
            </div>
        @elseif($resp === 'viewing_requested')
            <div style="margin-top:.875rem; display:flex; align-items:center; gap:.4rem; background:color-mix(in srgb, var(--brand-icon) 12%, #fff); border:1px solid color-mix(in srgb, var(--brand-icon) 30%, transparent); color:var(--brand-default); border-radius:9px; padding:.6rem .75rem; font-size:.8125rem; font-weight:600;">
                ✓ Viewing requested — your agent will be in touch to arrange it
            </div>
        @elseif($resp === 'not_interested')
            <div style="margin-top:.875rem; display:flex; align-items:center; gap:.4rem; background:var(--surface-2); border:1px solid var(--border); color:var(--text-muted); border-radius:9px; padding:.6rem .75rem; font-size:.8125rem; font-weight:600;">
                You passed on this one — we'll keep it out of your feed
            </div>
        @else
            <div style="margin-top:.875rem; display:grid; grid-template-columns:1fr 1fr; gap:.5rem;">
                <form method="POST" action="{{ route('buyer-portal.respond', $token) }}">
                    @csrf
                    <input type="hidden" name="property_id" value="{{ $prop->id }}">
                    <input type="hidden" name="response" value="interested">
                    <button type="submit" style="width:100%; min-height:44px; border:0; border-radius:9px; font-size:.8125rem; font-weight:700; color:#fff; background:var(--ds-green); cursor:pointer;">👍 Interested</button>
                </form>
                <form method="POST" action="{{ route('buyer-portal.respond', $token) }}">
                    @csrf
                    <input type="hidden" name="property_id" value="{{ $prop->id }}">
                    <input type="hidden" name="response" value="viewing_requested">
                    <button type="submit" style="width:100%; min-height:44px; border:0; border-radius:9px; font-size:.8125rem; font-weight:700; color:#fff; background:var(--brand-button); cursor:pointer;">📅 Request viewing</button>
                </form>
                <form method="POST" action="{{ route('buyer-portal.respond', $token) }}" style="grid-column:1 / -1;">
                    @csrf
                    <input type="hidden" name="property_id" value="{{ $prop->id }}">
                    <input type="hidden" name="response" value="not_interested">
                    <button type="submit" style="width:100%; min-height:40px; border:1px solid var(--border); border-radius:9px; font-size:.8125rem; font-weight:600; color:var(--text-muted); background:var(--surface); cursor:pointer;">Not for me</button>
                </form>
            </div>
        @endif
    </div>
</article>
