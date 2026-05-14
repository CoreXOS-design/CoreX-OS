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

    {{-- Empty state: contact has no linked properties --}}
    @if($linkedProperties->isEmpty())
        <div class="rounded-md px-6 py-10 text-center"
             style="background: var(--surface); border: 1px dashed var(--border); color: var(--text-secondary);">
            <h2 class="text-lg font-semibold mb-2" style="color: var(--text-primary);">
                No properties linked to this contact
            </h2>
            <p class="text-sm mb-4">
                The composer needs a specific property to pitch about. Link a property to
                {{ $contact->first_name ?: 'this contact' }} first.
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
                        property_id: this.propertyId,
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
                window.open(data.client_url, '_blank');
                window.location.href = this.sentUrlBase + '/' + data.send_id;
            } catch (e) {
                alert('Network error — try again.');
            } finally {
                this.sending = false;
            }
        },
    };
}
</script>
@endsection
