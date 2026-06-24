{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — branded header, rounded-md cards, tokens via var(--token, #fallback). --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-6">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div data-tour="portal-comm-capture-intro">
                <h1 class="text-xl font-bold text-white leading-tight">Communication Capture</h1>
                <p class="text-sm text-white/60">Link your mailbox so your client email is captured to the agency archive (a legal 5-year requirement). Your password is stored encrypted and is never shown back to anyone.</p>
            </div>
            <a href="{{ route('agent.portal') }}" class="corex-btn-outline" style="color:#fff; border-color:rgba(255,255,255,0.3);" data-tour="portal-comm-capture-back">My Portal</a>
        </div>
    </div>

    @if(session('success'))
    <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green, #16a34a) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-green, #16a34a) 30%, transparent); color: var(--text-primary, #1f2937);">{{ session('success') }}</div>
    @endif

    <div class="rounded-md p-4 lg:p-6" style="background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);" data-tour="portal-comm-capture-mailbox">
        <h3 class="text-sm font-bold uppercase tracking-wider mb-1" style="color: var(--text-primary, #1f2937);">Email</h3>
        <p class="text-xs mb-4" style="color: var(--text-muted, #6b7280);">Your agency can also set these up for you. Either way the password is write-only — to change it, just enter a new one.</p>
        @include('settings.email-setup._user-mailbox', [
            'user' => $user,
            'ctx'  => [
                'storeUrl'    => route('my-portal.comm-capture.store'),
                'updateName'  => 'my-portal.comm-capture.update',
                'destroyName' => 'my-portal.comm-capture.destroy',
                'allowReveal' => false,
            ],
        ])
    </div>

    {{-- WhatsApp self-service is surfaced here once the WhatsApp capture code
         (AT-34) is integrated via the Staging consolidation — deferred. --}}
</div>
@endsection
