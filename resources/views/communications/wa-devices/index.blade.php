@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-6">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);" data-tour="comms-wa-devices-intro">
        <h1 class="text-xl font-bold text-white leading-tight">WhatsApp Capture</h1>
        <p class="text-sm text-white/60">Register the device that runs the read-only capture extension. Business WhatsApp conversations with loaded contacts are archived for compliance.</p>
    </div>

    @if(session('success'))
    <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif

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
