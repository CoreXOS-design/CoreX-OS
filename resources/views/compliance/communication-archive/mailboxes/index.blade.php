{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Archive Mailboxes</h1>
                <p class="text-sm text-white/60">Agency-held IMAP mailboxes polled into the Communication Archive.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('compliance.comm-archive.index') }}" class="corex-btn-outline text-sm" style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">Archive</a>
                <a href="{{ route('compliance.comm-mailboxes.create') }}" class="corex-btn-primary text-sm">Add Mailbox</a>
            </div>
        </div>
    </div>

    @if(session('success'))
    <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="color: var(--ds-green);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
        </svg>
        <div class="flex-1">{{ session('success') }}</div>
    </div>
    @endif

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Email</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Host</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Polls</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Interval</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Last polled</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($mailboxes as $m)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $m->email_address }}</td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $m->imap_host }}:{{ $m->imap_port }}</td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $m->poll_inbox ? 'Inbox' : '' }}{{ $m->poll_inbox && $m->poll_sent ? ' + ' : '' }}{{ $m->poll_sent ? 'Sent' : '' }}</td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $m->poll_interval_minutes }} min</td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $m->last_polled_at?->format('d M H:i') ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="ds-badge {{ $m->active ? 'ds-badge-success' : 'ds-badge-default' }}">{{ $m->active ? 'Active' : 'Inactive' }}</span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('compliance.comm-mailboxes.edit', $m) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Edit</a>
                            <form method="POST" action="{{ route('compliance.comm-mailboxes.destroy', $m) }}" class="inline ml-2" onsubmit="return confirm('Archive this mailbox?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-xs font-semibold" style="color: var(--ds-crimson);">Archive</button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">No mailboxes configured. Add one to start capturing email.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
