{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="space-y-6">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Calendar Invitations</h1>
                <p class="text-sm text-white/60">Events other agents have invited you to.</p>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green, #059669);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            <div class="flex-1">{{ session('success') }}</div>
        </div>
    @endif

    <div class="space-y-3">
    @forelse($invitations as $inv)
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <div class="text-sm font-semibold" style="color: var(--text-primary);">{{ $inv->event?->title ?? 'Event' }}</div>
                        @if($inv->status === 'tentative')
                            <span class="ds-badge ds-badge-warning">Tentative</span>
                        @endif
                    </div>
                    <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                        {{ $inv->event?->event_date?->format('D, d M Y H:i') ?? '' }}
                        · Invited by {{ $inv->inviter?->name ?? 'Unknown' }}
                    </div>
                    @php $liveConflicts = collect($inv->live_conflicts ?? []); @endphp
                    @if($liveConflicts->isNotEmpty())
                        <div class="rounded-md px-3 py-2 text-xs mt-2 flex items-start gap-2"
                             style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 10%, transparent);
                                    border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 30%, transparent);
                                    color: var(--text-primary);">
                            <svg class="w-4 h-4 flex-shrink-0" style="color: var(--ds-amber, #f59e0b);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                            </svg>
                            <div class="flex-1">
                                Conflicts with:
                                @foreach($liveConflicts as $c)
                                    {{ $c['title'] ?? 'Event' }}@if(!$loop->last), @endif
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <form method="POST" action="{{ route('command-center.calendar.invitations.respond', $inv) }}">
                        @csrf <input type="hidden" name="action" value="accepted">
                        <button type="submit" class="corex-btn-primary">Accept</button>
                    </form>
                    <form method="POST" action="{{ route('command-center.calendar.invitations.respond', $inv) }}">
                        @csrf <input type="hidden" name="action" value="tentative">
                        <button type="submit" class="corex-btn-outline">Tentative</button>
                    </form>
                    <form method="POST" action="{{ route('command-center.calendar.invitations.respond', $inv) }}">
                        @csrf <input type="hidden" name="action" value="declined">
                        <button type="submit" class="corex-btn-outline">Decline</button>
                    </form>
                </div>
            </div>
        </div>
    @empty
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No pending invitations</h3>
            <p class="text-sm" style="color: var(--text-muted);">You're all caught up. Invitations from other agents will appear here.</p>
        </div>
    @endforelse
    </div>

    {{ $invitations->links() }}
</div>
@endsection
