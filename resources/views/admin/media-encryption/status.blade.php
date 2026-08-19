@extends('layouts.corex-app')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- AT-173 — System Developer → Media Encryption. Read-only status of encryption
     at rest for client-sensitive files (agency-managed key). Moved from Compliance
     to Admin — this reports server-side encryption CONFIGURATION, the same
     category of infra/security status as Backups / Server Health / API. --}}

@php
    $accent = $enabled ? 'var(--ds-green, #059669)' : 'var(--ds-crimson, #c41e3a)';
@endphp

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Media Encryption</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    AT-173 — client-sensitive files are encrypted on disk with an agency-managed key.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-semibold"
                      style="background: color-mix(in srgb, {{ $accent }} 18%, transparent); color: {{ $accent }};">
                    <span style="width:8px;height:8px;border-radius:9999px;background:{{ $accent }};display:inline-block;"></span>
                    {{ $enabled ? 'On — encrypting new files' : 'Off' }}
                </span>
            </div>
        </div>
    </div>

    {{-- ── STATUS / HEALTH ─────────────────────────────────────────── --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
            <h2 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--brand-icon, #0ea5e9);">Status &amp; Health</h2>
        </div>
        <div class="p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
            @php
                $cell = 'flex flex-col gap-1';
                $lbl  = 'text-xs uppercase tracking-wide';
                $lblStyle = 'color: var(--text-muted, #64748b);';
                $val  = 'font-semibold';
            @endphp
            <div class="{{ $cell }}"><span class="{{ $lbl }}" style="{{ $lblStyle }}">Encryption</span>
                <span class="{{ $val }}" style="color: {{ $accent }};">{{ $enabled ? 'ON' : 'OFF' }}</span>
            </div>
            <div class="{{ $cell }}"><span class="{{ $lbl }}" style="{{ $lblStyle }}">Encryption key configured</span>
                <span class="{{ $val }}" style="{{ $keyPresent ? '' : 'color: var(--ds-crimson, #c41e3a);' }}">
                    {{ $keyPresent ? 'Yes' : 'No — set MEDIA_ENCRYPTION_KEY' }}
                </span>
            </div>
            <div class="{{ $cell }}"><span class="{{ $lbl }}" style="{{ $lblStyle }}">Algorithm</span><span class="{{ $val }}">{{ $algorithm }} (authenticated)</span></div>
        </div>
    </div>

    {{-- ── COVERAGE ─────────────────────────────────────────────────── --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
            <h2 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--brand-icon, #0ea5e9);">What is encrypted</h2>
        </div>
        <div class="p-4 text-sm">
            <ul class="space-y-2" style="color: var(--text-secondary);">
                <li>✓ <strong style="color: var(--text-primary);">Communication media</strong> — WhatsApp voice notes &amp; images, email attachments.</li>
                <li>✓ <strong style="color: var(--text-primary);">FICA documents</strong> — ID copies, proof of address, FICA forms ({{ number_format($ficaDocCount) }} on record). Served through a decrypting stream, never a direct link.</li>
            </ul>
            <p class="text-xs mt-3" style="color: var(--text-muted, #64748b);">Public property/agent marketing photos are intentionally NOT encrypted (they are public by design).</p>
        </div>
    </div>

    {{-- ── MIGRATION / BACKFILL ────────────────────────────────────── --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
            <h2 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--brand-icon, #0ea5e9);">Encrypt existing files (backfill)</h2>
        </div>
        <div class="p-4 text-sm">
            <p class="mb-2" style="color: var(--text-secondary);">New files encrypt automatically. To encrypt files created before this was switched on, run (idempotent, round-trip verified — no data loss):</p>
            <pre class="text-xs rounded-md p-3 overflow-x-auto font-mono" style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">php artisan media:encrypt-backfill --scope=comms --dry-run
php artisan media:encrypt-backfill --scope=comms
php artisan media:encrypt-backfill --scope=fica  --dry-run
php artisan media:encrypt-backfill --scope=fica</pre>
        </div>
    </div>

    {{-- ── HONEST SCOPE ─────────────────────────────────────────────── --}}
    <div class="rounded-md px-4 py-3 text-xs" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 10%, transparent); color: var(--text-secondary); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 28%, transparent);">
        <strong style="color: var(--ds-amber, #f59e0b);">What this protects (POPIA §19):</strong> a stolen or decommissioned disk, the off-box backups, a database/volume dump, and casual file browsing all yield ciphertext, not client data. The key lives only in this server's environment (never in the repo); it is deliberately separate from the app key. It does not defend a live-root attacker who can read that key from the running server — that is a documented, accepted limit.
    </div>

</div>
@endsection
