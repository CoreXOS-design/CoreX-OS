{{--
    Event Reminder fields (AT-178).
    Spec: .ai/specs/calendar-event-reminders.md §8
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md (CSS vars + fallbacks, no naked hex)

    Self-contained reminder UI for the New/Edit event slide-over. Binds to the shared
    calendarPage() `form.*` reminder keys via x-model (UI controls) and emits deterministic
    hidden inputs (send_reminder / reminder_offset / reminder_popup / reminder_email) so the
    native form POST carries them regardless of checkbox quirks. This partial is the ONLY
    place reminder markup lives — included with one line inside the event <form> so no
    cockpit geometry/windowing/layers/deck is touched (cc3 owns those).

    Expects $reminderLeadOptions (int[] minutes) from the controller.
--}}
@php
    $leadLabel = function (int $m): string {
        if ($m <= 0)        return 'At time of event';
        if ($m < 60)        return $m . ' minutes before';
        if ($m === 60)      return '1 hour before';
        if ($m % 60 === 0)  return ($m / 60) . ' hours before';
        if ($m === 1440)    return '1 day before';
        return round($m / 60, 1) . ' hours before';
    };
    $leadOptions = ($reminderLeadOptions ?? [0, 5, 10, 15, 30, 60, 120, 1440]);
@endphp

<div class="pt-1">
    {{-- Deterministic POST payload (independent of checkbox submit semantics). --}}
    <input type="hidden" name="send_reminder"  :value="form.sendReminder ? 1 : 0">
    <input type="hidden" name="reminder_offset" :value="form.reminderOffset">
    <input type="hidden" name="reminder_popup"  :value="form.reminderPopup ? 1 : 0">
    <input type="hidden" name="reminder_email"  :value="form.reminderEmail ? 1 : 0">

    <div class="flex items-center justify-between">
        <label class="block text-xs font-medium" style="color: var(--text-secondary, #4a5568);">Reminder</label>
        <label class="inline-flex items-center gap-2 cursor-pointer">
            <input type="checkbox" x-model="form.sendReminder"
                   class="rounded" style="accent-color: var(--brand-default, #1a365d);">
            <span class="text-xs" style="color: var(--text-muted, #718096);"
                  x-text="form.sendReminder ? 'On' : 'Off'"></span>
        </label>
    </div>

    <div x-show="form.sendReminder" x-cloak class="mt-2 space-y-2">
        {{-- Lead-time selector (agency-configurable option list) --}}
        <div>
            <select x-model.number="form.reminderOffset"
                    class="w-full rounded-md px-3 py-2 text-sm"
                    style="background: var(--surface, #fff); border: 1px solid var(--border, #e2e8f0); color: var(--text-primary, #1a202c);">
                @foreach($leadOptions as $opt)
                    <option value="{{ (int) $opt }}">{{ $leadLabel((int) $opt) }}</option>
                @endforeach
            </select>
        </div>

        {{-- Channel toggles (independent) --}}
        <div class="flex items-center gap-4">
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input type="checkbox" x-model="form.reminderPopup"
                       class="rounded" style="accent-color: var(--brand-default, #1a365d);">
                <span class="text-xs" style="color: var(--text-secondary, #4a5568);">On-screen popup</span>
            </label>
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input type="checkbox" x-model="form.reminderEmail"
                       class="rounded" style="accent-color: var(--brand-default, #1a365d);">
                <span class="text-xs" style="color: var(--text-secondary, #4a5568);">Email</span>
            </label>
        </div>
        <p x-show="!form.reminderPopup && !form.reminderEmail" x-cloak
           class="text-[11px]" style="color: var(--warning, #d69e2e);">
            Pick at least one channel, or the reminder won't be sent.
        </p>
    </div>
</div>
