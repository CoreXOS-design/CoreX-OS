{{-- .ai/specs/rentals-faults-work-orders.md section 24.4 - THE date-and-time pattern for the work order and fault screens: a date field and a separate time dropdown (07:00 to 18:00 in
     30-minute steps, plus "Other time" which reveals a typed HH:MM), side by side. No browser date-time popup. It still posts ONE field, named $name, as "YYYY-MM-DDTHH:MM" (the same shape
     the native control posted), read by the server in the AGENCY timezone (App\Support\AppDate::parse) - so nothing server-side changes.
     Props: name; value ("Y-m-d\TH:i" or null); required. Extra attributes land on the wrapper. --}}
@props(['name', 'value' => null, 'required' => true])
@php
    $dtSlots = [];
    for ($h = 7; $h <= 18; $h++) {
        foreach (['00', '30'] as $m) {
            if ($h === 18 && $m === '30') { continue; }
            $dtSlots[] = sprintf('%02d:%s', $h, $m);
        }
    }
    $dtValue = (string) $value;
    $dtDate = strlen($dtValue) >= 10 ? substr($dtValue, 0, 10) : '';
    $dtTime = strlen($dtValue) >= 16 ? substr($dtValue, 11, 5) : '';
    $dtKnown = $dtTime !== '' && in_array($dtTime, $dtSlots, true);
@endphp
<div {{ $attributes->merge(['class' => 'cx-dt']) }} data-date-time-field
     x-data="{ d: @js($dtDate), t: @js($dtKnown ? $dtTime : ($dtTime !== '' ? 'other' : '')), other: @js($dtKnown ? '' : $dtTime),
               get tm() { return this.t === 'other' ? this.other.trim() : this.t; },
               get v() { return (this.d && /^([01]\d|2[0-3]):[0-5]\d$/.test(this.tm)) ? this.d + 'T' + this.tm : ''; } }">
    <input type="hidden" name="{{ $name }}" x-bind:value="v" value="{{ $dtValue }}" data-dt-value>
    <input type="date" x-model="d" class="cx-dt-ctl cx-dt-date" aria-label="Date" @if($required) required @endif data-dt-date>
    <select x-model="t" class="cx-dt-ctl cx-dt-time" aria-label="Time" @if($required) required @endif data-dt-time>
        <option value="">Time</option>
        @foreach($dtSlots as $slot)<option value="{{ $slot }}">{{ $slot }}</option>@endforeach
        <option value="other">Other time</option>
    </select>
    <input type="text" x-show="t === 'other'" x-cloak x-model="other" inputmode="numeric" maxlength="5" placeholder="HH:MM" pattern="([01][0-9]|2[0-3]):[0-5][0-9]"
           x-bind:required="t === 'other'" class="cx-dt-ctl cx-dt-other" aria-label="Time (HH:MM)" data-dt-other>
</div>
@once
<style>
    .cx-dt { display: flex; flex-wrap: nowrap; gap: 8px; align-items: center; }
    @media (max-width: 480px) { .cx-dt { flex-wrap: wrap; } }
    .cx-dt-ctl { height: 38px; box-sizing: border-box; padding: 0 10px; font-size: 14px; border-radius: 6px; border: 1px solid var(--border); background: var(--surface, #fff); color: var(--text-primary); }
    .cx-dt-date { flex: 1 1 100px; min-width: 110px; }
    .cx-dt-time { flex: 0 0 112px; }
    .cx-dt-other { flex: 0 0 76px; }
    .cx-dt-other[x-cloak] { display: none; }
</style>
@endonce
