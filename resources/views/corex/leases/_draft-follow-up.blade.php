{{--
    Audit A1 (2026-10-19) - leases.md §15.4 / §15.6: a lease created as "Create lease only" is not a dead end. On a draft with no agreement
    in flight the Lease Hub offers the three honest ways forward, in this order:
      1. prepare the agency's lease agreement for e-sign (same gate, guard and flow as the capture screen's second button),
      2. attach the signed paper copy (files it, activates the lease, marks it signed on paper),
      3. activate it without a signed agreement - an explicit choice, recorded in the lease history.
    $draftFollowUp comes from LeaseCaptureService::draftFollowUpFor() (null = nothing to offer).
--}}
@if(!empty($draftFollowUp))
    @php
        $fu = $draftFollowUp;
        $hasAgreementFields = collect($fu['agreements'])->contains(fn ($a) => count($a['fields']) > 0);
        $propertyWithdrawnFu = strtolower(trim((string) ($lease->property?->status ?? ''))) === 'withdrawn';
    @endphp
    <div id="draft-follow-up" class="rounded-md p-4 space-y-4" style="background: var(--surface); border: 1px solid var(--border);" data-qa="draft-follow-up"
         x-data="{ agreementId: '{{ $fu['selected_agreement_id'] }}' }">
        <div>
            <h2 class="text-sm font-semibold">Get this lease signed</h2>
            <p class="text-xs mt-1" style="color: var(--text-muted);">This lease was created without a signed agreement. Choose how it gets one.</p>
        </div>

        @if($fu['can_prepare'])
            <form method="POST" action="{{ route('corex.leases.signing.prepare', $lease) }}" class="space-y-3" data-qa="prepare-for-signing">
                @csrf
                <h3 class="text-xs font-semibold">Prepare the lease agreement for signing</h3>
                @if(count($fu['agreements']) > 1)
                    <div>
                        <label class="prop-label" for="fu-agreement">Lease agreement</label>
                        <select id="fu-agreement" name="agreement_id" x-model="agreementId" class="prop-select">
                            @foreach($fu['agreements'] as $a)
                                <option value="{{ $a['id'] }}">{{ $a['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <input type="hidden" name="agreement_id" value="{{ $fu['agreements'][0]['id'] }}">
                @endif
                @foreach($fu['agreements'] as $a)
                    @if(count($a['fields']) > 0)
                        <div class="grid grid-cols-2 gap-3" @if(count($fu['agreements']) > 1) x-show="String(agreementId) === '{{ $a['id'] }}'" @if(!$loop->first) x-cloak @endif @endif>
                            @foreach($a['fields'] as $f)
                                @php $name = 'agreement[' . $f['key'] . ']'; $value = old('agreement.' . $f['key'], $f['value']); @endphp
                                <div class="col-span-2 {{ $f['type'] === 'longtext' ? '' : 'sm:col-span-1' }}">
                                    <label class="prop-label">{{ $f['label'] }}@if($f['required']) <span class="text-[10px]" style="color: var(--text-muted);">· needed for signing</span>@endif</label>
                                    @switch($f['type'])
                                        @case('longtext')
                                            <textarea name="{{ $name }}" rows="3" maxlength="{{ $f['max'] }}" class="prop-input" @if(count($fu['agreements']) > 1) x-bind:disabled="String(agreementId) !== '{{ $a['id'] }}'" @endif>{{ $value }}</textarea>
                                            @break
                                        @case('date')
                                            <input type="date" name="{{ $name }}" value="{{ $value }}" class="prop-input" style="color-scheme: light dark;" @if(count($fu['agreements']) > 1) x-bind:disabled="String(agreementId) !== '{{ $a['id'] }}'" @endif>
                                            @break
                                        @case('integer')
                                        @case('month')
                                        @case('percent')
                                        @case('money')
                                            <input type="number" name="{{ $name }}" value="{{ $value }}" step="{{ $f['type'] === 'integer' || $f['type'] === 'month' ? 1 : 0.01 }}" min="0" class="prop-input" @if(count($fu['agreements']) > 1) x-bind:disabled="String(agreementId) !== '{{ $a['id'] }}'" @endif>
                                            @break
                                        @default
                                            <input type="text" name="{{ $name }}" value="{{ $value }}" maxlength="{{ $f['max'] }}" class="prop-input" @if(count($fu['agreements']) > 1) x-bind:disabled="String(agreementId) !== '{{ $a['id'] }}'" @endif>
                                    @endswitch
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endforeach
                <button type="submit" class="corex-btn-primary text-xs">Prepare the agreement for signing</button>
                <p class="text-[11px]" style="color: var(--text-muted);">Nothing is sent to anyone yet - you check the agreement first.</p>
            </form>
        @elseif($fu['state'] === 'none')
            <p class="text-xs" style="color: var(--text-muted);">Your agency has not set up a lease agreement to sign on CoreX yet. An administrator sets one up under Settings → Rental lease agreements.</p>
        @elseif($fu['state'] === 'attention')
            <p class="text-xs" style="color: var(--text-muted);">The agency's lease agreement needs attention before it can be used{{ $fu['reason'] ? ': ' . $fu['reason'] : '' }}.</p>
        @endif

        @if($fu['can_paper'])
            <form method="POST" action="{{ route('corex.leases.signing.signed-copy', $lease) }}" enctype="multipart/form-data" class="space-y-2" data-qa="attach-signed-copy">
                @csrf
                <h3 class="text-xs font-semibold">Attach the signed paper copy</h3>
                <p class="text-xs" style="color: var(--text-muted);">Already signed on paper? Attaching the copy files it against the lease and the property and makes the lease active.</p>
                <input type="file" name="signed_document" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required class="w-full text-sm">
                <button type="submit" class="corex-btn-outline text-xs">Attach the signed copy and activate</button>
            </form>
        @endif

        @if(in_array((string) ($lease->signing_status ?: 'not_sent'), ['not_sent', 'declined', 'voided', 'expired'], true))
            <form method="POST" action="{{ route('corex.leases.activate', $lease) }}" class="space-y-1" data-qa="activate-without-agreement">
                @csrf
                <h3 class="text-xs font-semibold">Activate without a signed agreement</h3>
                <p class="text-xs" style="color: var(--text-muted);">Only if the tenancy is going ahead on other terms. The lease history records that it was activated without a signed agreement.</p>
                <button type="submit" class="corex-btn-outline text-xs"
                        data-confirm="{{ $propertyWithdrawnFu ? 'This property is withdrawn. ' : '' }}Activate this lease without a signed agreement? This is recorded in the lease history.">Activate without a signed agreement</button>
            </form>
        @endif
    </div>
@endif
