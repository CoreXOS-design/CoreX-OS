{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — tokens via var(--token, #fallback); no naked hex. --}}
{{--
    Reusable per-user mailbox management block. Used by:
      • Settings → Email Setup (agency control centre, AT-37)
      • Admin → Users edit "Communication Capture" section (AT-37)
      • My Portal → Communication Capture (user self-service, AT-39)
    One component, not three code paths.

    Expects:
      $user — the owner whose mailboxes render (with ->commMailboxes loaded).
      $ctx  — OPTIONAL route/permission context. Defaults to agency mode
              (settings.email-setup.* + principal reveal). Self-service passes a
              ctx pointing at its own routes and disables reveal.

    The password field is WRITE-ONLY: it never renders a stored value;
    blank-on-edit keeps the current password.
--}}
@php
    $ctx         = $ctx ?? [];
    $storeUrl    = $ctx['storeUrl']    ?? route('settings.email-setup.store', $user);
    $updateName  = $ctx['updateName']  ?? 'settings.email-setup.update';
    $destroyName = $ctx['destroyName'] ?? 'settings.email-setup.destroy';
    $revealName  = $ctx['revealName']  ?? 'settings.email-setup.reveal';
    // AT-395 (2026-09-07) — same Test Connection action the compliance screen
    // has, so a mailbox configured here can be verified the same way.
    $testConnectionName = $ctx['testConnectionName'] ?? 'settings.email-setup.test-connection';
    // Reveal only where the context allows it AND the viewer holds the perm.
    $allowReveal = ($ctx['allowReveal'] ?? true) && auth()->user()->hasPermission('reveal_mailbox_credential');
    $revealedId  = session('revealed_mailbox_id');
    $revealedPass = session('revealed_password');
    $setByLabel  = ['agency' => 'Set by agency', 'user' => 'Set by user'];
    // AT-395 (2026-09-07) — Test Connection is a full page POST/redirect/GET, so
    // the edit panel holding its result must re-open on the mailbox it belongs
    // to after the redirect, or the flashed result renders behind x-show hidden.
    $reopenEditingFor = session('test_connection_mailbox_id');
@endphp

<div x-data="{ adding: false, editing: {{ $reopenEditingFor ? (int) $reopenEditingFor : 'null' }} }" class="space-y-3">
    @forelse($user->commMailboxes as $mbx)
        <div class="rounded-md p-3" style="background: var(--surface-2, #f8fafc); border: 1px solid var(--border, #e5e7eb);">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm truncate" style="color: var(--text-primary, #1f2937);">{{ $mbx->email_address }}</div>
                    <div class="text-xs mt-0.5" style="color: var(--text-muted, #6b7280);">
                        {{ $mbx->imap_host }}:{{ $mbx->imap_port }} ·
                        {{ $mbx->poll_inbox ? 'Inbox' : '' }}{{ $mbx->poll_inbox && $mbx->poll_sent ? ' + ' : '' }}{{ $mbx->poll_sent ? 'Sent' : '' }} ·
                        {{ $mbx->poll_interval_minutes }} min ·
                        <span style="color: {{ $mbx->active ? 'var(--ds-green, #16a34a)' : 'var(--text-muted, #6b7280)' }};">{{ $mbx->active ? 'Active' : 'Inactive' }}</span>
                        @if($mbx->set_by)
                            · <span title="Who last set these credentials">{{ $setByLabel[$mbx->set_by] ?? $mbx->set_by }}</span>
                        @endif
                        {{-- AT-395 (2026-09-07) — same outgoing-status visibility as the compliance screen. --}}
                        @if($mbx->outgoing_enabled)
                            · <span style="color: {{ $mbx->sendHealth() === 'failing' ? 'var(--ds-crimson, #dc2626)' : 'var(--ds-green, #16a34a)' }};" title="Outgoing mail (e-sign invitations) through this mailbox">Outgoing: {{ ucfirst($mbx->sendHealth()) }}</span>
                        @endif
                    </div>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <button type="button" class="text-xs font-semibold" style="color: var(--brand-icon, #00b4d8);" @click="editing === {{ $mbx->id }} ? editing = null : editing = {{ $mbx->id }}">Edit</button>
                    @if($allowReveal)
                        <form method="POST" action="{{ route($revealName, $mbx) }}" class="inline"
                              onsubmit="return confirm('Reveal this mailbox password? Every reveal is recorded in the credential audit log.');">
                            @csrf
                            <button type="submit" class="text-xs font-semibold" style="color: var(--ds-amber, #d97706);" title="Audited — every reveal is logged">Reveal</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route($destroyName, $mbx) }}" class="inline"
                          onsubmit="return confirm('Archive this capture mailbox?');">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-xs font-semibold" style="color: var(--ds-crimson, #dc2626);">Archive</button>
                    </form>
                </div>
            </div>

            {{-- Revealed once, only for the mailbox just revealed. --}}
            @if($allowReveal && (int) $revealedId === (int) $mbx->id && $revealedPass !== null)
                <div class="mt-2 rounded-md px-3 py-2 text-xs" style="background: color-mix(in srgb, var(--ds-amber, #d97706) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #d97706) 30%, transparent); color: var(--text-primary, #1f2937);">
                    Password (shown once, this reveal is logged): <code class="font-mono font-semibold">{{ $revealedPass }}</code>
                </div>
            @endif

            {{-- Inline edit form. --}}
            <div x-show="editing === {{ $mbx->id }}" x-cloak class="mt-3 pt-3" style="border-top: 1px solid var(--border, #e5e7eb);">
                <form method="POST" action="{{ route($updateName, $mbx) }}" class="space-y-3">
                    @csrf @method('PUT')
                    @include('settings.email-setup._mailbox-fields', ['mbx' => $mbx, 'isEdit' => true])
                    <div class="flex items-center gap-3">
                        <button type="submit" class="corex-btn-primary">Save</button>
                        <button type="button" class="text-sm" style="color: var(--text-muted, #6b7280);" @click="editing = null">Cancel</button>
                    </div>
                </form>

                {{-- AT-395 (2026-09-07) — same Test Connection action as the compliance
                     screen, both legs reported independently. Separate form so this
                     POST never inherits the edit form's PUT method-spoof. --}}
                <form method="POST" action="{{ route($testConnectionName, $mbx) }}" class="mt-2">
                    @csrf
                    <button type="submit" class="text-xs font-semibold" style="color: var(--brand-icon, #00b4d8);">Test Connection (both legs)</button>
                </form>

                @if(session('test_connection_result') && (int) session('test_connection_mailbox_id') === (int) $mbx->id)
                    @php $tc = session('test_connection_result'); @endphp
                    <div class="mt-2 rounded-md px-3 py-2 text-xs space-y-1" style="background: var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color: var(--text-primary, #1f2937);">
                        <div><strong>SMTP send:</strong> <span style="color: {{ $tc['smtp']['ok'] ? 'var(--ds-green, #16a34a)' : 'var(--ds-crimson, #dc2626)' }};">{{ $tc['smtp']['ok'] ? 'Pass' : 'Fail' }}</span> — {{ $tc['smtp']['message'] }}</div>
                        <div><strong>Sent-folder write:</strong> <span style="color: {{ $tc['imap_append']['ok'] ? 'var(--ds-green, #16a34a)' : 'var(--ds-crimson, #dc2626)' }};">{{ $tc['imap_append']['ok'] ? 'Pass' : 'Fail' }}</span> — {{ $tc['imap_append']['message'] }}</div>
                    </div>
                @endif
            </div>
        </div>
    @empty
        <p class="text-xs" style="color: var(--text-muted, #6b7280);">No capture mailbox linked yet.</p>
    @endforelse

    {{-- Add a mailbox. --}}
    <div>
        <button type="button" class="text-xs font-semibold" style="color: var(--brand-icon, #00b4d8);" x-show="!adding" @click="adding = true">+ Link a mailbox</button>
        <div x-show="adding" x-cloak class="rounded-md p-3 mt-1" style="background: var(--surface-2, #f8fafc); border: 1px solid var(--border, #e5e7eb);">
            <form method="POST" action="{{ $storeUrl }}" class="space-y-3">
                @csrf
                @include('settings.email-setup._mailbox-fields', ['mbx' => null, 'isEdit' => false])
                <div class="flex items-center gap-3">
                    <button type="submit" class="corex-btn-primary">Link mailbox</button>
                    <button type="button" class="text-sm" style="color: var(--text-muted, #6b7280);" @click="adding = false">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
