<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Already Signed — {{ $agencyName ?? 'Home Finders Coastal' }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md text-center">

        {{-- Agency Branding --}}
        @if(!empty($agencyLogo))
            <img src="{{ $agencyLogo }}" alt="{{ $agencyName ?? 'Agency' }}" class="h-14 mx-auto mb-4">
        @else
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-100 mb-4">
                <svg class="w-8 h-8 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
        @endif

        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
            <h1 class="text-xl font-bold text-slate-800 mb-2">Already Signed</h1>

            <p class="text-sm text-slate-500 mb-4">
                You have already completed signing this document.
            </p>

            {{-- Document Details --}}
            <div class="p-3 rounded-xl bg-emerald-50 border border-emerald-100 mb-4">
                <div class="text-xs text-emerald-500 uppercase tracking-wider font-semibold mb-1">Document</div>
                <div class="text-sm font-medium text-slate-700">{{ $request->template->document->name ?? 'Document' }}</div>
                @if($request->completed_at)
                <div class="text-xs text-slate-400 mt-1">
                    Signed on {{ $request->completed_at->format('d M Y \a\t H:i') }}
                </div>
                @endif
            </div>

            {{-- Consent Timestamp --}}
            @if(isset($consentLog) && $consentLog)
                <div class="p-3 rounded-xl bg-blue-50 border border-blue-100 mb-4">
                    <div class="text-xs text-blue-500 uppercase tracking-wider font-semibold mb-1">Consent Recorded</div>
                    <div class="text-xs text-slate-500">
                        {{ $consentLog->consent_accepted_at->format('d M Y \a\t H:i') }}
                    </div>
                </div>
            @endif

            {{-- Signed by --}}
            <div class="p-3 rounded-xl bg-slate-50 border border-slate-100 mb-4 text-left">
                <div class="text-xs text-slate-400 uppercase tracking-wider font-semibold mb-1">Signed by</div>
                <div class="text-sm font-medium text-slate-700">{{ $request->signer_name }}</div>
                <div class="text-xs text-slate-400">{{ ucfirst(str_replace('_', ' ', $request->party_role)) }}</div>
            </div>

            {{-- Download Link --}}
            @if(isset($downloadAvailable) && $downloadAvailable)
                <a href="{{ route('signatures.download.page', $request->token) }}"
                   class="inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold text-white transition-colors mb-4"
                   style="background:{{ $agencyColor ?? '#0b2a4a' }};">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    Download Your Copy
                </a>
            @endif

            <p class="text-sm text-slate-500 mb-4">
                No further action is needed from you. You will receive a copy of the fully signed document once all parties have signed.
            </p>

            {{-- Agent Contact --}}
            @if(isset($agentName) && $agentName)
                <div class="p-3 rounded-xl bg-amber-50 border border-amber-200 text-left">
                    <div class="text-xs text-amber-600 font-medium mb-1">
                        If you believe this is an error, contact your agent:
                    </div>
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
            {{ $agencyName ?? 'Home Finders Coastal' }} &mdash; Document Signing
        </div>
    </div>
</body>
</html>
