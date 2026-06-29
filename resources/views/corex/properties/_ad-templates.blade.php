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
    $fs        = $baseFontPx ? "font-size:{$baseFontPx}px;" : '';
    // Branding resolution (spec ad-manager.md §7): branch→agency logo, else CoreX wordmark.
    $logoUrl   = $logoUrl   ?? null;
    $agencyName= $agencyName ?? 'COREX';
    $website   = $website   ?? '';
    $footerTxt = $website ?: $agencyName;
    $img4      = $img4 ?? null;
    $img5      = $img5 ?? null;
    $statusBadge = $statusBadge ?? 'FOR SALE';
@endphp

{{-- Reusable logo block — pass $logoH (em height) and optional $logoColor for the wordmark. --}}
@php
    $renderLogo = function ($style = '', $emHeight = '2.6em', $wordColor = '#ffffff') use ($logoUrl) {
        if ($logoUrl) {
            return '<img src="' . e($logoUrl) . '" alt="" style="height:' . $emHeight . ';object-fit:contain;object-position:left center;filter:drop-shadow(0 2px 10px rgba(0,0,0,0.55));' . $style . '">';
        }
        return '<div style="font-family:Figtree,sans-serif;font-weight:900;font-size:' . $emHeight . ';line-height:1;color:' . $wordColor . ';' . $style . '">corex<span style="color:#33c4e0">os</span></div>';
    };

    // ── Agent identity (single OR co-listed two-agent split) ─────────────────
    // ad-manager.md §10d. Agent 2 is the co-listing agent: empty unless co-listed.
    // The generator JS swaps slot-1 nodes (js-ad-*) and slot-2 nodes (js-ad-*-2),
    // and shows/hides the slot-2 wrapper (js-ad-agent2). For a co-listed ad the two
    // agents render as SEPARATE blocks, never a merged "A & B" line.
    $agentName    = $agentName    ?? '';
    $agentEmail   = $agentEmail   ?? '';
    $agentDesig   = $agentDesig   ?? '';
    $initial      = $initial      ?? '';
    $agent2Name    = $agent2Name    ?? '';
    $agent2Email   = $agent2Email   ?? '';
    $agent2Desig   = $agent2Desig   ?? '';
    $agent2Initial = $agent2Initial ?? '';

    // Avatar + name + (email) chip. $slot 1 = listing (always shown), 2 = co-agent
    // (server-renders hidden; JS reveals only in "both"). $o keys: avatarEm,
    // avatarFsEm, nameEm, emailEm, nameColor, emailColor, avatarBg, avatarBorder,
    // align ('left'|'right'), showEmail.
    $agentChip = function ($slot, $name, $email, $initial, $o = []) {
        $is2  = $slot === 2;
        $av   = $o['avatarEm']    ?? '2.3em';
        $avFs = $o['avatarFsEm']  ?? '0.95em';
        $nEm  = $o['nameEm']      ?? '0.78em';
        $eEm  = $o['emailEm']     ?? '0.62em';
        $nCol = $o['nameColor']   ?? '#ffffff';
        $eCol = $o['emailColor']  ?? 'rgba(255,255,255,0.5)';
        $avBg = $o['avatarBg']    ?? 'linear-gradient(135deg,#00b4d8,#007fa8)';
        $avBd = $o['avatarBorder']?? '2px solid rgba(255,255,255,0.18)';
        $align   = $o['align']    ?? 'left';
        $showEml = $o['showEmail'] ?? true;
        $nc = $is2 ? 'js-ad-name-2'    : 'js-ad-name';
        $ec = $is2 ? 'js-ad-email-2'   : 'js-ad-email';
        $ic = $is2 ? 'js-ad-initial-2' : 'js-ad-initial';
        $avatar = '<div style="width:' . $av . ';height:' . $av . ';border-radius:50%;background:' . $avBg . ';display:flex;align-items:center;justify-content:center;font-size:' . $avFs . ';font-weight:800;color:#fff;flex-shrink:0;border:' . $avBd . ';"><span class="' . $ic . '">' . e($initial) . '</span></div>';
        $textAlign = $align === 'right' ? 'text-align:right;' : '';
        $text = '<div style="min-width:0;' . $textAlign . '">'
              . '<div style="font-size:' . $nEm . ';font-weight:800;color:' . $nCol . ';letter-spacing:0.06em;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><span class="' . $nc . '">' . e($name) . '</span></div>'
              . ($showEml ? '<div style="font-size:' . $eEm . ';color:' . $eCol . ';white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><span class="' . $ec . '">' . e($email) . '</span></div>' : '')
              . '</div>';
        $inner = $align === 'right' ? ($text . $avatar) : ($avatar . $text);
        $wrapCls   = $is2 ? 'js-ad-agent2' : '';
        $wrapAttr  = $is2 ? ' data-disp="flex"' : '';
        $hidden    = $is2 ? 'display:none;' : 'display:flex;';
        $justify   = $align === 'right' ? 'justify-content:flex-end;' : '';
        return '<div class="' . $wrapCls . '"' . $wrapAttr . ' style="' . $hidden . 'align-items:center;gap:0.6em;min-width:0;' . $justify . '">' . $inner . '</div>';
    };

    // Inline "NAME · email" (or just NAME) for the text-footer templates. Slot 2
    // server-renders hidden, JS reveals it in "both" — so a co-listed ad shows two
    // separate agent lines, not a merged one.
    $agentLine = function ($slot, $name, $email, $o = []) {
        $is2  = $slot === 2;
        $sep  = $o['sep'] ?? ($email !== '' ? ' · ' : '');
        $nc = $is2 ? 'js-ad-name-2'  : 'js-ad-name';
        $ec = $is2 ? 'js-ad-email-2' : 'js-ad-email';
        $body = '<span class="' . $nc . '">' . e($name) . '</span>'
              . (($o['showEmail'] ?? true) ? '<span class="ad-sep">' . e($sep) . '</span><span class="' . $ec . '">' . e($email) . '</span>' : '');
        if (! $is2) {
            return $body;
        }
        return '<span class="js-ad-agent2" data-disp="inline" style="display:none;"><span class="ad-sep"> · </span>' . $body . '</span>';
    };
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

        {{-- Logo top-left --}}
        <div style="position:absolute;top:0.9em;left:0.9em;z-index:10;">{!! $renderLogo('', '2.8em', '#ffffff') !!}</div>

        {{-- Left: main image (60% width) --}}
        <div style="flex:1.55;overflow:hidden;position:relative;">
            @if($img1)
                <img src="{{ $img1 }}" class="ad-img-fit" alt="">
            @else
                <div class="ad-placeholder"></div>
            @endif
        </div>

        {{-- Right col: 2 stacked images --}}
        <div style="flex:1;display:flex;flex-direction:column;overflow:hidden;gap:2px;margin-left:2px;">
            <div style="flex:1;overflow:hidden;">
                @if($img2)
                    <img src="{{ $img2 }}" class="ad-img-fit" alt="">
                @else
                    <div style="width:100%;height:100%;background:linear-gradient(135deg,#0d3259,#143d6e);"></div>
                @endif
            </div>
            <div style="flex:1;overflow:hidden;">
                @if($img3)
                    <img src="{{ $img3 }}" class="ad-img-fit" alt="">
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
        <span style="font-size:0.62em;font-weight:700;color:#0b2a4a;letter-spacing:0.1em;text-transform:uppercase;opacity:0.5;">{{ $agencyName }}</span>
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

        {{-- Agent row (single, or two split blocks when co-listed) --}}
        <div style="display:flex;align-items:center;gap:1.1em;">
            <div style="display:flex;align-items:center;gap:1.1em;flex:1;min-width:0;">
                {!! $agentChip(1, $agentName, $agentEmail, $initial) !!}
                {!! $agentChip(2, $agent2Name, $agent2Email, $agent2Initial) !!}
            </div>
            <div style="font-size:0.52em;font-weight:700;color:rgba(255,255,255,0.18);letter-spacing:0.1em;text-transform:uppercase;flex-shrink:0;">{{ $footerTxt }}</div>
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
        <img src="{{ $img1 }}" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block;" alt="">
    @else
        <div style="position:absolute;inset:0;background:linear-gradient(135deg,#0b2a4a,#143d6e);"></div>
    @endif

    {{-- Gradient overlays for depth --}}
    {{-- Top-left: logo protection --}}
    <div style="position:absolute;inset:0;background:linear-gradient(155deg,rgba(7,19,37,0.82) 0%,rgba(7,19,37,0) 42%);pointer-events:none;"></div>
    {{-- Bottom: content area --}}
    <div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(7,19,37,0.98) 0%,rgba(7,19,37,0.88) 28%,rgba(7,19,37,0.3) 52%,rgba(7,19,37,0) 70%);pointer-events:none;"></div>

    {{-- Logo top-left --}}
    <div style="position:absolute;top:1em;left:1em;z-index:10;">{!! $renderLogo('', '2.6em', '#ffffff') !!}</div>

    {{-- Property type badge top-right --}}
    <div style="position:absolute;top:1.1em;right:1.1em;background:rgba(0,180,216,0.92);color:#fff;font-size:0.62em;font-weight:800;padding:0.5em 1.1em;border-radius:2em;letter-spacing:0.1em;text-transform:uppercase;z-index:10;backdrop-filter:blur(4px);">
        {{ $type }}
    </div>

    {{-- Thumbnail strip (img2 + img3) mid-right --}}
    @if($img2 || $img3)
    <div style="position:absolute;bottom:36%;right:1.2em;display:flex;flex-direction:column;gap:0.4em;z-index:10;">
        @if($img2)
        <div style="width:5.5em;height:3.5em;border-radius:0.4em;overflow:hidden;border:1.5px solid rgba(255,255,255,0.25);box-shadow:0 4px 12px rgba(0,0,0,0.5);">
            <img src="{{ $img2 }}" class="ad-img-fit" alt="">
        </div>
        @endif
        @if($img3)
        <div style="width:5.5em;height:3.5em;border-radius:0.4em;overflow:hidden;border:1.5px solid rgba(255,255,255,0.25);box-shadow:0 4px 12px rgba(0,0,0,0.5);">
            <img src="{{ $img3 }}" class="ad-img-fit" alt="">
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

            {{-- Agent (single, or two split blocks when co-listed) --}}
            <div style="display:flex;align-items:center;gap:1em;flex-shrink:0;">
                {!! $agentChip(1, $agentName, $agentEmail, $initial, ['align' => 'right', 'avatarEm' => '2.4em', 'nameEm' => '0.78em', 'emailEm' => '0.6em', 'emailColor' => 'rgba(255,255,255,0.45)', 'avatarBorder' => '2px solid rgba(255,255,255,0.3)']) !!}
                {!! $agentChip(2, $agent2Name, $agent2Email, $agent2Initial, ['align' => 'right', 'avatarEm' => '2.4em', 'nameEm' => '0.78em', 'emailEm' => '0.6em', 'emailColor' => 'rgba(255,255,255,0.45)', 'avatarBorder' => '2px solid rgba(255,255,255,0.3)']) !!}
            </div>
        </div>

        <div style="margin-top:0.6em;font-size:0.5em;font-weight:700;color:rgba(255,255,255,0.18);letter-spacing:0.12em;text-transform:uppercase;">{{ $footerTxt }}</div>
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
        <div style="margin-bottom:auto;position:relative;z-index:1;">{!! $renderLogo('', '2.4em', '#ffffff') !!}</div>

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
            {{-- Agent (single, or two stacked blocks when co-listed) --}}
            <div style="display:flex;flex-direction:column;gap:0.5em;">
                {!! $agentChip(1, $agentName, $agentEmail, $initial, ['avatarEm' => '2.2em', 'nameEm' => '0.7em', 'emailEm' => '0.56em', 'emailColor' => 'rgba(255,255,255,0.4)', 'avatarBorder' => 'none']) !!}
                {!! $agentChip(2, $agent2Name, $agent2Email, $agent2Initial, ['avatarEm' => '2.2em', 'nameEm' => '0.7em', 'emailEm' => '0.56em', 'emailColor' => 'rgba(255,255,255,0.4)', 'avatarBorder' => 'none']) !!}
            </div>
            <div style="font-size:0.47em;color:rgba(255,255,255,0.16);letter-spacing:0.12em;text-transform:uppercase;margin-top:0.6em;">{{ $footerTxt }}</div>
        </div>
    </div>

    {{-- ── Right panel: images ── --}}
    <div style="flex:1;display:flex;flex-direction:column;overflow:hidden;gap:2px;margin-left:2px;">

        {{-- Main image (top ~62% height) --}}
        <div style="flex:1.65;overflow:hidden;position:relative;">
            @if($img1)
                <img src="{{ $img1 }}" class="ad-img-fit" alt="">
            @else
                <div style="width:100%;height:100%;background:linear-gradient(135deg,#0d3259,#143d6e);"></div>
            @endif
        </div>

        {{-- Two images side by side (bottom ~38% height) --}}
        <div style="flex:1;display:flex;gap:2px;overflow:hidden;">
            <div style="flex:1;overflow:hidden;">
                @if($img2)
                    <img src="{{ $img2 }}" class="ad-img-fit" alt="">
                @else
                    <div style="width:100%;height:100%;background:#0b2a4a;"></div>
                @endif
            </div>
            <div style="flex:1;overflow:hidden;">
                @if($img3)
                    <img src="{{ $img3 }}" class="ad-img-fit" alt="">
                @else
                    <div style="width:100%;height:100%;background:#071e35;"></div>
                @endif
            </div>
        </div>
    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     TEMPLATE 4 — JUST LISTED  (single hero + diagonal ribbon)
