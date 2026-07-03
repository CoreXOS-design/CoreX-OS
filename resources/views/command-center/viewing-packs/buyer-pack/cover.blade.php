<!DOCTYPE html>
<html lang="en">
{{-- AT-160 — pack cover rebuilt on the listing-presentation front-page design
     (navy brand line, accent bar, address/detail treatment, navy-ruled agent
     block with a bordered portrait, PPRA footer). Headline: "Welcome to your
     viewing day". Bounded to exactly ONE A4 page by construction: fixed .pg
     height + overflow:hidden, agent block absolutely anchored to the bottom,
     buyer name clamped. DomPDF-safe (no flex/min-height/color-mix). --}}
<head>@include('command-center.viewing-packs.buyer-pack._head')</head>
<body>
    <div class="pg" style="height:1020px; overflow:hidden;">
        {{-- Brand line (uppercase navy — mirrors the presentation .cover-brand) --}}
        <div style="display:flex; align-items:center; gap:14px;">
            @if($logo)<img src="{{ $logo }}" style="height:44px; width:auto;">@endif
            <span style="font-size:13px; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:var(--brand);">{{ $agencyName }}</span>
        </div>

        {{-- Title block (upper-middle) --}}
        <div style="margin-top:150px;">
            <div style="font-size:12px; font-weight:700; letter-spacing:0.16em; text-transform:uppercase; color:var(--teal);">Viewing Pack</div>
            <div style="width:80px; height:4px; background:var(--brand); border-radius:2px; margin:18px 0 26px;"></div>
            <h1 style="font-size:38px; line-height:1.12; color:var(--brand); margin:0 0 14px; max-width:640px;">Welcome to your<br>viewing day</h1>
            @if($buyerName)
                <p style="font-size:15px; color:var(--text-muted); margin:0 0 6px;">Prepared for <strong style="color:var(--text);">{{ \Illuminate\Support\Str::limit($buyerName, 58) }}</strong></p>
            @endif
            <p style="font-size:14px; color:var(--text-muted); line-height:1.7;">
                {{ $propertyCount }} {{ \Illuminate\Support\Str::plural('property', $propertyCount) }} selected for viewing &middot; {{ $date }}
            </p>
        </div>

        {{-- Agent block — absolutely anchored to the page bottom (one-page-safe),
             navy top rule + bordered portrait, matching the presentation cover. --}}
        <div style="position:absolute; left:56px; right:56px; bottom:48px; border-top:2px solid var(--brand); padding-top:20px;">
            <div style="display:flex; align-items:flex-end; justify-content:space-between;">
                <div style="flex:1; padding-right:20px;">
                    <div style="font-size:11px; font-weight:600; letter-spacing:0.1em; text-transform:uppercase; color:var(--text-muted); margin-bottom:6px;">Your agent</div>
                    <div style="font-size:18px; font-weight:700; color:var(--brand);">{{ $agentName }}</div>
                    <div style="font-size:12px; color:var(--text-muted); line-height:1.7;">
                        @if($agentPhone){{ $agentPhone }}<br>@endif
                        @if($agentEmail){{ $agentEmail }}<br>@endif
                        {{ $agencyName }}
                    </div>
                </div>
                @if($agentPhoto)
                    <img src="{{ $agentPhoto }}" style="width:128px; height:156px; object-fit:cover; border-radius:8px; border:3px solid var(--brand);">
                @endif
            </div>
        </div>

        {{-- PPRA footer note (as on the presentation cover) --}}
        <div style="position:absolute; left:0; right:0; bottom:16px; text-align:center; font-size:9px; color:#9aa7b4;">Registered with the PPRA</div>
    </div>
</body>
</html>
