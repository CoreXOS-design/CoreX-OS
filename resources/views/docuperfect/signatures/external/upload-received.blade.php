<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Upload Received — Home Finders Coastal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md text-center">

        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-blue-100 mb-4">
            <svg class="w-8 h-8 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
            </svg>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
            <h1 class="text-xl font-bold text-slate-800 mb-2">Upload Received</h1>

            <p class="text-sm text-slate-500 mb-4">
                Your signed document has been uploaded successfully and is now awaiting review.
            </p>

            <div class="p-3 rounded-xl bg-blue-50 border border-blue-100 mb-4">
                <div class="text-xs text-blue-500 uppercase tracking-wider font-semibold mb-1">Document</div>
                <div class="text-sm font-medium text-slate-700">{{ $request->template->document->name ?? 'Document' }}</div>
            </div>

            <div class="p-4 rounded-xl bg-amber-50 border border-amber-200 mb-4 text-left">
                <div class="flex items-start gap-2">
                    <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div>
                        <p class="text-sm font-medium text-amber-800">What happens next?</p>
                        <p class="text-xs text-amber-700 mt-1">
                            The agent will review your uploaded document to confirm all signatures are present. You will be notified by email if there are any issues.
                        </p>
                    </div>
                </div>
            </div>

            <p class="text-xs text-slate-400">
                Uploaded by {{ $request->signer_name }} on {{ now()->format('d M Y \a\t H:i') }}
            </p>
        </div>

        <div class="text-center mt-4 text-xs text-slate-400">
            Home Finders Coastal &mdash; Document Signing
        </div>
    </div>
</body>
</html>
