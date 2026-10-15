@extends('layouts.corex')

{{--
    AT-442 — agency parts & labour catalogue. Create screen.
    §17.38: the item card + Cost / Markup / Selling pricing block live in _form-fields.blade.php (shared by create and edit).
--}}

@section('content')
<div class="p-6 max-w-3xl space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Add Catalogue Item</h1>
        <a href="{{ route('admin.company-settings') }}#catalogue-item-types" class="text-xs underline" style="color:var(--text-muted);">Manage types &amp; units</a>
    </div>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('corex.rental-catalogue-items.store') }}" class="space-y-4">
        @csrf
        @include('corex.rental-catalogue-items._form-fields')
        <div class="flex justify-end gap-2 cx-equal-btns">
            <a href="{{ route('corex.rental-catalogue-items.index') }}" class="corex-btn-outline text-xs">Cancel</a>
            <button type="submit" class="corex-btn-primary text-xs">Add item</button>
        </div>
    </form>
</div>
@endsection
