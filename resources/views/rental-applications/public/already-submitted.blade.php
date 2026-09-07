<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Application Received</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 min-h-screen p-4">
<div class="w-full max-w-md mx-auto">

    @if(session('success'))
        <div class="p-3 rounded-xl bg-emerald-50 border border-emerald-100 text-emerald-700 text-sm mb-4">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="p-3 rounded-xl bg-red-50 border border-red-100 text-red-700 text-sm mb-4">{{ session('error') }}</div>
    @endif

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 text-center">
        <h1 class="text-xl font-bold text-slate-800 mb-2">Application already received</h1>
        <p class="text-sm text-slate-500">
            Your rental application is already being processed
            @if($application->submitted_at)
                (submitted {{ $application->submitted_at->format('d M Y') }}).
            @else
                .
            @endif
        </p>
        <p class="text-sm text-slate-500 mt-2">Please contact your agent if you need to change anything.</p>
    </div>

    {{--
        AT-392, Johan 2026-09-07 — spec §5: supporting documents are
        uploadable "both BEFORE signing... and AFTER signing" (matching the
        e-sign precedent this feature is modelled on). uploadDocuments()
        never blocked on status — only this VIEW never offered the form
        once submitted, silently cutting off exactly the post-submission
        upload path the backend already supported.
    --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 mt-4 text-left">
        <h2 class="font-semibold text-slate-700 mb-2">Supporting Documents</h2>
        <p class="text-xs text-slate-500 mb-3">Upload payslips, bank statements, ID or proof of residence — whatever you have.</p>

        @include('rental-applications.public._document-list', ['application' => $application])

        @error('supporting_files')
            <p class="text-xs text-red-600 mb-2">{{ $message }}</p>
        @enderror
        @error('supporting_files.*')
            <p class="text-xs text-red-600 mb-2">{{ $message }}</p>
        @enderror

        <form method="POST" action="{{ route('rental-applications.public.documents', $application->token) }}" enctype="multipart/form-data">
            @csrf
            <input type="file" name="supporting_files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                   class="block w-full text-sm text-slate-600 mb-3">
            <button type="submit" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700">
                Upload
            </button>
        </form>
    </div>

</div>
</body>
</html>
