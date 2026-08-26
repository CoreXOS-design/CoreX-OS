{{--
    Optional recipient supporting-document upload.
    Self-contained (no Alpine dependency) so it works on BOTH the sign-or-download
    screen and the post-signing already-completed screen. Requires: $request (SignatureRequest).
    Signing is NEVER gated on this — the copy makes that explicit.
--}}
@php
    $supportingDocs = \App\Models\Docuperfect\SignedDocumentVersion::where('signature_request_id', $request->id)
        ->where('kind', \App\Models\Docuperfect\SignedDocumentVersion::KIND_SUPPORTING)
        ->orderByDesc('id')
        ->get();
@endphp

<div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 text-left">
    <div class="flex items-center justify-between mb-2 gap-3">
        <h2 class="text-base font-semibold text-slate-800">Upload additional documents</h2>
        <span class="shrink-0 text-xs font-semibold px-2 py-1 rounded-full bg-slate-100 text-slate-500 uppercase tracking-wider">Optional</span>
    </div>

    <div class="flex items-start gap-2 p-3 rounded-lg bg-blue-50 border border-blue-100 mb-4">
        <svg class="w-4 h-4 text-blue-500 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <p class="text-xs text-blue-800 leading-relaxed">
            <strong>You are not required to upload anything to sign.</strong>
            Signing is not held up by this. If you have supporting documents (for example an ID copy or
            proof of address), you can add them here &mdash; before you sign, or come back to this same
            link and add them after you&rsquo;ve signed.
        </p>
    </div>

    @if(session('supporting_success'))
        <div class="p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-sm text-emerald-800 mb-4">
            {{ session('supporting_success') }}
        </div>
    @endif

    @error('supporting_files')
        <div class="p-3 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700 mb-4">{{ $message }}</div>
    @enderror
    @error('supporting_files.*')
        <div class="p-3 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700 mb-4">{{ $message }}</div>
    @enderror

    @if($supportingDocs->isNotEmpty())
        <div class="mb-4">
            <div class="text-xs text-slate-400 uppercase tracking-wider font-semibold mb-2">Already uploaded</div>
            <ul class="space-y-1">
                @foreach($supportingDocs as $doc)
                    <li class="flex items-center gap-2 text-sm text-slate-600">
                        <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span class="truncate">{{ strtoupper($doc->file_type) }} document</span>
                        <span class="text-xs text-slate-400">&middot; {{ $doc->uploaded_at?->format('d M Y, H:i') }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('signatures.external.supportingUpload', $request->token) }}" enctype="multipart/form-data" class="space-y-3">
        @csrf
        <input type="file" name="supporting_files[]" multiple
               accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
               class="block w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200 cursor-pointer" />
        <p class="text-xs text-slate-400">PDF, JPG, PNG, DOC or DOCX &middot; up to 15&nbsp;MB each &middot; up to 10 files.</p>
        <button type="submit"
                class="inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold text-white bg-slate-700 hover:bg-slate-800 transition-colors">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
            </svg>
            Upload documents
        </button>
    </form>
</div>
