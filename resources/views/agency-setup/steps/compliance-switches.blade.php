{{-- AT-509 — Compliance Switches inside the Setup Wizard's Compliance step (.ai/specs/property-compliance-gate-settings.md §11).
     Same partial, same saver and same disclaimer as Settings → Compliance Switches. Only rendered for someone who may
     change the switches; when it is not rendered its marker is never posted and the saver leaves everything alone. --}}
@if(auth()->user()?->hasPermission('compliance_switches.manage'))
    <div class="space-y-3">
        <div>
            <h3 class="text-sm font-semibold" style="color:var(--text-primary);">Compliance checks before marketing</h3>
            <p class="text-xs mt-0.5" style="color:var(--text-secondary);">
                Before a property can be marketed, CoreX checks it has the documents your agency requires, such as the mandate,
                FICA on the seller and the disclosure form. Everything is on by default. If your agency handles one of these
                outside CoreX you can switch it off here. You'll be asked to accept responsibility for that, and the choice is recorded.
                What this changes: a switched-off check no longer stops a property from being marketed.
            </p>
        </div>
        @include('corex.settings.partials.compliance-switches-form')
    </div>
@endif
