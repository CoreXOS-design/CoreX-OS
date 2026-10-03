{{-- Date + time pickers styled like the property page (native, theme-aware via color-scheme).
     Submits one combined value under $name as Y-m-d H:i. --}}
@php
    $dtValue = old($name, $value ?? null);
    $dtValue = $dtValue ? \Illuminate\Support\Carbon::parse($dtValue) : null;
@endphp
<div x-data="{ d: '{{ $dtValue?->format('Y-m-d') }}', t: '{{ $dtValue?->format('H:i') }}',
               get v() { return this.d ? this.d + ' ' + (this.t || '00:00') : ''; } }"
     class="flex gap-2">
    <input type="hidden" name="{{ $name }}" :value="v">
    <input type="date" x-model="d" @if(!empty($required)) required @endif
           class="prop-input" style="color-scheme: light dark; flex:1 1 0; min-width:0; max-width:none; cursor:pointer;"
           aria-label="{{ $label }} date" @click="$el.showPicker && $el.showPicker()">
    <input type="time" x-model="t" step="300" @if(!empty($required)) required @endif
           class="prop-input" style="color-scheme: light dark; width:7.5rem; max-width:none; cursor:pointer;"
           aria-label="{{ $label }} time" @click="$el.showPicker && $el.showPicker()">
</div>
