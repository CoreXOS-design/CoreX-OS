{{--
    Core Match action bar — WhatsApp send, Email send, Print/PDF, Client Page
    link, and the stat chips (matches / views / hidden). Extracted verbatim
    from corex.contacts.match-results' page header so Buyers Pipeline gets
    the identical, non-duplicated behaviour (parity item — Johan, 2026-08-24)
    instead of a second copy that can drift.

    Self-contained Alpine scope — drops into any host page without requiring
    shared parent state, same convention as <x-match-card> and the p24 picker.

    Required:
      $contact      App\Models\Contact
      $match        App\Models\ContactMatch
      $matchCount   int — how many matches this match/wishlist resolves to.
                    Callers decide what "matches" means for their own screen
                    (match-results passes the includeHidden=true total so this
                    stays byte-identical to today; buyers-pipeline passes its
                    own existing visible-only wishlistMatchCounts figure so the
                    number here matches the badge already shown elsewhere on
                    that page) — this partial never recomputes it itself.

    Depends on $outreachWindow being composed onto the host view (see
    OutreachWindowComposer registration in AppServiceProvider) — falls back to
    "allowed" if absent, same as the page this was extracted from.
--}}
@php
    $defaultWaMsg = \App\Models\PerformanceSetting::get('matches_wa_message',
        "Hi {name}! \xf0\x9f\x91\x8b\n\nI've put together a personalised selection of properties that match your search criteria.\n\nView your property matches here:\n{link}\n\nFeel free to reach out if you'd like to arrange viewings or have any questions!"
    );
    $waPhoneRecord = $contact->whatsAppPhone();
    $waPhone = \App\Support\WhatsAppNumberFormatter::forDeepLink($waPhoneRecord?->phone ?? $contact->phone, $waPhoneRecord?->dial_code ?? $contact->primaryPhone?->dial_code);
    $renderedWaMsg = str_replace(['{name}', '{link}'], [$contact->first_name, $match->sharedUrl()], $defaultWaMsg);
    $matchEmailSubject = 'Your property matches';
    $matchEmailBody = $renderedWaMsg;
    $totalViews = array_sum($match->property_view_counts ?? []);
    $hiddenCount = count($match->hidden_property_ids ?? []);
