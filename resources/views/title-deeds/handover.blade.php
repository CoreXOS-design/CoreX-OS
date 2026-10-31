@extends('layouts.corex-app')

@section('corex-content')
@php
    $p = $ctx['policy'];
    $pin = $ctx['pin'];
    $consent = $ctx['consent'];
    $ovr = $ctx['override'];
    $owners = $ctx['owners'];
    $input = 'w-full rounded-md px-3 py-3 text-base';
    $inputStyle = 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);';
    $lbl = 'block text-xs font-medium mb-1';
    $repOpen = $p->repWithoutPinOffered();
    // Which ways in are offered at all: Not used / Required are applied here, so staff only ever see what the server will accept.
    $tabs = collect([
        'pin' => $p->pinUsed() ? 'The collector has the PIN' : null,
        'id_check' => ($p->ownerIdCheckOffered()) ? 'The owner is here without the PIN' : null,
        'representative' => $repOpen ? 'Someone else is here without the PIN' : null,
    ])->filter();
    $startMode = old('method');
    if (! $tabs->has($startMode)) {
        $startMode = ($p->pinUsed() && $pin['issued'] && ! $pin['locked']) ? 'pin' : ($tabs->keys()->first() ?? 'pin');
    }
    $photoNote = $p->photoRequired() ? '' : ' (optional)';
    // The few steps that will happen, per way in, so staff know what is coming before they start.
    $steps = [
        'pin' => array_values(array_filter([
            "Type the PIN the collector gives you",
            $p->photoUsed() ? "Photograph the collector's ID" . $photoNote : null,
            $p->signatureUsed() ? ($p->signOnScreen ? 'The collector signs in the box' . ($p->signatureRequired() ? '' : ' (optional)') : 'Send the collector a signing link and wait for them to sign') : null,
            'Press "Hand the deed over"',
        ])),
        'id_check' => array_values(array_filter([
            "Choose the owner and type the ID number you saw",
            $p->photoUsed() ? "Photograph the collector's ID" . $photoNote : null,
            $p->signatureUsed() ? ($p->signOnScreen ? 'The owner signs in the box' . ($p->signatureRequired() ? '' : ' (optional)') : 'Send the owner a signing link and wait for them to sign') : null,
            'Press "Hand the deed over"',
        ])),
        'representative' => array_values(array_filter([
            "Type their name, ID number and relationship to the owner",
            $p->repRule === 'consent_or_letter'
                ? 'Ask the owner to confirm by link, or upload the signed authority letter' . ($p->phoneConsent ? ' (or record a phone call)' : '')
                : 'Ask the owner to confirm by link' . ($p->phoneConsent ? ' (or record a phone call)' : ''),
            ($p->letterRequired() && $p->repRule !== 'consent_or_letter') ? 'Upload the owner\'s signed authority letter' : null,
            $p->photoUsed() ? "Photograph the collector's ID" . $photoNote : null,
            $p->signatureUsed() ? ($p->signOnScreen ? 'They sign in the box' . ($p->signatureRequired() ? '' : ' (optional)') : 'Send them a signing link and wait for them to sign') : null,
            'Press "Hand the deed over"',
        ])),
    ];
    $blocked = $ovr['needed'] && ! $ovr['can'];
    $hasLink = (bool) $link;
