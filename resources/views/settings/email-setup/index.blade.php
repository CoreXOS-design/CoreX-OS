{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — branded header, rounded-md cards, tokens via var(--token, #fallback). --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-6" x-data>
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Email Setup</h1>
                <p class="text-sm text-white/60">Link each user's mailbox so their email feeds the Communication Archive. Passwords are stored encrypted and never shown — retrieving one is a separate, logged action.</p>
            </div>
            <a href="{{ route('compliance.comm-archive.index') }}" class="corex-btn-outline" style="color:#fff; border-color:rgba(255,255,255,0.3);">View Archive</a>
        </div>
    </div>

    @if(session('success'))
    <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green, #16a34a) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-green, #16a34a) 30%, transparent); color: var(--text-primary, #1f2937);">{{ session('success') }}</div>
    @endif

    @forelse($users as $user)
        <div class="rounded-md p-4" style="background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);">
            <div class="flex items-center justify-between gap-3 mb-3">
                <div>
                    <div class="font-semibold" style="color: var(--text-primary, #1f2937);">{{ $user->name }}</div>
                    <div class="text-xs" style="color: var(--text-muted, #6b7280);">{{ $user->email }} · {{ ucfirst(str_replace('_', ' ', $user->role)) }}</div>
                </div>
                <span class="ds-badge {{ $user->commMailboxes->where('active', true)->count() ? 'ds-badge-success' : 'ds-badge-default' }}">
                    {{ $user->commMailboxes->count() ? $user->commMailboxes->count() . ' mailbox' . ($user->commMailboxes->count() === 1 ? '' : 'es') : 'No capture' }}
                </span>
            </div>
            @include('settings.email-setup._user-mailbox', ['user' => $user])
        </div>
    @empty
        <div class="rounded-md px-4 py-12 text-center text-sm" style="background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); color: var(--text-muted, #6b7280);">
            No active users in this agency yet.
        </div>
    @endforelse
</div>
@endsection
