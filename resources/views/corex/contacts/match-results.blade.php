{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $defaultWaMsg = \App\Models\PerformanceSetting::get('matches_wa_message',
        "Hi {name}! \xf0\x9f\x91\x8b\n\nI've put together a personalised selection of properties that match your search criteria.\n\nView your property matches here:\n{link}\n\nFeel free to reach out if you'd like to arrange viewings or have any questions!"
    );
    // Contact-details Phase 1 fix — this used to strip digits and blindly
    // replace a leading '0' with South Africa's '27' regardless of the
    // number's real country (same defect as show.blade.php's sendWa()).
    // WhatsAppNumberFormatter uses the number's OWN dial code instead.
    // Contact-details Phase 3 — prefer the designated primary-WhatsApp
    // number (may differ from the primary contact number); falls back to
    // the primary contact number when no WhatsApp designation exists yet.
    $waPhoneRecord = $contact->whatsAppPhone();
    $waPhone = \App\Support\WhatsAppNumberFormatter::forDeepLink($waPhoneRecord?->phone ?? $contact->phone, $waPhoneRecord?->dial_code ?? $contact->primaryPhone?->dial_code);
    $renderedWaMsg = str_replace(['{name}', '{link}'], [$contact->first_name, $match->sharedUrl()], $defaultWaMsg);

    $defaultEmailSubject = \App\Models\PerformanceSetting::get('matches_email_subject', 'Your personalised property matches');
    $defaultEmailMsg = \App\Models\PerformanceSetting::get('matches_email_message',
        "Hi {name},\n\nI've put together a personalised selection of properties that match your search criteria.\n\nView your property matches here:\n{link}\n\nFeel free to reach out if you'd like to arrange viewings or have any questions!"
    );
    $renderedEmailSubject = str_replace(['{name}', '{link}'], [$contact->first_name, $match->sharedUrl()], $defaultEmailSubject);
    $renderedEmailMsg = str_replace(['{name}', '{link}'], [$contact->first_name, $match->sharedUrl()], $defaultEmailMsg);

    $totalViews = array_sum($match->property_view_counts ?? []);
    $hiddenCount = count($match->hidden_property_ids ?? []);
@endphp
<div class="w-full space-y-6"
     x-data="{
         showWaModal: false,
         showEmailModal: false,
         waMessage: {{ Js::from($renderedWaMsg) }},
         waPhone: '{{ $waPhone }}',
         emailSubject: {{ Js::from($renderedEmailSubject) }},
         emailBody: {{ Js::from($renderedEmailMsg) }},
         contactEmail: {{ Js::from($contact->email ?? '') }},
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
                     headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.incrementCsrf },
                     body: '{}',
                 });
             } catch (e) {}
         },
         async recordSend(channel, payload = {}) {
             try {
                 const res = await fetch(this.incrementUrl, {
                     method: 'POST',
                     headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.incrementCsrf, 'Accept': 'application/json' },
                     body: JSON.stringify({ channel, subject: payload.subject ?? null, body: payload.body ?? null }),
                 });
                 return await res.json();
             } catch (e) {
                 // Network blip: the archive is the source of truth on next load;
                 // the WhatsApp/email deep link has already fired regardless.
                 return null;
             }
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
             const data = await this.recordSend('whatsapp', { body: this.waMessage });
             if (data && data.communication_id) {
                 this.sentConfirm = { open: true, communicationId: data.communication_id };
             }
         },
         sendEmail() {
             if (!this.contactEmail) return;
             window.location.href = 'mailto:' + encodeURIComponent(this.contactEmail) + '?subject=' + encodeURIComponent(this.emailSubject) + '&body=' + encodeURIComponent(this.emailBody);
             this.recordSend('email', { subject: this.emailSubject, body: this.emailBody });
             this.showEmailModal = false;
         },
         // AT-117 — add this composed message to the outreach queue (ready now).
         queueUrl: @js(route('corex.outreach-queue.enqueue')),
         queueContactId: {{ (int) $contact->id }},
         queueCsrf: @js(csrf_token()),
         queuing: false,
         async addToQueue(channel, body) {
             if (this.queuing) return;
             this.queuing = true;
             try {
                 const res = await fetch(this.queueUrl, {
                     method: 'POST',
                     headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': this.queueCsrf, 'Accept': 'application/json' },
                     body: new URLSearchParams({ contact_id: this.queueContactId, channel: channel, source: 'mic', body: body }),
                 });
                 const data = await res.json();
                 if (!res.ok || !data.ok) { alert(data.message || 'Could not queue.'); return; }
                 alert(data.message || 'Added to your outreach queue.');
                 this.showWaModal = false;
                 this.showEmailModal = false;
             } catch (e) { alert('Network error — try again.'); } finally { this.queuing = false; }
         },
     }">

    {{-- AT-323 — SHARED post-send did-you-send confirmation modal (same component the contact page
         + outreach pitch-send use). Driven by this component's sentConfirm / confirmSent. --}}
    @include('partials.whatsapp-send-confirm-modal')

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">

        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">

            {{-- Left: contact + criteria --}}
            <div class="flex items-start gap-4 min-w-0">
                {{-- Avatar --}}
                {{-- `text-white` is intentionally NOT a class here: .corex-page-banner
                     rewrites .text-white to --text-primary, which would kill the
                     contrast of initials sitting on the coloured avatar fill. --}}
                <div class="w-12 h-12 rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0"
                     style="background: {{ $contact->type?->color ?? 'var(--brand-icon)' }}; color: #fff;">
                    {{ $contact->initials }}
                </div>

                <div class="min-w-0">
                    {{-- Title row --}}
                    <div class="flex items-center gap-2 flex-wrap mb-1">
                        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $contact->full_name }}</h1>
                        @if($contact->type)
                        <span class="ds-badge ds-badge-default" style="background: {{ $contact->type->color }}22; color: {{ $contact->type->color }}; border: 1px solid {{ $contact->type->color }}55;">
                            {{ $contact->type->name }}
                        </span>
                        @endif
                        <span class="ds-badge {{ $match->listing_type === 'rental' ? 'ds-badge-info' : 'ds-badge-success' }}">
                            {{ $match->listingTypeLabel() }}
                        </span>
                        @if(auth()->user()->hasPermission('access_core_matches'))
                        {{-- AT-240 — edit this wishlist/criteria; opens the existing edit flow. --}}
                        <a href="{{ route('corex.contacts.matches.edit', [$contact, $match]) }}"
                           class="corex-btn-outline text-xs no-underline inline-flex items-center gap-1"
                           title="Edit this wishlist / match criteria">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" /></svg>
                            Edit criteria
                        </a>
                        @endif
                    </div>

                    {{-- Phone / email --}}
                    <div class="flex items-center gap-3 mb-3 flex-wrap text-xs" style="color: var(--text-muted);">
                        @if($contact->phone)<span>{{ $contact->phone }}</span>@endif
                        @if($contact->email)<span>{{ $contact->email }}</span>@endif
                    </div>

                    {{-- Criteria chips --}}
                    <div class="flex items-center gap-1.5 flex-wrap">
                        @if($match->price_min || $match->price_max)
                        <span class="text-xs font-semibold px-2.5 py-1 rounded-md"
                              style="background: color-mix(in srgb, var(--brand-icon) 10%, transparent); color: var(--brand-icon); border: 1px solid color-mix(in srgb, var(--brand-icon) 22%, transparent);">
                            {{ $match->priceRangeLabel() }}
                        </span>
                        @endif
                        @foreach($match->suburbList() as $sub)
                        <span class="text-xs font-medium px-2.5 py-1 rounded-md"
                              style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">
                            {{ $sub }}
                        </span>
                        @endforeach
                        @if($match->category)
                        <span class="text-xs font-medium px-2.5 py-1 rounded-md"
                              style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">
                            {{ $match->category }}
                        </span>
                        @endif
                        @if($match->property_type)
                        <span class="text-xs font-medium px-2.5 py-1 rounded-md"
                              style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">
                            {{ $match->property_type }}
                        </span>
                        @endif
                        @foreach([[$match->beds_min,'Beds'],[$match->baths_min,'Baths'],[$match->garages_min,'Gar']] as [$val,$lbl])
                        @if($val !== null)
                        <span class="text-xs font-medium px-2.5 py-1 rounded-md"
                              style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">
                            {{ $val }}+ {{ $lbl }}
                        </span>
                        @endif
                        @endforeach
                        @if($match->floor_size_min || $match->floor_size_max)
                        <span class="text-xs font-medium px-2.5 py-1 rounded-md"
                              style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">
                            {{ $match->floor_size_min ? number_format($match->floor_size_min) : '—' }}–{{ $match->floor_size_max ? number_format($match->floor_size_max) : '—' }} m²
                        </span>
                        @endif
                        @if(!$match->category && !$match->property_type && !$match->suburb && !$match->price_min && !$match->price_max && !$match->beds_min && !$match->baths_min)
                        <span class="text-xs italic" style="color: var(--text-muted);">Any property</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Right: stats + actions --}}
            <div class="flex flex-col md:items-end gap-3 flex-shrink-0">
                {{-- Stats row --}}
                <div class="flex items-center gap-4">
                    <div class="md:text-right">
                        <div class="text-base font-bold leading-tight tabular-nums" style="color: var(--text-primary);">
                            {{ number_format($properties->count()) }}
                        </div>
                        <div class="text-[0.625rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                            {{ Str::plural('match', $properties->count()) }}
                        </div>
                    </div>
                    @if($totalViews > 0)
                    <div style="width:1px; height:26px; background: var(--border);"></div>
                    <div class="md:text-right">
                        <div class="text-base font-bold leading-tight tabular-nums" style="color: var(--text-primary);">{{ number_format($totalViews) }}</div>
                        <div class="text-[0.625rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                            client {{ Str::plural('view', $totalViews) }}
                        </div>
                    </div>
                    @endif
                    @if($hiddenCount > 0)
                    <div style="width:1px; height:26px; background: var(--border);"></div>
                    <div class="md:text-right">
                        <div class="text-base font-bold leading-tight tabular-nums" style="color: var(--text-muted);">{{ number_format($hiddenCount) }}</div>
                        <div class="text-[0.625rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">hidden</div>
                    </div>
                    @endif
                </div>

                {{-- Action buttons --}}
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('corex.contacts.show', $contact) }}?tab=matches"
                       class="corex-btn-outline text-xs whitespace-nowrap inline-flex items-center gap-1.5">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                        Back to {{ $contact->full_name }}
                    </a>
                    @if($waPhone)
                    <button type="button" @click="showWaModal = true" class="corex-btn-primary text-xs" style="background: #25d366; box-shadow: none;">
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
                            style="background: rgba(255,255,255,0.08); color: #fff; border-color: rgba(255,255,255,0.2);">
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
                                style="background: rgba(255,255,255,0.08); color: #fff; border-color: rgba(255,255,255,0.2);"
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
                    <a href="{{ $match->sharedUrl() }}" target="_blank" class="corex-btn-outline" style="background: rgba(255,255,255,0.08); color: #fff; border-color: rgba(255,255,255,0.2);">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.641 0-8.58-3.007-9.964-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                        Client Page
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- Property list --}}
    @if($properties->isEmpty())
    <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
             style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 15.803a7.5 7.5 0 0 0 10.607 0Z" /></svg>
        </div>
        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No active properties match these criteria</h3>
        <p class="text-sm mb-4" style="color: var(--text-muted);">Try broadening the price range, suburb, or room requirements.</p>
        <a href="{{ route('corex.contacts.show', $contact) }}?tab=matches" class="corex-btn-outline">
            ← Back to {{ $contact->full_name }}
        </a>
    </div>
    @else
    <div class="space-y-3">
        @php
            // Belt-and-braces: hard-filter results to the match's listing_type.
            // The controller already uses ClientMatchResolver which filters strictly,
            // but if anything ever leaks through (legacy code path, cache, etc.)
            // a sale match must never display rentals, and vice versa.
            // Spec: .ai/specs/client-auth.md
            $matchListingType = $match->listing_type;
            $rentalStatuses   = ['to_rent','torent','for_rent','forrent','rented'];
            $saleStatuses     = ['for_sale','forsale','sold'];

            $filteredProperties = collect($properties)->filter(function ($p) use ($matchListingType, $rentalStatuses, $saleStatuses) {
                if (!$matchListingType) return true;
                $pLt = strtolower((string) ($p->listing_type ?? ''));
                $pSt = strtolower((string) ($p->status ?? ''));
                if ($matchListingType === 'sale') {
                    if ($pLt === 'rental') return false;
                    if (in_array($pSt, $rentalStatuses, true)) return false;
                }
                if ($matchListingType === 'rental') {
                    if ($pLt === 'sale') return false;
                    if (in_array($pSt, $saleStatuses, true)) return false;
                }
                return true;
            });

            // Visible properties first, hidden ones grouped at the bottom.
            $visibleProperties = $filteredProperties->reject(fn ($p) => $match->isPropertyHidden($p->id))->values();
            $hiddenProperties  = $filteredProperties->filter(fn ($p) => $match->isPropertyHidden($p->id))->values();
            $orderedProperties = $visibleProperties->concat($hiddenProperties);
            $firstHiddenId     = $hiddenProperties->first()?->id;
        @endphp
        @foreach($orderedProperties as $property)
        @php
            $isHidden = $match->isPropertyHidden($property->id);
        @endphp
        @if($isHidden && $property->id === $firstHiddenId)
        <div class="flex items-center gap-3 pt-6 pb-1">
            <div class="text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                Hidden from this match ({{ $hiddenProperties->count() }})
            </div>
            <div class="flex-1" style="height:1px; background: var(--border);"></div>
        </div>
        @endif
        <x-match-card :property="$property" :match="$match" :contact="$contact" :feedback="$feedback[$property->id] ?? null" />
        @endforeach
    </div>

    @endif

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
                          style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary); resize: vertical; line-height: 1.6;"></textarea>
                <p class="text-xs" style="color: var(--text-muted);">The client's personalised link is already included in the message.</p>
            </div>

            {{-- AT-117 — add to the outreach queue (ready now). Available any time;
                 sending from the queue is gated by the send-window. --}}
            <div class="px-6 pb-4 mt-2 pt-3" style="border-top: 1px solid var(--border);">
                <button type="button" @click="addToQueue('whatsapp', waMessage)" :disabled="queuing"
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

    {{-- Email Modal — for buyers who don't use WhatsApp. --}}
    <div x-show="showEmailModal" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="background: rgba(0,0,0,0.5);"
         @keydown.escape.window="showEmailModal = false">
        <div class="w-full max-w-lg rounded-md overflow-hidden"
             style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 10px 30px rgba(0,0,0,0.18);"
             @click.stop>

            {{-- Modal header --}}
            <div class="flex items-center justify-between px-6 py-4" style="border-bottom: 1px solid var(--border);">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-md flex items-center justify-center flex-shrink-0"
                         style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); border: 1px solid color-mix(in srgb, var(--brand-icon) 30%, transparent);">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="var(--brand-icon)" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                    </div>
                    <div>
                        <div class="text-lg font-semibold" style="color: var(--text-primary);">Send via Email</div>
                        <div class="text-xs" style="color: var(--text-muted);">{{ $contact->full_name }}@if($contact->email) · {{ $contact->email }}@endif</div>
                    </div>
                </div>
                <button type="button" @click="showEmailModal = false"
                        class="w-8 h-8 flex items-center justify-center rounded-md text-sm font-bold"
                        style="color: var(--text-muted); background: var(--surface-2); border: 1px solid var(--border);">✕</button>
            </div>

            {{-- Message editor --}}
            <div class="px-6 py-5 space-y-3">
                <label class="block text-xs font-medium" style="color: var(--text-secondary);">Subject</label>
                <input type="text" x-model="emailSubject"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <label class="block text-xs font-medium" style="color: var(--text-secondary);">Edit message before sending</label>
                <textarea x-model="emailBody" rows="10"
                          class="w-full rounded-md px-3 py-2 text-sm"
                          style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary); resize: vertical; line-height: 1.6;"></textarea>
                <p class="text-xs" style="color: var(--text-muted);">The client's personalised link is already included in the message.</p>
            </div>

            <div class="px-6 pb-4 mt-2 pt-3" style="border-top: 1px solid var(--border);">
                <button type="button" @click="addToQueue('email', emailBody)" :disabled="queuing"
                        class="corex-btn-outline disabled:opacity-40 disabled:cursor-not-allowed">
                    <span x-show="!queuing">Add to queue</span>
                    <span x-show="queuing" x-cloak>Adding…</span>
                </button>
            </div>

            {{-- Footer --}}
            <div class="px-6 pb-5 flex items-center justify-end gap-3">
                <button type="button" @click="showEmailModal = false" class="corex-btn-outline">Cancel</button>
                <button type="button" @click="sendEmail()" class="corex-btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                    Open in Email
                </button>
            </div>
        </div>
    </div>


</div>
@endsection
