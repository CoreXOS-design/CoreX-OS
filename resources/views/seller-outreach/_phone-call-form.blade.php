{{-- Road to Sold 23.23 drop 3: the Phone call tab of the Contact step. The call has already happened; the agent says when, how it went and what to do next.
     It writes the SAME contact record as the belt's "Log contact" (rts_contact_outcomes, channel = phone) through the one Road to Sold outcome route; nothing is composed or sent.
     props: $phoneCall (PitchContactService::phoneForm), $contact, $property --}}
@php
    $addr = $property ? (trim((string) $property->composeAddressFromParts()) ?: ($property->address ?: 'this property')) : null;
    $inputStyle = 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);';
@endphp
<div data-qa="phone-call" class="space-y-4"
     x-data="phoneCallForm({
        url: @js(route('road-to-sold.outcomes.record')),
        csrf: @js(csrf_token()),
        tp: @js($phoneCall['tracked_property_id']),
        now: @js($phoneCall['now']),
        address: @js($addr),
        groups: @js($phoneCall['groups']),
     })">

    @if(! $phoneCall['tracked_property_id'])
        <div class="rounded-md p-4 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">
            This pitch is about an address only, so there is no property on your Road to Sold to log a call against. Link or create the property on the contact first.
        </div>
    @elseif($phoneCall['refusal'])
        <div class="rounded-md p-4 text-sm" data-qa="phone-gate" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 40%, var(--border)); color: var(--text-primary);">
            {{ $phoneCall['refusal'] }}
        </div>
    @else

    {{-- done: the form gives way to the answer --}}
    <div x-show="done" x-cloak class="rounded-md p-5" data-qa="phone-done" style="background: color-mix(in srgb, var(--ds-green, #10b981) 10%, var(--surface)); border: 1px solid color-mix(in srgb, var(--ds-green, #10b981) 40%, var(--border));">
        <div class="text-base font-semibold" style="color: var(--text-primary);">✓ Call logged</div>
        <div class="text-sm mt-1" style="color: var(--text-secondary);" x-text="doneMessage"></div>
        <button type="button" class="corex-btn-outline text-sm mt-3" @click="again()">Log another call</button>
    </div>

    <div x-show="!done" class="space-y-4">
        @if($phoneCall['phone'])
            <div class="rounded-md p-3 flex items-center justify-between gap-3 flex-wrap" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="text-sm" style="color: var(--text-primary);">
                    <span style="color: var(--text-muted);">{{ trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: 'The seller' }}</span>
                    · <span class="font-mono">{{ $phoneCall['phone'] }}</span>
                </div>
                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $phoneCall['phone']) }}" class="corex-btn-outline text-sm no-underline" data-qa="phone-dial">Call now</a>
            </div>
        @endif

        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);" for="pc-when">When was the call?</label>
            <input id="pc-when" type="datetime-local" x-model="when" :max="now" class="px-3 py-2 text-sm rounded-md" style="{{ $inputStyle }}">
            <span class="text-xs ml-2" style="color: var(--text-muted);">Now, unless you are logging an earlier call.</span>
        </div>

        <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" data-qa="phone-outcomes">
            <div class="text-xs font-semibold" style="color: var(--text-secondary);">How did it go?</div>
            <template x-for="g in groups" :key="g.key">
                <div>
                    <div class="text-[0.6875rem] font-bold uppercase mb-1" style="color: var(--text-muted);" x-text="g.title"></div>
                    <div class="space-y-1.5">
                        <template x-for="c in g.items" :key="c.id">
                            <label class="flex items-start gap-2 text-sm cursor-pointer" style="color: var(--text-primary);">
                                <input type="radio" name="phone_outcome" :value="c.id" x-model.number="chosen" class="mt-1">
                                <span><span class="font-semibold" x-text="c.label"></span><span class="text-xs" style="color: var(--text-muted);" x-text="' · ' + c.summary"></span></span>
                            </label>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        <div x-show="current && current.needs_date" class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);" for="pc-next">Bring it back on</label>
            <input id="pc-next" type="date" x-model="nextDate" class="px-3 py-2 text-sm rounded-md" style="{{ $inputStyle }}">
        </div>
        <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);" x-show="current && current.offers_valuation">
            <input type="checkbox" x-model="valuation"> Evaluation booked
        </label>
        <div x-show="current && (current.needs_appointment || valuation)" class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);" for="pc-appt">Evaluation date and time</label>
            <input id="pc-appt" type="datetime-local" x-model="appointmentAt" class="px-3 py-2 text-sm rounded-md" style="{{ $inputStyle }}">
        </div>

        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);" for="pc-note">Feedback note</label>
            <textarea id="pc-note" rows="3" maxlength="5000" x-model="note" class="w-full px-3 py-2 text-sm rounded-md" style="{{ $inputStyle }}" placeholder="What did they say?"></textarea>
        </div>

        <div class="rounded-md p-3 text-sm" x-show="error" x-cloak data-qa="phone-error" style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); border: 1px solid var(--ds-crimson); color: var(--text-primary);" x-text="error"></div>

        <div class="pt-1">
            <button type="button" data-qa="phone-save" @click="save()" :disabled="saving || !chosen"
                    class="px-6 py-2.5 text-sm font-semibold rounded-md"
                    :style="chosen ? 'background: var(--brand-button, #0ea5e9); color:#fff;' : 'background: var(--surface-2); color: var(--text-muted); cursor:not-allowed;'">
                <span x-show="!saving">Log this call</span><span x-show="saving" x-cloak>Saving…</span>
            </button>
        </div>
    </div>
    @endif
