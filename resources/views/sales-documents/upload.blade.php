<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Return Signed Document — Home Finders Coastal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-lg">

        <div class="text-center mb-6">
            <h1 class="text-xl font-bold text-slate-800">Home Finders Coastal</h1>
            <p class="text-sm text-slate-500 mt-1">Return Signed Document</p>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">

            <div class="p-3 rounded-xl bg-blue-50 border border-blue-100 mb-6">
                <div class="text-xs text-blue-500 uppercase tracking-wider font-semibold mb-1">Document</div>
                <div class="text-sm font-medium text-slate-700">{{ $send->document_name }}</div>
                <div class="text-xs text-slate-500 mt-1">Sent by: {{ $send->sender->name ?? 'Agent' }}</div>
            </div>

            <p class="text-sm text-slate-600 mb-4">
                Hi <strong>{{ $recipient->recipient_name }}</strong>, please follow the steps below to sign and return your document.
            </p>

            {{-- Step 1: Download --}}
            @if($send->original_file_path)
                <div class="mb-6">
                    <h3 class="text-sm font-semibold text-slate-700 mb-2">Step 1: Download the document</h3>
                    <p class="text-xs text-slate-500 mb-3">Download, print, and sign all required sections.</p>
                    <a href="{{ route('sales-documents.download', ['token' => $recipient->token]) }}"
                       class="inline-flex items-center gap-2 bg-blue-600 text-white px-5 py-2.5 rounded-xl text-sm font-semibold hover:bg-blue-700 transition-colors">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        Download PDF
                    </a>
                </div>
                <hr class="border-slate-200 mb-6">
            @endif

            {{-- Step 2: Upload signed copy --}}
            <h3 class="text-sm font-semibold text-slate-700 mb-2">{{ $send->original_file_path ? 'Step 2: Upload your signed copy' : 'Upload your signed document' }}</h3>

            <form action="{{ route('sales-documents.upload.store', ['token' => $recipient->token]) }}" method="POST" enctype="multipart/form-data">
                @csrf

                <div class="border-2 border-dashed border-slate-300 rounded-xl p-6 text-center hover:border-blue-400 transition-colors mb-4">
                    <svg class="w-10 h-10 text-slate-400 mx-auto mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" />
                    </svg>
                    <label class="cursor-pointer">
                        <span class="text-sm text-blue-600 hover:text-blue-800 font-medium">Click to browse</span>
                        <span class="text-sm text-slate-500"> or drag files here</span>
                        <input type="file" name="files[]" multiple accept=".pdf,.jpg,.jpeg,.png" class="hidden" required>
                    </label>
                    <p class="text-xs text-slate-400 mt-2">Accepted: PDF, JPG, PNG (max 20MB each)</p>
                </div>

                <div id="file-list" class="mb-4 space-y-1 text-sm text-slate-600 hidden"></div>

                @if($errors->any())
                    <div class="rounded-xl bg-red-50 border border-red-200 p-3 mb-4 text-sm text-red-700">
                        @foreach($errors->all() as $error)
                            <div>{{ $error }}</div>
                        @endforeach
                    </div>
                @endif

                <button type="submit" class="w-full bg-slate-800 text-white py-3 rounded-xl font-semibold hover:bg-slate-700 transition-colors">
                    Submit Signed Document
                </button>
            </form>

            <div class="mt-6 pt-4 border-t border-slate-100 text-center">
                <p class="text-xs text-slate-500">
                    Or email your signed copy directly to:<br>
                    <a href="mailto:{{ $send->sender->email ?? '' }}" class="text-blue-600 font-medium">{{ $send->sender->email ?? '' }}</a>
                </p>
            </div>
        </div>

        <div class="text-center mt-4 text-xs text-slate-400">
            Home Finders Coastal &mdash; Document Management
        </div>
    </div>

    <script>
        document.querySelector('input[type="file"]').addEventListener('change', function(e) {
            const list = document.getElementById('file-list');
            list.innerHTML = '';
            list.classList.remove('hidden');
            Array.from(e.target.files).forEach(f => {
                const div = document.createElement('div');
                div.textContent = f.name + ' (' + (f.size / 1024 / 1024).toFixed(1) + ' MB)';
                list.appendChild(div);
            });
        });
    </script>
</body>
</html>
