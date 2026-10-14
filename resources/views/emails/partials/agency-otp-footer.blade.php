{{-- The agency's name and contact details under a code email. Needs $agency and $agencyName. --}}
<p style="margin:0 0 4px; font-size:0.75rem; font-weight:600; color:#6b7280;">{{ $agencyName }}</p>
@php
    $details = array_filter([
        trim((string) ($agency->address ?? '')),
        trim((string) ($agency->phone ?? '')),
        trim((string) ($agency->email ?? '')),
        trim((string) ($agency->website ?? '')),
    ]);
@endphp
@if($details)
    <p style="margin:0; font-size:0.6875rem; line-height:1.5; color:#9ca3af;">{{ implode(' · ', $details) }}</p>
@endif
