{{--
    .ai/specs/leases.md §18 — the lease's notice and early-cancellation terms: ONE block, used on the capture screen (new lease and
    renewal) and on the lease screen's "Notice terms" card, so the two can never differ. They start from the agency's defaults (or the
    term being renewed) and may be changed per lease. These are the values the lease agreement document and the tenant / owner portal
    FAQ are filled from.
    Needs: $noticeValues (key => value), $noticePrefix ('notice'), optional $noticeHide (keys not to show), $noticeDisabled.
--}}
@php
    $nv = $noticeValues ?? [];
    $np = $noticePrefix ?? 'notice';
    $hide = $noticeHide ?? [];
    $off = ! empty($noticeDisabled);
    $units = ['days' => 'days', 'weeks' => 'weeks', 'months' => 'months'];
    $ec = old($np . '.early_cancellation_allowed', $nv['early_cancellation_allowed'] ?? '');
@endphp
<div class="grid grid-cols-2 gap-3" data-qa="lease-notice-terms" x-data="{ ec: {{ \Illuminate\Support\Js::from((string) $ec) }} }">
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
        <input type="date" name="{{ $np }}[earliest_notice_date]" value="{{ old($np . '.earliest_notice_date', $nv['earliest_notice_date'] ?? '') }}" @disabled($off) class="prop-input" style="color-scheme: light dark;">
        @if(($earliestNoticeMonths ?? null) && empty($nv['earliest_notice_date']))
            <p class="text-xs mt-0.5" style="color: var(--text-muted);">Blank = start date + {{ $earliestNoticeMonths }} {{ $earliestNoticeMonths === 1 ? 'month' : 'months' }} (your agency setting)</p>
        @endif
    </div>
    @unless(in_array('earliest_termination_date', $hide, true))
        <div class="col-span-2 sm:col-span-1">
            <label class="prop-label">Earliest date the lease may end</label>
            <input type="date" name="{{ $np }}[earliest_termination_date]" value="{{ old($np . '.earliest_termination_date', $nv['earliest_termination_date'] ?? '') }}" @disabled($off) class="prop-input" style="color-scheme: light dark;">
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
