@extends('layouts.corex')

{{--
    2026-09-20 — the list screen's own entry point for starting an
    inspection, added alongside the property Rental Images tab's existing
    AJAX "Start In/Out-Inspection" buttons, never replacing them. Only
    properties with an active lease are offered (RentalInspectionController::
    create()) — RentalInspection::start() hard-requires one.

    §43 (2026-10-05) — the SAME form now also offers "Schedule" (books a
    future date/time/inspector via RentalInspection::schedule(), lands back
    on the list — nothing is recorded yet) alongside the original "Start
    Now" (unchanged: RentalInspection::start(), straight into the recording
    tab). This is also the Lease Hub's own "Start in/out-inspection"
    next-step link's destination (lease_id/property_id prefilled) — so the
    Lease Hub gets scheduling for free, no change needed there.
--}}

@section('content')
<div class="p-6 max-w-2xl mx-auto space-y-4">
    <h1 class="text-lg font-semibold">Start or Schedule an Inspection</h1>

    <form method="POST" action="{{ route('corex.rental-inspections.store') }}" class="space-y-4 rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf
        {{-- §45.7 (Build I-5) — set when this form was opened from "Book from this date" on a loaded interim date; the
             server re-checks it (same agency/lease/type, still planned) before linking — never trusted as posted. --}}
        @if(old('planned_date_id', request()->query('planned_date_id')))
            <input type="hidden" name="planned_date_id" value="{{ old('planned_date_id', request()->query('planned_date_id')) }}">
        @endif

        @if ($errors->any())
            <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div>
            <label class="text-xs font-medium">Property</label>
            <select name="property_id" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                <option value="">Select a property…</option>
                @foreach($properties as $p)
                    <option value="{{ $p->id }}" @selected(old('property_id', $selectedPropertyId) == $p->id)>{{ $p->buildDisplayAddress() }}</option>
                @endforeach
            </select>
            @if($properties->isEmpty())
                <p class="text-xs mt-1" style="color: var(--text-muted);">No rental property currently has an active lease — an inspection needs one to attach to.</p>
            @endif
        </div>

        <div>
            <label class="text-xs font-medium">Type</label>
            @php
                // AT-444 follow-up 2 (2026-10-05) — the Lease Hub's next-step
                // links (both "Start in-inspection" and the new "Start
                // out-inspection") pass ?type= alongside lease_id; old()
                // alone never read it, so this select silently fell back to
                // whichever option sits first in the DOM (TYPE_IN) regardless
                // of the link clicked. old() still wins on a failed resubmit.
                $preselectedType = old('type', request()->query('type'));
            @endphp
            <select name="type" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                {{-- §49 — the one list of all four types, with the meaning beside each (RentalInspection::TYPE_PICKER). --}}
                @foreach(\App\Models\RentalInspection::typePickerOptions() as $opt)
                    <option value="{{ $opt['value'] }}" @selected($preselectedType === $opt['value'])>{{ $opt['text'] }}</option>
                @endforeach
            </select>
        </div>

        <div class="pt-2" style="border-top: 1px solid var(--border);">
            <p class="text-xs font-medium mb-2">Schedule for later (optional)</p>
            <p class="text-xs mb-3" style="color: var(--text-muted);">
                Leave the date blank and click "Start Now" below to begin recording immediately — exactly as before.
                Fill in a date and click "Schedule" to book it for later instead; the tenant, landlord and inspector
                are notified, and it appears on the "Scheduled inspections" tile until someone opens it to record.
            </p>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-medium">Date</label>
                    <input type="date" name="scheduled_for" value="{{ old('scheduled_for', request()->query('scheduled_for')) }}" @unless(request()->query('planned_date_id')) min="{{ now()->toDateString() }}" @endunless" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                </div>
                <div>
                    <label class="text-xs font-medium">Time</label>
                    <input type="time" name="scheduled_time" value="{{ old('scheduled_time') }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                </div>
                <div>
                    <label class="text-xs font-medium">Duration (minutes)</label>
                    <input type="number" name="scheduled_duration_minutes" value="{{ old('scheduled_duration_minutes', 60) }}" min="5" max="1440" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                </div>
                <div>
                    <label class="text-xs font-medium">Inspector</label>
                    <select name="inspector_user_id" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                        @foreach($inspectors as $inspectorOption)
                            <option value="{{ $inspectorOption->id }}" @selected(old('inspector_user_id', $defaultInspectorId) == $inspectorOption->id)>{{ $inspectorOption->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="mt-3">
                <label class="text-xs font-medium">Note</label>
                <textarea name="schedule_note" rows="2" maxlength="1000" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('schedule_note') }}</textarea>
            </div>
            <p class="text-xs mt-2" style="color: var(--text-muted);">
                This agency's usual notice period is {{ $minimumNoticeDays }} day{{ $minimumNoticeDays === 1 ? '' : 's' }} — booking inside that is still allowed, just flagged.
            </p>
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ route('corex.rental-inspections.index') }}" class="corex-btn-outline text-xs">Cancel</a>
            <button type="submit" name="intent" value="start_now" class="corex-btn-outline text-xs">Start Now</button>
            <button type="submit" name="intent" value="schedule" class="corex-btn-primary text-xs">Schedule</button>
        </div>
    </form>
</div>
@endsection
