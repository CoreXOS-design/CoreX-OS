{{-- Lead response — counting hours per weekday (Johan, 2026-10-07). ONE editor, shared by Settings → Lead
     response and the Setup Wizard's Contacts step so the two cannot drift. Posts lead_response_hours[day][counted|start|end]
     plus the lead_response_present marker (the saver only writes what was rendered — spec §6.1). A day left unticked is
     "not counted"; start = end counts nothing for that day. Expects $hours (BusinessHours::normalise shape). --}}
@php
    $dayLabels = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
@endphp
<div x-data="{
        copyMonday() {
            const q = (d, k) => this.$root.querySelector(`[name='lead_response_hours[${d}][${k}]']`);
            const box = (d) => this.$root.querySelector(`input[type=checkbox][name='lead_response_hours[${d}][counted]']`);
            ['tue','wed','thu','fri','sat','sun'].forEach(d => {
                q(d, 'start').value = q('mon', 'start').value;
                q(d, 'end').value = q('mon', 'end').value;
                box(d).checked = box('mon').checked;
            });
        }
     }" class="space-y-2">
    <input type="hidden" name="lead_response_present" value="1">
    <div class="flex items-center justify-between">
        <span class="text-xs font-medium" style="color:var(--text-secondary);">Hours that count towards the response time</span>
        <button type="button" @click="copyMonday()" class="text-xs underline" style="color:var(--brand-icon, #0ea5e9);">Copy Monday to all days</button>
    </div>
    <div class="space-y-1">
        @foreach($dayLabels as $key => $label)
            @php $d = $hours[$key]; @endphp
            <div class="flex items-center gap-3 text-sm" style="color:var(--text-primary);">
                <span class="w-24">{{ $label }}</span>
                <input type="hidden" name="lead_response_hours[{{ $key }}][counted]" value="0">
                <label class="flex items-center gap-1 text-xs w-28" style="color:var(--text-secondary);">
                    <input type="checkbox" name="lead_response_hours[{{ $key }}][counted]" value="1" {{ $d['counted'] ? 'checked' : '' }}> Counted
                </label>
                <input type="time" name="lead_response_hours[{{ $key }}][start]" value="{{ $d['start'] }}" class="px-2 py-1 rounded-md text-sm" style="background:var(--surface); color:var(--text-primary); border:1px solid var(--border);">
                <span class="text-xs" style="color:var(--text-muted);">to</span>
                <input type="time" name="lead_response_hours[{{ $key }}][end]" value="{{ $d['end'] }}" class="px-2 py-1 rounded-md text-sm" style="background:var(--surface); color:var(--text-primary); border:1px solid var(--border);">
            </div>
        @endforeach
    </div>
    @error('lead_response_hours')<p class="text-xs" style="color:var(--ds-crimson);">{{ $message }}</p>@enderror
    @foreach($dayLabels as $key => $label)
        @error("lead_response_hours.$key")<p class="text-xs" style="color:var(--ds-crimson);">{{ $message }}</p>@enderror
    @endforeach
    <p class="text-[11px]" style="color:var(--text-muted);">Times are in your agency's timezone. A lead that arrives outside these hours starts counting at the next start time. Untick a day to leave it out entirely.</p>
</div>
