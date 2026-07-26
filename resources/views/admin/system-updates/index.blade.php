{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
     Spec: .ai/specs/system-updates.md §7.1 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">System Updates</h1>
                <p class="text-sm text-white/60">
                    Tell everyone what changed in CoreX. Each update shows once as a pop-up the next
                    time a user opens CoreX, and never interrupts them again after they close it.
                </p>
            </div>
            <a href="{{ route('admin.system-updates.create') }}" class="corex-btn-primary">New Update</a>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background:var(--surface-2, #f0f2f8); color:var(--text-primary, #111827); border:1px solid var(--border, rgba(0,0,0,0.07));">
            {{ session('success') }}
        </div>
    @endif

    {{-- Archived toggle --}}
    <div class="flex items-center gap-3 text-sm">
        <a href="{{ route('admin.system-updates.index') }}"
           class="px-3 py-1.5 rounded-md"
           style="background:{{ $showArchived ? 'transparent' : 'var(--surface-2, #f0f2f8)' }}; color:var(--text-primary, #111827); border:1px solid var(--border, rgba(0,0,0,0.07));">
            Active
        </a>
        <a href="{{ route('admin.system-updates.index', ['archived' => 1]) }}"
           class="px-3 py-1.5 rounded-md"
           style="background:{{ $showArchived ? 'var(--surface-2, #f0f2f8)' : 'transparent' }}; color:var(--text-primary, #111827); border:1px solid var(--border, rgba(0,0,0,0.07));">
            Archived
        </a>
    </div>

    <div class="rounded-md overflow-hidden"
         style="background:var(--surface, #fff); border:1px solid var(--border, rgba(0,0,0,0.07));">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="background:var(--surface-2, #f0f2f8); color:var(--text-secondary, #6b7280);">
                    <th class="text-left px-4 py-3 font-semibold">Type</th>
                    <th class="text-left px-4 py-3 font-semibold">Title</th>
                    <th class="text-left px-4 py-3 font-semibold">Status</th>
                    <th class="text-left px-4 py-3 font-semibold">Seen by</th>
                    <th class="text-right px-4 py-3 font-semibold">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($updates as $update)
                @php $chip = $update->typeChip(); @endphp
                <tr style="border-top:1px solid var(--border, rgba(0,0,0,0.07)); color:var(--text-primary, #111827);">
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-1 rounded-md text-[0.6875rem] font-bold uppercase tracking-wide whitespace-nowrap"
                              style="background:color-mix(in srgb, var({{ $chip['token'] }}, {{ $chip['fallback'] }}) 15%, transparent);
                                     color:var({{ $chip['token'] }}, {{ $chip['fallback'] }});">
                            {{ $chip['label'] }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.system-updates.show', $update->id) }}" class="font-semibold" style="color:var(--brand-icon, #0ea5e9);">
                            {{ $update->title }}
                        </a>
                        <div class="text-xs mt-0.5" style="color:var(--text-secondary, #6b7280);">
                            by {{ $update->authorName() }}
                            @if($update->published_at) · {{ $update->published_at->format('d M Y') }} @endif
                        </div>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        @if($update->trashed())
                            <span style="color:var(--text-secondary, #6b7280);">Archived</span>
                        @elseif($update->isPublished())
                            <span style="color:var(--ds-emerald, #10b981);">Published</span>
                        @else
                            <span style="color:var(--ds-amber, #f59e0b);">Draft</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap" style="color:var(--text-secondary, #6b7280);">
                        @if($update->isPublished())
                            {{ number_format($adoption[$update->id]['seen']) }} / {{ number_format($adoption[$update->id]['total']) }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        @if($update->trashed())
                            <form method="POST" action="{{ route('admin.system-updates.restore', $update->id) }}" class="inline">
                                @csrf
                                <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                            </form>
                        @else
                            <a href="{{ route('admin.system-updates.edit', $update->id) }}" class="corex-btn-outline text-xs">Edit</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center" style="color:var(--text-secondary, #6b7280);">
                        @if($showArchived)
                            Nothing archived.
                        @else
                            No updates yet. Click <strong>New Update</strong> to tell everyone what changed.
                        @endif
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div>{{ $updates->links() }}</div>
</div>
@endsection
