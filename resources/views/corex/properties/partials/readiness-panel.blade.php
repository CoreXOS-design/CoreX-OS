@php
    /** @var \App\Services\Compliance\ReadinessReport $report */
    /** @var \App\Models\Property $property */
    $isLive = $report->snapshotAt !== null;
    $isReady = $report->ready && !$isLive;
    $isBlocked = !$report->ready && !$isLive;

    $statusLabel = $isLive ? 'LIVE' : ($isReady ? 'READY' : 'BLOCKED');
    $statusStyle = match(true) {
        $isLive => 'background:#10b981; color:#ffffff;',
        $isReady => 'background:rgba(0,212,170,.15); color:#047857;',
        default => 'background:rgba(245,158,11,.15); color:#b45309;',
    };

    // Resolve FICA-failing seller contacts for per-seller action buttons
    $ficaFailingSellers = collect();
    if (!($report->checklist['fica_sellers']['passed'] ?? true)) {
        $ficaFailingSellers = $property->contacts()
            ->wherePivotIn('role', ['owner', 'seller', 'landlord', 'lessor'])
            ->get()
            ->filter(function ($c) {
                $fica = \DB::table('fica_submissions')
                    ->where('contact_id', $c->id)
                    ->where('status', 'approved')
                    ->first();
                return !$fica;
            });
    }
@endphp
<div class="mx-6 mt-4 mb-2 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);"
     x-data="{
        expanded: localStorage.getItem('readiness_expanded_{{ $property->id }}') !== null
            ? localStorage.getItem('readiness_expanded_{{ $property->id }}') === 'true'
            : {{ $isBlocked ? 'true' : 'false' }},
        goLiveLoading: false, goLiveError: null, goLiveDone: {{ $isLive ? 'true' : 'false' }},
        toggle() { this.expanded = !this.expanded; localStorage.setItem('readiness_expanded_{{ $property->id }}', this.expanded); }
     }">

    {{-- Header — always visible --}}
    <div class="flex items-center justify-between px-5 py-3 cursor-pointer select-none" @click="toggle()">
        <div class="flex items-center gap-2.5">
            @if($isLive)
                <span class="text-xs" style="color:#10b981;">&#10003;</span>
                <span class="text-xs font-semibold" style="color:var(--text-primary);">
                    Compliance Live — captured {{ $report->snapshotAt->format('j M Y') }}
                    @if($property->compliance_snapshot_data['snapshotted_by_name'] ?? null)
                        by {{ $property->compliance_snapshot_data['snapshotted_by_name'] }}
                    @endif
                </span>
            @elseif($isReady)
                <span class="text-xs" style="color:#10b981;">&#10003;</span>
                <span class="text-xs font-semibold" style="color:var(--text-primary);">Compliance Ready — all gates passed</span>
            @else
                <span class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Compliance Status</span>
            @endif
            <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded" style="{{ $statusStyle }}">{{ $statusLabel }}</span>
            <span class="text-[10px] cursor-help" style="color:var(--text-muted);" title="Compliance gates protect the agency under the Property Practitioners Act, FICA, and POPIA. All gates must pass before this listing can be marketed externally.">&#9432;</span>
        </div>
        <div class="flex items-center gap-2">
            @if($isReady)
                <button type="button"
                        x-show="!goLiveDone && !goLiveLoading"
                        @click.stop="if(confirm('Record compliance snapshot and enable marketing?')) {
                            goLiveLoading = true; goLiveError = null;
                            fetch('{{ route('corex.properties.go-live', $property->id) }}', {
                                method: 'POST',
                                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                credentials: 'same-origin',
                            }).then(r => r.json().then(d => ({ ok: r.ok, data: d }))).then(({ ok, data }) => {
                                goLiveLoading = false;
                                if (ok && data.ok) { goLiveDone = true; window.location.reload(); }
                                else { goLiveError = data.message || 'Check compliance gates.'; expanded = true; }
                            }).catch(() => { goLiveLoading = false; goLiveError = 'Network error.'; expanded = true; });
                        }"
                        class="text-[10px] font-semibold px-3 py-1.5 rounded transition hover:opacity-90"
                        style="background:#00d4aa; color:#0f172a;">
                    Go Live & Start Marketing
                </button>
                <span x-show="goLiveLoading" x-cloak class="text-[10px]" style="color:var(--text-muted);">Processing...</span>
            @endif
            <svg class="w-4 h-4 transition-transform duration-200" :class="expanded ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" style="color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="m19 9-7 7-7-7"/></svg>
        </div>
    </div>

    {{-- Expandable checklist --}}
    <div x-show="expanded" x-cloak x-collapse>
        <div x-show="goLiveError" x-cloak class="mx-5 mb-2 text-[11px] rounded px-2 py-1" style="background:rgba(239,68,68,.1); color:#ef4444;" x-text="goLiveError"></div>

        <div class="px-5 pb-3 space-y-2" style="border-top:1px solid var(--border); padding-top:0.75rem;">
            @foreach($report->checklist as $gate => $check)
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-start gap-2 min-w-0">
                        @if($check['passed'])
                            <span class="text-xs mt-0.5" style="color:#10b981;">&#10003;</span>
                        @else
                            <span class="text-xs mt-0.5" style="color:#ef4444;">&#10007;</span>
                        @endif
                        <div>
                            <div class="text-xs font-medium" style="color:var(--text-primary);">{{ ucfirst(str_replace('_', ' ', $gate)) }}</div>
                            <div class="text-[10px]" style="color:var(--text-muted);">{{ $check['detail'] }}</div>
                        </div>
                    </div>
                    @if(!$check['passed'] && !$isLive)
                        @if($gate === 'authority_to_market')
                            <a href="{{ route('docuperfect.esign.create') }}?property_id={{ $property->id }}&template_type=mandate"
                               class="text-[10px] font-medium px-2 py-1 rounded no-underline flex-shrink-0 transition hover:opacity-80"
                               style="background:rgba(0,212,170,.1); color:#00d4aa; border:1px solid rgba(0,212,170,.2);">Send Marketing Pack</a>
                        @elseif($gate === 'fica_sellers')
                            <div class="flex flex-col gap-1 flex-shrink-0">
                                @foreach($ficaFailingSellers as $seller)
                                    <a href="{{ route('compliance.fica.create') }}?contact_id={{ $seller->id }}"
                                       class="text-[10px] font-medium px-2 py-1 rounded no-underline transition hover:opacity-80"
                                       style="background:rgba(0,212,170,.1); color:#00d4aa; border:1px solid rgba(0,212,170,.2);">FICA — {{ $seller->first_name }}</a>
                                @endforeach
                            </div>
                        @elseif($gate === 'photos')
                            <a href="{{ route('corex.properties.show', $property->id) }}?tab=gallery"
                               class="text-[10px] font-medium px-2 py-1 rounded no-underline flex-shrink-0 transition hover:opacity-80"
                               style="background:rgba(0,212,170,.1); color:#00d4aa; border:1px solid rgba(0,212,170,.2);">Upload Photos</a>
                        @elseif($gate === 'details_complete')
                            <a href="{{ route('corex.properties.show', $property->id) }}?tab=info"
                               class="text-[10px] font-medium px-2 py-1 rounded no-underline flex-shrink-0 transition hover:opacity-80"
                               style="background:rgba(0,212,170,.1); color:#00d4aa; border:1px solid rgba(0,212,170,.2);">Complete Details</a>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>

        @if($isBlocked)
            <div class="px-5 pb-3">
                <p class="text-[11px]" style="color:var(--text-muted);">Marketing is blocked until all gates are green.</p>
            </div>
        @endif
    </div>
</div>
