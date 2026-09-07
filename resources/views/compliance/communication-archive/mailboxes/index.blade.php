{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Archive Mailboxes</h1>
                <p class="text-xs" style="color: var(--text-muted);">Agency-held mailboxes — polled into the Communication Archive (incoming) and, once outgoing mail is set up, the mailbox CoreX sends e-sign invitations through (AT-395).</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('compliance.comm-archive.index') }}" class="corex-btn-outline text-xs">Archive</a>
                <a href="{{ route('compliance.comm-mailboxes.create') }}" class="corex-btn-primary text-xs">Add Mailbox</a>
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

    {{-- AT-395 §6 — Test Connection result, both legs. --}}
    @if(session('test_connection_result'))
        @php
            $tc = session('test_connection_result');
        @endphp
        <div class="rounded-md px-4 py-3 text-sm space-y-1" style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-primary);">
            <div><strong>SMTP send:</strong> <span style="color: {{ $tc['smtp']['ok'] ? 'var(--ds-green)' : 'var(--ds-crimson)' }};">{{ $tc['smtp']['ok'] ? 'Pass' : 'Fail' }}</span> — {{ $tc['smtp']['message'] }}</div>
            <div><strong>Sent-folder write:</strong> <span style="color: {{ $tc['imap_append']['ok'] ? 'var(--ds-green)' : 'var(--ds-crimson)' }};">{{ $tc['imap_append']['ok'] ? 'Pass' : 'Fail' }}</span> — {{ $tc['imap_append']['message'] }}</div>
        </div>
    @endif

    {{-- AT-395 §7.2 — search, filters. Sort is column-header driven below. --}}
    <form method="GET" class="rounded-md px-4 py-3 flex flex-wrap items-end gap-3" style="background: var(--surface); border:1px solid var(--border);">
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Email or user name"
                   class="rounded-md px-3 py-1.5 text-sm" style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-primary);">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Status</label>
            <select name="status" class="rounded-md px-3 py-1.5 text-sm" style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-primary);">
                <option value="">All</option>
                <option value="active" @selected(($filters['status'] ?? null) === 'active')>Active</option>
                <option value="inactive" @selected(($filters['status'] ?? null) === 'inactive')>Inactive</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Outgoing mail</label>
            <select name="outgoing" class="rounded-md px-3 py-1.5 text-sm" style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-primary);">
                <option value="">All</option>
                <option value="yes" @selected(($filters['outgoing'] ?? null) === 'yes')>Enabled</option>
                <option value="no" @selected(($filters['outgoing'] ?? null) === 'no')>Not enabled</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Send health</label>
            <select name="send_health" class="rounded-md px-3 py-1.5 text-sm" style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-primary);">
                <option value="">All</option>
                <option value="healthy" @selected(($filters['send_health'] ?? null) === 'healthy')>Healthy</option>
                <option value="failing" @selected(($filters['send_health'] ?? null) === 'failing')>Failing</option>
                <option value="pending" @selected(($filters['send_health'] ?? null) === 'pending')>Pending</option>
                <option value="inactive" @selected(($filters['send_health'] ?? null) === 'inactive')>Inactive</option>
            </select>
        </div>
        <button type="submit" class="corex-btn-outline text-xs">Filter</button>
        @if(array_filter($filters ?? []))
            <a href="{{ route('compliance.comm-mailboxes.index') }}" class="text-xs" style="color: var(--text-muted);">Clear</a>
        @endif
    </form>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        @php
                            $dir = ($filters['sort'] ?? 'email_address') === 'email_address' && ($filters['direction'] ?? 'asc') === 'asc' ? 'desc' : 'asc';
                        @endphp
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'email_address', 'direction' => $dir]) }}">Email</a>
                        </th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Host</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Polls</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'last_polled_at', 'direction' => ($filters['sort'] ?? '') === 'last_polled_at' && ($filters['direction'] ?? 'asc') === 'asc' ? 'desc' : 'asc']) }}">Last polled</a>
                        </th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Read health</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                            Outgoing mail
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'last_sent_at', 'direction' => ($filters['sort'] ?? '') === 'last_sent_at' && ($filters['direction'] ?? 'asc') === 'asc' ? 'desc' : 'asc']) }}" class="ml-1" style="color: var(--text-muted);">↕</a>
                        </th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($mailboxes as $m)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">
                            {{ $m->email_address }}
                            @if($m->user)<div class="text-xs" style="color: var(--text-muted);">{{ $m->user->name }}</div>@endif
                        </td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $m->imap_host }}:{{ $m->imap_port }}</td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $m->poll_inbox ? 'Inbox' : '' }}{{ $m->poll_inbox && $m->poll_sent ? ' + ' : '' }}{{ $m->poll_sent ? 'Sent' : '' }}</td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $m->last_polled_at?->format('d M H:i') ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @php
                                // AT-181 — honest health badge. The manual on/off flag is only one
                                // input; genuine ingestion health is derived from poll success + freshness.
                                $health = $m->pollHealth();
                                $badge = [
                                    'inactive' => ['class' => 'ds-badge-default', 'label' => 'Inactive'],
                                    'pending'  => ['class' => 'ds-badge-info',    'label' => 'Pending'],
                                    'healthy'  => ['class' => 'ds-badge-success', 'label' => 'Healthy'],
                                    'failing'  => ['class' => 'ds-badge-danger',  'label' => 'Failing'],
                                ][$health];
                                $reason = $m->lastErrorLabel();
                            @endphp
                            <span class="ds-badge {{ $badge['class'] }}" title="{{ $reason ?? 'Polling and ingesting normally.' }}">{{ $badge['label'] }}</span>
                        </td>
                        <td class="px-4 py-3">
                            @if(!$m->outgoing_enabled)
                                <span class="ds-badge ds-badge-default" title="Outgoing mail is not set up for this mailbox — e-sign invitations for this person still send via the shared CoreX address.">Not set up</span>
                            @else
                                @php
                                    $sendHealth = $m->sendHealth();
                                    $sendBadge = [
                                        'inactive' => ['class' => 'ds-badge-default', 'label' => 'Inactive'],
                                        'pending'  => ['class' => 'ds-badge-info',    'label' => 'Pending'],
                                        'healthy'  => ['class' => 'ds-badge-success', 'label' => 'Healthy'],
                                        'failing'  => ['class' => 'ds-badge-danger',  'label' => 'Failing'],
                                    ][$sendHealth];
                                    $sendReason = $m->lastSendErrorLabel();
                                @endphp
                                <span class="ds-badge {{ $sendBadge['class'] }}" title="{{ $sendReason ?? 'Sending normally through this mailbox.' }}">{{ $sendBadge['label'] }}</span>
                                @if($sendHealth === 'failing')
                                    <div class="mt-1 text-xs" style="color: var(--ds-crimson);">{{ $sendReason }}</div>
                                @endif
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <form method="POST" action="{{ route('compliance.comm-mailboxes.test-connection', $m) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-xs font-semibold" style="color: var(--brand-icon);">Test</button>
                            </form>
                            <a href="{{ route('compliance.comm-mailboxes.edit', $m) }}" class="text-xs font-semibold ml-2" style="color: var(--brand-icon);">Edit</a>
                            <form method="POST" action="{{ route('compliance.comm-mailboxes.destroy', $m) }}" class="inline ml-2" onsubmit="return confirm('Archive this mailbox?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-xs font-semibold" style="color: var(--ds-crimson);">Archive</button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                            @if(array_filter($filters ?? []))
                                No mailboxes match this filter. <a href="{{ route('compliance.comm-mailboxes.index') }}" style="color: var(--brand-icon);">Clear filters</a> to see all.
                            @else
                                No mailboxes configured yet. Add one to start capturing email and, once outgoing mail is set up, sending e-sign invitations from your own address.
                            @endif
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($mailboxes->hasPages())
            <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
                {{ $mailboxes->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
