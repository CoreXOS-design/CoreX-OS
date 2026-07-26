{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
     Adoption view — who has actually seen this. Spec: .ai/specs/system-updates.md §7.1 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $update->title }}</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    {{ $update->typeLabel() }} ·
                    @if($update->isPublished())
                        published {{ $update->published_at->format('d M Y') }}
                    @else
                        not published
                    @endif
                    · by {{ $update->authorName() }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.system-updates.edit', $update->id) }}" class="corex-btn-outline text-xs">Edit</a>
                <a href="{{ route('admin.system-updates.index') }}" class="corex-btn-outline text-xs">Back</a>
            </div>
        </div>
    </div>

    {{-- Adoption --}}
    <div class="rounded-md p-5"
         style="background:var(--surface); border:1px solid var(--border);">
        <div class="text-sm font-semibold mb-1" style="color:var(--text-primary);">Seen by</div>
        <div class="text-2xl font-bold" style="color:var(--text-primary);">
            {{ number_format($seen) }} <span class="text-base font-normal" style="color:var(--text-secondary);">of {{ number_format($total) }} CoreX users</span>
        </div>
        @if($update->notify_reset_at)
            <p class="text-xs mt-2" style="color:var(--text-secondary);">
                Counting only since you re-notified on {{ $update->notify_reset_at->format('d M Y \a\t H:i') }}.
                Earlier views are kept but no longer counted.
            </p>
        @endif
    </div>

    {{-- The update as users see it --}}
    <div class="rounded-md p-5"
         style="background:var(--surface); border:1px solid var(--border);">
        @include('layouts.partials._system-update-card', ['update' => $update, 'showLink' => false])
    </div>

    {{-- Who --}}
    <div class="rounded-md overflow-hidden"
         style="background:var(--surface); border:1px solid var(--border);">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="background:var(--surface-2); color:var(--text-secondary);">
                    <th class="text-left px-4 py-3 font-semibold">User</th>
                    <th class="text-left px-4 py-3 font-semibold">Email</th>
                    <th class="text-left px-4 py-3 font-semibold">Closed it</th>
                </tr>
            </thead>
            <tbody>
            @forelse($viewers as $viewer)
                <tr style="border-top:1px solid var(--border); color:var(--text-primary);">
                    <td class="px-4 py-3">{{ $viewer->user?->name ?? 'Deleted user' }}</td>
                    <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $viewer->user?->email ?? '—' }}</td>
                    <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $viewer->dismissed_at?->format('d M Y H:i') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" class="px-4 py-8 text-center" style="color:var(--text-secondary);">
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
