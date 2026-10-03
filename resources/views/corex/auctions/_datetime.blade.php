{{-- Calendar popup + time picker (flatpickr). Submits one value under $name as Y-m-d H:i. --}}
@php
    $dtValue = old($name, $value ?? null);
    $dtValue = $dtValue ? \Illuminate\Support\Carbon::parse($dtValue)->format('Y-m-d H:i') : '';
@endphp
@once
    @push('head')
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.css">
        <script src="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.js"></script>
    @endpush
@endonce
<input type="text" name="{{ $name }}" value="{{ $dtValue }}" @if(!empty($required)) required @endif
       placeholder="Select date &amp; time" autocomplete="off" aria-label="{{ $label }}"
       class="prop-input w-full js-auction-dt" style="cursor:pointer;">
@once
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                if (!window.flatpickr) return;
                document.querySelectorAll('.js-auction-dt').forEach(function (el) {
                    flatpickr(el, {
                        enableTime: true, time_24hr: true, minuteIncrement: 5,
                        dateFormat: 'Y-m-d H:i', altInput: true, altFormat: 'D, j M Y \\a\\t H:i',
                        allowInput: false, disableMobile: true,
                        onReady: function (_, __, fp) { fp.altInput.classList.add('prop-input', 'w-full'); fp.altInput.required = el.required; }
                    });
                });
            });
        </script>
    @endpush
@endonce
