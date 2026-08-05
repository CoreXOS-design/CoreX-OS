{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<style>
    /* Contact show page — CoreX UI Design System token-based hover */
    .contact-show-row { transition: background 150ms ease; }
    .contact-show-row:hover { background: var(--surface-2); }
    .contact-show-wa-card { transition: background 150ms ease, border-color 150ms ease; }
    .contact-show-wa-card:hover { border-color: #25d366; background: color-mix(in srgb, #25d366 6%, transparent); }
    .contact-show-email-card { transition: background 150ms ease, border-color 150ms ease; }
    .contact-show-email-card:hover { border-color: var(--brand-icon, #0ea5e9); background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 4%, transparent); }
    .contact-show-btn-hover { transition: opacity 150ms ease; }
    .contact-show-btn-hover:hover { opacity: 0.85; }
</style>
<div class="w-full space-y-4"
     x-data="contactShowData('{{ route('corex.contacts.properties.search', $contact) }}', '{{ request('tab', 'info') }}')"
     x-init="activeTab = initTab">

    {{-- ════════════════════════════════════════════════════════════════════
         CONTACT HEADER (AT-336) — see _header.blade.php.

         The facts below are resolved ONCE here and read by _header and its
         partials, so the header never re-queries what the page already has.
         ════════════════════════════════════════════════════════════════════ --}}
    @php
        // AT-50/AT-81 — derived communication status. All five outreach-consent
        // states are visibly distinct; tint is keyed off $commMeta['key'].
        $commMeta = $contact->communicationStatusMeta();
        $commTint = match ($commMeta['key']) {
            \App\Models\Contact::COMM_TRANSACTION_ONLY     => 'rgba(217,119,6,0.85)',
            \App\Models\Contact::COMM_ALL_BLOCKED          => 'rgba(220,38,38,0.85)',
            \App\Models\Contact::COMM_MARKETING_OPTED_OUT  => 'var(--ds-orange, #ea580c)', // declined
            \App\Models\Contact::OUTREACH_NO_RESPONSE      => 'var(--ds-amber, #f59e0b)',
            \App\Models\Contact::OUTREACH_PENDING          => 'var(--ds-orange, #ea580c)',
            \App\Models\Contact::OUTREACH_CONFIRMED        => 'var(--ds-green, #059669)',
            \App\Models\Contact::OUTREACH_INITIAL          => 'rgba(22,163,74,0.85)',
            default                                        => 'rgba(22,163,74,0.85)',
        };

        // AT-125 — ALL identifiers (primary first, marked); falls back to the
        // mirror column for any contact without child rows.
        $allPhones = $contact->relationLoaded('phones')
            ? $contact->phones->sortByDesc('is_primary')->values() : collect();
        $allEmails = $contact->relationLoaded('emails')
            ? $contact->emails->sortByDesc('is_primary')->values() : collect();

        // The ASSIGNED agent (contacts.agent_id) ONLY — never the creator (AT-118).
        $primaryAgent = $contact->agent;
    @endphp

    @include('corex.contacts._header')

    {{-- AT-267 — view-only lock when the current user may not edit this contact (an assistant
         looking at a colleague's contact). An UNOWNED contact stays editable — see canMutateContact. --}}
    @include('partials._readonly-lock', [
        'canEdit'         => $canEdit ?? true,
        'readonlyMessage' => 'You can view this contact, but only its agent can change it. Ask your agent if something needs updating.',
    ])

    {{-- AT-267 — "added by {assistant}" (show_attribution). Renders nothing unless an assistant
         actually changed this contact and their agent has attribution switched on. --}}
    <x-assistant-attribution type="contact" :id="$contact->id" />

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
            <div class="flex-1"><strong>Please fix the following:</strong> {{ $errors->first() }}</div>
        </div>
    @endif

    {{-- Tab bar --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex overflow-x-auto" style="border-bottom: 1px solid var(--border);" id="tab-bar">
            @php
                $ficaStatus = $contact->ficaStatus();
                $ficaIcon = match($ficaStatus) {
                    'complete' => '<span class="ds-badge ds-badge-success ml-1">Complete</span>',
                    'expiring' => '<span class="ds-badge ds-badge-warning ml-1">Expiring</span>',
                    default => '<span class="ds-badge ds-badge-danger ml-1">Incomplete</span>',
                };
            @endphp
            @php
                $outreachCount = $outreachSends?->count() ?? 0;
                $outreachOptOutBadge = $contact->messaging_opt_out_at
                    ? ' <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase" style="background:var(--ds-crimson); color:#fff;">opt-out</span>'
                    : '';
            @endphp
            @foreach([
                ['key'=>'info','label'=>'Info'],
                ['key'=>'properties','label'=>'Properties &amp; Core Matches <span class="ml-1 text-xs px-1.5 py-0.5 rounded-md" style="background:var(--surface-2);">'. $contact->properties->count() .'</span>'],
                ['key'=>'viewings','label'=>'Viewings &amp; Feedback <span class="ml-1 text-xs px-1.5 py-0.5 rounded-md" style="background:var(--surface-2);">'. ($viewingsCount ?? 0) .'</span>'],
                ['key'=>'notes','label'=>'Notes &amp; Testimonials <span class="ml-1 text-xs px-1.5 py-0.5 rounded-md" style="background:var(--surface-2);">'. ($contact->contactNotes->count() + $contact->testimonials->count()) .'</span>'],
                ['key'=>'drive','label'=>'Drive <span class="ml-1 text-xs px-1.5 py-0.5 rounded-md" style="background:var(--surface-2);">'. $contact->documents->count() .'</span>'],
                ['key'=>'fica','label'=>'FICA Compliance ' . $ficaIcon],
                ['key'=>'consent','label'=>'Consent'],
                ['key'=>'communications','label'=>'Communications <span class="ml-1 text-xs px-1.5 py-0.5 rounded-md" style="background:var(--surface-2);">'. ($contactThreads ?? collect())->count() .'</span>'],
                ['key'=>'outreach','label'=>'Outreach <span class="ml-1 text-xs px-1.5 py-0.5 rounded-md" style="background:var(--surface-2);">'. $outreachCount .'</span>' . $outreachOptOutBadge],
                ['key'=>'history','label'=>'History'],
            ] as $t)
            @if($t['key'] === 'outreach' && !auth()->user()->hasPermission('outreach.compose'))
                @continue
            @endif
            @if($t['key'] === 'communications' && !(($canViewComms ?? false) || ($canRequestComms ?? false)))
                @continue
            @endif
            <button type="button"
                    @click="activeTab = '{{ $t['key'] }}'"
                    @if($t['key'] === 'outreach') data-tour="outreach-tab" @endif
                    :class="activeTab === '{{ $t['key'] }}' ? 'border-b-2' : 'border-b-2 border-transparent'"
                    :style="activeTab === '{{ $t['key'] }}' ? 'color:var(--brand-icon, #0ea5e9); border-color:var(--brand-icon, #0ea5e9); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);' : 'color:var(--text-secondary);'"
                    class="px-4 py-4 text-sm font-semibold whitespace-nowrap transition-all duration-300 outline-none hover:opacity-80"
                    >
                {!! $t['label'] !!}
            </button>
            @endforeach
        </div>

        {{-- ════════════════════════════
             INFO TAB
             ════════════════════════════ --}}
        <div x-show="activeTab === 'info'" class="p-6 space-y-6">

            {{-- ── Action Boxes: Last Contacted | WhatsApp | Email ── --}}
            <div x-data="{
                    editing: false,
                    showWa: false,
                    showEmail: false,
                    waCount: {{ (int) ($waSent ?? 0) }},
                    emailCount: {{ (int) ($emailSent ?? 0) }},
                    lastContactedLabel: '{{ $contact->last_contacted_at ? $contact->last_contacted_at->format('d M Y H:i') : 'Never' }}',
                    lastContactedRelative: '{{ $contact->last_contacted_at ? $contact->last_contacted_at->diffForHumans() : '' }}',
                    waMessage: 'Hi {{ addslashes($contact->first_name) }}',
                    emailSubject: 'Hi {{ addslashes($contact->first_name) }}',
                    emailBody: 'Hi {{ addslashes($contact->first_name) }}',
                    // Outreach number/email selector — the agent picks WHICH of the
                    // contact's numbers/emails a send goes to, defaulting to the
                    // Phase 3 primary/WhatsApp/email designations but changeable
                    // before every send. Same selector data feeds the Phase 4
                    // could-not-send reselect-and-resend picker (contact_phone_id /
                    // contact_email_id) — one source of truth for which number.
                    waNumbers: @js($contact->phones->map(fn($p) => [
                        'id' => $p->id,
                        'display' => $p->phone . ($p->label ? ' (' . $p->label . ')' : '') . ($p->is_whatsapp ? ' — WhatsApp' : ''),
                        'deeplink' => \App\Support\WhatsAppNumberFormatter::forDeepLink($p->phone, $p->dial_code),
                    ])->values()),
                    selectedPhoneId: {{ $contact->whatsAppPhone()?->id ?? 'null' }},
                    emailAddresses: @js($contact->emails->map(fn($e) => [
                        'id' => $e->id,
                        'display' => $e->email . ($e->label ? ' (' . $e->label . ')' : ''),
                        'email' => $e->email,
                    ])->values()),
                    selectedEmailId: {{ $contact->primaryEmail?->id ?? 'null' }},
                    async increment(channel, payload = {}) {
                        // AT-323 — do NOT optimistically bump the WhatsApp counter: a WhatsApp
                        // send is born not_delivered (uncounted) and only counts once the agent
                        // answers "Yes" in the modal. Email is system-sent (no modal), so it keeps
                        // the optimistic bump. Either way the server's derived count is authoritative.
                        if (channel === 'email') this.emailCount++;
                        try {
                            const res = await fetch('{{ route('corex.contacts.increment', $contact) }}', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'X-Requested-With': 'XMLHttpRequest' },
                                body: JSON.stringify({
                                    channel, subject: payload.subject ?? null, body: payload.body ?? null,
                                    contact_phone_id: payload.contactPhoneId ?? null,
                                    contact_email_id: payload.contactEmailId ?? null,
                                    resent_from_communication_id: payload.resentFrom ?? null,
                                })
                            });
                            const data = await res.json();
                            if (channel === 'whatsapp') this.waCount = data.count;
                            else this.emailCount = data.count;
                            this.lastContactedLabel = data.last_contacted;
                            this.lastContactedRelative = data.last_contacted_relative;
                            return data; // AT-323 — carries communication_id for the send-confirm modal
                        } catch (e) {
                            // Network blip: keep the optimistic bump; the archive
                            // remains the source of truth on next page load.
                            return null;
                        }
                    },
                    // AT-323 — post-send did-it-send confirmation. WhatsApp is client-side
                    // (opens the app); CoreX can't confirm delivery, so we ask. A No answer flags
                    // the just-recorded send not_delivered instead of leaving a false sent.
                    // NOTE: no literal double-quote characters in comments here — this x-data
                    // sits inside a double-quoted HTML attribute; a stray one closes it early
                    // and leaks the rest of the JS onto the page as visible text.
                    sentConfirm: { open: false, communicationId: null },
                    async confirmSent(didSend) {
                        const commId = this.sentConfirm.communicationId;
                        this.sentConfirm.open = false;
                        if (!commId) return;
                        if (!didSend) return; // "No" — the row was born not_delivered; nothing counts, nothing to do.
                        // "Yes, I sent it" — the ONLY path a WhatsApp send reaches sent (+1 the counter).
                        try {
                            const res = await fetch('{{ url('corex/contacts/'.$contact->id.'/communications') }}/' + commId + '/mark-sent', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'X-Requested-With': 'XMLHttpRequest' },
                                body: '{}'
                            });
                            const d = await res.json();
                            if (d && typeof d.count === 'number') this.waCount = d.count;
                        } catch (e) {
                            // network blip — the archive reconciles the count on next load
                        }
                    },
                    // AT-323 — Resend from the Recent Sends list re-runs the FULL flow (open
                    // WhatsApp + modal), never a silent record. Dispatched as a window event so
                    // the Recent Sends partial (a separate component) can trigger it.
                    resendWa(detail) {
                        if (detail && detail.phoneId) this.selectedPhoneId = detail.phoneId;
                        this.showWa = false;
                        this.sendWa(detail && detail.resendFrom ? detail.resendFrom : null);
                    },
                    async sendWa(resentFrom = null) {
                        // Contact-details Phase 1 fix — this used to strip digits and
                        // blindly replace a leading '0' with South Africa's '27', so a
                        // USA (or any non-ZA) number could never resolve on WhatsApp: a
                        // number typed with a local-style leading 0 got a ZA country code
                        // prepended to non-ZA digits (agents literally could not reach a
                        // USA contact — can't load a USA number. The digits are built
                        // server-side by WhatsAppNumberFormatter using THIS number's own
                        // dial code (contact_phones.dial_code), never a hardcoded '27'.
                        // Outreach selector — the agent's chosen number (selectedPhoneId),
                        // defaulting to the Phase 3 WhatsApp/primary designation but
                        // changeable per send via the Send-to dropdown below.
                        const target = this.waNumbers.find(p => p.id === this.selectedPhoneId) ?? this.waNumbers[0];
                        if (!target) { alert('This contact has no phone number.'); return; }
                        // AT-323 — ORDER MATTERS: open WhatsApp FIRST, in a NEW TAB, so the agent
                        // can actually send. This runs inside the click gesture, so the new tab is
                        // not popup-blocked; wa.me opens WhatsApp Web on desktop / the app on mobile
                        // (universal), so it opens regardless of platform. CoreX stays in this tab
                        // and THEN asks did-you-send below (a modal that replaced the open would be
                        // the never-opens bug).
                        // NOTE: keep this comment free of literal double-quotes — it lives inside the
                        // double-quoted x-data attribute and a stray one closes it, leaking JS as text.
                        window.open('https://wa.me/' + target.deeplink + '?text=' + encodeURIComponent(this.waMessage), '_blank', 'noopener');
                        this.showWa = false;
                        const data = await this.increment('whatsapp', { body: this.waMessage, contactPhoneId: target.id, resentFrom });
                        if (data && data.communication_id) {
                            this.sentConfirm = { open: true, communicationId: data.communication_id };
                        }
                    },
                    sendEmail() {
                        const target = this.emailAddresses.find(e => e.id === this.selectedEmailId) ?? this.emailAddresses[0];
                        if (!target) { alert('This contact has no email address.'); return; }
                        window.location.href = 'mailto:' + encodeURIComponent(target.email) + '?subject=' + encodeURIComponent(this.emailSubject) + '&body=' + encodeURIComponent(this.emailBody);
                        this.increment('email', { subject: this.emailSubject, body: this.emailBody, contactEmailId: target.id });
                        this.showEmail = false;
                    }
                 }" @at323-resend.window="resendWa($event.detail)" class="space-y-3">

                {{-- AT-323 — SHARED post-send confirmation modal (same component used by the
                     outreach pitch-send). Driven by this component's sentConfirm / confirmSent. --}}
                @include('partials.whatsapp-send-confirm-modal')

                {{-- 3 boxes in a row --}}
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">

                    {{-- Box 1: Last Contacted --}}
                    <div class="rounded-md px-5 py-4" style="background:var(--surface-2); border:1px solid var(--border);">
                        <div class="flex items-center gap-2 mb-2">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" style="color:var(--brand-icon, #0ea5e9);">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                            <div class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Last Contacted</div>
                        </div>
                        <div class="text-sm font-semibold" style="color:var(--text-primary);" x-text="lastContactedLabel"></div>
                        <div class="text-xs mt-0.5" style="color:var(--text-muted);" x-text="lastContactedRelative"></div>
                        <div class="mt-3 flex items-center gap-2">
                            <template x-if="!editing">
                                <div class="flex items-center gap-2">
                                    <form method="POST" action="{{ route('corex.contacts.touch', $contact) }}">
                                        @csrf
                                        <input type="hidden" name="last_contacted_at" value="{{ now()->format('Y-m-d\TH:i') }}">
                                        <button type="submit" class="text-[10px] font-semibold px-2.5 py-1 rounded-md transition-all duration-300"
                                                style="color:var(--brand-icon, #0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 30%, transparent);">
                                            Mark as Now
                                        </button>
                                    </form>
                                    <button type="button" @click="editing = true"
                                            class="text-[10px] font-semibold px-2.5 py-1 rounded-md"
                                            style="color:var(--text-muted); border:1px solid var(--border);">
                                        Pick Date
                                    </button>
                                </div>
                            </template>
                            <template x-if="editing">
                                <form method="POST" action="{{ route('corex.contacts.touch', $contact) }}" class="flex flex-col gap-2 w-full">
                                    @csrf
                                    <input type="datetime-local" name="last_contacted_at"
                                           value="{{ $contact->last_contacted_at?->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i') }}"
                                           class="rounded-md px-2.5 py-1 text-xs w-full"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <div class="flex gap-2">
                                        <button type="submit" class="corex-btn-primary text-[10px] px-2.5 py-1">Save</button>
                                        <button type="button" @click="editing = false" class="text-[10px]" style="color:var(--text-muted);">Cancel</button>
                                    </div>
                                </form>
                            </template>
                        </div>
                    </div>

                    {{-- Box 2: WhatsApp --}}
                    @if(auth()->user()->hasPermission('contacts.whatsapp'))
                    <div class="rounded-md px-5 py-4 cursor-pointer group contact-show-wa-card"
                         style="background:var(--surface-2); border:2px solid rgba(37,211,102,0.25);"
                         @click="showWa = !showWa; showEmail = false">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <svg class="w-5 h-5" style="color:#25d366;" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                                <div class="text-xs font-bold uppercase tracking-widest" style="color:#25d366;">WhatsApp</div>
                            </div>
                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded-md" style="background:rgba(37,211,102,0.12); color:#25d366;">Click to send</span>
                        </div>
                        <div class="text-2xl font-bold" style="color:var(--text-primary);" x-text="waCount"></div>
                        <div class="text-xs mt-0.5" style="color:var(--text-muted);">messages sent</div>
                    </div>
                    @endif

                    {{-- Box 3: Email --}}
                    @if(auth()->user()->hasPermission('contacts.email'))
                    <div class="rounded-md px-5 py-4 cursor-pointer group contact-show-email-card"
                         style="background:var(--surface-2); border:2px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 25%, transparent);"
                         @click="showEmail = !showEmail; showWa = false">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" style="color:var(--brand-icon, #0ea5e9);"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                                <div class="text-xs font-bold uppercase tracking-widest" style="color:var(--brand-icon, #0ea5e9);">Email</div>
                            </div>
                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded-md" style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color:var(--brand-icon, #0ea5e9);">Click to send</span>
                        </div>
                        <div class="text-2xl font-bold" style="color:var(--text-primary);" x-text="emailCount"></div>
                        <div class="text-xs mt-0.5" style="color:var(--text-muted);">sent from CoreX</div>
                        @if(($canViewComms ?? false) && $contactComms->count())
                        <button type="button" @click.stop="activeTab = 'communications'" class="text-[11px] font-semibold mt-1 underline" style="color:var(--brand-icon, #0ea5e9);">
                            {{ $contactComms->count() }} in archive →
                        </button>
                        @endif
                    </div>
                    @endif
                </div>

                {{-- WhatsApp template popup --}}
                @if(auth()->user()->hasPermission('contacts.whatsapp'))
                <div x-show="showWa" x-cloak
                     x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                     class="rounded-md p-4" style="background:var(--surface); border:1px solid #25d366; border-left:3px solid #25d366;">
                    <div class="flex items-center gap-2 mb-3">
                        <svg class="w-4 h-4" style="color:#25d366;" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                        <div class="text-xs font-bold" style="color:#25d366;">WhatsApp Message</div>
                    </div>
                    <div class="mb-3">
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Send to</label>
                        <select x-model.number="selectedPhoneId"
                                class="w-full rounded-md px-3 py-2 text-sm"
                                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            <template x-for="p in waNumbers" :key="p.id">
                                <option :value="p.id" x-text="p.display"></option>
                            </template>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Template</label>
                        <select @change="waMessage = $el.value"
                                class="w-full rounded-md px-3 py-2 text-sm"
                                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            <option value="Hi {{ $contact->first_name }}">Hi {{ $contact->first_name }}</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Message</label>
                        <textarea x-model="waMessage" rows="3"
                                  class="w-full rounded-md px-3 py-2 text-sm resize-none"
                                  style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                    </div>
                    <div class="flex items-center gap-2">
                        @if($outreachWindow['allowed'] ?? true)
                        <button type="button" @click="sendWa()"
                                class="inline-flex items-center gap-1.5 text-sm font-semibold px-4 py-2 rounded-md text-white contact-show-btn-hover"
                                style="background:#25d366;">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            Send WhatsApp
                        </button>
                        @else
                        {{-- AT-117 §4a — outside the send-window: disabled + reason. --}}
                        <button type="button" disabled
                                class="inline-flex items-center gap-1.5 text-sm font-semibold px-4 py-2 rounded-md opacity-60 cursor-not-allowed"
                                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-muted);" title="{{ $outreachWindow['message'] ?? '' }}">
                            Sending closed
                        </button>
                        @endif
                        <button type="button" @click="showWa = false" class="text-sm" style="color:var(--text-muted);">Cancel</button>
                    </div>
                    @unless($outreachWindow['allowed'] ?? true)
                    <p class="text-xs mt-2" style="color:var(--ds-crimson,#dc2626);">{{ $outreachWindow['message'] ?? '' }}</p>
                    @endunless
                </div>

                @endif

                {{-- Email template popup --}}
                @if(auth()->user()->hasPermission('contacts.email'))
                <div x-show="showEmail" x-cloak
                     x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                     class="rounded-md p-4" style="background:var(--surface); border:1px solid var(--brand-icon, #0ea5e9); border-left:3px solid var(--brand-icon, #0ea5e9);">
                    <div class="flex items-center gap-2 mb-3">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4" style="color:var(--brand-icon, #0ea5e9);"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                        <div class="text-xs font-bold" style="color:var(--brand-icon, #0ea5e9);">Email Message</div>
                    </div>
                    <div class="mb-3">
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Send to</label>
                        <select x-model.number="selectedEmailId"
                                class="w-full rounded-md px-3 py-2 text-sm"
                                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            <template x-for="e in emailAddresses" :key="e.id">
                                <option :value="e.id" x-text="e.display"></option>
                            </template>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Template</label>
                        <select @change="emailSubject = 'Hi {{ addslashes($contact->first_name) }}'; emailBody = $el.value"
                                class="w-full rounded-md px-3 py-2 text-sm"
                                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            <option value="Hi {{ $contact->first_name }}">Hi {{ $contact->first_name }}</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Subject</label>
                        <input type="text" x-model="emailSubject"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                    <div class="mb-3">
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Body</label>
                        <textarea x-model="emailBody" rows="3"
                                  class="w-full rounded-md px-3 py-2 text-sm resize-none"
                                  style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" @click="sendEmail()"
                                class="inline-flex items-center gap-1.5 text-sm font-semibold px-4 py-2 rounded-md contact-show-btn-hover"
                                style="background:var(--brand-button, #0ea5e9); color:#fff;">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" /></svg>
                            Send Email
                        </button>
                        <button type="button" @click="showEmail = false" class="text-sm" style="color:var(--text-muted);">Cancel</button>
                    </div>
                </div>
                @endif
            </div>

            @include('corex.contacts._recent-sends')

            <form method="POST" action="{{ route('corex.contacts.update', $contact) }}" class="space-y-6">
                @csrf @method('PUT')
                <input type="hidden" name="_from_show" value="1">

                {{-- Basic Info --}}
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-widest mb-4" style="color:var(--text-muted);">Basic Information</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">First Name <span class="text-red-500">*</span></label>
                            <input type="text" name="first_name" value="{{ old('first_name', $contact->first_name) }}" required
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Surname <span class="text-red-500">*</span></label>
                            <input type="text" name="last_name" value="{{ old('last_name', $contact->last_name) }}" required
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div class="sm:col-span-2 lg:col-span-3">
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Contact Type <span class="text-red-500">*</span></label>
                            @include('corex.contacts._type_picker', ['contactTypes' => $contactTypes, 'contact' => $contact])
                            @error('parent_type_ids')<p class="mt-1 text-[11px]" style="color:var(--ds-crimson, #c41e3a);">{{ $message }}</p>@enderror
                        </div>
                        <div class="sm:col-span-2 lg:col-span-3">
                            @include('corex.contacts._identifier-repeater', ['kind' => 'phones', 'type' => 'text', 'title' => 'Phone Numbers', 'addLabel' => 'phone', 'placeholder' => 'e.g. 082 123 4567', 'existing' => $contact->phones()->orderByDesc('is_primary')->orderBy('id')->get(), 'labels' => $contactIdentifierLabels])
                        </div>
                        <div class="sm:col-span-2 lg:col-span-3">
                            @include('corex.contacts._identifier-repeater', ['kind' => 'emails', 'type' => 'email', 'title' => 'Emails (optional — but a contact needs at least one phone or email)', 'addLabel' => 'email', 'placeholder' => 'e.g. john@example.com', 'existing' => $contact->emails()->orderByDesc('is_primary')->orderBy('id')->get(), 'labels' => $contactIdentifierLabels])
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">ID Number <span style="color:var(--text-muted); font-weight:400;">(optional)</span></label>
                            <input type="text" name="id_number" value="{{ old('id_number', $contact->id_number) }}"
                                   placeholder="e.g. 9001010000000"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Date of Birth <span style="color:var(--text-muted); font-weight:400;">(optional)</span></label>
                            <input type="date" name="birthday" value="{{ old('birthday', $contact->birthday?->format('Y-m-d')) }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        {{-- Residential address — where the CONTACT lives. Free text,
                             set ONLY by the agent here. Distinct from the structured
                             property-address capture on the Properties & Core Matches tab. --}}
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Address <span style="color:var(--text-muted); font-weight:400;">(optional)</span></label>
                            <input type="text" name="address" value="{{ old('address', $contact->address) }}"
                                   placeholder="e.g. 21 Dee Road, Uvongo"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>

                        {{-- Loaded / Modified dates --}}
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Loaded Date</label>
                            <input type="datetime-local" name="loaded_at" value="{{ old('loaded_at', $contact->loaded_at?->format('Y-m-d\TH:i')) }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Modified Date</label>
                            <input type="datetime-local" name="modified_at" value="{{ old('modified_at', $contact->modified_at?->format('Y-m-d\TH:i')) }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                    </div>
                </div>

                {{-- Banking Details (collapsible) --}}
                <div x-data="{ open: {{ ($contact->bank_name || $contact->bank_account_name || $contact->bank_account_number || $contact->bank_branch_name || $contact->bank_branch_code || $contact->bank_account_type) ? 'true' : 'false' }} }">
                    <button type="button" @click="open = !open" class="flex items-center gap-2 w-full text-left mb-4">
                        <h3 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Banking Details</h3>
                        <svg :class="open ? 'rotate-180' : ''" class="w-4 h-4 transition-transform" style="color:var(--text-muted);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                    <div x-show="open" x-cloak>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Bank Name</label>
                                <input type="text" name="bank_name" value="{{ old('bank_name', $contact->bank_name) }}"
                                       placeholder="e.g. FNB"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Account Name</label>
                                <input type="text" name="bank_account_name" value="{{ old('bank_account_name', $contact->bank_account_name) }}"
                                       placeholder="Account holder name"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Account Number</label>
                                <input type="text" name="bank_account_number" value="{{ old('bank_account_number', $contact->bank_account_number) }}"
                                       placeholder="e.g. 62000000000"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Branch Name</label>
                                <input type="text" name="bank_branch_name" value="{{ old('bank_branch_name', $contact->bank_branch_name) }}"
                                       placeholder="e.g. Margate"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Branch Code</label>
                                <input type="text" name="bank_branch_code" value="{{ old('bank_branch_code', $contact->bank_branch_code) }}"
                                       placeholder="e.g. 210835"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Account Type</label>
                                <select name="bank_account_type"
                                        class="w-full rounded-md px-3 py-2 text-sm"
                                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                    <option value="">— Select —</option>
                                    @foreach(['Savings', 'Cheque/Current', 'Transmission'] as $atype)
                                        <option value="{{ $atype }}" {{ old('bank_account_type', $contact->bank_account_type) === $atype ? 'selected' : '' }}>{{ $atype }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Financial Position — buyer pre-approval (spec D3) --}}
                <div x-data="{ open: {{ ($contact->preapproval_amount || $contact->preapproval_expires_at || $contact->preapproval_institution) ? 'true' : 'false' }} }">
                    <button type="button" @click="open = !open" class="flex items-center gap-2 w-full text-left mb-4">
                        <h3 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Financial Position</h3>
                        @if($contact->hasValidPreapproval())
                            <span class="ds-badge ds-badge-success">Pre-approved</span>
                        @elseif($contact->preapproval_amount)
                            <span class="ds-badge ds-badge-warning">Expired</span>
                        @endif
                        <svg :class="open ? 'rotate-180' : ''" class="w-4 h-4 transition-transform" style="color:var(--text-muted);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                    <div x-show="open" x-cloak>
                        <p class="text-[11px] mb-3" style="color:var(--text-muted);">Buyer's verified financial pre-approval. Used for demand intelligence — pre-approved buyers count separately in the prospecting summary.</p>
                        @if($contact->preapproval_amount)
                            <div class="text-[11px] mb-3 rounded-md p-2" style="background:var(--surface-2); color:var(--text-secondary);">
                                Currently: <strong>R {{ number_format((float) $contact->preapproval_amount, 0, '.', ',') }}</strong>
                                @if($contact->preapproval_institution) via {{ $contact->preapproval_institution }} @endif
                                @if($contact->preapproval_expires_at) , expires {{ $contact->preapproval_expires_at->format('d M Y') }} @endif
                            </div>
                        @endif
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Pre-approval Amount (R)</label>
                                <input type="number" name="preapproval_amount" value="{{ old('preapproval_amount', $contact->preapproval_amount) }}"
                                       placeholder="e.g. 2500000" min="0" step="1000"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Pre-approval Expires</label>
                                <input type="date" name="preapproval_expires_at" value="{{ old('preapproval_expires_at', $contact->preapproval_expires_at?->format('Y-m-d')) }}"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Pre-approval Institution</label>
                                <input type="text" name="preapproval_institution" value="{{ old('preapproval_institution', $contact->preapproval_institution) }}"
                                       placeholder="e.g. FNB Home Loans" maxlength="100"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                        </div>
                    </div>
                </div>

                @include('corex.contacts._assigned-agents')

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="corex-btn-primary text-sm">Save Changes</button>
                    <a href="{{ route('corex.contacts.index') }}" class="text-sm" style="color:var(--text-muted);">Cancel</a>
                </div>
            </form>

            @include('corex.contacts.partials.client-app-access', ['contact' => $contact])
        </div>

        {{-- ════════════════════════════
             PROPERTIES TAB
             ════════════════════════════ --}}
        <div x-show="activeTab === 'properties'" x-cloak class="p-6 space-y-6">

            @include('corex.contacts._linked-properties')

            {{-- Link property by address search --}}
            <div class="rounded-md p-5" style="background: var(--surface-2); border: 1px solid var(--border);">
                <h3 class="text-xs font-bold uppercase tracking-widest mb-4" style="color:var(--text-muted);">Link a Property</h3>
                <p class="text-xs mb-4" style="color:var(--text-muted);">Search by address, suburb or title.</p>

                <div class="relative mb-3">
                    <input type="text" x-model="propSearch" @input.debounce.300ms="searchProps()"
                           placeholder="e.g. 21 Dee Road, Uvongo…"
                           class="w-full rounded-md px-3 py-2 text-sm pr-10"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    <div x-show="propLoading" class="absolute right-3 top-2.5">
                        <svg class="animate-spin w-4 h-4" style="color:var(--text-muted);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    </div>
                </div>

                <div x-show="propResults.length > 0" class="rounded-md overflow-hidden mb-3" style="border:1px solid var(--border);">
                    <template x-for="r in propResults" :key="r.id">
                        <form method="POST" action="{{ route('corex.contacts.properties.link', $contact) }}">
                            @csrf
                            <input type="hidden" name="property_id" :value="r.id">
                            <button type="submit" class="w-full flex items-center gap-3 px-4 py-3 text-left hover:opacity-80 transition-colors"
                                    style="border-bottom:1px solid var(--border); background:var(--surface);">
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-semibold" style="color:var(--text-primary);" x-text="r.label || r.address || r.title"></div>
                                    <div class="text-xs mt-0.5" style="color:var(--text-muted);" x-text="(r.address || '') + ' · ' + r.price + (r.agent ? ' · ' + r.agent : '')"></div>
                                </div>
                                <span class="text-xs font-semibold flex-shrink-0 px-2 py-1 rounded-md"
                                      :style="`background:${statusColor(r.status || '')}22; color:${statusColor(r.status || '')}; border:1px solid ${statusColor(r.status || '')}44;`"
                                      x-text="(r.status || '').charAt(0).toUpperCase() + (r.status || '').slice(1)"></span>
                                <span class="text-xs font-semibold flex-shrink-0" style="color:var(--brand-icon, #0ea5e9);">+ Link</span>
                            </button>
                        </form>
                    </template>
                </div>

                <div x-show="propSearched && propResults.length === 0" class="text-sm" style="color:var(--text-muted);">
                    No matching properties found.
                </div>
            </div>

            @include('corex.contacts._held-address-warning')

            <div class="rounded-md p-5" style="background: var(--surface-2); border: 1px solid var(--border);"
                 x-data="contactAddress({{ Js::from([
                    'unitNumber'       => old('unit_number',        $contact->unit_number ?? ''),
                    'floorNumber'      => old('floor_number',       $contact->floor_number ?? ''),
                    'unitSectionBlock' => old('unit_section_block', $contact->unit_section_block ?? ''),
                    'complexName'      => old('complex_name',       $contact->complex_name ?? ''),
                    'streetNumber'     => old('street_number',      $contact->street_number ?? ''),
                    'streetName'       => old('street_name',        $contact->street_name ?? ''),
                    'suburb'           => old('suburb',             $contact->suburb ?? ''),
                    'city'             => old('city',               $contact->city ?? ''),
                    'province'         => old('province',           $contact->province ?? ''),
                 ]) }})">
                <h3 class="text-xs font-bold uppercase tracking-widest mb-1" style="color:var(--text-muted);">Start a Property from an Address</h3>
                <p class="text-xs mb-4" style="color:var(--text-muted);">Capture an address here to create a new property pre-filled with it. This does <strong>not</strong> change the contact's residential address.</p>

                <form method="POST" action="{{ route('corex.contacts.property-address.update', $contact) }}">
                    @csrf @method('PUT')

                    {{-- Read-only composed summary — a real, clearly-editable control (No Invisible Edits, STANDARDS.md) --}}
                    <button type="button" @click="openAddrModal = true"
                            class="w-full flex items-center justify-between gap-3 rounded-md px-3 py-2 text-left transition-all duration-300"
                            style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        <span class="text-sm truncate" x-text="hasAddress ? summary : 'Click to set a property address'"
                              :style="hasAddress ? '' : 'color:var(--text-muted);'"></span>
                        <span class="inline-flex items-center gap-1 flex-shrink-0 text-[11px] font-semibold" style="color:var(--brand-icon, #2563eb);">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            <span x-text="hasAddress ? 'Edit' : 'Set'"></span>
                        </span>
                    </button>

                    {{-- Part 3 — live "already on our books" warning. Fires as the agent
                         types the street/suburb; warns BEFORE they save & prospect so they
                         don't canvass an owner HFC already represents. Read-only check —
                         never mints a property. Honours the agency warn toggle server-side. --}}
                    <div x-show="held" x-cloak class="mt-3 rounded-md p-3"
                         style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 40%, transparent);">
                        <div class="flex items-start gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--ds-amber, #f59e0b);"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                            <div class="text-xs leading-relaxed" style="color:var(--text-primary);">
                                <strong>HFC already has this property on its books</strong> — <span x-text="held && held.label"></span>.
                                <template x-if="held && held.address"><span> (<span x-text="held.address"></span>)</span></template>
                                <div class="mt-1" style="color:var(--text-secondary);">
                                    Check the existing record before canvassing the owner —
                                    <template x-if="held && held.property_url"><a :href="held.property_url" target="_blank" rel="noopener" class="font-semibold" style="color:var(--brand-icon, #2563eb);">open the property record</a></template>
                                    <template x-if="held && !held.property_url && held.tracked_url"><a :href="held.tracked_url" target="_blank" rel="noopener" class="font-semibold" style="color:var(--brand-icon, #2563eb);">open property intel</a></template>.
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Hidden inputs holding the parent-managed components so they submit even while the modal is closed. --}}
                    <input type="hidden" name="unit_number"        :value="unitNumber">
                    <input type="hidden" name="floor_number"       :value="floorNumber">
                    <input type="hidden" name="unit_section_block" :value="unitSectionBlock">
                    <input type="hidden" name="complex_name"       :value="complexName">
                    <input type="hidden" name="street_number"      :value="streetNumber">
                    <input type="hidden" name="street_name"        :value="streetName">

                    <div class="flex items-center gap-2 mt-3 flex-wrap">
                        <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--brand-button, #0ea5e9); color:#fff;">Save address</button>
                        @if($contact->hasStructuredAddress())
                            <a href="{{ route('corex.properties.create', ['contact_id' => $contact->id]) }}"
                               target="_blank" rel="noopener"
                               class="inline-flex items-center gap-1 text-xs font-semibold px-3 py-1.5 rounded-md transition-all duration-300"
                               style="background:color-mix(in srgb, var(--brand-icon, #2563eb) 12%, transparent); color:var(--brand-icon, #2563eb);"
                               title="Create a property record pre-filled with this address and link this contact to it (opens in a new tab)">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                                Use for property
                            </a>
                            {{-- AT-61 follow-up — REMOVE the captured property-address (completes
                                 CRUD). Submits the sibling DELETE form below via the HTML5 `form=`
                                 attribute (forms cannot legally nest). Only shown when an address is
                                 actually present. Clearing turns the address-only outreach bypass OFF
                                 and does NOT touch the residential address or any linked property. --}}
                            <button type="submit"
                                    form="clear-property-address-{{ $contact->id }}"
                                    onclick="return confirm('Remove this captured property address?\n\nThis clears the address from {{ addslashes($contact->first_name ?: 'this contact') }} and turns OFF the address-only pitch. The contact\'s residential address and any property you already created from it are NOT affected.');"
                                    class="inline-flex items-center gap-1 text-xs font-semibold px-3 py-1.5 rounded-md transition-all duration-300"
                                    style="background:color-mix(in srgb, var(--ds-crimson, #dc2626) 12%, transparent); color:var(--ds-crimson, #dc2626);"
                                    title="Remove the captured property address from this contact. Does not affect the residential address or any property already created from it.">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                Remove address
                            </button>
                        @endif
                    </div>

                    {{-- ===== PROPERTY-ADDRESS MODAL ===== --}}
                    <div x-show="openAddrModal" x-cloak
                         class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
                         @keydown.escape.window="openAddrModal = false">
                        <div class="absolute inset-0 bg-black/60" @click="openAddrModal = false"></div>
                        <div class="relative w-full max-w-[46rem] max-h-[85vh] overflow-y-auto rounded-lg shadow-2xl"
                             style="background:var(--surface); border:1px solid var(--border);" @click.stop>

                            <div class="sticky top-0 z-10 flex items-center justify-between px-5 py-3 rounded-t-lg"
                                 style="background:var(--surface-2); border-bottom:1px solid var(--border); color:var(--text-primary);">
                                <div class="flex items-center gap-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--brand-icon, #0ea5e9);"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"/><circle cx="12" cy="11" r="3"/></svg>
                                    <span class="text-sm font-bold">Property Address</span>
                                </div>
                                <button type="button" @click="openAddrModal = false" class="p-1 rounded" style="color:var(--text-muted);" onmouseover="this.style.background='var(--surface)'" onmouseout="this.style.background='transparent'">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                </button>
                            </div>

                            <div class="p-5 space-y-5">
                                {{-- Complex or Estate --}}
                                <div>
                                    <div class="text-[0.6875rem] font-bold uppercase tracking-wider text-center py-1.5 rounded-t-md" style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 10%, transparent); border:1px solid var(--border); border-bottom:0; color:var(--brand-icon, #0ea5e9);">Complex or Estate</div>
                                    <div class="p-4 rounded-b-md space-y-3" style="background:var(--surface-2); border:1px solid var(--border); border-top:0;">
                                        <div class="grid grid-cols-2 gap-3">
                                            <div>
                                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Unit Number</label>
                                                <input type="text" x-model="unitNumber" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Floor Number</label>
                                                <input type="text" x-model="floorNumber" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Name of Unit, Section or Block</label>
                                            <input type="text" x-model="unitSectionBlock" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Name of Complex or Estate</label>
                                            <input type="text" x-model="complexName" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                    </div>
                                </div>

                                {{-- Street --}}
                                <div>
                                    <div class="text-[0.6875rem] font-bold uppercase tracking-wider text-center py-1.5 rounded-t-md" style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 10%, transparent); border:1px solid var(--border); border-bottom:0; color:var(--brand-icon, #0ea5e9);">Street</div>
                                    <div class="p-4 rounded-b-md space-y-3" style="background:var(--surface-2); border:1px solid var(--border); border-top:0;">
                                        <div>
                                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Street Number</label>
                                            <input type="text" x-model="streetNumber" placeholder="e.g. 21" autocomplete="off" class="w-40 rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Street Name</label>
                                            <input type="text" x-model="streetName" placeholder="e.g. Dee Road" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                    </div>
                                </div>

                                {{-- Province / City / Suburb — Property24-backed typeahead (shared partial).
                                     fieldPrefix 'contact_addr' so it never cross-fires a property picker. --}}
                                <div>
                                    <div class="text-[0.6875rem] font-bold uppercase tracking-wider text-center py-1.5 rounded-t-md" style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 10%, transparent); border:1px solid var(--border); border-bottom:0; color:var(--brand-icon, #0ea5e9);">Province / City / Suburb</div>
                                    <div class="p-4 rounded-b-md" style="background:var(--surface-2); border:1px solid var(--border); border-top:0;">
                                        @include('corex._partials.p24-location-picker', [
                                            'fieldPrefix'         => 'contact_addr',
                                            'initialProvinceId'   => old('contact_addr_province_id', $contact->p24_province_id ?? 0),
                                            'initialCityId'       => old('contact_addr_city_id',     $contact->p24_city_id ?? 0),
                                            'initialSuburbId'     => old('contact_addr_suburb_id',   $contact->p24_suburb_id ?? 0),
                                            'initialProvinceName' => old('province', $contact->province ?? ''),
                                            'initialCityName'     => old('city',     $contact->city ?? ''),
                                            'initialSuburbName'   => old('suburb',   $contact->suburb ?? ''),
                                            'denormaliseNames'    => true,
                                        ])
                                        <p class="text-[11px] mt-2" style="color:var(--text-muted);">Suburb is optional, but if you type one it must be picked from the Property24 list so it links cleanly to a property later.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="sticky bottom-0 px-5 py-3 rounded-b-lg flex items-center justify-between" style="background:var(--surface); border-top:1px solid var(--border);">
                                <button type="button" @click="clearAddress()" x-show="hasAddress"
                                        class="px-3 py-2 rounded-md text-xs font-semibold transition-all duration-300"
                                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--ds-crimson, #dc2626);">Clear address</button>
                                <span x-show="!hasAddress"></span>
                                <button type="button" @click="openAddrModal = false" class="px-4 py-2 rounded-md text-xs font-semibold text-white" style="background:var(--ds-green, #16a34a);">Done</button>
                            </div>
                        </div>
                    </div>
                </form>

                {{-- AT-61 follow-up — sibling DELETE form for "Remove address" (kept
                     OUTSIDE the update form above so the markup never nests forms).
                     Triggered by the Remove button via its `form=` attribute. --}}
                @if($contact->hasStructuredAddress())
                    <form id="clear-property-address-{{ $contact->id }}" method="POST"
                          action="{{ route('corex.contacts.property-address.clear', $contact) }}" class="hidden">
                        @csrf @method('DELETE')
                    </form>
                @endif
            </div>

        </div>

        {{-- ════════════════════════════
             NOTES TAB
             ════════════════════════════ --}}
        <div x-show="activeTab === 'notes'" x-cloak class="p-6 space-y-5" id="tab-notes">

            {{-- ════════════════════════════ TESTIMONIALS ════════════════════════════ --}}
            <div class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-bold" style="color:var(--text-primary);">Testimonials</h3>
                    <span class="text-xs" style="color:var(--text-muted);">Captured here · publish to the website in <span class="font-semibold">Company Settings → Website</span></span>
                </div>

                {{-- Add testimonial --}}
                <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);" x-data="{ rating: 0 }">
                    <div class="text-xs font-semibold mb-3" style="color:var(--text-secondary);">Add Testimonial</div>
                    <form method="POST" action="{{ route('corex.contacts.testimonials.store', $contact) }}" class="space-y-3">
                        @csrf
                        <textarea name="body" rows="3" required placeholder="What did the client say?"
                                  class="w-full rounded-md px-3 py-2 text-sm resize-none"
                                  style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Public display name</label>
                                <input type="text" name="display_name" maxlength="150"
                                       value="{{ trim(($contact->first_name ?? '').' '.($contact->last_name ?? '')) }}"
                                       placeholder="Name shown on the website"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Agent it's about</label>
                                <select name="agent_id"
                                        class="w-full rounded-md px-3 py-2 text-sm"
                                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    @foreach(($agencyAgents ?? collect()) as $ag)
                                        <option value="{{ $ag->id }}" {{ (int) $ag->id === (int) auth()->id() ? 'selected' : '' }}>{{ $ag->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Rating (optional)</label>
                                <input type="hidden" name="rating" :value="rating || ''">
                                <div class="flex items-center gap-1">
                                    <template x-for="star in 5" :key="star">
                                        <button type="button" @click="rating = (rating === star ? 0 : star)"
                                                class="text-xl leading-none"
                                                :style="star <= rating ? 'color:var(--ds-amber, #f5b301);' : 'color:var(--text-muted); opacity:.5;'">★</button>
                                    </template>
                                    <button type="button" x-show="rating > 0" @click="rating = 0" class="ml-2 text-xs" style="color:var(--text-muted);">clear</button>
                                </div>
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="corex-btn-primary text-sm">Add Testimonial</button>
                        </div>
                    </form>
                </div>

                {{-- Testimonials list --}}
                @forelse($contact->testimonials as $testimonial)
                <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);" x-data="{ editing: false }">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-semibold" style="color:var(--text-primary);">{{ $testimonial->display_name }}</span>
                                @if($testimonial->rating)
                                    <span class="text-sm" style="color:var(--ds-amber, #f5b301);">{{ str_repeat('★', (int) $testimonial->rating) }}<span style="color:var(--text-muted); opacity:.4;">{{ str_repeat('★', 5 - (int) $testimonial->rating) }}</span></span>
                                @endif
                            </div>
                            <div class="text-xs" style="color:var(--text-muted);">
                                {{ $testimonial->user?->name ?? 'Unknown' }} · {{ $testimonial->created_at->format('d M Y') }}
                                @if($testimonial->agent)
                                    · <span style="color:var(--text-secondary);">About {{ $testimonial->agent->name }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="flex items-center gap-3 flex-shrink-0">
                            @if($testimonial->published)
                                <span class="text-[10px] font-semibold uppercase px-1.5 py-0.5 rounded" style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 15%, transparent); color:var(--brand-icon, #0ea5e9);">On website</span>
                            @else
                                <span class="text-[10px] font-semibold uppercase px-1.5 py-0.5 rounded" style="background:var(--surface); color:var(--text-muted); border:1px solid var(--border);">Not published</span>
                            @endif
                            <button type="button" @click="editing = !editing" class="text-xs font-semibold" style="color:var(--brand-icon, #0ea5e9);">Edit</button>
                            <form method="POST" action="{{ route('corex.contacts.testimonials.destroy', [$contact, $testimonial]) }}"
                                  onsubmit="return confirm('Delete this testimonial?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-xs font-semibold" style="color: var(--ds-crimson);">Delete</button>
                            </form>
                        </div>
                    </div>

                    {{-- Read view --}}
                    <div class="mt-3 text-sm whitespace-pre-line" style="color:var(--text-primary);" x-show="!editing">{{ $testimonial->body }}</div>

                    {{-- Edit view --}}
                    <form x-show="editing" x-cloak method="POST" action="{{ route('corex.contacts.testimonials.update', [$contact, $testimonial]) }}"
                          class="mt-3 space-y-3" x-data="{ rating: {{ (int) $testimonial->rating }} }">
                        @csrf @method('PUT')
                        <textarea name="body" rows="3" required class="w-full rounded-md px-3 py-2 text-sm resize-none"
                                  style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">{{ $testimonial->body }}</textarea>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Public display name</label>
                                <input type="text" name="display_name" maxlength="150" value="{{ $testimonial->display_name }}"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Agent it's about</label>
                                <select name="agent_id"
                                        class="w-full rounded-md px-3 py-2 text-sm"
                                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    @foreach(($agencyAgents ?? collect()) as $ag)
                                        <option value="{{ $ag->id }}" {{ (int) $ag->id === (int) $testimonial->agent_id ? 'selected' : '' }}>{{ $ag->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Rating (optional)</label>
                                <input type="hidden" name="rating" :value="rating || ''">
                                <div class="flex items-center gap-1">
                                    <template x-for="star in 5" :key="star">
                                        <button type="button" @click="rating = (rating === star ? 0 : star)"
                                                class="text-xl leading-none"
                                                :style="star <= rating ? 'color:var(--ds-amber, #f5b301);' : 'color:var(--text-muted); opacity:.5;'">★</button>
                                    </template>
                                    <button type="button" x-show="rating > 0" @click="rating = 0" class="ml-2 text-xs" style="color:var(--text-muted);">clear</button>
                                </div>
                            </div>
                        </div>
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="editing = false" class="text-sm px-3 py-1.5 rounded-md" style="border:1px solid var(--border); color:var(--text-secondary);">Cancel</button>
                            <button type="submit" class="corex-btn-primary text-sm">Save</button>
                        </div>
                    </form>
                </div>
                @empty
                <div class="rounded-md py-8 px-6 text-center" style="background: var(--surface); border: 1px dashed var(--border);">
                    <p class="text-sm" style="color: var(--text-muted);">No testimonials captured yet. Add one above when a client gives you positive feedback.</p>
                </div>
                @endforelse
            </div>

            <div style="border-top:1px solid var(--border);"></div>

            {{-- Add note --}}
            <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                <div class="text-xs font-semibold mb-3" style="color:var(--text-secondary);">Add Note</div>
                <form method="POST" action="{{ route('corex.contacts.notes.store', $contact) }}" class="space-y-3">
                    @csrf
                    <textarea name="body" rows="3" required
                              placeholder="Write a note…"
                              class="w-full rounded-md px-3 py-2 text-sm resize-none"
                              style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                    <div class="flex justify-end">
                        <button type="submit" class="corex-btn-primary text-sm">Add Note</button>
                    </div>
                </form>
            </div>

            {{-- Notes list --}}
            @forelse($contact->contactNotes as $note)
            <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
                             style="background:var(--brand-default, #0b2a4a);">
                            {{ strtoupper(substr($note->user?->name ?? '?', 0, 1)) }}
                        </div>
                        <div>
                            <div class="text-xs font-semibold" style="color:var(--text-primary);">{{ $note->user?->name ?? 'Unknown' }}</div>
                            <div class="text-xs" style="color:var(--text-muted);">{{ $note->created_at->format('d M Y H:i') }} · {{ $note->created_at->diffForHumans() }}</div>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('corex.contacts.notes.destroy', [$contact, $note]) }}"
                          onsubmit="return confirm('Delete this note?');">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-xs font-semibold flex-shrink-0" style="color: var(--ds-crimson);">Delete</button>
                    </form>
                </div>
                <div class="mt-3 text-sm whitespace-pre-line" style="color:var(--text-primary);">{{ $note->body }}</div>
            </div>
            @empty
            <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" /></svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No notes yet</h3>
                <p class="text-sm" style="color: var(--text-muted);">Use the form above to record your first note for this contact.</p>
            </div>
            @endforelse
        </div>

        {{-- ════════════════════════════
             DRIVE TAB
             ════════════════════════════ --}}
        <div x-show="activeTab === 'drive'" x-cloak class="p-6 space-y-5" id="tab-drive"
             x-data="{ dragging: false }">
            @include('corex.contacts._drive-tab-body')
        </div>

        {{-- ════════════════════════════
             FICA COMPLIANCE TAB
             ════════════════════════════ --}}
        <div x-show="activeTab === 'fica'" x-cloak class="p-6 space-y-6" id="tab-fica">
            @include('corex.contacts._fica-tab-body')
        </div>

        {{-- ════════════════════════════
             CONSENT & COMPLIANCE TAB (M3.4)
             ════════════════════════════ --}}
        <div x-show="activeTab === 'consent'" x-cloak class="p-6 space-y-4" id="tab-consent">
            @include('corex.contacts._consent-tab-body')
        </div>

        {{-- ════════════════════════════
             CORE MATCHES (merged into Properties tab)
             ════════════════════════════ --}}
        @if(\App\Models\PerformanceSetting::get('matches_enabled', 1) && auth()->user()->hasPermission('access_core_matches'))
        <div x-show="activeTab === 'properties'" x-cloak class="p-6 pt-0 space-y-6" id="tab-matches">

            {{-- Core Matches section header --}}
            <div class="pt-2 border-t" style="border-color:var(--border);">
                <h3 class="text-sm font-bold uppercase tracking-wide pt-4" style="color:var(--text-primary);">Core Matches</h3>
                <p class="text-xs mt-1" style="color:var(--text-muted);">Buyer/tenant requirements matched against tracked property intelligence.</p>
            </div>

            {{-- Add new match form --}}
            <div class="rounded-md p-5 space-y-5" style="background:var(--surface); border:1px solid var(--border);">
                <h3 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Add New Match Criteria</h3>

                @include('corex.contacts._match-form', ['contact' => $contact, 'match' => null])
            </div>

            {{-- Existing matches --}}
            @if($contact->matches->count())
            <div class="space-y-3">
                <h3 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Saved Matches ({{ $contact->matches->count() }})</h3>
                @foreach($contact->matches as $match)
                <div class="rounded-md p-4" style="background:var(--surface); border:1px solid var(--border);">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0 space-y-3">

                            {{-- Header row: type badge + price + primary flag --}}
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="ds-badge {{ $match->listing_type === 'rental' ? 'ds-badge-info' : 'ds-badge-default' }}"
                                      style="{{ $match->listing_type === 'rental' ? '' : 'background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon); border: 1px solid color-mix(in srgb, var(--brand-icon) 25%, transparent);' }}">
                                    {{ $match->listingTypeLabel() }}
                                </span>
                                @if($match->is_primary)
                                <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-md whitespace-nowrap"
                                      style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 18%, transparent); color:var(--ds-amber, #f59e0b); border:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 35%, transparent);"
                                      title="This is the contact's primary wishlist — used for demand intelligence">
                                    ⭐ Primary
                                </span>
                                @endif
                                @if($match->price_min || $match->price_max)
                                <span class="text-sm font-bold" style="color:var(--text-primary);">{{ $match->priceRangeLabel() }}</span>
                                @endif
                                @if($match->suburb)
                                <span class="text-xs px-2 py-0.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">
                                    📍 {{ $match->suburb }}
                                </span>
                                @endif
                            </div>

                            {{-- Detail grid --}}
                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-x-4 gap-y-1.5 min-w-0 break-words">
                                @if($match->category)
                                <div>
                                    <span class="text-[10px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Category</span>
                                    <div class="text-xs font-medium mt-0.5" style="color:var(--text-primary);">{{ $match->category }}</div>
                                </div>
                                @endif
                                @if($match->property_type)
                                <div>
                                    <span class="text-[10px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Type</span>
                                    <div class="text-xs font-medium mt-0.5" style="color:var(--text-primary);">{{ $match->property_type }}</div>
                                </div>
                                @endif
                                @foreach([[$match->beds_min,'Beds'],[$match->baths_min,'Baths'],[$match->garages_min,'Garages'],[$match->parking_min,'Parking']] as [$val,$lbl])
                                @if($val !== null)
                                <div>
                                    <span class="text-[10px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">{{ $lbl }}</span>
                                    <div class="text-xs font-medium mt-0.5" style="color:var(--text-primary);">{{ $val }}+</div>
                                </div>
                                @endif
                                @endforeach
                                @if($match->floor_size_min || $match->floor_size_max)
                                <div>
                                    <span class="text-[10px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Floor m²</span>
                                    <div class="text-xs font-medium mt-0.5" style="color:var(--text-primary);">
                                        {{ $match->floor_size_min ? number_format($match->floor_size_min) : '—' }} – {{ $match->floor_size_max ? number_format($match->floor_size_max) : '—' }}
                                    </div>
                                </div>
                                @endif
                                @if($match->erf_size_min || $match->erf_size_max)
                                <div>
                                    <span class="text-[10px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Erf m²</span>
                                    <div class="text-xs font-medium mt-0.5" style="color:var(--text-primary);">
                                        {{ $match->erf_size_min ? number_format($match->erf_size_min) : '—' }} – {{ $match->erf_size_max ? number_format($match->erf_size_max) : '—' }}
                                    </div>
                                </div>
                                @endif
                            </div>

                            @if($match->notes)
                            <p class="text-xs leading-relaxed" style="color:var(--text-muted);">{{ $match->notes }}</p>
                            @endif

                            <div class="flex items-center justify-between gap-3 flex-wrap">
                                <div class="text-[10px]" style="color:var(--text-muted);">
                                    Added {{ $match->created_at->diffForHumans() }}
                                    @if($match->createdBy) · by {{ $match->createdBy->name }} @endif
                                </div>
                                <div class="flex items-center gap-2">
                                    @if(!$match->is_primary)
                                    <form method="POST" action="{{ route('corex.contacts.matches.update', [$contact, $match]) }}" class="inline">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="is_primary" value="1">
                                        <button type="submit"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-all duration-300"
                                                style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 10%, transparent); color:var(--ds-amber, #f59e0b); border:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 25%, transparent);"
                                                title="Mark this wishlist as the contact's primary">
                                            ⭐ Make Primary
                                        </button>
                                    </form>
                                    @endif
                                    {{-- AT-240 — edit this wishlist/criteria (permission-gated by the
                                         enclosing access_core_matches block). Opens the existing edit flow. --}}
                                    <a href="{{ route('corex.contacts.matches.edit', [$contact, $match]) }}"
                                       class="corex-btn-outline text-xs no-underline"
                                       title="Edit this wishlist / match criteria">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" /></svg>
                                        Edit
                                    </a>
                                    <a href="{{ route('corex.contacts.matches.results', [$contact, $match]) }}"
                                       class="corex-btn-outline text-xs no-underline">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" /></svg>
                                        View Matches
                                    </a>
                                </div>
                            </div>
                        </div>

                        {{-- Delete --}}
                        <form method="POST" action="{{ route('corex.contacts.matches.destroy', [$contact, $match]) }}"
                              onsubmit="return confirm('Remove this match criteria?');"
                              class="flex-shrink-0">
                            @csrf @method('DELETE')
                            <button type="submit"
                                    class="p-1.5 rounded-md transition-all duration-300"
                                    style="color: var(--ds-crimson);">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                            </button>
                        </form>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" /></svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No match criteria saved</h3>
                <p class="text-sm" style="color: var(--text-muted);">Use the form above to add what this contact is looking for.</p>
            </div>
            @endif

        </div>{{-- /matches (under Properties) --}}
        @endif

        {{-- ══════════════════════════════════════════
             VIEWINGS & FEEDBACK TAB
             ════════════════════════════════════════ --}}
        <div x-show="activeTab === 'viewings'" x-cloak class="p-6 space-y-6" id="tab-viewings">

            {{-- Viewing Packs (AT-110 discoverability) — find/open/edit packs built for this contact. --}}
            @include('command-center.viewing-packs._packs-section', ['contact' => $contact])

            {{-- Buyer perspective — ALL linked appointments (property optional) + provide-feedback-from-here (AT-114). --}}
            @include('command-center.calendar._linked-events', ['contact' => $contact])

            {{-- Seller perspective --}}
            @if(($sellerUpcoming ?? collect())->isNotEmpty() || ($sellerPast ?? collect())->isNotEmpty())
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:var(--text-muted);">Seller — Feedback on Your Listings</h3>
                    @foreach($sellerPast ?? [] as $sv)
                        <div class="rounded-md p-4 mb-2" style="background:var(--surface); border:1px solid var(--border);">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('corex.properties.show', $sv['property_id']) }}" target="_blank"
                                       class="text-sm font-semibold no-underline hover:underline" style="color:var(--text-primary);">{{ $sv['address'] }}</a>
                                    <div class="text-[10px] mt-0.5" style="color:var(--text-muted);">Viewed by: {{ $sv['buyer_label'] }}</div>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <div class="text-[10px]" style="color:var(--text-muted);">{{ \Carbon\Carbon::parse($sv['event_date'])->format('D, j M Y') }}</div>
                                    <div class="text-[10px]" style="color:var(--text-muted);">Agent: {{ $sv['agent_name'] }}</div>
                                </div>
                            </div>
                            @if($sv['feedback'] ?? null)
                                <div class="mt-2 rounded px-3 py-2" style="background:var(--surface-2); border:1px solid var(--border);">
                                    @if($sv['feedback']['outcome_label'] ?? null)
                                        <span class="text-[10px] font-semibold uppercase px-1.5 py-0.5 rounded-md" style="background:color-mix(in srgb, var(--ds-green, #059669) 15%, transparent); color:var(--ds-green, #059669);">{{ $sv['feedback']['outcome_label'] }}</span>
                                    @endif
                                    @if($sv['feedback']['seller_notes'] ?? null)
                                        <p class="text-xs mt-1" style="color:var(--text-secondary);">{{ $sv['feedback']['seller_notes'] }}</p>
                                    @endif
                                    <div class="text-[10px] mt-1" style="color:var(--text-muted);">Captured {{ \Carbon\Carbon::parse($sv['feedback']['captured_at'])->diffForHumans() }}</div>
                                </div>
                            @else
                                <span class="ds-badge ds-badge-default mt-1">No feedback</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if(($buyerViewings ?? collect())->isEmpty() && ($sellerViewings ?? collect())->isEmpty())
                <div class="py-12 text-center">
                    <p class="text-sm" style="color:var(--text-muted);">No viewings or feedback recorded for this contact.</p>
                </div>
            @endif

        </div>{{-- /viewings tab --}}

        {{-- ════════════════════════════
             OUTREACH TAB (Prompt 07)
             ════════════════════════════ --}}
        {{-- ════════════════════════════
             COMMUNICATIONS TAB (AT-43) — linked archive comms (email + WhatsApp)
             ════════════════════════════ --}}
        {{-- AT-132 Wave 1 — per-thread list. Safe metadata for every thread (channel,
             date, message count, owning agent, attachment flag, subject unless the
             owner hid it); BODIES stay gated per row. Visible threads open to the
             archive; gated threads show a per-thread "Request access". Never renders
             body / body_preview / message content. DESIGN SYSTEM COMPLIANCE:
             UI_DESIGN_SYSTEM.md (tokens via var(), no emojis, sharp corners). --}}
        @if(($canViewComms ?? false) || ($canRequestComms ?? false))
        <div x-show="activeTab === 'communications'" x-cloak class="p-6 space-y-4" id="tab-communications">
            @include('corex.contacts._communications-tab-body')
        </div>
        @endif

        @if(auth()->user()->hasPermission('outreach.compose') && isset($outreachSends))
        <div x-show="activeTab === 'outreach'" x-cloak class="p-6 space-y-6" id="tab-outreach">
            @include('seller-outreach.contact-timeline._panel', [
                'contact'        => $contact,
                'sends'          => $outreachSends,
                'clickCounts'    => $outreachClickCounts ?? collect(),
                'optedOut'       => $contact->messaging_opt_out_at !== null,
                'optedIn'        => $contact->messaging_opted_in_at !== null,
                'outcomeOptions' => $outreachOutcomeOptions ?? [],
            ])
        </div>
        @endif

        {{-- ── HISTORY TAB (AT-321-C — contact audit trail) ─────────────────── --}}
        <div x-show="activeTab === 'history'" x-cloak class="p-6 space-y-4" id="tab-history">
            @include('corex.contacts._history-tab-body')
        </div>

    </div>{{-- /tab container --}}

