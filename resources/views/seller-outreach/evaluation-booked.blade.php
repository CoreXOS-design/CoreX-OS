{{-- Road to Sold 23.23 drop 4: the evaluation was saved by the calendar's own form inside the pitch popup. Tells the popup (Done notice, list refresh). --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full max-w-2xl mx-auto py-8 space-y-4" data-qa="evaluation-booked">
    <div class="rounded-md p-6 text-center" style="background: color-mix(in srgb, var(--ds-green, #10b981) 10%, var(--surface)); border: 1px solid color-mix(in srgb, var(--ds-green, #10b981) 40%, var(--border));">
        <h1 class="text-xl font-semibold" style="color: var(--text-primary);">✓ Evaluation booked</h1>
        <p class="text-base mt-2" style="color: var(--text-primary);"><strong>{{ $whenText }}</strong> · {{ $address }}</p>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">It is in your calendar and on the seller's file, and the property moves to Evaluation booked.</p>
        @if($logged)
            <p class="text-sm mt-3" style="color: var(--text-secondary);">Your call is logged: <strong>{{ $logged->outcome_label }}</strong>.</p>
        @endif
        @if($problem)
            <p class="text-sm mt-3" data-qa="booked-problem" style="color: var(--ds-amber, #b45309);">The evaluation is booked, but the call could not be logged: {{ $problem }}</p>
        @endif
    </div>
    <p class="text-xs text-center" style="color: var(--text-muted);">Press Done to go back to your list.</p>
</div>
<script>
window.addEventListener('load', function () {
    if (window.corexPitchResult) { window.corexPitchResult('booked', @js($address), @js($whenText)); }
});
</script>
@include('layouts.partials._pitch-result')
@endsection
