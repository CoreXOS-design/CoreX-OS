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
    // Reveal only where the context allows it AND the viewer holds the perm.
    $allowReveal = ($ctx['allowReveal'] ?? true) && auth()->user()->hasPermission('reveal_mailbox_credential');
    $revealedId  = session('revealed_mailbox_id');
    $revealedPass = session('revealed_password');
    $setByLabel  = ['agency' => 'Set by agency', 'user' => 'Set by user'];
@endphp

<div x-data="{ adding: false, editing: null }" class="space-y-3">
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
