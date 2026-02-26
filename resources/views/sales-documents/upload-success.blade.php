<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Document Received — Home Finders Coastal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md text-center">

        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-100 mb-4">
            <svg class="w-8 h-8 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
            <h1 class="text-xl font-bold text-slate-800 mb-2">Thank You!</h1>

            <p class="text-sm text-slate-500 mb-4">
                Your signed document has been received successfully.
            </p>

            <div class="p-3 rounded-xl bg-emerald-50 border border-emerald-100 mb-4">
                <div class="text-xs text-emerald-500 uppercase tracking-wider font-semibold mb-1">Document</div>
                <div class="text-sm font-medium text-slate-700">{{ $send->document_name }}</div>
            </div>

            <p class="text-sm text-slate-500">
                {{ $send->sender->name ?? 'Your agent' }} has been notified and will review your submission.
            </p>

            <div class="text-xs text-slate-400 mt-4">
                Received from <strong>{{ $recipient->recipient_name }}</strong>
                on {{ now()->format('d M Y \a\t H:i') }}
            </div>
        </div>

        <div class="text-center mt-4 text-xs text-slate-400">
            Home Finders Coastal &mdash; Document Management
        </div>
    </div>
</body>
</html>
