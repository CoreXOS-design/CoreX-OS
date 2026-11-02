{{--
    The marital-status block, ONE copy for the Main applicant AND the Co-applicant (AT-517): the status select (the agency's own option
    list), the spouse name and ID that appear on the same condition (a status the agency marked "implies a spouse"), the agency's
    wording / hidden / compulsory settings and the same inline "required" behaviour. Moved here unchanged from public/show.blade.php.

    Ruling 1, AT-392 round 5, 2026-09-13 - marital_status is a real select (agency-configurable option list,
    RentalApplicationQualifyingSetting::maritalStatusOptionsFor()) so the spouse-fields condition can actually fire. A stored value that
    predates this select (or does not match the agency's current option list) is SHOWN, not blanked - existing values "must not be
    destroyed". The outer x-data scope stays UNCONDITIONAL (the nested spouse x-show depends on it) even when marital_status itself is
    hidden - only the select's own div, and each spouse field independently, are gated on their own shown state.

    @param array  $fieldConfig, $requiredFieldKeys, $maritalStatusOptions
    @param string $prefix  '' for the Main applicant, 'co_applicant' for the Co-applicant (inputs are named co_applicant[marital_status] ...)
    @param array  $values  marital_status / spouse_name / spouse_id as stored
--}}
@php
    $prefix = $prefix ?? '';
    $values = $values ?? [];
    $mName = fn (string $k): string => $prefix === '' ? $k : $prefix . '[' . $k . ']';
    $mKey = fn (string $k): string => $prefix === '' ? $k : $prefix . '.' . $k;
    $maritalCurrent = old($mKey('marital_status'), $values['marital_status'] ?? null);
@endphp
<div x-data="{
        maritalStatus: {{ Js::from($maritalCurrent) }},
        spouseImplyingLabels: {{ Js::from(collect($maritalStatusOptions)->where('implies_spouse', true)->pluck('label')->values()) }}
     }" class="contents" @if($prefix !== '') data-qa="co-marital-block" @endif>
    @if($fieldConfig['marital_status']['shown'])
    <div style="order: {{ $fieldConfig['marital_status']['order'] }}">
        <label class="block text-xs text-slate-500 mb-1">{{ $fieldConfig['marital_status']['label'] }} @if(in_array('marital_status', $requiredFieldKeys, true)) *@endif</label>
        <select name="{{ $mName('marital_status') }}" x-model="maritalStatus"
                @if(in_array('marital_status', $requiredFieldKeys, true)) required aria-required="true" @endif
                class="w-full rounded-lg border px-3 py-2 text-sm {{ $errors->has($mKey('marital_status')) ? 'border-red-400' : 'border-slate-300' }}">
            <option value="">— Select —</option>
            @foreach($maritalStatusOptions as $option)
                <option value="{{ $option['label'] }}">{{ $option['label'] }}</option>
            @endforeach
            @php $knownLabels = collect($maritalStatusOptions)->pluck('label'); @endphp
            @if($values['marital_status'] ?? null)
                @if(! $knownLabels->contains($values['marital_status']))
                    <option value="{{ $values['marital_status'] }}" selected>{{ $values['marital_status'] }} (previously recorded)</option>
                @endif
            @endif
        </select>
        @if($fieldConfig['marital_status']['help_text'])
            <p class="text-[11px] text-slate-400 mt-1">{{ $fieldConfig['marital_status']['help_text'] }}</p>
        @endif
        @error($mKey('marital_status')) <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>
    @endif
    {{-- x-show (not x-if): never destroys data the applicant already typed just because they are mid-selecting. --}}
    <div x-show="spouseImplyingLabels.includes(maritalStatus)" x-cloak class="contents">
        @if($fieldConfig['spouse_name']['shown'])
        <x-rental-application-field :name="$mName('spouse_name')" :label="$fieldConfig['spouse_name']['label']" :hint="$fieldConfig['spouse_name']['help_text']" :order="$fieldConfig['spouse_name']['order']" :value="$values['spouse_name'] ?? null"
            required-expr="spouseImplyingLabels.includes(maritalStatus) && {{ in_array('spouse_name', $requiredFieldKeys, true) ? 'true' : 'false' }}" />
        @endif
        @if($fieldConfig['spouse_id']['shown'])
        <x-rental-application-field :name="$mName('spouse_id')" :label="$fieldConfig['spouse_id']['label']" :hint="$fieldConfig['spouse_id']['help_text']" :order="$fieldConfig['spouse_id']['order']" :value="$values['spouse_id'] ?? null"
            required-expr="spouseImplyingLabels.includes(maritalStatus) && {{ in_array('spouse_id', $requiredFieldKeys, true) ? 'true' : 'false' }}" />
        @endif
    </div>
</div>
