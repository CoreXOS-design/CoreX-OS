{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
     Spec: .ai/specs/system-updates.md §7.2 --}}
@extends('layouts.corex')

@section('corex-content')
<form method="POST" action="{{ route('admin.system-updates.store') }}" enctype="multipart/form-data" class="w-full space-y-5">
    @csrf

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">New Update</h1>
                <p class="text-xs" style="color: var(--text-muted);">Save it as a draft first, or publish it straight away.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.system-updates.index') }}" class="corex-btn-outline text-xs">Cancel</a>
            </div>
        </div>
    </div>

    @include('admin.system-updates._form', ['isEdit' => false])

    <div class="flex flex-wrap items-center gap-3">
        <button type="submit" class="corex-btn-outline">Save as draft</button>
        <button type="submit" name="publish_now" value="1" class="corex-btn-primary">Publish now</button>
        <span class="text-xs" style="color:var(--text-secondary);">
            Publishing shows this to every CoreX user the next time they open the system.
        </span>
    </div>
</form>
@endsection
