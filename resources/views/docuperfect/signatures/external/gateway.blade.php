<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Document Signing — {{ $agencyName }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md">

        {{-- Agency Branding --}}
        <div class="text-center mb-6">
            @if($agencyLogo)
                <img src="{{ $agencyLogo }}" alt="{{ $agencyName }}" class="h-16 mx-auto mb-3">
            @else
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl mb-3" style="background:{{ $agencyColor }};">
                    <svg class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                </div>
            @endif
            <h1 class="text-xl font-bold text-slate-800">Document Signing</h1>
            <p class="text-sm text-slate-500 mt-1">{{ $agencyName }}</p>
        </div>

        {{-- Error Messages --}}
        @if(session('error'))
            <div class="rounded-xl border border-red-200 bg-red-50 text-red-700 px-4 py-3 text-sm mb-4">
                {{ session('error') }}
            </div>
        @endif

        {{-- Main Card --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">

            {{-- Invitation --}}
            <div class="text-center mb-5">
                <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-blue-50 mb-3">
                    <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </div>
                <p class="text-sm text-slate-600 leading-relaxed">
                    You have been invited to sign
                </p>
                <p class="text-base font-semibold text-slate-800 mt-1">
                    {{ $documentName }}
                </p>
            </div>

            {{-- Signer Info --}}
            <div class="mb-5 p-3 rounded-xl bg-slate-50 border border-slate-100">
                <div class="flex items-center gap-3">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center text-white text-sm font-bold" style="background:{{ $agencyColor }};">
                        {{ strtoupper(substr($request->signer_name, 0, 1)) }}
                    </div>
                    <div>
                        <div class="text-sm font-medium text-slate-700">{{ $request->signer_name }}</div>
                        <div class="text-xs text-slate-400">Signing as {{ ucfirst(str_replace('_', ' ', $request->party_role)) }}</div>
                    </div>
                </div>
            </div>

            {{-- Sent by --}}
            @if($request->template && $request->template->creator)
                <div class="mb-5 p-3 rounded-xl bg-blue-50 border border-blue-100">
                    <div class="text-xs text-blue-500 uppercase tracking-wider font-semibold mb-1">Sent by</div>
                    <div class="text-sm font-medium text-slate-700">
                        {{ $request->template->creator->name ?? 'Agent' }}
                    </div>
                    @if($request->template->creator->email)
                        <div class="text-xs text-slate-400">{{ $request->template->creator->email }}</div>
                    @endif
                    @if($request->template->creator->phone)
                        <div class="text-xs text-slate-400">{{ $request->template->creator->phone }}</div>
                    @endif
                </div>
            @endif

            {{-- ID Verification Form --}}
            <form action="{{ route('signatures.external.verify', $request->token) }}" method="POST" class="space-y-4">
                @csrf

                <div>
                    <label for="id_number" class="block text-sm font-medium text-slate-700 mb-1">
                        <svg class="inline w-4 h-4 text-slate-400 mr-1 -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2" />
                        </svg>
                        ID / Passport Number
                    </label>
                    <input type="text" id="id_number" name="id_number" value="{{ old('id_number') }}" required
                           class="w-full rounded-lg border-slate-300 text-sm px-3 py-2.5 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="Enter your full ID or passport number"
                           autocomplete="off">
                    @error('id_number')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <p class="text-xs text-slate-400 leading-relaxed">
                    Your identity is verified to protect the security of this document, in compliance with POPIA and FICA regulations.
                </p>

                <button type="submit"
                        class="w-full rounded-lg px-4 py-3 text-sm font-semibold text-white transition-colors"
                        style="background:{{ $agencyColor }};"
                        onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
                    Verify My Identity
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
