@component('mail::message')
@if($isCutout)
# AI has been paused for {{ $agencyName }}

**{{ $agencyName }}** spent **R {{ number_format($spendZar, 2) }}** on AI in the last {{ $windowHours }} hours,
reaching the cut-out level of **R {{ number_format($thresholdZar, 2) }}**.

Every AI feature for {{ $agencyName }} is now switched off. Their users see:
"AI is paused for your agency — please contact CoreX support." Other agencies are not affected.

AI stays off until someone turns it back on from the AI usage page.
@else
# AI spend alert — {{ $agencyName }}

**{{ $agencyName }}** has spent **R {{ number_format($spendZar, 2) }}** on AI in the last {{ $windowHours }} hours,
past the alert level of **R {{ number_format($thresholdZar, 2) }}**.

Nothing has been switched off yet. This is the only alert you will get for this agency in the next {{ $windowHours }} hours.
@endif

@if(!empty($topSources))
**Where the spend came from:**

@foreach($topSources as $source => $cost)
- {{ ucwords(str_replace('_', ' ', $source)) }}: R {{ number_format($cost, 2) }}
@endforeach
@endif

@component('mail::button', ['url' => $usageUrl])
Open AI usage
@endcomponent

Thanks,
{{ config('app.name') }}
@endcomponent
