{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- TFS Screening — automatic in-app sanctions screening result + CO actions.
     Screening runs against the ingested FIC sanctions list. A HIT (exact ID/passport)
     or an undecided name review BLOCKS approval (server-enforced in FicaController); a
     clean result shows "Screened & passed" with an honest coverage label. --}}
@php
    $tfsScreening = $tfsScreening ?? $submission->latestTfsScreening();
    $viewerIsCo   = auth()->user()?->isComplianceOfficer() ?? false;
    $tone = $tfsScreening?->tone() ?? 'grey';
    $toneColor = [
        'green' => 'var(--ds-green,#059669)',
        'amber' => '#b45309',
        'red'   => '#dc2626',
        'grey'  => 'var(--text-muted)',
    ][$tone];
    $listName = 'FIC UN Consolidated Sanctions List';
@endphp

<div class="rounded-md p-4" style="background:var(--surface); border:1px solid var(--border); border-left:4px solid {{ $toneColor }};">
    <div class="flex items-center justify-between gap-3 mb-3">
        <div class="flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" style="color:{{ $toneColor }};"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
            <span class="text-sm font-bold" style="color:var(--text-primary);">TFS Sanctions Screening</span>
        </div>
        @if($tfsScreening)
            <span class="text-xs font-bold px-2 py-0.5 rounded" style="color:#fff; background:{{ $toneColor }};">{{ $tfsScreening->badge() }}</span>
        @else
            <span class="text-xs font-semibold" style="color:var(--text-muted);">Not screened yet</span>
        @endif
    </div>

    @if(! $tfsScreening)
        {{-- Never screened --}}
        <p class="text-xs mb-3" style="color:var(--text-muted);">This submission has not been screened against the sanctions list. Screening is required before approval.</p>
    @else
        {{-- Outcome-specific body --}}
        @if($tfsScreening->outcome === 'hit')
            <p class="text-sm font-semibold mb-1" style="color:{{ $toneColor }};">Exact ID / passport match against a sanctioned party.</p>
            <p class="text-xs mb-3" style="color:var(--text-secondary);">Approval is blocked. A Compliance Officer must confirm or clear this before the submission can proceed.</p>
        @elseif($tfsScreening->outcome === 'review_required')
            <p class="text-sm font-semibold mb-1" style="color:{{ $toneColor }};">Possible name match{{ $tfsScreening->reason === 'list_stale' ? ' / list is stale' : '' }} — review required.</p>
            <p class="text-xs mb-3" style="color:var(--text-secondary);">Approval is blocked until a Compliance Officer clears these as false positives or confirms a match.</p>
        @elseif($tfsScreening->outcome === 'passed')
            <p class="text-sm font-semibold mb-1" style="color:{{ $toneColor }};">No sanctions match found.</p>
        @else
            <p class="text-sm font-semibold mb-1" style="color:{{ $toneColor }};">Screening could not complete.</p>
            <p class="text-xs mb-3" style="color:var(--text-secondary);">No current sanctions list is available. Approval is blocked until the list ingests successfully.</p>
        @endif

        {{-- HONEST COVERAGE / PROVENANCE — always shown, never a bare "passed" --}}
        <div class="rounded p-2 mb-3 text-xs" style="background:var(--surface-2); color:var(--text-muted);">
            <div>Screened against <span style="color:var(--text-secondary); font-weight:600;">{{ $listName }}</span>
                @if($tfsScreening->import)
                    · version <span style="color:var(--text-secondary); font-weight:600;">{{ optional($tfsScreening->list_fetched_at)->format('Y-m-d H:i') ?? ('#'.$tfsScreening->import_id) }}</span>
                @else
                    · <span style="color:#b45309;">no list version on record</span>
                @endif
            </div>
            <div>Screened {{ optional($tfsScreening->screened_at)->diffForHumans() }}
                @if($tfsScreening->screened_name) · subject: <span style="color:var(--text-secondary);">{{ $tfsScreening->screened_name }}</span>@endif
            </div>
            @if($tfsScreening->coverageNote())
                <div class="mt-1" style="color:{{ $tfsScreening->auto_pass_trusted ? 'var(--ds-green,#059669)' : '#b45309' }};">{{ $tfsScreening->coverageNote() }}</div>
            @endif
        </div>

        {{-- Candidate matches (hit / review) --}}
        @if(in_array($tfsScreening->outcome, ['hit','review_required']) && !empty($tfsScreening->candidates))
            <div class="mb-3">
                <div class="text-xs font-bold uppercase tracking-wide mb-1" style="color:var(--text-secondary);">Candidate {{ \Illuminate\Support\Str::plural('match', count($tfsScreening->candidates)) }} ({{ count($tfsScreening->candidates) }})</div>
                <div class="space-y-1">
                    @foreach($tfsScreening->candidates as $c)
                        <div class="rounded p-2 text-xs" style="background:var(--surface-2); border:1px solid var(--border);">
                            <span style="color:var(--text-primary); font-weight:700;">{{ $c['name'] ?? '—' }}</span>
                            @if(!empty($c['ref'])) <span style="color:var(--text-muted);">· {{ $c['ref'] }}</span>@endif
                            @if(!empty($c['dob'])) <span style="color:var(--text-muted);">· DOB {{ $c['dob'] }}</span>@endif
                            @if(!empty($c['nationality'])) <span style="color:var(--text-muted);">· {{ $c['nationality'] }}</span>@endif
                            @if(!empty($c['why'])) <div style="color:{{ $toneColor }};">{{ $c['why'] }}</div>@endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Decision state / CO actions --}}
        @if($tfsScreening->decision === 'cleared_false_positive')
            <p class="text-xs mb-3" style="color:var(--ds-green,#059669);">Cleared as a false positive by a Compliance Officer{{ $tfsScreening->decided_at ? ' on '.$tfsScreening->decided_at->format('Y-m-d H:i') : '' }}. Approval unblocked.</p>
        @elseif($tfsScreening->decision === 'confirmed_hit')
            <p class="text-xs mb-3" style="color:#dc2626;">Confirmed as a sanctions match by a Compliance Officer. Approval remains blocked.</p>
        @elseif(in_array($tfsScreening->outcome, ['hit','review_required']))
            @if($viewerIsCo)
                <form method="POST" action="{{ route('compliance.fica.tfs-decision', $submission) }}" class="rounded p-2" style="background:var(--surface-2); border:1px dashed var(--border);">
                    @csrf
                    <input type="hidden" name="screening_id" value="{{ $tfsScreening->id }}">
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Compliance Officer decision</label>
                    <textarea name="note" rows="2" class="w-full rounded-md px-2 py-1 text-xs mb-2" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);" placeholder="Reason / note (recorded to the audit trail)"></textarea>
                    <div class="flex gap-2">
                        <button type="submit" name="action" value="clear" class="corex-btn-primary text-xs">Clear as false positive</button>
                        <button type="submit" name="action" value="confirm" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:#dc2626; color:#fff;">Confirm sanctions match</button>
                    </div>
                </form>
            @else
                <p class="text-xs mb-3" style="color:var(--text-muted);">Awaiting a Compliance Officer's decision.</p>
            @endif
        @endif
    @endif

    {{-- Primary action: run / re-run screening --}}
    <div class="flex items-center gap-3 mt-3 pt-3" style="border-top:1px solid var(--border);">
        <form method="POST" action="{{ route('compliance.fica.tfs-screen', $submission) }}">
            @csrf
            <button type="submit" class="corex-btn-outline text-sm">{{ $tfsScreening ? 'Re-run screening' : 'Run TFS screening' }}</button>
        </form>
        {{-- Secondary: manual cross-check on the FIC site (opens in a new tab) --}}
        <a href="https://tfs.fic.gov.za/Pages/Search" target="_blank" rel="noopener"
           class="inline-flex items-center gap-1.5 text-xs font-semibold transition" style="color:var(--text-secondary);">
            Open FIC TFS in new tab
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3 h-3"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
        </a>
    </div>
</div>
