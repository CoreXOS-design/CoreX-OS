@extends('layouts.corex')

{{--
    AT-442 — agency parts & labour catalogue. Create screen.
    §17.38: the item card + Cost / Markup / Selling pricing block live in _form-fields.blade.php (shared by create and edit).
--}}

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Add Catalogue Item</h1>
                <p class="text-xs" style="color: var(--text-muted);">A labour or part your maintenance team can pick when pricing a job card.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('admin.company-settings') }}#catalogue-item-types" class="corex-btn-outline text-xs">Manage types &amp; units</a>
            </div>
        </div>
    </div>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm max-w-3xl" style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">{{ $errors->first() }}</div>
    @endif

    {{-- The item card and the pricing block (each its own card) come from the shared partial (spec §17.38). --}}
    <form method="POST" action="{{ route('corex.rental-catalogue-items.store') }}" class="space-y-5 max-w-3xl">
        @csrf
        @include('corex.rental-catalogue-items._form-fields')
        <div class="flex justify-end gap-2 pt-4 cx-equal-btns" style="border-top: 1px solid var(--border);">
            <a href="{{ route('corex.rental-catalogue-items.index') }}" class="corex-btn-outline text-sm">Cancel</a>
            <button type="submit" class="corex-btn-primary text-sm">Add item</button>
        </div>
    </form>
</div>
@endsection
