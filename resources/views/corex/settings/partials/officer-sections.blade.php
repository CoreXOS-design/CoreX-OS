{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Company Settings › Compliance officers — the per-module RO / CO sections + the two switches.
     Spec .ai/specs/esign-compliance-approval-gate.md §7. Included by corex/settings.blade.php inside
     the manage_compliance_officer block; $agencyUsers, $agency and $settingsAgencyId come from there. --}}
@php
    $officerRegistry = app(\App\Services\Compliance\OfficerRegistry::class);
    $esignCo   = $officerRegistry->currentCo($settingsAgencyId, \App\Models\Compliance\OfficerAppointment::MODULE_ESIGN);
    $esignRoIds = $officerRegistry->activeRos($settingsAgencyId, \App\Models\Compliance\OfficerAppointment::MODULE_ESIGN)->pluck('user_id')->filter()->map(fn ($i) => (int) $i)->all();
    $wbCo      = $officerRegistry->currentCo($settingsAgencyId, \App\Models\Compliance\OfficerAppointment::MODULE_WHISTLEBLOW);
    $wbRoIds   = $officerRegistry->activeRos($settingsAgencyId, \App\Models\Compliance\OfficerAppointment::MODULE_WHISTLEBLOW)->pluck('user_id')->filter()->map(fn ($i) => (int) $i)->all();
    $esignRoute = $officerRegistry->esignRoute($settingsAgencyId);
    $wbRosMaySubmit = $officerRegistry->whistleblowRosMaySubmit($settingsAgencyId);
    $chevron = '<svg class="w-4 h-4 transition-transform duration-150" :class="open && \'rotate-90\'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>';
@endphp

{{-- ── E-sign approval ── --}}
<div x-data="{ open: false }" class="rounded-md overflow-hidden mt-3" style="border:1px solid var(--border);">
    <button type="button" @click="open = !open"
            class="w-full flex items-center justify-between px-4 py-3 text-sm font-semibold transition-colors hover:opacity-80"
            style="background:var(--surface-2); color:var(--text-primary);">
        <span>E-sign approval — Reporting Officers and Compliance Officer</span>
        {!! $chevron !!}
    </button>
    <div x-show="open" x-cloak x-transition class="p-4 space-y-5" style="border-top:1px solid var(--border); background:var(--surface);">

        @if($esignRoute === 'ro_co' && ! $esignCo)
        <div class="px-3 py-2 text-xs font-semibold" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); border-radius:6px; color: var(--ds-crimson);">
            The Reporting Officer route is on but no e-sign Compliance Officer is appointed. Appoint one below.
        </div>
        @elseif($esignRoute === 'ro_co' && $esignRoIds === [])
        <div class="px-3 py-2 text-xs" style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); border-radius:6px; color: var(--text-primary);">
            No Reporting Officers yet — every document waits for the Compliance Officer alone.
        </div>
        @endif

        {{-- Route --}}
        <form method="POST" action="{{ route('corex.settings.esign-approval-route') }}" class="space-y-2">
            @csrf
            <input type="hidden" name="esign_route_present" value="1">
            <div class="text-xs font-semibold" style="color:var(--text-secondary);">Who may send an e-sign document</div>
            <label class="flex items-start gap-2 text-sm cursor-pointer" style="color:var(--text-primary);">
                <input type="radio" name="esign_approval_route" value="full_status" {{ $esignRoute === 'full_status' ? 'checked' : '' }} class="mt-1" style="accent-color: var(--brand-button, #0ea5e9);">
                <span><strong>Full-status practitioners send without approval</strong> (default). A candidate always submits to a full-status practitioner for authorisation — that part is law and never switches off.</span>
            </label>
            <label class="flex items-start gap-2 text-sm cursor-pointer" style="color:var(--text-primary);">
                <input type="radio" name="esign_approval_route" value="ro_co" {{ $esignRoute === 'ro_co' ? 'checked' : '' }} class="mt-1" style="accent-color: var(--brand-button, #0ea5e9);">
                <span><strong>A Reporting Officer approves every send; the Compliance Officer overrides.</strong> After the sender signs, the document is held until an officer approves it. Nothing leaves the agency unseen.</span>
            </label>
            <p class="text-xs" style="color:var(--text-muted);">Switching this on needs an e-sign Compliance Officer. Documents already out with a party are not pulled back.</p>
            <button type="submit" class="corex-btn-primary text-xs">Save route</button>
        </form>

        {{-- CO --}}
        <form method="POST" action="{{ route('corex.settings.officers.co', ['module' => 'esign']) }}" class="space-y-2" style="border-top:1px solid var(--border); padding-top:14px;">
            @csrf
            <div class="text-xs font-semibold" style="color:var(--text-secondary);">E-sign Compliance Officer (one)</div>
            @if($esignCo)
                <div class="text-xs" style="color:var(--text-muted);">Currently <strong style="color:var(--text-primary);">{{ $esignCo->full_name }}</strong> — appointed {{ $esignCo->appointed_on->format('d M Y') }}</div>
            @endif
            <select name="co_user_id" class="w-full max-w-md rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                <option value="">— No Compliance Officer —</option>
                @foreach($agencyUsers as $u)
                    <option value="{{ $u->id }}" {{ $esignCo && (int) $esignCo->user_id === (int) $u->id ? 'selected' : '' }}>{{ $u->name }} ({{ $u->email }})</option>
                @endforeach
            </select>
            <p class="text-xs" style="color:var(--text-muted);">Appointing a new person ends the previous appointment. The Compliance Officer may approve their own documents and may override a decline.</p>
            <button type="submit" class="corex-btn-primary text-xs">Save Compliance Officer</button>
        </form>

        {{-- ROs --}}
        <form method="POST" action="{{ route('corex.settings.officers.ros', ['module' => 'esign']) }}" style="border-top:1px solid var(--border); padding-top:14px;">
            @csrf
            <div class="text-xs font-semibold mb-2" style="color:var(--text-secondary);">E-sign Reporting Officers (as many as you like)</div>
            <div class="space-y-1 max-h-48 overflow-y-auto mb-2 rounded-md p-2" style="border:1px solid var(--border); background:var(--surface);">
                @foreach($agencyUsers as $u)
                <label class="flex items-center gap-2 py-1 px-1 text-sm cursor-pointer hover:bg-[color:var(--surface-2)] rounded">
                    <input type="checkbox" name="ro_user_ids[]" value="{{ $u->id }}" {{ in_array((int) $u->id, $esignRoIds, true) ? 'checked' : '' }} style="accent-color: var(--brand-button, #0ea5e9);">
                    <span style="color:var(--text-primary);">{{ $u->name }}</span>
                    <span class="text-xs" style="color:var(--text-muted);">{{ $u->role }}</span>
                </label>
                @endforeach
            </div>
            <p class="text-xs mb-3" style="color:var(--text-muted);">An officer only sees documents inside the branch or agency they may act on (agent: own, branch manager: branch, admin: all). To authorise a candidate's document an officer must be a full-status practitioner.</p>
            <button type="submit" class="corex-btn-primary text-xs">Save Reporting Officers</button>
        </form>
    </div>
