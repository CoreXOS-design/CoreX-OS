@extends('layouts.corex')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Edit Provision: {{ $typeLabels[$provision->provision_type] ?? $provision->provision_type }}" :back-route="route('compliance.agency-settings.index')" :flush="true">
        <x-slot:actions>
            <button type="submit" form="edit-provision-form" class="px-4 py-2 rounded text-sm font-semibold text-white"
                    style="background:#00d4aa; border-radius:3px;">Save Changes</button>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">

        @if($errors->any())
        <div class="rounded px-4 py-3 text-sm mb-5" style="background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); color:#ef4444; border-radius:3px;">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
        @endif

        <form id="edit-provision-form" method="POST" action="{{ route('compliance.agency-settings.update', $provision) }}" enctype="multipart/form-data"
              class="rounded p-6" style="background:var(--surface); border:1px solid var(--border); border-radius:3px;">
            @csrf @method('PATCH')

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Provision Type</label>
                    <div class="px-3 py-2.5 text-sm rounded" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-muted); border-radius:3px;">
                        {{ $typeLabels[$provision->provision_type] ?? $provision->provision_type }}
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Policy Reference</label>
                    <input type="text" name="policy_reference" value="{{ old('policy_reference', $provision->policy_reference) }}"
                           class="w-full rounded px-3 py-2.5 text-sm outline-none"
                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); border-radius:3px;">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Replace Document</label>
                    @if($provision->document_path)
                    <div class="text-[10px] mb-1" style="color:var(--text-muted);">Current: {{ $provision->document_original_name }}</div>
                    @endif
                    <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png"
                           class="block w-full text-sm rounded px-3 py-2"
                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary); border-radius:3px;">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Effective From <span class="text-red-500">*</span></label>
                    <input type="date" name="effective_from" value="{{ old('effective_from', $provision->effective_from?->toDateString()) }}" required
                           class="w-full rounded px-3 py-2.5 text-sm outline-none"
                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); border-radius:3px;">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Effective Until</label>
                    <input type="date" name="effective_until" value="{{ old('effective_until', $provision->effective_until?->toDateString()) }}"
                           class="w-full rounded px-3 py-2.5 text-sm outline-none"
                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); border-radius:3px;">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Applies to Roles</label>
                    <select name="applies_to_roles[]" multiple class="w-full rounded px-3 py-2.5 text-sm outline-none"
                            style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); border-radius:3px; min-height:80px;">
                        @foreach($roles as $role)
                        <option value="{{ $role->name }}" {{ in_array($role->name, $provision->applies_to_roles ?? []) ? 'selected' : '' }}>{{ $role->label }}</option>
                        @endforeach
                    </select>
                    <p class="text-[10px] mt-1" style="color:var(--text-muted);">Leave empty = all roles</p>
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Applies to Branches</label>
                    <select name="applies_to_branches[]" multiple class="w-full rounded px-3 py-2.5 text-sm outline-none"
                            style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); border-radius:3px; min-height:80px;">
                        @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" {{ in_array((string) $branch->id, $provision->applies_to_branches ?? []) ? 'selected' : '' }}>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    <p class="text-[10px] mt-1" style="color:var(--text-muted);">Leave empty = all branches</p>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Notes</label>
                    <textarea name="notes" rows="2" class="w-full rounded px-3 py-2.5 text-sm outline-none"
                              style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); border-radius:3px;">{{ old('notes', $provision->notes) }}</textarea>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
