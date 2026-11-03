{{-- AT-527: shown wherever a post can be made while external publishing is off on this server (QA1). Nothing is sent to Facebook. --}}
@if (app(\App\Services\AdStudio\VideoAdPostService::class)->isDryRun())
    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 18%, transparent); color: var(--text-primary); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 45%, transparent);"
          title="External posting is switched off on this server. Post now and Schedule only log what they would send." data-qa="dry-run-badge">Dry run (QA)</span>
@endif
