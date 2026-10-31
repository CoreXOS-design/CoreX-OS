{{-- Removed (soft-deleted) steps — per-step RESTORE. Restores a data-loss-shaped gap from the pipeline
     rebuild: the old board (pipeline.blade.php) had a "Removed steps (N)" collapsible with a Restore
     button per step, but the new List/Timeline let an agent Remove a step with no way back — even though
     BuildsPipelineContext already plumbs $removedSteps to both views. Reachable on BOTH surfaces:
       · List     — included at the bottom of the phased step column.
       · Timeline — included from _pipeline-context-tabs (the deal-panels area). pipeline-timeline.blade.php
                    is cc5's and must not be touched, and _pipeline-timeline-actions is a PER-TILE partial
                    so a deal-level list cannot live there.
     Gated exactly as the old board had it: unless($locked) + permission('view_deals').
     Posts to the existing pipeline.step.restore action; `from` returns the agent to the view they acted on.
     Expects in scope: $deal, $removedSteps (Collection), $locked, $from ('list'|'timeline').
     Renders NOTHING when there are no removed steps. --}}
@unless($locked)
@permission('view_deals')
@if(($removedSteps ?? collect())->isNotEmpty())
<div class="dr2-removed" x-data="{ rm:false }" style="padding:.5rem .25rem;border-top:1px solid var(--border);margin-top:.5rem;">
    <button type="button" @click="rm=!rm"
            style="display:inline-flex;align-items:center;gap:.3rem;padding:.25rem .7rem;font-size:12px;font-weight:600;color:var(--ds-amber,#f59e0b);background:var(--surface);border:1px solid color-mix(in srgb,var(--ds-amber,#f59e0b) 45%,transparent);border-radius:999px;cursor:pointer;font-family:inherit;box-shadow:none;">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true" style="width:12px;height:12px;transition:transform .18s;" :style="{ transform: rm ? 'rotate(90deg)' : '' }"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg> Removed steps ({{ $removedSteps->count() }})
    </button>
    <div x-show="rm" x-cloak style="margin-top:.5rem;display:flex;flex-direction:column;gap:.15rem;">
        @foreach($removedSteps as $rs)
        <div style="display:flex;align-items:center;justify-content:space-between;gap:.6rem;padding:.3rem .1rem;font-size:12.5px;">
            <span style="text-decoration:line-through;color:var(--text-muted);">{{ $rs->name }}</span>
            <form method="POST" action="{{ route('deals-dr2.pipeline.step.restore', $deal) }}" style="margin:0;">@csrf
                <input type="hidden" name="step_id" value="{{ $rs->id }}">
                <input type="hidden" name="from" value="{{ $from ?? 'list' }}">
                <button type="submit"
                        style="padding:.15rem .7rem;font-size:11.5px;font-weight:600;color:var(--brand-button,#0ea5e9);background:var(--surface);border:1px solid var(--border);border-radius:6px;cursor:pointer;font-family:inherit;box-shadow:none;">Restore</button>
            </form>
        </div>
        @endforeach
    </div>
</div>
@endif
@endpermission
@endunless
