@props(['name', 'label', 'value' => null, 'type' => 'text'])
{{--
    AT-392, Johan 2026-09-07 — "losing a tenant's typed answers is
    unacceptable." A validation failure on submit() redirects back with
    old() flashed to the session (Laravel's own default behaviour for a
    failed validate() on a non-JSON request) — but this component was
    reading straight from $value (the DB row) and never consulted old(),
    so a rejected resubmission silently reverted every field to whatever
    was last saved, discarding exactly what the applicant just typed.
--}}
<div>
    <label class="block text-xs text-slate-500 mb-1">{{ $label }}</label>
    <input type="{{ $type }}" name="{{ $name }}" value="{{ old($name, $value) }}"
           class="w-full rounded-lg border px-3 py-2 text-sm {{ $errors->has($name) ? 'border-red-400' : 'border-slate-300' }}">
    @error($name)
        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
    @enderror
</div>
