{{-- The agency's own mark on a code email: its logo, or its name when it has none. Needs $agencyName, $agencyLogoUrl, $agencyColor. --}}
@if(!empty($agencyLogoUrl))
    <img src="{{ $agencyLogoUrl }}" alt="{{ $agencyName }}" style="max-height:56px; max-width:220px;">
@else
    <div style="font-size:1.5rem; font-weight:800; letter-spacing:-0.02em; color:{{ $agencyColor ?? '#0b2a4a' }}; line-height:1.1;">{{ $agencyName }}</div>
@endif
