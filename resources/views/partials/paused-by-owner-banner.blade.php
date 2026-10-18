{{-- .ai/specs/rentals-faults-work-orders.md section 22.7 - the PAUSED BY OWNER banner on the work order / job card pages. Expects $pauseSubject (RentalWorkOrder | RentalJobCard). Resume lives on the fault page. --}}
@php
    $pbSvc = app(\App\Services\Rentals\RentalOwnerHoldService::class);
    $pbHold = $pauseSubject ? $pbSvc->activeFor($pauseSubject) : null;
    [$pbFault] = $pauseSubject ? $pbSvc->jobOf($pauseSubject) : [null];
@endphp
@if($pbHold)
    <div class="rounded-md px-4 py-3 text-sm space-y-1" style="background: color-mix(in srgb, var(--ds-crimson) 10%, var(--surface)); border: 2px solid var(--ds-crimson);" data-paused-by-owner>
        <div class="font-semibold" style="color: var(--ds-crimson);">PAUSED BY OWNER</div>
        <div data-paused-line>{{ $pbSvc->stopLine($pbHold) }}</div>
        <div class="text-xs" style="color: var(--text-muted);">Nothing can be sent, approved or started while it is on hold.
            @if($pbFault) @feature('rental-faults')<a class="underline" href="{{ route('corex.rental-fault-reports.show', $pbFault->id) }}">Open the fault to resume it</a>.@endfeature @endif
        </div>
    </div>
@endif
