{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
     Adoption view — who has actually seen this. Spec: .ai/specs/system-updates.md §7.1 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5" style="background:var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">{{ $update->title }}</h1>
                <p class="text-sm text-white/60">
                    {{ $update->typeLabel() }} · {{ $update->audienceLabel() }} ·
                    @if($update->isPublished())
                        published {{ $update->published_at->format('d M Y') }}
                    @else
                        not published
                    @endif
                    · by {{ $update->authorName() }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.system-updates.edit', $update->id) }}" class="corex-btn-outline text-sm" style="color:#fff; border-color:rgba(255,255,255,0.35);">Edit</a>
                <a href="{{ route('admin.system-updates.index') }}" class="corex-btn-outline text-sm" style="color:#fff; border-color:rgba(255,255,255,0.35);">Back</a>
            </div>
        </div>
    </div>

    {{-- Adoption --}}
    <div class="rounded-md p-5"
         style="background:var(--surface, #fff); border:1px solid var(--border, rgba(0,0,0,0.07));">
        <div class="text-sm font-semibold mb-1" style="color:var(--text-primary, #111827);">Seen by</div>
        <div class="text-2xl font-bold" style="color:var(--text-primary, #111827);">
            {{ number_format($seen) }} <span class="text-base font-normal" style="color:var(--text-secondary, #6b7280);">of {{ number_format($total) }} in audience</span>
        </div>
        @if($update->notify_reset_at)
            <p class="text-xs mt-2" style="color:var(--text-secondary, #6b7280);">
                Counting only since you re-notified on {{ $update->notify_reset_at->format('d M Y \a\t H:i') }}.
                Earlier views are kept but no longer counted.
            </p>
        @endif
    </div>

    {{-- The update as users see it --}}
    <div class="rounded-md p-5"
         style="background:var(--surface, #fff); border:1px solid var(--border, rgba(0,0,0,0.07));">
        @include('layouts.partials._system-update-card', ['update' => $update, 'showLink' => false])
    </div>

    {{-- Who --}}
    <div class="rounded-md overflow-hidden"
         style="background:var(--surface, #fff); border:1px solid var(--border, rgba(0,0,0,0.07));">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="background:var(--surface-2, #f0f2f8); color:var(--text-secondary, #6b7280);">
                    <th class="text-left px-4 py-3 font-semibold">User</th>
                    <th class="text-left px-4 py-3 font-semibold">Email</th>
                    <th class="text-left px-4 py-3 font-semibold">Closed it</th>
                </tr>
            </thead>
            <tbody>
            @forelse($viewers as $viewer)
                <tr style="border-top:1px solid var(--border, rgba(0,0,0,0.07)); color:var(--text-primary, #111827);">
                    <td class="px-4 py-3">{{ $viewer->user?->name ?? 'Deleted user' }}</td>
                    <td class="px-4 py-3" style="color:var(--text-secondary, #6b7280);">{{ $viewer->user?->email ?? '—' }}</td>
                    <td class="px-4 py-3" style="color:var(--text-secondary, #6b7280);">{{ $viewer->dismissed_at?->format('d M Y H:i') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" class="px-4 py-8 text-center" style="color:var(--text-secondary, #6b7280);">
                        Nobody has seen this yet.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div>{{ $viewers->links() }}</div>
</div>
@endsection
