{{--
    Buyer-level Share button — WhatsApp / Email / Client Page (Johan,
    2026-08-24: sharing is buyer-level, not per-wishlist — these three
    duplicated on every wishlist card before this). Print/PDF and Edit stay
    on each wishlist card (wishlist-specific).

    Copy-adapted from corex.contacts._match-action-bar's WhatsApp/Email logic
    (same did-you-send modal, same outreach-queue-add, same communication
    increment so the contact's WA/email counters still update) rather than
    parametrizing that partial further — this button targets
    $contact->clientPageUrl() (buyer-level), not a single wishlist's
    sharedUrl(), and sits in the page banner's on-brand button styling
    (corex-btn-*), not the card's --match-action-bar-* custom properties.

    Required: $buyer  App\Models\Contact
--}}
@php
    $defaultWaMsg = \App\Models\PerformanceSetting::get('matches_wa_message',
        "Hi {name}! \xf0\x9f\x91\x8b\n\nI've put together a personalised selection of properties that match your search criteria.\n\nView your property matches here:\n{link}\n\nFeel free to reach out if you'd like to arrange viewings or have any questions!"
    );
    $waPhoneRecord = $buyer->whatsAppPhone();
    $waPhone = \App\Support\WhatsAppNumberFormatter::forDeepLink($waPhoneRecord?->phone ?? $buyer->phone, $waPhoneRecord?->dial_code ?? $buyer->primaryPhone?->dial_code);
    $clientPageUrl = $buyer->clientPageUrl();
    $renderedWaMsg = str_replace(['{name}', '{link}'], [$buyer->first_name, $clientPageUrl], $defaultWaMsg);
    $shareEmailSubject = 'Your property matches';
    $shareEmailBody = $renderedWaMsg;
@endphp
<div x-data="{
        shareOpen: false,
        showWaModal: false,
        waMessage: {{ Js::from($renderedWaMsg) }},
        waPhone: '{{ $waPhone }}',
        outreachAllowed: {{ ($outreachWindow['allowed'] ?? true) ? 'true' : 'false' }},
        outreachWindowMessage: {{ Js::from($outreachWindow['message'] ?? '') }},
        incrementUrl: @js(route('corex.contacts.increment', $buyer)),
        commBase: @js(url('corex/contacts/'.$buyer->id.'/communications')),
        csrf: @js(csrf_token()),
        sentConfirm: { open: false, communicationId: null },
        emailAddress: @js($buyer->email),
        emailSubject: {{ Js::from($shareEmailSubject) }},
        emailBody: {{ Js::from($shareEmailBody) }},
        async increment(channel, payload = {}) {
            try {
                const res = await fetch(this.incrementUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ channel: channel, subject: payload.subject ?? null, body: payload.body ?? null }),
                });
                return await res.json();
            } catch (e) { return null; }
        },
        async confirmSent(didSend) {
            const commId = this.sentConfirm.communicationId;
            this.sentConfirm.open = false;
            if (!commId || !didSend) return;
            try {
                await fetch(this.commBase + '/' + commId + '/mark-sent', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body: '{}',
                });
            } catch (e) {}
        },
        sendEmail() {
            this.shareOpen = false;
            if (!this.emailAddress) { alert('This contact has no email address.'); return; }
            window.location.href = 'mailto:' + encodeURIComponent(this.emailAddress) + '?subject=' + encodeURIComponent(this.emailSubject) + '&body=' + encodeURIComponent(this.emailBody);
            this.increment('email', { subject: this.emailSubject, body: this.emailBody });
        },
        async sendWhatsApp() {
            if (!this.waPhone) return;
            if (!this.outreachAllowed) {
                alert(this.outreachWindowMessage || 'Outreach sending is closed right now.');
                return;
            }
            window.open('https://wa.me/' + this.waPhone + '?text=' + encodeURIComponent(this.waMessage), '_blank', 'noopener');
            this.showWaModal = false;
            const data = await this.increment('whatsapp', { body: this.waMessage });
            if (data && data.communication_id) {
                this.sentConfirm = { open: true, communicationId: data.communication_id };
            }
        },
    }" class="relative inline-block" @keydown.escape.window="shareOpen = false">

    @include('partials.whatsapp-send-confirm-modal')

    <button type="button" @click="shareOpen = !shareOpen" class="corex-btn-outline corex-btn-on-brand inline-flex items-center gap-1.5">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z"/></svg>
        Share
    </button>

    <div x-show="shareOpen" x-cloak x-transition.opacity @click.outside="shareOpen = false"
         class="absolute right-0 mt-1 rounded-md z-40 w-52 py-1"
         style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 8px 30px rgba(0,0,0,0.18);">
        @if($waPhone)
        <button type="button" @click="shareOpen = false; showWaModal = true"
                class="w-full flex items-center gap-2 px-3 py-2 text-xs text-left transition-colors"
                style="color: var(--text-secondary); background: transparent;"
                onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 flex-shrink-0" viewBox="0 0 24 24" fill="#25d366">
                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
                <path d="M12 0C5.373 0 0 5.373 0 12c0 2.117.554 4.103 1.523 5.824L0 24l6.335-1.509A11.945 11.945 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.854 0-3.6-.483-5.12-1.33l-.368-.214-3.76.896.952-3.656-.238-.384A10.01 10.01 0 0 1 2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/>
            </svg>
            WhatsApp
        </button>
        @endif
        @if($buyer->email)
        <button type="button" @click="sendEmail()"
                class="w-full flex items-center gap-2 px-3 py-2 text-xs text-left transition-colors"
                style="color: var(--text-secondary); background: transparent;"
                onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
            Email
        </button>
        @endif
        <a href="{{ $clientPageUrl }}" target="_blank" @click="shareOpen = false"
           class="w-full flex items-center gap-2 px-3 py-2 text-xs no-underline transition-colors"
           style="color: var(--text-secondary);"
           onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.641 0-8.58-3.007-9.964-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
            Client Page
        </a>
    </div>

    {{-- WhatsApp Modal — identical shape to _match-action-bar's --}}
    <div x-show="showWaModal" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="background: rgba(0,0,0,0.5);"
         @keydown.escape.window="showWaModal = false">
        <div class="w-full max-w-lg rounded-md overflow-hidden text-left"
             style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 10px 30px rgba(0,0,0,0.18);"
             @click.stop>
            <div class="flex items-center justify-between px-6 py-4" style="border-bottom: 1px solid var(--border);">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-md flex items-center justify-center flex-shrink-0"
                         style="background: color-mix(in srgb, #25d366 12%, transparent); border: 1px solid color-mix(in srgb, #25d366 30%, transparent);">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#25d366" style="width:18px;height:18px;">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
                            <path d="M12 0C5.373 0 0 5.373 0 12c0 2.117.554 4.103 1.523 5.824L0 24l6.335-1.509A11.945 11.945 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.854 0-3.6-.483-5.12-1.33l-.368-.214-3.76.896.952-3.656-.238-.384A10.01 10.01 0 0 1 2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/>
                        </svg>
                    </div>
                    <div>
                        <div class="text-lg font-semibold" style="color: var(--text-primary);">Send via WhatsApp</div>
                        <div class="text-xs" style="color: var(--text-muted);">{{ $buyer->full_name }}@if($buyer->phone) · {{ $buyer->phone }}@endif</div>
                    </div>
                </div>
                <button type="button" @click="showWaModal = false"
                        class="w-8 h-8 flex items-center justify-center rounded-md text-sm font-bold"
                        style="color: var(--text-muted); background: var(--surface-2); border: 1px solid var(--border);">✕</button>
            </div>
            <div class="px-6 py-5 space-y-3">
                <label class="block text-xs font-medium" style="color: var(--text-secondary);">Edit message before sending</label>
                <textarea x-model="waMessage" rows="10"
                          class="w-full rounded-md px-3 py-2 text-sm"
                          style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary); resize: vertical; line-height: 1.6;"></textarea>
                <p class="text-xs" style="color: var(--text-muted);">The client's personalised link is already included in the message.</p>
            </div>
            <div class="px-6 pb-5 flex items-center justify-end gap-3 pt-3" style="border-top: 1px solid var(--border);">
                <button type="button" @click="showWaModal = false" class="corex-btn-outline">Cancel</button>
                <template x-if="!outreachAllowed">
                    <span class="text-xs" style="color:var(--ds-crimson,#c41e3a);" x-text="outreachWindowMessage"></span>
                </template>
                <button type="button" @click="sendWhatsApp()" class="corex-btn-primary" :disabled="!outreachAllowed" :class="{ 'opacity-60 cursor-not-allowed': !outreachAllowed }" style="background: #25d366; box-shadow: none;">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
                        <path d="M12 0C5.373 0 0 5.373 0 12c0 2.117.554 4.103 1.523 5.824L0 24l6.335-1.509A11.945 11.945 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.854 0-3.6-.483-5.12-1.33l-.368-.214-3.76.896.952-3.656-.238-.384A10.01 10.01 0 0 1 2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/>
                    </svg>
                    Open in WhatsApp
                </button>
            </div>
        </div>
    </div>
</div>
