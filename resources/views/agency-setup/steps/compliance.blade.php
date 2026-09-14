{{-- Compliance — inline wizard step.
     Posts through the wizard form to saveWhistleblowSettings, FicaOfficerAppointmentsController@saveReferralSettings
     (AT-236) AND OfficerAppointmentsController@onboardingSave (compliance approval gate, spec
     .ai/specs/esign-compliance-approval-gate.md §7.1). Every list/boolean carries its own `_present`
     marker (onboarding spec §6.1) so a subset post never wipes a setting it did not render.
     $whistleblow = ['officer_email'=>?]; $officers = ['esign_co'=>?, 'esign_ros'=>[], 'wb_co'=>?, 'wb_ros'=>[],
     'esign_route'=>str, 'wb_ro_can_submit'=>bool]; $agencyMembers = users; $agency = the current Agency. --}}
@php
    $officerEmail = old('whistleblow_compliance_officer_email', $whistleblow['officer_email'] ?? '');
    $referralEnabled = (bool) old('fica_referral_enabled', $agency->fica_referral_enabled ?? true);
    $referralRecipientId = old('fica_referral_recipient_user_id', $agency->fica_referral_recipient_user_id ?? '');
    $esignRoute = old('esign_approval_route', $officers['esign_route'] ?? 'full_status');
    $esignCoId  = old('esign_co_user_id', $officers['esign_co'] ?? '');
    $esignRoIds = array_map('intval', (array) old('esign_ro_user_ids', $officers['esign_ros'] ?? []));
    $wbCoId     = old('whistleblow_co_user_id', $officers['wb_co'] ?? '');
    $wbRoIds    = array_map('intval', (array) old('whistleblow_ro_user_ids', $officers['wb_ros'] ?? []));
    $wbRosMay   = (bool) old('whistleblow_ro_can_submit', $officers['wb_ro_can_submit'] ?? false);