</div>

{{-- ── Compliance reporting officers ── --}}
<div x-data="{ open: false }" class="rounded-md overflow-hidden mt-3" style="border:1px solid var(--border);">
    <button type="button" @click="open = !open"
            class="w-full flex items-center justify-between px-4 py-3 text-sm font-semibold transition-colors hover:opacity-80"
            style="background:var(--surface-2); color:var(--text-primary);">
        <span>Compliance reporting — Reporting Officers and Compliance Officer</span>
        {!! $chevron !!}
    </button>
    <div x-show="open" x-cloak x-transition class="p-4 space-y-5" style="border-top:1px solid var(--border); background:var(--surface);">

        @if(! $wbCo)
        <div class="px-3 py-2 text-xs" style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); border-radius:6px; color: var(--text-primary);">
            No Compliance Officer appointed for compliance reporting. Until one is, admins and branch managers decide reports, as before.
        </div>
        @endif

        {{-- CO --}}
        <form method="POST" action="{{ route('corex.settings.officers.co', ['module' => 'whistleblow']) }}" class="space-y-2">
            @csrf
            <div class="text-xs font-semibold" style="color:var(--text-secondary);">Compliance Officer (one) — approves reports and sends them to the PPRA</div>
            @if($wbCo)
                <div class="text-xs" style="color:var(--text-muted);">Currently <strong style="color:var(--text-primary);">{{ $wbCo->full_name }}</strong> — appointed {{ $wbCo->appointed_on->format('d M Y') }}</div>
            @endif
            <select name="co_user_id" class="w-full max-w-md rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                <option value="">— No Compliance Officer —</option>
                @foreach($agencyUsers as $u)
                    <option value="{{ $u->id }}" {{ $wbCo && (int) $wbCo->user_id === (int) $u->id ? 'selected' : '' }}>{{ $u->name }} ({{ $u->email }})</option>
                @endforeach
            </select>
            <button type="submit" class="corex-btn-primary text-xs">Save Compliance Officer</button>
        </form>

        {{-- ROs + policy --}}
        <form method="POST" action="{{ route('corex.settings.officers.ros', ['module' => 'whistleblow']) }}" style="border-top:1px solid var(--border); padding-top:14px;">
            @csrf
            <div class="text-xs font-semibold mb-2" style="color:var(--text-secondary);">Reporting Officers</div>
            <div class="space-y-1 max-h-48 overflow-y-auto mb-3 rounded-md p-2" style="border:1px solid var(--border); background:var(--surface);">
                @foreach($agencyUsers as $u)
                <label class="flex items-center gap-2 py-1 px-1 text-sm cursor-pointer hover:bg-[color:var(--surface-2)] rounded">
                    <input type="checkbox" name="ro_user_ids[]" value="{{ $u->id }}" {{ in_array((int) $u->id, $wbRoIds, true) ? 'checked' : '' }} style="accent-color: var(--brand-button, #0ea5e9);">
                    <span style="color:var(--text-primary);">{{ $u->name }}</span>
                    <span class="text-xs" style="color:var(--text-muted);">{{ $u->role }}</span>
                </label>
                @endforeach
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Save Reporting Officers</button>
        </form>

        <form method="POST" action="{{ route('corex.settings.whistleblow-submit-policy') }}" style="border-top:1px solid var(--border); padding-top:14px;">
            @csrf
            <input type="hidden" name="whistleblow_submit_policy_present" value="1">
            <label class="flex items-center gap-2 text-sm cursor-pointer mb-2" style="color:var(--text-primary);">
                <input type="checkbox" name="whistleblow_ro_can_submit" value="1" {{ $wbRosMaySubmit ? 'checked' : '' }} style="accent-color: var(--brand-button, #0ea5e9);">
                <span>Reporting Officers may also <strong>approve reports and send them onward to the PPRA</strong> (not only the Compliance Officer).</span>
            </label>
            <p class="text-xs mb-3" style="color:var(--text-muted);">Anyone in the agency may still file a report. This only widens who may decide one.</p>
            <button type="submit" class="corex-btn-primary text-xs">Save policy</button>
        </form>
    </div>
</div>
