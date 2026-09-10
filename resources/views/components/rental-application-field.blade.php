@props(['name', 'label', 'value' => null, 'type' => 'text', 'hint' => null, 'inputmode' => null, 'min' => null, 'max' => null])
{{--
    AT-392, Johan 2026-09-07 — "losing a tenant's typed answers is
    unacceptable." A validation failure on submit() redirects back with
    old() flashed to the session (Laravel's own default behaviour for a
    failed validate() on a non-JSON request) — but this component was
    reading straight from $value (the DB row) and never consulted old(),
    so a rejected resubmission silently reverted every field to whatever
    was last saved, discarding exactly what the applicant just typed.

    2026-09-08 — optional $hint added: Johan's own example of the
    affordability-figure defect was an applicant typing 10,000 (take-home)
    when their payslip showed 18,000 gross — the label alone ("Gross
    monthly income") may not be enough for someone unfamiliar with the
    word. A short hint directly under the field, not just a stricter
    label, is the more robust fix for exactly the error he described.
--}}
<div>
    <label class="block text-xs text-slate-500 mb-1">{{ $label }}</label>
    <input type="{{ $type }}" name="{{ $name }}" value="{{ old($name, $value) }}"
           @if($inputmode) inputmode="{{ $inputmode }}" @endif
           @if($min !== null) min="{{ $min }}" @endif
           @if($max !== null) max="{{ $max }}" @endif
           class="w-full rounded-lg border px-3 py-2 text-sm {{ $errors->has($name) ? 'border-red-400' : 'border-slate-300' }}">
    @if($hint)
        <p class="text-[11px] text-slate-400 mt-1">{{ $hint }}</p>
    @endif
    @error($name)
        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
    @enderror
</div>
