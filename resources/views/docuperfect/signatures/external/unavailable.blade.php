<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{--
        Johan, 2026-08-24 — cc6's public-link audit found this route was the
        last one in the product still leaking on its dead-link page (the
        document's own name — which auto-generates to include a recipient's
        name — rendered with no auth, no rate limit). Built on the SAME
        pattern the rest of the product converged on (SharedMatchController /
        PublicPresentationController's renderUnavailable()): a reason-driven
        heading + copy, agency branding, a route back to the agent — and
        NOTHING that confirms which document, property, party or date this
        link was ever for. A signing link goes to a named individual about a
        specific contract and gets forwarded; a stranger who finds a dead one
        must learn nothing from it.
    --}}
    @php
        $copy = match($reason ?? 'unavailable') {
            'cancelled' => 'This signing request has been withdrawn by the agency. It is no longer available to sign.',
            'declined'  => 'This document was declined and is no longer available to sign.',
            'expired'   => 'This signing link is no longer valid.',
            default     => 'This signing link is no longer available.',
        };
        $heading = match($reason ?? 'unavailable') {
            'cancelled' => 'No longer available',
            'declined'  => 'Signing declined',
            'expired'   => 'Link no longer available',
            default     => 'Link unavailable',
        };
    @endphp
    <title>{{ $heading }} — {{ $agencyName ?? 'Agency' }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md text-center">

        {{-- Agency Branding — same lookup + same "never leak an arbitrary
             tenant's branding" fallback already-completed.blade.php uses. --}}
        @if(!empty($agencyLogo))
            <img src="{{ $agencyLogo }}" alt="{{ $agencyName ?? 'Agency' }}" class="h-14 mx-auto mb-4">
        @else
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-200 mb-4">
                <svg class="w-8 h-8 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                </svg>
            </div>
        @endif

        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
            <h1 class="text-xl font-bold text-slate-800 mb-2">{{ $heading }}</h1>

            <p class="text-sm text-slate-500 mb-4">{{ $copy }}</p>

            <p class="text-sm text-slate-500">
                If you believe this is a mistake, contact your agent below.
            </p>

            {{-- Agent Contact — no document/property/party/date anywhere on
                 this page, deliberately: this must reveal nothing beyond "get
                 in touch with the agency" to anyone who finds a dead link. --}}
            @if(isset($agentName) && $agentName)
                <div class="p-3 rounded-xl bg-amber-50 border border-amber-200 text-left mt-4">
                    <div class="text-xs text-amber-600 font-medium mb-1">Contact your agent:</div>
                    <div class="text-sm font-medium text-slate-700">{{ $agentName }}</div>
                    @if(!empty($agentEmail))
                        <a href="mailto:{{ $agentEmail }}" class="text-xs text-blue-600 hover:underline">{{ $agentEmail }}</a>
                    @endif
                    @if(!empty($agentPhone))
                        <div class="text-xs text-slate-400">{{ $agentPhone }}</div>
                    @endif
                </div>
            @endif
        </div>

        <div class="text-center mt-4 text-xs text-slate-400">
            {{ $agencyName ?? 'Agency' }} &mdash; Document Signing
        </div>
    </div>
</body>
</html>