@endphp
<div class="space-y-6">

    {{-- ── E-sign approval ── --}}
    <div class="space-y-3">
        <h3 class="text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">E-sign approval</h3>
        <p class="text-xs" style="color:var(--text-muted);">Who may send an e-sign document to a client. <strong>What this changes:</strong> on the Reporting Officer route every document stops after the sender signs and waits for an officer; on the default route full-status practitioners send straight away and only candidates wait for a full-status practitioner.</p>
        <input type="hidden" name="esign_route_present" value="1">
        <label class="flex items-start gap-2 text-sm cursor-pointer" style="color:var(--text-primary);">
            <input type="radio" name="esign_approval_route" value="full_status" @checked($esignRoute === 'full_status') class="mt-1" style="accent-color: var(--brand-button,#0ea5e9);">
            <span><strong>Full-status practitioners send without approval</strong> (default)</span>
        </label>
        <label class="flex items-start gap-2 text-sm cursor-pointer" style="color:var(--text-primary);">
            <input type="radio" name="esign_approval_route" value="ro_co" @checked($esignRoute === 'ro_co') class="mt-1" style="accent-color: var(--brand-button,#0ea5e9);">
            <span><strong>A Reporting Officer approves every send; the Compliance Officer overrides</strong> — needs a Compliance Officer below</span>
        </label>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">E-sign Compliance Officer (one)</label>
                <p class="text-xs mb-2" style="color:var(--text-muted);">May approve their own documents and override a decline.</p>
                <input type="hidden" name="esign_co_present" value="1">
                <select name="esign_co_user_id" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    <option value="">— none yet —</option>
                    @foreach ($agencyMembers as $m)
                        <option value="{{ $m->id }}" @selected((string) $esignCoId === (string) $m->id)>{{ $m->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">E-sign Reporting Officers</label>
                <p class="text-xs mb-2" style="color:var(--text-muted);">Tick everyone who may approve a send. Each sees only their own, branch or agency documents.</p>
                <input type="hidden" name="esign_ros_present" value="1">
                <div class="grid grid-cols-1 gap-1 max-h-40 overflow-y-auto rounded-md p-2" style="border:1px solid var(--border);">
                    @forelse ($agencyMembers as $m)
                        <label class="flex items-center gap-2 rounded-md px-2 py-1">
                            <input type="checkbox" name="esign_ro_user_ids[]" value="{{ $m->id }}" @checked(in_array((int) $m->id, $esignRoIds, true)) class="rounded" style="accent-color: var(--brand-button,#0ea5e9);">
                            <span class="text-sm truncate" style="color:var(--text-primary);">{{ $m->name }}</span>
                        </label>
                    @empty
                        <p class="text-xs" style="color:var(--text-muted);">No team members yet — add agents first.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- ── Compliance reporting ── --}}
    <div class="space-y-3" style="border-top:1px solid var(--border); padding-top:18px;">
        <h3 class="text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Compliance reporting</h3>
        <p class="text-xs" style="color:var(--text-muted);">Anyone may file a report about another agency or practitioner. <strong>What this changes:</strong> the Compliance Officer decides reports and sends them to the PPRA; Reporting Officers may also decide when you allow it below.</p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Compliance Officer (one)</label>
                <input type="hidden" name="whistleblow_co_present" value="1">
                <select name="whistleblow_co_user_id" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    <option value="">— none yet (admins and branch managers decide) —</option>
                    @foreach ($agencyMembers as $m)
                        <option value="{{ $m->id }}" @selected((string) $wbCoId === (string) $m->id)>{{ $m->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Reporting Officers</label>
                <input type="hidden" name="whistleblow_ros_present" value="1">
                <div class="grid grid-cols-1 gap-1 max-h-40 overflow-y-auto rounded-md p-2" style="border:1px solid var(--border);">
                    @forelse ($agencyMembers as $m)
                        <label class="flex items-center gap-2 rounded-md px-2 py-1">
                            <input type="checkbox" name="whistleblow_ro_user_ids[]" value="{{ $m->id }}" @checked(in_array((int) $m->id, $wbRoIds, true)) class="rounded" style="accent-color: var(--brand-button,#0ea5e9);">
                            <span class="text-sm truncate" style="color:var(--text-primary);">{{ $m->name }}</span>
                        </label>
                    @empty
                        <p class="text-xs" style="color:var(--text-muted);">No team members yet — add agents first.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <input type="hidden" name="whistleblow_submit_policy_present" value="1">
        <label class="flex items-center gap-2 text-sm cursor-pointer" style="color:var(--text-primary);">
            <input type="checkbox" name="whistleblow_ro_can_submit" value="1" @checked($wbRosMay) style="accent-color: var(--brand-button,#0ea5e9);">
            <span>Reporting Officers may also approve reports and send them onward to the PPRA</span>
        </label>

        <div>
            <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary);">Compliance officer email (copied on every PPRA submission)</label>
            <input type="email" name="whistleblow_compliance_officer_email" value="{{ $officerEmail }}"
                   class="w-full max-w-md rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                   placeholder="compliance@youragency.co.za">
        </div>

        <div>
            <h4 class="text-xs font-semibold uppercase tracking-wider mb-2" style="color:var(--text-muted);">Escalation tiers (optional)</h4>
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
    </div>

    {{-- ── FICA referral ── --}}
    <div style="border-top:1px solid var(--border); padding-top:18px;">
        <h3 class="text-xs font-semibold uppercase tracking-wider mb-2" style="color:var(--text-muted);">Refer to Compliance Officer (FICA)</h3>
        <p class="text-xs mb-2" style="color:var(--text-muted);">Lets a FICA reviewer refer a pack to your Compliance Officer as a third action (alongside approve/decline), with a mandatory reason.</p>
        <input type="hidden" name="fica_referral_settings_present" value="1">
        <label class="flex items-center gap-2 text-sm cursor-pointer mb-3" style="color:var(--text-primary);">
            <input type="checkbox" name="fica_referral_enabled" value="1" @checked($referralEnabled)
                   style="accent-color: var(--brand-button, #0ea5e9);">
            <span>Allow reviewers to refer a FICA to the Compliance Officer</span>
        </label>
        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Referrals go to</label>
        <select name="fica_referral_recipient_user_id"
                class="w-full max-w-md rounded-md px-3 py-2 text-sm mb-1"
                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <option value="">Primary Compliance Officer (default)</option>
            @foreach ($agencyMembers as $m)
                <option value="{{ $m->id }}" @selected((string) $referralRecipientId === (string) $m->id)>{{ $m->name }}</option>
            @endforeach
        </select>
        <p class="text-xs" style="color:var(--text-muted);">The recipient must be an active compliance officer; otherwise referrals fall back to the primary CO.</p>
    </div>

    <p class="text-[11px] italic" style="color:var(--text-muted);">Formal FICA / Information Officer appointments (with the acceptance workflow) are completed in the Compliance module — this step sets who decides e-sign documents and compliance reports, and where reports are routed.</p>
</div>
