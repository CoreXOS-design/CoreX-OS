{{--
    .ai/specs/leases.md §18 — the lease's notice and early-cancellation terms: ONE block, used on the capture screen (new lease and
    renewal) and on the lease screen's "Notice terms" card, so the two can never differ. They start from the agency's defaults (or the
    term being renewed) and may be changed per lease. These are the values the lease agreement document and the tenant / owner portal
    FAQ are filled from.
    Needs: $noticeValues (key => value), $noticePrefix ('notice'), optional $noticeHide (keys not to show), $noticeDisabled.
    AT-489 (Johan, 21 Oct 2026) - the dates are worked out LIVE on the screen with the server's own formula and the server's own rules:
      optional $earliestNoticeMonths  the agency's "not before" months (start date + N months is the earliest notice date a blank one gets)
      optional $leaseStart            Y-m-d, when the start date is not an input on this form (the lease screen's Notice terms card)
      optional $shiftWithStart        true when the dates were carried forward from the term being renewed (they move with a new start date)
    Changing the start date, end date or notice period re-works the earliest notice date unless the agent typed their own - a typed one is
    kept and re-checked - and any rule breach is said in words under the field, not only when the form is posted.
--}}
@php
    $nv = $noticeValues ?? [];
    $np = $noticePrefix ?? 'notice';
    $hide = $noticeHide ?? [];
    $off = ! empty($noticeDisabled);
    $units = ['days' => 'days', 'weeks' => 'weeks', 'months' => 'months'];
    $ec = old($np . '.early_cancellation_allowed', $nv['early_cancellation_allowed'] ?? '');
    $liveCfg = [
        'ec' => (string) $ec,
        'prefix' => $np,
        'months' => ($earliestNoticeMonths ?? null) ? (int) $earliestNoticeMonths : null,
        'fixedStart' => $leaseStart ?? null,
        'shiftWithStart' => (bool) ($shiftWithStart ?? false),
    ];
