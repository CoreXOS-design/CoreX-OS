{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md - Edit a single agent's details (owner-only). --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Edit {{ $signup->agency?->name }}</h1>
                <p class="text-xs" style="color: var(--text-muted);">The agency name is the one printed on their documents.</p>
            </div>
            <a href="{{ route('admin.solo-agents.show', $signup->id) }}" class="corex-btn-outline text-xs">Back</a>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.solo-agents.update', $signup->id) }}" class="rounded-md p-5 space-y-5" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf @method('PUT')
        @include('admin.solo-agents._fields', ['signup' => $signup])
        <div class="flex items-center gap-2">
            <button class="corex-btn-primary text-sm">Save</button>
            <a href="{{ route('admin.solo-agents.show', $signup->id) }}" class="corex-btn-outline text-sm">Cancel</a>
        </div>
    </form>
</div>
@endsection
