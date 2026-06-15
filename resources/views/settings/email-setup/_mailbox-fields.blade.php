{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — tokens via var(--token, #fallback). --}}
{{-- AT-37 shared IMAP credential fields. $mbx (nullable), $isEdit (bool). Password write-only. --}}
@php $mbx = $mbx ?? null; @endphp
<div class="grid grid-cols-1 md:grid-cols-2 gap-3">
    <div>
        <label class="block text-xs font-semibold mb-1" style="color: var(--text-primary, #1f2937);">Email address *</label>
        <input type="email" name="email_address" required value="{{ old('email_address', $mbx->email_address ?? '') }}"
               class="w-full px-3 py-2 text-sm" style="border: 1px solid var(--border, #e5e7eb); border-radius: 6px; background: var(--surface, #fff); color: var(--text-primary, #1f2937);">
        @error('email_address') <p class="text-xs mt-1" style="color: var(--ds-crimson, #dc2626);">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-xs font-semibold mb-1" style="color: var(--text-primary, #1f2937);">Username *</label>
        <input type="text" name="username" required autocomplete="off" value="{{ old('username', $mbx->username ?? '') }}"
               class="w-full px-3 py-2 text-sm" style="border: 1px solid var(--border, #e5e7eb); border-radius: 6px; background: var(--surface, #fff); color: var(--text-primary, #1f2937);">
        @error('username') <p class="text-xs mt-1" style="color: var(--ds-crimson, #dc2626);">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-xs font-semibold mb-1" style="color: var(--text-primary, #1f2937);">IMAP host *</label>
        <input type="text" name="imap_host" required placeholder="imap.example.com" value="{{ old('imap_host', $mbx->imap_host ?? '') }}"
               class="w-full px-3 py-2 text-sm" style="border: 1px solid var(--border, #e5e7eb); border-radius: 6px; background: var(--surface, #fff); color: var(--text-primary, #1f2937);">
        @error('imap_host') <p class="text-xs mt-1" style="color: var(--ds-crimson, #dc2626);">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-xs font-semibold mb-1" style="color: var(--text-primary, #1f2937);">Port *</label>
        <input type="number" name="imap_port" required min="1" max="65535" value="{{ old('imap_port', $mbx->imap_port ?? 993) }}"
               class="w-full px-3 py-2 text-sm" style="border: 1px solid var(--border, #e5e7eb); border-radius: 6px; background: var(--surface, #fff); color: var(--text-primary, #1f2937);">
        @error('imap_port') <p class="text-xs mt-1" style="color: var(--ds-crimson, #dc2626);">{{ $message }}</p> @enderror
    </div>
    <div class="md:col-span-2">
        <label class="block text-xs font-semibold mb-1" style="color: var(--text-primary, #1f2937);">Password {{ $isEdit ? '(leave blank to keep current)' : '*' }}</label>
        <input type="password" name="password" autocomplete="new-password" {{ $isEdit ? '' : 'required' }}
               class="w-full px-3 py-2 text-sm" style="border: 1px solid var(--border, #e5e7eb); border-radius: 6px; background: var(--surface, #fff); color: var(--text-primary, #1f2937);">
        <p class="text-xs mt-1" style="color: var(--text-muted, #6b7280);">Stored encrypted at rest. Never displayed back — use Reveal (logged) to retrieve it.</p>
        @error('password') <p class="text-xs mt-1" style="color: var(--ds-crimson, #dc2626);">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-xs font-semibold mb-1" style="color: var(--text-primary, #1f2937);">Poll interval (minutes) *</label>
        <input type="number" name="poll_interval_minutes" required min="1" max="1440" value="{{ old('poll_interval_minutes', $mbx->poll_interval_minutes ?? 15) }}"
               class="w-full px-3 py-2 text-sm" style="border: 1px solid var(--border, #e5e7eb); border-radius: 6px; background: var(--surface, #fff); color: var(--text-primary, #1f2937);">
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
