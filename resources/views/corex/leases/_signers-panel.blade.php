{{--
    .ai/specs/leases.md §15.3 / §15.4 (Build L3a) — "Who signs": the landlord(s) the property gives this lease, and
    each tenant, with what is still missing before the agreement can be prepared (an email address, an ID or
    passport number) and a link to the contact. The order shown is the order they sign — always the agent first,
    then the tenant(s), then the landlord(s); there is no setting for it (R4). Names and "what is missing" only.

    Shown only when the agency has a ready lease agreement and the user may prepare agreements. Included by
    capture.blade.php inside the capture form (Alpine scope: leaseCaptureForm — refreshSigners() fills `signers`).
--}}
@if($canPrepare && $agreementReady)
    <div class="rounded-md p-3 text-xs space-y-2" style="background: var(--surface-2); border: 1px solid var(--border);" data-qa="signers-panel">
        <div class="font-medium">Who signs</div>
        <p style="color: var(--text-muted);">Always in this order: you first, then the tenant(s), then the landlord(s).</p>

        <div>
            <div class="font-medium">Tenant(s)</div>
            <p x-show="signers.tenants.length === 0" x-cloak style="color: var(--text-muted);">Add a tenant above.</p>
            <ul class="space-y-0.5">
                <template x-for="t in signers.tenants" :key="'t' + t.id">
                    <li>
                        <span x-text="t.name"></span>
                        <span x-show="t.needs.length === 0" x-cloak style="color: var(--ds-green, #16a34a);">— ready to sign</span>
                        <span x-show="t.needs.length > 0" x-cloak style="color: var(--ds-crimson);" x-text="'— needs ' + t.needs.join(' and ')"></span>
                        <a x-show="t.needs.length > 0" x-cloak :href="t.url" target="_blank" rel="noopener" class="underline">update contact</a>
                        {{-- FICA is a warning, never a stop (Johan, 2026-10-08): submitted or further = nothing to say. --}}
                        <span x-show="t.fica && !t.fica.open" x-cloak style="color: var(--ds-amber, #b45309);" data-qa="fica-warning" x-text="'— ' + t.fica.label.toLowerCase() + ' (you can carry on)'"></span>
                        <a x-show="t.fica && !t.fica.open && t.fica.url" x-cloak :href="t.fica && t.fica.url" target="_blank" rel="noopener" class="underline" data-qa="fica-link">request / complete FICA</a>
                    </li>
                </template>
            </ul>
        </div>

        <div>
            <div class="font-medium">Landlord(s)</div>
            <p x-show="!propertyId && !signers.loaded" x-cloak style="color: var(--text-muted);">Choose a property to see its landlord.</p>
            <p x-show="signers.landlordMissing" x-cloak style="color: var(--ds-crimson);" data-qa="no-landlord">
                No landlord is linked to this property.
                <a :href="signers.landlordUrl" target="_blank" rel="noopener" class="underline">Link landlord on the property</a>
            </p>
            <ul class="space-y-0.5">
                <template x-for="l in signers.landlords" :key="'l' + l.id">
                    <li>
                        <span x-text="l.name"></span>
                        <span x-show="l.needs.length === 0" x-cloak style="color: var(--ds-green, #16a34a);">— ready to sign</span>
                        <span x-show="l.needs.length > 0" x-cloak style="color: var(--ds-crimson);" x-text="'— needs ' + l.needs.join(' and ')"></span>
                        <a x-show="l.needs.length > 0" x-cloak :href="l.url" target="_blank" rel="noopener" class="underline">update contact</a>
                        {{-- FICA is a warning, never a stop (Johan, 2026-10-08): submitted or further = nothing to say. --}}
                        <span x-show="l.fica && !l.fica.open" x-cloak style="color: var(--ds-amber, #b45309);" data-qa="fica-warning" x-text="'— ' + l.fica.label.toLowerCase() + ' (you can carry on)'"></span>
                        <a x-show="l.fica && !l.fica.open && l.fica.url" x-cloak :href="l.fica && l.fica.url" target="_blank" rel="noopener" class="underline" data-qa="fica-link">request / complete FICA</a>
                    </li>
                </template>
            </ul>
        </div>
    </div>
@endif
