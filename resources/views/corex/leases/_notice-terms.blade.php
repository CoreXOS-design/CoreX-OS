{{--
    .ai/specs/leases.md §18 — the lease's notice and early-cancellation terms: ONE block, used on the capture screen (new lease and
    renewal) and on the lease screen's "Notice terms" card, so the two can never differ. They start from the agency's defaults (or the
    term being renewed) and may be changed per lease. These are the values the lease agreement document and the tenant / owner portal
    FAQ are filled from.
    Needs: $noticeValues (key => value), $noticePrefix ('notice'), optional $noticeHide (keys not to show), $noticeDisabled.
    AT-489 (Johan, 21 Oct 2026) - EXPIRY NOTICE. The agency must tell a tenant their fixed term is ending not more than 80 and not less than 40
    business days before it ends (Consumer Protection Act s14(2)(c); clause 5.1 of the lease). "Expiry notice due between" shows the two
    dates, COMPUTED from the end date (end minus 80 / end minus 40 business days; CPA s2(6): first day excluded, last day included, weekends
    and South African public holidays left out; the 80 / 40 are agency settings) and read-only - nothing is typed. It re-works live when the
    end date changes (or month-to-month is ticked: no end, no expiry notice). The server does the same sum (LeaseNoticeTermsService::expiryWindow).
      optional $noticeSendBd                        the agency's send day: business days before the end that CoreX sends the expiry notice
      optional $leaseStart / $leaseEnd              Y-m-d, when the date is not an input on this form (the lease screen's Notice terms card)
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
        // the law (CPA s14(2)(c)): not more than 80 and not less than 40 business days; the agency chooses the day within it that CoreX sends
        'maxBd' => \App\Models\LeaseSetting::CPA_EXPIRY_NOTICE_MAX_BUSINESS_DAYS,
        'minBd' => \App\Models\LeaseSetting::CPA_EXPIRY_NOTICE_MIN_BUSINESS_DAYS,
        'sendBd' => ($noticeSendBd ?? null) ? (int) $noticeSendBd : \App\Models\LeaseSetting::DEFAULT_EXPIRY_NOTICE_SEND_BUSINESS_DAYS,
        'fixedStart' => $leaseStart ?? null,
        'fixedEnd' => $leaseEnd ?? null,
        // the public holidays the browser counts business days with (the server counts with the very same list)
        'holidays' => app(\App\Services\Rentals\BusinessDayService::class)->holidayList(now()->subYear()->startOfYear(), now()->addYears(10)->endOfYear()),
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
    <div class="col-span-2 sm:col-span-1" data-qa="expiry-notice-window">
        <label class="prop-label">Expiry notice due between</label>
        <div class="text-sm" style="color: var(--text-primary);" x-show="windowText" x-text="windowText" x-cloak data-qa="expiry-notice-dates"></div>
        <div class="text-sm mt-1" style="color: var(--text-primary);" x-show="sendText" x-text="sendText" x-cloak data-qa="expiry-notice-send-on"></div>
        <p class="text-xs mt-0.5" style="color: var(--text-muted);" x-show="windowText" x-cloak>
            When the agency must tell the tenant the fixed term is ending (Consumer Protection Act): {{ $liveCfg['maxBd'] }} to {{ $liveCfg['minBd'] }} business days before the lease ends; weekends and public holidays do not count. Worked out from the end date.
        </p>
        <p class="text-xs mt-0.5" style="color: var(--text-muted);" x-show="!windowText" data-qa="expiry-notice-none">Fill in the end date and this works out. A month-to-month lease has no end date, so no expiry notice.</p>
    </div>
    @unless(in_array('earliest_termination_date', $hide, true))
        <div class="col-span-2 sm:col-span-1">
            <label class="prop-label">Earliest date the lease may end</label>
            <input type="date" name="{{ $np }}[earliest_termination_date]" value="{{ old($np . '.earliest_termination_date', $nv['earliest_termination_date'] ?? '') }}" @disabled($off) class="prop-input" style="color-scheme: light dark;"
                   @input="check()" :style="err.termination ? 'color-scheme: light dark; border-color: var(--ds-crimson);' : 'color-scheme: light dark;'">
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
 * AT-489 - "Expiry notice due between" for the lease capture screen (new lease and renewal) and the lease screen's Notice terms card.
 * The SAME calculation as the server (LeaseNoticeTermsService::expiryWindow / BusinessDayService::daysBefore): the date with exactly N
 * business days after it up to and including the lease END date (CPA s2(6): first day excluded, last day included); a business day is not
 * a Saturday, not a Sunday and not a public holiday (the server hands this page its holiday list). From = N is the agency's maximum (80),
 * to = the minimum (40). Nothing is typed; a month-to-month lease (no end date) has none.
 */
if (typeof window.noticeTermsLive === 'undefined') {
    window.noticeTermsLive = function (cfg) {
        const ISO = /^\d{4}-\d{2}-\d{2}$/;
        const utc = (iso) => { const [y, m, d] = iso.split('-').map(Number); return Date.UTC(y, m - 1, d); };
        const iso = (ms) => { const dt = new Date(ms); return dt.getUTCFullYear() + '-' + String(dt.getUTCMonth() + 1).padStart(2, '0') + '-' + String(dt.getUTCDate()).padStart(2, '0'); };
        const nice = (v) => new Date(utc(v)).toLocaleDateString('en-ZA', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
        const holidays = new Set(cfg.holidays || []);
        const isBusinessDay = (ms) => { const dow = new Date(ms).getUTCDay(); return dow !== 0 && dow !== 6 && !holidays.has(iso(ms)); };
        // BusinessDayService::subtract() - the n-th business day before `end`, end itself not counted
        const subtract = (end, n) => { let ms = utc(end), counted = 0; while (counted < n) { ms -= 86400000; if (isBusinessDay(ms)) { counted++; } } return iso(ms); };
        // BusinessDayService::daysBefore() - exactly n business days after the answer, up to and including `end`
        const daysBefore = (end, n) => subtract(end, n + (isBusinessDay(utc(end)) ? 0 : 1));
        return {
            ec: cfg.ec, err: { termination: '' }, windowText: '', sendText: '', bound: false,
            form() { return this.$root.closest('form'); },
            field(name) { return this.$root.querySelector('[name="' + cfg.prefix + '[' + name + ']"]'); },
            startValue() {
                if (cfg.fixedStart) return cfg.fixedStart;
                const el = this.form() && this.form().querySelector('[name="start_date"]');
                return el && ISO.test(el.value) ? el.value : '';
            },
            // no end date (month-to-month, or not filled in yet) = no expiry notice
            endValue() {
                if (cfg.fixedEnd) return cfg.fixedEnd;
                const f = this.form();
                const el = f && f.querySelector('[name="end_date"]');
                const m2m = f && f.querySelector('[name="is_month_to_month"]');
                if (!el || el.disabled || (m2m && m2m.checked)) return '';
                return ISO.test(el.value) ? el.value : '';
            },
            live() {
                if (!this.bound) {
                    this.bound = true;
                    const form = this.form();
                    const watch = (el) => { if (el) { el.addEventListener('input', () => this.check()); el.addEventListener('change', () => this.check()); } };
                    if (form) { ['start_date', 'end_date', 'is_month_to_month'].forEach((n) => watch(form.querySelector('[name="' + n + '"]'))); }
                }
                this.check();
            },
            check() {
                const end = this.endValue(), start = this.startValue();
                this.windowText = end ? nice(daysBefore(end, cfg.maxBd)) + ' and ' + nice(daysBefore(end, cfg.minBd)) : '';
                this.sendText = end ? 'CoreX sends it on ' + nice(daysBefore(end, cfg.sendBd)) + ' (' + cfg.sendBd + ' business days before the lease ends).' : '';
                const t = this.field('earliest_termination_date');
                const tv = t && ISO.test(t.value) ? t.value : '';
                this.err.termination = start && tv && tv < start ? 'The lease cannot end on ' + nice(tv) + ' - that is before it starts on ' + nice(start) + '.' : '';
            },
        };
    };
}
</script>