@endphp
<div class="grid grid-cols-2 gap-3" data-qa="lease-notice-terms" x-data="noticeTermsLive({{ \Illuminate\Support\Js::from($liveCfg) }})" x-init="live()">
    <div class="col-span-2 sm:col-span-1">
        <label class="prop-label">Notice period</label>
        <div class="flex gap-2">
            <input type="number" name="{{ $np }}[notice_period]" min="1" max="999" step="1" value="{{ old($np . '.notice_period', $nv['notice_period'] ?? '') }}" @disabled($off) class="prop-input" style="max-width: 6rem;">
            <select name="{{ $np }}[notice_period_unit]" @disabled($off) class="prop-select">
                @foreach($units as $v => $label)
                    <option value="{{ $v }}" @selected(old($np . '.notice_period_unit', $nv['notice_period_unit'] ?? 'days') === $v)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="col-span-2 sm:col-span-1">
        <label class="prop-label">Earliest date notice may be given</label>
        <input type="date" name="{{ $np }}[earliest_notice_date]" value="{{ old($np . '.earliest_notice_date', $nv['earliest_notice_date'] ?? '') }}" @disabled($off) class="prop-input" style="color-scheme: light dark;"
               x-ref="earliest" @input="typed('earliest')" :style="err.earliest ? 'color-scheme: light dark; border-color: var(--ds-crimson);' : 'color-scheme: light dark;'">
        @if(($earliestNoticeMonths ?? null) && empty($nv['earliest_notice_date']))
            <p class="text-xs mt-0.5" style="color: var(--text-muted);" x-show="!note.earliest">Blank = start date + {{ $earliestNoticeMonths }} {{ $earliestNoticeMonths === 1 ? 'month' : 'months' }} (your agency setting)</p>
        @endif
        {{-- AT-489 - what the date is and why; any rule breach in words, as soon as it happens. --}}
        <p class="text-xs mt-0.5" style="color: var(--text-muted);" x-show="note.earliest" x-text="note.earliest" x-cloak data-qa="notice-earliest-note"></p>
        <p class="text-xs mt-0.5" style="color: var(--ds-crimson);" x-show="err.earliest" x-text="err.earliest" x-cloak role="alert" data-qa="notice-earliest-live-error"></p>
        <button type="button" class="text-xs underline mt-0.5" style="color: var(--brand-icon, #0ea5e9);" x-show="canReset" x-cloak @click="resetEarliest()" data-qa="notice-earliest-reset">Use the calculated date</button>
    </div>
    @unless(in_array('earliest_termination_date', $hide, true))
        <div class="col-span-2 sm:col-span-1">
            <label class="prop-label">Earliest date the lease may end</label>
            <input type="date" name="{{ $np }}[earliest_termination_date]" value="{{ old($np . '.earliest_termination_date', $nv['earliest_termination_date'] ?? '') }}" @disabled($off) class="prop-input" style="color-scheme: light dark;"
                   x-ref="termination" @input="typed('termination')" :style="err.termination ? 'color-scheme: light dark; border-color: var(--ds-crimson);' : 'color-scheme: light dark;'">
            <p class="text-xs mt-0.5" style="color: var(--ds-crimson);" x-show="err.termination" x-text="err.termination" x-cloak role="alert" data-qa="notice-termination-live-error"></p>
        </div>
    @endunless
    <div class="col-span-2 sm:col-span-1">
        <label class="prop-label">Early cancellation allowed</label>
        <select name="{{ $np }}[early_cancellation_allowed]" x-model="ec" @disabled($off) class="prop-select">
            <option value="">Not set</option>
            <option value="yes">Yes</option>
            <option value="no">No</option>
        </select>
    </div>
    <div class="col-span-2 sm:col-span-1" x-show="ec === 'yes'" @if($ec !== 'yes') x-cloak @endif>
        <label class="prop-label">Notice needed to cancel early</label>
        <div class="flex gap-2">
            <input type="number" name="{{ $np }}[early_cancellation_notice]" min="1" max="999" step="1" value="{{ old($np . '.early_cancellation_notice', $nv['early_cancellation_notice'] ?? '') }}" @disabled($off) class="prop-input" style="max-width: 6rem;">
            <select name="{{ $np }}[early_cancellation_notice_unit]" @disabled($off) class="prop-select">
                @foreach($units as $v => $label)
                    <option value="{{ $v }}" @selected(old($np . '.early_cancellation_notice_unit', $nv['early_cancellation_notice_unit'] ?? 'days') === $v)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="col-span-2" x-show="ec === 'yes'" @if($ec !== 'yes') x-cloak @endif>
        <label class="prop-label">Early-cancellation penalty (wording)</label>
        <textarea name="{{ $np }}[early_cancellation_penalty]" rows="2" maxlength="2000" @disabled($off) class="prop-input">{{ old($np . '.early_cancellation_penalty', $nv['early_cancellation_penalty'] ?? '') }}</textarea>
    </div>
    @if($errors->has($np . '.*') || $errors->hasAny(array_map(fn ($k) => $np . '.' . $k, \App\Services\Rentals\LeaseNoticeTermsService::EDIT_KEYS)))
        <div class="col-span-2 text-xs" style="color: var(--ds-crimson);" data-qa="lease-notice-errors">
            @foreach(\App\Services\Rentals\LeaseNoticeTermsService::EDIT_KEYS as $k)
                @foreach($errors->get($np . '.' . $k) as $message)<div>{{ $message }}</div>@endforeach
            @endforeach
        </div>
    @endif
</div>

<script>
/*
 * AT-489 - live earliest-notice date for the lease capture screen (new lease and renewal) and the lease screen's Notice terms card.
 * The SAME formula and the SAME rules as the server (LeaseNoticeTermsService): a blank earliest notice date is the start date plus the
 * agency's "not before" months (months added without overflowing the month end); a renewal's carried-forward dates move by the number of
 * days the start date moved; earliest notice may not be before the lease starts, the earliest end may not be before it either, and
 * notice may not first be given after the earliest date the lease may end. Nothing here replaces the server check - it only tells the
 * agent at once, in words, instead of after Create.
 */
