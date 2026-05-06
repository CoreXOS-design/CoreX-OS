@extends('layouts.corex')

@section('corex-content')
<div class="space-y-4">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <h1 class="text-xl font-bold text-white">Calendar Invitations</h1>
        <p class="text-sm text-white/60">Events other agents have invited you to.</p>
    </div>

    @if(session('success'))
        <div class="px-4 py-3 rounded-lg text-sm font-medium" style="background:rgba(16,185,129,0.1); color:#10b981;">{{ session('success') }}</div>
    @endif

    @forelse($invitations as $inv)
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="text-sm font-semibold" style="color: var(--text-primary);">{{ $inv->event?->title ?? 'Event' }}</div>
                    <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                        {{ $inv->event?->event_date?->format('D, d M Y H:i') ?? '' }}
                        · Invited by {{ $inv->inviter?->name ?? 'Unknown' }}
                    </div>
                    @if($inv->status === 'tentative')
                        <span class="text-[10px] px-1.5 py-0.5 rounded mt-1 inline-block" style="background: #f59e0b20; color: #f59e0b;">Tentative</span>
                    @endif
                    @if(!empty($inv->conflict_at_invite))
                        <div class="mt-2 text-[10px] px-2 py-1 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 8%, transparent); color: #ef4444; border: 1px solid rgba(239,68,68,0.2);">
                            ⚠ Conflict: {{ collect($inv->conflict_at_invite)->pluck('title')->implode(', ') }}
                        </div>
                    @endif
                </div>
                <div class="flex items-center gap-1 flex-shrink-0">
                    <form method="POST" action="{{ route('command-center.calendar.invitations.respond', $inv) }}">
                        @csrf <input type="hidden" name="action" value="accepted">
                        <button type="submit" class="text-[10px] font-medium px-2 py-1 rounded text-white" style="background: #10b981;">Accept</button>
                    </form>
                    <form method="POST" action="{{ route('command-center.calendar.invitations.respond', $inv) }}">
                        @csrf <input type="hidden" name="action" value="tentative">
                        <button type="submit" class="text-[10px] font-medium px-2 py-1 rounded" style="background: var(--surface-2); color: #f59e0b; border: 1px solid rgba(245,158,11,0.3);">Tentative</button>
                    </form>
                    <form method="POST" action="{{ route('command-center.calendar.invitations.respond', $inv) }}">
                        @csrf <input type="hidden" name="action" value="declined">
                        <button type="submit" class="text-[10px] font-medium px-2 py-1 rounded" style="background: var(--surface-2); color: #ef4444; border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);">Decline</button>
                    </form>
                </div>
            </div>
        </div>
    @empty
        <div class="rounded-md p-8 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <p class="text-sm" style="color: var(--text-muted);">No pending invitations.</p>
        </div>
    @endforelse

    {{ $invitations->links() }}
</div>
@endsection
