@extends('layouts.corex')

@section('corex-content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5"
     x-data="composerState({
         contactId: {{ $contact->id }},
         channel: @js($channel),
         propertyId: {{ $property?->id ?? 'null' }},
         templateId: {{ $context?->template?->id ?? 'null' }},
         body: @js($context?->renderedBody ?? ''),
         subject: @js($context?->renderedSubject ?? ''),
         submitUrl: @js(route('seller-outreach.composer.submit', $contact)),
         sentUrlBase: @js(url('/corex/contacts/' . $contact->id . '/outreach/sent')),
         csrfToken: @js(csrf_token()),
         windowAllowed: @js($outreachWindow['allowed'] ?? true),
         windowMessage: @js($outreachWindow['message'] ?? ''),
         queueUrl: @js(route('seller-outreach.composer.queue', $contact)),
         contactUrl: @js(route('corex.contacts.show', $contact)),
     })">

    {{-- Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <a href="{{ route('corex.contacts.show', $contact) }}" class="inline-flex items-center gap-1 text-xs no-underline" style="color: rgba(255,255,255,0.7);">
                    ← Back to {{ trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: 'contact' }}
                </a>
                <h1 class="text-xl font-bold text-white leading-tight mt-1">Compose Seller Pitch</h1>
                <p class="text-sm text-white/60">
                    Defensible, data-backed pitch via {{ $channel === 'whatsapp' ? 'WhatsApp' : 'Email' }}.
                    Every claim is sourced live; every send is recorded for PPRA compliance.
                </p>
            </div>
        </div>
    </div>

    {{-- Flash --}}
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            {{ session('error') }}
        </div>
    @endif
    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif

    {{-- Empty state: contact has neither a linked property NOR a captured
         structured address. AT-61 — when an address IS captured we render the
         composer in address-only mode below instead of this dead-end. --}}
    @if($linkedProperties->isEmpty() && !($addressOnly ?? false))
        <div class="rounded-md px-6 py-10 text-center"
             style="background: var(--surface); border: 1px dashed var(--border); color: var(--text-secondary);">
            <h2 class="text-lg font-semibold mb-2" style="color: var(--text-primary);">
                No property or address to pitch about
            </h2>
            <p class="text-sm mb-4">
                The composer needs either a linked property or a captured address to pitch about.
                Link a property to {{ $contact->first_name ?: 'this contact' }}, or capture the
                property address on the contact, first.
            </p>
            <a href="{{ route('corex.contacts.show', $contact) }}"
               class="inline-flex items-center px-4 py-2 rounded text-sm font-semibold"
               style="background: #00d4aa; color: #003a2f;">
                Open contact to link a property →
            </a>
        </div>
    @else

        {{-- Two-column composer (60/40 on lg+) --}}
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-5">
            <div class="lg:col-span-3">
                @include('seller-outreach._compose-form', [
                    'contact'            => $contact,
                    'property'           => $property,
                    'linkedProperties'   => $linkedProperties,
                    'channel'            => $channel,
                    'availableTemplates' => $availableTemplates,
                    'context'            => $context,
                    'propertyStatuses'   => $propertyStatuses ?? [],
                    'addressOnly'        => $addressOnly ?? false,
                ])
            </div>
            <div class="lg:col-span-2">
                @include('seller-outreach._compose-facts', ['context' => $context])
            </div>
        </div>
    @endif
</div>

<script>
function composerState(init) {
    return {
        ...init,
        sending: false,
        // AT-117 — add-to-queue (no due-time; ready immediately).
        queuing: false,
        // BUG-1 fix — read-only preview only. The per-send links (opt-out / opt-in /
        // tracking) get their real URLs minted at send time, so show a friendly
        // stand-in here instead of raw {tokens}. The editable body keeps the literal
        // tokens (they round-trip to the saved template and the sender substitutes the
        // real URLs into body_snapshot).
        previewBody() {
            return (this.body || '')
                .replace(/\{opt_out_link\}/g, '(one-tap opt-out link)')
                .replace(/\{opt_in_link\}/g, '(one-tap opt-in link)')
                .replace(/\{tracking_link\}/g, '(tracking link)');
        },
        switchChannel(newChannel) {
            const url = new URL(window.location.href);
            url.searchParams.set('channel', newChannel);
            url.searchParams.delete('body');
            url.searchParams.delete('subject');
            url.searchParams.delete('template_id');
            window.location.href = url.toString();
        },
        switchProperty(newPropertyId) {
            const url = new URL(window.location.href);
            url.searchParams.set('property_id', newPropertyId);
            url.searchParams.delete('body');
            url.searchParams.delete('subject');
            window.location.href = url.toString();
        },
        switchTemplate(newTemplateId) {
            const url = new URL(window.location.href);
            url.searchParams.set('template_id', newTemplateId);
            url.searchParams.delete('body');
            url.searchParams.delete('subject');
            window.location.href = url.toString();
        },
        async submit() {
            if (this.sending) return;
            // AT-117 §4a — send-window lock. Client prevent (immediate message);
            // the server submit endpoint also refuses out-of-window (defense in depth).
            if (this.windowAllowed === false) {
                alert(this.windowMessage || 'Outreach sending is closed right now.');
                return;
            }
            this.sending = true;
            try {
                const res = await fetch(this.submitUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: new URLSearchParams({
                        // AT-61 — address-only mode has no property; send ''
                        // (not the JS literal null, which would serialise to
                        // the string "null" and fail `nullable|integer`).
                        property_id: this.propertyId || '',
                        channel: this.channel,
                        template_id: this.templateId || '',
                        subject: this.subject || '',
                        body: this.body || '',
                    }),
                });
                const data = await res.json();
                if (!res.ok) {
                    alert(data.message || 'Send failed.');
                    return;
                }
                // Email is sent server-side (branded HTML) — no client URL to open.
                // WhatsApp: reuse ONE named tab ('corex_whatsapp_web') so repeated
                // sends target the agent's existing WhatsApp Web tab instead of
                // spawning a new tab each time.
                if (data.client_url) {
                    window.open(data.client_url, 'corex_whatsapp_web');
                }
                window.location.href = this.sentUrlBase + '/' + data.send_id;
            } catch (e) {
                alert('Network error — try again.');
            } finally {
                this.sending = false;
            }
        },

        // AT-117 — add the prepared message to the queue (no due-time; ready now).
        async addToQueue() {
            if (this.queuing) return;
            this.queuing = true;
            try {
                const res = await fetch(this.queueUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: new URLSearchParams({
                        property_id: this.propertyId || '',
                        channel: this.channel,
                        template_id: this.templateId || '',
                        subject: this.subject || '',
                        body: this.body || '',
                    }),
                });
                const data = await res.json();
                if (!res.ok) { alert(data.message || 'Could not queue.'); return; }
                alert(data.message || 'Added to your outreach queue.');
                window.location.href = this.contactUrl;
            } catch (e) {
                alert('Network error — try again.');
            } finally {
                this.queuing = false;
            }
        },
    };
}