</div>

<script>
function phoneCallForm(cfg) {
    return {
        groups: cfg.groups, now: cfg.now, when: cfg.now, chosen: '', nextDate: '', valuation: false, appointmentAt: '', note: '',
        saving: false, error: '', done: false, doneMessage: '',
        get current() { var id = Number(this.chosen); var found = null; this.groups.forEach(function (g) { g.items.forEach(function (c) { if (c.id === id) { found = c; } }); }); return found; },
        again() { this.done = false; this.chosen = ''; this.note = ''; this.nextDate = ''; this.valuation = false; this.appointmentAt = ''; this.error = ''; this.when = this.now; },
        async save() {
            if (this.saving || !this.current) { return; }
            var c = this.current;
            if (c.needs_date && !this.nextDate) { this.error = 'Pick the date to bring it back on.'; return; }
            if ((c.needs_appointment || this.valuation) && !this.appointmentAt) { this.error = 'Pick the evaluation date and time.'; return; }
            this.error = ''; this.saving = true;
            var body = new URLSearchParams({ tracked_property_id: cfg.tp, outcome_definition_id: c.id, channel: 'phone', occurred_at: this.when || '', note: this.note || '' });
            if (c.needs_date && this.nextDate) { body.set('next_action_at', this.nextDate); }
            if (this.valuation) { body.set('valuation_booked', '1'); }
            if (this.appointmentAt && (c.needs_appointment || this.valuation)) { body.set('appointment_at', this.appointmentAt); }
            try {
                var res = await fetch(cfg.url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: body });
                var data = {}; try { data = await res.json(); } catch (e) { /* not json */ }
                if (!res.ok || data.ok === false) {
                    var first = data.errors ? Object.values(data.errors)[0] : null;
                    this.error = (first && first[0]) || data.message || 'We could not log the call. Try again.';
                    return;
                }
                this.done = true; this.doneMessage = data.message || 'Saved.';
                if (window.corexPitchResult) { window.corexPitchResult('logged', cfg.address, c.label); }
            } catch (e) {
                this.error = 'No response from the server. Check your connection and try again.';
            } finally { this.saving = false; }
        },
    };
}
</script>
