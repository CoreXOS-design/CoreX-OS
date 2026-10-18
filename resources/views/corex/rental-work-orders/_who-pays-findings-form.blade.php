{{-- .ai/specs/rentals-faults-work-orders.md section 22.13.3 - the agent's "What was found" + "Cause" form (enter, or correct what the contractor/crew said). Needs $workOrder, $wp, $wpOptions. --}}
<form method="POST" action="{{ route('corex.rental-work-orders.who-pays.findings', $workOrder) }}" class="space-y-2 max-w-xl">
    @csrf
    <div>
        <label class="text-xs font-medium" for="wp-found">What was found (required)</label>
        <textarea id="wp-found" name="found_text" rows="2" required maxlength="2000" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('found_text', $workOrder->found_text) }}</textarea>
    </div>
    <div>
        <label class="text-xs font-medium" for="wp-cause">Cause (required)</label>
        <select id="wp-cause" name="cause_id" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            @foreach($wpOptions as $o)
                <option value="{{ $o->id }}" @selected((int) old('cause_id', $workOrder->cause_id) === $o->id)>{{ $o->label }}</option>
            @endforeach
            <option value="" @selected($workOrder->cause_id === null && ($workOrder->cause_note || old('cause_note')))>Something else (describe it)</option>
        </select>
    </div>
    <div>
        <label class="text-xs font-medium" for="wp-cause-note">Cause in words (needed for &ldquo;Something else&rdquo;; optional extra otherwise)</label>
        <input id="wp-cause-note" type="text" name="cause_note" maxlength="500" value="{{ old('cause_note', $workOrder->cause_note) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
    </div>
    <button type="submit" class="corex-btn-primary text-xs" style="display:inline-flex; align-items:center;">Save what was found</button>
</form>
