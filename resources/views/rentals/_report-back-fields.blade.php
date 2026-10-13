{{--
    .ai/specs/rentals-faults-work-orders.md section 22.13.2 - "What was found" and "Cause", asked on the contractor link and the crew link/page when the
    job came from a fault. Inline styles only: this sits inside two public pages with different style sheets. Receives $workOrder (or null).
--}}
@php
    $rbSvc = app(\App\Services\Rentals\RentalWhoPaysService::class);
    $rbShow = isset($workOrder) && $workOrder && $rbSvc->isFaultJob($workOrder);
    $rbOptions = $rbShow ? $rbSvc->causeOptions($workOrder) : collect();
    $rbField = 'width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid rgba(0,0,0,.2);border-radius:10px;font-size:15px;font-family:inherit;background:#fff;';
    $rbLabel = 'display:block;font-size:13px;font-weight:600;margin:10px 0 4px;';
@endphp
@if($rbShow)
    <div data-report-back>
        <label for="rb-found" style="{{ $rbLabel }}">What did you find?</label>
        <textarea id="rb-found" name="found_text" rows="3" required minlength="3" maxlength="2000" style="{{ $rbField }}">{{ old('found_text', $workOrder->found_text) }}</textarea>
        <label for="rb-cause" style="{{ $rbLabel }}">What caused it?</label>
        <select id="rb-cause" name="cause_id" style="{{ $rbField }}">
            @foreach($rbOptions as $o)
                <option value="{{ $o->id }}" @selected((int) old('cause_id', $workOrder->cause_id) === $o->id)>{{ $o->label }}</option>
            @endforeach
            <option value="" @selected($rbOptions->isEmpty() || (old('cause_id', $workOrder->cause_id) !== null && (string) old('cause_id', $workOrder->cause_id) === ''))>Something else (describe it)</option>
        </select>
        <label for="rb-cause-note" style="{{ $rbLabel }}">Anything to add about the cause (needed for &ldquo;Something else&rdquo;)</label>
        <input id="rb-cause-note" type="text" name="cause_note" maxlength="500" value="{{ old('cause_note', $workOrder->cause_note) }}" style="{{ $rbField }}">
    </div>
@endif