════════════════════════════════════════════════════════════════ --}}
@if($tpl === 'just_listed')
<div style="position:absolute;inset:0;background:#071325;overflow:hidden;{{ $fs }}">
    @if($img1)
        <img src="{{ $img1 }}" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;" alt="">
    @else
        <div style="position:absolute;inset:0;background:linear-gradient(135deg,#0b2a4a,#143d6e);"></div>
    @endif
    <div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(7,19,37,0.96) 0%,rgba(7,19,37,0.55) 34%,rgba(7,19,37,0) 62%);"></div>

    {{-- Diagonal ribbon top-left --}}
    <div style="position:absolute;top:1.5em;left:-3.4em;transform:rotate(-45deg);background:#e63946;color:#fff;font-size:0.8em;font-weight:900;letter-spacing:0.16em;text-transform:uppercase;padding:0.45em 4em;box-shadow:0 6px 20px rgba(0,0,0,0.45);">Just Listed</div>

    {{-- Logo top-right --}}
    <div style="position:absolute;top:1em;right:1.1em;z-index:10;">{!! $renderLogo('', '2.4em', '#ffffff') !!}</div>

    <div style="position:absolute;bottom:0;left:0;right:0;padding:0 1.5em 1.3em;">
        <div style="width:2.8em;height:0.22em;background:#e63946;border-radius:1em;margin-bottom:0.6em;"></div>
        <div style="font-size:3.6em;font-weight:900;color:#fff;line-height:1;letter-spacing:-0.025em;">{{ $price }}</div>
        <div style="font-size:0.9em;font-weight:700;color:rgba(255,255,255,0.9);margin-top:0.35em;text-transform:uppercase;letter-spacing:0.04em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $title }}</div>
        <div style="font-size:0.66em;font-weight:500;color:rgba(255,255,255,0.5);margin-top:0.15em;text-transform:uppercase;letter-spacing:0.06em;">{{ $suburb }}</div>
        <div style="display:flex;align-items:center;gap:0.55em;font-size:0.7em;font-weight:600;color:rgba(255,255,255,0.7);letter-spacing:0.05em;text-transform:uppercase;margin-top:0.7em;">
            <span>{{ $beds }} BED</span><span style="opacity:0.3;">|</span><span>{{ $baths }} BATH</span>
            @if($garages)<span style="opacity:0.3;">|</span><span>{{ $garages }} GAR</span>@endif
            @if($size)<span style="opacity:0.3;">|</span><span>{{ $size }}</span>@endif
        </div>
    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     TEMPLATE 5 — OPEN HOUSE  (hero + centred viewing call-out card)
