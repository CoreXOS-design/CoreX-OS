{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
     Spec: .ai/specs/system-updates-bulk-email.md §5 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">System Updates</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    Send a branded email to every CoreX user, or to one specific agency — for
                    example a maintenance-window notice or an agency-specific announcement.
                </p>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background:color-mix(in srgb, var(--ds-red, #ef4444) 12%, transparent); color:var(--ds-red, #ef4444); border:1px solid color-mix(in srgb, var(--ds-red, #ef4444) 30%, transparent);">
            {{ session('error') }}
        </div>
    @endif

    {{-- Top-level tabs: Updates | Bulk Email (this page) --}}
    @php
        $suTabOn  = 'background:color-mix(in srgb, var(--brand-icon) 10%, transparent); border:1px solid color-mix(in srgb, var(--brand-icon) 40%, transparent); color:var(--brand-icon);';
        $suTabOff = 'background:transparent; border:1px solid var(--border); color:var(--text-secondary);';
    @endphp
    <div class="flex items-center gap-2 text-xs">
        <a href="{{ route('admin.system-updates.index') }}"
           class="px-3 py-1.5 rounded-md no-underline font-semibold"
           style="{{ $suTabOff }}">
            Updates
        </a>
        <a href="{{ route('admin.system-updates.bulk-email.create') }}"
           class="px-3 py-1.5 rounded-md no-underline font-semibold"
           style="{{ $suTabOn }}">
            Bulk Email
        </a>
    </div>

    {{-- Compose form --}}
    <div class="rounded-md p-6" style="background:var(--surface); border:1px solid var(--border);">
        <form method="POST" action="{{ route('admin.system-updates.bulk-email.send') }}"
              id="bulk-email-form"
              onsubmit="return confirmBulkEmailSend(event);">
            @csrf

            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Send to</label>
                    <select name="target_type" id="bulk-email-target" class="px-3 py-2 rounded-md text-sm" style="max-width:420px; background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                            onchange="document.getElementById('bulk-email-agency-wrap').style.display = this.value === 'agency' ? 'block' : 'none';">
                        <option value="all"
                                data-count="{{ $totalActiveUsers }}"
                                data-label="All CoreX Users"
                                {{ old('target_type', 'all') === 'all' ? 'selected' : '' }}>
                            All CoreX Users ({{ number_format($totalActiveUsers) }})
                        </option>
                        <option value="agency"
                                {{ old('target_type') === 'agency' ? 'selected' : '' }}
                                @if($agencies->isEmpty()) disabled @endif>
                            Specific agency&hellip;
                        </option>
                    </select>
                </div>

                <div id="bulk-email-agency-wrap" style="display:{{ old('target_type') === 'agency' ? 'block' : 'none' }};">
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Agency</label>
                    <select name="target_agency_id" id="bulk-email-agency" class="px-3 py-2 rounded-md text-sm" style="max-width:420px; background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        @foreach($agencies as $agency)
                            <option value="{{ $agency->id }}"
                                    data-count="{{ $agency->users_count }}"
                                    data-label="{{ $agency->name }}"
                                    {{ (int) old('target_agency_id') === $agency->id ? 'selected' : '' }}
                                    @if($agency->users_count === 0) disabled @endif>
                                {{ $agency->name }} ({{ number_format($agency->users_count) }} user{{ $agency->users_count === 1 ? '' : 's' }})
                            </option>
                        @endforeach
                    </select>
                    @error('target_agency_id')
                        <p class="text-xs mt-1" style="color:var(--ds-red, #ef4444);">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Subject</label>
                    <input type="text" name="subject" maxlength="200"
                           value="{{ old('subject') }}" placeholder="e.g. CoreX maintenance tonight at 22:00"
                           class="w-full px-3 py-2 rounded-md text-sm"
                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    @error('subject')
                        <p class="text-xs mt-1" style="color:var(--ds-red, #ef4444);">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Message</label>
                    <textarea name="body" rows="8" maxlength="5000"
                              placeholder="Write the message users will read in the email."
                              class="w-full px-3 py-2 rounded-md text-sm"
                              style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">{{ old('body') }}</textarea>
                    <p class="text-xs mt-1" style="color:var(--text-secondary);">
                        Plain text. Line breaks are kept; formatting and HTML are not.
                    </p>
                    @error('body')
                        <p class="text-xs mt-1" style="color:var(--ds-red, #ef4444);">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <button type="submit" id="bulk-email-submit" class="corex-btn-primary text-xs">Send</button>
                </div>
            </div>
        </form>
    </div>

    {{-- Recent broadcasts — the audit trail. No edit/resend: an email that was
         sent already happened (spec §5.2 step 7). --}}
    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="background:var(--surface-2); color:var(--text-secondary);">
                    <th class="text-left px-4 py-3 font-semibold">Subject</th>
                    <th class="text-left px-4 py-3 font-semibold">Sent to</th>
                    <th class="text-left px-4 py-3 font-semibold">Recipients</th>
                    <th class="text-left px-4 py-3 font-semibold">Sent by</th>
                    <th class="text-left px-4 py-3 font-semibold">Sent at</th>
                </tr>
            </thead>
            <tbody>
            @forelse($broadcasts as $broadcast)
                <tr style="border-top:1px solid var(--border); color:var(--text-primary);">
                    <td class="px-4 py-3 font-semibold">{{ $broadcast->subject }}</td>
                    <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $broadcast->targetLabel() }}</td>
                    <td class="px-4 py-3" style="color:var(--text-secondary);">{{ number_format($broadcast->recipient_count) }}</td>
                    <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $broadcast->senderName() }}</td>
                    <td class="px-4 py-3 whitespace-nowrap" style="color:var(--text-secondary);">{{ $broadcast->created_at->format('d M Y, H:i') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center" style="color:var(--text-secondary);">
                        No bulk emails sent yet.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div>{{ $broadcasts->links() }}</div>
</div>

<script>
    // Confirmation before an irreversible bulk send, naming the exact recipient
    // count for the currently selected target (spec §5.2 step 5). This is the
    // established confirm() pattern used elsewhere on this page (e.g. Re-notify).
    function confirmBulkEmailSend(event) {
        const targetSelect = document.getElementById('bulk-email-target');
        const isAgency     = targetSelect.value === 'agency';
        const activeSelect = isAgency ? document.getElementById('bulk-email-agency') : targetSelect;
        const option       = activeSelect.options[activeSelect.selectedIndex];
        const count        = option ? option.getAttribute('data-count') : null;
        const label        = option ? option.getAttribute('data-label') : (isAgency ? '' : 'All CoreX Users');

        if (isAgency && (!activeSelect.value || count === '0')) {
            alert('Choose an agency with at least one active user.');
            event.preventDefault();
            return false;
        }

        const ok = confirm(
            'This will email ' + (count ?? '0') + ' user(s) — ' + label + '. This cannot be undone. Send now?'
        );

        if (!ok) {
            event.preventDefault();
            return false;
        }

        document.getElementById('bulk-email-submit').disabled = true;
        return true;
    }
</script>
@endsection
