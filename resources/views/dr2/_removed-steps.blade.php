{{-- Removed (soft-deleted) steps — per-step RESTORE. Restores a data-loss-shaped gap from the pipeline
     rebuild: the old board (pipeline.blade.php) had a "Removed steps (N)" collapsible with a Restore
     button per step, but the new List/Timeline let an agent Remove a step with no way back — even though
     BuildsPipelineContext already plumbs $removedSteps to both views. Reachable on BOTH surfaces:
       · List     — included at the bottom of the phased step column.
       · Timeline — included from _pipeline-context-tabs (the deal-panels area). pipeline-timeline.blade.php
                    is cc5's and must not be touched, and _pipeline-timeline-actions is a PER-TILE partial
                    so a deal-level list cannot live there.
     Gated exactly as the old board had it: @unless($locked) + @permission('view_deals').
     Posts to the existing pipeline.step.restore action; `from` returns the agent to the view they acted on.
     Expects in scope: $deal, $removedSteps (Collection), $locked, $from ('list'|'timeline').
     Renders NOTHING when there are no removed steps. --}}
@unless($locked)
@permission('view_deals')
@if(($removedSteps ?? collect())->isNotEmpty())
<div class="dr2-removed" x-data="{ rm:false }" style="padding:.5rem .25rem;border-top:2px solid #e5e7eb;margin-top:.5rem;">
    <button type="button" @click="rm=!rm"
            style="padding:.25rem .7rem;font-size:12px;font-weight:600;color:#b45309;background:#fff;border:1px solid #fcd34d;border-radius:999px;cursor:pointer;font-family:inherit;">
        <span x-text="rm ? '▾' : '▸'"></span> Removed steps ({{ $removedSteps->count() }})
    </button>
    <div x-show="rm" x-cloak style="margin-top:.5rem;display:flex;flex-direction:column;gap:.15rem;">
        @foreach($removedSteps as $rs)
        <div style="display:flex;align-items:center;justify-content:space-between;gap:.6rem;padding:.3rem .1rem;font-size:12.5px;">
            <span style="text-decoration:line-through;color:#6b7280;">{{ $rs->name }}</span>
            <form method="POST" action="{{ route('deals-dr2.pipeline.step.restore', $deal) }}" style="margin:0;">@csrf
                <input type="hidden" name="step_id" value="{{ $rs->id }}">
                <input type="hidden" name="from" value="{{ $from ?? 'list' }}">
                <button type="submit"
                        style="padding:.15rem .7rem;font-size:11.5px;font-weight:600;color:#2563eb;background:#fff;border:1px solid #bfdbfe;border-radius:6px;cursor:pointer;font-family:inherit;">Restore</button>
            </form>
        </div>
        @endforeach
    </div>
</div>
@endif
@endpermission
@endunless
