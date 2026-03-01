{{--
    Shared ad template partial.
    Variables expected from parent (ad.blade.php):
      $tpl        — 'power' | 'luxe' | 'split'
      $img1-3     — image URLs (nullable)
      $price, $title, $suburb, $type
      $beds, $baths, $garages, $size
      $initial, $agentName, $agentEmail, $agentDesig
      $baseFontPx — set (integer) for thumbnail renders; null for generator (em units scale via CSS font-size on parent)
--}}
@php
    // For thumbnails the parent sets an explicit font-size; generator inherits from #ad-canvas via CSS
    $fs = $baseFontPx ? "font-size:{$baseFontPx}px;" : '';
@endphp

{{-- ════════════════════════════════════════════════════════════════
     TEMPLATE 1 — POWER
     Layout: 3-photo collage (flexbox row) / white price strip / dark info bar
     All dimensions in em so they scale with the parent's font-size
════════════════════════════════════════════════════════════════ --}}
@if($tpl === 'power')
<div style="position:absolute;inset:0;display:flex;flex-direction:column;background:#071325;{{ $fs }}">

    {{-- Images section — fills all remaining height --}}
    <div style="flex:1;min-height:0;position:relative;display:flex;overflow:hidden;">

        {{-- HF Logo top-left --}}
        <img src="/images/logo.png"
             style="position:absolute;top:0.9em;left:0.9em;z-index:10;height:2.8em;filter:drop-shadow(0 2px 10px rgba(0,0,0,0.9));"
             crossorigin="anonymous" alt="">

        {{-- Left: main image (60% width) --}}
        <div style="flex:1.55;overflow:hidden;position:relative;">
            @if($img1)
                <img src="{{ $img1 }}" class="ad-img-fit" crossorigin="anonymous" alt="">
            @else
                <div class="ad-placeholder"></div>
            @endif
        </div>

        {{-- Right col: 2 stacked images --}}
        <div style="flex:1;display:flex;flex-direction:column;overflow:hidden;gap:2px;margin-left:2px;">
            <div style="flex:1;overflow:hidden;">
                @if($img2)
                    <img src="{{ $img2 }}" class="ad-img-fit" crossorigin="anonymous" alt="">
                @else
                    <div style="width:100%;height:100%;background:linear-gradient(135deg,#0d3259,#143d6e);"></div>
                @endif
            </div>
            <div style="flex:1;overflow:hidden;">
                @if($img3)
                    <img src="{{ $img3 }}" class="ad-img-fit" crossorigin="anonymous" alt="">
                @else
                    <div style="width:100%;height:100%;background:linear-gradient(135deg,#071e35,#0b2a4a);"></div>
                @endif
            </div>
        </div>

        {{-- Subtle vignette at bottom of images --}}
        <div style="position:absolute;bottom:0;left:0;right:0;height:40%;background:linear-gradient(to bottom,transparent,rgba(7,19,37,0.45));pointer-events:none;"></div>
    </div>

    {{-- White price strip --}}
    <div style="flex-shrink:0;background:#ffffff;padding:0.45em 1.4em;display:flex;align-items:center;justify-content:space-between;border-top:3px solid #e63946;">
        <span style="font-size:3.15em;font-weight:900;color:#e63946;line-height:1;letter-spacing:-0.025em;">{{ $price }}</span>
        <span style="font-size:0.62em;font-weight:700;color:#0b2a4a;letter-spacing:0.1em;text-transform:uppercase;opacity:0.5;">HOME FINDERS COASTAL</span>
    </div>

    {{-- Dark info bar --}}
    <div style="flex-shrink:0;background:#07111e;padding:0.6em 1.4em 0.75em;display:flex;flex-direction:column;gap:0.4em;">

        {{-- Title --}}
        <div style="font-size:0.82em;font-weight:700;color:#ffffff;text-transform:uppercase;letter-spacing:0.04em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
            {{ $title }}
        </div>

        {{-- Features row --}}
        <div style="display:flex;align-items:center;gap:0.5em;font-size:0.7em;font-weight:600;color:rgba(255,255,255,0.65);letter-spacing:0.05em;text-transform:uppercase;">
            <span>{{ $beds }} BED</span>
            <span style="color:rgba(255,255,255,0.2);">•</span>
            <span>{{ $baths }} BATH</span>
            @if($garages)
            <span style="color:rgba(255,255,255,0.2);">•</span>
            <span>{{ $garages }} GAR</span>
            @endif
            @if($size)
            <span style="color:rgba(255,255,255,0.2);">•</span>
            <span>{{ $size }}</span>
            @endif
        </div>

        {{-- Agent row --}}
        <div style="display:flex;align-items:center;gap:0.65em;">
            <div style="width:2.3em;height:2.3em;border-radius:50%;background:linear-gradient(135deg,#00b4d8,#007fa8);display:flex;align-items:center;justify-content:center;font-size:0.95em;font-weight:800;color:#fff;flex-shrink:0;border:2px solid rgba(255,255,255,0.18);">
                {{ $initial }}
            </div>
            <div style="min-width:0;flex:1;">
                <div style="font-size:0.78em;font-weight:800;color:#ffffff;letter-spacing:0.06em;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $agentName }}</div>
                <div style="font-size:0.62em;color:rgba(255,255,255,0.5);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $agentEmail }}</div>
            </div>
            <div style="font-size:0.52em;font-weight:700;color:rgba(255,255,255,0.18);letter-spacing:0.1em;text-transform:uppercase;flex-shrink:0;">hfcoastal.co.za</div>
        </div>
    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     TEMPLATE 2 — LUXE
     Layout: full-bleed hero image + cinematic gradient overlay + bottom content
     Content floats on top of the image via absolute positioning
