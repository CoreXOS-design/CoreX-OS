{{--
    "Build staff training from this version" - shared by the RMCP and policy view pages (compliance.md).
    Expects: $version (must be active), $counts (StaffTrainingService::counts), $trainingUrl, $docLabel.
--}}
<div id="staff-training" class="mb-4 rounded-md px-4 py-3 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <div class="font-semibold">Staff training</div>
            <div class="text-xs mt-0.5" style="color: var(--text-muted);" data-training-status>
                @if($version->staff_training_sent_at)
                    Last sent {{ $version->staff_training_sent_at->format('j F Y H:i') }} · {{ $counts['signed'] }} of {{ $counts['total'] }} signed
                @else
                    Not sent yet · {{ $counts['signed'] }} of {{ $counts['total'] }} signed
                @endif
            </div>
        </div>
        <form method="POST" action="{{ $trainingUrl }}">
            @csrf
            <button type="submit" class="corex-btn-primary text-xs"
                    data-confirm="Send {{ $docLabel }} to staff? {{ $counts['asked'] }} of {{ $counts['total'] }} active staff will be asked to read it section by section and sign at the end. Earlier signed versions stop counting."
                    data-confirm-label="Send to staff">Build staff training from this version</button>
        </form>
    </div>
</div>
