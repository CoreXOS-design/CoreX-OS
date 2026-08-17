{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
@php $isConfigured = $setting->isConfigured(); @endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">P24 IMAP Settings</h1>
                <p class="text-xs mt-1" style="color: var(--text-secondary);">Your own Property24 alert-email mailbox — used to import P24 listing alerts into Market Pulse.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="ds-badge {{ $isConfigured ? 'ds-badge-success' : 'ds-badge-warning' }}">
                    {{ $isConfigured ? 'Configured' : 'Not configured' }}
                </span>
                <a href="{{ route('corex.settings') }}" class="corex-btn-outline text-xs shrink-0">&larr; Settings</a>
            </div>
        </div>
    </div>

    @if(session('success'))
    <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-emerald, #10b981) 12%, transparent); border: 1px solid var(--ds-emerald, #10b981); color: var(--text-primary);">
        {{ session('success') }}
    </div>
    @endif

    <div class="max-w-2xl">
        <form method="POST" action="{{ route('admin.p24-imap-settings.update') }}" class="rounded-md p-5 lg:p-6 space-y-5" style="background: var(--surface); border: 1px solid var(--border);">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-semibold mb-1" style="color: var(--text-primary);">IMAP Host *</label>
                    <input type="text" name="imap_host" value="{{ old('imap_host', $setting->imap_host) }}" required placeholder="imap.example.com"
                           class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    @error('imap_host') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color: var(--text-primary);">Port *</label>
                    <input type="number" name="imap_port" value="{{ old('imap_port', $setting->imap_port ?? 993) }}" required
                           class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    @error('imap_port') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color: var(--text-primary);">Encryption *</label>
                    <select name="imap_encryption" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        @foreach(['ssl' => 'SSL', 'tls' => 'TLS', 'notls' => 'None'] as $val => $label)
                        <option value="{{ $val }}" {{ old('imap_encryption', $setting->imap_encryption ?? 'ssl') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('imap_encryption') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color: var(--text-primary);">Folder *</label>
                    <input type="text" name="imap_folder" value="{{ old('imap_folder', $setting->imap_folder ?? 'INBOX') }}" required
                           class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    @error('imap_folder') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="block text-sm font-semibold mb-1" style="color: var(--text-primary);">Username *</label>
                <input type="text" name="username" value="{{ old('username', $setting->username) }}" required autocomplete="off"
                       class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                @error('username') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-semibold mb-1" style="color: var(--text-primary);">Password {{ $setting->exists ? '(leave blank to keep current)' : '*' }}</label>
                <input type="password" name="password" autocomplete="new-password" {{ $setting->exists ? '' : 'required' }}
                       class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <p class="text-xs mt-1" style="color:var(--text-muted);">Stored encrypted at rest. Never displayed back.</p>
                @error('password') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);">
                    <input type="checkbox" name="active" value="1" {{ old('active', $setting->active ?? true) ? 'checked' : '' }} style="accent-color:var(--brand-icon);"> Active
                </label>
                <p class="text-xs mt-1" style="color:var(--text-muted);">P24 alert emails are only imported into Market Pulse while this is active and configured.</p>
            </div>

            @if($setting->exists && $setting->last_error)
            <div class="rounded-md px-3 py-2 text-xs" style="background: color-mix(in srgb, var(--ds-crimson, #dc2626) 10%, transparent); border: 1px solid var(--ds-crimson, #dc2626); color: var(--text-primary);">
                Last import error: {{ $setting->last_error }} ({{ $setting->last_error_at?->diffForHumans() }})
            </div>
            @endif

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="corex-btn-primary text-sm">Save P24 IMAP Settings</button>
                <a href="{{ route('corex.settings') }}" class="corex-btn-outline text-sm">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
