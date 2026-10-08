{{--
    §49 — Johan, 8 Oct 2026: a tenant or landlord can take a PDF of the inspection report ONLY once every party has signed
    (and it stays available after). Until then the report is for reading on screen. A refused download lands here — a plain
    explanation, never a blank page or an error.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PDF Not Ready</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md text-center">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6" data-qa="download-not-ready">
            <h1 class="text-xl font-bold text-slate-800 mb-2">The PDF isn't ready yet</h1>
            <p class="text-sm text-slate-500">You can read the whole report on screen. The PDF copy becomes available once everyone has signed the report, and stays available after that.</p>
            <button type="button" onclick="history.back()" class="mt-4 text-sm font-semibold px-4 py-2 rounded-md bg-slate-100 text-slate-700 border border-slate-200">Back to the report</button>
        </div>
    </div>
</body>
</html>
