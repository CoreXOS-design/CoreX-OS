{{--
    Fuller application (26 Oct 2026) - the agency's own terms, read-only: Tenant information summary, Documents to accompany,
    Amounts payable, Deposit banking details. Built by App\Services\Rentals\ApplicationTerms (agency settings only - an agency that
    has set nothing sees nothing here). Used by the applicant's online form and the agent's review screen; the printed PDF prints the
    same arrays in its own markup (corex/rental-applications/pdf.blade.php).

    @param \App\Models\RentalApplication $application
    @param string $mode 'public' | 'agent'
--}}
@php
    $mode = $mode ?? 'public';
    $property = $application->property;
    $applicants = 1 + (filled(($application->co_applicant ?? [])['full_name'] ?? null) ? 1 : 0);
    $terms = \App\Services\Rentals\ApplicationTerms::for(
        $application->agency_id,
        $property && $property->rental_amount ? (float) $property->rental_amount : null,
        $applicants,
        $property?->buildDisplayAddress() ?: $application->property_address_override,
        $application->full_name,
        null,
    );
    $card = $mode === 'public'
        ? 'rounded-xl border border-slate-200 bg-white p-4 shadow-sm'
        : 'rounded-lg p-3';
    $cardStyle = $mode === 'public' ? '' : 'border: 1px solid var(--border); background: var(--surface);';
    $head = $mode === 'public' ? 'font-semibold text-slate-700 text-sm mb-2' : 'text-xs font-bold uppercase tracking-widest mb-2';
    $headStyle = $mode === 'public' ? '' : 'color: var(--text-muted);';
    $dt = $mode === 'public' ? 'text-slate-500' : '';
    $dtStyle = $mode === 'public' ? '' : 'color: var(--text-muted);';
@endphp
@if($terms['summary'] !== [] || $terms['documents'] !== [] || $terms['amounts'] !== [] || $terms['bank'] !== null)
<div class="space-y-3 {{ $mode === 'public' ? 'mb-4' : 'mt-3' }}" data-qa="application-terms">
    @if($terms['summary'] !== [])
    <div class="{{ $card }}" style="{{ $cardStyle }}" data-qa="terms-summary">
        <h2 class="{{ $head }}" style="{{ $headStyle }}">{{ $terms['summary_heading'] !== '' ? $terms['summary_heading'] : 'Tenant information summary' }}</h2>
        <ul class="list-disc list-inside text-xs space-y-1">
            @foreach($terms['summary'] as $line)<li>{{ $line }}</li>@endforeach
        </ul>
    </div>
    @endif
    @if($terms['documents'] !== [])
    <div class="{{ $card }}" style="{{ $cardStyle }}" data-qa="terms-documents">
        <h2 class="{{ $head }}" style="{{ $headStyle }}">Documents to accompany this application</h2>
        <ul class="list-disc list-inside text-xs space-y-1">
            @foreach($terms['documents'] as $line)<li>{{ $line }}</li>@endforeach
        </ul>
    </div>
    @endif
    @if($terms['amounts'] !== [])
    <div class="{{ $card }}" style="{{ $cardStyle }}" data-qa="terms-amounts">
        <h2 class="{{ $head }}" style="{{ $headStyle }}">Amounts payable</h2>
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-xs">
            @foreach($terms['amounts'] as $row)
                <dt class="{{ $dt }}" style="{{ $dtStyle }}">{{ $row['label'] }}</dt><dd class="font-medium">{{ $row['value'] }}</dd>
            @endforeach
        </dl>
    </div>
    @endif
    @if($terms['bank'] !== null)
    <div class="{{ $card }}" style="{{ $cardStyle }}" data-qa="terms-bank">
        <h2 class="{{ $head }}" style="{{ $headStyle }}">Deposit banking details</h2>
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-xs">
            @foreach($terms['bank']['rows'] as $row)
                <dt class="{{ $dt }}" style="{{ $dtStyle }}">{{ $row['label'] }}</dt><dd class="font-medium">{{ $row['value'] }}</dd>
            @endforeach
            @if($terms['bank']['reference'] !== '')
                <dt class="{{ $dt }}" style="{{ $dtStyle }}">Payment reference</dt><dd class="font-medium">{{ $terms['bank']['reference'] }}</dd>
            @endif
        </dl>
        @if($terms['bank']['note'] !== '')
            <p class="text-xs mt-2">{{ $terms['bank']['note'] }}</p>
        @endif
    </div>
    @endif
</div>
@endif
