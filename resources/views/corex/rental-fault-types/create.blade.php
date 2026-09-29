@extends('layouts.corex')

{{-- .ai/specs/rentals-faults-work-orders.md §2/§8.1 --}}

@section('content')
<div class="p-6 max-w-2xl space-y-4">
    <h1 class="text-lg font-semibold">Add Fault Type</h1>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('corex.rental-fault-types.store') }}" enctype="multipart/form-data" class="space-y-4">
        @csrf
        <div>
            <label class="prop-label">Name</label>
            <input type="text" name="name" value="{{ old('name') }}" required maxlength="191" class="prop-input w-full">
        </div>
        <div>
            <label class="prop-label">Category</label>
            <input type="text" name="category" value="{{ old('category') }}" maxlength="100" placeholder="e.g. Plumbing" class="prop-input w-full">
        </div>
        <div>
            <label class="prop-label">Urgency</label>
            <select name="urgency" required class="prop-select w-full">
                @foreach(['routine' => 'Routine', 'urgent' => 'Urgent', 'emergency' => 'Emergency'] as $val => $label)
                    <option value="{{ $val }}" @selected(old('urgency') === $val)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="prop-label">Tenant first-aid steps</label>
            <textarea name="first_aid_steps" rows="8" class="prop-input w-full" placeholder="What should the tenant do before submitting?">{{ old('first_aid_steps') }}</textarea>
            <p class="text-xs mt-1" style="color: var(--text-muted);">Shown to the tenant BEFORE they can log this fault. Placeholders @{{main_water_valve_location}} and @{{db_board_location}} are replaced with the property's own recorded location.</p>
        </div>
        <div class="flex items-center gap-2">
            <input type="checkbox" id="is_active" name="is_active" value="1" checked class="rounded">
            <label for="is_active" class="prop-label !mb-0">Active (shown to tenants)</label>
        </div>

        <div class="pt-2 border-t" style="border-color: var(--border);">
            <label class="prop-label">Attach a document (optional)</label>
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
            <div class="mt-2">
                <label class="text-xs" style="color: var(--text-muted);">Video link (YouTube, Vimeo, etc.)</label>
                <input type="url" name="video_url" placeholder="https://..." class="prop-input w-full">
            </div>
            <div class="mt-2">
                <label class="text-xs" style="color: var(--text-muted);">Caption</label>
                <input type="text" name="caption" maxlength="255" class="prop-input w-full">
            </div>
        </div>

        <div class="flex gap-2">
            <button type="submit" class="corex-btn-primary text-sm">Save</button>
            <a href="{{ route('corex.rental-fault-types.index') }}" class="corex-btn-outline text-sm">Cancel</a>
        </div>
    </form>
</div>
@endsection
