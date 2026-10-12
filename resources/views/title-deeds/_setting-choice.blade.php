{{--
    One setting as a sentence with its own explicit choice (Required / Optional / Not used, or Yes / No) - never a bare tick.
    Params: $key (form field), $label (the question), $help (one plain line saying what each answer does), $options (value => label),
            $model (the Alpine variable that holds the answer), $show (Alpine expression: false hides the row AND stops it being posted).
--}}
@php($show = $show ?? 'true')
<div x-show="{{ $show }}" x-cloak class="py-4 flex flex-col md:flex-row md:items-start md:justify-between gap-3" style="border-top: 1px solid var(--border);" data-setting="{{ $key }}">
    <div class="md:max-w-md">
        <div class="text-sm font-semibold" style="color: var(--text-primary);" id="lbl-{{ $key }}">{{ $label }}</div>
        @if(! empty($help))<div class="text-xs mt-0.5" style="color: var(--text-secondary);">{{ $help }}</div>@endif
        @error($key)<div class="text-xs mt-1 font-medium" style="color: var(--ds-crimson);" role="alert">{{ $message }}</div>@enderror
    </div>
    <div class="inline-flex flex-wrap rounded-md overflow-hidden flex-shrink-0" style="border: 1px solid var(--border);" role="radiogroup" aria-labelledby="lbl-{{ $key }}">
        @foreach($options as $value => $text)
        <label class="cursor-pointer">
            <input type="radio" name="{{ $key }}" value="{{ $value }}" x-model="{{ $model }}" :disabled="!({{ $show }})" class="sr-only peer">
            <span class="block px-4 py-2 text-sm font-medium peer-checked:text-white peer-focus-visible:ring-2" style="color: var(--text-primary);"
                  :style="{{ $model }} === '{{ $value }}' ? 'background: var(--brand-icon); color: #fff;' : 'background: var(--surface-2);'">{{ $text }}</span>
        </label>
        @endforeach
    </div>
</div>
