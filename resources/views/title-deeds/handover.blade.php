@extends('layouts.corex-app')

@push('head')
    @vite(['resources/js/esign-signature-pad.js'])
@endpush

@section('corex-content')
@php
    $s = $ctx['settings'];
    $pin = $ctx['pin'];
    $consent = $ctx['consent'];
    $ovr = $ctx['override'];
    $owners = $ctx['owners'];
    $input = 'w-full rounded-md px-3 py-3 text-base';
    $inputStyle = 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);';
    $lbl = 'block text-xs font-medium mb-1';
    $repOpen = $s['representatives_allowed'] && $s['rep_mode'] !== 'refuse';
    $startMode = old('method', ($s['pin_required'] && $pin['issued'] && ! $pin['locked']) ? 'pin' : 'id_check');
    $blocked = $ovr['needed'] && ! $ovr['can'];
@endphp
<div class="w-full max-w-2xl space-y-4"
     x-data="titleDeedHandover({
        mode: @js($startMode),
        collectorType: @js(old('collector_type', 'owner')),
        consent: @js($consent),
        pollUrl: @js($pollUrl),
        sigRequired: @js($s['signature_required']),
     })">

    <div class="rounded-md px-5 py-4 corex-page-banner">
        <a href="{{ route('title-deeds.show', $deed->id) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">&larr; Back to the record</a>
        <h1 class="text-base font-bold leading-tight mt-1" style="color: var(--text-primary);">Hand over: {{ $deed->property_display }}</h1>
        <div class="text-sm" style="color: var(--text-secondary);">{{ collect($owners)->pluck('name')->implode(', ') }}</div>
    </div>

    @if(session('error'))<div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">{{ session('error') }}</div>@endif
    @if(session('success'))<div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);"><ul class="list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

    @if($ovr['needed'])
    <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-amber) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 35%, transparent); color: var(--text-primary);">
        The email or phone for {{ implode(', ', $ovr['owners']) }} was changed in the last {{ $ovr['days'] }} {{ $ovr['days'] === 1 ? 'day' : 'days' }}.
        @if($ovr['can']) You can release the deed, but you must give a reason below.
        @else Someone with permission to override must release this deed.
        @endif
    </div>
    @endif

    {{-- How is it being handed over? --}}
    <div class="grid grid-cols-3 gap-2 text-sm" role="tablist">
        <button type="button" class="rounded-md px-2 py-3 font-semibold" :style="mode === 'pin' ? 'background: var(--brand-icon); color: #fff;' : 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);'" @click="mode = 'pin'">Has the PIN</button>
        <button type="button" class="rounded-md px-2 py-3 font-semibold" :style="mode === 'id_check' ? 'background: var(--brand-icon); color: #fff;' : 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);'" @click="mode = 'id_check'">Owner, ID check</button>
        <button type="button" class="rounded-md px-2 py-3 font-semibold" :style="mode === 'representative' ? 'background: var(--brand-icon); color: #fff;' : 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);'" @click="mode = 'representative'">Rep, no PIN</button>
    </div>

    {{-- Representative without the PIN: ask the owners / record a phone call (their own forms) --}}
    <div x-show="mode === 'representative'" x-cloak class="space-y-4">
        @if(! $repOpen)
            <div class="rounded-md p-4 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">
                @if(! $s['representatives_allowed']) Representatives are not allowed to collect at this agency. @else A representative must have the PIN at this agency. @endif
            </div>
        @else
        <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-sm font-semibold" style="color: var(--text-primary);">Who is collecting</div>
            <form method="POST" action="{{ route('title-deeds.consent.request', $deed->id) }}" id="rep-form" class="space-y-3">
                @csrf
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
                @if($s['rep_mode'] !== 'refuse')
                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="corex-btn-primary" x-show="!consentLocksRep">Ask owner to confirm</button>
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

        @if($s['phone_consent_allowed'])
        <details class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <summary class="text-sm font-semibold cursor-pointer" style="color: var(--text-primary);">Record a phone call instead</summary>
            <form method="POST" action="{{ route('title-deeds.consent.phone', $deed->id) }}" class="space-y-3 mt-3"
                  x-data="{ owner: '', numbers: @js(collect($owners)->mapWithKeys(fn ($o) => [(string) $o['contact_id'] => collect($o['numbers'])->map(fn ($masked, $id) => ['id' => $id, 'masked' => $masked])->values()])->all()) }">
                @csrf
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
                <button type="submit" class="corex-btn-outline">Record the call</button>
            </form>
        </details>
        @endif
        @endif
    </div>

    {{-- The handover itself --}}
    <form method="POST" action="{{ route('title-deeds.handover.submit', $deed->id) }}" enctype="multipart/form-data"
          class="rounded-md p-4 space-y-4" style="background: var(--surface); border: 1px solid var(--border);"
          @submit="beforeSubmit($event)">
        @csrf
        <input type="hidden" name="method" :value="mode">
        <input type="hidden" name="consent_id" :value="mode === 'representative' && consent.state === 'approved' ? consent.consent_id : ''">
        <input type="hidden" name="signature_image" x-ref="sigImage">
        <input type="hidden" name="rep_name" :value="mode === 'representative' || (mode === 'pin' && collectorType === 'representative') ? rep.name : ''">
        <input type="hidden" name="rep_id_number" :value="mode === 'representative' || (mode === 'pin' && collectorType === 'representative') ? rep.id : ''">
        <input type="hidden" name="rep_relationship" :value="mode === 'representative' || (mode === 'pin' && collectorType === 'representative') ? rep.rel : ''">

        {{-- PIN --}}
        <div x-show="mode === 'pin'" x-cloak class="space-y-3">
            @if(! $s['pin_required'])
                <div class="text-sm" style="color: var(--text-secondary);">This agency does not use a collection PIN.</div>
            @elseif(! $pin['issued'])
                <div class="text-sm" style="color: var(--text-secondary);">No PIN has been issued yet - notify the owners from the record first.</div>
            @elseif($pin['locked'])
                <div class="text-sm" style="color: var(--ds-crimson);">The PIN is locked after too many wrong attempts. Issue a new PIN from the record.</div>
            @else
                <div>
                    <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="pin">PIN from the owner</label>
                    <input id="pin" name="pin" type="text" inputmode="numeric" autocomplete="off" maxlength="12" class="{{ $input }} tracking-widest" style="{{ $inputStyle }}" :required="mode === 'pin'">
                    @if($pin['attempts'])<div class="text-xs mt-1" style="color: var(--text-muted);">{{ $pin['attempts'] }} wrong {{ $pin['attempts'] === 1 ? 'attempt' : 'attempts' }} so far; it locks at {{ $pin['limit'] }}.</div>@endif
                </div>
                <div>
                    <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="collector_type">Who is collecting</label>
                    <select id="collector_type" name="collector_type" x-model="collectorType" class="{{ $input }}" style="{{ $inputStyle }}">
                        <option value="owner">An owner</option>
                        @if($s['representatives_allowed'])<option value="representative">A representative</option>@endif
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
            <div>
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

        {{-- Representative: authority letter --}}
        <div x-show="mode === 'representative'" x-cloak class="space-y-2">
            @if($s['rep_mode'] === 'consent_or_letter' || $s['authority_letter_required'])
            <div>
                <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="authority_letter">
                    Signed authority letter @if($s['authority_letter_required']) (required) @elseif($s['rep_mode'] === 'consent_or_letter') (instead of the owners' confirmation) @endif
                </label>
                <input id="authority_letter" name="authority_letter" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic" capture="environment" class="block w-full text-sm" style="color: var(--text-secondary);">
            </div>
            @endif
        </div>

        @if($s['collector_id_photo_required'])
        <div>
            <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="collector_id_photo">Photo of the collector's ID</label>
            <input id="collector_id_photo" name="collector_id_photo" type="file" accept="image/*,.pdf" capture="environment" required class="block w-full text-sm" style="color: var(--text-secondary);">
        </div>
        @endif

        {{-- Collector signs --}}
        <div class="space-y-3">
            <div>
                <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="typed_name">Collector's full name{{ $s['signature_required'] ? '' : ' (optional)' }}</label>
                <input id="typed_name" name="typed_name" type="text" maxlength="191" autocomplete="off" value="{{ old('typed_name') }}" class="{{ $input }}" style="{{ $inputStyle }}" @if($s['signature_required']) required @endif>
            </div>
            <div>
                <label class="{{ $lbl }}" style="color: var(--text-secondary);">Collector signs here{{ $s['signature_required'] ? '' : ' (optional)' }}</label>
                <canvas x-ref="pad" class="w-full block rounded-md" style="height:180px; touch-action:none; background:#fff; border: 1px solid var(--border);"></canvas>
                <button type="button" @click="clearPad()" class="mt-1 text-xs underline" style="color: var(--text-muted);">Clear</button>
            </div>
        </div>

        @if($ovr['needed'] && $ovr['can'])
        <div>
            <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="override_reason">Why you are releasing it now (logged)</label>
            <textarea id="override_reason" name="override_reason" rows="2" maxlength="500" required class="{{ $input }}" style="{{ $inputStyle }}">{{ old('override_reason') }}</textarea>
        </div>
        @endif

        <p class="text-sm" style="color: var(--ds-crimson);" x-show="clientError" x-cloak x-text="clientError"></p>

        <button type="submit" class="corex-btn-primary w-full text-base py-3" :disabled="submitting || !canSubmit()" @if($blocked) disabled @endif>
            <span x-text="submitting ? 'Saving…' : 'Hand the deed over'"></span>
        </button>
    </form>
</div>

<script>
    function titleDeedHandover(cfg) {
        return {
            mode: cfg.mode, collectorType: cfg.collectorType, consent: cfg.consent, pad: null, submitting: false, clientError: '',
            rep: { name: @js(old('rep_name', '')), id: @js(old('rep_id_number', '')), rel: @js(old('rep_relationship', '')) },
            timer: null,
            init() {
                this.$nextTick(() => this.setupPad());
                if (this.consent.state === 'approved' || this.consent.state === 'waiting') {
                    this.rep = { name: this.consent.rep_name || this.rep.name, id: this.rep.id, rel: this.rep.rel };
                }
                this.startPolling();
                document.addEventListener('visibilitychange', () => { if (!document.hidden) this.poll(); });
            },
            get consentLocksRep() { return this.consent.state === 'waiting' || this.consent.state === 'approved'; },
            canSubmit() {
                @if($s['rep_mode'] === 'owner_consent')
                if (this.mode === 'representative') return this.consent.state === 'approved';
                @endif
                return true;
            },
            untilText() { return this.consent.until ? new Date(this.consent.until).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''; },
            startPolling() {
                this.timer = setInterval(() => { if (!document.hidden && this.consent.state === 'waiting') this.poll(); }, 5000);
            },
            async poll() {
                try {
                    const res = await fetch(cfg.pollUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                    if (!res.ok) return;
                    const data = await res.json();
                    if (data.collected) { window.location.href = @js(route('title-deeds.show', $deed->id)); return; }
                    this.consent = Object.assign({}, this.consent, data);
                } catch (e) { /* the next tick tries again */ }
            },
            setupPad() {
                const canvas = this.$refs.pad;
                if (!canvas || !window.SignaturePad) return;
                const ratio = Math.max(window.devicePixelRatio || 1, 1);
                canvas.width = canvas.offsetWidth * ratio;
                canvas.height = canvas.offsetHeight * ratio;
                canvas.getContext('2d').scale(ratio, ratio);
                this.pad = new window.SignaturePad(canvas, { penColor: '#111827', backgroundColor: 'rgba(255,255,255,0)' });
            },
            clearPad() { if (this.pad) this.pad.clear(); },
            beforeSubmit(e) {
                this.clientError = '';
                if (cfg.sigRequired && (!this.pad || this.pad.isEmpty())) {
                    e.preventDefault(); this.clientError = 'The collector needs to sign on the screen.'; return;
                }
                this.$refs.sigImage.value = (this.pad && !this.pad.isEmpty()) ? this.pad.toDataURL('image/png') : '';
                this.submitting = true;
            },
        };
    }
</script>
@endsection
