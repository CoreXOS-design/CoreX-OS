@extends('layouts.corex')

@section('corex-content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="rounded-md p-6 text-center"
         style="background: var(--surface); border: 1px solid color-mix(in srgb, var(--ds-green) 50%, var(--border));">
        <h1 class="text-xl font-semibold mb-2" style="color: var(--text-primary);">
            ✓ Pitch recorded
        </h1>
        <p class="text-sm mb-4" style="color: var(--text-secondary);">
            The send was recorded against
            <strong>{{ trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: 'this contact' }}</strong>.
            @if($send->channel === 'whatsapp')
                WhatsApp should have opened in a new tab.
            @else
                A branded email has been sent to <strong>{{ $send->recipient_email_snapshot }}</strong>.
            @endif
        </p>
        @if($send->channel === 'whatsapp' && $clientUrl)
            <p class="text-xs mb-6" style="color: var(--text-muted);">
                If it didn't open automatically:
                <a href="{{ $clientUrl }}" target="corex_whatsapp_web" rel="noopener"
                   style="color: var(--brand-icon, #0ea5e9); text-decoration: underline;">
                    Open WhatsApp manually
                </a>
            </p>
        @endif
        <div class="text-xs space-y-1" style="color: var(--text-muted);">
            <div>Tracking code: <code style="color: var(--text-secondary);">{{ $send->tracking_short_code }}</code></div>
            <div>
                Landing URL:
                <a href="{{ $send->landingUrl() }}" target="_blank" rel="noopener" style="color: var(--brand-icon, #0ea5e9);">
                    {{ $send->landingUrl() }}
                </a>
            </div>
        </div>
        <div class="mt-6 flex items-center justify-center gap-3 flex-wrap">
            <a href="{{ route('corex.contacts.show', $contact) }}"
               class="corex-btn-primary text-sm no-underline">
                Back to contact
            </a>
            <a href="{{ route('seller-outreach.composer.show', $contact) }}"
               class="corex-btn-outline text-sm no-underline">
                Send another
            </a>
        </div>
    </div>
</div>
@endsection
