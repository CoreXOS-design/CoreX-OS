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
@endphp
<div class="mx-6 mt-4 mb-2 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);"
     x-data="{ expanded: {{ $isBlocked ? 'true' : 'false' }}, goLiveLoading: false, goLiveError: null, goLiveDone: {{ $isLive ? 'true' : 'false' }} }">

    {{-- Header — always visible --}}
    <div class="flex items-center justify-between px-5 py-3 cursor-pointer select-none" @click="expanded = !expanded">
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
        </div>
        <div class="flex items-center gap-2">
            {{-- Go Live button (READY state, inline in header) --}}
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
            {{-- Chevron --}}
            <svg class="w-4 h-4 transition-transform duration-200" :class="expanded ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" style="color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="m19 9-7 7-7-7"/></svg>
        </div>
    </div>

    {{-- Expandable checklist --}}
    <div x-show="expanded" x-cloak x-collapse>
        {{-- Error alert --}}
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
                        @php
                            $actionUrl = match($gate) {
                                'authority_to_market' => route('corex.properties.show', $property->id) . '?tab=drive',
                                'fica_sellers' => route('corex.properties.show', $property->id) . '?tab=contacts',
                                'photos' => route('corex.properties.show', $property->id) . '?tab=gallery',
                                'details_complete' => route('corex.properties.show', $property->id) . '?tab=info',
                                default => '#',
                            };
                            $actionLabel = match($gate) {
                                'authority_to_market' => 'Send Marketing Pack',
                                'fica_sellers' => 'Request FICA',
                                'photos' => 'Upload Photos',
                                'details_complete' => 'Complete Details',
                                default => 'Resolve',
                            };
                        @endphp
                        <a href="{{ $actionUrl }}" class="text-[10px] font-medium px-2 py-1 rounded no-underline flex-shrink-0 transition hover:opacity-80"
                           style="background:rgba(0,212,170,.1); color:#00d4aa; border:1px solid rgba(0,212,170,.2);">{{ $actionLabel }}</a>
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
