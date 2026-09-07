{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — tokens via var(--token, #fallback). --}}
{{-- AT-37 shared IMAP credential fields. $mbx (nullable), $isEdit (bool). Password write-only. --}}
{{-- AT-395 (2026-09-07) — outgoing/SMTP fields added below so this shared partial matches
     the compliance "Email Mailboxes (import)" form field-for-field: a mailbox configured
     from either screen must be able to send. Unique element IDs are suffixed with the
     mailbox id (or 'new') because this partial renders once per row in a list, unlike the
     compliance form which only ever shows one mailbox per page. --}}
@php
    $mbx = $mbx ?? null;
    $ogSuffix = $mbx->id ?? 'new';
@endphp
<div class="grid grid-cols-1 md:grid-cols-2 gap-3">
    <div>
        <label class="block text-xs font-semibold mb-1" style="color: var(--text-primary, #1f2937);">Email address *</label>
        <input type="email" name="email_address" required value="{{ old('email_address', $mbx->email_address ?? '') }}"
               class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border); background: var(--surface-2); color: var(--text-primary);">
        @error('email_address') <p class="text-xs mt-1" style="color: var(--ds-crimson, #dc2626);">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-xs font-semibold mb-1" style="color: var(--text-primary, #1f2937);">Username *</label>
        <input type="text" name="username" required autocomplete="off" value="{{ old('username', $mbx->username ?? '') }}"
               class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border); background: var(--surface-2); color: var(--text-primary);">
        @error('username') <p class="text-xs mt-1" style="color: var(--ds-crimson, #dc2626);">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-xs font-semibold mb-1" style="color: var(--text-primary, #1f2937);">IMAP host *</label>
        <input type="text" name="imap_host" required placeholder="imap.example.com" value="{{ old('imap_host', $mbx->imap_host ?? '') }}"
               class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border); background: var(--surface-2); color: var(--text-primary);">
        @error('imap_host') <p class="text-xs mt-1" style="color: var(--ds-crimson, #dc2626);">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-xs font-semibold mb-1" style="color: var(--text-primary, #1f2937);">Port *</label>
        <input type="number" name="imap_port" required min="1" max="65535" value="{{ old('imap_port', $mbx->imap_port ?? 993) }}"
               class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border); background: var(--surface-2); color: var(--text-primary);">
        @error('imap_port') <p class="text-xs mt-1" style="color: var(--ds-crimson, #dc2626);">{{ $message }}</p> @enderror
    </div>
    <div class="md:col-span-2">
        <label class="block text-xs font-semibold mb-1" style="color: var(--text-primary, #1f2937);">Password {{ $isEdit ? '(leave blank to keep current)' : '*' }}</label>
        <input type="password" name="password" autocomplete="new-password" {{ $isEdit ? '' : 'required' }}
               class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border); background: var(--surface-2); color: var(--text-primary);">
        <p class="text-xs mt-1" style="color: var(--text-muted, #6b7280);">Stored encrypted at rest. Never displayed back — use Reveal (logged) to retrieve it.</p>
        @error('password') <p class="text-xs mt-1" style="color: var(--ds-crimson, #dc2626);">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-xs font-semibold mb-1" style="color: var(--text-primary, #1f2937);">Poll interval (minutes) *</label>
        <input type="number" name="poll_interval_minutes" required min="1" max="1440" value="{{ old('poll_interval_minutes', $mbx->poll_interval_minutes ?? 15) }}"
               class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border); background: var(--surface-2); color: var(--text-primary);">
        @error('poll_interval_minutes') <p class="text-xs mt-1" style="color: var(--ds-crimson, #dc2626);">{{ $message }}</p> @enderror
    </div>
    <div class="flex flex-col justify-end gap-1.5">
        <label class="flex items-center gap-2 text-xs" style="color: var(--text-primary, #1f2937);">
            <input type="checkbox" name="poll_inbox" value="1" {{ old('poll_inbox', $mbx->poll_inbox ?? true) ? 'checked' : '' }} style="accent-color: var(--brand-icon, #00b4d8);"> Poll Inbox (inbound)
        </label>
        <label class="flex items-center gap-2 text-xs" style="color: var(--text-primary, #1f2937);">
            <input type="checkbox" name="poll_sent" value="1" {{ old('poll_sent', $mbx->poll_sent ?? true) ? 'checked' : '' }} style="accent-color: var(--brand-icon, #00b4d8);"> Poll Sent (outbound)
        </label>
        <label class="flex items-center gap-2 text-xs" style="color: var(--text-primary, #1f2937);">
            <input type="checkbox" name="active" value="1" {{ old('active', $mbx->active ?? true) ? 'checked' : '' }} style="accent-color: var(--brand-icon, #00b4d8);"> Active
        </label>
    </div>
