{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md --}}
@extends('layouts.corex')

@section('corex-content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Soft Deletes</h1>
                <p class="text-sm text-white/60">
                    Everything in CoreX is archived, never hard-deleted. Anything that has been deleted shows here so you can restore it.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-semibold text-white"
                      style="background: color-mix(in srgb, white 15%, transparent);"
                      title="Total archived records you can restore">
                    {{ number_format($totalArchived) }} archived
                </span>
            </div>
        </div>
    </div>

    {{-- Flash messages --}}
    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            {{ session('error') }}
        </div>
    @endif

    @forelse($categories as $group)
        <div class="space-y-2">
            <div class="flex items-center gap-2">
                <h2 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--text-muted);">{{ $group['category'] }}</h2>
                <span class="text-xs" style="color: var(--text-muted);">·</span>
                <span class="text-xs font-semibold" style="color: var(--text-muted);">{{ number_format($group['total']) }}</span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach($group['models'] as $model)
                    <a href="{{ route('admin.soft-deletes.show', $model['key']) }}"
                       class="rounded-md px-4 py-3 flex items-center justify-between transition-colors"
                       style="background: var(--surface); border: 1px solid var(--border);"
                       onmouseover="this.style.background='var(--surface-2)'"
                       onmouseout="this.style.background='var(--surface)'">
                        <span class="text-sm font-semibold" style="color: var(--text-primary);">{{ $model['label'] }}</span>
                        <span class="inline-flex items-center justify-center min-w-[24px] h-6 px-2 rounded-full text-xs font-bold"
                              style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 15%, transparent); color: var(--brand-icon, #0ea5e9);">
                            {{ number_format($model['count']) }}
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    @empty
        <div class="rounded-md px-4 py-16 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <p class="text-sm font-semibold" style="color: var(--text-primary);">Nothing archived</p>
            <p class="text-sm mt-1" style="color: var(--text-muted);">When records are deleted anywhere in CoreX, they will appear here for you to restore.</p>
        </div>
    @endforelse

</div>
@endsection
