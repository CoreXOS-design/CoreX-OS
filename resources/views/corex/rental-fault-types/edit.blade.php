@extends('layouts.corex')

{{-- .ai/specs/rentals-faults-work-orders.md §2/§8.1 --}}

@section('corex-content')
<div class="w-full space-y-5">
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Edit Fault Type</h1>
                <p class="text-xs" style="color: var(--text-muted);">{{ $faultType->name }}</p>
            </div>
        </div>
    </div>

    <div class="max-w-2xl space-y-5">

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-red, #dc2626) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-red, #dc2626) 30%, transparent); color: var(--text-primary);">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('corex.rental-fault-types.update', $faultType) }}" class="rounded-md px-5 pb-5 pt-1 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf
        @method('PUT')
        <div class="text-sm font-semibold" style="color: var(--text-primary);">Details</div>
        <div>
            <label class="prop-label">Name</label>
            <input type="text" name="name" value="{{ old('name', $faultType->name) }}" required maxlength="191" class="prop-input w-full">
        </div>
        <div>
            <label class="prop-label">Category</label>
            <input type="text" name="category" value="{{ old('category', $faultType->category) }}" maxlength="100" class="prop-input w-full">
        </div>
        <div>
            <label class="prop-label">Urgency</label>
            <select name="urgency" required class="prop-select w-full">
                @foreach(['routine' => 'Routine', 'urgent' => 'Urgent', 'emergency' => 'Emergency'] as $val => $label)
                    <option value="{{ $val }}" @selected(old('urgency', $faultType->urgency) === $val)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="prop-label">Tenant first-aid steps</label>
            <textarea name="first_aid_steps" rows="8" class="prop-textarea">{{ old('first_aid_steps', $faultType->first_aid_steps) }}</textarea>
            <p class="text-xs mt-1" style="color: var(--text-muted);">Placeholders @{{main_water_valve_location}} and @{{db_board_location}} are replaced with the property's own recorded location.</p>
        </div>
        <div class="flex items-center gap-2">
            <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $faultType->is_active)) class="rounded">
            <label for="is_active" class="prop-label !mb-0">Active (shown to tenants)</label>
        </div>

        <div class="flex gap-2">
            <button type="submit" class="corex-btn-primary text-sm">Save</button>
            <a href="{{ route('corex.rental-fault-types.index') }}" class="corex-btn-outline text-sm">Cancel</a>
        </div>
    </form>

    @include('corex.rental-fault-types._causes-panel')

    <div class="rounded-md p-5 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Documents</h2>

        <ul class="text-sm space-y-1">
            @forelse($faultType->documents as $doc)
                <li class="flex items-center justify-between rounded-md px-3 py-2" style="background: var(--surface-2);">
                    <span>
                        <span class="ds-badge ds-badge-muted">{{ ucfirst(str_replace('_', ' ', $doc->document_type)) }}</span>
                        {{ $doc->caption ?? ($doc->external_url ?? basename((string) $doc->storage_path)) }}
                    </span>
                    <span class="flex items-center gap-2">
                        @if($doc->storage_path)
                            <a href="{{ route('corex.rental-fault-types.documents.download', [$faultType, $doc]) }}" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">Download</a>
                        @elseif($doc->external_url)
                            <a href="{{ $doc->external_url }}" target="_blank" rel="noopener" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">Open link</a>
                        @endif
                        <form method="POST" action="{{ route('corex.rental-fault-types.documents.destroy', [$faultType, $doc]) }}" data-confirm="Remove this document?" data-confirm-danger data-confirm-label="Remove">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs" style="color: var(--ds-red, #dc2626);">Remove</button>
                        </form>
                    </span>
                </li>
            @empty
                <li class="text-xs" style="color: var(--text-muted);">No documents attached yet.</li>
            @endforelse
        </ul>

        <form method="POST" action="{{ route('corex.rental-fault-types.documents.store', $faultType) }}" enctype="multipart/form-data" class="space-y-2">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="text-xs" style="color: var(--text-muted);">Image</label>
                    <input type="file" name="image" accept="image/*" class="text-sm">
                </div>
                <div>
                    <label class="text-xs" style="color: var(--text-muted);">PDF / document</label>
                    <input type="file" name="document_file" accept=".pdf,.doc,.docx" class="text-sm">
                </div>
            </div>
            <input type="url" name="video_url" placeholder="Or a video link (https://...)" class="prop-input w-full">
            <input type="text" name="caption" maxlength="255" placeholder="Caption (optional)" class="prop-input w-full">
            <button type="submit" class="corex-btn-outline text-sm">Add document</button>
        </form>
    </div>
    </div>
</div>
@endsection
