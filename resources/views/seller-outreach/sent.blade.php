@extends('layouts.corex')

@php
    $recipientName = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: 'this contact';
    $isWhatsapp = $send->channel === 'whatsapp';
@endphp

@section('corex-content')
{{-- AT-323 — the outreach pitch-send no longer CLAIMS "sent" on page load. For a
     WhatsApp pitch (client-side, no delivery signal) CoreX opened WhatsApp on the
     composer and now ASKS whether it actually went, via the SAME shared confirm
     modal used by the contact quick-send. "Yes" leaves it sent; "No" records the
     honest not_sent outcome (+ mirrors not_delivered to the linked communication).
     Email is genuinely system-sent, so it still reports sent truthfully. --}}
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6"
     x-data="{
        answered: {{ $isWhatsapp ? 'false' : 'true' }},
        result: @js($isWhatsapp ? null : 'sent'),
        sentConfirm: { open: {{ $isWhatsapp ? 'true' : 'false' }} },
        async confirmSent(didSend) {
            this.sentConfirm.open = false;
            this.answered = true;
            this.result = didSend ? 'sent' : 'not_sent';
            // AT-323 — "Yes" promotes the mirrored comm to sent (the ONLY path a pitch counts);
            // "No" records the honest not_sent. Either way the send never counts before this answer.
            const url = didSend
                ? @js(route('seller-outreach.composer.mark-sent', ['contact' => $contact->id, 'send' => $send->id]))
                : @js(route('seller-outreach.composer.not-sent', ['contact' => $contact->id, 'send' => $send->id]));
            try {
                await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()), 'X-Requested-With': 'XMLHttpRequest' },
                    body: '{}'
                });
            } catch (e) { /* keep local state; server is source of truth on next load */ }
        }
     }">

    <div class="rounded-md p-6 text-center"
         style="background: var(--surface); border: 1px solid color-mix(in srgb, var(--ds-green) 50%, var(--border));">

        @if($isWhatsapp)
            {{-- Awaiting the agent's confirmation (modal open, or re-openable). --}}
            <template x-if="!answered">
                <div>
                    <h1 class="text-xl font-semibold mb-2" style="color: var(--text-primary);">
                        Confirm your WhatsApp to {{ $recipientName }}
                    </h1>
                    <p class="text-sm mb-4" style="color: var(--text-secondary);">
                        CoreX opened WhatsApp for <strong>{{ $recipientName }}</strong>. It can't tell whether
                        the message actually went — please confirm so we only record a send that really happened.
                    </p>
                    <button type="button" @click="sentConfirm.open = true"
                            class="px-4 py-2 text-sm font-semibold rounded" style="background: #00d4aa; color: #003a2f;">
                        Confirm send status
                    </button>
                </div>
            </template>

            {{-- Confirmed sent. --}}
            <template x-if="answered && result === 'sent'">
                <div>
                    <h1 class="text-xl font-semibold mb-2" style="color: var(--text-primary);">✓ Pitch sent</h1>
                    <p class="text-sm mb-4" style="color: var(--text-secondary);">
                        Recorded as sent to <strong>{{ $recipientName }}</strong>.
                    </p>
                </div>
            </template>

            {{-- Confirmed NOT sent. --}}
            <template x-if="answered && result === 'not_sent'">
                <div>
                    <h1 class="text-xl font-semibold mb-2" style="color: var(--text-primary);">Recorded as not sent</h1>
                    <p class="text-sm mb-4" style="color: var(--text-secondary);">
                        Noted — this pitch to <strong>{{ $recipientName }}</strong> is <strong>not</strong> counted as sent.
                        @if($clientUrl)
                            You can
                            <a href="{{ $clientUrl }}" target="corex_whatsapp_web" rel="noopener"
                               style="color: #00d4aa; text-decoration: underline;">open WhatsApp and try again</a>.
                        @endif
                    </p>
                </div>
            </template>

            @if($clientUrl)
                <p class="text-xs mb-6" style="color: var(--text-muted);" x-show="!answered">
                    Didn't WhatsApp open?
                    <a href="{{ $clientUrl }}" target="corex_whatsapp_web" rel="noopener"
                       style="color: #00d4aa; text-decoration: underline;">Open WhatsApp manually</a>
                </p>
            @endif
        @else
            {{-- Email is sent by CoreX itself (branded HTML) — a real, confirmed send. --}}
            <h1 class="text-xl font-semibold mb-2" style="color: var(--text-primary);">✓ Pitch sent</h1>
            <p class="text-sm mb-4" style="color: var(--text-secondary);">
                A branded email has been sent to <strong>{{ $send->recipient_email_snapshot }}</strong>.
            </p>
        @endif

        <div class="text-xs space-y-1" style="color: var(--text-muted);">
            <div>Tracking code: <code style="color: var(--text-secondary);">{{ $send->tracking_short_code }}</code></div>
            <div>
                Landing URL:
                <a href="{{ $send->landingUrl() }}" target="_blank" rel="noopener" style="color: #00d4aa;">
                    {{ $send->landingUrl() }}
                </a>
            </div>
        </div>

        <div class="mt-6 flex items-center justify-center gap-3 flex-wrap">
            <a href="{{ route('corex.contacts.show', $contact) }}"
               class="px-4 py-2 text-sm font-semibold rounded"
               style="background: #00d4aa; color: #003a2f;">
                Back to contact
            </a>
            <a href="{{ route('seller-outreach.composer.show', $contact) }}"
               class="px-4 py-2 text-sm rounded"
               style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">
                Send another
            </a>
        </div>
    </div>

    {{-- AT-323 — SHARED confirm modal (identical component to the contact quick-send). --}}
    @if($isWhatsapp)
        @include('partials.whatsapp-send-confirm-modal')
    @endif
</div>
@endsection
