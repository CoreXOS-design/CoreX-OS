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
     x-data="{ goLiveLoading: false, goLiveError: null, goLiveDone: {{ $isLive ? 'true' : 'false' }} }">

    {{-- Header --}}
    <div class="flex items-center justify-between px-5 py-3" style="border-bottom:1px solid var(--border);">
        <div class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Compliance Status</div>
        <span class="text-[10px] font-bold uppercase px-2.5 py-1 rounded" style="{{ $statusStyle }}">
            <span x-text="goLiveDone ? 'LIVE' : '{{ $statusLabel }}'"></span>
        </span>
    </div>

    {{-- Checklist --}}
    <div class="px-5 py-3 space-y-2">
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
                            'marketing_permission' => '#',
                            'mandate' => route('corex.properties.show', $property->id) . '?tab=drive',
                            'fica_sellers' => route('corex.properties.show', $property->id) . '?tab=contacts',
                            'photos' => route('corex.properties.show', $property->id) . '?tab=gallery',
                            'details_complete' => route('corex.properties.show', $property->id) . '?tab=info',
                            default => '#',
                        };
                        $actionLabel = match($gate) {
                            'marketing_permission' => 'Send Marketing Permission',
                            'mandate' => 'Send Mandate',
                            'fica_sellers' => 'Request FICA',
                            'photos' => 'Upload Photos',
                            'details_complete' => 'Complete Details',
                            default => 'Resolve',
                        };
                    @endphp
                    <a href="{{ $actionUrl }}" class="text-[10px] font-medium px-2 py-1 rounded no-underline flex-shrink-0 transition hover:opacity-80"
                       style="background:rgba(0,212,170,.1); color:#00d4aa; border:1px solid rgba(0,212,170,.2);"
                       @if($gate === 'marketing_permission') title="Wired in Prompt F" @endif>{{ $actionLabel }}</a>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Footer --}}
    <div class="px-5 py-3" style="border-top:1px solid var(--border);">
        @if($isBlocked)
            <p class="text-[11px]" style="color:var(--text-muted);">Marketing is blocked until all gates are green. Resolve the items above to continue.</p>
        @elseif($isReady || !$isLive)
            <div x-show="!goLiveDone">
                <div x-show="goLiveError" x-cloak class="text-[11px] mb-2 rounded px-2 py-1" style="background:rgba(239,68,68,.1); color:#ef4444;" x-text="goLiveError"></div>
                <button type="button"
                        x-show="!goLiveLoading"
                        @click="if(confirm('This will record a compliance snapshot and enable all marketing channels for this property. Continue?')) {
                            goLiveLoading = true; goLiveError = null;
                            fetch('{{ route('corex.properties.go-live', $property->id) }}', {
                                method: 'POST',
                                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                credentials: 'same-origin',
                            }).then(r => r.json().then(d => ({ ok: r.ok, data: d }))).then(({ ok, data }) => {
                                goLiveLoading = false;
                                if (ok && data.ok) { goLiveDone = true; window.location.reload(); }
                                else { goLiveError = data.message || 'Could not go live. Check compliance gates.'; }
                            }).catch(() => { goLiveLoading = false; goLiveError = 'Network error.'; });
                        }"
                        class="text-xs font-semibold px-4 py-2 rounded transition hover:opacity-90"
                        style="background:#00d4aa; color:#0f172a;">
                    Go Live & Start Marketing
                </button>
                <span x-show="goLiveLoading" x-cloak class="text-xs" style="color:var(--text-muted);">Processing...</span>
            </div>
        @endif
        @if($isLive)
            <p class="text-[11px]" style="color:var(--text-muted);">
                Live since {{ $report->snapshotAt->format('j M Y, H:i') }}
                @if($property->compliance_snapshot_data['snapshotted_by_name'] ?? null)
                    — captured by {{ $property->compliance_snapshot_data['snapshotted_by_name'] }}
                @endif
                . Compliance snapshot preserved.
            </p>
        @endif
        <div x-show="goLiveDone && !{{ $isLive ? 'true' : 'false' }}" x-cloak>
            <p class="text-[11px]" style="color:#10b981;">Property is now live and ready for marketing.</p>
        </div>
    </div>
</div>
