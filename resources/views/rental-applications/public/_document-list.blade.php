{{--
    AT-392, Johan 2026-09-07 — full CRUD for the applicant's own documents:
    view (open what they uploaded), replace (swap for a corrected file),
    remove (archive — Document::delete() is a soft delete, never
    destructive). Shared by show.blade.php and already-submitted.blade.php
    so the two never drift.
--}}
@if($application->documents->isNotEmpty())
<ul class="text-sm text-slate-600 mb-3 space-y-2">
    @foreach($application->documents as $doc)
        <li class="flex items-center justify-between gap-2 border border-slate-100 rounded-lg px-3 py-2">
            <a href="{{ route('rental-applications.public.documents.view', [$application->token, $doc->id]) }}"
               target="_blank" rel="noopener" class="text-slate-700 hover:underline">
                ✓ {{ $doc->original_name }}
            </a>
            <div class="flex items-center gap-2 flex-shrink-0">
                <form method="POST" action="{{ route('rental-applications.public.documents.replace', [$application->token, $doc->id]) }}"
                      enctype="multipart/form-data">
                    @csrf
                    <label class="text-xs font-medium text-slate-500 hover:text-slate-700 cursor-pointer">
                        Replace
                        <input type="file" name="replacement_file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                               class="hidden" onchange="this.form.submit()">
                    </label>
                </form>
                <form method="POST" action="{{ route('rental-applications.public.documents.remove', [$application->token, $doc->id]) }}"
                      onsubmit="return confirm('Remove {{ addslashes($doc->original_name) }}?');">
                    @csrf
                    <button type="submit" class="text-xs font-medium text-red-500 hover:text-red-700">Remove</button>
                </form>
            </div>
        </li>
    @endforeach
</ul>
@endif

@error('replacement_file')
    <p class="text-xs text-red-600 mb-2">{{ $message }}</p>
@enderror
