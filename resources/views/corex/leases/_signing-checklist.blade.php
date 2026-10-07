{{--
    .ai/specs/leases.md §15.3 (Build L2) — the "Needed for signing" panel: updates as the agent types and lists
    exactly which of the agreement details the agency's own lease marks required are still blank. Shown only
    when the lease agreement marks something required and the user may prepare agreements. (Contact gaps —
    landlord, a tenant's email or ID — are checked when the signing document is prepared, Build L3a.)
    Included by capture.blade.php inside the capture form (Alpine scope: leaseCaptureForm).
--}}
@if($canPrepare && collect($requiredByAgreement)->flatten(1)->isNotEmpty())
    <div class="rounded-md p-3 text-xs" style="background: var(--surface-2); border: 1px solid var(--border);" data-qa="signing-checklist">
        <div class="font-medium">Needed for signing</div>
        <div x-show="missing().length === 0" style="color: var(--text-muted);">Everything needed is filled in.</div>
        <ul x-show="missing().length > 0" x-cloak class="list-disc pl-4">
            <template x-for="m in missing()" :key="m.key">
                <li x-text="m.label"></li>
            </template>
        </ul>
    </div>
@endif