</div>

<div class="mt-3 pt-3" style="border-top: 1px solid var(--border, #e5e7eb);">
    <h4 class="text-xs font-bold mb-1" style="color: var(--text-primary, #1f2937);">Outgoing mail (SMTP)</h4>
    <p class="text-xs mb-2" style="color: var(--text-muted, #6b7280);">Send e-sign invitations through this mailbox's own mail server, so receiving mail servers trust the sender and a copy lands in this mailbox's own Sent folder. Off by default — leave off and nothing changes.</p>

    <label class="flex items-center gap-2 text-xs mb-2" style="color: var(--text-primary, #1f2937);">
        <input type="checkbox" id="outgoing-enabled-{{ $ogSuffix }}" name="outgoing_enabled" value="1"
               {{ old('outgoing_enabled', $mbx->outgoing_enabled ?? false) ? 'checked' : '' }}
               style="accent-color: var(--brand-icon, #00b4d8);"
               onchange="document.getElementById('outgoing-fields-{{ $ogSuffix }}').style.display = this.checked ? 'block' : 'none';">
        Send outgoing mail through this mailbox
    </label>

    <div id="outgoing-fields-{{ $ogSuffix }}" class="space-y-2 pl-1" style="display: {{ old('outgoing_enabled', $mbx->outgoing_enabled ?? false) ? 'block' : 'none' }};">
        <label class="flex items-center gap-2 text-xs" style="color: var(--text-primary, #1f2937);">
            <input type="checkbox" name="use_imap_credentials_for_smtp" value="1"
                   {{ old('use_imap_credentials_for_smtp', $mbx->use_imap_credentials_for_smtp ?? true) ? 'checked' : '' }}
                   style="accent-color: var(--brand-icon, #00b4d8);">
            Use the same username and password as above
        </label>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="md:col-span-2">
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-primary, #1f2937);">SMTP host *</label>
                <input type="text" name="smtp_host" placeholder="mail.example.com" value="{{ old('smtp_host', $mbx->smtp_host ?? '') }}"
                       class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border); background: var(--surface-2); color: var(--text-primary);">
                @error('smtp_host') <p class="text-xs mt-1" style="color: var(--ds-crimson, #dc2626);">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-primary, #1f2937);">Port</label>
                <input type="number" name="smtp_port" value="{{ old('smtp_port', $mbx->smtp_port ?? 587) }}"
                       class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border); background: var(--surface-2); color: var(--text-primary);">
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-primary, #1f2937);">Encryption</label>
                <select name="smtp_encryption" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border); background: var(--surface-2); color: var(--text-primary);">
                    @foreach(['tls' => 'TLS (STARTTLS, port 587 — most common)', 'ssl' => 'SSL (port 465)', 'none' => 'None'] as $val => $label)
                        <option value="{{ $val }}" @selected(old('smtp_encryption', $mbx->smtp_encryption ?? 'tls') === $val)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-primary, #1f2937);">From name override</label>
                <input type="text" name="smtp_from_name" placeholder="Agent name" value="{{ old('smtp_from_name', $mbx->smtp_from_name ?? '') }}"
                       class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border); background: var(--surface-2); color: var(--text-primary);">
            </div>
        </div>

        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-primary, #1f2937);">SMTP username (only if different from above)</label>
            <input type="text" name="smtp_username" autocomplete="off" value="{{ old('smtp_username', $mbx->smtp_username ?? '') }}"
                   class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border); background: var(--surface-2); color: var(--text-primary);">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-primary, #1f2937);">SMTP password (only if different from above)</label>
            <input type="password" name="smtp_password" autocomplete="new-password"
                   class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border); background: var(--surface-2); color: var(--text-primary);">
            <p class="text-xs mt-1" style="color: var(--text-muted, #6b7280);">Stored encrypted at rest. Never displayed back.</p>
        </div>

        <label class="flex items-center gap-2 text-xs" style="color: var(--text-primary, #1f2937);">
            <input type="checkbox" name="outgoing_active" value="1" {{ old('outgoing_active', $mbx->outgoing_active ?? true) ? 'checked' : '' }} style="accent-color: var(--brand-icon, #00b4d8);"> Outgoing active
        </label>

        @if($isEdit && $mbx)
            @php $sh = $mbx->sendHealth(); @endphp
            <p class="text-xs" style="color: {{ $sh === 'failing' ? 'var(--ds-crimson, #dc2626)' : 'var(--text-muted, #6b7280)' }};">
                Send health: <strong>{{ ucfirst($sh) }}</strong>@if($sh === 'failing' && $mbx->lastSendErrorLabel()) — {{ $mbx->lastSendErrorLabel() }}@endif
            </p>
        @endif
    </div>
</div>