════════════════════════════════════════════════════════════════ --}}
@if($tpl === 'open_house')
<div style="position:absolute;inset:0;background:#071325;overflow:hidden;{{ $fs }}">
    @if($img1)
        <img src="{{ $img1 }}" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;" alt="">
    @else
        <div style="position:absolute;inset:0;background:linear-gradient(135deg,#0b2a4a,#143d6e);"></div>
    @endif
    <div style="position:absolute;inset:0;background:rgba(7,19,37,0.55);"></div>

    <div style="position:absolute;top:1em;left:1.1em;z-index:10;">{!! $renderLogo('', '2.4em', '#ffffff') !!}</div>

    {{-- Centre card --}}
    <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;">
        <div style="background:rgba(7,17,30,0.86);border:1px solid rgba(255,255,255,0.12);border-radius:0.7em;padding:1.5em 2.2em;text-align:center;backdrop-filter:blur(4px);max-width:70%;">
            <div style="font-size:0.7em;font-weight:800;letter-spacing:0.22em;text-transform:uppercase;color:#00b4d8;">Open House</div>
            <div style="font-size:1.5em;font-weight:900;color:#fff;margin-top:0.4em;letter-spacing:0.02em;">Book a Viewing</div>
            <div style="font-size:0.7em;color:rgba(255,255,255,0.6);margin-top:0.35em;text-transform:uppercase;letter-spacing:0.06em;">{{ $title }}</div>
            <div style="width:100%;height:1px;background:rgba(255,255,255,0.12);margin:0.9em 0;"></div>
            <div style="font-size:1.7em;font-weight:900;color:#fff;line-height:1;">{{ $price }}</div>
            <div style="font-size:0.62em;color:rgba(255,255,255,0.5);margin-top:0.5em;">{!! $agentLine(1, $agentName, $agentEmail) !!}{!! $agentLine(2, $agent2Name, $agent2Email) !!}</div>
        </div>
    </div>

    <div style="position:absolute;bottom:0.9em;left:0;right:0;text-align:center;font-size:0.52em;font-weight:700;color:rgba(255,255,255,0.55);letter-spacing:0.12em;text-transform:uppercase;">{{ $footerTxt }}</div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     TEMPLATE 6 — EDITORIAL  (light, minimalist luxury)
