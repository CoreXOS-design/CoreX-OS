@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-6">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);" data-tour="comms-wa-devices-intro">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">WhatsApp Capture</h1>
                <p class="text-sm text-white/60">Register the device that runs the read-only capture extension. Business WhatsApp conversations with loaded contacts are archived for compliance.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @include('layouts.partials.tour-header-launcher')
            </div>
        </div>
    </div>

    @if(session('success'))
    <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif

    {{-- AT-135 — agency-wide read-only body backfill toggle (admin/owner only).
         Default ON. OFF keeps capture strictly passive/live-only (ToS risk control). --}}
    <div class="rounded-md p-4 flex items-start justify-between gap-4" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="min-w-0">
            <div class="text-sm font-semibold" style="color: var(--text-primary);">Message-body backfill (read-only)</div>
            <p class="text-xs mt-1" style="color: var(--text-muted);">
                WhatsApp stores message bodies encrypted on the device, so older/unopened chats archive with the body pending. When ON, the extension — only while you're idle, and strictly read-only (it opens &amp; reads chats, never sends) — fills those bodies so business WhatsApp is fully retained for FICA. Turn OFF to capture live messages only.
            </p>
            <p class="text-xs mt-1 font-semibold" style="color: {{ $backfillEnabled ? 'var(--ds-green)' : 'var(--text-muted)' }};">
                Currently {{ $backfillEnabled ? 'ON' : 'OFF' }} for this agency.
            </p>
        </div>
        @if($canManageBackfill)
        <form method="POST" action="{{ route('communications.wa-devices.backfill-toggle') }}" class="shrink-0">
            @csrf
            <input type="hidden" name="enabled" value="{{ $backfillEnabled ? '0' : '1' }}">
            <button type="submit" class="text-xs font-semibold rounded px-3 py-2"
                    style="background: {{ $backfillEnabled ? 'var(--surface-2)' : 'var(--brand-button, #0ea5e9)' }}; color: {{ $backfillEnabled ? 'var(--text-secondary)' : '#fff' }}; border:1px solid var(--border);"
                    onclick="return confirm('{{ $backfillEnabled ? 'Turn OFF body backfill? Only live messages will be captured.' : 'Turn ON read-only body backfill for this agency?' }}')">
                {{ $backfillEnabled ? 'Turn OFF' : 'Turn ON' }}
            </button>
        </form>
        @endif
    </div>

    {{-- AT-168 Part B — consent-embargo retention window (admin/owner only).
         A message captured while capture-consent is pending is stored embargoed
         (never shown) so a later opt-in releases it instantly; if consent is never
         granted the body is purged after this many days (POPIA). --}}
    <div class="rounded-md p-4 flex items-start justify-between gap-4" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="min-w-0">
            <div class="text-sm font-semibold" style="color: var(--text-primary);">Pending-consent embargo retention</div>
            <p class="text-xs mt-1" style="color: var(--text-muted);">
                Messages captured before you opt in to a contact are held privately (never displayed) so opting in later reveals the full history. If consent is never given, the held body is permanently purged after this window — only the FICA envelope (who/when) is kept.
            </p>
            <p class="text-xs mt-1 font-semibold" style="color: var(--text-secondary);">
                Currently {{ (int) $embargoRetentionDays }} day{{ (int) $embargoRetentionDays === 1 ? '' : 's' }}.
            </p>
        </div>
        @if($canManageBackfill)
        <form method="POST" action="{{ route('communications.wa-devices.embargo-retention') }}" class="shrink-0 flex items-center gap-2">
            @csrf
            <input type="number" name="days" min="1" max="365" value="{{ (int) $embargoRetentionDays }}"
                   class="w-20 text-xs rounded px-2 py-2" style="background: var(--surface-2); color: var(--text-primary); border:1px solid var(--border);">
            <button type="submit" class="text-xs font-semibold rounded px-3 py-2"
                    style="background: var(--brand-button, #0ea5e9); color:#fff; border:1px solid var(--border);">
                Save
            </button>
        </form>
        @endif
    </div>

    @if($plainToken)
    <div class="rounded-md p-4" style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 35%, transparent); color: var(--text-primary);">
        <div class="text-sm font-semibold mb-1">Your device token (shown once)</div>
        <code class="block text-xs p-2 break-all" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary);">{{ $plainToken }}</code>
        <p class="text-xs mt-2" style="color:#a16207;">Paste this into the WhatsApp Capture extension now. For security it will not be shown again — revoke and re-register if you lose it.</p>
    </div>
    @endif

    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="POST" action="{{ route('communications.wa-devices.store') }}" class="flex flex-wrap items-end gap-3" data-tour="comms-wa-devices-register">
            @csrf
            <div class="flex-1 min-w-[220px]">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">WhatsApp number (optional)</label>
                <input type="text" name="wa_number" placeholder="e.g. 0821234567" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <button type="submit" class="corex-btn-primary">Register Device &amp; Issue Token</button>
        </form>
        <p class="text-xs mt-3" style="color: var(--text-muted);" data-tour="comms-wa-devices-extension">
            Download the extension: <a href="{{ asset('downloads/wa-capture-extension.zip') }}" style="color: var(--brand-icon);">wa-capture-extension.zip</a>
            — unzip, load it as an unpacked extension in Chrome, open the popup, set your CoreX URL + this token.
        </p>
    </div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);" data-tour="comms-wa-devices-table">
        <table class="min-w-full text-sm ds-table">
            <thead>
                <tr style="background: var(--surface-2);">
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Number</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Registered</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Last seen</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                    <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($devices as $d)
                <tr style="border-top: 1px solid var(--border);">
                    <td class="px-4 py-3" style="color: var(--text-primary);">{{ $d->wa_number ?: '—' }}</td>
                    <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $d->created_at?->format('d M Y') }}</td>
                    <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $d->last_seen_at?->diffForHumans() ?? 'never' }}</td>
                    <td class="px-4 py-3"><span class="ds-badge {{ $d->active ? 'ds-badge-success' : 'ds-badge-default' }}">{{ $d->active ? 'Active' : 'Revoked' }}</span></td>
                    <td class="px-4 py-3 text-right">
                        @if($d->active)
                        <form method="POST" action="{{ route('communications.wa-devices.destroy', $d) }}" class="inline" onsubmit="return confirm('Revoke this device? The extension on it will stop capturing.');">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs font-semibold" style="color: var(--ds-crimson);">Revoke</button>
                        </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">No devices registered yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