════════════════════════════════════════════════════════════════ --}}
@if($tpl === 'luxe')
<div style="position:absolute;inset:0;background:#071325;overflow:hidden;{{ $fs }}">

    {{-- Full-bleed background image --}}
    @if($img1)
        <img src="{{ $img1 }}" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block;" crossorigin="anonymous" alt="">
    @else
        <div style="position:absolute;inset:0;background:linear-gradient(135deg,#0b2a4a,#143d6e);"></div>
    @endif

    {{-- Gradient overlays for depth --}}
    {{-- Top-left: logo protection --}}
    <div style="position:absolute;inset:0;background:linear-gradient(155deg,rgba(7,19,37,0.82) 0%,rgba(7,19,37,0) 42%);pointer-events:none;"></div>
    {{-- Bottom: content area --}}
    <div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(7,19,37,0.98) 0%,rgba(7,19,37,0.88) 28%,rgba(7,19,37,0.3) 52%,rgba(7,19,37,0) 70%);pointer-events:none;"></div>

    {{-- Logo top-left --}}
    <img src="/images/logo.png"
         style="position:absolute;top:1em;left:1em;height:2.6em;filter:drop-shadow(0 2px 10px rgba(0,0,0,0.7));z-index:10;"
         crossorigin="anonymous" alt="">

    {{-- Property type badge top-right --}}
    <div style="position:absolute;top:1.1em;right:1.1em;background:rgba(0,180,216,0.92);color:#fff;font-size:0.62em;font-weight:800;padding:0.5em 1.1em;border-radius:2em;letter-spacing:0.1em;text-transform:uppercase;z-index:10;backdrop-filter:blur(4px);">
        {{ $type }}
    </div>

    {{-- Thumbnail strip (img2 + img3) mid-right --}}
    @if($img2 || $img3)
    <div style="position:absolute;bottom:36%;right:1.2em;display:flex;flex-direction:column;gap:0.4em;z-index:10;">
        @if($img2)
        <div style="width:5.5em;height:3.5em;border-radius:0.4em;overflow:hidden;border:1.5px solid rgba(255,255,255,0.25);box-shadow:0 4px 12px rgba(0,0,0,0.5);">
            <img src="{{ $img2 }}" class="ad-img-fit" crossorigin="anonymous" alt="">
        </div>
        @endif
        @if($img3)
        <div style="width:5.5em;height:3.5em;border-radius:0.4em;overflow:hidden;border:1.5px solid rgba(255,255,255,0.25);box-shadow:0 4px 12px rgba(0,0,0,0.5);">
            <img src="{{ $img3 }}" class="ad-img-fit" crossorigin="anonymous" alt="">
        </div>
        @endif
    </div>
    @endif

    {{-- Bottom content --}}
    <div style="position:absolute;bottom:0;left:0;right:0;padding:0 1.5em 1.2em;z-index:10;">

        {{-- Cyan accent line --}}
        <div style="width:2.8em;height:0.22em;background:#00b4d8;border-radius:1em;margin-bottom:0.65em;"></div>

        {{-- Price --}}
        <div style="font-size:3.6em;font-weight:900;color:#ffffff;line-height:1;letter-spacing:-0.025em;text-shadow:0 3px 16px rgba(0,0,0,0.4);">{{ $price }}</div>

        {{-- Title --}}
        <div style="font-size:0.88em;font-weight:700;color:rgba(255,255,255,0.88);margin-top:0.4em;text-transform:uppercase;letter-spacing:0.04em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
            {{ $title }}
        </div>

        {{-- Suburb --}}
        <div style="font-size:0.65em;font-weight:500;color:rgba(255,255,255,0.45);margin-top:0.15em;letter-spacing:0.06em;text-transform:uppercase;">{{ $suburb }}</div>

        {{-- Divider --}}
        <div style="width:100%;height:1px;background:rgba(255,255,255,0.12);margin:0.75em 0;"></div>

        {{-- Features + Agent --}}
        <div style="display:flex;align-items:center;justify-content:space-between;gap:1em;">

            {{-- Features --}}
            <div style="display:flex;align-items:center;gap:0.55em;font-size:0.68em;font-weight:600;color:rgba(255,255,255,0.6);letter-spacing:0.05em;text-transform:uppercase;">
                <span>{{ $beds }} BED</span>
                <span style="opacity:0.3;">|</span>
                <span>{{ $baths }} BATH</span>
                @if($garages)<span style="opacity:0.3;">|</span><span>{{ $garages }} GAR</span>@endif
                @if($size)<span style="opacity:0.3;">|</span><span>{{ $size }}</span>@endif
            </div>

            {{-- Agent --}}
            <div style="display:flex;align-items:center;gap:0.6em;flex-shrink:0;">
                <div style="text-align:right;">
                    <div style="font-size:0.78em;font-weight:800;color:#fff;text-transform:uppercase;letter-spacing:0.06em;">{{ $agentName }}</div>
                    <div style="font-size:0.6em;color:rgba(255,255,255,0.45);">{{ $agentEmail }}</div>
                </div>
                <div style="width:2.4em;height:2.4em;border-radius:50%;background:linear-gradient(135deg,#00b4d8,#007fa8);display:flex;align-items:center;justify-content:center;font-weight:900;color:#fff;font-size:0.95em;border:2px solid rgba(255,255,255,0.3);flex-shrink:0;">
                    {{ $initial }}
                </div>
            </div>
        </div>

        <div style="margin-top:0.6em;font-size:0.5em;font-weight:700;color:rgba(255,255,255,0.18);letter-spacing:0.12em;text-transform:uppercase;">HFCOASTAL.CO.ZA</div>
    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     TEMPLATE 3 — SPLIT
     Layout: left dark info panel (38%) | right images (62%)
     Left: logo, accent, price in brand cyan, title, features grid, agent
     Right: 1 tall image top + 2 side-by-side images bottom
