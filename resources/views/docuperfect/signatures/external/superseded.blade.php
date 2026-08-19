<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Document Updated — {{ $agencyName ?? 'Agency' }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-lg">

        {{-- Logo / Header --}}
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl mb-3" style="background:#0b2a4a;">
                <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
            </div>
            <h1 class="text-xl font-bold text-slate-800">Document Updated</h1>
        </div>

        {{-- Message --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 mb-4">
            <div class="flex items-start gap-3 mb-5">
                <div class="flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center bg-cyan-100 text-cyan-600">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div>
                    <div class="text-sm font-semibold text-slate-800">This document has been updated</div>
                    <div class="text-xs text-slate-500 mt-1">
                        A revised version of this document has been created. This signing link is no longer active.
                    </div>
                </div>
            </div>

            <div class="p-3 rounded-xl bg-slate-50 border border-slate-100 mb-5">
                <div class="text-xs text-slate-400 uppercase tracking-wider font-semibold mb-1">Document</div>
                <div class="text-sm font-medium text-slate-700">{{ $documentName }}</div>
            </div>

            <div class="p-3 rounded-xl bg-cyan-50 border border-cyan-100">
                <div class="text-sm text-cyan-800">
                    A new signing link will be sent to you shortly. Please check your email for the updated document.
                </div>
            </div>
        </div>

        <div class="text-center text-xs text-slate-400 mt-4">
            {{ $agencyName ?? 'Agency' }} &mdash; Document Signing
        </div>
    </div>
</body>
</html>