════════════════════════════════════════════════════════════════ --}}
@if($tpl === 'editorial')
<div style="position:absolute;inset:0;background:#f5f3ee;display:flex;flex-direction:column;{{ $fs }}">
    <div style="flex:1.9;min-height:0;position:relative;overflow:hidden;margin:1em 1em 0;">
        @if($img1)
            <img src="{{ $img1 }}" class="ad-img-fit" alt="">
        @else
            <div class="ad-placeholder"></div>
        @endif
    </div>
    <div style="flex-shrink:0;padding:0.85em 1.3em 1.1em;display:flex;align-items:flex-end;justify-content:space-between;gap:1em;">
        <div style="min-width:0;">
            <div style="font-size:0.6em;font-weight:600;color:#9a7b3f;letter-spacing:0.2em;text-transform:uppercase;">{{ $type }}</div>
            <div style="font-size:1.05em;font-weight:700;color:#1a1a1a;margin-top:0.25em;letter-spacing:0.01em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $title }}</div>
            <div style="font-size:0.62em;font-weight:500;color:#6b6b6b;margin-top:0.15em;text-transform:uppercase;letter-spacing:0.08em;">{{ $suburb }}</div>
            <div style="font-size:0.62em;color:#6b6b6b;margin-top:0.45em;letter-spacing:0.04em;">{{ $beds }} Bed &nbsp;·&nbsp; {{ $baths }} Bath @if($garages)&nbsp;·&nbsp; {{ $garages }} Garage @endif @if($size)&nbsp;·&nbsp; {{ $size }}@endif</div>
        </div>
        <div style="text-align:right;flex-shrink:0;">
            <div style="font-size:2.1em;font-weight:300;color:#1a1a1a;line-height:1;letter-spacing:-0.01em;">{{ $price }}</div>
            <div style="margin-top:0.55em;display:flex;justify-content:flex-end;">{!! $renderLogo('', '1.7em', '#1a1a1a') !!}</div>
        </div>
    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     TEMPLATE 7 — FEATURE GRID  (2×2 photo mosaic + bottom bar)
