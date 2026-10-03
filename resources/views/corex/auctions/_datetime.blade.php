{{-- Calendar + time pickers that submit one combined value under $name (Y-m-d H:i). --}}
@php
    $dtValue = old($name, $value ?? null);
    $dtValue = $dtValue ? \Illuminate\Support\Carbon::parse($dtValue) : null;
@endphp
<div x-data="{ d: '{{ $dtValue?->format('Y-m-d') }}', t: '{{ $dtValue?->format('H:i') }}',
               get v() { return this.d ? this.d + ' ' + (this.t || '00:00') : ''; } }"
     class="flex gap-2">
    <input type="hidden" name="{{ $name }}" :value="v">
    <input type="date" x-model="d" @if(!empty($required)) required @endif class="prop-input flex-1" aria-label="{{ $label }} date">
    <input type="time" x-model="t" step="300" @if(!empty($required)) required @endif class="prop-input" style="width:8.5rem;" aria-label="{{ $label }} time">
</div>
