{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
@php $isEdit = $mailbox->exists; @endphp
<div class="-m-4 lg:-m-6">
    <x-page-header :title="$isEdit ? 'Edit Mailbox' : 'Add Mailbox'" :back-route="route('compliance.comm-mailboxes.index')" back-label="Mailboxes" :flush="true" />

    <div class="p-4 lg:p-6">
        <div class="max-w-2xl">
            <form method="POST" action="{{ $isEdit ? route('compliance.comm-mailboxes.update', $mailbox) : route('compliance.comm-mailboxes.store') }}" class="space-y-5">
                @csrf
                @if($isEdit) @method('PUT') @endif

                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Email Address *</label>
                    <input type="email" name="email_address" value="{{ old('email_address', $mailbox->email_address) }}" required
                           class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    @error('email_address') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">IMAP Host *</label>
                        <input type="text" name="imap_host" value="{{ old('imap_host', $mailbox->imap_host) }}" required placeholder="imap.example.com"
                               class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        @error('imap_host') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Port *</label>
                        <input type="number" name="imap_port" value="{{ old('imap_port', $mailbox->imap_port ?? 993) }}" required
                               class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        @error('imap_port') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Username *</label>
                    <input type="text" name="username" value="{{ old('username', $mailbox->username) }}" required autocomplete="off"
                           class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    @error('username') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Password {{ $isEdit ? '(leave blank to keep current)' : '*' }}</label>
                    <input type="password" name="password" autocomplete="new-password" {{ $isEdit ? '' : 'required' }}
                           class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <p class="text-xs mt-1" style="color:var(--text-muted);">Stored encrypted at rest. Never displayed back.</p>
                    @error('password') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Poll interval (minutes) *</label>
                        <input type="number" name="poll_interval_minutes" value="{{ old('poll_interval_minutes', $mailbox->poll_interval_minutes ?? 15) }}" required min="1" max="1440"
                               class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
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

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="corex-btn-primary">{{ $isEdit ? 'Save Mailbox' : 'Add Mailbox' }}</button>
                    <a href="{{ route('compliance.comm-mailboxes.index') }}" class="corex-btn-outline text-sm">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
