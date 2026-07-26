@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-6">
    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-base font-bold leading-tight truncate" style="color: var(--text-primary);">Edit: {{ $provision->documentType?->name ?? 'Document' }}</h1>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
                <a href="{{ route('compliance.agency-settings.index') }}" class="corex-btn-outline text-xs">&larr; Back</a>
                <button type="submit" form="edit-provision-form" class="corex-btn-primary text-xs">Save Changes</button>
            </div>
        </div>
    </div>

    <div>

        @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm mb-5"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            <ul class="list-disc list-inside space-y-1">@foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach</ul>
        </div>
        @endif

        <form id="edit-provision-form" method="POST" action="{{ route('compliance.agency-settings.update', $provision) }}" enctype="multipart/form-data"
              class="max-w-2xl p-6 rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
            @csrf @method('PATCH')

            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Document Type</label>
                    <div class="px-3 py-2 text-sm font-medium rounded-md" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        {{ $provision->documentType?->name ?? 'Unknown' }}
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Policy / Reference</label>
                    <input type="text" name="policy_reference" value="{{ old('policy_reference', $provision->policy_reference) }}" maxlength="200"
                           class="w-full px-3 py-2 text-sm rounded-md" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>

                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Replace Document</label>
                    @if($provision->document_path)
                    <div class="text-[10px] mb-1" style="color: var(--text-muted);">Current: {{ $provision->document_original_name }}</div>
                    @endif
                    <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png"
                           class="w-full text-sm" style="color: var(--text-secondary);">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Effective From <span class="text-red-500">*</span></label>
                        <input type="date" name="effective_from" value="{{ old('effective_from', $provision->effective_from?->toDateString()) }}" required
                               class="w-full px-3 py-2 text-sm rounded-md" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Effective Until</label>
                        <input type="date" name="effective_until" value="{{ old('effective_until', $provision->effective_until?->toDateString()) }}"
                               class="w-full px-3 py-2 text-sm rounded-md" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Notes</label>
                    <textarea name="notes" rows="2" maxlength="2000"
                              class="w-full px-3 py-2 text-sm rounded-md" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">{{ old('notes', $provision->notes) }}</textarea>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
