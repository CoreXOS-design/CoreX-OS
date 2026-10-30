{{-- AT-516 - "Changes": what an agent corrected on the applicant's details since they submitted. Compact: one line per field
     (label, old -> new, who, when). Read-only for everyone who can see the review screen; the Correct details button is agent-only
     and only while the lock allows it (returned / under assessment, not with the authoriser). The applicant's signed submission
     is not shown here and is never changed - see "Submission history". --}}
@php
    $detailEditor = app(\App\Services\RentalApplications\RentalApplicationDetailEditor::class);
    $changeRows = $rentalApplication->fieldChanges()->with('changedBy:id,name')->limit(50)->get();
    $canCorrectDetails = ($viewerRole ?? null) === 'agent' && $detailEditor->canEdit($rentalApplication);
@endphp
@if($changeRows->isNotEmpty() || $canCorrectDetails)
    <div class="rounded-lg p-4" style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 1px 2px rgba(15,23,42,0.06);" data-qa="field-changes-panel">
        <div class="flex items-center justify-between">
            <h2 class="text-xs font-bold uppercase tracking-widest" style="color: var(--text-muted);">Changes</h2>
            @if($canCorrectDetails)
                <a href="{{ route('corex.rental-applications.review.details.edit', $rentalApplication) }}" class="text-xs" style="color: var(--ds-blue, #2563eb);" data-qa="correct-details-link">Correct details &rarr;</a>
            @endif
        </div>
        @if($changeRows->isEmpty())
            <p class="text-xs mt-2" style="color: var(--text-muted);">Nothing corrected since the applicant submitted.</p>
        @else
            <ul class="mt-2 space-y-1 text-xs" style="color: var(--text-secondary);">
                @foreach($changeRows as $change)
                    <li data-qa="field-change-row">
                        <span class="font-medium" style="color: var(--text-primary);">{{ $change->field_label }}</span>:
                        {{ $detailEditor->displayValue($rentalApplication, $change->field_key, $change->old_value) }}
                        &rarr; {{ $detailEditor->displayValue($rentalApplication, $change->field_key, $change->new_value) }}
                        <span style="color: var(--text-muted);">— {{ $change->changedBy?->name ?? 'System' }}, {{ $change->created_at->format('d M Y H:i') }}@if($change->note) · {{ $change->note }}@endif</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