════════════════════════════════════════════════════════════════ --}}
@if($tpl === 'feature_grid')
<div style="position:absolute;inset:0;background:#071325;display:flex;flex-direction:column;{{ $fs }}">
    <div style="flex:1;min-height:0;display:grid;grid-template-columns:1fr 1fr;grid-template-rows:1fr 1fr;gap:2px;position:relative;">
        @php $gridImgs = array_values(array_filter([$img1,$img2,$img3,$img4])); @endphp
        @for($i = 0; $i < 4; $i++)
            <div style="overflow:hidden;position:relative;">
                @if(isset($gridImgs[$i]))
                    <img src="{{ $gridImgs[$i] }}" class="ad-img-fit" alt="">
                @else
                    <div style="width:100%;height:100%;background:linear-gradient(135deg,#0d3259,#143d6e);"></div>
                @endif
            </div>
        @endfor
        {{-- Centre price medallion --}}
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#e63946;color:#fff;border-radius:0.5em;padding:0.5em 1.1em;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,0.5);border:2px solid rgba(255,255,255,0.18);">
            <div style="font-size:1.5em;font-weight:900;line-height:1;">{{ $price }}</div>
        </div>
        <div style="position:absolute;top:0.8em;left:0.8em;">{!! $renderLogo('', '2.2em', '#ffffff') !!}</div>
    </div>
    <div style="flex-shrink:0;background:#07111e;padding:0.6em 1.4em 0.7em;display:flex;align-items:center;justify-content:space-between;gap:1em;">
        <div style="min-width:0;">
            <div style="font-size:0.82em;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:0.04em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $title }}</div>
            <div style="font-size:0.66em;font-weight:600;color:rgba(255,255,255,0.6);text-transform:uppercase;letter-spacing:0.06em;margin-top:0.2em;">{{ $suburb }}</div>
        </div>
        <div style="font-size:0.7em;font-weight:600;color:rgba(255,255,255,0.7);letter-spacing:0.05em;text-transform:uppercase;flex-shrink:0;white-space:nowrap;">{{ $beds }} BED · {{ $baths }} BATH @if($garages)· {{ $garages }} GAR @endif</div>
    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     TEMPLATE 8 — PRICE SPOTLIGHT  (oversized price + NEW PRICE tag)
