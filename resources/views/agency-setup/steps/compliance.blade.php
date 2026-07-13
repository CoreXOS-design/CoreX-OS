{{-- Compliance reporting — inline wizard step.
     Posts through the wizard form to saveWhistleblowSettings.
     $whistleblow = ['officer_email'=>?, 'approver_ids'=>[]]; $agencyMembers = users. --}}
@php
    $officerEmail = old('whistleblow_compliance_officer_email', $whistleblow['officer_email'] ?? '');
    $approverIds  = (array) old('whistleblow_approver_user_ids', $whistleblow['approver_ids'] ?? []);
@endphp
<div class="space-y-5">
    <div>
        <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary);">Compliance officer email</label>
        <p class="text-xs mb-2" style="color:var(--text-muted);">Where compliance / whistleblower reports are sent.</p>
        <input type="email" name="whistleblow_compliance_officer_email" value="{{ $officerEmail }}"
               class="w-full max-w-md rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
               placeholder="compliance@youragency.co.za">
    </div>

    <div>
        <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary);">Report approvers</label>
        <p class="text-xs mb-2" style="color:var(--text-muted);">Team members who may review and approve compliance reports. Tick everyone who should have access.</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-56 overflow-y-auto rounded-md p-2" style="border:1px solid var(--border);">
            @forelse ($agencyMembers as $m)
                <label class="flex items-center gap-2 rounded-md px-2 py-1.5" style="border:1px solid var(--border);">
                    <input type="checkbox" name="whistleblow_approver_user_ids[]" value="{{ $m->id }}" @checked(in_array($m->id, array_map('intval', $approverIds), true))
                           class="rounded" style="accent-color: var(--brand-button,#0ea5e9);">
                    <span class="text-sm min-w-0" style="color:var(--text-primary);">
                        <span class="block truncate">{{ $m->name }}</span>
                        <span class="block text-[11px] truncate" style="color:var(--text-muted);">{{ $m->email }}</span>
                    </span>
                </label>
            @empty
                <p class="text-xs" style="color:var(--text-muted);">No team members yet — add agents first, then return to assign approvers.</p>
            @endforelse
        </div>
    </div>

    <div>
        <h3 class="text-xs font-semibold uppercase tracking-wider mb-2" style="color:var(--text-muted);">Escalation tiers (optional)</h3>
        <p class="text-xs mb-2" style="color:var(--text-muted);">One email per line. Reports escalate through these tiers if unresolved.</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            @foreach (['tier_1' => 'Tier 1 (first)', 'tier_2' => 'Tier 2', 'tier_3' => 'Tier 3 (final)'] as $tier => $lbl)
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">{{ $lbl }}</label>
                    <textarea name="tier_recipients[{{ $tier }}]" rows="2"
                              class="w-full rounded-md px-3 py-2 text-xs" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                              placeholder="name@agency.co.za">{{ old("tier_recipients.$tier") }}</textarea>
                </div>
            @endforeach
        </div>
    </div>

    <p class="text-[11px] italic" style="color:var(--text-muted);">Formal FICA / Information Officer appointments (with the acceptance workflow) are completed in the Compliance module — this step sets where reports are routed.</p>
</div>
