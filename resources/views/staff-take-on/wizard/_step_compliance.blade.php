{{-- Step 7: Compliance Documents --}}
<form method="POST" action="{{ route('staff-take-on.save-step', [$takeOn, 'compliance']) }}">
    @csrf
    @method('PATCH')

    <div class="space-y-4">
        <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:3px;">
            <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">7. Compliance Documents</h4>
            <p class="text-xs mb-4" style="color:var(--text-secondary, #6b7280);">Upload required documents. Each is filed to the employee's document profile automatically.</p>

            @foreach([
                'id_copy' => ['ID Copy', true],
                'ffc_certificate' => ['FFC Certificate', false],
                'qualification' => ['Qualifications', false],
                'other' => ['Signed Employment Contract / Other', true],
            ] as $docType => [$label, $required])
                <div class="mb-4 p-3" style="border:1px solid var(--border, #e5e7eb); border-radius:3px;">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-semibold" style="color:var(--text-primary, #0f172a);">{{ $label }} {{ $required ? '*' : '(optional)' }}</span>
                        @php
                            $existing = ($uploadedDocs ?? collect())->where('document_type', $docType);
                        @endphp
                        @if($existing->count() > 0)
                            <span class="text-[10px] font-semibold" style="color:#00d4aa;">Uploaded ({{ $existing->count() }})</span>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('staff-take-on.upload-document', $takeOn) }}" enctype="multipart/form-data" class="flex items-end gap-2">
                        @csrf
                        <input type="hidden" name="document_type" value="{{ $docType }}">
                        <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png" class="text-xs" style="color:var(--text-secondary, #6b7280);">
                        <button type="submit" class="px-3 py-1.5 text-xs font-semibold text-white" style="background:#00d4aa; border-radius:3px;">Upload</button>
                    </form>
                    @if($existing->count() > 0)
                        <div class="mt-2 space-y-1">
                            @foreach($existing as $doc)
                                <p class="text-[10px]" style="color:var(--text-secondary, #94a3b8);">{{ $doc->file_name }} ({{ $doc->created_at?->format('d M Y') }})</p>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <input type="hidden" name="contract_uploaded" value="{{ ($uploadedDocs ?? collect())->where('document_type', 'other')->count() > 0 ? '1' : '0' }}">
        <button type="submit" class="px-4 py-2 text-sm font-semibold text-white transition" style="background:#00d4aa; border-radius:3px;">Save & Continue</button>
    </div>
</form>