if (typeof window.noticeTermsLive === 'undefined') {
    window.noticeTermsLive = function (cfg) {
        const ISO = /^\d{4}-\d{2}-\d{2}$/;
        const utc = (iso) => { const [y, m, d] = iso.split('-').map(Number); return Date.UTC(y, m - 1, d); };
        const iso = (ms) => { const dt = new Date(ms); return dt.getUTCFullYear() + '-' + String(dt.getUTCMonth() + 1).padStart(2, '0') + '-' + String(dt.getUTCDate()).padStart(2, '0'); };
        const nice = (v) => new Date(utc(v)).toLocaleDateString('en-ZA', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
        const addDays = (v, n) => iso(utc(v) + n * 86400000);
        const daysBetween = (a, b) => Math.round((utc(b) - utc(a)) / 86400000);
        const addMonths = (v, n) => {
            const [y, m, d] = v.split('-').map(Number);
            const total = y * 12 + (m - 1) + n;
            const ty = Math.floor(total / 12), tm = total % 12;
            const dim = new Date(Date.UTC(ty, tm + 1, 0)).getUTCDate();
            return iso(Date.UTC(ty, tm, Math.min(d, dim)));
        };
        return {
            ec: cfg.ec, err: { earliest: '', termination: '' }, note: { earliest: '' }, canReset: false,
            auto: { earliest: false, termination: false }, lastStart: null, bound: false,
            startValue() {
                if (cfg.fixedStart) return cfg.fixedStart;
                const el = this.form() && this.form().querySelector('[name="start_date"]');
                return el && ISO.test(el.value) ? el.value : '';
            },
            form() { return this.$root.closest('form'); },
            field(name) { return this.$root.querySelector('[name="' + cfg.prefix + '[' + name + ']"]'); },
            calculated() { const s = this.startValue(); return cfg.months && s ? addMonths(s, cfg.months) : ''; },
            live() {
                const e = this.field('earliest_notice_date'), t = this.field('earliest_termination_date');
                this.lastStart = this.startValue() || null;
                // "Managed by the screen" = blank, or still exactly what the formula (or a carried-forward move) gives.
                // Anything else was typed (or already saved) by the agent and is kept - only re-checked.
                this.auto.earliest = !!e && (e.value === '' || (this.calculated() !== '' && e.value === this.calculated()) || cfg.shiftWithStart);
                this.auto.termination = !!t && (t.value === '' || cfg.shiftWithStart);
                if (!this.bound) {
                    this.bound = true;
                    const form = this.form();
                    const watch = (el) => { if (el) { el.addEventListener('input', () => this.changed()); el.addEventListener('change', () => this.changed()); } };
                    if (!cfg.fixedStart && form) { watch(form.querySelector('[name="start_date"]')); }
                    if (form) { watch(form.querySelector('[name="end_date"]')); }
                    watch(this.field('notice_period')); watch(this.field('notice_period_unit'));
                }
                this.check();
            },
            // The agent's own typing takes the field out of the screen's hands; clearing it hands it back.
            typed(which) {
                const el = which === 'earliest' ? this.field('earliest_notice_date') : this.field('earliest_termination_date');
                this.auto[which] = !el || el.value === '';
                this.check();
            },
            changed() {
                const start = this.startValue();
                const e = this.field('earliest_notice_date'), t = this.field('earliest_termination_date');
                const delta = start && this.lastStart ? daysBetween(this.lastStart, start) : 0;
                if (e && this.auto.earliest) {
                    // A renewal's carried-forward date moves by the days the start moved (the server's own offset rule);
                    // otherwise a screen-managed date is the formula: start + the agency's months.
                    if (cfg.shiftWithStart) { if (delta && ISO.test(e.value)) { e.value = addDays(e.value, delta); } }
                    else if (this.calculated() !== '') { e.value = this.calculated(); }
                }
                if (t && this.auto.termination && cfg.shiftWithStart && delta && ISO.test(t.value)) { t.value = addDays(t.value, delta); }
                if (start) { this.lastStart = start; }
                this.check();
            },
            resetEarliest() {
                const e = this.field('earliest_notice_date');
                if (e && this.calculated() !== '') { e.value = this.calculated(); this.auto.earliest = true; }
                this.check();
            },
            check() {
                const start = this.startValue();
                const e = this.field('earliest_notice_date'), t = this.field('earliest_termination_date');
                const ev = e && ISO.test(e.value) ? e.value : '', tv = t && ISO.test(t.value) ? t.value : '';
                this.err.earliest = ''; this.err.termination = ''; this.note.earliest = '';
                if (start && ev && ev < start) {
                    this.err.earliest = 'Notice cannot be given from ' + nice(ev) + ' - that is before the lease starts on ' + nice(start) + '. Pick ' + nice(start) + ' or later.';
                } else if (ev && tv && ev > tv) {
                    this.err.earliest = 'Notice cannot first be given on ' + nice(ev) + ' - that is after the earliest date the lease may end (' + nice(tv) + '). Pick ' + nice(tv) + ' or earlier.';
                }
                if (start && tv && tv < start) {
                    this.err.termination = 'The lease cannot end on ' + nice(tv) + ' - that is before it starts on ' + nice(start) + '.';
                }
                const calc = this.calculated();
                if (ev && calc && ev === calc && this.auto.earliest) {
                    this.note.earliest = 'Worked out from the start date + ' + cfg.months + (cfg.months === 1 ? ' month' : ' months') + ' (your agency setting). Type another date to change it.';
                } else if (ev && this.auto.earliest === false) {
                    this.note.earliest = 'Your date is kept when the other dates change.';
                }
                this.canReset = !!(calc && ev && ev !== calc);
            },
        };
    };
}
</script>