════════════════════════════════════════════════════════════════ --}}
@if($tpl === 'price_spotlight')
<div style="position:absolute;inset:0;background:#071325;overflow:hidden;{{ $fs }}">
    @if($img1)
        <img src="{{ $img1 }}" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;" alt="">
    @else
        <div style="position:absolute;inset:0;background:linear-gradient(135deg,#0b2a4a,#143d6e);"></div>
    @endif
    <div style="position:absolute;inset:0;background:linear-gradient(125deg,rgba(7,19,37,0.92) 0%,rgba(7,19,37,0.55) 55%,rgba(7,19,37,0.15) 100%);"></div>

    <div style="position:absolute;top:1em;right:1.1em;z-index:10;">{!! $renderLogo('', '2.4em', '#ffffff') !!}</div>

    <div style="position:absolute;top:50%;left:1.6em;transform:translateY(-50%);max-width:62%;">
        <div style="display:inline-block;background:#e63946;color:#fff;font-size:0.62em;font-weight:900;letter-spacing:0.16em;text-transform:uppercase;padding:0.4em 1em;border-radius:0.35em;margin-bottom:0.7em;">New Price</div>
        <div style="font-size:4.6em;font-weight:900;color:#fff;line-height:0.95;letter-spacing:-0.03em;text-shadow:0 4px 22px rgba(0,0,0,0.5);">{{ $price }}</div>
        <div style="font-size:0.92em;font-weight:700;color:rgba(255,255,255,0.9);margin-top:0.4em;text-transform:uppercase;letter-spacing:0.04em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $title }}</div>
        <div style="font-size:0.66em;font-weight:600;color:rgba(255,255,255,0.55);margin-top:0.2em;text-transform:uppercase;letter-spacing:0.08em;">{{ $suburb }} · {{ $beds }} BED · {{ $baths }} BATH</div>
    </div>
    <div style="position:absolute;bottom:0.9em;left:1.6em;font-size:0.55em;font-weight:700;color:rgba(255,255,255,0.4);letter-spacing:0.12em;text-transform:uppercase;">{!! $agentLine(1, $agentName, '', ['showEmail' => false]) !!}{!! $agentLine(2, $agent2Name, '', ['showEmail' => false]) !!} · {{ $footerTxt }}</div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     TEMPLATE 9 — COMING SOON  (dimmed teaser hero)
════════════════════════════════════════════════════════════════ --}}
@if($tpl === 'coming_soon')
<div style="position:absolute;inset:0;background:#04101f;overflow:hidden;{{ $fs }}">
    @if($img1)
        <img src="{{ $img1 }}" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;filter:brightness(0.4) saturate(0.85);" alt="">
    @else
        <div style="position:absolute;inset:0;background:linear-gradient(135deg,#08233f,#0b2a4a);"></div>
    @endif
    <div style="position:absolute;inset:0;background:radial-gradient(circle at 50% 45%,rgba(0,180,216,0.12),transparent 60%);"></div>

    <div style="position:absolute;top:1.1em;left:0;right:0;text-align:center;">{!! $renderLogo('margin:0 auto;', '2.4em', '#ffffff') !!}</div>

    <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:0 2em;">
        <div style="font-size:0.8em;font-weight:700;letter-spacing:0.4em;text-transform:uppercase;color:#00b4d8;">Coming Soon</div>
        <div style="font-size:2.6em;font-weight:900;color:#fff;margin-top:0.3em;line-height:1;letter-spacing:-0.01em;">{{ $type }} in {{ $suburb }}</div>
        <div style="font-size:0.72em;color:rgba(255,255,255,0.6);margin-top:0.7em;max-width:80%;letter-spacing:0.04em;">An exceptional new listing is about to hit the market. Register your interest today.</div>
        <div style="font-size:0.66em;font-weight:700;color:#fff;margin-top:1.1em;letter-spacing:0.08em;text-transform:uppercase;">{!! $agentLine(1, $agentName, $agentEmail) !!}{!! $agentLine(2, $agent2Name, $agent2Email) !!}</div>
    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     TEMPLATE 10 — SOLD / UNDER OFFER  (celebration stamp)
