{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- TFS Screening — automatic, non-blocking result (three-tier seriousness flow):
     TIER 1 clean -> green "Screened & passed", ticks, continue.
     TIER 2 name match -> amber "ID does not match, name and surname match.", ticks, continue (flagged).
     TIER 3 exact ID  -> red "Sanctions HIT", record LOCKED (all buttons hidden, Report to CO only).
     Auto-runs on data landing; the "Run TFS" button here is a FALLBACK only when a run could
     not complete (list unavailable / stale). Provenance label is mandatory on every result. --}}
@php
    $s = $tfsScreening ?? $submission->latestTfsScreening();
    $ran = $s?->ranSuccessfully() ?? false;
    $viewerIsCo = auth()->user()?->isComplianceOfficer() ?? false;
    $tone = $s?->tone() ?? 'grey';
    $toneColor = ['green' => 'var(--ds-green,#059669)', 'amber' => '#b45309', 'red' => '#dc2626', 'grey' => 'var(--text-muted)'][$tone];
    $listName = 'FIC UN Consolidated list';
@endphp

<div class="rounded-md p-4" style="background:var(--surface); border:1px solid var(--border); border-left:4px solid {{ $toneColor }};">
    <div class="flex items-center justify-between gap-3 mb-3">
        <div class="flex items-center gap-2">
            @if($ran && !$s->isFlagged())
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5" style="color:var(--ds-green,#059669);"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            @else
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" style="color:{{ $toneColor }};"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
            @endif
            <span class="text-sm font-bold" style="color:var(--text-primary);">TFS Sanctions Screening</span>
        </div>
        @if($s)
            <span class="text-xs font-bold px-2 py-0.5 rounded" style="color:#fff; background:{{ $toneColor }};">{{ $s->badge() }}</span>
        @else
            <span class="text-xs font-semibold" style="color:var(--text-muted);">Not screened yet</span>
        @endif
    </div>

    @if(! $s)
        <p class="text-xs mb-3" style="color:var(--text-muted);">Screening runs automatically once the applicant's name and ID are on file. If it has not run, use the button below.</p>
    @else
        {{-- Outcome body --}}
        @if($s->outcome === 'hit')
            <p class="text-sm font-semibold mb-1" style="color:{{ $toneColor }};">Exact ID / passport match — Sanctions HIT.</p>
            <p class="text-xs mb-3" style="color:var(--text-secondary);">This record is <strong>locked</strong>. The only available action is <strong>Report to CO</strong>.</p>
        @elseif($s->outcome === 'review_required')
            <p class="text-sm font-semibold mb-1" style="color:{{ $toneColor }};">{{ $s->amberMessage() }}</p>
            <p class="text-xs mb-3" style="color:var(--text-secondary);">Flagged amber for Compliance Officer attention. The agent may continue — this does not block approval.</p>
        @elseif($s->outcome === 'passed')
            <p class="text-sm font-semibold mb-1" style="color:{{ $toneColor }};">Screened &amp; passed — no sanctions match.</p>
        @else
            <p class="text-sm font-semibold mb-1" style="color:{{ $toneColor }};">{{ $s->statusLine() }}</p>
        @endif

        {{-- MANDATORY provenance / freshness — the legal defense, on every result --}}
        @if($s->import)
            <div class="rounded p-2 mb-3 text-xs" style="background:var(--surface-2); color:var(--text-muted);">
                <div>Screened against <span style="color:var(--text-secondary); font-weight:600;">{{ $listName }}</span>
                    · version <span style="color:var(--text-secondary); font-weight:600;">{{ optional($s->list_fetched_at)->format('Y-m-d H:i') ?? ('#'.$s->import_id) }}</span></div>
                <div>Screened {{ optional($s->screened_at)->diffForHumans() }}@if($s->screened_name) · subject: <span style="color:var(--text-secondary);">{{ $s->screened_name }}</span>@endif</div>
            </div>
        @endif

        {{-- Candidate matches (hit / review) --}}
        @if(in_array($s->outcome, ['hit','review_required']) && !empty($s->candidates))
            <div class="mb-3">
                <div class="text-xs font-bold uppercase tracking-wide mb-1" style="color:var(--text-secondary);">Candidate {{ \Illuminate\Support\Str::plural('match', count($s->candidates)) }} ({{ count($s->candidates) }})</div>
                <div class="space-y-1">
                    @foreach($s->candidates as $c)
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

        {{-- CO decision state / actions (clearing a hit unlocks the record) --}}
        @if($s->decision === 'cleared_false_positive')
            <p class="text-xs mb-3" style="color:var(--ds-green,#059669);">Cleared as a false positive by a Compliance Officer{{ $s->decided_at ? ' on '.$s->decided_at->format('Y-m-d H:i') : '' }}.</p>
        @elseif($s->decision === 'confirmed_hit')
            <p class="text-xs mb-3" style="color:#dc2626;">Confirmed as a sanctions match by a Compliance Officer.</p>
        @elseif($s->isFlagged() && $viewerIsCo)
            <form method="POST" action="{{ route('compliance.fica.tfs-decision', $submission) }}" class="rounded p-2" style="background:var(--surface-2); border:1px dashed var(--border);">
                @csrf
                <input type="hidden" name="screening_id" value="{{ $s->id }}">
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Compliance Officer decision</label>
                <textarea name="note" rows="2" class="w-full rounded-md px-2 py-1 text-xs mb-2" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);" placeholder="Reason / note (recorded to the audit trail)"></textarea>
                <div class="flex gap-2">
                    <button type="submit" name="action" value="clear" class="corex-btn-primary text-xs">Clear as false positive</button>
                    <button type="submit" name="action" value="confirm" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:#dc2626; color:#fff;">Confirm sanctions match</button>
                </div>
            </form>
        @endif
    @endif

    {{-- Fallback: manual run ONLY when a run has not completed (never in the happy path) --}}
    <div class="flex items-center gap-3 mt-3 pt-3" style="border-top:1px solid var(--border);">
        @if(! $ran)
            <form method="POST" action="{{ route('compliance.fica.tfs-screen', $submission) }}">
                @csrf
                <button type="submit" class="corex-btn-outline text-sm">Run TFS screening</button>
            </form>
        @endif
        <a href="https://tfs.fic.gov.za/Pages/Search" target="_blank" rel="noopener"
           class="inline-flex items-center gap-1.5 text-xs font-semibold transition" style="color:var(--text-secondary);">
            Open FIC TFS in new tab
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3 h-3"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
        </a>
    </div>
</div>
