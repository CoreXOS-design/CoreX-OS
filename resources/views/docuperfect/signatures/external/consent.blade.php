<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Consent Declaration — {{ $agencyName }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-lg" x-data="{ accepted: false }">

        {{-- Agency Branding --}}
        <div class="text-center mb-6">
            @if($agencyLogo)
                <img src="{{ $agencyLogo }}" alt="{{ $agencyName }}" class="h-14 mx-auto mb-3">
            @else
                <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl mb-3" style="background:{{ $agencyColor }};">
                    <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                </div>
            @endif
            <h1 class="text-xl font-bold text-slate-800">Consent Declaration</h1>
            <p class="text-sm text-slate-500 mt-1">Please read and accept before proceeding</p>
        </div>

        {{-- Identity Confirmed Banner --}}
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 mb-4 flex items-center gap-3">
            <svg class="w-5 h-5 text-emerald-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <div>
                <div class="text-sm font-medium text-emerald-800">Identity Verified</div>
                <div class="text-xs text-emerald-600">{{ $signerName }} (ID: ****{{ $idLastFour }})</div>
            </div>
        </div>

        {{-- Consent Card --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">

            {{-- Document Info --}}
            <div class="mb-5 p-3 rounded-xl bg-slate-50 border border-slate-100">
                <div class="text-xs text-slate-400 uppercase tracking-wider font-semibold mb-1">Document</div>
                <div class="text-sm font-medium text-slate-700">{{ $documentName }}</div>
            </div>

            {{-- Consent Text --}}
            <div class="mb-5 p-4 rounded-xl bg-amber-50 border border-amber-200 text-sm text-slate-700 leading-relaxed">
                <div class="font-semibold text-slate-800 mb-3">By proceeding, I confirm:</div>

                <ol class="list-decimal list-outside ml-4 space-y-2">
                    <li>I am <strong>{{ $signerName }}</strong> (ID: ****{{ $idLastFour }}).</li>
                    <li>I am acting of my own free will and have not been coerced.</li>
                    <li>I understand I am about to review and electronically sign legal documents.</li>
                    <li>My electronic signature carries the same legal weight as a handwritten signature under the <em>Electronic Communications and Transactions Act 25 of 2002</em>.</li>
                    <li>I consent to the processing of my personal information for the purposes of this transaction in terms of the <em>Protection of Personal Information Act 4 of 2013</em>.</li>
                </ol>

                <div class="mt-3 pt-3 border-t border-amber-200 font-medium text-slate-800">
                    I have read and understood the above.
                </div>
            </div>

            {{-- Consent Form --}}
            <form action="{{ route('signatures.external.consent', $token) }}" method="POST">
                @csrf

                {{-- Checkbox --}}
                <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200 cursor-pointer hover:bg-slate-50 transition-colors mb-4"
                       :class="accepted ? 'border-blue-300 bg-blue-50' : ''">
                    <input type="checkbox" name="consent_accepted" x-model="accepted"
                           class="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    <span class="text-sm text-slate-700 select-none">
                        I confirm the above declaration
                    </span>
                </label>

                {{-- Submit --}}
                <button type="submit"
                        :disabled="!accepted"
                        class="w-full rounded-lg px-4 py-3 text-sm font-semibold text-white transition-all"
                        :class="accepted ? 'opacity-100 cursor-pointer' : 'opacity-50 cursor-not-allowed'"
                        :style="'background:' + (accepted ? '{{ $agencyColor }}' : '#94a3b8')">
                    <svg class="inline w-4 h-4 mr-1 -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                    </svg>
                    Proceed to Documents
                </button>
            </form>
        </div>

        {{-- Footer --}}
        <div class="text-center mt-4 space-y-1">
            <div class="text-xs text-slate-400">
                <svg class="inline w-3.5 h-3.5 text-slate-300 mr-1 -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
                Secure document signing powered by CoreX OS
            </div>
            <div class="text-xs text-slate-300">{{ $agencyName }}</div>
        </div>
    </div>
</body>
</html>
