@component('mail::message')
# Outbound mail interception is still ON

Environment: **{{ strtoupper($environment) }}** ({{ $host }})

This environment normally sends real mail, but a CoreX super admin turned outbound mail
interception ON here, and it has not been turned back off.

- **On for:** {{ $sinceLabel }}
- **Messages held:** {{ $heldCount }}
@if($turnedOnBy)
- **Turned on by:** {{ $turnedOnBy }}
@endif
@if($reason)
- **Reason given:** "{{ $reason }}"
@endif

Nothing has been auto-resumed — it will not be, on purpose, to avoid re-flooding a host
still blocking us. If the reason above no longer applies, turn it back off from
Email Setup.

Thanks,
{{ config('app.name') }}
@endcomponent
