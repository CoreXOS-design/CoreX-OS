<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Already Signed — Home Finders Coastal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md text-center">

        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-100 mb-4">
            <svg class="w-8 h-8 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
            <h1 class="text-xl font-bold text-slate-800 mb-2">Already Signed</h1>

            <p class="text-sm text-slate-500 mb-4">
                You have already completed signing this document.
            </p>

            <div class="p-3 rounded-xl bg-emerald-50 border border-emerald-100 mb-4">
                <div class="text-xs text-emerald-500 uppercase tracking-wider font-semibold mb-1">Document</div>
                <div class="text-sm font-medium text-slate-700">{{ $request->template->document->name ?? 'Document' }}</div>
                @if($request->completed_at)
                <div class="text-xs text-slate-400 mt-1">
                    Signed on {{ $request->completed_at->format('d M Y \a\t H:i') }}
                </div>
                @endif
            </div>

            <p class="text-sm text-slate-500">
                No further action is needed from you. You will receive a copy of the fully signed document once all parties have signed.
            </p>
        </div>

        <div class="text-center mt-4 text-xs text-slate-400">
            Home Finders Coastal &mdash; Document Signing
        </div>
    </div>
</body>
</html>
