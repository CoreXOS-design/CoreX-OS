{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
@php $isEdit = $mailbox->exists; @endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $isEdit ? 'Edit Mailbox' : 'Add Mailbox' }}</h1>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
                <a href="{{ route('compliance.comm-mailboxes.index') }}" class="corex-btn-outline text-xs shrink-0">&larr; Mailboxes</a>
            </div>
        </div>
    </div>

    <div>
        <div class="max-w-2xl">
            <form method="POST" action="{{ $isEdit ? route('compliance.comm-mailboxes.update', $mailbox) : route('compliance.comm-mailboxes.store') }}" class="rounded-md p-5 lg:p-6 space-y-5" style="background: var(--surface); border: 1px solid var(--border);">
                @csrf
                @if($isEdit) @method('PUT') @endif

                <div>
                    <label class="block text-sm font-semibold mb-1" style="color: var(--text-primary);">Email Address *</label>
                    <input type="email" name="email_address" value="{{ old('email_address', $mailbox->email_address) }}" required
                           class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    @error('email_address') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-semibold mb-1" style="color: var(--text-primary);">IMAP Host *</label>
                        <input type="text" name="imap_host" value="{{ old('imap_host', $mailbox->imap_host) }}" required placeholder="imap.example.com"
                               class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        @error('imap_host') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1" style="color: var(--text-primary);">Port *</label>
                        <input type="number" name="imap_port" value="{{ old('imap_port', $mailbox->imap_port ?? 993) }}" required
                               class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        @error('imap_port') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-1" style="color: var(--text-primary);">Username *</label>
                    <input type="text" name="username" value="{{ old('username', $mailbox->username) }}" required autocomplete="off"
                           class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    @error('username') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-1" style="color: var(--text-primary);">Password {{ $isEdit ? '(leave blank to keep current)' : '*' }}</label>
                    <input type="password" name="password" autocomplete="new-password" {{ $isEdit ? '' : 'required' }}
                           class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    <p class="text-xs mt-1" style="color:var(--text-muted);">Stored encrypted at rest. Never displayed back.</p>
                    @error('password') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold mb-1" style="color: var(--text-primary);">Poll interval (minutes) *</label>
                        <input type="number" name="poll_interval_minutes" value="{{ old('poll_interval_minutes', $mailbox->poll_interval_minutes ?? 15) }}" required min="1" max="1440"
                               class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        @error('poll_interval_minutes') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex flex-col justify-end gap-2">
                        <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);">
                            <input type="checkbox" name="poll_inbox" value="1" {{ old('poll_inbox', $mailbox->poll_inbox ?? true) ? 'checked' : '' }} style="accent-color:var(--brand-icon);"> Poll Inbox (inbound)
                        </label>
                        <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);">
                            <input type="checkbox" name="poll_sent" value="1" {{ old('poll_sent', $mailbox->poll_sent ?? true) ? 'checked' : '' }} style="accent-color:var(--brand-icon);"> Poll Sent (outbound)
                        </label>
                        <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);">
                            <input type="checkbox" name="active" value="1" {{ old('active', $mailbox->active ?? true) ? 'checked' : '' }} style="accent-color:var(--brand-icon);"> Active
                        </label>
                    </div>
                </div>

                <hr style="border-color: var(--border);">

                {{-- AT-395 §7.1 — outgoing mail. --}}
                <div>
                    <h2 class="text-sm font-bold mb-1" style="color: var(--text-primary);">Outgoing mail (SMTP)</h2>
                    <p class="text-xs mb-3" style="color: var(--text-muted);">Send e-sign invitations through this mailbox's own mail server, so receiving mail servers trust the sender and the message lands in this mailbox's own Sent folder. Off by default — leave off and nothing changes.</p>

                    <label class="flex items-center gap-2 text-sm mb-3" style="color:var(--text-primary);">
                        <input type="checkbox" id="outgoing_enabled" name="outgoing_enabled" value="1" {{ old('outgoing_enabled', $mailbox->outgoing_enabled ?? false) ? 'checked' : '' }} style="accent-color:var(--brand-icon);" onchange="document.getElementById('at395-outgoing-fields').style.display = this.checked ? 'block' : 'none';">
                        Send outgoing mail through this mailbox
                    </label>

                    <div id="at395-outgoing-fields" style="display: {{ old('outgoing_enabled', $mailbox->outgoing_enabled ?? false) ? 'block' : 'none' }};" class="space-y-4 pl-1">
                        <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);">
                            <input type="checkbox" name="use_imap_credentials_for_smtp" value="1" {{ old('use_imap_credentials_for_smtp', $mailbox->use_imap_credentials_for_smtp ?? true) ? 'checked' : '' }} style="accent-color:var(--brand-icon);">
                            Use the same username and password as above
                        </label>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-semibold mb-1" style="color: var(--text-primary);">SMTP Host *</label>
                                <input type="text" name="smtp_host" value="{{ old('smtp_host', $mailbox->smtp_host) }}" placeholder="mail.example.com"
                                       class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                @error('smtp_host') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-semibold mb-1" style="color: var(--text-primary);">Port</label>
                                <input type="number" name="smtp_port" value="{{ old('smtp_port', $mailbox->smtp_port ?? 587) }}"
                                       class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold mb-1" style="color: var(--text-primary);">Encryption</label>
                                <select name="smtp_encryption" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                    @foreach(['tls' => 'TLS (STARTTLS, port 587 — most common)', 'ssl' => 'SSL (port 465)', 'none' => 'None'] as $val => $label)
                                        <option value="{{ $val }}" @selected(old('smtp_encryption', $mailbox->smtp_encryption ?? 'tls') === $val)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold mb-1" style="color: var(--text-primary);">From name override</label>
                                <input type="text" name="smtp_from_name" value="{{ old('smtp_from_name', $mailbox->smtp_from_name) }}" placeholder="{{ $mailbox->user->name ?? 'Agent name' }}"
                                       class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold mb-1" style="color: var(--text-primary);">SMTP username (only if different from above)</label>
                            <input type="text" name="smtp_username" value="{{ old('smtp_username', $mailbox->smtp_username) }}" autocomplete="off"
                                   class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1" style="color: var(--text-primary);">SMTP password (only if different from above)</label>
                            <input type="password" name="smtp_password" autocomplete="new-password"
                                   class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                            <p class="text-xs mt-1" style="color:var(--text-muted);">Stored encrypted at rest. Never displayed back.</p>
                        </div>

                        <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);">
                            <input type="checkbox" name="outgoing_active" value="1" {{ old('outgoing_active', $mailbox->outgoing_active ?? true) ? 'checked' : '' }} style="accent-color:var(--brand-icon);"> Outgoing active
                        </label>

                        @if($isEdit)
                            @php
                                $sh = $mailbox->sendHealth();
                            @endphp
                            <p class="text-xs" style="color: {{ $sh === 'failing' ? 'var(--ds-crimson)' : 'var(--text-muted)' }};">
                                Send health: <strong>{{ ucfirst($sh) }}</strong>@if($sh === 'failing' && $mailbox->lastSendErrorLabel()) — {{ $mailbox->lastSendErrorLabel() }}@endif
                            </p>
                        @endif
                    </div>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="corex-btn-primary text-sm">{{ $isEdit ? 'Save Mailbox' : 'Add Mailbox' }}</button>
                    <a href="{{ route('compliance.comm-mailboxes.index') }}" class="corex-btn-outline text-sm">Cancel</a>
                </div>
            </form>

            {{-- AT-395 §6 — separate form so the edit form's PUT method-spoof never leaks into this POST. --}}
            @if($isEdit)
                <form method="POST" action="{{ route('compliance.comm-mailboxes.test-connection', $mailbox) }}" class="mt-3">
                    @csrf
                    <button type="submit" class="corex-btn-outline text-sm">Test Connection (both legs)</button>
                </form>

                @if(session('test_connection_result'))
                    @php
                        $tc = session('test_connection_result');
                    @endphp
                    <div class="mt-3 rounded-md px-4 py-3 text-sm space-y-1" style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-primary);">
                        <div><strong>SMTP send:</strong> <span style="color: {{ $tc['smtp']['ok'] ? 'var(--ds-green)' : 'var(--ds-crimson)' }};">{{ $tc['smtp']['ok'] ? 'Pass' : 'Fail' }}</span> — {{ $tc['smtp']['message'] }}</div>
                        <div><strong>Sent-folder write:</strong> <span style="color: {{ $tc['imap_append']['ok'] ? 'var(--ds-green)' : 'var(--ds-crimson)' }};">{{ $tc['imap_append']['ok'] ? 'Pass' : 'Fail' }}</span> — {{ $tc['imap_append']['message'] }}</div>
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
@endsection
