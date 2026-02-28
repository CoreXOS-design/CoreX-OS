<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify Your Identity — Home Finders Coastal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md">

        {{-- Logo / Header --}}
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl mb-3" style="background:#0b2a4a;">
                <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                </svg>
            </div>
            <h1 class="text-xl font-bold text-slate-800">Verify Your Identity</h1>
            <p class="text-sm text-slate-500 mt-1">Hi {{ $recipient->recipient_name }}, please enter your ID number to access the document.</p>
        </div>

        {{-- Error --}}
        @if(session('error'))
            <div class="rounded-xl border border-red-200 bg-red-50 text-red-700 px-4 py-3 text-sm mb-4">
                {{ session('error') }}
            </div>
        @endif

        {{-- Form --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">

            <div class="mb-5 p-3 rounded-xl bg-slate-50 border border-slate-100">
                <div class="text-xs text-slate-400 uppercase tracking-wider font-semibold mb-1">Document</div>
                <div class="text-sm font-medium text-slate-700">{{ $send->document_name }}</div>
                <div class="text-xs text-slate-500 mt-1">Sent by: {{ $send->sender->name ?? 'Agent' }}</div>
            </div>

            <form action="{{ route('sales-documents.verify', ['token' => $recipient->token]) }}" method="POST" class="space-y-4">
                @csrf

                <div>
                    <label for="id_number" class="block text-sm font-medium text-slate-700 mb-1">ID / Passport Number</label>
                    <input type="text" id="id_number" name="id_number" value="{{ old('id_number') }}" required
                           class="w-full rounded-lg border-slate-300 text-sm px-3 py-2.5 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="Your ID or passport number"
                           autocomplete="off">
                    @error('id_number')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <p class="text-xs text-slate-400 leading-relaxed">
                    This verification protects the security of your document in compliance with POPIA regulations.
                </p>

                <button type="submit"
                        class="w-full rounded-lg px-4 py-3 text-sm font-semibold text-white transition-colors"
                        style="background:#0b2a4a;"
                        onmouseover="this.style.background='#163d63'" onmouseout="this.style.background='#0b2a4a'">
                    Verify & Continue
                </button>
            </form>
        </div>

        <div class="text-center mt-4 text-xs text-slate-400">
            Home Finders Coastal &mdash; Document Management
        </div>
    </div>
</body>
</html>