@endphp
<div class="w-full max-w-2xl space-y-4"
     x-data="titleDeedHandover({
        mode: @js($startMode),
        collectorType: @js(old('collector_type', 'owner')),
        consent: @js($consent),
        pollUrl: @js($pollUrl),
        signingPollUrl: @js($signingPollUrl),
        linkState: @js($linkState),
        sigRequired: @js($p->signatureRequired()),
        sigOnScreen: @js($p->signOnScreen),
        letterRequired: @js($p->letterRequired()),
        repRule: @js($p->repRule),
        showUrl: @js(route('title-deeds.show', $deed->id)),
     })">

    <div class="rounded-md px-5 py-4 corex-page-banner" data-tour="rpt-tdh-head">
        <a href="{{ route('title-deeds.show', $deed->id) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">&larr; Back to the record</a>
        <h1 class="text-base font-bold leading-tight mt-1" style="color: var(--text-primary);">Hand over: {{ $deed->reference }} &mdash; {{ $deed->property_display }}</h1>
        <div class="text-sm" style="color: var(--text-secondary);">{{ collect($owners)->pluck('name')->implode(', ') }}</div>
    </div>

    @foreach(['success' => 'green', 'error' => 'crimson', 'info' => 'amber'] as $key => $colour)
        @if(session($key))<div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-{{ $colour }}) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-{{ $colour }}) 30%, transparent); color: var(--text-primary);">{{ session($key) }}</div>@endif
    @endforeach
    @if($errors->any())<div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);"><ul class="list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

    @if($ovr['needed'])
    <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-amber) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 35%, transparent); color: var(--text-primary);">
        The email or phone for {{ implode(', ', $ovr['owners']) }} was changed in the last {{ $ovr['days'] }} {{ $ovr['days'] === 1 ? 'day' : 'days' }}.
        @if($ovr['can']) You can release the deed, but you must give a reason below.
        @else Someone with permission to override must release this deed.
        @endif
    </div>
    @endif

@if($hasLink)
    {{-- ═════════ The collector is signing on their own phone ═════════ --}}
    <div class="rounded-md p-4 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">
        <div>
            <div class="text-sm font-semibold" style="color: var(--text-primary);">Signing link for {{ $link->collector_name }}</div>
            <div class="text-sm mt-1" aria-live="polite" data-link-status>
                <template x-if="linkState.state === 'waiting'"><span style="color: var(--text-secondary);">Waiting for {{ $link->collector_name }} to sign on their phone&hellip; <span x-text="linkState.expires_at ? '(valid until ' + new Date(linkState.expires_at).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'}) + ')' : ''"></span></span></template>
                <template x-if="linkState.state === 'signed'"><span style="color: var(--ds-green); font-weight: 600;">Signed by <span x-text="linkState.signed_name"></span> at <span x-text="linkState.signed_at"></span></span></template>
                <template x-if="linkState.state === 'expired'"><span style="color: var(--ds-crimson);">This link has run out. Cancel it and generate a new one.</span></template>
                <template x-if="linkState.state === 'cancelled'"><span style="color: var(--ds-crimson);">This link was cancelled.</span></template>
            </div>
        </div>

        @if($link->isLive())
        <div class="space-y-3">
            @if($link->collector_type === 'representative')
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <form method="POST" action="{{ route('title-deeds.signing-link.email', [$deed->id, $link->id]) }}" class="space-y-1">
                    @csrf <input type="hidden" name="_form_token" value="{{ $tokens['link_email'] }}">
                    <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="send_email">Email for the link</label>
                    <input id="send_email" name="send_email" type="email" maxlength="191" value="{{ old('send_email', $link->send_email) }}" class="{{ $input }}" style="{{ $inputStyle }}">
                    <button type="submit" class="corex-btn-outline text-xs">Email the link to the collector</button>
                </form>
                <form method="POST" action="{{ route('title-deeds.signing-link.whatsapp', [$deed->id, $link->id]) }}" class="space-y-1">
                    @csrf <input type="hidden" name="_form_token" value="{{ $tokens['link_whatsapp'] }}">
                    <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="send_mobile">Mobile for WhatsApp</label>
                    <input id="send_mobile" name="send_mobile" type="tel" maxlength="40" value="{{ old('send_mobile', $link->send_mobile) }}" class="{{ $input }}" style="{{ $inputStyle }}">
                    <button type="submit" class="corex-btn-outline text-xs">Prepare a WhatsApp with the link</button>
                </form>
            </div>
            @else
            <div class="flex flex-wrap items-center gap-2">
                <form method="POST" action="{{ route('title-deeds.signing-link.email', [$deed->id, $link->id]) }}">@csrf <input type="hidden" name="_form_token" value="{{ $tokens['link_email'] }}"><button type="submit" class="corex-btn-outline text-xs">Email the link to the collector</button></form>
                <form method="POST" action="{{ route('title-deeds.signing-link.whatsapp', [$deed->id, $link->id]) }}">@csrf <input type="hidden" name="_form_token" value="{{ $tokens['link_whatsapp'] }}"><button type="submit" class="corex-btn-outline text-xs">Prepare a WhatsApp with the link</button></form>
            </div>
            @endif

            @if($linkQr)
            <details>
                <summary class="text-sm font-semibold cursor-pointer" style="color: var(--brand-icon);">Show QR code to scan</summary>
                <img src="{{ $linkQr }}" alt="QR code for the signing link" class="mt-2 rounded-md" style="width: 220px; height: 220px; background: #fff; padding: 6px;">
            </details>
            @endif
        </div>
        @endif

        <div class="flex flex-wrap items-center gap-3 pt-1" style="border-top: 1px solid var(--border);">
            <form method="POST" action="{{ route('title-deeds.handover.submit', $deed->id) }}" @submit="submitting = true">
                @csrf
                <input type="hidden" name="_form_token" value="{{ $tokens['handover_link'] }}">
                <input type="hidden" name="signing_link_id" value="{{ $link->id }}">
                <button type="submit" class="corex-btn-primary text-base py-3 px-6" :disabled="linkState.state !== 'signed' || submitting" @if($blocked) disabled @endif>Hand the deed over</button>
            </form>
            <form method="POST" action="{{ route('title-deeds.signing-link.cancel', [$deed->id, $link->id]) }}">@csrf
                <x-confirm-submit :plain="true" title="Cancel the signing link" message="The link stops working{{ $link->status === 'signed' ? ' and the collector\'s signature is discarded' : '' }}. You can sign on this screen or create a new link." confirm-label="Cancel the link" :danger="true" class="corex-btn-outline text-xs">Cancel the signing link</x-confirm-submit>
            </form>
        </div>
    </div>

    @include('title-deeds._prepared-whatsapp', ['prepared' => $prepared, 'deed' => $deed])
