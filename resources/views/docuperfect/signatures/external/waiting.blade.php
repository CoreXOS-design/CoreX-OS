<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Not Your Turn Yet — Home Finders Coastal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md text-center">

        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-blue-100 mb-4">
            <svg class="w-8 h-8 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
            <h1 class="text-xl font-bold text-slate-800 mb-2">Not Your Turn Yet</h1>

            <p class="text-sm text-slate-500 mb-4">
                This document is being signed in sequence. Other parties must sign before you.
                You will receive an email when it is your turn.
            </p>

            <div class="p-3 rounded-xl bg-slate-50 border border-slate-100 mb-4">
                <div class="text-xs text-slate-400 uppercase tracking-wider font-semibold mb-1">Document</div>
                <div class="text-sm font-medium text-slate-700">{{ $request->template->document->name ?? 'Document' }}</div>
            </div>

            <p class="text-sm text-slate-500">
                Please wait for notification from the agent. You will be emailed when the document is ready for your signature.
            </p>
        </div>

        <div class="text-center mt-4 text-xs text-slate-400">
            Home Finders Coastal &mdash; Document Signing
        </div>
    </div>
</body>
</html>
