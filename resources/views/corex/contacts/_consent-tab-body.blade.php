{{-- Consent & Compliance tab body (M3.4) — extracted as its own partial
     (pre-existing bug fix, found while verifying Phase 4 in a real browser —
     NOT a Phase 4 change): same class of Blade-compiler defect as the other
     _*.blade.php partials split out of show.blade.php today. $consentTypes
     never got assigned, so the foreach loop over it threw on every contact
     page. No logic changed here. --}}
@php
    $consentTypes  = \App\Models\Contact::CONSENT_TYPES;
    $consentStates = collect($contact->consentStates())->keyBy('type');
@endphp

<div class="flex items-center justify-between">
    <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Consent Records</h3>
    <span class="text-xs" style="color: var(--text-muted);">POPIA + CPA compliant</span>
</div>

<p class="text-[11px]" style="color: var(--text-muted);">
    <span class="font-medium" style="color: var(--ds-crimson);">No</span>
    means the client has refused this — do not contact them this way.
</p>

<div class="space-y-2">
    @foreach($consentTypes as $typeKey => $typeLabel)
        @php
            $state    = $consentStates->get($typeKey);
            $decision = $state['decision'] ?? null;        // given | declined | null
            $recordedAt = $state['recorded_at'] ?? null;
            $isDeclined = $decision === 'declined';
        @endphp
        <div class="flex items-center justify-between px-3 py-2 rounded-md"
             style="background: var(--surface-2); border: 1px solid {{ $isDeclined ? 'var(--ds-crimson)' : 'var(--border)' }};">
            <div>
                <span class="text-xs font-medium" style="color: var(--text-primary);">{{ $typeLabel }}</span>
                @if($decision === 'given')
                    <span class="ds-badge ds-badge-success ml-2">Given</span>
                    @if($recordedAt)
                        <span class="ml-1 text-[10px]" style="color: var(--text-muted);">since {{ $recordedAt->format('d M Y') }}</span>
                    @endif
                @elseif($isDeclined)
                    <span class="ds-badge ds-badge-danger ml-2">No</span>
                    @if($recordedAt)
                        <span class="ml-1 text-[10px]" style="color: var(--text-muted);">since {{ $recordedAt->format('d M Y') }}</span>
                    @endif
                @else
                    <span class="ds-badge ds-badge-default ml-2">Not recorded</span>
                @endif
            </div>
            <div class="flex items-center gap-1">
                {{-- Given — shown unless the client has declined. Once "Given"
                     is chosen the "No" option is hidden; Clear reveals both again. --}}
                @if(!$isDeclined)
                    <form method="POST" action="{{ route('corex.contacts.consent.record', $contact) }}">
                        @csrf
                        <input type="hidden" name="consent_type" value="{{ $typeKey }}">
                        <input type="hidden" name="decision" value="given">
                        <input type="hidden" name="method" value="electronic">
                        <button type="submit"
                                class="text-[10px] px-2 py-1 rounded-md {{ $decision === 'given' ? 'corex-btn-primary' : 'corex-btn-outline' }}">
                            Given
                        </button>
                    </form>
                @endif
                {{-- No — shown unless the client has given. Hidden once "Given"
                     is chosen (per the toggle rule); Clear reveals it again. --}}
                @if($decision !== 'given')
                    <form method="POST" action="{{ route('corex.contacts.consent.record', $contact) }}">
                        @csrf
                        <input type="hidden" name="consent_type" value="{{ $typeKey }}">
                        <input type="hidden" name="decision" value="declined">
                        <input type="hidden" name="method" value="electronic">
                        <button type="submit"
                                class="text-[10px] px-2 py-1 rounded-md"
                                style="{{ $isDeclined
                                    ? 'background: var(--ds-crimson); color: #fff; border: 1px solid var(--ds-crimson);'
                                    : 'background: transparent; color: var(--ds-crimson); border: 1px solid var(--ds-crimson);' }}">
                            No
                        </button>
                    </form>
                @endif
                @if($decision !== null)
                    <form method="POST" action="{{ route('corex.contacts.consent.revoke', $contact) }}">
                        @csrf
                        <input type="hidden" name="consent_type" value="{{ $typeKey }}">
                        <input type="hidden" name="reason" value="Cleared by agent">
                        <button type="submit" class="text-[10px] px-2 py-1" style="color: var(--text-muted);" title="Clear — back to not recorded">Clear</button>
                    </form>
                @endif
            </div>
        </div>
    @endforeach
</div>
