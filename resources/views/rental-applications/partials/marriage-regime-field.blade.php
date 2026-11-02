{{--
    "How married" (marriage regime) - ONE copy for the Main applicant AND the Co-applicant (AT-517). Moved here unchanged from
    partials/extended-sections: the same list, the agency's wording / hint / order, and a stored value that is not on the list is shown
    ("previously recorded"), never blanked.

    @param array  $fieldConfig
    @param string $prefix  '' (Main applicant) or 'co_applicant'
    @param mixed  $value   the stored value
--}}
@php
    $prefix = $prefix ?? '';
    $rName = $prefix === '' ? 'marriage_regime' : $prefix . '[marriage_regime]';
    $rKey = $prefix === '' ? 'marriage_regime' : $prefix . '.marriage_regime';
    $regimeValue = old($rKey, $value ?? null);
    $regimeOptions = \App\Models\RentalApplication::MARRIAGE_REGIMES;
@endphp
<div style="@if(($fieldConfig['marriage_regime']['order'] ?? null) !== null)order: {{ (int) $fieldConfig['marriage_regime']['order'] }}@endif" @if($prefix !== '') data-qa="co-marriage-regime" @endif>
    <label class="block text-xs text-slate-500 mb-1">{{ $fieldConfig['marriage_regime']['label'] ?? 'How married' }}</label>
    <select name="{{ $rName }}" class="w-full rounded-lg border px-3 py-2 text-sm border-slate-300">
        <option value="">— Select —</option>
        @foreach($regimeOptions as $opt)
            <option value="{{ $opt }}" @selected($regimeValue === $opt)>{{ $opt }}</option>
        @endforeach
        @if($regimeValue && ! in_array($regimeValue, $regimeOptions, true))
            <option value="{{ $regimeValue }}" selected>{{ $regimeValue }} (previously recorded)</option>
        @endif
    </select>
    <p class="text-[11px] text-slate-400 mt-1">{{ $fieldConfig['marriage_regime']['help_text'] ?? 'Only if you are married.' }}</p>
    @error($rKey) <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
</div>
