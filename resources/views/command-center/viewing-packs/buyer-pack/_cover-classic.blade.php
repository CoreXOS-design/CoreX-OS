{{-- Viewing Pack cover — "Classic welcome" style (.ai/specs/viewing-pack.md §14).
     ONE partial, used by the PDF (cover.blade.php) and by the settings-page "Cover preview", so
     the two cannot drift. Portrait A4 at 96 dpi: 794 x 1120 box, everything absolutely placed in
     pixels with inline styles only (DomPDF-safe: no flex, no CSS variables, no object-fit).
     Input: $c = ViewingPackCoverService::dataFor() — colours, slogan/website/phone (each may be
     null → that line is simply not drawn), logo / photo boxes (null → omitted), agent text.
     Type sizes follow the reference page: WELCOME TO / VIEWING DAY 32pt, YOUR 115pt, bold sans. --}}
@php
    $W = \App\Services\ViewingPack\ViewingPackCoverService::PAGE_W;   // 794
    $H = \App\Services\ViewingPack\ViewingPackCoverService::PAGE_H;   // 1120
    $B = \App\Services\ViewingPack\ViewingPackCoverService::BAND_W;   // 60  (7.5% band)
    $L = $W - $B;                                                     // 734 (width left of the band)
    $navy   = $c['navy'];
    $accent = $c['accent'];
    $light  = $c['light'];
    $font   = "'Inter','DejaVu Sans',Arial,Helvetica,sans-serif";
    $hasBandText = filled($c['slogan']) || filled($c['website']) || filled($c['phone']);
    $photo  = $c['photo'] ?? null;
    $logo   = $c['logo'] ?? null;
@endphp
<div style="position:relative; width:{{ $W }}px; height:{{ $H }}px; overflow:hidden; background:#ffffff; font-family:{!! $font !!}; font-weight:700;">

    {{-- Navy band down the right edge --}}
    <div style="position:absolute; left:{{ $L }}px; top:0; width:{{ $B }}px; height:{{ $H }}px; background:{{ $navy }};"></div>

    {{-- Band text, rotated 90° so it reads bottom-to-top: slogan (bottom) → website → phone (top).
         A light-blue accent block sits behind the first part of the slogan. The box is page-height
         wide and band-wide tall, then rotated about its top-left corner. --}}
    @if($hasBandText)
        <div style="position:absolute; left:{{ $L }}px; top:{{ $H }}px; width:{{ $H }}px; height:{{ $B }}px; transform:rotate(-90deg); transform-origin:0 0;">
            @if(filled($c['slogan']))
                <div style="position:absolute; left:0; top:0; width:330px; height:{{ $B }}px; background:{{ $light }};"></div>
            @endif
            <table cellpadding="0" cellspacing="0" style="position:absolute; left:0; top:0; width:{{ $H }}px; height:{{ $B }}px; border-collapse:collapse;">
                <tr>
                    @if(filled($c['slogan']))
                        <td style="padding:0 0 0 36px; vertical-align:middle; white-space:nowrap; color:#ffffff; font-size:16px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase;">{{ $c['slogan'] }}</td>
                    @endif
                    @if(filled($c['website']))
                        <td style="padding:0 0 0 46px; vertical-align:middle; white-space:nowrap; color:#ffffff; font-size:16px; font-weight:700; letter-spacing:1.5px;">{{ $c['website'] }}</td>
                    @endif
                    <td style="width:100%;">&nbsp;</td>
                    @if(filled($c['phone']))
                        <td style="padding:0 36px 0 0; vertical-align:middle; white-space:nowrap; text-align:right; color:#ffffff; font-size:16px; font-weight:700; letter-spacing:1.5px;">{{ $c['phone'] }}</td>
                    @endif
                </tr>
            </table>
        </div>
    @endif

    {{-- Logo — top, across the remaining width (fitted to 646 x 210, never stretched).
         No logo → the agency name as plain text, never a broken image. --}}
    @if($logo)
        <img src="{{ $logo['uri'] }}" width="{{ $logo['w'] }}" height="{{ $logo['h'] }}" style="position:absolute; left:44px; top:40px; width:{{ $logo['w'] }}px; height:{{ $logo['h'] }}px;">
    @elseif(filled($c['agencyName']))
        <div style="position:absolute; left:44px; top:56px; width:646px; font-size:28px; letter-spacing:2px; text-transform:uppercase; color:{{ $navy }};">{{ $c['agencyName'] }}</div>
    @endif

    {{-- WELCOME TO / YOUR / VIEWING DAY — centred in the area left of the band --}}
    <div style="position:absolute; left:0; top:300px; width:{{ $L }}px; text-align:center;">
        <div style="font-size:32pt; line-height:52px; color:{{ $navy }}; text-transform:uppercase;">Welcome to</div>
        <div style="font-size:115pt; line-height:152px; height:152px; color:{{ $accent }}; text-transform:uppercase;">Your</div>
        <div style="font-size:32pt; line-height:52px; color:{{ $navy }}; text-transform:uppercase;">Viewing day</div>
    </div>

    {{-- Agent portrait — cut-out when the agent has one, else the plain photo; lower right, left
         of the band, standing on the bottom edge. --}}
    @if($photo)
        <img src="{{ $photo['uri'] }}" width="{{ $photo['w'] }}" height="{{ $photo['h'] }}" style="position:absolute; left:{{ $L - 20 - $photo['w'] }}px; top:{{ $H - $photo['h'] }}px; width:{{ $photo['w'] }}px; height:{{ $photo['h'] }}px;">
    @endif

    {{-- Agent details — bottom left, stacked, navy bold --}}
    <div style="position:absolute; left:48px; bottom:56px; width:280px; color:{{ $navy }};">
        @if(filled($c['agentName']))
            <div style="font-size:18pt; line-height:28px;">{{ $c['agentName'] }}</div>
        @endif
        @if(filled($c['agentCell']))
            <div style="font-size:12pt; line-height:22px;">{{ $c['agentCell'] }}</div>
        @endif
        @if(filled($c['agentEmail']))
            <div style="font-size:12pt; line-height:22px;">{{ $c['agentEmail'] }}</div>
        @endif
    </div>
</div>
