@extends('layouts.corex')

@section('corex-content')
<div class="w-full max-w-3xl mx-auto space-y-5">

    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <h1 class="text-xl font-bold tracking-tight text-white">Media Encryption at Rest</h1>
        <p class="text-sm" style="color: rgba(255,255,255,0.65);">AT-173 — client-sensitive files are encrypted on disk with an agency-managed key.</p>
    </div>

    {{-- Status --}}
    <div class="rounded-md p-5 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex items-center justify-between">
            <span class="text-sm font-semibold" style="color: var(--text-secondary);">Encryption</span>
            @if($enabled)
                <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold text-white" style="background:#16a34a;">● ON — encrypting new files</span>
            @else
                <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold text-white" style="background:#dc2626;">● OFF</span>
            @endif
        </div>
        <div class="flex items-center justify-between text-sm">
            <span style="color: var(--text-secondary);">Encryption key configured</span>
            <span style="color: var(--text-primary);">{{ $keyPresent ? 'Yes' : 'No — set MEDIA_ENCRYPTION_KEY' }}</span>
        </div>
        <div class="flex items-center justify-between text-sm">
            <span style="color: var(--text-secondary);">Algorithm</span>
            <span style="color: var(--text-primary);">{{ $algorithm }} (authenticated)</span>
        </div>
    </div>

    {{-- Coverage --}}
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h3 class="text-sm font-bold mb-3" style="color: var(--text-primary);">What is encrypted</h3>
        <ul class="space-y-2 text-sm" style="color: var(--text-secondary);">
            <li>✓ <strong style="color: var(--text-primary);">Communication media</strong> — WhatsApp voice notes &amp; images, email attachments.</li>
            <li>✓ <strong style="color: var(--text-primary);">FICA documents</strong> — ID copies, proof of address, FICA forms ({{ number_format($ficaDocCount) }} on record). Served through a decrypting stream, never a direct link.</li>
        </ul>
        <p class="text-xs mt-3" style="color: var(--text-muted);">Public property/agent marketing photos are intentionally NOT encrypted (they are public by design).</p>
    </div>

    {{-- Migration --}}
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h3 class="text-sm font-bold mb-2" style="color: var(--text-primary);">Encrypt existing files (backfill)</h3>
        <p class="text-sm mb-2" style="color: var(--text-secondary);">New files encrypt automatically. To encrypt files created before this was switched on, run (idempotent, round-trip verified — no data loss):</p>
        <pre class="text-xs rounded-md p-3 overflow-x-auto" style="background: var(--surface-2); color: var(--text-primary);">php artisan media:encrypt-backfill --scope=comms --dry-run
php artisan media:encrypt-backfill --scope=comms
php artisan media:encrypt-backfill --scope=fica  --dry-run
php artisan media:encrypt-backfill --scope=fica</pre>
    </div>

    {{-- Honest scope --}}
    <div class="rounded-md p-4 text-xs" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-muted);">
        <strong style="color: var(--text-secondary);">What this protects (POPIA §19):</strong> a stolen or decommissioned disk, the off-box backups, a database/volume dump, and casual file browsing all yield ciphertext, not client data. The key lives only in this server's environment (never in the repo); it is deliberately separate from the app key. It does not defend a live-root attacker who can read that key from the running server — that is a documented, accepted limit.
    </div>

</div>
@endsection