@endphp
<div x-data="{
        showWaModal: false,
        waMessage: {{ Js::from($renderedWaMsg) }},
        waPhone: '{{ $waPhone }}',
        outreachAllowed: {{ ($outreachWindow['allowed'] ?? true) ? 'true' : 'false' }},
        outreachWindowMessage: {{ Js::from($outreachWindow['message'] ?? '') }},
        // AT-323 send-confirm + counter — REUSES the contact-page mechanism: /increment logs a
        // provisional Communication (WhatsApp born not_delivered), then the SHARED did-you-send
        // modal marks it sent on Yes, which is what increments the contact WA/email counter.
        // Keep these comments free of literal double quotes: this x-data sits inside a
        // double-quoted attribute and a stray one closes it, leaking JS onto the page as text.
        incrementUrl: @js(route('corex.contacts.increment', $contact)),
        commBase: @js(url('corex/contacts/'.$contact->id.'/communications')),
        csrf: @js(csrf_token()),
        sentConfirm: { open: false, communicationId: null },
        emailAddress: @js($contact->email),
        emailSubject: {{ Js::from($matchEmailSubject) }},
        emailBody: {{ Js::from($matchEmailBody) }},
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
            if (!commId || !didSend) return; // No answer: the WhatsApp row stays not_delivered (uncounted).
            try {
                await fetch(this.commBase + '/' + commId + '/mark-sent', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body: '{}',
                });
            } catch (e) {}
        },
        sendEmail() {
            if (!this.emailAddress) { alert('This contact has no email address.'); return; }
            window.location.href = 'mailto:' + encodeURIComponent(this.emailAddress) + '?subject=' + encodeURIComponent(this.emailSubject) + '&body=' + encodeURIComponent(this.emailBody);
            // Email is client-launched (mailto): counted on send, no did-you-send modal (matches outreach).
            this.increment('email', { subject: this.emailSubject, body: this.emailBody });
        },
        async sendWhatsApp() {
            if (!this.waPhone) return;
            // AT-117 §4a — send-window lock.
            if (!this.outreachAllowed) {
                alert(this.outreachWindowMessage || 'Outreach sending is closed right now.');
                return;
            }
            // AT-323 — open WhatsApp FIRST (inside the click gesture, new tab, not popup-blocked),
            // THEN record the send and ask did-you-send.
            window.open('https://wa.me/' + this.waPhone + '?text=' + encodeURIComponent(this.waMessage), '_blank', 'noopener');
            this.showWaModal = false;
            const data = await this.increment('whatsapp', { body: this.waMessage });
            if (data && data.communication_id) {
                this.sentConfirm = { open: true, communicationId: data.communication_id };
            }
        },
        // AT-117 — add this composed message to the outreach queue (ready now).
        queueUrl: @js(route('corex.outreach-queue.enqueue')),
        queueContactId: {{ (int) $contact->id }},
        queueCsrf: @js(csrf_token()),
        queuing: false,
        async addToQueue() {
            if (this.queuing) return;
            this.queuing = true;
            try {
                const res = await fetch(this.queueUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': this.queueCsrf, 'Accept': 'application/json' },
                    body: new URLSearchParams({ contact_id: this.queueContactId, channel: 'whatsapp', source: 'mic', body: this.waMessage }),
                });
                const data = await res.json();
                if (!res.ok || !data.ok) { alert(data.message || 'Could not queue.'); return; }
                alert(data.message || 'Added to your outreach queue.');
                this.showWaModal = false;
            } catch (e) { alert('Network error — try again.'); } finally { this.queuing = false; }
        },
    }" class="contents">

    {{-- AT-323 — SHARED post-send did-you-send confirmation modal (same component the contact page
         + outreach pitch-send use). Driven by this component's sentConfirm / confirmSent. --}}
    @include('partials.whatsapp-send-confirm-modal')

    {{-- display:contents above means this partial imposes NO layout of its own — the two
         rows below (stats, buttons) are direct children of whatever the CALLER wraps this
         include in. match-results.blade.php wraps them in its existing "Right" column div
         (preserving its exact original two-column header unchanged); any other caller
         supplies its own wrapper. Do not add a layout div here — it would silently change
         match-results' header structure. --}}
    {{-- Stats row --}}
    <div class="flex items-center gap-4">
            <div class="md:text-right">
                <div class="text-[1.625rem] font-semibold leading-tight" style="color: var(--match-action-bar-stat-color, #fff);">
                    {{ number_format($matchCount) }}
                </div>
                <div class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: var(--match-action-bar-stat-label-color, rgba(255,255,255,0.6));">
                    {{ Str::plural('match', $matchCount) }}
                </div>
            </div>
            @if($totalViews > 0)
            <div style="width:1px; height:32px; background: var(--match-action-bar-divider-color, rgba(255,255,255,0.15));"></div>
            <div class="md:text-right">
                <div class="text-[1.625rem] font-semibold leading-tight" style="color: var(--match-action-bar-stat-color, #fff);">{{ number_format($totalViews) }}</div>
                <div class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: var(--match-action-bar-stat-label-color, rgba(255,255,255,0.6));">
                    client {{ Str::plural('view', $totalViews) }}
                </div>
            </div>
            @endif
            @if($hiddenCount > 0)
            <div style="width:1px; height:32px; background: var(--match-action-bar-divider-color, rgba(255,255,255,0.15));"></div>
            <div class="md:text-right">
                <div class="text-[1.625rem] font-semibold leading-tight" style="color: var(--match-action-bar-stat-color-muted, rgba(255,255,255,0.6));">{{ number_format($hiddenCount) }}</div>
                <div class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: var(--match-action-bar-stat-label-color-muted, rgba(255,255,255,0.5));">hidden</div>
            </div>
            @endif
        </div>

        {{-- Action buttons --}}
        <div class="flex items-center gap-2">
            @if($waPhone)
            <button type="button" @click="showWaModal = true" class="corex-btn-primary" style="background: #25d366; box-shadow: none;">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
                    <path d="M12 0C5.373 0 0 5.373 0 12c0 2.117.554 4.103 1.523 5.824L0 24l6.335-1.509A11.945 11.945 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.854 0-3.6-.483-5.12-1.33l-.368-.214-3.76.896.952-3.656-.238-.384A10.01 10.01 0 0 1 2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/>
                </svg>
                WhatsApp
            </button>
            @endif
            @if($contact->email)
            {{-- Email the match list — opens the mail client and records the send so the
                 contact's email counter updates (same mechanism as the contact page). --}}
            <button type="button" @click="sendEmail()" class="corex-btn-outline inline-flex items-center gap-1.5"
                    style="background: var(--match-action-bar-outline-bg, rgba(255,255,255,0.08)); color: var(--match-action-bar-outline-color, #fff); border-color: var(--match-action-bar-outline-border, rgba(255,255,255,0.2));">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                Email
            </button>
            @endif
            {{-- Print / Download PDF — the resolved list as a clean A4 sheet for
                 appointment rounds. INTERNAL (seller + address details). Johan's
                 with-photo / without-photo choice: With photos (default) embeds a
                 photo per property; Without photos is a compact text-only sheet
                 (faster to print, saves ink). Both open inline → browser print. --}}
            <div class="relative inline-block" x-data="{ pdfOpen: false }" @keydown.escape="pdfOpen = false">
                <button type="button" @click="pdfOpen = !pdfOpen"
                        class="corex-btn-outline inline-flex items-center gap-1.5"
                        style="background: var(--match-action-bar-outline-bg, rgba(255,255,255,0.08)); color: var(--match-action-bar-outline-color, #fff); border-color: var(--match-action-bar-outline-border, rgba(255,255,255,0.2));"
                        title="Print or download this list as a PDF — internal working sheet (contains seller & address details)">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z" /></svg>
                    Print / PDF
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                </button>
                <div x-show="pdfOpen" x-cloak @click.outside="pdfOpen = false"
                     class="absolute right-0 mt-1 rounded-md overflow-hidden"
                     style="min-width: 210px; z-index: 40; background: var(--surface, #fff); border: 1px solid var(--border, #e2e8f0); box-shadow: 0 10px 28px rgba(0,0,0,0.20);">
                    <a href="{{ route('corex.contacts.matches.print', [$contact, $match]) }}?photos=1" target="_blank" rel="noopener noreferrer"
                       @click="pdfOpen = false"
                       class="flex items-start gap-2 px-3 py-2.5 no-underline" style="color: var(--text-primary, #1a1d21);">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" /></svg>
                        <span class="text-xs leading-tight">
                            <span class="font-semibold">With photos</span><br>
                            <span style="color: var(--text-muted, #64748b);">A photo per property</span>
                        </span>
                    </a>
                    <a href="{{ route('corex.contacts.matches.print', [$contact, $match]) }}?photos=0" target="_blank" rel="noopener noreferrer"
                       @click="pdfOpen = false"
                       class="flex items-start gap-2 px-3 py-2.5 no-underline" style="color: var(--text-primary, #1a1d21); border-top: 1px solid var(--border, #f1f5f9);">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
                        <span class="text-xs leading-tight">
                            <span class="font-semibold">Without photos</span><br>
                            <span style="color: var(--text-muted, #64748b);">Compact text-only — saves ink</span>
                        </span>
                    </a>
                </div>
            </div>
            <a href="{{ $match->sharedUrl() }}" target="_blank" class="corex-btn-outline" style="background: var(--match-action-bar-outline-bg, rgba(255,255,255,0.08)); color: var(--match-action-bar-outline-color, #fff); border-color: var(--match-action-bar-outline-border, rgba(255,255,255,0.2));">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.641 0-8.58-3.007-9.964-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                Client Page
            </a>
        </div>

    {{-- WhatsApp Modal --}}
    <div x-show="showWaModal" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="background: rgba(0,0,0,0.5);"
         @keydown.escape.window="showWaModal = false">
        <div class="w-full max-w-lg rounded-md overflow-hidden"
             style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 10px 30px rgba(0,0,0,0.18);"
             @click.stop>

            {{-- Modal header --}}
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
                        <div class="text-xs" style="color: var(--text-muted);">{{ $contact->full_name }}@if($contact->phone) · {{ $contact->phone }}@endif</div>
                    </div>
                </div>
                <button type="button" @click="showWaModal = false"
                        class="w-8 h-8 flex items-center justify-center rounded-md text-sm font-bold"
                        style="color: var(--text-muted); background: var(--surface-2); border: 1px solid var(--border);">✕</button>
            </div>

            {{-- Message editor --}}
            <div class="px-6 py-5 space-y-3">
                <label class="block text-xs font-medium" style="color: var(--text-secondary);">Edit message before sending</label>
                <textarea x-model="waMessage" rows="10"
                          class="w-full rounded-md px-3 py-2 text-sm"
                          style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary); resize: vertical; line-height: 1.6;"></textarea>
                <p class="text-xs" style="color: var(--text-muted);">The client's personalised link is already included in the message.</p>
            </div>

            {{-- AT-117 — add to the outreach queue (ready now). Available any time;
                 sending from the queue is gated by the send-window. --}}
            <div class="px-6 pb-4 mt-2 pt-3" style="border-top: 1px solid var(--border);">
                <button type="button" @click="addToQueue()" :disabled="queuing"
                        class="corex-btn-outline disabled:opacity-40 disabled:cursor-not-allowed">
                    <span x-show="!queuing">Add to queue</span>
                    <span x-show="queuing" x-cloak>Adding…</span>
                </button>
            </div>

            {{-- Footer --}}
            <div class="px-6 pb-5 flex items-center justify-end gap-3">
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
