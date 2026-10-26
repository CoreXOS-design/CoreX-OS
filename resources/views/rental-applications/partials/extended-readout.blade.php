{{--
    Fuller application (26 Oct 2026) - read-only readout of the four blocks for the agent's review screen (the scalar fields are rows
    in the review's own summary list). Only what the applicant actually filled in is shown (the screen-space rule of that list).
    @param \App\Models\RentalApplication $application
--}}
@php
    $roCo = (array) ($application->co_applicant ?? []);
    $roOcc = (array) ($application->co_occupants ?? []);
    $roPets = (array) ($application->pets ?? []);
    $roInc = (array) (($application->affordability ?? [])['income'] ?? []);
    $roExp = (array) (($application->affordability ?? [])['expenses'] ?? []);
    $roMoney = fn ($v) => 'R ' . number_format((float) $v, 2, '.', ',');
@endphp
@if($roCo !== [] || $roOcc !== [] || $roPets !== [] || $roInc !== [] || $roExp !== [])
<div class="mt-3 pt-2 space-y-3 text-xs" style="border-top: 1px solid var(--border);" data-qa="review-extended">
    @if($roCo !== [])
    <div data-qa="review-co-applicant">
        <p class="font-semibold mb-1" style="color: var(--text-secondary);">Co-applicant / spouse</p>
        <dl class="grid grid-cols-2 gap-x-4 gap-y-1">
            @foreach(\App\Models\RentalApplication::CO_APPLICANT_FIELDS as $k => [$lbl, $kind])
                @if(isset($roCo[$k]))
                    <dt style="color: var(--text-muted);">{{ $lbl }}</dt><dd>{{ $kind === 'money' ? $roMoney($roCo[$k]) : ($kind === 'yesno' ? ucfirst($roCo[$k]) : $roCo[$k]) }}</dd>
                @endif
            @endforeach
        </dl>
    </div>
    @endif
    @if($roOcc !== [])
    <div data-qa="review-co-occupants">
        <p class="font-semibold mb-1" style="color: var(--text-secondary);">Other people who will live in the property</p>
        <table class="w-full"><thead><tr style="color: var(--text-muted);"><th class="text-left font-normal">Name</th><th class="text-left font-normal">Relationship</th><th class="text-left font-normal">Age</th></tr></thead>
            <tbody>@foreach($roOcc as $r)<tr><td>{{ $r['name'] ?? '' }}</td><td>{{ $r['relationship'] ?? '' }}</td><td>{{ $r['age'] ?? '' }}</td></tr>@endforeach</tbody></table>
    </div>
    @endif
    @if($roPets !== [])
    <div data-qa="review-pets">
        <p class="font-semibold mb-1" style="color: var(--text-secondary);">Pets</p>
        <table class="w-full"><thead><tr style="color: var(--text-muted);"><th class="text-left font-normal">Type</th><th class="text-left font-normal">Breed</th><th class="text-left font-normal">Size</th></tr></thead>
            <tbody>@foreach($roPets as $r)<tr><td>{{ $r['type'] ?? '' }}</td><td>{{ $r['breed'] ?? '' }}</td><td>{{ $r['size'] ?? '' }}</td></tr>@endforeach</tbody></table>
    </div>
    @endif
    @if($roInc !== [] || $roExp !== [])
    <div data-qa="review-affordability">
        <p class="font-semibold mb-1" style="color: var(--text-secondary);">Tenant's own monthly schedule (as stated, not yet checked against statements)</p>
        <div class="grid grid-cols-2 gap-x-4">
            <div>@foreach($roInc as $l)<p class="flex justify-between"><span>{{ $l['label'] }}</span><span>{{ $roMoney($l['amount']) }}</span></p>@endforeach
                <p class="flex justify-between font-semibold pt-1" style="border-top: 1px solid var(--border);"><span>Income</span><span>{{ $roMoney($application->affordabilityTotal('income')) }}</span></p></div>
            <div>@foreach($roExp as $l)<p class="flex justify-between"><span>{{ $l['label'] }}</span><span>{{ $roMoney($l['amount']) }}</span></p>@endforeach
                <p class="flex justify-between font-semibold pt-1" style="border-top: 1px solid var(--border);"><span>Expenses</span><span>{{ $roMoney($application->affordabilityTotal('expenses')) }}</span></p></div>
        </div>
        <p class="flex justify-between font-bold mt-1"><span>Left over each month</span><span>{{ $roMoney($application->affordabilityTotal('income') - $application->affordabilityTotal('expenses')) }}</span></p>
    </div>
    @endif
</div>
@endif
