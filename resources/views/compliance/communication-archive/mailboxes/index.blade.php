@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-6">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Archive Mailboxes</h1>
                <p class="text-sm text-white/60">Agency-held IMAP mailboxes polled into the Communication Archive.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('compliance.comm-archive.index') }}" class="corex-btn-outline" style="color:#fff; border-color:rgba(255,255,255,0.3);">Archive</a>
                <a href="{{ route('compliance.comm-mailboxes.create') }}" class="corex-btn-primary">Add Mailbox</a>
            </div>
        </div>
    </div>

    @if(session('success'))
    <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
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
                            @php
                                // AT-181 — honest health badge. The manual on/off flag is only one
                                // input; genuine ingestion health is derived from poll success + freshness.
                                $health = $m->pollHealth();
                                $badge = [
                                    'inactive' => ['class' => 'ds-badge-default', 'label' => 'Inactive'],
                                    'pending'  => ['class' => 'ds-badge-info',    'label' => 'Active · Pending first poll'],
                                    'healthy'  => ['class' => 'ds-badge-success', 'label' => 'Active · Healthy'],
                                    'failing'  => ['class' => 'ds-badge-danger',  'label' => 'Active · Failing'],
                                ][$health];
                                $reason = $m->lastErrorLabel();
                                $tip = $health === 'failing'
                                    ? ($reason ?? ($m->last_polled_at ? 'No successful poll within the last two intervals.' : 'Has never polled successfully — check the mailbox settings.'))
                                    : ($health === 'pending' ? 'Added recently; the scheduler has not polled it yet.' : ($health === 'inactive' ? 'Manually switched off — no polling.' : 'Polling and ingesting normally.'));
                            @endphp
                            <span class="ds-badge {{ $badge['class'] }}" title="{{ $tip }}">{{ $badge['label'] }}</span>
                            @if($health === 'failing')
                                <div class="mt-1 text-xs" style="color: var(--ds-crimson);">
                                    {{ $reason ?? ($m->last_polled_at ? 'Stale — no recent successful poll' : 'Never connected') }}@if($m->last_error_at) · {{ $m->last_error_at->format('d M H:i') }}@endif
                                </div>
                            @endif
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