@else
    {{-- How is it being handed over? Spelled as the situation at the counter. A way that is Not used (or ruled out by a Required PIN) is not shown at all. --}}
    @if($tabs->count() > 1)
    <div class="grid gap-2 text-sm {{ $tabs->count() === 3 ? 'grid-cols-1 sm:grid-cols-3' : 'grid-cols-1 sm:grid-cols-2' }}" role="tablist" aria-label="Who is at the counter" data-tour="rpt-tdh-ways">
        @foreach($tabs as $key => $label)
        <button type="button" class="rounded-md px-3 py-3 font-semibold text-left" role="tab" :aria-selected="mode === '{{ $key }}'" :style="mode === '{{ $key }}' ? 'background: var(--brand-icon); color: #fff;' : 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);'" @click="mode = '{{ $key }}'">{{ $label }}</button>
        @endforeach
    </div>
    @elseif($p->pinRequired())
    <div class="text-sm font-semibold" style="color: var(--text-primary);">This agency needs the owner's PIN to hand a deed over.</div>
    @endif

    @foreach($steps as $key => $list)
        @if($tabs->has($key))
        <div x-show="mode === '{{ $key }}'" x-cloak class="rounded-md px-4 py-3 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);" data-steps="{{ $key }}">
            <span class="font-semibold">What happens next:</span>
            @foreach($list as $i => $step)<span class="whitespace-nowrap">{{ $i + 1 }}. {{ $step }}{{ $loop->last ? '' : ';' }}</span> @endforeach
        </div>
        @endif
    @endforeach

    {{-- Representative without the PIN: ask the owners / record a phone call (their own forms) --}}
    <div x-show="mode === 'representative'" x-cloak class="space-y-4">
        @if($repOpen)
        <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-sm font-semibold" style="color: var(--text-primary);">Who is collecting</div>
            <form method="POST" action="{{ route('title-deeds.consent.request', $deed->id) }}" id="rep-form" class="space-y-3">
                @csrf
                <input type="hidden" name="_form_token" value="{{ $tokens['consent'] }}">
                <div>
                    <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="rep_name">Full name</label>
                    <input id="rep_name" name="rep_name" type="text" maxlength="191" value="{{ old('rep_name') }}" x-model="rep.name" class="{{ $input }}" style="{{ $inputStyle }}" :readonly="consentLocksRep">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="rep_id_number">ID number</label>
                        <input id="rep_id_number" name="rep_id_number" type="text" maxlength="40" value="{{ old('rep_id_number') }}" x-model="rep.id" class="{{ $input }}" style="{{ $inputStyle }}" :readonly="consentLocksRep">
                    </div>
                    <div>
                        <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="rep_relationship">Relationship to the owner</label>
                        <input id="rep_relationship" name="rep_relationship" type="text" maxlength="100" value="{{ old('rep_relationship') }}" x-model="rep.rel" class="{{ $input }}" style="{{ $inputStyle }}" :readonly="consentLocksRep">
                    </div>
                </div>
                @if($p->repRule !== 'refuse')
                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="corex-btn-primary" x-show="!consentLocksRep">Ask the owner to confirm by link</button>
                    <template x-if="consent.state === 'waiting' && consent.consent_id">
                        <button type="submit" form="cancel-consent-form" class="text-xs font-semibold" style="color: var(--ds-crimson);">Withdraw the request</button>
                    </template>
                </div>
                @endif
            </form>
            <form method="POST" id="cancel-consent-form" action="{{ $consent['consent_id'] ? route('title-deeds.consent.cancel', [$deed->id, $consent['consent_id']]) : '#' }}">@csrf</form>

            <div x-show="consent.state !== 'none'" x-cloak class="rounded-md px-3 py-2 text-sm" aria-live="polite"
                 :style="consent.state === 'approved' ? 'background: color-mix(in srgb, var(--ds-green) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 35%, transparent);' : (consent.state === 'declined' ? 'background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 35%, transparent);' : 'background: var(--surface-2); border:1px solid var(--border);')"
                 style="color: var(--text-primary);">
                <template x-if="consent.state === 'waiting'"><span>Waiting for an owner to answer&hellip;</span></template>
                <template x-if="consent.state === 'approved'"><span><span x-text="consent.by || 'An owner'"></span> approved <span x-text="consent.rep_name"></span> (ID ending <span x-text="consent.rep_id_last4"></span>) until <span x-text="untilText()"></span>.</span></template>
                <template x-if="consent.state === 'declined'"><span><span x-text="consent.by || 'An owner'"></span> declined. The deed cannot be released to <span x-text="consent.rep_name"></span>.</span></template>
                <template x-if="consent.state === 'expired'"><span>That request has run out. Ask the owners again.</span></template>
                <template x-if="consent.state === 'cancelled'"><span>The request was withdrawn.</span></template>
                <template x-if="consent.state === 'used'"><span>That approval has been used.</span></template>
            </div>
        </div>

        @include('title-deeds._prepared-whatsapp', ['prepared' => $prepared, 'deed' => $deed])

        @if($p->phoneConsent)
        <details class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <summary class="text-sm font-semibold cursor-pointer" style="color: var(--text-primary);">Record a phone call instead</summary>
            <form method="POST" action="{{ route('title-deeds.consent.phone', $deed->id) }}" class="space-y-3 mt-3"
                  x-data="{ owner: '', numbers: @js(collect($owners)->mapWithKeys(fn ($o) => [(string) $o['contact_id'] => collect($o['numbers'])->map(fn ($masked, $id) => ['id' => $id, 'masked' => $masked])->values()])->all()) }">
                @csrf
                <input type="hidden" name="_form_token" value="{{ $tokens['phone_consent'] }}">
                <input type="hidden" name="rep_name" :value="rep.name"><input type="hidden" name="rep_id_number" :value="rep.id"><input type="hidden" name="rep_relationship" :value="rep.rel">
                <div>
                    <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="called_contact_id">Owner you called</label>
                    <select id="called_contact_id" name="called_contact_id" x-model="owner" required class="{{ $input }}" style="{{ $inputStyle }}">
                        <option value="">Choose&hellip;</option>
                        @foreach($owners as $o)@if($o['reachable'] && $o['numbers'])<option value="{{ $o['contact_id'] }}">{{ $o['name'] }}</option>@endif @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="called_phone_id">Number called (stored on the contact)</label>
                    <select id="called_phone_id" name="called_phone_id" required class="{{ $input }}" style="{{ $inputStyle }}">
                        <option value="">Choose&hellip;</option>
                        <template x-for="n in (numbers[owner] || [])" :key="n.id"><option :value="n.id" x-text="n.masked"></option></template>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="spoke_to">Who you spoke to</label>
                        <input id="spoke_to" name="spoke_to" type="text" required maxlength="191" class="{{ $input }}" style="{{ $inputStyle }}">
                    </div>
                    <div>
                        <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="called_at">When</label>
                        <input id="called_at" name="called_at" type="datetime-local" max="{{ now()->format('Y-m-d\TH:i') }}" value="{{ now()->format('Y-m-d\TH:i') }}" class="{{ $input }}" style="{{ $inputStyle }}">
                    </div>
                </div>
                <button type="submit" class="corex-btn-outline">Record the phone call</button>
            </form>
        </details>
        @endif
        @endif
    </div>

    {{-- The handover itself --}}
    <form method="POST" action="{{ route('title-deeds.handover.submit', $deed->id) }}" enctype="multipart/form-data" id="handover-form"
          class="rounded-md p-4 space-y-4" style="background: var(--surface); border: 1px solid var(--border);"
          @submit="beforeSubmit($event)" data-tour="rpt-tdh-form">
        @csrf
        <input type="hidden" name="_form_token" value="{{ $tokens['handover'] }}">
        <input type="hidden" name="method" :value="mode">
        <input type="hidden" name="consent_id" :value="mode === 'representative' && consent.state === 'approved' ? consent.consent_id : ''">
        <input type="hidden" name="signature_image" x-ref="sigImage" id="signature_image">
        <input type="hidden" name="rep_name" :value="mode === 'representative' || (mode === 'pin' && collectorType === 'representative') ? rep.name : ''">
        <input type="hidden" name="rep_id_number" :value="mode === 'representative' || (mode === 'pin' && collectorType === 'representative') ? rep.id : ''">
        <input type="hidden" name="rep_relationship" :value="mode === 'representative' || (mode === 'pin' && collectorType === 'representative') ? rep.rel : ''">

        {{-- PIN --}}
        <div x-show="mode === 'pin'" x-cloak class="space-y-3">
            @if(! $p->pinUsed())
                <div class="text-sm" style="color: var(--text-secondary);">This agency does not use a collection PIN.</div>
            @elseif(! $pin['issued'])
                <div class="text-sm" style="color: var(--text-secondary);">No PIN has been issued yet - notify the owners from the record first.</div>
            @elseif($pin['locked'])
                <div class="text-sm" style="color: var(--ds-crimson);">The PIN is locked after too many wrong attempts. Issue a new PIN from the record.</div>
            @else
                <div data-tour="rpt-tdh-pin">
                    <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="pin">PIN from the owner</label>
                    <input id="pin" name="pin" type="text" inputmode="numeric" autocomplete="off" maxlength="12" class="{{ $input }} tracking-widest" style="{{ $inputStyle }}" :required="mode === 'pin'">
                    @if($pin['attempts'])<div class="text-xs mt-1" style="color: var(--text-muted);">{{ $pin['attempts'] }} wrong {{ $pin['attempts'] === 1 ? 'attempt' : 'attempts' }} so far; it locks at {{ $pin['limit'] }}.</div>@endif
                </div>
                <div>
                    <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="collector_type">Who is collecting</label>
                    <select id="collector_type" name="collector_type" x-model="collectorType" class="{{ $input }}" style="{{ $inputStyle }}">
                        <option value="owner">An owner</option>
                        @if($p->repsAllowed)<option value="representative">A representative (who has the PIN)</option>@endif
                    </select>
                </div>
                <div x-show="collectorType === 'representative'" x-cloak class="space-y-3">
                    <div>
                        <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="pin_rep_name">Full name</label>
                        <input id="pin_rep_name" type="text" maxlength="191" x-model="rep.name" class="{{ $input }}" style="{{ $inputStyle }}">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="{{ $lbl }}" style="color: var(--text-secondary);" for="pin_rep_id">ID number</label><input id="pin_rep_id" type="text" maxlength="40" x-model="rep.id" class="{{ $input }}" style="{{ $inputStyle }}"></div>
                        <div><label class="{{ $lbl }}" style="color: var(--text-secondary);" for="pin_rep_rel">Relationship to the owner</label><input id="pin_rep_rel" type="text" maxlength="100" x-model="rep.rel" class="{{ $input }}" style="{{ $inputStyle }}"></div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Which owner (PIN as owner, or ID check) --}}
        <div x-show="(mode === 'pin' && collectorType === 'owner') || mode === 'id_check'" x-cloak class="space-y-3">
            <div data-tour="rpt-tdh-owner">
                <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="collector_contact_id">Owner collecting</label>
                <select id="collector_contact_id" name="collector_contact_id" class="{{ $input }}" style="{{ $inputStyle }}" :required="(mode === 'pin' && collectorType === 'owner') || mode === 'id_check'">
                    @if(count($owners) > 1)<option value="">Choose&hellip;</option>@endif
                    @foreach($owners as $o)<option value="{{ $o['contact_id'] }}" @selected((string) old('collector_contact_id') === (string) $o['contact_id'])>{{ $o['name'] }}</option>@endforeach
                </select>
            </div>
            <div x-show="mode === 'id_check'" x-cloak>
                <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="id_number_seen">ID number you saw</label>
                <input id="id_number_seen" name="id_number_seen" type="text" maxlength="40" value="{{ old('id_number_seen') }}" class="{{ $input }}" style="{{ $inputStyle }}" :required="mode === 'id_check'">
            </div>
        </div>

        {{-- Authority letter (Required / Optional; Not used = not shown). Only for a representative. --}}
        @if($p->letterUsed())
        <div x-show="mode === 'representative' || (mode === 'pin' && collectorType === 'representative')" x-cloak>
            <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="authority_letter" x-text="letterLabel()">Signed authority letter</label>
            <input id="authority_letter" name="authority_letter" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic" capture="environment" class="block w-full text-sm" style="color: var(--text-secondary);" :required="letterIsRequired()">
        </div>
        @endif

        {{-- Photo of the collector's ID (Required / Optional; Not used = not shown) --}}
        @if($p->photoUsed())
        <div data-tour="rpt-tdh-photo">
            <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="collector_id_photo">Photo of the collector's ID{{ $photoNote }}</label>
            <input id="collector_id_photo" name="collector_id_photo" type="file" accept="image/*,.pdf" capture="environment" @if($p->photoRequired()) required @endif class="block w-full text-sm" style="color: var(--text-secondary);">
        </div>
        @endif

        {{-- Signature: Required / Optional on this screen; "Not used" or "sign on our screen = No" = no box at all --}}
        @if($p->signatureUsed() && $p->signOnScreen)
        <div class="space-y-3" data-tour="rpt-tdh-sign">
            <div>
                <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="typed_name">Collector's full name{{ $p->signatureRequired() ? '' : ' (optional)' }}</label>
                <input id="typed_name" name="typed_name" type="text" maxlength="191" autocomplete="off" value="{{ old('typed_name') }}" class="{{ $input }}" style="{{ $inputStyle }}">
            </div>
            <div>
                <label class="{{ $lbl }}" style="color: var(--text-secondary);">Collector signs here{{ $p->signatureRequired() ? '' : ' (optional)' }}</label>
                <canvas x-ref="pad" id="td-signature-pad" class="w-full block rounded-md" style="height:180px; touch-action:none; background:#fff; border: 1px solid var(--border);" aria-label="Signature box"></canvas>
                <div class="flex items-center justify-between">
                    <button type="button" @click="clearPad()" class="mt-1 text-xs underline" style="color: var(--text-muted);">Clear the signature</button>
                    <span x-show="padFailed" x-cloak class="text-xs" style="color: var(--ds-crimson);">The signature box could not start. Reload this page, or use the signing link below.</span>
                </div>
            </div>
        </div>
        @elseif($p->signatureUsed())
        <div class="text-sm" style="color: var(--text-secondary);">Signing on this screen is switched off at this agency{{ $p->signByLink ? ': send the collector a signing link below.' : '.' }}</div>
        @endif

        @if($signByLink)
        <details class="rounded-md p-3" style="background: var(--surface-2); border: 1px solid var(--border);" data-tour="rpt-tdh-link" @if(($errors->any() && old('send_email')) || ! $p->signOnScreen) open @endif>
            <summary class="text-sm font-semibold cursor-pointer" style="color: var(--text-primary);">{{ $p->signOnScreen ? 'Or: the collector signs on their own phone' : 'The collector signs on their own phone' }}</summary>
            <div class="mt-3 space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" x-show="mode === 'representative' || (mode === 'pin' && collectorType === 'representative')" x-cloak>
                    <div>
                        <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="send_email_new">Their email (for the link)</label>
                        <input id="send_email_new" name="send_email" type="email" maxlength="191" value="{{ old('send_email') }}" class="{{ $input }}" style="{{ $inputStyle }}">
                    </div>
                    <div>
                        <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="send_mobile_new">Their mobile (for WhatsApp)</label>
                        <input id="send_mobile_new" name="send_mobile" type="tel" maxlength="40" value="{{ old('send_mobile') }}" class="{{ $input }}" style="{{ $inputStyle }}">
                    </div>
                </div>
                <button type="submit" formaction="{{ route('title-deeds.signing-link.generate', $deed->id) }}" data-link="1" class="corex-btn-outline text-sm">Create the signing link</button>
            </div>
        </details>
        @endif

        @if($ovr['needed'] && $ovr['can'])
        <div>
            <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="override_reason">Why you are releasing it now (logged)</label>
            <textarea id="override_reason" name="override_reason" rows="2" maxlength="500" required class="{{ $input }}" style="{{ $inputStyle }}">{{ old('override_reason') }}</textarea>
        </div>
        @endif

        <p class="text-sm" style="color: var(--ds-crimson);" x-show="clientError" x-cloak x-text="clientError" id="client-error"></p>

        <button type="submit" id="handover-submit" data-tour="rpt-tdh-submit" class="corex-btn-primary w-full text-base py-3" :disabled="submitting || !canSubmit()" @if($blocked) disabled @endif>
            <span x-text="submitting ? 'Saving…' : 'Hand the deed over'"></span>
        </button>
    </form>
@endif
</div>

@include('title-deeds._signature-pad')
<script>
    function titleDeedHandover(cfg) {
        return {
            mode: cfg.mode, collectorType: cfg.collectorType, consent: cfg.consent, linkState: cfg.linkState || { state: 'none' },
            submitting: false, clientError: '', padFailed: false, padEmpty: true,
            rep: { name: @js(old('rep_name', '')), id: @js(old('rep_id_number', '')), rel: @js(old('rep_relationship', '')) },
            init() {
                if (this.consent.state === 'approved' || this.consent.state === 'waiting') {
                    this.rep = Object.assign({}, this.rep, { name: this.consent.rep_name || this.rep.name });
                }
                this.$nextTick(() => this.setupPad());
                setInterval(() => {
                    if (document.hidden) return;
                    if (this.consent.state === 'waiting') this.pollConsent();
                    if (this.linkState && this.linkState.state === 'waiting') this.pollLink();
                }, 5000);
                document.addEventListener('visibilitychange', () => { if (!document.hidden) { this.pollLink(); } });
            },
            // The pad is NOT stored in this reactive object (Alpine would wrap it in a proxy): it lives in window.tdPads, looked up from the canvas.
            padApi() { const c = this.$refs.pad; return c && window.tdSignaturePad ? window.tdSignaturePad.of(c) : null; },
            setupPad() {
                const c = this.$refs.pad;
                if (!c) return;   // signature Not used, or signing on this screen is switched off
                if (!window.tdSignaturePad) { this.padFailed = true; return; }
                window.tdSignaturePad.attach(c, { onChange: (empty) => { this.padEmpty = empty; this.clientError = ''; } });
                setTimeout(() => { const p = this.padApi(); if (!p || !p.ready()) this.padFailed = true; }, 1500);
            },
            clearPad() { const p = this.padApi(); if (p) p.clear(); },
            letterIsRequired() { return cfg.letterRequired; },
            letterLabel() {
                if (cfg.letterRequired) return 'Signed authority letter (required)';
                if (this.mode === 'representative' && cfg.repRule === 'consent_or_letter') return "Signed authority letter (instead of the owner's confirmation)";
                return 'Signed authority letter (optional)';
            },
            get consentLocksRep() { return this.consent.state === 'waiting' || this.consent.state === 'approved'; },
            canSubmit() {
                @if($p->repRule === 'owner_consent')
                if (this.mode === 'representative') return this.consent.state === 'approved';
                @endif
                return true;
            },
            untilText() { return this.consent.until ? new Date(this.consent.until).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''; },
            async pollConsent() {
                try {
                    const res = await fetch(cfg.pollUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                    if (!res.ok) return;
                    const data = await res.json();
                    if (data.collected) { window.location.href = cfg.showUrl; return; }
                    this.consent = Object.assign({}, this.consent, data);
                } catch (e) { /* the next tick tries again */ }
            },
            async pollLink() {
                if (!cfg.signingPollUrl || !this.linkState || this.linkState.state === 'none') return;
                try {
                    const res = await fetch(cfg.signingPollUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                    if (!res.ok) return;
                    const data = await res.json();
                    if (data.collected) { window.location.href = cfg.showUrl; return; }
                    this.linkState = Object.assign({}, this.linkState, data);
                } catch (e) { /* the next tick tries again */ }
            },
            beforeSubmit(e) {
                this.clientError = '';
                const viaLink = e.submitter && e.submitter.dataset && e.submitter.dataset.link === '1';
                if (viaLink) { this.submitting = false; return; }          // generating a signing link needs no signature on this screen
                if (!this.$refs.pad) {
                    // No box on this screen: signing here is switched off, or no signature is collected at all.
                    if (cfg.sigRequired) { e.preventDefault(); this.clientError = 'This agency collects the signature on the collector\'s own phone. Create the signing link below and wait for them to sign.'; return; }
                    this.submitting = true; return;
                }
                const name = (document.getElementById('typed_name') || {}).value || '';
                if (cfg.sigRequired && name.trim().length < 2) { e.preventDefault(); this.clientError = "Type the collector's full name before they sign."; return; }
                const p = this.padApi();
                const failed = !p || !p.ready();
                if (cfg.sigRequired && (failed || p.isEmpty())) {
                    e.preventDefault();
                    this.clientError = failed ? 'The signature box could not start. Reload this page, or use "Collector signs on their own phone instead".' : 'Nothing has been drawn in the signature box yet - draw the collector\'s signature with the mouse or a finger.';
                    return;
                }
                this.$refs.sigImage.value = p && !p.isEmpty() ? p.toDataURL() : '';
                this.submitting = true;
            },
        };
    }
</script>
@endsection