════════════════════════════════════════════════════════════════ --}}
@if($tpl === 'sold')
<div style="position:absolute;inset:0;background:#071325;overflow:hidden;{{ $fs }}">
    @if($img1)
        <img src="{{ $img1 }}" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;filter:brightness(0.6);" alt="">
    @else
        <div style="position:absolute;inset:0;background:linear-gradient(135deg,#0b2a4a,#143d6e);"></div>
    @endif
    <div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(7,19,37,0.92),rgba(7,19,37,0.2));"></div>

    <div style="position:absolute;top:1em;left:1.1em;z-index:10;">{!! $renderLogo('', '2.4em', '#ffffff') !!}</div>

    {{-- Stamp --}}
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-55%) rotate(-9deg);border:0.28em solid #19c37d;border-radius:0.4em;padding:0.25em 1.2em;background:rgba(7,19,37,0.35);">
        <div style="font-size:3.4em;font-weight:900;color:#19c37d;letter-spacing:0.06em;line-height:1;">{{ $statusBadge }}</div>
    </div>

    <div style="position:absolute;bottom:0;left:0;right:0;padding:0 1.5em 1.2em;text-align:center;">
        <div style="font-size:0.9em;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:0.05em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $title }}</div>
        <div style="font-size:0.66em;font-weight:600;color:rgba(255,255,255,0.55);margin-top:0.2em;text-transform:uppercase;letter-spacing:0.08em;">{{ $suburb }}</div>
        <div style="font-size:0.6em;font-weight:700;color:#00b4d8;margin-top:0.7em;letter-spacing:0.08em;text-transform:uppercase;">Another one closed by {!! $agentLine(1, $agentName, '', ['showEmail' => false]) !!}{!! $agentLine(2, $agent2Name, '', ['showEmail' => false]) !!}</div>
    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     TEMPLATE 11 — FOR RENT  (hero left / info right, p/m emphasis)
════════════════════════════════════════════════════════════════ --}}
@if($tpl === 'for_rent')
<div style="position:absolute;inset:0;display:flex;background:#071325;{{ $fs }}">
    <div style="flex:1.45;overflow:hidden;position:relative;">
        @if($img1)
            <img src="{{ $img1 }}" class="ad-img-fit" alt="">
        @else
            <div style="width:100%;height:100%;background:linear-gradient(135deg,#0d3259,#143d6e);"></div>
        @endif
        <div style="position:absolute;top:0.9em;left:0.9em;background:#19c37d;color:#04221a;font-size:0.62em;font-weight:900;letter-spacing:0.14em;text-transform:uppercase;padding:0.45em 1em;border-radius:0.3em;">To Let</div>
    </div>
    <div style="width:42%;flex-shrink:0;background:#07101a;display:flex;flex-direction:column;padding:1.2em 1.35em;position:relative;">
        <div>{!! $renderLogo('', '2.2em', '#ffffff') !!}</div>
        <div style="flex:1;display:flex;flex-direction:column;justify-content:center;">
            <div style="font-size:2.5em;font-weight:900;color:#19c37d;line-height:1;letter-spacing:-0.02em;">{{ $price }}</div>
            <div style="font-size:0.62em;font-weight:700;color:rgba(255,255,255,0.45);text-transform:uppercase;letter-spacing:0.12em;margin-top:0.2em;">Per Month</div>
            <div style="font-size:0.8em;font-weight:700;color:#fff;margin-top:0.7em;text-transform:uppercase;letter-spacing:0.03em;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">{{ $title }}</div>
            <div style="font-size:0.6em;font-weight:500;color:rgba(255,255,255,0.4);margin-top:0.25em;text-transform:uppercase;letter-spacing:0.07em;">{{ $suburb }}</div>
            <div style="width:100%;height:1px;background:rgba(255,255,255,0.1);margin:0.8em 0;"></div>
            <div style="display:flex;gap:1em;font-size:0.66em;font-weight:600;color:rgba(255,255,255,0.7);">
                <span><b style="color:#19c37d;font-size:1.1em;">{{ $beds }}</b> Bed</span>
                <span><b style="color:#19c37d;font-size:1.1em;">{{ $baths }}</b> Bath</span>
                @if($garages)<span><b style="color:#19c37d;font-size:1.1em;">{{ $garages }}</b> Gar</span>@endif
            </div>
        </div>
        <div style="font-size:0.58em;color:rgba(255,255,255,0.4);letter-spacing:0.06em;text-transform:uppercase;">{!! $agentLine(1, $agentName, '', ['showEmail' => false]) !!}{!! $agentLine(2, $agent2Name, '', ['showEmail' => false]) !!} · {{ $footerTxt }}</div>
    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     TEMPLATE 12 — AGENT SPOTLIGHT  (agent front and centre over hero)
