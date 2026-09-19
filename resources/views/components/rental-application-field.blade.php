@props(['name', 'label', 'value' => null, 'type' => 'text', 'hint' => null, 'inputmode' => null, 'min' => null, 'max' => null, 'required' => false, 'requiredExpr' => null, 'order' => null])
{{--
    .ai/specs/rental-application-field-config.md — $order (nullable int)
    sets a CSS `order` on this component's own root div. CSS order works
    in both the flex AND grid containers this component is used inside,
    and applies WITHIN whatever container the field currently sits in —
    deliberately not a DOM move, so it can never disturb a parent
    conditional group's own x-show/x-data wiring (spouse/landlord/
    employer). Omitted (null) = no inline style at all, i.e. today's
    unchanged source-order behaviour.
--}}
{{--
    Submission hard floor, AT-392 round 5, 2026-09-13 — $required is a
    static courtesy attribute for a field that's ALWAYS relevant when
    ticked compulsory. $requiredExpr is an Alpine JS boolean expression
    string for a field inside a conditional group (employer/landlord/
    spouse) — a static `required` there would wrongly block, say, a
    self-employed applicant from submitting with Employer name blank the
    moment the agency ticks it, even though the server would never have
    asked for it. Either way this is a courtesy only; submit() enforces
    the real gate from the same agency setting regardless of what the
    browser did or didn't block.
--}}
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
<div @if($order !== null) style="order: {{ (int) $order }}" @endif>
    <label class="block text-xs text-slate-500 mb-1">{{ $label }}@if($requiredExpr)<span x-show="{{ $requiredExpr }}" x-cloak> *</span>@elseif($required) *@endif</label>
    <input type="{{ $type }}" name="{{ $name }}" value="{{ old($name, $value) }}"
           @if($inputmode) inputmode="{{ $inputmode }}" @endif
           @if($min !== null) min="{{ $min }}" @endif
           @if($max !== null) max="{{ $max }}" @endif
           @if($requiredExpr) x-bind:required="{{ $requiredExpr }}" x-bind:aria-required="{{ $requiredExpr }}"
           @elseif($required) required aria-required="true" @endif
           class="w-full rounded-lg border px-3 py-2 text-sm {{ $errors->has($name) ? 'border-red-400' : 'border-slate-300' }}">
    @if($hint)
        <p class="text-[11px] text-slate-400 mt-1">{{ $hint }}</p>
    @endif
    @error($name)
        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
    @enderror
</div>