════════════════════════════════════════════════════════════════ --}}
@if($tpl === 'split')
<div style="position:absolute;inset:0;display:flex;background:#071325;{{ $fs }}">

    {{-- ── Left panel ── --}}
    <div style="width:38%;flex-shrink:0;background:#07101a;display:flex;flex-direction:column;padding:1.15em 1.35em;position:relative;overflow:hidden;">

        {{-- Decorative radial glow bottom-left --}}
        <div style="position:absolute;bottom:-2em;left:-2em;width:14em;height:14em;background:radial-gradient(circle,rgba(0,180,216,0.1) 0%,transparent 70%);pointer-events:none;"></div>
        {{-- Top right decorative corner dot --}}
        <div style="position:absolute;top:0;right:0;width:0.25em;height:100%;background:linear-gradient(to bottom,#00b4d8,rgba(0,180,216,0));opacity:0.4;"></div>

        {{-- Logo --}}
        <img src="/images/logo.png" style="height:2.4em;margin-bottom:auto;object-fit:contain;object-position:left center;filter:brightness(1.05);position:relative;z-index:1;" crossorigin="anonymous" alt="">

        {{-- Center content --}}
        <div style="flex:1;display:flex;flex-direction:column;justify-content:center;position:relative;z-index:1;padding:0.8em 0;">

            {{-- Cyan accent line --}}
            <div style="width:2.2em;height:0.2em;background:#00b4d8;border-radius:1em;margin-bottom:0.7em;"></div>

            {{-- Price (cyan, large) --}}
            <div style="font-size:2.45em;font-weight:900;color:#00b4d8;line-height:1;letter-spacing:-0.025em;">{{ $price }}</div>

            {{-- Title --}}
            <div style="font-size:0.78em;font-weight:700;color:#ffffff;margin-top:0.45em;text-transform:uppercase;letter-spacing:0.04em;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">{{ $title }}</div>

            {{-- Suburb --}}
            <div style="font-size:0.62em;font-weight:500;color:rgba(255,255,255,0.38);margin-top:0.2em;text-transform:uppercase;letter-spacing:0.07em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $suburb }}</div>

            {{-- Hairline --}}
            <div style="width:100%;height:1px;background:rgba(255,255,255,0.08);margin:0.75em 0;"></div>

            {{-- Features grid --}}
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.4em 0.3em;">
                <div style="font-size:0.65em;font-weight:600;color:rgba(255,255,255,0.65);">
                    <span style="font-size:1.1em;font-weight:800;color:#00b4d8;">{{ $beds }}</span> BEDRM{{ $beds != 1 ? 'S' : '' }}
                </div>
                <div style="font-size:0.65em;font-weight:600;color:rgba(255,255,255,0.65);">
                    <span style="font-size:1.1em;font-weight:800;color:#00b4d8;">{{ $baths }}</span> BATHRM{{ $baths != 1 ? 'S' : '' }}
                </div>
                @if($garages)
                <div style="font-size:0.65em;font-weight:600;color:rgba(255,255,255,0.65);">
                    <span style="font-size:1.1em;font-weight:800;color:#00b4d8;">{{ $garages }}</span> GARAGE{{ $garages != 1 ? 'S' : '' }}
                </div>
                @endif
                @if($size)
                <div style="font-size:0.65em;font-weight:600;color:rgba(255,255,255,0.65);">
                    <span style="font-size:1.1em;font-weight:800;color:#00b4d8;">{{ $size }}</span>
                </div>
                @endif
            </div>
        </div>

        {{-- Agent footer --}}
        <div style="position:relative;z-index:1;">
            <div style="width:100%;height:1px;background:rgba(255,255,255,0.07);margin-bottom:0.7em;"></div>
            <div style="display:flex;align-items:center;gap:0.55em;">
                <div style="width:2.2em;height:2.2em;border-radius:50%;background:linear-gradient(135deg,#00b4d8,#007fa8);display:flex;align-items:center;justify-content:center;font-weight:900;color:#fff;font-size:0.95em;flex-shrink:0;">
                    {{ $initial }}
                </div>
                <div style="min-width:0;">
                    <div style="font-size:0.7em;font-weight:800;color:#fff;letter-spacing:0.05em;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $agentName }}</div>
                    <div style="font-size:0.56em;color:rgba(255,255,255,0.4);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $agentEmail }}</div>
                </div>
            </div>
            <div style="font-size:0.47em;color:rgba(255,255,255,0.16);letter-spacing:0.12em;text-transform:uppercase;margin-top:0.6em;">HFCOASTAL.CO.ZA</div>
        </div>
    </div>

    {{-- ── Right panel: images ── --}}
    <div style="flex:1;display:flex;flex-direction:column;overflow:hidden;gap:2px;margin-left:2px;">

        {{-- Main image (top ~62% height) --}}
        <div style="flex:1.65;overflow:hidden;position:relative;">
            @if($img1)
                <img src="{{ $img1 }}" class="ad-img-fit" crossorigin="anonymous" alt="">
            @else
                <div style="width:100%;height:100%;background:linear-gradient(135deg,#0d3259,#143d6e);"></div>
            @endif
        </div>

        {{-- Two images side by side (bottom ~38% height) --}}
        <div style="flex:1;display:flex;gap:2px;overflow:hidden;">
            <div style="flex:1;overflow:hidden;">
                @if($img2)
                    <img src="{{ $img2 }}" class="ad-img-fit" crossorigin="anonymous" alt="">
                @else
                    <div style="width:100%;height:100%;background:#0b2a4a;"></div>
                @endif
            </div>
            <div style="flex:1;overflow:hidden;">
                @if($img3)
                    <img src="{{ $img3 }}" class="ad-img-fit" crossorigin="anonymous" alt="">
                @else
                    <div style="width:100%;height:100%;background:#071e35;"></div>
                @endif
            </div>
        </div>
    </div>
</div>
@endif
