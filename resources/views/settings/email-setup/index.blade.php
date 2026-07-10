{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — branded header, rounded-md cards, tokens via var(--token, #fallback). --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5" x-data>
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Email Setup</h1>
                <p class="text-sm text-white/60">Link each user's mailbox so their email feeds the Communication Archive. Passwords are stored encrypted and never shown — retrieving one is a separate, logged action.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('compliance.comm-archive.index') }}" class="corex-btn-outline text-sm"
                   style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">View Archive</a>
            </div>
        </div>
    </div>

    @if(session('success'))
    <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
         style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent); color: var(--text-primary, #1f2937);">
        <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green, #059669);" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
        </svg>
        <div class="flex-1">{{ session('success') }}</div>
    </div>
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
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary, #111827);">No active users yet</h3>
            <p class="text-sm" style="color: var(--text-muted, #6b7280);">Once this agency has active users, link each mailbox here to feed the Communication Archive.</p>
        </div>
    @endforelse
</div>
@endsection