════════════════════════════════════════════════════════════════ --}}
@if($tpl === 'agent_spotlight')
<div style="position:absolute;inset:0;background:#071325;overflow:hidden;{{ $fs }}">
    @if($img1)
        <img src="{{ $img1 }}" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;" alt="">
    @else
        <div style="position:absolute;inset:0;background:linear-gradient(135deg,#0b2a4a,#143d6e);"></div>
    @endif
    <div style="position:absolute;inset:0;background:linear-gradient(110deg,rgba(7,19,37,0.95) 0%,rgba(7,19,37,0.7) 45%,rgba(7,19,37,0.1) 100%);"></div>

    <div style="position:absolute;top:1em;right:1.1em;z-index:10;">{!! $renderLogo('', '2.2em', '#ffffff') !!}</div>

    <div style="position:absolute;top:50%;left:1.6em;transform:translateY(-50%);max-width:58%;">
        <div style="width:5em;height:5em;border-radius:50%;background:linear-gradient(135deg,#00b4d8,#007fa8);display:flex;align-items:center;justify-content:center;font-size:1.8em;font-weight:900;color:#fff;border:0.18em solid rgba(255,255,255,0.25);box-shadow:0 8px 26px rgba(0,0,0,0.45);"><span class="js-ad-initial">{{ $initial }}</span></div>
        <div style="font-size:1.5em;font-weight:900;color:#fff;margin-top:0.5em;letter-spacing:0.02em;line-height:1;"><span class="js-ad-name">{{ $agentName }}</span></div>
        <div style="font-size:0.66em;font-weight:700;color:#00b4d8;margin-top:0.3em;text-transform:uppercase;letter-spacing:0.1em;"><span class="js-ad-desig">{{ $agentDesig }}</span></div>
        <div style="font-size:0.62em;color:rgba(255,255,255,0.6);margin-top:0.5em;"><span class="js-ad-email">{{ $agentEmail }}</span></div>
        {{-- Co-listing agent — a compact second profile, shown only when co-listed --}}
        <div style="margin-top:0.7em;">{!! $agentChip(2, $agent2Name, $agent2Email, $agent2Initial, ['avatarEm' => '2.6em', 'avatarFsEm' => '1.05em', 'nameEm' => '0.82em', 'emailEm' => '0.6em', 'emailColor' => 'rgba(255,255,255,0.6)', 'avatarBorder' => '0.12em solid rgba(255,255,255,0.25)']) !!}</div>
        <div style="width:100%;max-width:18em;height:1px;background:rgba(255,255,255,0.15);margin:0.8em 0;"></div>
        <div style="font-size:0.7em;font-weight:600;color:rgba(255,255,255,0.85);text-transform:uppercase;letter-spacing:0.04em;">Now Marketing · {{ $price }}</div>
        <div style="font-size:0.6em;color:rgba(255,255,255,0.5);margin-top:0.2em;text-transform:uppercase;letter-spacing:0.06em;">{{ $title }} · {{ $suburb }}</div>
    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     TEMPLATE 13 — SHOWCASE  (large hero + 4-photo filmstrip)
════════════════════════════════════════════════════════════════ --}}
@if($tpl === 'showcase')
<div style="position:absolute;inset:0;background:#071325;display:flex;flex-direction:column;{{ $fs }}">
    <div style="flex:2.1;min-height:0;position:relative;overflow:hidden;">
        @if($img1)
            <img src="{{ $img1 }}" class="ad-img-fit" alt="">
        @else
            <div style="width:100%;height:100%;background:linear-gradient(135deg,#0d3259,#143d6e);"></div>
        @endif
        <div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(7,19,37,0.9),rgba(7,19,37,0) 55%);"></div>
        <div style="position:absolute;top:0.9em;left:0.9em;">{!! $renderLogo('', '2.4em', '#ffffff') !!}</div>
        <div style="position:absolute;bottom:0.8em;left:1.2em;right:1.2em;display:flex;align-items:flex-end;justify-content:space-between;gap:1em;">
            <div style="min-width:0;">
                <div style="font-size:0.86em;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:0.04em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $title }}</div>
                <div style="font-size:0.62em;font-weight:600;color:rgba(255,255,255,0.6);text-transform:uppercase;letter-spacing:0.07em;margin-top:0.15em;">{{ $suburb }} · {{ $beds }} BED · {{ $baths }} BATH</div>
            </div>
            <div style="font-size:1.8em;font-weight:900;color:#fff;line-height:1;flex-shrink:0;">{{ $price }}</div>
        </div>
    </div>
    {{-- Filmstrip --}}
    <div style="flex:0.62;min-height:0;display:flex;gap:2px;background:#04101f;">
        @php $strip = array_values(array_filter([$img2,$img3,$img4,$img5])); @endphp
        @for($i = 0; $i < 4; $i++)
            <div style="flex:1;overflow:hidden;">
                @if(isset($strip[$i]))
                    <img src="{{ $strip[$i] }}" class="ad-img-fit" alt="">
                @else
                    <div style="width:100%;height:100%;background:linear-gradient(135deg,#071e35,#0b2a4a);"></div>
                @endif
            </div>
        @endfor
    </div>
</div>
@endif
