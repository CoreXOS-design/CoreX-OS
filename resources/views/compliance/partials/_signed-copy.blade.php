{{--
    Signed copy + compliance-officer sign-off, shared by the RMCP and policy view pages (compliance.md).
    Expects: $version, $signed (SignedCopyService::describe or null), $canManage, $canAttach, $downloadUrl, $storeUrl.
    No signed copy and nobody who can add one = nothing is printed.
--}}
@if($signed || ($canManage && $canAttach))
<div id="signed-copy" class="mb-4 rounded-md px-4 py-3 text-sm"
     style="background: {{ $signed ? 'color-mix(in srgb, var(--ds-green, #059669) 10%, transparent)' : 'var(--surface-2)' }}; border: 1px solid {{ $signed ? 'color-mix(in srgb, var(--ds-green, #059669) 35%, transparent)' : 'var(--border)' }}; color: var(--text-primary);">
    @if($signed)
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="font-semibold" data-signed-copy-line>{{ $signed['line'] }}</div>
            <a href="{{ $downloadUrl }}" class="corex-btn-outline text-xs">Download signed copy</a>
        </div>
        @if($signed['source'] === 'signed_copy' && $signed['uploaded_by'])
            <div class="text-xs mt-1" style="color: var(--text-muted);">Uploaded by {{ $signed['uploaded_by'] }}{{ $signed['uploaded_at'] ? ' on ' . $signed['uploaded_at']->format('j F Y') : '' }}.</div>
        @endif
    @else
        <div class="font-semibold" style="color: var(--text-secondary);">No signed copy on the system yet.</div>
    @endif

    @if($canManage && $canAttach)
    <details class="mt-2" @if($errors->has('signed_copy') || $errors->has('signed_on') || $errors->has('submitted_to_fic_on') || $errors->has('signed_by_name')) open @endif>
        <summary class="text-xs font-semibold cursor-pointer" style="color: var(--brand-icon);">{{ $signed ? 'Replace the signed copy' : 'Add the signed copy' }}</summary>
        <form method="POST" action="{{ $storeUrl }}" enctype="multipart/form-data" class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
            @csrf
            <div class="md:col-span-2">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Signed PDF <span class="text-red-500">*</span></label>
                <input type="file" name="signed_copy" accept="application/pdf,.pdf" required class="text-xs">
                @error('signed_copy') <p class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Signed by (compliance officer)</label>
                <input type="text" name="signed_by_name" maxlength="190" value="{{ old('signed_by_name', $version->signed_by_name) }}" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                @error('signed_by_name') <p class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Signed on</label>
                <input type="date" name="signed_on" max="{{ now()->toDateString() }}" value="{{ old('signed_on', $version->signed_on?->toDateString()) }}" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                @error('signed_on') <p class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Submitted to the FIC on (if it was)</label>
                <input type="date" name="submitted_to_fic_on" max="{{ now()->toDateString() }}" value="{{ old('submitted_to_fic_on', $version->submitted_to_fic_on?->toDateString()) }}" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                @error('submitted_to_fic_on') <p class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-end">
                <button type="submit" class="corex-btn-primary text-xs">{{ $signed ? 'Replace signed copy' : 'Save signed copy' }}</button>
            </div>
            <p class="md:col-span-2 text-xs" style="color: var(--text-muted);">This only keeps the file on the system. It does not approve anything and staff are not asked to sign again.</p>
        </form>
    </details>
    @endif
</div>
@endif
