{{-- Sign-offs, tenant confirmation, complete and cancel - each only at the stage it fits. Receives $jobCard, $isOpen, $stage. --}}
            @if($isOpen)
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                @permission('rental_job_cards.sign_off')
                @if(! $jobCard->worker_signed_off_at && $stage['can']['worker_sign_off'])
                {{-- 2026-10-05 — crew have no CoreX login to sign off themselves; the agent
                     records it, naming who on the (already-assigned) crew actually did the
                     work — free text, with that crew's own member names offered via the
                     native datalist below as a convenience, not a constraint. --}}
                <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.worker-sign-off', $jobCard) }}" class="space-y-2">
                    @csrf
                    <input type="text" name="worker_sign_off_name" list="worker-sign-off-names" maxlength="191" placeholder="Who did the work (optional)" aria-label="Who did the work" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
                    <datalist id="worker-sign-off-names">
                        @foreach($jobCard->crew?->members ?? [] as $member)
                            <option value="{{ $member->name }}">
                        @endforeach
                    </datalist>
                    <button type="submit" class="corex-btn-outline text-xs w-full">Worker sign-off</button>
                </form>
                @elseif($jobCard->worker_signed_off_at)
                <div class="text-xs" style="color: var(--text-muted);">Worker — done: {{ $jobCard->workerSignedOffByUser?->name ?? 'crew' }} ({{ $jobCard->worker_signed_off_at->format('Y-m-d H:i') }})@if($jobCard->worker_sign_off_name) — {{ $jobCard->worker_sign_off_name }}@endif @if($jobCard->worker_sign_off_via && $jobCard->worker_sign_off_via !== 'office')<span class="ds-badge ds-badge-muted">{{ str_replace('_', ' ', $jobCard->worker_sign_off_via) }}</span>@endif</div>
                @endif

                @if(! $jobCard->agent_signed_off_at && $stage['can']['agent_sign_off'])
                <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.agent-sign-off', $jobCard) }}">@csrf<button type="submit" class="corex-btn-outline text-xs w-full">Agent sign-off</button></form>
                @elseif($jobCard->agent_signed_off_at)
                <div class="text-xs" style="color: var(--text-muted);">Agent — checked: {{ $jobCard->agentSignedOffByUser?->name }} ({{ $jobCard->agent_signed_off_at->format('Y-m-d H:i') }})</div>
                @endif

                @if(! $jobCard->tenant_confirmed_at && $stage['can']['tenant_confirm'])
                <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.tenant-confirm', $jobCard) }}" class="space-y-2">
                    @csrf
                    <textarea name="tenant_confirmation_note" rows="2" placeholder="Tenant confirmation note (optional)" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);"></textarea>
                    <button type="submit" class="corex-btn-outline text-xs w-full">Record tenant confirmation</button>
                </form>
                @elseif($jobCard->tenant_confirmed_at && $jobCard->tenant_confirmed_fixed === false)
                {{-- Reconciliation 7 Oct: the tenant's "not complete" (§17.10) is mirrored into tenant_confirmed_at with fixed = false; this line used to say "confirmed fixed" for ANY answer. --}}
                <div class="text-xs" style="color: var(--ds-crimson);">Tenant — said the work is NOT complete ({{ $jobCard->tenant_confirmed_at->format('Y-m-d H:i') }})</div>
                @elseif($jobCard->tenant_confirmed_at)
                <div class="text-xs" style="color: var(--text-muted);">Tenant — confirmed fixed: {{ $jobCard->tenantConfirmedByUser?->name }} ({{ $jobCard->tenant_confirmed_at->format('Y-m-d H:i') }})</div>
                @endif

                @if($stage['can']['complete'])
                <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.complete', $jobCard) }}" data-confirm="Mark this job card complete?">
                    @csrf
                    <label class="text-xs font-medium" for="complete-paid-by">Who pays for this job?</label>
                    <select id="complete-paid-by" name="paid_by" class="w-full rounded-md px-3 py-2 text-sm mt-1 mb-2" style="border: 1px solid var(--border);">
                        <option value="owner">Owner</option>
                        <option value="tenant">Tenant</option>
                        <option value="deposit_deduction">Deposit deduction</option>
                        <option value="not_yet_paid">Not yet paid</option>
                    </select>
                    <button type="submit" class="corex-btn-primary text-xs w-full">Complete job card</button>
                </form>
                @endif
                @endpermission

                @permission('rental_job_cards.cancel')
                <button type="button" onclick="document.getElementById('cancel-job-card-form').classList.toggle('hidden')" class="text-xs w-full text-left" style="color: var(--text-muted);">Cancel job card</button>
                <form data-keep-scroll id="cancel-job-card-form" method="POST" action="{{ route('corex.rental-job-cards.cancel', $jobCard) }}" class="hidden space-y-2 pt-2">
                    @csrf
                    <textarea name="cancel_reason" required placeholder="Reason for cancellation" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);"></textarea>
                    <button type="submit" class="corex-btn-outline text-xs w-full" style="color: var(--ds-crimson);">Confirm cancel</button>
                </form>
                @endpermission
            </div>
            @endif
        </div>
