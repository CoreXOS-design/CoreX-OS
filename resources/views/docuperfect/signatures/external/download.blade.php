<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Download Signed Document — {{ $template->creator?->agency?->name ?? 'Agency' }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-lg">

        {{-- Logo / Header --}}
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl mb-3" style="background:#0b2a4a;">
                <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
            </div>
            <h1 class="text-xl font-bold text-slate-800">Download Signed Document</h1>
            <p class="text-sm text-slate-500 mt-1">Hi {{ $request->signer_name }}</p>
        </div>

        {{-- Error state --}}
        @if(!empty($error))
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 mb-4">
                <div class="flex items-start gap-3">
                    <div class="flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center bg-red-100 text-red-600">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                        </svg>
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-slate-800">Document Not Available</div>
                        <div class="text-xs text-slate-500 mt-0.5">{{ $error }}</div>
                    </div>
                </div>
            </div>
        @elseif(!empty($verified))
            {{-- Verified — show download button --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 mb-4">
                <div class="p-3 rounded-xl bg-slate-50 border border-slate-100 mb-5">
                    <div class="text-xs text-slate-400 uppercase tracking-wider font-semibold mb-1">Document</div>
                    <div class="text-sm font-medium text-slate-700">{{ $document->name ?? 'Signed Document' }}</div>
                    @if($template->completed_at)
                        <div class="text-xs text-slate-400 mt-1">
                            Completed {{ $template->completed_at->format('d M Y') }}
                        </div>
                    @endif
                </div>

                <div class="flex items-start gap-3 p-3 rounded-xl bg-emerald-50 border border-emerald-100 mb-5">
                    <svg class="w-5 h-5 text-emerald-600 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div class="text-sm text-emerald-700">All parties have signed this document. Your copy is ready for download.</div>
                </div>

                <a href="{{ route('signatures.download.file', $token) }}"
                   class="block w-full text-center rounded-xl text-white font-semibold py-3 px-6 transition-all hover:opacity-90"
                   style="background:#276749;">
                    Download Signed PDF
                </a>
            </div>
        @elseif(!empty($needsVerification))
            {{-- ID verification required --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 mb-4">
                <div class="p-3 rounded-xl bg-slate-50 border border-slate-100 mb-5">
                    <div class="text-xs text-slate-400 uppercase tracking-wider font-semibold mb-1">Document</div>
                    <div class="text-sm font-medium text-slate-700">{{ $document->name ?? 'Signed Document' }}</div>
                </div>

                <p class="text-sm text-slate-600 mb-4">Please enter your ID number to verify your identity before downloading.</p>

                @if(session('error'))
                    <div class="p-3 rounded-xl bg-red-50 border border-red-200 text-sm text-red-700 mb-4">
                        {{ session('error') }}
                    </div>
                @endif

                <form action="{{ route('signatures.download.verify', $token) }}" method="POST">
                    @csrf
                    <div class="mb-4">
                        <label for="id_number" class="block text-sm font-medium text-slate-700 mb-1">ID / Passport Number</label>
                        <input type="text" id="id_number" name="id_number" required
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-400 focus:border-transparent"
                               placeholder="Enter your ID number"
                               value="{{ old('id_number') }}">
                        @error('id_number')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="submit"
                            class="w-full rounded-xl text-white font-semibold py-3 px-6 transition-all hover:opacity-90"
                            style="background:#0b2a4a;">
                        Verify &amp; Download
                    </button>
                </form>
            </div>
        @endif

        <div class="text-center text-xs text-slate-400 mt-4">
            {{ $template->creator?->agency?->name ?? 'Agency' }} &mdash; Document Signing
        </div>
    </div>
</body>
</html>