</div>

<script>
// AT-60 — structured contact address modal + live summary.
function contactAddress(config) {
    return {
        openAddrModal: false,
        unitNumber:       config.unitNumber       || '',
        floorNumber:      config.floorNumber      || '',
        unitSectionBlock: config.unitSectionBlock || '',
        complexName:      config.complexName      || '',
        streetNumber:     config.streetNumber     || '',
        streetName:       config.streetName       || '',
        // Province/City/Suburb are owned by the P24 picker; mirrored here for the
        // summary via the namespaced "p24-location-changed:contact_addr" event so
        // the property pickers on other pages never cross-fire this one.
        suburb:   config.suburb   || '',
        city:     config.city     || '',
        province: config.province || '',

        // Part 3 — "already on our books" live check.
        heldChecking: false,
        held: null,

        init() {
            window.addEventListener('p24-location-changed:contact_addr', (e) => {
                if (!e.detail) return;
                this.suburb   = e.detail.suburbName   || '';
                this.city     = e.detail.cityName     || '';
                this.province = e.detail.provinceName || '';
                this.queueHeldCheck();
            });

            // Debounced held-address check as the agent types the street/suburb.
            let t;
            this._queueHeldCheck = () => { clearTimeout(t); t = setTimeout(() => this.checkHeld(), 450); };
            this.$watch('streetName',  () => this.queueHeldCheck());
            this.$watch('streetNumber', () => this.queueHeldCheck());
            this.$watch('complexName', () => this.queueHeldCheck());
            // Run once on open if an address is already present.
            if (this.streetName || this.streetNumber) this.queueHeldCheck();
        },

        queueHeldCheck() { if (this._queueHeldCheck) this._queueHeldCheck(); },

        async checkHeld() {
            // Need at least a street name or number — a suburb alone is too broad.
            if (!this.streetName && !this.streetNumber) { this.held = null; return; }
            this.heldChecking = true;
            try {
                const res = await fetch('{{ route('corex.contacts.check-held-address') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        street_number: this.streetNumber, street_name: this.streetName,
                        unit_number: this.unitNumber, complex_name: this.complexName,
                        suburb: this.suburb, city: this.city, province: this.province,
                    }),
                });
                if (!res.ok) { this.held = null; return; }
                const data = await res.json();
                this.held = data.held ? data : null;
            } catch (e) {
                this.held = null;
            } finally {
                this.heldChecking = false;
            }
        },

        get summary() {
            const parts = [];
            if (this.unitNumber)       parts.push('Unit ' + this.unitNumber.trim());
            if (this.unitSectionBlock) parts.push(this.unitSectionBlock.trim());
            if (this.complexName)      parts.push(this.complexName.trim());
            if (this.streetNumber && this.streetName) parts.push((this.streetNumber + ' ' + this.streetName).trim());
            else if (this.streetName)  parts.push(this.streetName.trim());
            if (this.suburb)           parts.push(this.suburb.trim());
            if (this.city && this.city.toLowerCase() !== (this.suburb || '').toLowerCase()) parts.push(this.city.trim());
            if (this.province)         parts.push(this.province.trim());
            return parts.filter(Boolean).join(', ');
        },

        get hasAddress() { return this.summary.length > 0; },

        clearAddress() {
            this.unitNumber = ''; this.floorNumber = ''; this.unitSectionBlock = '';
            this.complexName = ''; this.streetNumber = ''; this.streetName = '';
            this.suburb = ''; this.city = ''; this.province = '';
            // Reset the P24 picker (clears its hidden ids/names too).
            window.dispatchEvent(new CustomEvent('p24-location-reset:contact_addr'));
        },
    };
}

