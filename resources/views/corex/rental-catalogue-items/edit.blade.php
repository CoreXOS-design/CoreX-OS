@extends('layouts.corex')

{{-- AT-442 — agency parts & labour catalogue. --}}

@section('content')
<div class="p-6 max-w-2xl space-y-4">
    <h1 class="text-lg font-semibold">Edit Catalogue Item</h1>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('corex.rental-catalogue-items.update', $item) }}" class="space-y-4">
        @csrf
        @method('PUT')
        <div>
            <label class="prop-label">Type</label>
            <select name="type" required class="prop-select w-full">
                <option value="labour" @selected(old('type', $item->type) === 'labour')>Labour</option>
                <option value="part" @selected(old('type', $item->type) === 'part')>Part</option>
            </select>
        </div>
        <div>
            <label class="prop-label">Name</label>
            <input type="text" name="name" value="{{ old('name', $item->name) }}" required maxlength="191" class="prop-input w-full">
        </div>
        <div>
            <label class="prop-label">Unit</label>
            <input type="text" name="unit" value="{{ old('unit', $item->unit) }}" required maxlength="30" class="prop-input w-full">
        </div>
        <div>
            <label class="prop-label">{{ $priceLabel }} (R, optional)</label>
            <input type="number" name="default_price" value="{{ old('default_price', $item->default_price) }}" step="0.01" min="0" class="prop-input w-full">
        </div>
        @if($vatTypes->isNotEmpty())
        <div>
            <label class="prop-label">Default VAT type</label>
            <select name="default_rental_vat_type_id" class="prop-select w-full">
                <option value="">— Agency default —</option>
                @foreach($vatTypes as $vt)
                    <option value="{{ $vt->id }}" @selected((string) old('default_rental_vat_type_id', $item->default_rental_vat_type_id) === (string) $vt->id)>{{ $vt->name }}</option>
                @endforeach
            </select>
            <p class="text-[11px] mt-1" style="color:var(--text-muted);">A job card line that picks this item inherits this VAT type — still editable per line.</p>
        </div>
        @endif
        <div class="flex items-center gap-2">
            <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $item->is_active)) class="rounded">
            <label for="is_active" class="prop-label !mb-0">Active</label>
        </div>
        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ route('corex.rental-catalogue-items.index') }}" class="corex-btn-outline text-xs">Cancel</a>
            <button type="submit" class="corex-btn-primary text-xs">Save changes</button>
        </div>
    </form>
</div>
@endsection
