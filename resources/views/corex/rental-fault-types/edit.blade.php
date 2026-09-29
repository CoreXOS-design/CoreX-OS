@extends('layouts.corex')

{{-- .ai/specs/rentals-faults-work-orders.md §2/§8.1 --}}

@section('content')
<div class="p-6 max-w-2xl space-y-4">
    <h1 class="text-lg font-semibold">Edit Fault Type</h1>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46;">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('corex.rental-fault-types.update', $faultType) }}" class="space-y-4">
        @csrf
        @method('PUT')
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
            <textarea name="first_aid_steps" rows="8" class="prop-input w-full">{{ old('first_aid_steps', $faultType->first_aid_steps) }}</textarea>
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

    <div class="pt-4 border-t space-y-3" style="border-color: var(--border);">
        <h2 class="text-sm font-semibold">Documents</h2>

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
                        <form method="POST" action="{{ route('corex.rental-fault-types.documents.destroy', [$faultType, $doc]) }}" onsubmit="return confirm('Remove this document?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs" style="color: #991b1b;">Remove</button>
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
@endsection
