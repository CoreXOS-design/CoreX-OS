{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded, full width) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Document Types</h1>
                <p class="text-sm text-white/60">Manage categories for document templates.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('docuperfect.settings.namedFields') }}" class="corex-btn-outline text-sm"
                   style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">
                    Named Fields
                </a>
                <a href="{{ route('docuperfect.dashboard') }}" class="corex-btn-outline text-sm"
                   style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">
                    Back
                </a>
            </div>
        </div>
    </div>

    {{-- Flash + validation messages (most success/error feedback is also surfaced
         by the global toast system; these page-level alerts are the fallback). --}}
    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif

    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);
                    color: var(--text-primary);">
            {{ session('error') }}
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);
                    color: var(--text-primary);">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Add --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h3 class="ds-section-header mb-3">Add document type</h3>

        <form method="POST" action="{{ route('docuperfect.settings.types.store') }}" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
            @csrf

            <div class="md:col-span-7">
                <label for="dt-name" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Name</label>
                <input id="dt-name" name="name" required
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                       placeholder="e.g. Mandates">
            </div>

            <div class="md:col-span-3">
                <label for="dt-sort" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Sort order</label>
                <input id="dt-sort" name="sort_order" type="number" step="1" min="0"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                       placeholder="0">
            </div>

            <div class="md:col-span-2">
                <button class="w-full corex-btn-primary text-sm">Add</button>
            </div>
        </form>
    </div>

    {{-- List --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
            <div class="text-sm font-semibold" style="color: var(--text-primary);">Current types</div>
            <div class="text-xs" style="color: var(--text-muted);">{{ number_format(count($types)) }} total</div>
        </div>

        <div>
            @forelse($types as $type)
                <div class="p-4" style="border-top: 1px solid var(--border);">
                    <form method="POST" action="{{ route('docuperfect.settings.types.update', $type->id) }}" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                        @csrf
                        @method('PUT')

                        <div class="md:col-span-6">
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Name</label>
                            <input name="name" value="{{ $type->name }}" required
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Sort order</label>
                            <input name="sort_order" type="number" step="1" min="0" value="{{ (int)$type->sort_order }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>

                        <div class="md:col-span-2">
                            <button class="w-full corex-btn-outline text-sm">Save</button>
                        </div>

                        <div class="md:col-span-2 flex items-end">
                            <span class="text-xs" style="color: var(--text-muted);">{{ number_format($type->templates()->count()) }} template{{ $type->templates()->count() !== 1 ? 's' : '' }}</span>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('docuperfect.settings.types.destroy', $type->id) }}"
                          onsubmit="return confirm('Delete this document type? This cannot be undone.');"
                          class="mt-2">
                        @csrf
                        @method('DELETE')
                        <button class="text-xs font-semibold transition-colors"
                                style="color: var(--ds-crimson, #c41e3a);"
                                {{ $type->templates()->count() > 0 ? 'disabled title=Cannot delete — templates assigned' : '' }}>
                            Delete
                        </button>
                    </form>
                </div>
            @empty
                <div class="py-12 px-6 text-center" style="border-top: 1px solid var(--border);">
                    <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                         style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 0 0-1.883 2.542l.857 6a2.25 2.25 0 0 0 2.227 1.932H19.05a2.25 2.25 0 0 0 2.227-1.932l.857-6a2.25 2.25 0 0 0-1.883-2.542m-16.5 0V6A2.25 2.25 0 0 1 6 3.75h3.879a1.5 1.5 0 0 1 1.06.44l2.122 2.12a1.5 1.5 0 0 0 1.06.44H18A2.25 2.25 0 0 1 20.25 9v.776" />
                        </svg>
                    </div>
                    <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No document types yet</h3>
                    <p class="text-sm mb-4" style="color: var(--text-muted);">Add your first document type to start grouping templates.</p>
                    <a href="#dt-name" class="corex-btn-primary">Add document type</a>
                </div>
            @endforelse
        </div>
    </div>

</div>
@endsection
