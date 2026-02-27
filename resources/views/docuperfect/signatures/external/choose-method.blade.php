<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choose Signing Method — Home Finders Coastal</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-lg">

        {{-- Logo / Header --}}
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl mb-3" style="background:#0b2a4a;">
                <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                </svg>
            </div>
            <h1 class="text-xl font-bold text-slate-800">How would you like to sign?</h1>
            <p class="text-sm text-slate-500 mt-1">Hi {{ $request->signer_name }}, please choose your preferred signing method.</p>
        </div>

        {{-- Document info --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 mb-4">
            <div class="p-3 rounded-xl bg-slate-50 border border-slate-100 mb-5">
                <div class="text-xs text-slate-400 uppercase tracking-wider font-semibold mb-1">Document</div>
                <div class="text-sm font-medium text-slate-700">{{ $document->name }}</div>
                <div class="text-xs text-slate-400 mt-1">
                    Sent by {{ $template->creator->name ?? 'Home Finders Coastal' }}
                </div>
            </div>

            {{-- Two options --}}
            <div class="space-y-3">
                {{-- Electronic signing --}}
                <form action="{{ route('signatures.external.chooseMethod', $token) }}" method="POST">
                    @csrf
                    <input type="hidden" name="method" value="electronic">
                    <button type="submit"
                            class="w-full text-left rounded-xl border-2 border-slate-200 hover:border-cyan-400 hover:bg-cyan-50 transition-all p-4 group">
                        <div class="flex items-start gap-4">
                            <div class="flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center bg-cyan-100 text-cyan-600 group-hover:bg-cyan-200 transition-colors">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-slate-800">Sign Electronically</div>
                                <div class="text-xs text-slate-500 mt-0.5">Sign the document on screen using your device. Quick and convenient.</div>
                            </div>
                        </div>
                    </button>
                </form>

                {{-- Wet ink signing --}}
                <form action="{{ route('signatures.external.chooseMethod', $token) }}" method="POST">
                    @csrf
                    <input type="hidden" name="method" value="wet_ink">
                    <button type="submit"
                            class="w-full text-left rounded-xl border-2 border-slate-200 hover:border-amber-400 hover:bg-amber-50 transition-all p-4 group">
                        <div class="flex items-start gap-4">
                            <div class="flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center bg-amber-100 text-amber-600 group-hover:bg-amber-200 transition-colors">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-slate-800">Download, Sign & Upload</div>
                                <div class="text-xs text-slate-500 mt-0.5">Download the document, print and sign it physically, then upload the signed copy.</div>
                            </div>
                        </div>
                    </button>
                </form>
            </div>
        </div>

        <div class="text-center text-xs text-slate-400">
            Home Finders Coastal &mdash; Document Signing
        </div>
    </div>
</body>
</html>
