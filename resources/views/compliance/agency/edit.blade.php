@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Edit: {{ $provision->documentType?->name ?? 'Document' }}" :back-route="route('compliance.agency-settings.index')" :flush="true">
        <x-slot:actions>
            <button type="submit" form="edit-provision-form" class="px-4 py-2 text-sm font-semibold text-white"
                    style="background:var(--brand-icon); border-radius:6px;">Save Changes</button>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">

        @if($errors->any())
        <div class="px-4 py-3 text-sm mb-5" style="background:color-mix(in srgb, var(--ds-crimson) 8%, transparent); border:1px solid rgba(239,68,68,0.25); color:var(--ds-crimson); border-radius:6px;">
            <ul class="list-disc list-inside space-y-1">@foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach</ul>
        </div>
        @endif

        <form id="edit-provision-form" method="POST" action="{{ route('compliance.agency-settings.update', $provision) }}" enctype="multipart/form-data"
              class="max-w-2xl p-6" style="background:var(--surface, #fff); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
            @csrf @method('PATCH')

            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Document Type</label>
                    <div class="px-3 py-2 text-sm font-medium" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, var(--text-primary)); border-radius:6px;">
                        {{ $provision->documentType?->name ?? 'Unknown' }}
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Policy / Reference</label>
                    <input type="text" name="policy_reference" value="{{ old('policy_reference', $provision->policy_reference) }}" maxlength="200"
                           class="w-full px-3 py-2 text-sm" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, var(--text-primary)); border-radius:6px;">
                </div>

                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Replace Document</label>
                    @if($provision->document_path)
                    <div class="text-[10px] mb-1" style="color:var(--text-secondary, #94a3b8);">Current: {{ $provision->document_original_name }}</div>
                    @endif
                    <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png"
                           class="w-full text-sm" style="color:var(--text-secondary, #6b7280);">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Effective From <span class="text-red-500">*</span></label>
                        <input type="date" name="effective_from" value="{{ old('effective_from', $provision->effective_from?->toDateString()) }}" required
                               class="w-full px-3 py-2 text-sm" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, var(--text-primary)); border-radius:6px;">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Effective Until</label>
                        <input type="date" name="effective_until" value="{{ old('effective_until', $provision->effective_until?->toDateString()) }}"
                               class="w-full px-3 py-2 text-sm" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, var(--text-primary)); border-radius:6px;">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Notes</label>
                    <textarea name="notes" rows="2" maxlength="2000"
                              class="w-full px-3 py-2 text-sm" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, var(--text-primary)); border-radius:6px;">{{ old('notes', $provision->notes) }}</textarea>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
