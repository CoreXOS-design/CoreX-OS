@extends('layouts.corex')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded banner) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">New Pipeline Template</h1>
                <p class="text-sm text-white/60">Create a pipeline, then add the steps deals will follow through each stage.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('deals-v2.pipeline.index') }}" class="corex-btn-outline corex-btn-on-brand inline-flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    Back
                </a>
            </div>
        </div>
    </div>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson);" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
            <div class="flex-1">
                @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
            </div>
        </div>
    @endif

    {{-- Template details form --}}
    <form method="POST" action="{{ route('deals-v2.pipeline.store') }}" class="rounded-md p-5 max-w-3xl" style="border: 1px solid var(--border); background: var(--surface);">
        @csrf

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
            <div class="md:col-span-2">
                <label class="block text-xs font-medium uppercase tracking-wider mb-1" style="color: var(--text-muted);">Template Name</label>
                <input type="text" name="name" required value="{{ old('name') }}" placeholder="e.g. Standard Bond Sale"
                       class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label class="block text-xs font-medium uppercase tracking-wider mb-1" style="color: var(--text-muted);">Deal Type</label>
                <select name="deal_type" required class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                        style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="bond" {{ old('deal_type') === 'bond' ? 'selected' : '' }}>Bond Sale</option>
                    <option value="cash" {{ old('deal_type') === 'cash' ? 'selected' : '' }}>Cash Sale</option>
                    <option value="sale_of_2nd" {{ old('deal_type') === 'sale_of_2nd' ? 'selected' : '' }}>Sale of 2nd Property</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium uppercase tracking-wider mb-1" style="color: var(--text-muted);">Branch</label>
                <select name="branch_id" class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                        style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">All Branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" {{ old('branch_id') == $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-center gap-3 md:col-span-2">
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="hidden" name="is_default" value="0">
                    <input type="checkbox" name="is_default" value="1" {{ old('is_default') ? 'checked' : '' }}
                           class="rounded" style="accent-color: var(--brand-button, #0ea5e9);">
                    <span class="text-sm" style="color: var(--text-secondary);">Set as default template for this deal type</span>
                </label>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary inline-flex items-center gap-2">
                Create Template
            </button>
        </div>
    </form>
</div>
@endsection