function contactShowData(searchUrl, initTab) {
    // Core Matches was merged into the Properties tab — keep legacy ?tab=matches links working
    if (initTab === 'matches') initTab = 'properties';
    return {
        activeTab: initTab || 'info',
        initTab: initTab || 'info',
        propSearch: '',
        propResults: [],
        propLoading: false,
        propSearched: false,
        async searchProps() {
            if (this.propSearch.length < 1) { this.propResults = []; this.propSearched = false; return; }
            this.propLoading = true;
            try {
                const r = await fetch(searchUrl + '?q=' + encodeURIComponent(this.propSearch), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                this.propResults = await r.json();
                this.propSearched = true;
            } finally { this.propLoading = false; }
        },
        statusColor(s) {
            return {active:'#22c55e', draft:'#94a3b8', sold:'#3b82f6', withdrawn:'#f59e0b'}[s] || '#94a3b8';
        }
    };
}
document.addEventListener('DOMContentLoaded', function () {
    const hash = window.location.hash;
    if (hash === '#tab-notes') {
        document.querySelector('[\\@click="activeTab = \'notes\'"]')?.click();
    } else if (hash === '#tab-drive') {
        document.querySelector('[\\@click="activeTab = \'drive\'"]')?.click();
    }
});
</script>
@endsection
