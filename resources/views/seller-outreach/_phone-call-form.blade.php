{{-- Road to Sold 23.23 drop 3: the Phone call tab of the Contact step. The call has already happened; the agent says when, how it went and what to do next.
     It writes the SAME contact record as the belt's "Log contact" (rts_contact_outcomes, channel = phone) through the one Road to Sold outcome route; nothing is composed or sent.
     props: $phoneCall (PitchContactService::phoneForm), $contact, $property --}}
@php
    $addr = $property ? (trim((string) $property->composeAddressFromParts()) ?: ($property->address ?: 'this property')) : null;
    $inputStyle = 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);';
    $callAt = \Carbon\Carbon::parse($phoneCall['now']);
    // The call is written through the Road to Sold outcome route, which refuses when that feature is switched off for the agency.
    $rtsOn = feature('road-to-sold');
@endphp
<div data-qa="phone-call" class="space-y-4"
     x-data="phoneCallForm({
        url: @js($rtsOn ? route('road-to-sold.outcomes.record') : null),
        csrf: @js(csrf_token()),
        tp: @js($phoneCall['tracked_property_id']),
        now: @js($phoneCall['now']),
        address: @js($addr),
        groups: @js($phoneCall['groups']),
        bookUrl: @js($phoneCall['book_url']),
     })">

    @if(! $rtsOn)
        <div class="rounded-md p-4 text-sm" data-qa="phone-rts-off" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">
            Road to Sold is switched off for your agency, so a call cannot be logged here.
        </div>
    @elseif(! $phoneCall['tracked_property_id'])
        <div class="rounded-md p-4 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">
            This pitch is about an address only, so there is no property on your Road to Sold to log a call against. Link or create the property on the contact first.
        </div>
    @elseif($phoneCall['refusal'])
        <div class="rounded-md p-4 text-sm" data-qa="phone-gate" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 40%, var(--border)); color: var(--text-primary);">
            {{ $phoneCall['refusal'] }}
            @if(!empty($phoneCall['missing']))
                <div class="mt-1">Still missing: {{ implode(' and ', $phoneCall['missing']) }}.</div>
            @endif
            @if(\App\Support\Embed::on())
                <button type="button" class="corex-btn-outline text-sm mt-3" data-qa="phone-gate-go" onclick="window.corexPitchGo && corexPitchGo('owner', {!! in_array('the deed record', $phoneCall['missing'] ?? [], true) ? "'deed'" : 'null' !!})">Go to the Owner step</button>
            @endif
        </div>
    @else

    {{-- done: the form gives way to the answer --}}
    <div x-show="done" x-cloak class="rounded-md p-5" data-qa="phone-done" style="background: color-mix(in srgb, var(--ds-green, #10b981) 10%, var(--surface)); border: 1px solid color-mix(in srgb, var(--ds-green, #10b981) 40%, var(--border));">
        <div class="text-base font-semibold" style="color: var(--text-primary);">✓ Call logged</div>
        <div class="text-sm mt-1" style="color: var(--text-secondary);" x-text="doneMessage"></div>
        <div class="flex items-center gap-2 mt-3 flex-wrap">
            <button type="button" x-show="canBook" x-cloak data-qa="phone-book-next" class="px-4 py-2 text-sm font-semibold rounded-md" style="background: var(--brand-button, #0ea5e9); color:#fff;" @click="goBook(false)">Book appointment →</button>
            <button type="button" class="corex-btn-outline text-sm" @click="again()">Log another call</button>
        </div>
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
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);" for="pc-when-date">When was the call? <span style="color: var(--ds-crimson, #dc2626);" title="Required">*</span></label>
            <div class="flex items-center gap-2 flex-wrap" data-qa="call-when">
                <x-dmy-date id="pc-when-date" name="pc_when_date" :value="$callAt->toDateString()" :required="true" />
                <input id="pc-when-time" type="time" required value="{{ $callAt->format('H:i') }}" class="px-3 py-2 text-xs rounded-md" style="{{ $inputStyle }}" aria-label="Time of the call">
                <span class="text-xs" style="color: var(--text-muted);">Now, unless you are logging an earlier call.</span>
            </div>
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
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);" for="pc-next">Bring it back on <span style="color: var(--ds-crimson, #dc2626);" title="Required">*</span></label>
            <x-dmy-date id="pc-next" name="pc_next" :required="true" :min="now()->toDateString()" />
        </div>
        <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);" x-show="current && current.offers_valuation">
            <input type="checkbox" x-model="valuation"> Appointment booked
        </label>
        <div x-show="deferBooking" x-cloak data-qa="phone-book-hint" class="rounded-md p-4 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">
            You pick the date and time of the appointment next, in the calendar. The call is logged once it is booked.
        </div>
        <div x-show="!bookUrl && current && (current.needs_appointment || valuation)" class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);" for="pc-appt">Appointment date and time <span style="color: var(--ds-crimson, #dc2626);" title="Required">*</span></label>
            <div class="flex items-center gap-2 flex-wrap">
                <x-dmy-date id="pc-appt" name="pc_appt_date" :required="true" :min="now()->toDateString()" />
                <input id="pc-appt-time" type="time" required value="09:00" class="px-3 py-2 text-xs rounded-md" style="{{ $inputStyle }}" aria-label="Time of the evaluation">
            </div>
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
                <span x-show="!saving" x-text="deferBooking ? 'Next: book the appointment →' : 'Save call'"></span><span x-show="saving" x-cloak>Saving…</span>
            </button>
        </div>
    </div>
    @endif
</div>

<script>
function phoneCallForm(cfg) {
    return {
        groups: cfg.groups, now: cfg.now, bookUrl: cfg.bookUrl, loggedKind: null, loggedGroup: null, chosen: '', valuation: false, note: '',
        saving: false, error: '', done: false, doneMessage: '',
        get current() { var id = Number(this.chosen); var found = null; this.groups.forEach(function (g) { g.items.forEach(function (c) { if (c.id === id) { found = c; } }); }); return found; },
        // an evaluation outcome is booked in the calendar's own form (the next step), and the call is logged against that appointment
        get deferBooking() { return !!this.bookUrl && !!this.current && (this.current.needs_appointment || (this.valuation && this.current.offers_valuation)); },
        get canBook() { return !!this.bookUrl && this.loggedKind === 'conversation' && this.loggedGroup !== 'off_ramp'; },
        // dates are dd/mm/yyyy (x-dmy-date) and post their value as YYYY-MM-DD in a hidden field of that name; the time is its own box
        iso(name) { var i = this.$root.querySelector('input[type="hidden"][name="' + name + '"]'); return i ? i.value : ''; },
        timeOf(id) { var i = document.getElementById(id); return i && i.value ? i.value : ''; },
        readWhen() { var d = this.iso('pc_when_date'), t = this.timeOf('pc-when-time'); return d && t ? d + 'T' + t : ''; },
        readAppt() { var d = this.iso('pc_appt_date'), t = this.timeOf('pc-appt-time'); return d && t ? d + 'T' + t : ''; },
        clearDmy(name) { var i = this.$root.querySelector('input[type="hidden"][name="' + name + '"]'); var s = i && i.closest('[data-dmy-date]'); if (s && window.Alpine) { var d = Alpine.$data(s); d.iso = ''; d.shown = ''; } },
        goBook(withCall) {
            var u = new URL(this.bookUrl, window.location.origin);
            if (withCall) {
                u.searchParams.set('pitch_outcome', this.current.id); u.searchParams.set('pitch_channel', 'phone');
                if (this.readWhen()) { u.searchParams.set('pitch_when', this.readWhen()); }
                if (this.note) { u.searchParams.set('pitch_note', this.note); }
            }
            window.location.href = u.toString();
        },
        again() { this.done = false; this.chosen = ''; this.note = ''; this.valuation = false; this.error = ''; this.clearDmy('pc_next'); this.clearDmy('pc_appt_date'); },
        async save() {
            if (this.saving || !this.current) { return; }
            var c = this.current;
            var when = this.readWhen(), next = this.iso('pc_next'), appt = this.readAppt();
            if (!when) { this.error = 'Enter the date (dd/mm/yyyy) and the time of the call.'; return; }
            if (new Date(when) > new Date(Date.now() + 60000)) { this.error = 'The time of the call cannot be in the future. Use now, or an earlier time.'; return; }
            if (c.needs_date && !next) { this.error = 'Pick the date to bring it back on (dd/mm/yyyy).'; return; }
            if (!this.bookUrl && (c.needs_appointment || this.valuation) && !appt) { this.error = 'Pick the appointment date (dd/mm/yyyy) and time.'; return; }
            if (this.deferBooking) { this.goBook(true); return; }
            this.error = ''; this.saving = true;
            var body = new URLSearchParams({ tracked_property_id: cfg.tp, outcome_definition_id: c.id, channel: 'phone', occurred_at: when, note: this.note || '' });
            if (c.needs_date && next) { body.set('next_action_at', next); }
            if (this.valuation) { body.set('valuation_booked', '1'); }
            if (appt && (c.needs_appointment || this.valuation)) { body.set('appointment_at', appt); }
            try {
                var res = await fetch(cfg.url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: body });
                var data = {}; try { data = await res.json(); } catch (e) { /* not json */ }
                if (!res.ok || data.ok === false) {
                    var first = data.errors ? Object.values(data.errors)[0] : null;
                    this.error = (first && first[0]) || data.message || 'We could not log the call. Try again.';
                    return;
                }
                this.done = true; this.doneMessage = data.message || 'Saved.'; this.loggedKind = c.kind; this.loggedGroup = c.group;
                if (window.corexPitchResult) { window.corexPitchResult('logged', cfg.address, c.label); }
            } catch (e) {
                this.error = 'No response from the server. Check your connection and try again.';
            } finally { this.saving = false; }
        },
    };
}
</script>
