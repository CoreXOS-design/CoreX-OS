{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — branded header, rounded-md cards, tokens via var(--token, #fallback). --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5" x-data>
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Email Setup</h1>
                <p class="text-xs" style="color: var(--text-muted);">Link each user's mailbox so their email feeds the Communication Archive. Passwords are stored encrypted and never shown — retrieving one is a separate, logged action.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('compliance.comm-archive.index') }}" class="corex-btn-outline text-xs">View Archive</a>
            </div>
        </div>
    </div>

    {{-- 2026-09-09 (Johan, auth-lock safeguard) — visible BEFORE a Test
         Connection click, not discovered after. Reset is admin-only, on the
         Compliance → Archive Mailboxes screen. --}}
    @foreach(($hostAuthStatus ?? []) as $host => $status)
        @if($status['locked'] || $status['count'] > 0)
        <div class="rounded-md px-4 py-3 text-sm" style="background: {{ $status['locked'] ? 'color-mix(in srgb, var(--ds-crimson) 10%, transparent)' : 'color-mix(in srgb, var(--ds-amber) 10%, transparent)' }}; border:1px solid {{ $status['locked'] ? 'color-mix(in srgb, var(--ds-crimson) 30%, transparent)' : 'color-mix(in srgb, var(--ds-amber) 30%, transparent)' }}; color: var(--text-primary);">
            <strong>{{ $host }}</strong> — {{ $status['label'] }}.
            @if($status['locked'])
                All further real connection attempts (polling and Test Connection) to this server are blocked until an admin clears the login lock on Compliance → Archive Mailboxes.
            @else
                Test Connection clicks and polling both count against this — a mail server login limit set by the mail host, not by CoreX.
            @endif
        </div>
        @endif
    @endforeach

    @if(session('success'))
    <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
         style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent); color: var(--text-primary, #1f2937);">
        <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green, #059669);" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
        </svg>
        <div class="flex-1">{{ session('success') }}</div>
    </div>
    @endif

    {{--
        AT-URGENT-2026-09-09 (Johan, via conductor) — "for corex uses only".
        Absent entirely for anyone who isn't a CoreX super admin, not
        disabled — an agency admin loading this exact page never sees this
        block at all. The actual safety boundary is server-side
        (owner_only middleware on the write route), this is a courtesy so a
        control nobody can use doesn't invite a support call.
    --}}
    @if($mailIntercept)
        <div class="rounded-md p-4" style="background: var(--surface, #fff); border: 2px solid {{ $mailIntercept['overridden'] ? '#dc2626' : 'var(--border, #e5e7eb)' }};"
             x-data="{ reason: '', direction: null }">
            <div class="flex items-center justify-between gap-3 mb-1">
                <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Outbound mail — CoreX only</h2>
                <span class="ds-badge {{ $mailIntercept['overridden'] ? 'ds-badge-danger' : ($mailIntercept['active'] ? 'ds-badge-success' : 'ds-badge-warning') }}">
                    {{ $mailIntercept['active'] ? 'Intercepting — nothing leaves' : 'SENDING FOR REAL' }}
                    @if($mailIntercept['overridden']) (FORCED) @endif
                </span>
            </div>
            <p class="text-xs mb-3" style="color: var(--text-muted);">
                Intercept outbound email (nothing reaches real inboxes) — currently
                <strong>{{ $mailIntercept['active'] ? 'ON' : 'OFF' }}</strong>
                @if($mailIntercept['overridden'])
                    (forced {{ $mailIntercept['active'] ? 'on' : 'off' }} — this environment would otherwise
                    {{ $mailIntercept['sendsByDefault'] ? 'send for real' : 'intercept' }} by default)
                @else
                    (this environment's own default — no override set)
                @endif.
                @if($mailIntercept['active'] && $mailIntercept['overridden'])
                    <strong>{{ $mailIntercept['heldCount'] }}</strong> message(s) held since this was turned on —
                    <a href="{{ route('admin.outbound-mail-captures.index') }}" style="color: var(--brand-icon, #2563eb);">view them</a>.
                @endif
            </p>

            <textarea x-model="reason" rows="2" class="corex-input text-xs w-full mb-2"
                      placeholder="Reason (required) — why are you changing this?"></textarea>

            <form method="POST" action="{{ route('settings.email-setup.mail-intercept') }}" class="inline"
                  @submit="$refs.direction1.value = 'intercept'; $refs.reason1.value = reason">
                @csrf @method('PUT')
                <input type="hidden" name="direction" x-ref="direction1">
                <input type="hidden" name="reason" x-ref="reason1">
                <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-crimson, #dc2626); border-color: var(--ds-crimson, #dc2626);"
                        :disabled="!reason.trim()">Force intercept ON</button>
            </form>
            <form method="POST" action="{{ route('settings.email-setup.mail-intercept') }}" class="inline"
                  @submit="$refs.direction2.value = 'send'; $refs.reason2.value = reason">
                @csrf @method('PUT')
                <input type="hidden" name="direction" x-ref="direction2">
                <input type="hidden" name="reason" x-ref="reason2">
                <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-amber, #b45309); border-color: var(--ds-amber, #b45309);"
                        :disabled="!reason.trim()">Force send (turn interception OFF)</button>
            </form>
            @if($mailIntercept['overridden'])
                <form method="POST" action="{{ route('settings.email-setup.mail-intercept') }}" class="inline"
                      @submit="$refs.direction3.value = 'clear'; $refs.reason3.value = reason">
                    @csrf @method('PUT')
                    <input type="hidden" name="direction" x-ref="direction3">
                    <input type="hidden" name="reason" x-ref="reason3">
                    <button type="submit" class="corex-btn-outline text-xs" :disabled="!reason.trim()">Clear override — use this environment's default</button>
                </form>
            @endif

            @error('reason')
                <p class="text-xs mt-2" style="color: var(--ds-crimson, #dc2626);">{{ $message }}</p>
            @enderror

            @if($mailIntercept['recentAudit']->isNotEmpty())
                <div class="mt-3 pt-3 text-xs space-y-1" style="border-top: 1px solid var(--border); color: var(--text-muted);">
                    <p class="font-medium mb-1" style="color: var(--text-secondary);">Recent changes</p>
                    @foreach($mailIntercept['recentAudit'] as $entry)
                        <div>
                            {{ $entry->created_at->format('d M Y H:i') }} —
                            <strong>{{ $entry->user->name ?? 'Unknown' }}</strong>
                            turned {{ $entry->direction === 'intercept_on' ? 'ON' : 'OFF' }}
                            ({{ $entry->environment }}) — &ldquo;{{ $entry->reason }}&rdquo;
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    @forelse($users as $user)
        <div class="rounded-md p-4" style="background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);">
            <div class="flex items-center justify-between gap-3 mb-3">
                <div>
                    <div class="font-semibold" style="color: var(--text-primary, #1f2937);">{{ $user->name }}</div>
                    <div class="text-xs" style="color: var(--text-muted, #6b7280);">{{ $user->email }} · {{ ucfirst(str_replace('_', ' ', $user->role)) }}</div>
                </div>
                <span class="ds-badge {{ $user->commMailboxes->where('active', true)->count() ? 'ds-badge-success' : 'ds-badge-default' }}">
                    {{ $user->commMailboxes->count() ? $user->commMailboxes->count() . ' mailbox' . ($user->commMailboxes->count() === 1 ? '' : 'es') : 'No capture' }}
                </span>
            </div>
            @include('settings.email-setup._user-mailbox', ['user' => $user])
        </div>
    @empty
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary, #111827);">No active users yet</h3>
            <p class="text-sm" style="color: var(--text-muted, #6b7280);">Once this agency has active users, link each mailbox here to feed the Communication Archive.</p>
        </div>
    @endforelse
</div>
@endsection
