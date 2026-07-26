{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20

     What's New — the user-facing archive. Read-only.
     Spec: .ai/specs/system-updates.md §7.5 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5" style="background:var(--brand-default, #0b2a4a);">
        <h1 class="text-xl font-bold text-white leading-tight">What's New</h1>
        <p class="text-sm text-white/60">Everything that has changed in CoreX since you joined.</p>
    </div>

    {{-- Type filter --}}
    @php $types = collect(config('system-updates.types', []))->sortBy('sort'); @endphp
    <div class="flex flex-wrap items-center gap-2 text-sm">
        <a href="{{ route('corex.whats-new.index') }}"
           class="px-3 py-1.5 rounded-md"
           style="background:{{ $activeType === '' ? 'var(--surface-2, #f0f2f8)' : 'transparent' }}; color:var(--text-primary, #111827); border:1px solid var(--border, rgba(0,0,0,0.07));">
            All
        </a>
        @foreach($types as $key => $meta)
            <a href="{{ route('corex.whats-new.index', ['type' => $key]) }}"
               class="px-3 py-1.5 rounded-md"
               style="background:{{ $activeType === $key ? "color-mix(in srgb, var({$meta['token']}, {$meta['fallback']}) 15%, transparent)" : 'transparent' }};
                      color:{{ $activeType === $key ? "var({$meta['token']}, {$meta['fallback']})" : 'var(--text-primary, #111827)' }};
                      border:1px solid {{ $activeType === $key ? "var({$meta['token']}, {$meta['fallback']})" : 'var(--border, rgba(0,0,0,0.07))' }};">
                {{ $meta['label'] }}
            </a>
        @endforeach
    </div>

    @forelse($updates as $update)
        <div class="rounded-md p-5"
             style="background:var(--surface, #fff); border:1px solid var(--border, rgba(0,0,0,0.07));">

            <div class="flex items-center justify-between gap-3 mb-3">
                <span class="text-xs" style="color:var(--text-secondary, #6b7280);">
                    {{ $update->published_at?->format('d M Y') }}
                </span>
                @if(in_array($update->id, $pendingIds, true))
                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[0.6875rem] font-bold uppercase tracking-wide"
                          style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 15%, transparent); color:var(--brand-icon, #0ea5e9);">
                        New
                    </span>
                @endif
            </div>

            @include('layouts.partials._system-update-card', ['update' => $update])
        </div>
    @empty
        <div class="rounded-md p-8 text-center text-sm"
             style="background:var(--surface, #fff); border:1px solid var(--border, rgba(0,0,0,0.07)); color:var(--text-secondary, #6b7280);">
            @if($activeType !== '')
                Nothing of that kind yet.
            @else
                Nothing new since you joined. When something changes in CoreX, it will appear here.
            @endif
        </div>
    @endforelse
</div>
@endsection