// A.3.4 — picker-side collision gate. Selecting an `other_draft` property
// prompts before reloading the page with the new property_id. All other
// statuses (available, held, own_draft, previously_sold, previously_held)
// reload without friction — the badge below the picker conveys the state.
function propertyPickerCollision(init) {
    return {
        statuses: init.statuses || {},
        agentNames: init.agentNames || {},
        currentPropertyId: init.currentPropertyId,
        onPickerChange(newPropertyId) {
            const status = this.statuses[String(newPropertyId)]
                       ?? this.statuses[Number(newPropertyId)]
                       ?? 'available';
            if (status === 'other_draft') {
                const agent = this.agentNames[String(newPropertyId)]
                          ?? this.agentNames[Number(newPropertyId)]
                          ?? 'another agent';
                const ok = window.confirm(
                    agent + ' has a draft on this property. Coordinate with them before pitching. '
                    + 'Are you sure you want to use this property?'
                );
                if (!ok) {
                    // Roll the dropdown back to the previously-selected property.
                    const sel = document.querySelector('select[data-property-picker], select');
                    // Native select reset — find the option for currentPropertyId
                    // and re-select it without firing change.
                    const selects = document.querySelectorAll('select');
                    selects.forEach(s => {
                        const opt = s.querySelector('option[value="' + this.currentPropertyId + '"]');
                        if (opt) s.value = String(this.currentPropertyId);
                    });
                    return;
                }
            }
            // Reuse composerState's switchProperty by triggering its handler.
            const url = new URL(window.location.href);
            url.searchParams.set('property_id', newPropertyId);
            url.searchParams.delete('body');
            url.searchParams.delete('subject');
            window.location.href = url.toString();
        },
    };
}
</script>
@endsection
